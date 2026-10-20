# Контракт базы данных v1

Этот документ задаёт границу между разработчиком БД и бэкендом. Начальная SQL-миграция и seed находятся в `database/`; их установка описана в [инструкции БД](../database/README.md). Таблицы и имена колонок ниже — общие имена для запросов PHP. Конкретные типы, дополнительные индексы и служебные поля согласуются при реализации.

## Общие требования

MySQL 8.0+, InnoDB, utf8mb4. Соединение PDO — utf8mb4, UTC. Время хранить в UTC с точностью до миллисекунд; временные смещения событий — целые миллисекунды. BIGINT UNSIGNED для внутренних ID и счётчиков XP, INT UNSIGNED для счётчиков тренировок. Публичный UUID — CHAR(36), уникальный. CHAR/VARCHAR с кодами и ключами требуют точного сравнения; ожидаемые символы — бинарное сравнение с учётом регистра. Пароли хранятся только как password_hash VARCHAR(255).

Email канонизирует бэкенд: trim + lowercase; колонка имеет UNIQUE и согласованное сравнение без учёта регистра. Отображаемое имя не уникально. Не применять к символам сравнение, считающее `е` и `ё` либо `a` и `A` одинаковыми.

Сессии авторизации PHP хранятся штатным серверным session handler; отдельная таблица токенов в v1 не нужна.

## Таблицы

| Таблица | Обязательные поля | Ограничения и назначение |
| --- | --- | --- |
| users | id, email, password_hash, display_name, total_xp, is_demo, created_at, updated_at | UNIQUE(email); total_xp DEFAULT 0; is_demo DEFAULT false. Уровень вычисляется, не хранится отдельно |
| exercises | id, mode, language, difficulty, code_language, text, text_length, active, created_at | Источники тренировок: слова/предложения/код/символы; text UTF-8 NFC, LF, без TAB; text_length по code points |
| training_sessions | id, public_id, user_id, exercise_id NULL, mode, language, difficulty, code_language NULL, text_snapshot, text_length, rules_version, bonuses, status, created_at, expires_at, started_at NULL, finished_at NULL, duration_ms NULL, correct_count NULL, error_count NULL, max_combo NULL, xp_earned NULL, ranked, unranked_reason NULL, events_hash NULL, finish_response NULL | UNIQUE(public_id); FK user_id; FK exercise_id с ON DELETE SET NULL; status: created/started/finished/abandoned/expired. bonuses JSON; finish_response JSON хранит ответ для идемпотентного повтора |
| session_character_stats | session_id, expected_char, attempts, errors | Составной PK(session_id, expected_char), FK training_sessions; expected_char VARCHAR(1) utf8mb4 с бинарным сравнением; errors ≤ attempts |
| achievements | code, title, description, rule_key, threshold | PK(code); правила обрабатывает PHP по rule_key, без SQL/JS-выражений из базы |
| user_achievements | user_id, achievement_code, session_id, unlocked_at | PK(user_id, achievement_code); FK на users, achievements, training_sessions |
| schema_migrations | version, applied_at | PK(version); учёт применённых миграций |

Не хранить отдельно WPM, CPM, accuracy и level как изменяемые источники истины: они вычисляются из счётчиков, времени и XP. Полный JSON ответа finish — неизменяемый снимок для повтора, а не источник текущего профиля. Сырые события обрабатываются в памяти; в БД сохраняются их хэш и агрегаты, без полного журнала нажатий.

`text_snapshot` и `bonuses` фиксируются при создании тренировки и не меняются при редактировании упражнения. `expires_at` сначала created_at + 10 минут, после start — started_at + 30 минут; время на доставку finish проверяет бэкенд. Поля результата обязательны у finished; у остальных состояний NULL. ranked DEFAULT false. Правила сравнения ожидаемых символов должны различать пробел, LF, регистр и `е/ё`.

Связи: users 1:N training_sessions; exercises 1:N training_sessions; training_sessions 1:N session_character_stats; users N:M achievements через user_achievements. Удаление пользователей и результатов не входит в API v1; не включать неоговорённые каскадные удаления истории.

