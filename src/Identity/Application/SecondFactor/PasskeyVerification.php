<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

/**
 * A passkey gesture: the assertion is the code, checked against the options the server gave.
 */
interface PasskeyVerification extends CodeVerification
{
    public string $options { get; }
}
