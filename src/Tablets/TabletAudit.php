<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Accounts\CommonAccount;
use Scrutiny\Audit\Interfaces\AuditLogger;

/**
 * Everything Freedom writes to Scrutiny's audit log, in one place so the
 * lines stay the same shape.
 *
 * <b>Which entity.</b> A tablet signed in with a member's own account is
 * that member's business and is logged against them, as Fellowship logs a
 * Link handset. A tablet signed in with a common account belongs to no
 * member, so it is logged against the tablet itself (`freedom_tablet`),
 * and adding or removing a common account against the account
 * (`freedom_account`). Scrutiny stores any entity type it is given.
 *
 * <b>Never an address.</b> Scrutiny's contract is no raw personal data in
 * the detail, and a common account's address is somebody's Google
 * account, so accounts appear by id only.
 *
 * Configuration values are not audited here at all: they are not
 * personal data. Edits go to Freedom's own log, key and version only.
 */
final class TabletAudit
{
    public const ENTITY_TABLET = 'freedom_tablet';
    public const ENTITY_ACCOUNT = 'freedom_account';

    public function __construct(private readonly AuditLogger $logger)
    {
    }

    public function enrolled(Tablet $tablet, string $applicationSlug, bool $reattached, string $via): void
    {
        $this->forTablet(
            AuditLogger::ACTION_CREATE,
            $tablet,
            sprintf(
                'Freedom tablet %s via %s;app:%s;tablet:%d',
                $reattached ? 're-attached' : 'enrolled',
                $via,
                $applicationSlug,
                $tablet->id,
            ),
        );
    }

    public function revoked(Tablet $tablet, string $by): void
    {
        $this->forTablet(AuditLogger::ACTION_DELETE, $tablet, 'Freedom tablet revoked;tablet:' . $tablet->id . ';by:' . $by);
    }

    public function blocked(Tablet $tablet, string $by): void
    {
        $this->forTablet(AuditLogger::ACTION_UPDATE, $tablet, 'Freedom tablet blocked;tablet:' . $tablet->id . ';by:' . $by);
    }

    public function unblocked(Tablet $tablet, string $by): void
    {
        $this->forTablet(AuditLogger::ACTION_UPDATE, $tablet, 'Freedom tablet unblocked;tablet:' . $tablet->id . ';by:' . $by);
    }

    public function removed(Tablet $tablet, string $by): void
    {
        $this->forTablet(AuditLogger::ACTION_DELETE, $tablet, 'Freedom tablet removed;tablet:' . $tablet->id . ';by:' . $by);
    }

    public function accountAdded(CommonAccount $account, string $by): void
    {
        $this->logger->log(
            AuditLogger::ACTION_CREATE,
            self::ENTITY_ACCOUNT,
            $account->id,
            'authentication',
            'Freedom common account added;app:' . $account->applicationId . ';by:' . $by,
        );
    }

    public function accountRemoved(CommonAccount $account, string $by): void
    {
        $this->logger->log(
            AuditLogger::ACTION_DELETE,
            self::ENTITY_ACCOUNT,
            $account->id,
            'authentication',
            'Freedom common account removed;app:' . $account->applicationId . ';by:' . $by,
        );
    }

    private function forTablet(string $action, Tablet $tablet, string $detail): void
    {
        if ($tablet->isMemberAccount() && $tablet->memberId > 0) {
            $this->logger->log($action, AuditLogger::ENTITY_MEMBER, $tablet->memberId, 'authentication', $detail);

            return;
        }

        $this->logger->log(
            $action,
            self::ENTITY_TABLET,
            $tablet->id,
            'authentication',
            $detail . ($tablet->accountId > 0 ? ';account:' . $tablet->accountId : ''),
        );
    }
}
