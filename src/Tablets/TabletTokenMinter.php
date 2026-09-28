<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tablet bearer tokens: minted once, returned once, stored as an HMAC.
 *
 * Fellowship's scheme with its own prefix and key domain, so a Freedom
 * token and a Link token can be told apart at a glance and neither hashes
 * to the other's row. `frt_` + 64 hex.
 */
final class TabletTokenMinter
{
    public const TOKEN_PREFIX = 'frt_';

    private const TOKEN_BYTES = 32;

    public function mint(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->key());
    }

    /** A regex before a query, so somebody else's bearer token costs nothing. */
    public function looksLikeToken(string $candidate): bool
    {
        return (bool) preg_match(
            '/^' . preg_quote(self::TOKEN_PREFIX, '/') . '[0-9a-f]{' . (self::TOKEN_BYTES * 2) . '}$/',
            $candidate,
        );
    }

    public function bearerFrom(string $authorizationHeader): string
    {
        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorizationHeader, $matches)) {
            return '';
        }

        return $matches[1];
    }

    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|freedom-tablet-token', true);
    }
}
