<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

interface FormPluginInterface extends PluginInterface
{
    public function evaluate(FormContext $context): ?Refusal;
}
