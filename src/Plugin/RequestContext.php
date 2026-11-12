<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

/** Trusted server identity; sensitive values must never be logged by plugins. */
final readonly class RequestContext
{
    public string $ip;
    public function __construct(string $ip, public string $userAgent, public string $sessionId, public int $time, public bool $useServerClock = false)
    {
        $packed = @inet_pton($ip);
        if ($packed === false || $sessionId === '' || str_contains($sessionId, "\0") || $time < 0) {
            throw new \InvalidArgumentException('A verified IP, session and server time are required.');
        }
        $this->ip = inet_ntop($packed);
    }
}
