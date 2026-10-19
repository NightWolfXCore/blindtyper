<?php

declare(strict_types=1);

namespace BlindTyper\Http;

use BlindTyper\Controller\ApiController;
use BlindTyper\Domain\Scoring;
use BlindTyper\Repository\Database;
use BlindTyper\Repository\ReadRepository;
use BlindTyper\Repository\TrainingRepository;
use BlindTyper\Repository\UserRepository;
use BlindTyper\Service\AuthService;
use BlindTyper\Service\TrainingService;
use BlindTyper\Support\Config;
use BlindTyper\Support\SystemClock;

final class Application
{
    public static function run(Config $config): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            $request = Request::capture();
            if (!str_starts_with($request->path, '/api/v1/')) {
                throw ApiException::notFound();
            }
            $session = new Session($config);
            $db = new Database($config->connect());
            $clock = new SystemClock();
            $users = new UserRepository($db);
            $sessions = new TrainingRepository($db);
            $controller = new ApiController(
                new AuthService($users, $clock),
                new TrainingService($db, $users, $sessions, new Scoring(), $clock),
                $sessions,
                new ReadRepository($db, $clock),
                $session,
                new RateLimiter($config->storage() . '/rate-limits')
            );
            [$status,$data] = $controller->dispatch($request);
            self::respond($status, ['data' => $data]);
        } catch (ApiException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->fields !== []) {
                $error['fields'] = $e->fields;
            }
            self::respond($e->status, ['error' => $error]);
        } catch (\Throwable $e) {
            // Do not log request bodies, passwords, tokens, SQL parameters or credential-bearing messages.
            $id = bin2hex(random_bytes(8));
            error_log("Backend error $id: " . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
            self::respond(500, ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Внутренняя ошибка сервера', 'request_id' => $id]]);
        }
    }

    private static function respond(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
