<?php

declare(strict_types=1);

namespace BlindTyper\Repository;

/** Splits the project's SQL files without splitting semicolons inside strings or comments. */
final class SqlFile
{
    /** @return list<string> */
    public static function statements(string $path): array
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read SQL file: {$path}");
        }

        $statements = [];
        $buffer = '';
        $quote = null;
        $inComment = false;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($inComment) {
                if ($char === "\n") {
                    $inComment = false;
                    $buffer .= "\n";
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $buffer .= $source[++$i];
                } elseif ($char === $quote) {
                    if ($i + 1 < $length && $source[$i + 1] === $quote) {
                        $buffer .= $source[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === '#' || ($char === '-' && $i + 2 < $length
                    && $source[$i + 1] === '-' && ctype_space($source[$i + 2]))) {
                $inComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if ($quote !== null || trim($buffer) !== '') {
            throw new \RuntimeException("Incomplete SQL statement: {$path}");
        }

        return $statements;
    }
}
