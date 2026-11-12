<?php

declare(strict_types=1);

namespace FormGuard\Challenge;

interface TurnstileTransportInterface
{
    /** @return array<string, mixed> Server-verified response, never visitor-supplied JSON. */
    public function verify(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $token): array;
}
