<?php

declare(strict_types=1);

namespace BlindTyper\Service;

use BlindTyper\Domain\Rules;
use BlindTyper\Repository\Database;

/** Extends the authored starter texts to every option advertised by the API. */
final class CatalogSeed
{
    private const EXTRA_WORDS = [
        'ru' => [
            'easy' => 'дом кот лес мир река книга стол вода рука школа',
            'medium' => 'комната монитор алгоритм переменная функция скорость точность рейтинг профиль',
            'hard' => 'архитектор интерфейс технология дисциплина инженерия алгоритмы системный структура механика',
        ],
        'en' => [
            'easy' => 'cat dog tree book moon star water house school game',
            'medium' => 'keyboard monitor program variable function training accuracy profile',
            'hard' => 'challenge interface algorithm structure security compiler mechanics practice',
        ],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function complete(): void
    {
        $sourceRows = $this->db->all('SELECT * FROM exercises WHERE id BETWEEN 1 AND 30 ORDER BY id');
        if (count($sourceRows) !== 30) {
            throw new \RuntimeException('The authored exercise seed is incomplete');
        }

        foreach ($sourceRows as $source) {
            $text = $source['text'];
            if ($text === '' || str_contains($text, "\t") || str_contains($text, "\r")
                || !\Normalizer::isNormalized($text, \Normalizer::FORM_C)) {
                throw new \RuntimeException('An authored exercise contains unsupported text');
            }
            // Initial SQL contains hand-written lengths; use the stored text as the source of truth.
            $this->db->execute('UPDATE exercises SET text_length = ? WHERE id = ?', [mb_strlen($text, 'UTF-8'), $source['id']]);

            foreach (Rules::LENGTHS as $length) {
                $expanded = $this->expand($source, $length);
                $this->db->execute(
                    'INSERT INTO exercises (mode,language,difficulty,code_language,text,text_length,active,created_at)
                     VALUES (?,?,?,?,?,?,1,UTC_TIMESTAMP(3))',
                    [$source['mode'], $source['language'], $source['difficulty'], $source['code_language'],
                     $expanded, mb_strlen($expanded, 'UTF-8')]
                );
            }

            if ($source['mode'] === 'words' && !$this->validWords($text)) {
                $this->db->execute('UPDATE exercises SET active = 0 WHERE id = ?', [$source['id']]);
            }
        }
    }

    private function expand(array $source, int $target): string
    {
        $text = $source['text'];
        if ($source['mode'] === 'words') {
            $candidate = $text . ' ' . self::EXTRA_WORDS[$source['language']][$source['difficulty']];
            $words = array_values(array_filter(preg_split('/\s+/u', trim($candidate)), fn (string $word): bool => $this->validWord($word)));
            $output = '';
            for ($index = 0; ; $index++) {
                $next = $words[$index % count($words)];
                $candidate = $output === '' ? $next : $output . ' ' . $next;
                if (mb_strlen($candidate, 'UTF-8') > $target) {
                    break;
                }
                $output = $candidate;
            }
            return $output;
        }

        if ($source['mode'] === 'sentences') {
            $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text));
            $output = '';
            $low = $target === 1200 ? 1000 : (int) floor($target * .8);
            $high = (int) ceil($target * 1.2);
            while (mb_strlen($output, 'UTF-8') < $low) {
                $next = null;
                foreach ($sentences as $sentence) {
                    $candidate = $output === '' ? $sentence : $output . ' ' . $sentence;
                    if (mb_strlen($candidate, 'UTF-8') <= $high) {
                        $next = $candidate;
                        break;
                    }
                }
                if ($next === null) {
                    throw new \RuntimeException('Sentences do not fit the requested exercise length');
                }
                $output = $next;
            }
            return $output;
        }

        $separator = $source['mode'] === 'code' ? "\n" : ' ';
        $output = $text;
        while (mb_strlen($output, 'UTF-8') < $target) {
            $output .= $separator . $text;
        }
        return rtrim(mb_substr($output, 0, $target, 'UTF-8'));
    }

    private function validWords(string $text): bool
    {
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            if (!$this->validWord($word)) {
                return false;
            }
        }
        return true;
    }

    private function validWord(string $word): bool
    {
        $length = mb_strlen($word, 'UTF-8');
        return $length >= 2 && $length <= 10 && preg_match('/^\p{L}+$/u', $word) === 1;
    }
}
