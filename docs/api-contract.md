# Контракт HTTP API v1

Базовый путь: `/api/v1`. Контракт реализован PHP-бэкендом; запуск и интеграция описаны в README и backend.md.

## Общие правила

- JSON UTF-8; запросы с телом имеют `Content-Type: application/json`.
- Время в ответах: ISO 8601 UTC, например `2026-10-19T12:00:00Z`.
- Идентификаторы: положительные целые числа; `public_id` тренировки — UUID v4 в строке. Email обрезается по краям и приводится к нижнему регистру.
- Успех: `{"data": ...}`. Ошибка: `{"error":{"code":"VALIDATION_ERROR","message":"Проверьте поля","fields":{"email":"Некорректный email"}}}`. `fields` необязательно.
- HTTP: 200 чтение/изменение, 201 создание, 400 неверный JSON, 401 нет входа, 403 CSRF/закрытый режим, 404 ресурс не найден или чужой, 409 неверное состояние/конфликт повтора, 413 слишком большое тело, 422 неверные данные, 429 превышен лимит, 500 внутренняя ошибка, 503 нет подходящего упражнения.
- Стабильные коды: `INVALID_JSON`, `VALIDATION_ERROR`, `UNAUTHENTICATED`, `INVALID_CREDENTIALS`, `CSRF_INVALID`, `MODE_LOCKED`, `NOT_FOUND`, `SESSION_STATE_CONFLICT`, `RESULT_CONFLICT`, `PAYLOAD_TOO_LARGE`, `RATE_LIMITED`, `INTERNAL_ERROR`, `EXERCISE_UNAVAILABLE`.
- Авторизация по cookie, fetch с `credentials: 'same-origin'`. Все POST требуют `X-CSRF-Token`, полученный через `GET /auth/csrf`. Токен обновляется после входа/регистрации/выхода; клиент повторно получает его.
- Все маршруты, кроме CSRF, входа, регистрации, каталога и рейтинга, требуют входа. Каталог без входа показывает доступность для уровня 1. Ответы с пользовательскими данными: `Cache-Control: no-store`.
- Пагинация: `page` от 1, `per_page` 1–100 (по умолчанию 20). Для списков data содержит `items`, `page`, `per_page`, `total`.

## Аккаунты

| Метод и путь | Вход | data при успехе |
| --- | --- | --- |
| GET `/auth/csrf` | — | `{"csrf_token":"..."}` |
| POST `/auth/register` | `email`, `password`, `display_name` | User, 201; сразу устанавливается сессия |
| POST `/auth/login` | `email`, `password` | User; устанавливается сессия |
| POST `/auth/logout` | `{}` | `{"logged_out":true}` |
| GET `/me` | — | User |

`User`: `{ "id":1, "display_name":"Игрок", "email":"player@example.org", "total_xp":0, "level":1, "next_level_xp":100 }`.

Email ≤254 символов, display_name 2–40 символов, пароль 10–72 байт UTF-8 (совместимо с bcrypt). Email уникален без учёта регистра. Сообщение входа не различает отсутствующий аккаунт и неверный пароль. Поле email возвращается только владельцу. Пароли и session ID никогда не возвращаются.

## Каталог

`GET /catalog` → data:

```json
{
  "rules_version": 1,
  "languages": ["ru", "en"],
  "difficulties": ["easy", "medium", "hard"],
  "modes": [
    {"id":"words","required_level":1,"unlocked":true},
    {"id":"sentences","required_level":2,"unlocked":false},
    {"id":"symbols","required_level":3,"unlocked":false},
    {"id":"code","required_level":4,"unlocked":false}
  ],
  "code_languages": ["python", "javascript", "html", "css", "mixed"],
  "lengths": [120, 240, 1200],
  "scoring": {
    "xp_per_correct": 1,
    "combo_tiers": [{"streak":1,"multiplier":1},{"streak":20,"multiplier":2},{"streak":50,"multiplier":3},{"streak":100,"multiplier":5}],
    "wpm_word_length": 5
  }
}
```

