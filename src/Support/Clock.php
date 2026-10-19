<?php

declare(strict_types=1);

namespace BlindTyper\Support;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
