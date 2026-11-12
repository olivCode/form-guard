<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

use FormGuard\Rule\RuleContext;

final readonly class FormContext extends RuleContext
{
    /** @param array<string, mixed> $fields */
    public function __construct(array $fields, int $ageSeconds, bool $javaScript, public int $interactions)
    {
        $nodes = 0;
        parent::__construct(self::copyFields($fields, 0, $nodes), $ageSeconds, $javaScript);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function copyFields(array $values, int $depth, int &$nodes): array
    {
        if ($depth > 16) {
            throw new \InvalidArgumentException('Plugin context is too deeply nested.');
        }
        $copy = [];
        foreach ($values as $key => $value) {
            if (++$nodes > 10000) {
                throw new \InvalidArgumentException('Plugin context is too large.');
            }
            if (is_array($value)) {
                $copy[$key] = self::copyFields($value, $depth + 1, $nodes);
            } elseif ($value === null || is_scalar($value)) {
                $copy[$key] = $value;
            } else {
                throw new \InvalidArgumentException('Plugin context accepts only scalar/array business values.');
            }
        }
        return $copy;
    }
}