`length` — целевой размер текста. Сервер выбирает/генерирует подходящий текст и возвращает его фактическую длину `text_length`. Для кода язык раскладки фиксирован `en`, выбранный язык программирования задаётся отдельно. Значение `mixed` даёт в одном упражнении фрагменты Python, JavaScript, HTML и CSS; такой текст создаётся при создании тренировки из встроенных образцов и сохраняется в `text_snapshot`. Словари слов: длина слова 2–10 букв.

## Тренировки

### Создание

`POST /sessions`:

```json
{"mode":"words","language":"ru","difficulty":"easy","length":120,"code_language":null}
```

Поля mode/language/difficulty/length обязательны и берутся из каталога. `code_language` обязателен для mode=code, для остальных отсутствует или null. Режим code требует language=en.

Ответ 201, data:

```json
{
  "public_id":"b4c2b8b7-d387-40ee-81ae-e622278af230",
  "status":"created",
  "text":"пример учебного текста",
  "text_length":22,
  "mode":"words",
  "language":"ru",
  "difficulty":"easy",
  "code_language":null,
  "rules_version":1,
  "max_duration_ms":1800000,
  "expires_at":"2026-10-19T12:10:00Z",
  "bonuses":[{"type":"double_xp","starts_at_ms":15000,"ends_at_ms":25000}]
}
```

Пример текста сокращён для документации; `text_length` всегда равен реальной длине в code points. Созданную тренировку нужно начать в течение 10 минут. Не более одной активной тренировки пользователя: создание новой переводит предыдущую created/started в abandoned.

### Старт

`POST /sessions/{public_id}/start` с `{}` → `{"data":{"status":"started","started_at":"...","max_duration_ms":1800000}}`.

Сервер один раз фиксирует начало. Повтор для started возвращает прежний started_at и не сбрасывает часы. Клиент запускает ввод только после успешного ответа; при неопределённом сетевом исходе повтора рекомендует начать новую тренировку, чтобы не получить несогласованный таймер. Завершённую/брошенную/истёкшую тренировку начать нельзя: 409.

### Завершение

`POST /sessions/{public_id}/finish`:

```json
{
  "events":[
    {"seq":1,"at_ms":410,"char":"п"},
    {"seq":2,"at_ms":650,"char":"а"},
    {"seq":3,"at_ms":900,"char":"р"}
  ]
}
```

Пример показывает только начало: для успешного finish события должны полностью завершить серверный текст. Во втором событии ошибка, в третьем введена ожидаемая `р`. seq начинается с 1 и непрерывен; at_ms — целое число от 0 до max_duration_ms, не убывает. char — ровно один code point; `\n` допустим. Последнее событие должно завершить текст и иметь at_ms > 0; события после завершения запрещены. Максимум 20000 событий, 2 МБ на запрос. Итоговые XP/CPM/ошибки в запросе не передаются.

Ответ data:

```json
{
  "public_id":"b4c2b8b7-d387-40ee-81ae-e622278af230",
  "status":"finished",
  "result":{
    "duration_ms":60000,"correct":120,"errors":5,"attempts":125,
    "cpm":120.0,"wpm":24.0,"accuracy":96.0,"max_combo":54,
    "xp_earned":230,"ranked":true,"unranked_reason":null
  },
  "progress":{"total_xp":230,"level":2,"next_level_xp":400},
  "new_achievements":[{"code":"first_training","title":"Первый шаг","unlocked_at":"2026-10-19T12:01:00Z"}]
}
```

Числа показывают форму ответа, не являются тестовым вектором. `unranked_reason`: null, `TOO_SHORT` либо `SPEED_LIMIT`. Недопустимые события/неполный текст → 422; истёкшая или abandoned тренировка → 409. Истечение started определяется started_at + 30 минут; допускается до 60 секунд на доставку finish, но клиентская длительность не более 30 минут.

