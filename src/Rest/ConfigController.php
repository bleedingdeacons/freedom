<?php

declare(strict_types=1);

namespace Freedom\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Core\RateLimiter;
use Freedom\Config\ConfigEditor;
use Freedom\Config\ConfigKey;
use Freedom\Config\ConfigValue;
use Freedom\Config\EffectiveConfig;
use Freedom\Config\Manifest;
use Freedom\Config\ValueRepository;
use Freedom\Config\ValueSealer;
use Freedom\Logger\HasLogger;
use Freedom\Tablets\CurrentTablet;
use Freedom\Tablets\Tablet;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;

/**
 * What a signed-in tablet asks for: the manifest on every start, and the
 * values it found stale.
 *
 * <b>Two requests rather than one, on purpose.</b> On most starts nothing
 * has changed, and the manifest — keys and versions, no values — answers
 * that with a 304 and an empty body. Values are fetched only for the keys
 * whose version differs, so a secret is sent when it changed and not on
 * every start.
 *
 * <b>A read that failed is a 500, never an empty answer.</b> A tablet
 * deletes any key the manifest does not list, so an empty manifest built
 * from a failed query would wipe every tablet that asked while the
 * database was unwell. See {@see ValueRepository}.
 */
final class ConfigController
{
    use HasLogger;
    use RequiresSecureTransport;

    /** A manifest on every start; generous for a tablet that is restarted a great deal. */
    private const MANIFEST_MAX = 120;
    private const VALUES_MAX = 60;
    private const WINDOW = 3600;

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    public function __construct(
        private readonly CurrentTablet $currentTablet,
        private readonly ValueRepository $values,
        private readonly ConfigEditor $editor,
        private readonly ValueSealer $sealer,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(SignInController::NAMESPACE, '/config/manifest', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'manifest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(SignInController::NAMESPACE, '/config/values', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'values'],
            'permission_callback' => '__return_true',
            'args'                => [
                'keys' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'string']],
            ],
        ]);
    }

    public function manifest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($insecure = $this->insecureTransport()) {
            return $insecure;
        }

        $resolved = $this->currentTablet->resolve($request, true);
        if ($resolved->tablet === null || $resolved->application === null) {
            return $resolved->error();
        }

        $tablet = $resolved->tablet;

        if ($this->rateLimiter->overLimit('freedom_manifest_' . $tablet->id, self::MANIFEST_MAX, self::WINDOW)) {
            return $this->rateLimited();
        }

        $effective = $this->effective($tablet);
        if ($effective instanceof WP_Error) {
            return $effective;
        }

        $manifest = Manifest::for($tablet->id, $effective);

        if ($manifest->matches((string) $request->get_header('if_none_match'))) {
            $response = new WP_REST_Response(null, 304);
        } else {
            $response = new WP_REST_Response([
                'application' => $resolved->application->slug,
                'tablet'      => $tablet->id,
                'etag'        => $manifest->etag,
                'checked_at'  => time(),
                'keys'        => $manifest->keys,
            ], 200);
        }

        $response->header('ETag', '"' . $manifest->etag . '"');

        return $response;
    }

    public function values(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($insecure = $this->insecureTransport()) {
            return $insecure;
        }

        $resolved = $this->currentTablet->resolve($request);
        if ($resolved->tablet === null) {
            return $resolved->error();
        }

        $tablet = $resolved->tablet;

        if ($this->rateLimiter->overLimit('freedom_values_' . $tablet->id, self::VALUES_MAX, self::WINDOW)) {
            return $this->rateLimited();
        }

        $keys = $this->requestedKeys($request->get_param('keys'));
        if ($keys instanceof WP_Error) {
            return $keys;
        }

        $effective = $this->effective($tablet);
        if ($effective instanceof WP_Error) {
            return $effective;
        }

        $values = [];
        $missing = [];
        $unreadable = [];

        foreach ($keys as $key) {
            $value = $effective[$key] ?? null;
            if ($value === null) {
                $missing[] = $key;
                continue;
            }

            $entry = $this->entry($value, $tablet);
            if ($entry === null) {
                $unreadable[] = $key;
                continue;
            }

            $values[] = $entry;
        }

        if ($unreadable !== []) {
            // Worth an admin's attention: either a value no longer
            // decrypts (the auth salt moved) and must be re-entered, or
            // this tablet's key will not load and it must sign in again.
            self::logWarning('Values could not be served', [
                'tablet' => $tablet->id,
                'keys'   => $unreadable,
            ]);
        }

        return new WP_REST_Response([
            'values'     => $values,
            'missing'    => $missing,
            'unreadable' => $unreadable,
        ], 200);
    }

    /** @return array<string, ConfigValue>|WP_Error */
    private function effective(Tablet $tablet): array|WP_Error
    {
        try {
            return EffectiveConfig::resolve(
                $this->values->defaults($tablet->applicationId),
                $this->values->overrides($tablet->applicationId, $tablet->id),
            );
        } catch (RuntimeException $e) {
            self::logError('Configuration could not be read: ' . $e->getMessage(), ['tablet' => $tablet->id]);

            return new WP_Error(
                'freedom_unavailable',
                'Configuration is temporarily unavailable. Keep what you have and try again later.',
                ['status' => 500],
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function entry(ConfigValue $value, Tablet $tablet): ?array
    {
        $plaintext = $this->editor->reveal($value);
        if ($plaintext === null) {
            return null;
        }

        if (!$value->isSecret) {
            return ['key' => $value->key, 'version' => $value->version, 'secret' => false, 'value' => $plaintext];
        }

        $sealed = $this->sealer->seal($value->key, $value->version, $plaintext, $tablet->publicKey);
        if ($sealed === null) {
            return null;
        }

        return ['key' => $value->key, 'version' => $value->version, 'secret' => true, 'k' => $sealed['k'], 'p' => $sealed['p']];
    }

    /** @return list<string>|WP_Error */
    private function requestedKeys(mixed $raw): array|WP_Error
    {
        if (!is_array($raw) || $raw === [] || count($raw) > ConfigKey::MAX_KEYS_PER_REQUEST) {
            return new WP_Error(
                'freedom_bad_keys',
                'Ask for between 1 and ' . ConfigKey::MAX_KEYS_PER_REQUEST . ' keys.',
                ['status' => 400],
            );
        }

        $keys = [];
        foreach ($raw as $key) {
            if (!is_string($key) || !ConfigKey::isValid($key)) {
                return new WP_Error('freedom_bad_keys', 'A requested key is malformed.', ['status' => 400]);
            }

            $keys[$key] = $key;
        }

        return array_values($keys);
    }

    private function rateLimited(): WP_Error
    {
        return new WP_Error('freedom_rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
    }
}
