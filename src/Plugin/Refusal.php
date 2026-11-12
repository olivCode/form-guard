<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

use FormGuard\RejectionReason;

final readonly class Refusal
{
    /** @var array<string, int|string> */
    public array $parameters;
    /** @param array<array-key, mixed> $parameters */
    public function __construct(public RejectionReason $reason, array $parameters = [], public int $retryAfter = 0, public ?string $code = null)
    {
        if (!in_array($reason, [RejectionReason::TooFast, RejectionReason::BehaviorRejected, RejectionReason::RateLimited, RejectionReason::CustomRule], true)
            || $retryAfter < 0 || $retryAfter > 10000000 || count($parameters) > 8
            || ($code !== null && !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code))
            || ($reason === RejectionReason::CustomRule && $code === null)
            || ($reason !== RejectionReason::RateLimited && $retryAfter !== 0)) {
            throw new \InvalidArgumentException('Invalid plugin refusal.');
        }
        $clean = [];
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key)
                || !(is_int($value) && abs($value) <= 10000000 || $key === 'limit_scope' && in_array($value, ['client', 'ip'], true))) {
                throw new \InvalidArgumentException('Invalid plugin refusal metadata.');
            }
            $clean[$key] = $value;
        }
        $this->parameters = $clean;
        $valid = match ($reason) {
            RejectionReason::TooFast => $code === null && array_keys($clean) === ['seconds'] && is_int($clean['seconds']) && $clean['seconds'] > 0,
            RejectionReason::BehaviorRejected => $code === null && $clean === [],
            RejectionReason::RateLimited => $code === null && count($clean) === 2 && ($clean['retry_after'] ?? null) === $retryAfter && $retryAfter > 0 && in_array($clean['limit_scope'] ?? null, ['client', 'ip'], true),
            default => count(array_filter($clean, 'is_int')) === count($clean),
        };
        if (!$valid) {
            throw new \InvalidArgumentException('Plugin refusal metadata does not match its reason.');
        }
    }
}
