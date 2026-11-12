<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

interface PluginInterface
{
    public function id(): string;
    public function phase(): Phase;
    /**
     * IDs must form a list of strings; the registry validates this boundary.
     * @return array<array-key, mixed>
     */
    public function requires(): array;
    /**
     * IDs that must not be enabled together, validated by the registry.
     * @return array<array-key, mixed>
     */
    public function conflicts(): array;
}
