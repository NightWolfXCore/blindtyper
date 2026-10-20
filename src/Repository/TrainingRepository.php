<?php

declare(strict_types=1);

namespace BlindTyper\Repository;

use BlindTyper\Domain\Rules;
use BlindTyper\Domain\MixedCodeExercise;
use BlindTyper\Http\ApiException;
use BlindTyper\Support\Time;

final class TrainingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(string $id, int $userId, bool $lock = false): array
    {
        return $this->db->one('SELECT * FROM training_sessions WHERE public_id = ? AND user_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id, $userId])
            ?? throw ApiException::notFound();
    }

    public function exercise(array $options): array
    {
        if ($options['mode'] === 'code' && $options['code_language'] === 'mixed') {
            return MixedCodeExercise::make($options['difficulty'], $options['length']);
        }
        $low = (int) floor($options['length'] * .8);
        $high = (int) ceil($options['length'] * 1.2);
        if ($options['length'] === 1200) {
            $low = 1000;
        }
        $sql = 'SELECT * FROM exercises WHERE active = 1 AND mode = ? AND language = ? AND difficulty = ? AND code_language <=> ? AND text_length BETWEEN ? AND ? ORDER BY id';
        $rows = $this->db->all($sql, [$options['mode'], $options['language'], $options['difficulty'], $options['code_language'], $low, $high]);
        if ($rows === []) {
            throw new ApiException(503, 'EXERCISE_UNAVAILABLE', 'Нет упражнения с такими настройками');
        }
        return $rows[random_int(0, count($rows) - 1)];
    }

    public function abandon(int $userId): void
    {
        $this->db->execute("UPDATE training_sessions SET status = 'abandoned' WHERE user_id = ? AND status IN ('created','started')", [$userId]);
    }

    public function create(string $id, int $userId, array $exercise, array $options, array $bonuses, \DateTimeImmutable $now): array
    {
        $this->db->execute(
            "INSERT INTO training_sessions
            (public_id,user_id,exercise_id,mode,language,difficulty,code_language,text_snapshot,text_length,rules_version,bonuses,status,created_at,expires_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'created',?,?)",
            [$id, $userId, $exercise['id'], $options['mode'], $options['language'], $options['difficulty'], $options['code_language'],
            $exercise['text'], mb_strlen($exercise['text'], 'UTF-8'), Rules::VERSION, json_encode($bonuses, JSON_THROW_ON_ERROR), Time::sql($now), Time::sql($now->modify('+10 minutes'))]
        );
        return $this->find($id, $userId);
    }

    public function expire(int $id): void
    {
        $this->db->execute("UPDATE training_sessions SET status = 'expired' WHERE id = ?", [$id]);
    }

    public function start(int $id, \DateTimeImmutable $now): void
    {
        $this->db->execute("UPDATE training_sessions SET status = 'started',started_at = ?,expires_at = ? WHERE id = ?", [Time::sql($now), Time::sql($now->modify('+30 minutes')), $id]);
    }

    public function finish(int $id, array $result, string $hash, array $response, string $now): void
    {
        $this->db->execute(
            "UPDATE training_sessions SET status='finished',finished_at=?,duration_ms=?,correct_count=?,error_count=?,max_combo=?,xp_earned=?,ranked=?,unranked_reason=?,events_hash=?,finish_response=? WHERE id=?",
            [$now, $result['duration_ms'], $result['correct'], $result['errors'], $result['max_combo'], $result['xp_earned'], (int) $result['ranked'],
            $result['unranked_reason'], $hash, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $id]
        );
    }

    public function characterStats(int $id, array $stats): void
    {
        foreach ($stats as $row) {
            $this->db->execute('INSERT INTO session_character_stats (session_id,expected_char,attempts,errors) VALUES (?,?,?,?)', [$id, $row['char'], $row['attempts'], $row['errors']]);
        }
    }

    public function achievements(int $userId, int $sessionId, array $score, string $now): array
    {
        $count = (int) $this->db->one("SELECT COUNT(*) AS count FROM training_sessions WHERE user_id=? AND status='finished'", [$userId])['count'];
        // The result row is written after achievement evaluation in the same transaction.
        $values = ['finished_count' => $count + 1, 'session_cpm' => $score['raw_cpm'], 'session_max_combo' => $score['result']['max_combo']];
        $candidates = $this->db->all('SELECT a.* FROM achievements a LEFT JOIN user_achievements u ON u.achievement_code=a.code AND u.user_id=? WHERE u.user_id IS NULL ORDER BY a.code', [$userId]);
        $awarded = [];
        foreach ($candidates as $achievement) {
            if (!isset($values[$achievement['rule_key']]) || $values[$achievement['rule_key']] < (int) $achievement['threshold']) {
                continue;
            }
            $this->db->execute('INSERT INTO user_achievements (user_id,achievement_code,session_id,unlocked_at) VALUES (?,?,?,?)', [$userId, $achievement['code'], $sessionId, $now]);
            $awarded[] = ['code' => $achievement['code'], 'title' => $achievement['title'], 'unlocked_at' => Time::iso($now)];
        }
        return $awarded;
    }

    public function history(int $userId, int $page, int $perPage): array
    {
        $total = (int) $this->db->one("SELECT COUNT(*) AS count FROM training_sessions WHERE user_id=? AND status='finished'", [$userId])['count'];
        $rows = $this->db->all("SELECT * FROM training_sessions WHERE user_id=? AND status='finished' ORDER BY finished_at DESC,id DESC LIMIT ? OFFSET ?", [$userId, $perPage, ($page - 1) * $perPage]);
        return ['items' => array_map(fn ($row) => self::view($row), $rows), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    public static function view(array $row): array
    {
        $result = $row['finish_response'] === null ? null : json_decode($row['finish_response'], true, 512, JSON_THROW_ON_ERROR)['result'];
        return ['public_id' => $row['public_id'], 'status' => $row['status'], 'mode' => $row['mode'], 'language' => $row['language'],
            'difficulty' => $row['difficulty'], 'code_language' => $row['code_language'], 'started_at' => Time::iso($row['started_at']),
            'finished_at' => Time::iso($row['finished_at']), 'result' => $result];
    }
}
