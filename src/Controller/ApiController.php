<?php

declare(strict_types=1);

namespace BlindTyper\Controller;

use BlindTyper\Domain\Rules;
use BlindTyper\Http\ApiException;
use BlindTyper\Http\RateLimiter;
use BlindTyper\Http\Request;
use BlindTyper\Http\Session;
use BlindTyper\Repository\ReadRepository;
use BlindTyper\Repository\TrainingRepository;
use BlindTyper\Service\AuthService;
use BlindTyper\Service\TrainingService;

final class ApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TrainingService $training,
        private readonly TrainingRepository $sessions,
        private readonly ReadRepository $read,
        private readonly Session $session,
        private readonly RateLimiter $limiter
    ) {
    }

    /** @return array{0: int, 1: array} */
    public function dispatch(Request $request): array
    {
        $path = substr($request->path, 7);
        $method = $request->method;
        if ($method === 'GET' && $path === '/auth/csrf') {
            return [200, ['csrf_token' => $this->session->csrf()]];
        }
        if ($method === 'POST') {
            $this->session->verify($request->csrf);
        }
        if ($method === 'POST' && in_array($path, ['/auth/register', '/auth/login'], true)) {
            $this->limiter->hit('auth-ip:' . $request->ip, 20, 900);
            $email = AuthService::email($request->body['email'] ?? null);
            $this->limiter->hit('auth-email:' . $email, 10, 900);
            if ($path === '/auth/register') {
                $this->limiter->hit('register:' . $request->ip, 5, 3600);
            }
            $user = $path === '/auth/register' ? $this->auth->register($request->body) : $this->auth->login($request->body);
            $this->session->authenticate((int) $user['id']);
            return [$path === '/auth/register' ? 201 : 200, AuthService::view($user)];
        }
        if ($method === 'GET' && $path === '/catalog') {
            $id = $this->session->userId();
            return [200, Rules::catalog($id === null ? 0 : (int) $this->auth->user($id)['total_xp'])];
        }
        if ($method === 'GET' && $path === '/leaderboard') {
            return [200, $this->read->leaderboard(
                $request->choice('metric', ['cpm', 'accuracy', 'xp'], 'cpm'),
                $request->choice('period', ['today', 'week', 'all'], 'week'),
                $request->choice('mode', array_keys(Rules::MODES)),
                $request->choice('language', Rules::LANGUAGES),
                $request->integer('page', 1, 1, 1000000),
                $request->integer('per_page', 20, 1, 100)
            )];
        }
        // Do not let a misspelled public route masquerade as an authentication failure.
        $known = ($method === 'GET' && in_array($path, ['/me', '/me/stats', '/me/achievements', '/me/bonuses', '/sessions'], true))
            || ($method === 'POST' && in_array($path, ['/auth/logout', '/sessions'], true))
            || preg_match('~^/sessions/[0-9a-f-]{36}(?:/(?:start|finish))?$~D', $path);
        if (!$known) {
            throw ApiException::notFound();
        }
        $userId = $this->session->userId() ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Войдите в аккаунт');
        $user = $this->auth->user($userId);
        if ($method === 'POST' && $path === '/auth/logout') {
            $this->session->logout();
            return [200, ['logged_out' => true]];
        }
        if ($method === 'GET' && $path === '/me') {
            return [200, AuthService::view($user)];
        }
        if ($method === 'GET' && $path === '/me/stats') {
            return [200, $this->read->stats($userId, $request->integer('days', 30, 1, 90))];
        }
        if ($method === 'GET' && $path === '/me/achievements') {
            return [200, $this->read->achievements($userId)];
        }
        if ($method === 'GET' && $path === '/me/bonuses') {
            return [200, $this->read->bonuses($userId)];
        }
        if ($method === 'GET' && $path === '/sessions') {
            return [200, $this->sessions->history($userId, $request->integer('page', 1, 1, 1000000), $request->integer('per_page', 20, 1, 100))];
        }
        if ($method === 'POST' && $path === '/sessions') {
            $this->limiter->hit('create:' . $userId, 10, 60);
            return [201, $this->training->create($userId, $request->body)];
        }
        if (preg_match('~^/sessions/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})(?:/(start|finish))?$~D', $path, $match)) {
            $action = $match[2] ?? null;
            if ($method === 'GET' && $action === null) {
                return [200, TrainingRepository::view($this->sessions->find($match[1], $userId))];
            }
            if ($method === 'POST' && $action === 'start') {
                return [200, $this->training->start($userId, $match[1])];
            }
            if ($method === 'POST' && $action === 'finish') {
                return [200, $this->training->finish($userId, $match[1], $request->body)];
            }
        }
        throw ApiException::notFound();
    }
}
