<?php

declare(strict_types=1);

namespace Notification\UI\Controller;

use Notification\Application\Command\Send\Unsubscribe;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The unsubscribe link of a notification email, without login: its signature is the authorization (no CSRF token, the
 * one-click POST of RFC 8058 cannot carry one). GET only asks (mail scanners open links), POST turns the subscription
 * off. A link altered or unknown is a 404.
 */
#[Route(path: '/notifications/unsubscribe', name: RouteCollection::UNSUBSCRIBE->value, methods: ['GET', 'POST'])]
final class UnsubscribeController extends AbstractController
{
    public function __construct(private readonly UriSigner $signer, private readonly CommandBus $commands)
    {
    }

    public function __invoke(Request $request): Response
    {
        $subscription = $request->query->getString('subscription');
        if (!Uuid::isValid($subscription) || !$this->signer->checkRequest($request)) {
            throw new NotFoundHttpException();
        }

        $done = false;
        if ($request->isMethod('POST')) {
            try {
                $this->commands->dispatch(new Unsubscribe($subscription));
            } catch (AlreadyAudited) {
                throw new NotFoundHttpException();
            }

            $done = true;
        }

        $response = $this->render('notification/unsubscribe.html.twig', ['done' => $done, 'action' => $request->getUri()]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
