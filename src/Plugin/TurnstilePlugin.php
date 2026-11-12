<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

use FormGuard\Challenge\TurnstileTransport;
use FormGuard\Challenge\TurnstileTransportInterface;
use FormGuard\Http\RequestGuard;
use FormGuard\RejectionReason;

/** Explicit opt-in; JavaScript and an external service are required for this plugin. */
final class TurnstilePlugin implements FormPluginInterface
{
    private readonly TurnstileTransportInterface $transport;

    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly string $hostname,
        private readonly string $action = 'contact',
        private readonly string $field = 'challenge',
        ?TurnstileTransportInterface $transport = null,
    ) {
        if ($secret === '' || strlen($secret) > 256 || preg_match('/[\x00-\x20\x7f]/', $secret)
            || RequestGuard::origin('https://' . $hostname) !== 'https://' . $hostname
            || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $action)
            || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $field) || $field === 'honeypot') {
            throw new \InvalidArgumentException('Invalid Turnstile configuration.');
        }
        $this->transport = $transport ?? new TurnstileTransport();
    }

    public function id(): string
    {
        return 'turnstile';
    }
    public function phase(): Phase
    {
        return Phase::Form;
    }
    public function requires(): array
    {
        return [];
    }
    public function conflicts(): array
    {
        return [];
    }

    public function evaluate(FormContext $context): ?Refusal
    {
        $token = $context->fields[$this->field] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 2048 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            return new Refusal(RejectionReason::CustomRule, code: 'challenge_failed');
        }
        $result = $this->transport->verify($this->secret, $token);
        if (($result['success'] ?? null) !== true || ($result['hostname'] ?? null) !== $this->hostname || ($result['action'] ?? null) !== $this->action) {
            return new Refusal(RejectionReason::CustomRule, code: 'challenge_failed');
        }
        return null;
    }
}
