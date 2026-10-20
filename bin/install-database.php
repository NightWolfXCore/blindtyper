<?php

declare(strict_types=1);

use BlindTyper\Repository\Database;
use BlindTyper\Repository\SqlFile;
use BlindTyper\Service\CatalogSeed;

$config = require dirname(__DIR__) . '/config/bootstrap.php';
$db = new Database($config->connect());
$root = dirname(__DIR__);
$migration = '0001_initial';
$seedVersion = 'seed_0001_complete_catalog';

$tables = $db->all('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
if ($tables === []) {
    foreach (SqlFile::statements($root . '/database/migrations/0001_initial.sql') as $statement) {
        $db->pdo->exec($statement);
    }
    $db->execute('INSERT INTO schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP(3))', [$migration]);
    echo "Initial schema installed.\n";
} elseif (!in_array('schema_migrations', array_column($tables, 'TABLE_NAME'), true)
    || $db->one('SELECT version FROM schema_migrations WHERE version = ?', [$migration]) === null) {
    throw new RuntimeException('Database is not empty and has no installed 0001 migration. No changes were made; use a new database or a separate data migration.');
}

if ($db->one('SELECT version FROM schema_migrations WHERE version = ?', [$seedVersion]) !== null) {
    echo "Initial catalog is already installed.\n";
    return;
}

// The seed owns fixed IDs 1–30. Do not merge it into an existing catalog by accident.
if ((int) $db->one('SELECT COUNT(*) AS count FROM exercises')['count'] !== 0
    || (int) $db->one('SELECT COUNT(*) AS count FROM achievements')['count'] !== 0) {
    throw new RuntimeException('Catalog tables are not empty and the seed is unmarked. No seed changes were made; use a separate data migration.');
}

$db->transaction(function () use ($db, $root, $seedVersion): void {
    foreach (SqlFile::statements($root . '/database/seeds/0001_initial_data.sql') as $statement) {
        $db->pdo->exec($statement);
    }
    (new CatalogSeed($db))->complete();
    $db->execute('INSERT INTO schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP(3))', [$seedVersion]);
});
echo "Initial catalog installed.\n";
