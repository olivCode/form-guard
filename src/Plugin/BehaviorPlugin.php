<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

use FormGuard\RejectionReason;

final class BehaviorPlugin implements FormPluginInterface
{
    public function __construct(private readonly int $minimumScore, private readonly int $minimumInteractions, private readonly int $maximumRate)
    {
    }
    public function id(): string
    {
        return 'browser_behavior';
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
        if (!$context->javaScript) {
            return null;
        }
        $score = 3 + (int) ($context->interactions >= $this->minimumInteractions)
            + (int) ($context->interactions > 0 && $context->interactions / max(1, $context->ageSeconds) < $this->maximumRate);
        return $score < $this->minimumScore ? new Refusal(RejectionReason::BehaviorRejected) : null;
    }
}
