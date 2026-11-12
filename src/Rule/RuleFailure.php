<?php

declare(strict_types=1);

namespace FormGuard\Rule;

final readonly class RuleFailure
{
    /** @var array<string, int> */
    public array $parameters;

    /** @param array<array-key, mixed> $parameters Only bounded numeric presentation metadata; never visitor identifiers. */
    public function __construct(public string $code, array $parameters = [])
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code) || count($parameters) > 8) {
            throw new \InvalidArgumentException('Invalid rule failure.');
        }
        $clean = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $name) || !is_int($value) || abs($value) > 10000000) {
                throw new \InvalidArgumentException('Invalid rule metadata.');
            }
            $clean[$name] = $value;
        }
        $this->parameters = $clean;
    }
}
