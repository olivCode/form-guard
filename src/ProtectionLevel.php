<?php

declare(strict_types=1);

namespace FormGuard;

enum ProtectionLevel: string
{
    case Tolerant = 'tolerant';
    case Standard = 'standard';
    case Reinforced = 'reinforced';

    /** @return array<string, int> */
    public function overrides(): array
    {
        return match ($this) {
            self::Tolerant => ['client_limit' => 10, 'ip_limit' => 60],
            self::Standard => [],
            self::Reinforced => ['client_limit' => 3, 'ip_limit' => 20, 'min_seconds' => 5],
        };
    }
}
