<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Shared\Application\Contract\Identity\AccountProvisioning;
use Shared\Application\Contract\Identity\ProvisionedAccount;

/**
 * Identity's port as Organization sees it: known emails return their account, an unknown one is "invited" with the
 * id given by the command, a refused one throws.
 */
final class FixedAccounts implements AccountProvisioning
{
    /** @var list<array{string, string, string}> */
    public array $asked = [];

    /**
     * @param array<string, string> $known email => user id
     */
    public function __construct(private readonly array $known = [], private readonly ?\Throwable $refusal = null)
    {
    }

    public function teamAdminAccount(string $email, string $newUserId, string $setupRequestId): ProvisionedAccount
    {
        $this->asked[] = [$email, $newUserId, $setupRequestId];
        if (null !== $this->refusal) {
            throw $this->refusal;
        }

        $known = $this->known[$email] ?? null;

        return null === $known ? new ProvisionedAccount($newUserId, $email, true) : new ProvisionedAccount($known, $email, false);
    }
}
