<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// PHP CLI router: serve only real, non-hidden assets inside public/assets.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/assets/')) {
    $file = realpath(__DIR__ . $path);
    if ($file !== false && str_starts_with($file, __DIR__ . '/assets/') && is_file($file)
        && !preg_match('~(?:^|/)\.[^/]*|\.php$~i', $path)) {
        return false;
    }
}
try {
    $config = require dirname(__DIR__) . '/config/bootstrap.php';
    // Fixed page map integrates HTML from the frontend owner without arbitrary file inclusion.
    $pages = ['/' => 'index', '/login' => 'login', '/register' => 'register', '/profile' => 'profile', '/leaderboard' => 'leaderboard'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($pages[$path])) {
        $file = __DIR__ . '/pages/' . $pages[$path] . '.html';
        if (is_file($file)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($file);
            return;
        }
    }
    BlindTyper\Http\Application::run($config);
} catch (Throwable $error) {
    error_log('Backend bootstrap failure: ' . get_class($error));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":{"code":"INTERNAL_ERROR","message":"Ошибка настройки сервера"}}';
}
