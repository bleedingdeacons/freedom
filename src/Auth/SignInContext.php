<?php

declare(strict_types=1);

namespace Freedom\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What Freedom asks Fellowship to carry through the browser leg: which
 * application the sign-in is for, and the app's PKCE challenge.
 *
 * Fellowship stores it, hands it back to {@see FreedomAudience} at the
 * callback and to the exchange with the redeemed code, and never reads it.
 */
final class SignInContext
{
    public function __construct(
        public readonly string $application,
        public readonly string $challenge,
    ) {
    }

    public function encode(): string
    {
        return (string) wp_json_encode(['app' => $this->application, 'challenge' => $this->challenge]);
    }

    public static function decode(string $context): ?self
    {
        $decoded = json_decode($context, true);
        if (!is_array($decoded) || !is_string($decoded['app'] ?? null) || !is_string($decoded['challenge'] ?? null)) {
            return null;
        }

        return new self($decoded['app'], $decoded['challenge']);
    }
}
