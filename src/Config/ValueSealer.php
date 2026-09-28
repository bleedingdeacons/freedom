<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Crypto\MessageSealer;

/**
 * Seals one secret value to one tablet's public key.
 *
 * <b>Fellowship's envelope, unchanged.</b> A fresh AES-256-GCM content key
 * per value, gzip underneath, the content key wrapped to the tablet with
 * RSA-OAEP — SHA-1, because that is all PHP offers and both ends must
 * agree — travelling as `k` and `p`. Link already opens exactly this, so
 * a second envelope would be a second thing to keep in step across two
 * languages for nothing.
 *
 * <b>The key and version travel inside the seal.</b> A sealed value is a
 * blob, and without them one blob could be put in another key's place in
 * a response and the tablet would store it there. The tablet checks both
 * against the entry the envelope arrived in.
 *
 * What this protects: a secret in a logged response, a proxy that
 * terminates TLS, a stolen bearer token without the key. What it does not:
 * this server reads every value, and a tablet holding a secret knows it.
 */
final class ValueSealer
{
    public function __construct(private readonly MessageSealer $sealer)
    {
    }

    /** @return array{k: string, p: string}|null Null when the key is unusable; never an empty payload. */
    public function seal(string $key, int $version, string $plaintext, string $publicKey): ?array
    {
        return $this->sealer->seal([
            'key'     => $key,
            'version' => $version,
            'value'   => $plaintext,
        ], $publicKey);
    }
}
