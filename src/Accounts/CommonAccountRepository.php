<?php

declare(strict_types=1);

namespace Freedom\Accounts;

if (!defined('ABSPATH')) {
    exit;
}

interface CommonAccountRepository
{
    /** @throws \RuntimeException when the row cannot be written, including an address already listed. */
    public function add(int $applicationId, string $email, string $label, int $createdBy, int $now): CommonAccount;

    public function remove(int $id): bool;

    public function findById(int $id): ?CommonAccount;

    /** The listed account for this address in this application, or null. */
    public function find(int $applicationId, string $email): ?CommonAccount;

    /** @return list<CommonAccount> */
    public function forApplication(int $applicationId): array;

    public function removeForApplication(int $applicationId): int;
}
