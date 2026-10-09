<?php

declare(strict_types=1);

namespace Freedom\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Core\Cipher;

/**
 * Encrypts and reads back an application's own OAuth client secret.
 *
 * Its own key domain, deliberately not the configuration values' — a
 * client secret and a tablet's settings have nothing to do with each
 * other, and a key derived for one should open nothing of the other. The
 * settings page writes through this and {@see FreedomAudience} reads
 * through it, so the two can never disagree about the domain.
 */
final class ClientSecrets
{
    public const DOMAIN = 'freedom-oauth-clients';

    private readonly Cipher $cipher;

    public function __construct(?Cipher $cipher = null)
    {
        $this->cipher = $cipher ?? new Cipher(self::DOMAIN);
    }

    /** The ciphertext to store, or '' when it could not be encrypted. */
    public function encrypt(string $secret): string
    {
        return $this->cipher->encrypt($secret);
    }

    /** The secret, or null for nothing stored or a ciphertext that does not open. */
    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $secret = $this->cipher->decrypt($stored);

        return $secret === null || $secret === '' ? null : $secret;
    }
}
