<?php

declare(strict_types=1);

namespace FormGuard\Rule;

readonly class RuleContext
{
    /** @param array<string, mixed> $fields Untrusted business values under logical names, no form tokens/honeypot/session/IP. */
    public function __construct(public array $fields, public int $ageSeconds, public bool $javaScript)
    {
    }
}
