<?php

declare(strict_types=1);

namespace Freedom\Applications;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What an application's callback URI may be, and whether a redirect
 * matches it.
 *
 * The callback is where the browser leg hands the app its one-time code,
 * so it is a credential's destination and gets an allow-list rather than a
 * sanitiser, exactly as Fellowship's `DeviceRedirectValidator` does for
 * `link://auth`.
 *
 * <b>A custom scheme only.</b> An https callback would send the code to a
 * web page; `link` is Link's and belongs to Fellowship's own flow; and
 * `intent`, `javascript`, `data`, `file` and `content` are the schemes a
 * browser gives other meanings. A reverse-DNS scheme
 * (`org.example.register.freedom`) is recommended because two apps on one
 * tablet claiming the same scheme is how a code ends up in the wrong app,
 * and a reverse-DNS name is one nobody else picks by accident.
 *
 * <b>Loopback is for development</b>, and only when the application says
 * so: `http://127.0.0.1:<port>/` or `http://[::1]:<port>/`, port 1024 or
 * above. The example CLI signs in that way.
 */
final class CallbackUri
{
    private const REFUSED_SCHEMES = ['http', 'https', 'link', 'file', 'content', 'intent', 'javascript', 'data', 'about', 'ftp'];

    private const LOOPBACK_HOSTS = ['127.0.0.1', '[::1]', '::1'];

    private const MIN_LOOPBACK_PORT = 1024;

    public const MAX_BYTES = 255;

    /** Whether $uri may be saved as an application's callback. */
    public static function isAcceptable(string $uri): bool
    {
        $uri = trim($uri);
        if ($uri === '' || strlen($uri) > self::MAX_BYTES) {
            return false;
        }

        if (str_contains($uri, '#') || str_contains($uri, '@') || str_contains($uri, '?')) {
            return false;
        }

        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = (string) $parts['scheme'];
        if (preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) !== 1 || in_array($scheme, self::REFUSED_SCHEMES, true)) {
            return false;
        }

        return (string) $parts['host'] !== '' && !isset($parts['port']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * Whether a sign-in may be redirected to $candidate for an
     * application whose callback is $callbackUri.
     */
    public static function allows(string $candidate, string $callbackUri, bool $allowLoopback): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }

        if ($callbackUri !== '' && hash_equals($callbackUri, $candidate)) {
            return true;
        }

        return $allowLoopback && self::isLoopback($candidate);
    }

    public static function isLoopback(string $uri): bool
    {
        if (str_contains($uri, '#') || str_contains($uri, '@')) {
            return false;
        }

        $parts = parse_url($uri);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? 0;

        return in_array($host, self::LOOPBACK_HOSTS, true)
            && $port >= self::MIN_LOOPBACK_PORT
            && $port <= 65535
            && !isset($parts['query']);
    }
}
