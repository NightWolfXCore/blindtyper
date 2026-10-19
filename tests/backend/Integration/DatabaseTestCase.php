<?php

declare(strict_types=1);

namespace BlindTyper\Tests\Integration;

use BlindTyper\Domain\Scoring;
use BlindTyper\Repository\Database;
use BlindTyper\Repository\UserRepository;
use BlindTyper\Repository\TrainingRepository;
use BlindTyper\Repository\ReadRepository;
use BlindTyper\Service\AuthService;
use BlindTyper\Service\TrainingService;
use BlindTyper\Support\Config;
use BlindTyper\Tests\FrozenClock;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;
    protected FrozenClock $clock;
    protected TrainingService $training;
    protected AuthService $auth;
    protected TrainingRepository $sessions;
    protected ReadRepository $read;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromEnvironment(dirname(__DIR__, 3));
        if ($config->environment !== 'test' || !str_ends_with($config->dbName, '_test')) {
            throw new \RuntimeException('Tests require APP_ENV=test and a dedicated *_test database');
        }
        $db = new Database($config->connect());
        foreach (explode(';', file_get_contents(dirname(__DIR__, 2) . '/fixtures/schema.sql')) as $sql) {
            if (trim($sql) !== '') {
                $db->pdo->exec($sql);
            }
        }
        $seed = require dirname(__DIR__, 2) . '/fixtures/seed.php';
        $seed($db);
    }

    protected function setUp(): void
    {
        $this->db = new Database(Config::fromEnvironment(dirname(__DIR__, 3))->connect());
        foreach (['user_achievements', 'session_character_stats', 'training_sessions', 'users'] as $table) {
            $this->db->execute("DELETE FROM $table");
        }
        $this->clock = new FrozenClock();
        $users = new UserRepository($this->db);
        $this->sessions = new TrainingRepository($this->db);
        $this->auth = new AuthService($users, $this->clock);
        $this->training = new TrainingService($this->db, $users, $this->sessions, new Scoring(), $this->clock);
        $this->read = new ReadRepository($this->db, $this->clock);
    }

    protected function player(string $email = 'player@example.org'): int
    {
        return (int) $this->auth->register(['email' => $email, 'password' => 'a-test-password', 'display_name' => 'Игрок'])['id'];
    }

    protected function create(int $id, array $extra = []): array
    {
        return $this->training->create($id, $extra + ['mode' => 'words', 'language' => 'ru', 'difficulty' => 'easy', 'length' => 120]);
    }

    public static function events(string $text, int $duration = 60000): array
    {
        $chars = mb_str_split($text);
        $events = [];
        foreach ($chars as $i => $char) {
            $events[] = ['seq' => $i + 1, 'at_ms' => (int) floor(($i + 1) * $duration / count($chars)), 'char' => $char];
        }
        return $events;
    }

    protected function complete(int $id, array $session, int $duration = 60000): array
    {
        $this->training->start($id, $session['public_id']);
        $this->clock->advance('+' . (int) ceil($duration / 1000) . ' seconds');
        return $this->training->finish($id, $session['public_id'], ['events' => self::events($session['text'], $duration)]);
    }
}
