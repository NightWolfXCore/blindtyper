<?php

declare(strict_types=1);

namespace BlindTyper\Domain;

use BlindTyper\Http\ApiException;

final class Scoring
{
    /** Validate and canonicalize BEFORE hashing: object property order is irrelevant, types are strict. */
    public function events(mixed $events): array
    {
        if (!is_array($events) || !array_is_list($events) || count($events) < 1 || count($events) > 20000) {
            throw ApiException::validation('Требуется от 1 до 20000 событий');
        }
        $canonical = [];
        $lastTime = 0;
        foreach ($events as $i => $event) {
            if ($event instanceof \stdClass) {
                $event = (array) $event;
            }
            if (!is_array($event) || !isset($event['seq'], $event['at_ms'], $event['char'])
                || !is_int($event['seq']) || $event['seq'] !== $i + 1
                || !is_int($event['at_ms']) || $event['at_ms'] < $lastTime || $event['at_ms'] > Rules::MAX_DURATION
                || !is_string($event['char']) || !mb_check_encoding($event['char'], 'UTF-8') || mb_strlen($event['char'], 'UTF-8') !== 1
                || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/u', $event['char'])) {
                throw ApiException::validation('Некорректное событие', ['events' => 'Ошибка события ' . ($i + 1)]);
            }
            $lastTime = $event['at_ms'];
            $canonical[] = [$event['seq'], $event['at_ms'], $event['char']];
        }
        if ($lastTime <= 0) {
            throw ApiException::validation('Длительность должна быть положительной');
        }
        return $canonical;
    }

    public function hash(array $canonical): string
    {
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function calculate(string $text, array $events, array $bonuses, int $serverElapsedMs): array
    {
        $duration = $events[array_key_last($events)][1];
        if ($duration > $serverElapsedMs + 2000) {
            throw ApiException::validation('Время клиента превышает серверное');
        }
        $chars = mb_str_split($text, 1, 'UTF-8');
        if ($chars === []) {
            throw new \RuntimeException('Empty exercise snapshot');
        }
        $correct = $errors = $streak = $maxCombo = $xp = 0;
        $stats = [];
        foreach ($events as [, $at, $char]) {
            if ($correct >= count($chars)) {
                throw ApiException::validation('Лишние события после завершения текста');
            }
            $expected = $chars[$correct];
            // Prefix keys so numeric characters cannot be coerced to integer keys by PHP.
            $key = 'char:' . $expected;
            $stats[$key] ??= ['char' => $expected, 'attempts' => 0, 'errors' => 0];
            $stats[$key]['attempts']++;
            if ($char !== $expected) {
                $errors++;
                $streak = 0;
                $stats[$key]['errors']++;
                continue;
            }
            $correct++;
            $streak++;
            $maxCombo = max($maxCombo, $streak);
            $bonusMultiplier = 1;
            foreach ($bonuses as $bonus) {
                if ($bonus['type'] === 'double_xp' && $at >= $bonus['starts_at_ms'] && $at < $bonus['ends_at_ms']) {
                    $bonusMultiplier = 2;
                }
            }
            $xp += Rules::multiplier($streak) * $bonusMultiplier;
        }
        if ($correct !== count($chars)) {
            throw ApiException::validation('Текст не завершён');
        }
        $cpm = $correct * 60000 / $duration;
        $reason = $correct < 100 || $duration < 10000 ? 'TOO_SHORT' : ($cpm > 1500 ? 'SPEED_LIMIT' : null);
        return ['result' => ['duration_ms' => $duration, 'correct' => $correct, 'errors' => $errors,
            'attempts' => $correct + $errors, 'cpm' => round($cpm, 2), 'wpm' => round($cpm / 5, 2),
            'accuracy' => round(100 * $correct / ($correct + $errors), 2), 'max_combo' => $maxCombo,
            'xp_earned' => $xp, 'ranked' => $reason === null, 'unranked_reason' => $reason],
            'character_stats' => array_values($stats), 'raw_cpm' => $cpm];
    }
}
