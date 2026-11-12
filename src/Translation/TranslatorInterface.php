<?php

declare(strict_types=1);

namespace FormGuard\Translation;

interface TranslatorInterface
{
    /** Return plain text. Parameters use {name} placeholders, not HTML or printf. */
    /** @param array<string, int|string> $parameters */
    public function translate(string $key, array $parameters, string $locale): string;
}
