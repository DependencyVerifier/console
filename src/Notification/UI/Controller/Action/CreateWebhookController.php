<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Webhook\RegisterWebhook;
use Notification\UI\Controller\RouteCollection;
use Notification\UI\Form\WebhookType;
use Shared\Application\Crypto\TokenGenerator;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\SecretRevealingResponse;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * The "New webhook" dialog: on success, the same dialog shows its secret once (200, never cached, never the session).
 */
#[Route(path: '/teams/{teamId}/notifications/webhooks/new', name: RouteCollection::WEBHOOK_CREATE->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class CreateWebhookController
{
    public const string DIALOG = 'create-webhook';

    public function __construct(private FormFactoryInterface $forms, private DialogForm $dialog, private TokenGenerator $tokens, private Environment $twig)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $form = $this->forms->createNamed('webhook', WebhookType::class, null, ['csrf_token_id' => 'webhook_create:'.$teamId]);
        $secret = WebhookSecret::generate($this->tokens);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'notification.webhooks.create',
            static fn (): RegisterWebhook => new RegisterWebhook(Uuid::v7()->toRfc4122(), $teamId, FormData::string($form, 'name'), FormData::string($form, 'url'), $secret),
            new SecretRevealingResponse(fn (): Response => new Response($this->twig->render('notification/dialog/webhook_secret.html.twig', [
                'frame_id' => self::DIALOG.DialogForm::FRAME_SUFFIX,
                'secret' => $secret,
                'message' => 'notification.webhooks.created',
            ]))),
            new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
