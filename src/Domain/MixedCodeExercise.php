<?php

declare(strict_types=1);

namespace BlindTyper\Domain;

/** A virtual exercise: complete snippets from all four supported code languages. */
final class MixedCodeExercise
{
    private const SNIPPETS = [
        'easy' => "Python:\nprint('Hello')\nJavaScript:\nconsole.log('Hello');\nHTML:\n<h1>Hello</h1>\nCSS:\nh1 { color: blue; }",
        'medium' => "Python:\nsum([1, 2])\nJavaScript:\n[1, 2].map(x => x * 2)\nHTML:\n<button>Go</button>\nCSS:\nbutton { padding: 8px; }",
        'hard' => "Python:\n[x*x for x in xs]\nJavaScript:\nitems.filter(x => x.ok)\nHTML:\n<form><input required></form>\nCSS:\nform { display: grid; }",
    ];

    public static function make(string $difficulty, int $target): array
    {
        $snippet = self::SNIPPETS[$difficulty];
        $low = $target === 1200 ? 1000 : (int) floor($target * .8);
        $high = (int) ceil($target * 1.2);
        $text = $snippet;
        while (mb_strlen($text, 'UTF-8') < $low) {
            $text .= "\n\n" . $snippet;
        }
        $length = mb_strlen($text, 'UTF-8');
        if ($length > $high) {
            throw new \RuntimeException('Mixed code exercise does not fit the requested length');
        }
        return ['id' => null, 'text' => $text, 'text_length' => $length];
    }
}
