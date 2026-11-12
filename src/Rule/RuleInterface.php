<?php

declare(strict_types=1);

namespace FormGuard\Rule;

interface RuleInterface
{
    /** Stable public identifier, lowercase letters/digits/underscore, starting with a letter, max 32. */
    public function id(): string;

    /** Return null to pass, a structured failure to refuse. Exceptions fail closed. No external work under storage locks. */
    public function evaluate(RuleContext $context): ?RuleFailure;
}
