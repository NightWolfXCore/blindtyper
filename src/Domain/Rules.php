<?php

declare(strict_types=1);

namespace BlindTyper\Domain;

use BlindTyper\Http\ApiException;

final class Rules
{
    public const VERSION = 1;
    public const MAX_DURATION = 1800000;
    public const MODES = ['words' => 1, 'sentences' => 2, 'symbols' => 3, 'code' => 4];
    public const LANGUAGES = ['ru', 'en'];
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];
    public const CODE_LANGUAGES = ['python', 'javascript', 'html', 'css', 'mixed'];
    public const LENGTHS = [120, 240, 1200];

    public static function progress(int $xp): array
    {
        $level = 1 + (int) floor(sqrt($xp / 100));
        return ['total_xp' => $xp, 'level' => $level, 'next_level_xp' => 100 * $level ** 2];
    }

    public static function multiplier(int $streak): int
    {
        return match (true) {
            $streak >= 100 => 5, $streak >= 50 => 3, $streak >= 20 => 2, default => 1
        };
    }

    public static function options(array $input): array
    {
        $allowed = ['mode' => array_keys(self::MODES), 'language' => self::LANGUAGES,
            'difficulty' => self::DIFFICULTIES, 'length' => self::LENGTHS];
        foreach ($allowed as $key => $choices) {
            if (!isset($input[$key]) || !in_array($input[$key], $choices, true)) {
                throw ApiException::validation('Некорректные настройки', [$key => 'Недопустимое значение']);
            }
        }
        $code = $input['code_language'] ?? null;
        if (($input['mode'] === 'code' && ($input['language'] !== 'en' || !in_array($code, self::CODE_LANGUAGES, true)))
            || ($input['mode'] !== 'code' && $code !== null)) {
            throw ApiException::validation('Некорректный язык кода', ['code_language' => 'Код поддерживается только с раскладкой en']);
        }
        return array_intersect_key($input, $allowed) + ['code_language' => $code];
    }

    public static function catalog(int $xp): array
    {
        $modes = [];
        foreach (self::MODES as $mode => $level) {
            $modes[] = ['id' => $mode, 'required_level' => $level, 'unlocked' => self::progress($xp)['level'] >= $level];
        }
        return ['rules_version' => self::VERSION, 'languages' => self::LANGUAGES, 'difficulties' => self::DIFFICULTIES,
            'modes' => $modes, 'code_languages' => self::CODE_LANGUAGES, 'lengths' => self::LENGTHS,
            'scoring' => ['xp_per_correct' => 1, 'combo_tiers' => [['streak' => 1, 'multiplier' => 1], ['streak' => 20, 'multiplier' => 2],
                ['streak' => 50, 'multiplier' => 3], ['streak' => 100, 'multiplier' => 5]], 'wpm_word_length' => 5]];
    }
}
