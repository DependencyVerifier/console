<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

/**
 * Published port of Identity, called inside the caller's command (same transaction, no nested bus) once the holder of
 * an email has proved it (the confirmation link of a notification recipient): the account of that email. An unknown
 * email gets a notification-only account (no password, no `account.login`, nothing sent by Identity); an existing
 * account is returned as is, telling whether it signs in. A deactivated or deleted one is refused.
 */
interface RecipientProvisioning
{
    /**
     * @param string $newUserId id of the account if it has to be created
     */
    public function recipientAccount(string $email, string $newUserId): RecipientAccount;
}
