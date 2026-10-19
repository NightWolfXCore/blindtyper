<?php

declare(strict_types=1);

namespace BlindTyper\Http;

/** Single-host, cross-cookie fixed windows. Files must be on shared storage for multiple PHP workers. */
final class RateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    public function hit(string $key, int $limit, int $seconds): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Rate limit storage unavailable');
        }
        $handle = fopen($this->directory . '/' . hash('sha256', $key) . '.json', 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open rate limit');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock rate limit');
            }
            $state = json_decode(stream_get_contents($handle), true) ?: ['expires' => 0, 'count' => 0];
            $now = time();
            if ($state['expires'] <= $now) {
                $state = ['expires' => $now + $seconds, 'count' => 0];
            }
            if ($state['count'] >= $limit) {
                throw new ApiException(429, 'RATE_LIMITED', 'Слишком много запросов, попробуйте позже');
            }
            $state['count']++;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
