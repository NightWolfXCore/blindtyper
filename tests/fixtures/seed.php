<?php

declare(strict_types=1);
use BlindTyper\Domain\Rules;
use BlindTyper\Repository\Database;

return static function (Database $db): void {
    $now = gmdate('Y-m-d H:i:s');
    $achievements = [['first_training', 'Первый шаг', 'Завершите тренировку', 'finished_count', 1],
        ['speed_200', 'Быстрые пальцы', 'Достигните 200 символов в минуту', 'session_cpm', 200],
        ['streak_1000', 'Стальной палец', '1000 правильных вводов подряд', 'session_max_combo', 1000]];
    foreach ($achievements as $a) {
        if ($db->one('SELECT code FROM achievements WHERE code=?', [$a[0]]) === null) {
            $db->execute('INSERT INTO achievements (code,title,description,rule_key,threshold) VALUES (?,?,?,?,?)', $a);
        }
    }
    // Small deterministic compatibility corpus. The database/content owner supplies production exercises.
    foreach (array_keys(Rules::MODES) as $mode) {
        foreach (($mode === 'code' ? ['en'] : Rules::LANGUAGES) as $language) {
            foreach (Rules::DIFFICULTIES as $difficulty) {
                foreach (Rules::LENGTHS as $length) {
                    foreach (($mode === 'code' ? array_values(array_diff(Rules::CODE_LANGUAGES, ['mixed'])) : [null]) as $code) {
                        $base = match($mode) {
                            'words' => $language === 'ru' ? 'дом кот мир река книга лес ' : 'home cat tree sky ',
                            'sentences' => $language === 'ru' ? 'Печатать полезно. ' : 'Practice every day. ',
                            'symbols' => $language === 'ru' ? 'фыва-123 ёж! 456? ' : 'asdf-123 QW! 456? ',
                            'code' => match($code) {
                                'python' => "for item in items:\n    print(item)\n",
                                'javascript' => "const items = [1, 2, 3];\nitems.forEach(console.log);\n",
                                'html' => "<section>\n  <h1>Hello</h1>\n</section>\n",
                                'css' => ".card {\n  color: blue;\n  padding: 12px;\n}\n",
                            },
                        };
                        $text = '';
                        while (mb_strlen($text, 'UTF-8') < $length) {
                            $text .= $base;
                        }
                        if ($mode === 'code' && mb_strlen($text, 'UTF-8') > (int) ceil($length * 1.2)) {
                            $text = mb_substr($text, 0, $length, 'UTF-8');
                        }
                        $size = mb_strlen($text, 'UTF-8');
                        if ($db->one('SELECT id FROM exercises WHERE mode=? AND language=? AND difficulty=? AND code_language <=> ? AND text_length=? AND text=?', [$mode, $language, $difficulty, $code, $size, $text]) !== null) {
                            continue;
                        }
                        $db->execute('INSERT INTO exercises (mode,language,difficulty,code_language,text,text_length,active,created_at) VALUES (?,?,?,?,?,?,1,?)', [$mode, $language, $difficulty, $code, $text, $size, $now]);
                    }
                }
            }
        }
    }
};
