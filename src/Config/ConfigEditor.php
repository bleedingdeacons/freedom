<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Applications\Application;
use Freedom\Applications\ApplicationRepository;
use Freedom\Core\Cipher;
use Freedom\Logger\HasLogger;
use RuntimeException;

/**
 * Every change to a stored value goes through here, and this is where
 * versions come from.
 *
 * <b>The rule the whole design leans on: a write takes a new application
 * revision first, and the value is stamped with it.</b> So a version
 * number is never reused within an application, and a `(key, version)`
 * pair names exactly one value, ever. That is what lets a tablet compare
 * what it holds with the manifest for *difference* — and what makes
 * removing an override work, since the default it falls back to carries a
 * different number from the override the tablet was holding. See
 * {@see EffectiveConfig}.
 *
 * <b>Saving an unchanged value changes nothing.</b> The current value is
 * decrypted and compared first, so pressing Save on a form that was not
 * touched does not send every tablet back for a value it already has.
 *
 * Values never reach a log. The key, the version and who changed it do.
 */
final class ConfigEditor
{
    use HasLogger;

    public const SAVED = 'saved';
    public const UNCHANGED = 'unchanged';
    public const DELETED = 'deleted';
    public const NOT_FOUND = 'not_found';
    public const BAD_KEY = 'bad_key';
    public const TOO_LARGE = 'too_large';
    public const FAILED = 'failed';

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ValueRepository $values,
        private readonly Cipher $cipher,
    ) {
    }

    /**
     * Set a default ($tabletId 0) or one tablet's override.
     *
     * @return string One of the result constants.
     */
    public function set(Application $application, int $tabletId, string $key, string $plaintext, bool $isSecret, int $by): string
    {
        if (!ConfigKey::isValid($key)) {
            return self::BAD_KEY;
        }

        if (strlen($plaintext) > ConfigKey::MAX_VALUE_BYTES) {
            return self::TOO_LARGE;
        }

        $current = $this->values->find($application->id, $tabletId, $key);
        if ($current !== null && $current->isSecret === $isSecret) {
            $existing = $this->cipher->decrypt($current->storedValue);
            // A value that no longer decrypts is always rewritten: that is
            // how an admin repairs one after the salt has been rotated.
            if ($existing !== null && hash_equals($existing, $plaintext)) {
                return self::UNCHANGED;
            }
        }

        $stored = $this->cipher->encrypt($plaintext);
        if ($stored === '') {
            self::logError('Value not saved: the cipher refused', ['key' => $key]);

            return self::FAILED;
        }

        try {
            $version = $this->applications->nextRevision($application->id);
            $this->values->upsert($application->id, $tabletId, $key, $stored, $isSecret, $version, $by, time());
        } catch (RuntimeException $e) {
            self::logError('Value not saved: ' . $e->getMessage(), ['key' => $key]);

            return self::FAILED;
        }

        self::logInfo('Configuration value saved', [
            'application' => $application->slug,
            'tablet'      => $tabletId,
            'key'         => $key,
            'version'     => $version,
            'secret'      => $isSecret,
            'by'          => $by,
        ]);

        return self::SAVED;
    }

    /** @return string One of the result constants. */
    public function delete(Application $application, int $tabletId, string $key, int $by): string
    {
        if (!ConfigKey::isValid($key)) {
            return self::BAD_KEY;
        }

        // No new revision: a deleted default drops out of the manifest,
        // which is itself the signal, and a deleted override uncovers the
        // default's older — and therefore different — version.
        if (!$this->values->delete($application->id, $tabletId, $key)) {
            return self::NOT_FOUND;
        }

        self::logInfo('Configuration value deleted', [
            'application' => $application->slug,
            'tablet'      => $tabletId,
            'key'         => $key,
            'by'          => $by,
        ]);

        return self::DELETED;
    }

    /**
     * The plaintext of a stored value, or null when it no longer decrypts
     * — after the site's auth salt was rotated, say. Null is never to be
     * served as an empty value.
     */
    public function reveal(ConfigValue $value): ?string
    {
        return $this->cipher->decrypt($value->storedValue);
    }
}
