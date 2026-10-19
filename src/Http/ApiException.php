<?php

declare(strict_types=1);

namespace BlindTyper\Http;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message = 'Проверьте поля', array $fields = []): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $fields);
    }

    public static function notFound(): self
    {
        return new self(404, 'NOT_FOUND', 'Ресурс не найден');
    }

    public static function state(): self
    {
        return new self(409, 'SESSION_STATE_CONFLICT', 'Недопустимое состояние тренировки');
    }
}
