<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Shared\Application\Contract\Identity\ProvisionedAccount;
use Shared\Application\Contract\Identity\RecipientProvisioning;

/**
 * Identity's port as Notification sees it: a known email returns its account, an unknown one is created with the id
 * given, a refused one throws.
 */
final class FixedRecipientAccounts implements RecipientProvisioning
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, string> $known email => user id
     */
    public function __construct(private readonly array $known = [], public ?\Throwable $refusal = null)
    {
    }

    public function notificationAccount(string $email, string $newUserId): ProvisionedAccount
    {
        $this->asked[] = $email;
        if (null !== $this->refusal) {
            throw $this->refusal;
        }

        $known = $this->known[$email] ?? null;

        return null === $known ? new ProvisionedAccount($newUserId, $email, true) : new ProvisionedAccount($known, $email, false);
    }
}
