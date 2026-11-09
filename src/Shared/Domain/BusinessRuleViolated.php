<?php

declare(strict_types=1);

namespace Shared\Domain;

use function Symfony\Component\String\u;

/**
 * Family of business refusals raised by the domains (quota reached, last team admin, project not empty…).
 * The rule key is stable: it names the audit reason (`rule:<key>`) and the translation key of the message.
 */
abstract class BusinessRuleViolated extends \DomainException
{
    public function __construct(private readonly string $ruleKey, string $message = '')
    {
        if ([] === u($ruleKey)->match('/^[a-z][a-z0-9_]{1,63}$/')) {
            throw new \InvalidArgumentException(\sprintf('Invalid rule key "%s".', $ruleKey));
        }

        parent::__construct('' !== $message ? $message : $ruleKey);
    }

    final public function ruleKey(): string
    {
        return $this->ruleKey;
    }
}
