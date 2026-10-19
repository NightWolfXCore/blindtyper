<?php

declare(strict_types=1);

namespace BlindTyper\Support;

final class Time
{
    public static function sql(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.v');
    }

    public static function iso(?string $sql): ?string
    {
        return $sql === null ? null : (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function elapsed(string $start, \DateTimeImmutable $end): int
    {
        $a = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        return (int) floor(((float) $end->format('U.u') - (float) $a->format('U.u')) * 1000);
    }
}
