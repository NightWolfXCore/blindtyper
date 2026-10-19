<?php

declare(strict_types=1);

namespace BlindTyper\Tests;

use BlindTyper\Support\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public \DateTimeImmutable $time = new \DateTimeImmutable('2026-10-19T12:00:00Z'))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $relative): void
    {
        $this->time = $this->time->modify($relative);
    }
}
