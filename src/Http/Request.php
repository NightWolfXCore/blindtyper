<?php

declare(strict_types=1);

namespace BlindTyper\Http;

final readonly class Request
{
    public const MAX_BYTES = 2097152;

    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $body,
        public string $ip,
        public ?string $csrf
    ) {
    }

    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $body = [];
        if ($method === 'POST') {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BYTES) {
                throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'Слишком большой запрос');
            }
            $stream = fopen('php://input', 'rb');
            $raw = stream_get_contents($stream, self::MAX_BYTES + 1);
            fclose($stream);
            if (strlen($raw) > self::MAX_BYTES) {
                throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'Слишком большой запрос');
            }
            if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                throw ApiException::validation('Требуется Content-Type: application/json');
            }
            try {
                $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiException(400, 'INVALID_JSON', 'Некорректный JSON');
            }
            if (!$object instanceof \stdClass) {
                throw ApiException::validation('Тело запроса должно быть объектом');
            }
            $body = (array) $object;
        }
        return new self(
            $method,
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $_GET,
            $body,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null
        );
    }

    public function integer(string $name, int $default, int $min, int $max): int
    {
        if (!array_key_exists($name, $this->query)) {
            return $default;
        }
        $value = $this->query[$name];
        if (!is_string($value) || !preg_match('/^[0-9]{1,9}$/D', $value) || (int) $value < $min || (int) $value > $max) {
            throw ApiException::validation('Некорректный параметр', [$name => "Допустимо {$min}–{$max}"]);
        }
        return (int) $value;
    }

    public function choice(string $name, array $allowed, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? $default;
        if ($value !== null && (!is_string($value) || !in_array($value, $allowed, true))) {
            throw ApiException::validation('Некорректный параметр', [$name => 'Недопустимое значение']);
        }
        return $value;
    }
}
