<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

interface RequestPluginInterface extends PluginInterface
{
    public function evaluate(RequestContext $context): ?Refusal;
}
