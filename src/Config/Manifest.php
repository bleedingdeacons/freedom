<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The list a tablet asks for on every start: every key it should hold,
 * each with its version and whether it is secret. Never a value.
 *
 * <b>Complete, so absence means removal.</b> A key the tablet holds that
 * is not listed here has been deleted, and the tablet deletes it too.
 * That is why there are no tombstones, and why a manifest must never be
 * built from a read that failed — see {@see ValueRepository}.
 *
 * <b>The ETag</b> covers the tablet, every key, every version and every
 * secret flag, so any change to what this tablet should hold changes it,
 * and a tablet that sends it back with nothing changed gets a 304 and an
 * empty body.
 */
final class Manifest
{
    /**
     * @param list<array{key: string, version: int, secret: bool}> $keys
     */
    private function __construct(
        public readonly string $etag,
        public readonly array $keys,
    ) {
    }

    /** @param array<string, ConfigValue> $effective As EffectiveConfig::resolve() answers it. */
    public static function for(int $tabletId, array $effective): self
    {
        ksort($effective, SORT_STRING);

        $keys = [];
        $lines = [(string) $tabletId];

        foreach ($effective as $key => $value) {
            $key = (string) $key;
            $keys[] = ['key' => $key, 'version' => $value->version, 'secret' => $value->isSecret];
            $lines[] = $key . ':' . $value->version . ':' . ($value->isSecret ? '1' : '0');
        }

        return new self(substr(hash('sha256', implode("\n", $lines)), 0, 32), $keys);
    }

    /** Whether an If-None-Match header names this manifest. */
    public function matches(string $ifNoneMatch): bool
    {
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if (hash_equals($this->etag, trim($candidate, '"'))) {
                return true;
            }
        }

        return false;
    }
}
