<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

use FormGuard\RejectionReason;
use FormGuard\Rule\RuleInterface;

final class RuleAdapter implements FormPluginInterface
{
    public function __construct(private readonly RuleInterface $rule)
    {
    }
    public function id(): string
    {
        return $this->rule->id();
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
        $failure = $this->rule->evaluate($context);
        return $failure === null ? null : new Refusal(RejectionReason::CustomRule, $failure->parameters, code: $failure->code);
    }
}
