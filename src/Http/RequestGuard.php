<?php

declare(strict_types=1);

namespace FormGuard\Http;

/** Defense in depth. Keep server body limits and Form Guard/session CSRF validation. */
final class RequestGuard
{
    /** @var list<string> */
    private readonly array $origins;
    /** @var list<string> */
    private readonly array $types;

    /**
     * @param array<array-key, mixed> $allowedOrigins Explicit trusted scheme/host/port, not request Host.
     * @param array<array-key, mixed> $contentTypes
     */
    public function __construct(
        array $allowedOrigins,
        private readonly int $maxBodyBytes = 16384,
        private readonly int $maxFields = 32,
        private readonly int $maxDepth = 4,
        private readonly int $maxFieldBytes = 8192,
        private readonly bool $allowMissingOrigin = true,
        private readonly bool $allowCrossSite = false,
        private readonly string $locale = 'en',
        array $contentTypes = ['application/x-www-form-urlencoded', 'multipart/form-data'],
    ) {
        if (!$allowedOrigins || count($allowedOrigins) > 32 || !array_is_list($allowedOrigins) || $maxBodyBytes < 1024 || $maxBodyBytes > 1048576
            || $maxFields < 1 || $maxFields > 1000 || $maxDepth < 1 || $maxDepth > 16 || $maxFieldBytes < 1 || $maxFieldBytes > $maxBodyBytes || !preg_match('/^[a-zA-Z]{2,8}(?:[-_][a-zA-Z0-9]{1,8})*$/D', $locale)) {
            throw new \InvalidArgumentException('Invalid HTTP protection policy.');
        }
        $origins = [];
        foreach ($allowedOrigins as $origin) {
            if (!is_string($origin)) {
                throw new \InvalidArgumentException('Invalid origin configuration.');
            }
            $origins[] = self::origin($origin);
        }
        $supported = ['application/x-www-form-urlencoded', 'multipart/form-data', 'application/json'];
        if (!$contentTypes || !array_is_list($contentTypes) || count(array_filter($contentTypes, static fn ($type): bool => is_string($type) && in_array($type, $supported, true))) !== count($contentTypes)) {
            throw new \InvalidArgumentException('Invalid HTTP content types.');
        }
        $this->origins = array_values(array_unique($origins));
        $this->types = array_values(array_unique($contentTypes));
    }

    /** Canonical exact origin; rejects userinfo, opaque origins, ambiguous hosts and control characters. */
    public static function origin(string $url, bool $referer = false): string
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Invalid origin.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || (!$referer && (isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)))) {
            throw new \InvalidArgumentException('Invalid origin.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[')) {
            $packed = @inet_pton(substr($host, 1, -1));
            if ($packed === false || strlen($packed) !== 16) {
                throw new \InvalidArgumentException('Invalid IPv6 origin.');
            }
            $host = '[' . inet_ntop($packed) . ']';
        } else {
            if (strlen($host) > 253) {
                throw new \InvalidArgumentException('Invalid origin host.');
            }
            foreach (explode('.', $host) as $label) {
                if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) {
                    throw new \InvalidArgumentException('Invalid origin host.');
                }
            }
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1) {
            throw new \InvalidArgumentException('Invalid origin port.');
        }
        return $scheme . '://' . $host . (($scheme === 'https' && $port === 443 || $scheme === 'http' && $port === 80) ? '' : ':' . $port);
    }

    private function reject(string $reason, int $status): never
    {
        throw new RequestRejected($reason, $status, $this->locale);
    }

    /**
     * Validates already-parsed values. Upstream server/PHP limits are still mandatory before parsing.
     * @param array<array-key, mixed> $payload
     */
    public function check(RequestMetadata $request, array $payload = [], int $fileCount = 0): void
    {
        if ($request->method !== 'POST') {
            $this->reject('method_not_allowed', 405);
        }
        if ($request->bodyBytes === null || $request->bodyBytes < 0 || $request->bodyBytes > $this->maxBodyBytes) {
            $this->reject('body_size_invalid', 413);
        }
        if (strlen($request->contentType) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $request->contentType)) {
            $this->reject('unsupported_content_type', 415);
        }
        $type = strtolower(trim(explode(';', $request->contentType, 2)[0]));
        if (!in_array($type, $this->types, true) || $fileCount !== 0) {
            $this->reject('unsupported_content_type', 415);
        }
        if ($request->fetchSite !== null && !in_array($request->fetchSite, ['same-origin', 'same-site', 'cross-site', 'none'], true)) {
            $this->reject('invalid_fetch_metadata', 403);
        }
        if ($request->fetchSite === 'cross-site' && !$this->allowCrossSite) {
            $this->reject('cross_site_request', 403);
        }
        $source = $request->origin ?? $request->referer;
        if ($source === null) {
            if (!$this->allowMissingOrigin || $request->fetchSite === 'cross-site') {
                $this->reject('missing_origin', 403);
            }
            // Compatibility fallback relies on the application's subsequent session/CSRF token validation.
        } else {
            try {
                $origin = self::origin($source, $request->origin === null);
            } catch (\Throwable) {
                $this->reject('invalid_origin', 403);
            }
            if (!in_array($origin, $this->origins, true)) {
                $this->reject('untrusted_origin', 403);
            }
        }
        $count = 0;
        $bytes = 0;
        $walk = function (array $values, int $depth) use (&$walk, &$count, &$bytes): void {
            if ($depth > $this->maxDepth) {
                $this->reject('payload_too_deep', 413);
            }
            foreach ($values as $key => $value) {
                if (++$count > $this->maxFields || strlen((string) $key) > 128) {
                    $this->reject('too_many_fields', 413);
                }
                $bytes += strlen((string) $key);
                if (is_array($value)) {
                    $walk($value, $depth + 1);
                } elseif (is_string($value)) {
                    if (strlen($value) > $this->maxFieldBytes) {
                        $this->reject('field_too_large', 413);
                    }
                    $bytes += strlen($value);
                } else {
                    $this->reject('invalid_field_type', 422);
                }
                if ($bytes > $this->maxBodyBytes) {
                    $this->reject('payload_too_large', 413);
                }
            }
        };
        $walk($payload, 1);
    }
}
