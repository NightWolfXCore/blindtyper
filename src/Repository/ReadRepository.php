<?php

declare(strict_types=1);

namespace BlindTyper\Repository;

use BlindTyper\Support\Clock;
use BlindTyper\Support\Time;

final class ReadRepository
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function achievements(int $userId): array
    {
        $rows = $this->db->all('SELECT a.code,a.title,a.description,u.unlocked_at FROM achievements a LEFT JOIN user_achievements u ON a.code=u.achievement_code AND u.user_id=? ORDER BY a.code', [$userId]);
        foreach ($rows as &$row) {
            $row['unlocked_at'] = Time::iso($row['unlocked_at']);
        }
        return ['items' => $rows];
    }

    public function stats(int $userId, int $days): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $start = $today->modify('-' . ($days - 1) . ' days');
        $end = $today->modify('+1 day');
        $totals = $this->db->one("SELECT COUNT(*) AS training_count,COALESCE(SUM(duration_ms),0) AS training_time_ms,
            COALESCE(MAX(correct_count*60000.0/duration_ms),0) AS best_cpm,SUM(correct_count) AS correct_count,SUM(error_count) AS error_count
            FROM training_sessions WHERE user_id=? AND status='finished'", [$userId]);
        $attempts = (int) $totals['correct_count'] + (int) $totals['error_count'];
        $view = ['training_count' => (int) $totals['training_count'], 'training_time_ms' => (int) $totals['training_time_ms'],
            'best_cpm' => round((float) $totals['best_cpm'], 2), 'accuracy' => $attempts ? round(100 * (int) $totals['correct_count'] / $attempts, 2) : null];
        $rows = $this->db->all("SELECT DATE(finished_at) AS date,COUNT(*) AS training_count,SUM(correct_count) AS correct_count,
            SUM(error_count) AS error_count,SUM(duration_ms) AS duration_ms FROM training_sessions
            WHERE user_id=? AND status='finished' AND finished_at>=? AND finished_at<? GROUP BY DATE(finished_at)", [$userId, Time::sql($start), Time::sql($end)]);
        $byDate = array_column($rows, null, 'date');
        $daily = [];
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $row = $byDate[$key] ?? null;
            $daily[] = ['date' => $key, 'training_count' => (int) ($row['training_count'] ?? 0),
                'cpm' => $row ? round((float) $row['correct_count'] * 60000 / (float) $row['duration_ms'], 2) : null,
                'accuracy' => $row ? round(100 * (float) $row['correct_count'] / ((float) $row['correct_count'] + (float) $row['error_count']), 2) : null];
        }
        $characters = $this->db->all("SELECT c.expected_char AS `char`,SUM(c.attempts) AS attempts,SUM(c.errors) AS errors,
            100.0*SUM(c.errors)/SUM(c.attempts) AS error_rate FROM session_character_stats c
            JOIN training_sessions s ON c.session_id=s.id WHERE s.user_id=? AND s.status='finished' AND s.finished_at>=? AND s.finished_at<?
            GROUP BY c.expected_char HAVING SUM(c.attempts)>=10 ORDER BY error_rate DESC,errors DESC,BINARY c.expected_char ASC LIMIT 10", [$userId, Time::sql($start), Time::sql($end)]);
        foreach ($characters as &$row) {
            $row['attempts'] = (int) $row['attempts'];
            $row['errors'] = (int) $row['errors'];
            $row['error_rate'] = round((float) $row['error_rate'], 2);
        }
        return ['totals' => $view, 'daily' => $daily, 'difficult_characters' => $characters];
    }

    public function leaderboard(string $metric, string $period, ?string $mode, ?string $language, int $page, int $perPage): array
    {
        $where = "s.status='finished' AND s.ranked=1 AND u.is_demo=0";
        $params = [];
        $now = $this->clock->now();
        if ($period !== 'all') {
            $start = $now->setTime(0, 0);
            if ($period === 'week') {
                $start = $start->modify('-' . ((int) $start->format('N') - 1) . ' days');
            }
            $where .= ' AND s.finished_at>=?';
            $params[] = Time::sql($start);
        }
        $where .= ' AND s.finished_at<=?';
        $params[] = Time::sql($now);
        if ($mode !== null) {
            $where .= ' AND s.mode=?';
            $params[] = $mode;
        }
        if ($language !== null) {
            $where .= ' AND s.language=?';
            $params[] = $language;
        }
        $total = (int) $this->db->one("SELECT COUNT(DISTINCT u.id) AS count FROM training_sessions s JOIN users u ON u.id=s.user_id WHERE $where", $params)['count'];
        if ($metric === 'xp') {
            $sql = "SELECT u.id,u.display_name,SUM(s.xp_earned) AS value FROM training_sessions s JOIN users u ON u.id=s.user_id WHERE $where GROUP BY u.id,u.display_name ORDER BY value DESC,u.id ASC LIMIT ? OFFSET ?";
        } else {
            $cpm = 's.correct_count*60000.0/s.duration_ms';
            $accuracy = 's.correct_count*100.0/(s.correct_count+s.error_count)';
            // Both interpolated expressions are chosen exclusively from constants, never request input.
            $primary = $metric === 'cpm' ? $cpm : $accuracy;
            $secondary = $metric === 'cpm' ? $accuracy : $cpm;
            $sql = "SELECT id,display_name,cpm,accuracy,value FROM (SELECT u.id,u.display_name,$cpm AS cpm,$accuracy AS accuracy,$primary AS value,
                ROW_NUMBER() OVER (PARTITION BY u.id ORDER BY $primary DESC,$secondary DESC,s.finished_at ASC,s.id ASC) AS rn
                FROM training_sessions s JOIN users u ON u.id=s.user_id WHERE $where) best WHERE rn=1 ORDER BY value DESC,id ASC LIMIT ? OFFSET ?";
        }
        $rows = $this->db->all($sql, [...$params, $perPage, ($page - 1) * $perPage]);
        $items = [];
        foreach ($rows as $i => $row) {
            $items[] = ['rank' => ($page - 1) * $perPage + $i + 1, 'user' => ['id' => (int) $row['id'], 'display_name' => $row['display_name']],
                'value' => $metric === 'xp' ? (int) $row['value'] : round((float) $row['value'], 2),
                'cpm' => $metric === 'xp' ? null : round((float) $row['cpm'], 2), 'wpm' => $metric === 'xp' ? null : round((float) $row['cpm'] / 5, 2),
                'accuracy' => $metric === 'xp' ? null : round((float) $row['accuracy'], 2)];
        }
        return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }
}
