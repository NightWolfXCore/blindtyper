<?php

declare(strict_types=1);

namespace BlindTyper\Service;

use BlindTyper\Domain\Rules;
use BlindTyper\Http\ApiException;
use BlindTyper\Repository\UserRepository;
use BlindTyper\Support\Clock;
use BlindTyper\Support\Time;

final class AuthService
{
    public function __construct(private readonly UserRepository $users, private readonly Clock $clock)
    {
    }

    public function user(int $id): array
    {
        return $this->users->find($id) ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Войдите в аккаунт');
    }

    public static function view(array $user): array
    {
        return ['id' => (int) $user['id'], 'display_name' => $user['display_name'], 'email' => $user['email']] + Rules::progress((int) $user['total_xp']);
    }

    public function register(array $input): array
    {
        $email = self::email($input['email'] ?? null);
        $password = $input['password'] ?? null;
        $name = $input['display_name'] ?? null;
        if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw ApiException::validation('Проверьте пароль', ['password' => 'От 10 до 72 байт, без нулевых символов']);
        }
        if (!is_string($name) || mb_strlen(trim($name), 'UTF-8') < 2 || mb_strlen(trim($name), 'UTF-8') > 40
            || preg_match('/[\p{C}]/u', $name)) {
            throw ApiException::validation('Проверьте имя', ['display_name' => 'От 2 до 40 символов']);
        }
        try {
            return $this->users->create($email, password_hash($password, PASSWORD_BCRYPT), trim($name), Time::sql($this->clock->now()));
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiException::validation('Email уже используется', ['email' => 'Email уже используется']);
            }
            throw $e;
        }
    }

    public function login(array $input): array
    {
        $email = self::email($input['email'] ?? null);
        $password = $input['password'] ?? null;
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) {
            $password = '';
        }
        $user = $this->users->byEmail($email);
        // Constant bcrypt work even for unknown users; never reveal which field was wrong.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || $user === null) {
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Неверный email или пароль');
        }
        return $user;
    }

    public static function email(mixed $email): string
    {
        if (!is_string($email) || strlen($email) > 300) {
            throw ApiException::validation('Некорректный email', ['email' => 'Некорректный email']);
        }
        $email = mb_strtolower(trim($email), 'UTF-8');
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::validation('Некорректный email', ['email' => 'Некорректный email']);
        }
        return $email;
    }
}
