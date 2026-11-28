<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function Symfony\Component\String\u;

/**
 * Enforces #[TurboFrameOnly] before the controller runs, so after the firewall and access_control: an anonymous or
 * unauthorized visitor still gets their usual answer, a request without (or with an empty) `Turbo-Frame` header a 404.
 */
#[AsEventListener]
final readonly class TurboFrameOnlyListener
{
    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if ([] === $event->getAttributes(TurboFrameOnly::class)) {
            return;
        }

        if (u($event->getRequest()->headers->get('Turbo-Frame') ?? '')->trim()->isEmpty()) {
            throw new NotFoundHttpException('There is no page here outside a Turbo Frame.');
        }
    }
}
