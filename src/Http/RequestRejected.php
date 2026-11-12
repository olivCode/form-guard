<?php

declare(strict_types=1);

namespace FormGuard\Http;

final class RequestRejected extends \DomainException
{
    public function __construct(public readonly string $reason, int $status, string $locale = 'en')
    {
        parent::__construct(preg_match('/^fr(?:[-_]|$)/i', $locale) === 1 ? 'Cette requête ne peut pas être acceptée.' : 'This request cannot be accepted.', $status);
    }
}
