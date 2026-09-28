<?php

declare(strict_types=1);

namespace Freedom\Accounts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A shared Google account an application's tablets may sign in with.
 *
 * <b>Why these exist.</b> A register tablet at an intergroup meeting is
 * nobody's phone. Signing it in with a member's own Google account would
 * tie the tablet to that member — their removal from Unity would revoke
 * it mid-meeting — and put their personal account on a device anybody
 * picks up. So an application can name the accounts its tablets use,
 * and a tablet signed in with one is admitted as a member would be.
 */
final class CommonAccount
{
    public function __construct(
        public readonly int $id,
        public readonly int $applicationId,
        public readonly string $email,
        public readonly string $label,
        public readonly int $createdAt,
        public readonly int $createdBy,
    ) {
    }
}
