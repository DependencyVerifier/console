<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule\Handler;

use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Repository\NotificationRuleRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Symfony\Component\Uid\Uuid;

/**
 * A rule the actor manages: unknown is a 404, its real scope decides the permission.
 */
final readonly class RuleOfScope
{
    public function __construct(private NotificationRuleRepository $rules, private NotificationAccess $access)
    {
    }

    public function get(string $ruleId): NotificationRule
    {
        $rule = (Uuid::isValid($ruleId) ? $this->rules->find(Uuid::fromString($ruleId)) : null) ?? throw ResourceNotFound::of('rule');
        $this->access->assertManages($rule->teamId()->toRfc4122(), $rule->projectId()?->toRfc4122());

        return $rule;
    }
}
