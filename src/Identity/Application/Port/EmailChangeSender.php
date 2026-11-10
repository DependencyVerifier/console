<?php

declare(strict_types=1);

namespace Identity\Application\Port;

/**
 * The two emails of an email change: the confirmation link to the new address, the alert to the previous one.
 */
interface EmailChangeSender
{
    public function sendConfirmation(string $newEmail, #[\SensitiveParameter] string $token): void;

    public function alertPreviousAddress(string $previousEmail): void;
}
