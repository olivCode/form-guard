<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

/** Extends the 0.4 technical exception so existing handlers stay compatible. */
final class PluginExecutionException extends \FormGuard\Rule\RuleExecutionException
{
    public function __construct(string $reason, public readonly string $pluginId, ?\Throwable $previous = null)
    {
        parent::__construct($reason, $pluginId, $previous);
    }
}
