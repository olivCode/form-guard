<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

use FormGuard\RateLimiter;
use FormGuard\RejectionReason;
use FormGuard\Storage\State;
use FormGuard\Storage\StoreInterface;

final class RequestLimitPlugin implements RequestPluginInterface
{
    public function __construct(private readonly StoreInterface $store, private readonly RateLimiter $limiter)
    {
    }
    public function id(): string
    {
        return 'request_limits';
    }
    public function phase(): Phase
    {
        return Phase::Request;
    }
    public function requires(): array
    {
        return [];
    }
    public function conflicts(): array
    {
        return [];
    }
    public function evaluate(RequestContext $context): ?Refusal
    {
        // Resolve the live clock after acquiring the lock, preserving pre-plugin behavior.
        $decision = $this->store->transaction(fn (State $state): ?array => $this->limiter->attempt($state, $context->ip, $context->userAgent, $context->sessionId, $context->useServerClock ? time() : $context->time));
        return $decision === null ? null : new Refusal(RejectionReason::RateLimited, $decision, $decision['retry_after']);
    }
}
