<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Shared\Application\Contract\Identity\RecipientAccount;
use Shared\Application\Contract\Identity\RecipientProvisioning;

/**
 * Identity's port as Notification sees it: a known email returns its account (signing in or not), an unknown one is
 * created with the id given, a refused one throws.
 */
final class FixedRecipientAccounts implements RecipientProvisioning
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, array{string, bool}> $known email => [user id, signs in]
     */
    public function __construct(private readonly array $known = [], public ?\Throwable $refusal = null)
    {
    }

    public function recipientAccount(string $email, string $newUserId): RecipientAccount
    {
        $this->asked[] = $email;
        if (null !== $this->refusal) {
            throw $this->refusal;
        }

        [$id, $signsIn] = $this->known[$email] ?? [$newUserId, false];

        return new RecipientAccount($id, $signsIn);
    }
}
