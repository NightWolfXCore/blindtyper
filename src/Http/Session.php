<?php

declare(strict_types=1);

namespace BlindTyper\Http;

use BlindTyper\Support\Config;

final class Session
{
    public function __construct(Config $config)
    {
        $dir = $config->storage() . '/sessions';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Session storage unavailable');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '86400');
        session_save_path($dir);
        session_name('blindtyper_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => parse_url($config->url, PHP_URL_SCHEME) === 'https',
            'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) {
            throw new \RuntimeException('Cannot start session');
        }
        $now = time();
        if (isset($_SESSION['last_activity']) && $now - (int) $_SESSION['last_activity'] > 86400) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = $now;
    }

    public function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function verify(?string $token): void
    {
        if ($token === null || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            throw new ApiException(403, 'CSRF_INVALID', 'Обновите CSRF-токен');
        }
    }

    public function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public function authenticate(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $id, 'csrf' => bin2hex(random_bytes(32)), 'last_activity' => time()];
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
