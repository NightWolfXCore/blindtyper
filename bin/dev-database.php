<?php

declare(strict_types=1);
/** Development fixtures only. Does not own or replace the production database migration workflow. */
$config = require dirname(__DIR__) . '/config/bootstrap.php';
if (!in_array($config->environment, ['development', 'test'], true)) {
    throw new RuntimeException('Fixtures are forbidden outside development/test');
}
$db = new BlindTyper\Repository\Database($config->connect());
foreach (explode(';', file_get_contents(dirname(__DIR__) . '/tests/fixtures/schema.sql')) as $sql) {
    if (trim($sql) !== '') {
        $db->pdo->exec($sql);
    }
}
$seed = require dirname(__DIR__) . '/tests/fixtures/seed.php';
$seed($db);
echo "Development schema and fixtures applied (existing progress preserved).\n";