## Транзакции бэкенда

Создание тренировки: блокировка строки users через SELECT FOR UPDATE, перевод старых активных тренировок в abandoned, вставка новой; COMMIT. Старт: та же последовательность блокировок users → training_sessions. Это исключает гонку создания и старта нескольких активных тренировок.

Завершение:

1. BEGIN; блокировать users, затем training_sessions через SELECT FOR UPDATE, всегда в одинаковом порядке.
2. Проверить владельца, состояние, срок и events_hash. При повторе вернуть сохранённый finish_response без новых изменений.
3. Проверить события и вычислить результат; записать итог тренировки и session_character_stats.
4. Увеличить users.total_xp на xp_earned, определить уровень и новые достижения.
5. Записать user_achievements и снимок finish_response; COMMIT.

Все изменения завершаются совместно либо откатываются. Уникальные ключи — дополнительная защита от дублей. Подсчёт XP реализуется в PHP; триггеры начисления не создавать.

## Индексы и запросы

Минимальные кандидаты, которые разработчик БД проверяет через EXPLAIN на тестовых данных:

- training_sessions(user_id, status, finished_at, id): история и статистика пользователя.
- training_sessions(status, ranked, finished_at, user_id): общий рейтинг за период.
- training_sessions(mode, language, status, ranked, finished_at, user_id): рейтинг с фильтрами.
- exercises(mode, language, difficulty, active, text_length): выбор упражнения.
- user_achievements(user_id, unlocked_at): достижения профиля.

Для рейтинга cpm/accuracy выбирать лучшую тренировку каждого пользователя через оконную функцию ROW_NUMBER() в MySQL 8. XP — SUM(xp_earned) в том же фильтре. Производные показатели вычислять из correct_count/error_count/duration_ms; деление на ноль исключается проверкой длительности при finish. Финальная сортировка и ничьи определены в контракте API.

Статистика ошибок использует expected_char, а не фактически ошибочно нажатую клавишу. Например, ожидалась `ж`, введена `э`: attempts и errors увеличиваются у `ж`. Правильное повторное нажатие добавляет ещё одну попытку у `ж`. Сумма attempts по символам равна correct_count + error_count сессии.

## Миграции и начальные данные

- Файлы `database/migrations/0001_*.sql`, далее последовательная нумерация. Начальную миграцию применяет `bin/install-database.php`; новые миграции требуют расширения установщика. DDL MySQL может делать implicit COMMIT: не обещать транзакционный откат всего файла.
- Повторный запуск установщика пропускает применённые версии; seed идемпотентен, повтор не создаёт дубли и не сбрасывает прогресс.
- Seed даёт упражнения всех открываемых сочетаний режима/языка/сложности и длины. Для code только en, отдельно python/javascript/html/css. В словах длина каждого слова 2–10 букв; символы и предложения содержат кириллицу/латиницу согласно language. Длины 120/240/1200 — целевые: фактическая длина может отличаться до 20%, для длинного упражнения минимум 1000 символов.
- Справочник достижений: first_training (finished_count ≥ 1), speed_200 (session_cpm ≥ 200), streak_1000 (session_max_combo ≥ 1000).
- Демонстрационный аккаунт создаётся отдельно, с is_demo=true и total_xp=900 (уровень 4). Его пароль задаётся локальной настройкой/скриптом с password_hash, не хранится открытым в SQL или репозитории. Общедоступного пароля для обычных аккаунтов нет.
- Проверить минимум: пустая база; повторное применение setup; работа FK/UNIQUE; два параллельных finish; текст с `Ё`, `ё`, `A`, `a`, кавычками и LF; история и рейтинги на тестовом наборе.

Разработчик БД передаёт бэкенду миграции, seed, команду их применения, список переменных подключения и результаты проверок. Общие переменные: DB_HOST, DB_PORT (3306), DB_NAME, DB_USER, DB_PASSWORD; сервер дополнительно использует APP_ENV, APP_URL. Секреты передаются через окружение, не через Git.
