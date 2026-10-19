<?php

declare(strict_types=1);

namespace BlindTyper\Support;

final readonly class Config
{
    public function __construct(
        public string $root,
        public string $environment,
        public string $url,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
    ) {
    }

    public static function fromEnvironment(string $root): self
    {
        $env = getenv('APP_ENV') ?: 'production';
        $url = getenv('APP_URL') ?: 'http://localhost:8080';
        if ($env === 'production' && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('Production requires HTTPS APP_URL');
        }
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
            if (getenv($name) === false || getenv($name) === '') {
                throw new \RuntimeException("Missing configuration: $name");
            }
        }
        return new self(
            $root,
            $env,
            $url,
            getenv('DB_HOST') ?: '127.0.0.1',
            (int) (getenv('DB_PORT') ?: 3306),
            (string) getenv('DB_NAME'),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD')
        );
    }

    public function storage(): string
    {
        return $this->root . ($this->environment === 'test' ? '/storage/test' : '/storage');
    }

    public function connect(): \PDO
    {
        $pdo = new \PDO(
            "mysql:host={$this->dbHost};port={$this->dbPort};dbname={$this->dbName};charset=utf8mb4",
            $this->dbUser,
            $this->dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false,
             \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_STRINGIFY_FETCHES => false]
        );
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
