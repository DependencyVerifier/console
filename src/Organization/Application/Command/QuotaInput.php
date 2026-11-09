<?php

declare(strict_types=1);

namespace Organization\Application\Command;

use Organization\Domain\Model\Quota;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamQuotas;

/**
 * The 9 quotas of a form (null: unlimited).
 */
final class QuotaInput
{
    /**
     * @param array<string, ?int> $values
     */
    public static function teamQuotas(array $values): TeamQuotas
    {
        $quotas = [];
        foreach (QuotaName::cases() as $name) {
            if (!\array_key_exists($name->value, $values)) {
                throw new \InvalidArgumentException(\sprintf('Quota "%s" is missing.', $name->value));
            }

            $value = $values[$name->value];
            $quotas[$name->value] = null === $value ? Quota::unlimited() : Quota::of($value);
        }

        return TeamQuotas::of($quotas);
    }
}
