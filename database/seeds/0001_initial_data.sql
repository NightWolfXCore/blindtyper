-- BlindTyper initial data v1
-- Safe to run repeatedly: existing rows with the same primary key are kept.

INSERT INTO `achievements` (`code`, `title`, `description`, `rule_key`, `threshold`) VALUES
('first_training', 'Первый шаг', 'Завершите первую тренировку', 'finished_count', 1),
('speed_200', 'Скоростной печатник', 'Достичь скорости 200 символов в минуту', 'session_cpm', 200),
('streak_1000', 'Стальной палец', 'Сделать 1000 правильных вводов подряд', 'session_max_combo', 1000)
ON DUPLICATE KEY UPDATE `code` = `code`;

INSERT INTO `exercises`
(`id`, `mode`, `language`, `difficulty`, `code_language`, `text`, `text_length`, `active`) VALUES
(1, 'words', 'ru', 'easy', NULL, 'дом кот лес мир мак мама папа брат сестра вода еда рука нога стол стул шкаф дверь снег дождь ветер солнце книга ручка школа класс', 129, 1),
(2, 'words', 'ru', 'medium', NULL, 'комната компьютер клавиатура монитор телефон программа алгоритм переменная функция пользователь тренировка скорость точность результат ошибка уровень опыт достижение таблица рейтинг статистика профиль', 200, 1),
(3, 'words', 'ru', 'hard', NULL, 'производительность взаимодействие электричество внимательность последовательность концентрация автоматизация конфигурация аутентификация производительность разработка программирование информационный технологический пользовательский синхронизация безопасность оптимизация', 270, 1),
(4, 'words', 'en', 'easy', NULL, 'cat dog sun moon star book pen house tree water red blue green black white game player school mouse', 99, 1),
(5, 'words', 'en', 'medium', NULL, 'computer keyboard monitor window program algorithm variable function user training speed accuracy result error level experience achievement profile statistics', 158, 1),
(6, 'words', 'en', 'hard', NULL, 'authentication configuration development interaction electricity concentration automation synchronization optimization productivity programming information technological application implementation accessibility performance', 222, 1),
(7, 'sentences', 'ru', 'easy', NULL, 'Я учусь печатать. Сегодня хороший день. Кот спит на диване. Я люблю компьютерные игры. Солнце светит ярко. Тренировка начинается сейчас.', 136, 1),
(8, 'sentences', 'ru', 'medium', NULL, 'Я стараюсь не смотреть на клавиатуру во время тренировки. Каждая новая попытка помогает мне становиться быстрее и точнее. Ошибки показывают, над чем нужно работать.', 164, 1),
(9, 'sentences', 'ru', 'hard', NULL, 'Внимательность и точность важнее случайных быстрых нажатий. Регулярные занятия помогают сформировать полезные привычки. Постепенно усложняя задания, можно добиться стабильного результата и уверенно печатать длинные тексты.', 222, 1),
(10, 'sentences', 'en', 'easy', NULL, 'I can type. Today is a good day. The cat is sleeping. I like computer games. The sun is bright. The training starts now.', 120, 1),
(11, 'sentences', 'en', 'medium', NULL, 'I try not to look at the keyboard during practice. Every new attempt helps me become faster and more accurate. Mistakes show me what I need to improve.', 151, 1),
(12, 'sentences', 'en', 'hard', NULL, 'Accuracy is more important than random speed. Consistent practice builds useful habits. By making the exercises harder step by step, a player can achieve stable results and type long texts with confidence.', 205, 1),
(13, 'symbols', 'ru', 'easy', NULL, '12345 67890 абвгд АБВГД 123 abc 456 тест 789', 44, 1),
(14, 'symbols', 'ru', 'medium', NULL, 'Привет! 2026 @ тест # печать $ скорость % точность & опыт * уровень + игра = результат', 86, 1),
(15, 'symbols', 'ru', 'hard', NULL, 'Пользователь_01 = 200; скорость >= 150; точность != 95%; уровень + 1; опыт * 2; результат = (очки + бонус) / 2.', 111, 1),
(16, 'symbols', 'en', 'easy', NULL, '12345 67890 abc XYZ 123 test 456 hello 789', 42, 1),
(17, 'symbols', 'en', 'medium', NULL, 'user_01 test-02 data_03 score=100 level+1 xp*2 result/2', 55, 1),
(18, 'symbols', 'en', 'hard', NULL, '(a + b) * 10 >= 50 && x != 0 || y <= 100; user@example.com #typing_2026', 71, 1),
(19, 'code', 'en', 'easy', 'python', 'print("Hello, world!")', 22, 1),
(20, 'code', 'en', 'medium', 'python', 'name = "Player"\nage = 18\nprint(name)\nprint(age)', 50, 1),
(21, 'code', 'en', 'hard', 'python', 'def calculate_score(correct, errors):\n    score = correct * 10\n    score -= errors * 2\n    return max(score, 0)\n\nresult = calculate_score(120, 5)\nprint(result)', 165, 1),
(22, 'code', 'en', 'easy', 'javascript', 'console.log("Hello, world!");', 29, 1),
(23, 'code', 'en', 'medium', 'javascript', 'const score = 100;\nif (score > 50) {\n    console.log(score);\n}', 65, 1),
(24, 'code', 'en', 'hard', 'javascript', 'const numbers = [1, 2, 3, 4, 5];\nconst result = numbers.map(x => x * 2);\nconsole.log(result);', 95, 1),
(25, 'code', 'en', 'easy', 'html', '<h1>Hello, world!</h1>', 22, 1),
(26, 'code', 'en', 'medium', 'html', '<button class="start">Start</button>\n<p>Begin your typing training.</p>', 72, 1),
(27, 'code', 'en', 'hard', 'html', '<div class="card">\n    <h2>Typing Hero</h2>\n    <p>Practice every day and improve your speed.</p>\n    <button class="start">Start training</button>\n</div>', 158, 1),
(28, 'code', 'en', 'easy', 'css', 'color: purple;', 14, 1),
(29, 'code', 'en', 'medium', 'css', 'display: flex;\ngap: 16px;\npadding: 20px;', 42, 1),
(30, 'code', 'en', 'hard', 'css', 'background: linear-gradient(90deg, #222, #8050c8);\nborder-radius: 12px;\npadding: 24px;\ndisplay: flex;\nalign-items: center;\njustify-content: center;', 152, 1)
ON DUPLICATE KEY UPDATE `id` = `id`;
