<?php

declare(strict_types=1);

namespace Freedom\Tests\Support;

use Freedom\Accounts\CommonAccount;
use Freedom\Accounts\CommonAccountRepository;
use RuntimeException;

final class InMemoryCommonAccountRepository implements CommonAccountRepository
{
    /** @var array<int, CommonAccount> */
    public array $rows = [];

    private int $nextId = 1;

    public function add(int $applicationId, string $email, string $label, int $createdBy, int $now): CommonAccount
    {
        if ($this->find($applicationId, $email) !== null) {
            throw new RuntimeException('duplicate');
        }

        $account = new CommonAccount($this->nextId++, $applicationId, strtolower($email), $label, $now, $createdBy);
        $this->rows[$account->id] = $account;

        return $account;
    }

    public function remove(int $id): bool
    {
        $existed = isset($this->rows[$id]);
        unset($this->rows[$id]);

        return $existed;
    }

    public function findById(int $id): ?CommonAccount
    {
        return $this->rows[$id] ?? null;
    }

    public function find(int $applicationId, string $email): ?CommonAccount
    {
        foreach ($this->rows as $row) {
            if ($row->applicationId === $applicationId && $row->email === strtolower(trim($email))) {
                return $row;
            }
        }

        return null;
    }

    public function forApplication(int $applicationId): array
    {
        return array_values(array_filter($this->rows, fn(CommonAccount $a): bool => $a->applicationId === $applicationId));
    }

    public function removeForApplication(int $applicationId): int
    {
        $before = count($this->rows);
        $this->rows = array_filter($this->rows, fn(CommonAccount $a): bool => $a->applicationId !== $applicationId);

        return $before - count($this->rows);
    }
}
