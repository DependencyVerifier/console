<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule\Handler;

use Notification\Application\Command\Rule\DefineRule;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Repository\NotificationRuleRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

final readonly class DefineRuleHandler implements CommandHandler
{
    public function __construct(private NotificationAccess $access, private RuleInput $input, private NotificationRuleRepository $rules, private ClockInterface $clock)
    {
    }

    public function __invoke(DefineRule $command): void
    {
        $this->access->assertManages($command->teamId, $command->projectId);
        $team = Uuid::fromString($command->teamId);
        $settings = $command->settings;
        $this->rules->add(NotificationRule::define(
            Uuid::fromString($command->ruleId),
            $team,
            null === $command->projectId ? null : Uuid::fromString($command->projectId),
            $this->input->environment($command->teamId, $command->projectId, $settings),
            $settings->name,
            $this->input->events($settings),
            $this->input->thresholds($settings),
            $this->input->webhooks($team, $settings),
            $settings->emailSubscribers,
            $this->clock->now(),
        ));
    }
}
