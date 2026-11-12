<?php

declare(strict_types=1);

namespace FormGuard\Rule;

class RuleExecutionException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $ruleId, ?\Throwable $previous = null)
    {
        parent::__construct('An additional protection could not complete.', 0, $previous);
    }
}
