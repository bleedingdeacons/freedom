<?php

declare(strict_types=1);

namespace Freedom\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RFC 7636 PKCE, S256 only, between the app and this server.
 *
 * <b>What it closes.</b> The one-time code comes back to the app through a
 * custom URI scheme, and any other app on the tablet that registers the
 * same scheme can receive it. PKCE makes the code worthless to that app:
 * the app that started the sign-in holds a verifier that never left it,
 * only its SHA-256 went to the server, and the exchange needs the
 * verifier. Link's flow has the same exposure today and does not close
 * it; Freedom starts with it closed.
 *
 * Not the PKCE Fellowship does with Facebook — that one is between this
 * server and the provider, and the app never sees it.
 */
final class Pkce
{
    /** base64url of 32 bytes of SHA-256, unpadded. */
    private const CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /** RFC 7636's unreserved characters, 43 to 128 of them. */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}$/';

    public static function isChallenge(string $challenge): bool
    {
        return preg_match(self::CHALLENGE_PATTERN, $challenge) === 1;
    }

    public static function verifies(string $verifier, string $challenge): bool
    {
        if (preg_match(self::VERIFIER_PATTERN, $verifier) !== 1 || !self::isChallenge($challenge)) {
            return false;
        }

        return hash_equals($challenge, self::challengeFor($verifier));
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
