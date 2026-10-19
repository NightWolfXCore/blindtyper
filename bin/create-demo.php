<?php

declare(strict_types=1);
$config = require dirname(__DIR__) . '/config/bootstrap.php';
if (!in_array($config->environment, ['development', 'test'], true)) {
    throw new RuntimeException('Demo accounts are only available in development/test');
}
$password = getenv('DEMO_PASSWORD') ?: '';
if (strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
    throw new RuntimeException('Set DEMO_PASSWORD to 10–72 bytes');
}
$db = new BlindTyper\Repository\Database($config->connect());
$email = BlindTyper\Service\AuthService::email(getenv('DEMO_EMAIL') ?: 'demo@example.org');
if ($db->one('SELECT id FROM users WHERE email=?', [$email]) !== null) {
    throw new RuntimeException('Email already exists; account left unchanged');
}
$now = gmdate('Y-m-d H:i:s');
$db->execute('INSERT INTO users (email,password_hash,display_name,total_xp,is_demo,created_at,updated_at) VALUES (?,?,?,900,1,?,?)', [$email, password_hash($password, PASSWORD_BCRYPT), 'Демо', $now, $now]);
echo "Demo account created; level 4, excluded from leaderboards.\n";
