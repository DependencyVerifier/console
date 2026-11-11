<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Shared\Domain\BusinessRuleViolated;

trait AssertsRefusals
{
    /**
     * @param class-string<BusinessRuleViolated> $class
     * @param \Closure(): mixed                  $gesture
     */
    private function assertRefused(string $class, string $ruleKey, \Closure $gesture): void
    {
        try {
            $gesture();
            self::fail(\sprintf('Expected %s.', $class));
        } catch (BusinessRuleViolated $refused) {
            self::assertInstanceOf($class, $refused);
            self::assertSame($ruleKey, $refused->ruleKey());
        }
    }
}
