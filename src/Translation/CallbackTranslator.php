<?php

declare(strict_types=1);

namespace FormGuard\Translation;

final class CallbackTranslator implements TranslatorInterface
{
    private readonly \Closure $callback;

    public function __construct(callable $callback, private readonly TranslatorInterface $fallback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    /** @param array<string, int|string> $parameters */
    public function translate(string $key, array $parameters, string $locale): string
    {
        $result = ($this->callback)($key, $parameters, $locale);
        if ($result === null || $result === '' || $result === $key) {
            return $this->fallback->translate($key, $parameters, $locale);
        }
        if (!is_string($result)) {
            throw new \UnexpectedValueException('The translator must return a string or null.');
        }
        return $result;
    }
}
