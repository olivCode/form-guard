<?php

declare(strict_types=1);

namespace FormGuard\Challenge;

/** Fixed HTTPS destination, no redirects, bounded response and network deadline. */
final class TurnstileTransport implements TurnstileTransportInterface
{
    public function verify(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $token): array
    {
        if (!extension_loaded('openssl') || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new \RuntimeException('Turnstile HTTPS transport is unavailable.');
        }
        $deadline = microtime(true) + 3.0;
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
                'content' => http_build_query(['secret' => $secret, 'response' => $token], '', '&', PHP_QUERY_RFC3986),
                'timeout' => 2.0, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $stream = @fopen('https://challenges.cloudflare.com/turnstile/v0/siteverify', 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('Turnstile verification is unavailable.');
        }
        try {
            $metadata = stream_get_meta_data($stream);
            $headers = $metadata['wrapper_data'] ?? [];
            if (!is_array($headers) || !isset($headers[0]) || !preg_match('~^HTTP/\S+ 200(?: |$)~D', $headers[0])) {
                throw new \RuntimeException('Turnstile verification returned an unexpected status.');
            }
            $body = '';
            while (!feof($stream)) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new \RuntimeException('Turnstile verification timed out.');
                }
                stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
                $chunk = @fread($stream, 8193 - strlen($body));
                if ($chunk === false || ($chunk === '' && !feof($stream)) || stream_get_meta_data($stream)['timed_out']) {
                    throw new \RuntimeException('Turnstile verification failed.');
                }
                $body .= $chunk;
                if (strlen($body) > 8192) {
                    throw new \RuntimeException('Turnstile response exceeded the limit.');
                }
            }
            $response = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($response) || array_is_list($response)) {
                throw new \RuntimeException('Turnstile verification returned invalid JSON.');
            }
            return $response;
        } finally {
            fclose($stream);
        }
    }
}
