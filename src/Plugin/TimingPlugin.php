<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

use FormGuard\RejectionReason;

final class TimingPlugin implements FormPluginInterface
{
    public function __construct(private readonly int $minimumSeconds)
    {
    }
    public function id(): string
    {
        return 'timing';
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
        return $context->ageSeconds < $this->minimumSeconds ? new Refusal(RejectionReason::TooFast, ['seconds' => $this->minimumSeconds - $context->ageSeconds]) : null;
    }
}