Повтор с тем же нормализованным массивом событий возвращает сохранённый ответ, включая те же progress/new_achievements. Отличающийся массив после успешного сохранения → 409 `RESULT_CONFLICT`. Хэш вычисляет сервер по каноническому массиву `[seq, at_ms, char]` после валидации типов. Повторы не начисляют XP повторно. Неуспешный finish не завершает тренировку, если она ещё не истекла.

`GET /sessions?page=1&per_page=20` → список только своих завершённых тренировок, новые сначала: `{public_id, mode, language, difficulty, finished_at, result}`.

`GET /sessions/{public_id}` → `{public_id,status,mode,language,difficulty,code_language,started_at,finished_at,result}`; result=null до окончания. Текст и сырой журнал через историю не возвращаются.

## Статистика и достижения

`GET /me/stats?days=30`: days — целое 1–90, по умолчанию 30.

```json
{
  "data": {
    "totals":{"training_count":12,"training_time_ms":720000,"best_cpm":240.0,"accuracy":96.0},
    "daily":[{"date":"2026-10-19","training_count":3,"cpm":180.0,"accuracy":96.0}],
    "difficult_characters":[{"char":"ж","attempts":40,"errors":8,"error_rate":20.0}]
  }
}
```

Totals — за всё время. daily и difficult_characters — за выбранный период, включая текущую дату UTC. Дни без тренировок возвращаются с training_count=0 и cpm/accuracy=null. CPM агрегируется как `sum(correct) × 60000 / sum(duration_ms)`; accuracy — по сумме попыток, а не как среднее процентов. Ошибки группируются по ожидаемому символу; difficult_characters — первые 10 по error_rate убыванию, затем errors убыванию, затем char по коду Unicode, только символы с attempts ≥ 10. Пустая общая статистика: счётчики и скорости 0, accuracy=null.

`GET /me/achievements` → `{ "items":[{"code":"first_training","title":"Первый шаг","description":"Завершите тренировку","unlocked_at":null}] }`. Возвращаются все достижения, без пагинации.

## Рейтинг

`GET /leaderboard?metric=cpm&period=week&mode=words&language=ru&page=1&per_page=20`.

metric: cpm (по умолчанию), accuracy, xp. period: today, week, all (по умолчанию week). today начинается в 00:00 UTC, week — в понедельник 00:00 UTC. mode/language — необязательные фильтры, без них берутся все режимы/языки. Отдельного WPM-рейтинга нет: WPM пропорционален CPM и выводится рядом.

Участвуют только завершённые ranked-тренировки, отфильтрованные по finished_at. Для cpm и accuracy берётся лучшая тренировка пользователя по выбранной метрике; при равенстве — по второй метрике, затем по раннему finished_at и ID. Для xp суммируется xp_earned подходящих тренировок. Демонстрационный аккаунт исключается из рейтинга. Порядок пользователей: value DESC, user_id ASC; rank — последовательный номер с учётом пагинации.

Ответ data: `{ "items":[{"rank":1,"user":{"id":7,"display_name":"Игрок"},"value":240.0,"cpm":240.0,"wpm":48.0,"accuracy":98.0}],"page":1,"per_page":20,"total":1 }`.

Для metric=xp значение value — целое XP, поля cpm/wpm/accuracy равны null, поскольку единственной лучшей тренировки у суммы нет. Email других игроков никогда не возвращается.


## Уточнения реализации

- GET каталога выбирает доступность по текущему XP; упражнения выбираются случайно среди подходящих активных строк БД.
- Нет подходящего упражнения → 503 EXERCISE_UNAVAILABLE; предыдущая тренировка в таком случае не бросается.
- Истечение состояния отмечается при попытке start/finish. GET истории не изменяет БД; статус активной тренировки до такой попытки может оставаться прежним.
- При совпадении нескольких причин исключения из рейтинга TOO_SHORT имеет приоритет над SPEED_LIMIT.
- char не может содержать управляющие символы U+0000–U+0009, U+000B–U+001F, U+007F. Перенос LF разрешён.
- Лимиты запросов и способы подключения описаны в backend.md. Все API-ответы имеют Cache-Control: no-store.
