<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messenger;

use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;
use Tests\Fixtures\Doctrine\FixtureEvent;

/**
 * Records every published event; a "touched" event triggers a nested command that fails (business rule).
 */
final class FixtureEventHandler implements EventHandler
{
    /** @var list<FixtureEvent> */
    public static array $received = [];

    public function __construct(private readonly CommandBus $commands)
    {
    }

    public function __invoke(FixtureEvent $event): void
    {
        self::$received[] = $event;
        if ('touched' === $event->what) {
            $this->commands->dispatch(new FixtureCommand('rule', $event->id));
        }
    }
}
