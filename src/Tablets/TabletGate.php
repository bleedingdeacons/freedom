<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Devices\MemberGate;
use Freedom\Accounts\CommonAccountRepository;
use Freedom\Applications\Application;

/**
 * The single answer to "may this Google account use this application?".
 *
 * <b>Two ways in, both always accepted, and no approval step.</b> A Unity
 * member's own account — decided by Fellowship's `MemberGate`, so a
 * member here is exactly who is a member for Link — or one of the
 * application's common tablet accounts.
 *
 * One object because it is asked at four moments that must agree: in the
 * browser, so a stranger is told where they can read it; at the exchange
 * and the session hand-off, where the tablet row is written; and on every
 * request afterwards, so a member removed from Unity or an account taken
 * off the list stops receiving configuration at once rather than whenever
 * somebody remembers to revoke the tablet.
 *
 * A member is checked first: an address that is both is recorded as the
 * member's, so erasing that member takes the tablet with them.
 */
final class TabletGate
{
    public function __construct(
        private readonly MemberGate $members,
        private readonly CommonAccountRepository $accounts,
    ) {
    }

    public function admit(Application $application, string $email): ?Admission
    {
        $email = strtolower(trim($email));
        if ($email === '' || !$application->enabled) {
            return null;
        }

        $member = $this->members->authorisedMember($email);
        if ($member !== null) {
            return Admission::member($email, (int) $member->getId());
        }

        $account = $this->accounts->find($application->id, $email);
        if ($account !== null) {
            return Admission::common($email, $account->id);
        }

        return null;
    }
}
