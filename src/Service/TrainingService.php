<?php

declare(strict_types=1);

namespace BlindTyper\Service;

use BlindTyper\Domain\Rules;
use BlindTyper\Domain\Scoring;
use BlindTyper\Http\ApiException;
use BlindTyper\Repository\Database;
use BlindTyper\Repository\TrainingRepository;
use BlindTyper\Repository\UserRepository;
use BlindTyper\Support\Clock;
use BlindTyper\Support\Time;

final class TrainingService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly TrainingRepository $sessions,
        private readonly Scoring $scoring,
        private readonly Clock $clock
    ) {
    }

    public function create(int $userId, array $input): array
    {
        $options = Rules::options($input);
        return $this->db->transaction(function () use ($userId, $options) {
            $user = $this->users->find($userId, true) ?? throw ApiException::notFound();
            if (Rules::progress((int) $user['total_xp'])['level'] < Rules::MODES[$options['mode']]) {
                throw new ApiException(403, 'MODE_LOCKED', 'Режим ещё не открыт');
            }
            $exercise = $this->sessions->exercise($options);
            $text = $exercise['text'];
            if ($text === '' || !\Normalizer::isNormalized($text, \Normalizer::FORM_C) || str_contains($text, "\t") || str_contains($text, "\r")
                || mb_strlen($text, 'UTF-8') !== (int) $exercise['text_length']) {
                throw new \RuntimeException('Invalid exercise data');
            }
            $this->sessions->abandon($userId);
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
            $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
            $hex = bin2hex($bytes);
            $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
            $start = random_int(3, 8) * 1000;
            $type = random_int(0, 1) === 0 ? 'double_xp' : 'speed_boost';
            $bonuses = [['type' => $type, 'starts_at_ms' => $start, 'ends_at_ms' => $start + 10000]];
            $row = $this->sessions->create($id, $userId, $exercise, $options, $bonuses, $this->clock->now());
            return ['public_id' => $id, 'status' => 'created', 'text' => $text, 'text_length' => (int) $row['text_length'], 'mode' => $row['mode'],
                'language' => $row['language'], 'difficulty' => $row['difficulty'], 'code_language' => $row['code_language'],
                'rules_version' => Rules::VERSION, 'max_duration_ms' => Rules::MAX_DURATION, 'expires_at' => Time::iso($row['expires_at']), 'bonuses' => $bonuses];
        });
    }

    public function start(int $userId, string $id): array
    {
        $response = $this->db->transaction(function () use ($userId, $id) {
            $this->users->find($userId, true) ?? throw ApiException::notFound();
            $row = $this->sessions->find($id, $userId, true);
            $now = $this->clock->now();
            if (!in_array($row['status'], ['created', 'started'], true)) {
                throw ApiException::state();
            }
            if (Time::elapsed($row['expires_at'], $now) > 0) {
                $this->sessions->expire((int) $row['id']);
                return null;
            }
            if ($row['status'] === 'created') {
                $this->sessions->start((int) $row['id'], $now);
                $row['started_at'] = Time::sql($now);
            }
            return ['status' => 'started', 'started_at' => Time::iso($row['started_at']), 'max_duration_ms' => Rules::MAX_DURATION];
        });
        return $response ?? throw ApiException::state();
    }

    public function finish(int $userId, string $id, array $input): array
    {
        $events = $this->scoring->events($input['events'] ?? null);
        $hash = $this->scoring->hash($events);
        $response = $this->db->transaction(function () use ($userId, $id, $events, $hash) {
            $user = $this->users->find($userId, true) ?? throw ApiException::notFound();
            $row = $this->sessions->find($id, $userId, true);
            $now = $this->clock->now();
            if ($row['status'] === 'finished') {
                if (!hash_equals($row['events_hash'], $hash)) {
                    throw new ApiException(409, 'RESULT_CONFLICT', 'Результат уже сохранён с другими событиями');
                }
                return json_decode($row['finish_response'], true, 512, JSON_THROW_ON_ERROR);
            }
            if ($row['status'] !== 'started') {
                throw ApiException::state();
            }
            if (Time::elapsed($row['expires_at'], $now) > 60000) {
                $this->sessions->expire((int) $row['id']);
                return null;
            }
            if ((int) $row['rules_version'] !== Rules::VERSION) {
                throw ApiException::state();
            }
            $score = $this->scoring->calculate($row['text_snapshot'], $events, json_decode($row['bonuses'], true, 512, JSON_THROW_ON_ERROR), Time::elapsed($row['started_at'], $now));
            $stamp = Time::sql($now);
            $this->users->award($userId, $score['result']['xp_earned'], $stamp);
            $this->sessions->characterStats((int) $row['id'], $score['character_stats']);
            $achievements = $this->sessions->achievements($userId, (int) $row['id'], $score, $stamp);
            $response = ['public_id' => $id, 'status' => 'finished', 'result' => $score['result'],
                'progress' => Rules::progress((int) $user['total_xp'] + $score['result']['xp_earned']), 'new_achievements' => $achievements];
            $this->sessions->finish((int) $row['id'], $score['result'], $hash, $response, $stamp);
            return $response;
        });
        return $response ?? throw ApiException::state();
    }
}
