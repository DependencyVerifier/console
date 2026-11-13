<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Host\Handler;

use Inventory\Application\Command\Host\RecordHostLabel;
use Inventory\Domain\Model\HostLabel;
use Inventory\Domain\Repository\HostLabelRepository;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

final readonly class RecordHostLabelHandler implements CommandHandler
{
    public function __construct(private HostLabelRepository $labels)
    {
    }

    public function __invoke(RecordHostLabel $command): void
    {
        $label = $this->labels->find(Uuid::fromString($command->hostId));
        if (null === $label) {
            $this->labels->add(HostLabel::of(Uuid::fromString($command->hostId), Uuid::fromString($command->teamId), $command->displayName, $command->addresses));

            return;
        }

        $label->change($command->displayName, $command->addresses);
    }
}
