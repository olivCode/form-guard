<?php

declare(strict_types=1);

namespace FormGuard;

enum RejectionReason: string
{
    case FormUnavailable = 'form_unavailable';
    case FormExpired = 'form_expired';
    case InvalidToken = 'invalid_token';
    case Honeypot = 'honeypot';
    case TooFast = 'too_fast';
    case InvalidSignals = 'invalid_signals';
    case BehaviorRejected = 'behavior_rejected';
    case RateLimited = 'rate_limited';
    case CustomRule = 'custom_rule';

    public function httpStatus(): int
    {
        return match ($this) {
            self::FormUnavailable, self::FormExpired, self::InvalidToken => 403,
            self::RateLimited => 429,
            default => 422,
        };
    }
}
