<?php

declare(strict_types=1);

namespace Freedom\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Symmetric encryption for data this site encrypts to itself and reads
 * back — every configuration value Freedom stores.
 *
 * The key is derived from a WordPress salt, so a database dump alone
 * does not open it but this site always can. That is the right direction
 * for at-rest storage and exactly the wrong one for a value on its way
 * to a tablet, which has to be readable only by that tablet — see
 * {@see \Fellowship\Crypto\MessageSealer}, which encrypts to a key the
 * server does not hold the other half of.
 *
 * Same construction as Fellowship's and Reach's Cipher, deliberately: the shape
 * the suite already uses for at-rest secrets and there is no reason for
 * Freedom to invent a second one.
 */
final class Cipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /**
     * $domain separates one use of this class from another, so a value
     * encrypted for one purpose cannot be decrypted as another even
     * though both derive from the same site salt.
     */
    public function __construct(private readonly string $domain)
    {
    }

    /** Returns '' when the cipher refuses; a caller must not store that as a value. */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            return '';
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * The plaintext, or null for anything that does not decrypt and
     * authenticate.
     *
     * Null rather than Fellowship's '' because an empty string is a value
     * Freedom stores legitimately: a configuration key may be set to
     * nothing, and that must stay distinguishable from a value that no
     * longer opens — after the auth salt is rotated, say — which must
     * never be served to a tablet as if it were empty.
     */
    public function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $iv         = substr($raw, 0, self::IV_BYTES);
        $tag        = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        // Checked as a statement rather than folded into the return.
        //
        // For GCM this is the authentication check, not a formality: PHP
        // reports a tag that does not verify by returning false, so this
        // branch is what stands between a tampered value and the caller
        // treating it as plaintext.
        if ($plaintext === false) {
            return null;
        }

        return $plaintext;
    }

    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|' . $this->domain, true);
    }
}
