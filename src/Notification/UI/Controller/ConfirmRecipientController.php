<?php

declare(strict_types=1);

namespace Notification\UI\Controller;

use Notification\Application\Command\Recipient\ConfirmRecipient;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The confirmation link of a notification recipient, without login (the account cannot sign in): GET only asks (mail
 * scanners open links), POST confirms. The token is the authorization; an unknown or expired one shows the same
 * page as a spent one.
 */
#[Route(path: '/notifications/confirm/{token}', name: RouteCollection::CONFIRM_RECIPIENT->value, requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET', 'POST'])]
final class ConfirmRecipientController extends AbstractController
{
    public function __construct(private readonly CommandBus $commands)
    {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $state = 'ask';
        if ($request->isMethod('POST')) {
            try {
                $this->commands->dispatch(new ConfirmRecipient($token));
                $state = 'done';
            } catch (AlreadyAudited) {
                $state = 'invalid';
            }
        }

        $response = $this->render('notification/confirm.html.twig', ['state' => $state], new Response(status: 'invalid' === $state ? 422 : 200));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
