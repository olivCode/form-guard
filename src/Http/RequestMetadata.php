<?php

declare(strict_types=1);

namespace FormGuard\Http;

/** HTTP server/framework metadata, never guessed from an untrusted Host/forwarded header. */
final readonly class RequestMetadata
{
    public function __construct(
        public string $method,
        public string $contentType,
        public ?int $bodyBytes,
        public ?string $origin = null,
        public ?string $referer = null,
        public ?string $fetchSite = null,
    ) {
    }
}
