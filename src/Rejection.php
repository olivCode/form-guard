<?php

declare(strict_types=1);

namespace FormGuard;

final class Rejection extends \DomainException
{
    /** @param array<string, int|string> $parameters */
    public function __construct(
        public readonly RejectionReason $reason,
        string $message,
        public readonly array $parameters = [],
        public readonly int $retryAfter = 0,
        public readonly ?string $ruleId = null,
        public readonly ?string $ruleCode = null,
    ) {
        parent::__construct($message, $reason->httpStatus());
    }
}
