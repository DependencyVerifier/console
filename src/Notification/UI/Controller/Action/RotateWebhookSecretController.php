<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Webhook\RotateWebhookSecret;
use Notification\UI\Controller\RouteCollection;
use Shared\Application\Crypto\TokenGenerator;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\SecretRevealingResponse;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;

/**
 * The "New secret" dialog of a webhook: a confirmation, then the new secret shown once.
 */
#[Route(path: '/teams/{teamId}/notifications/webhooks/{webhookId}/secret', name: RouteCollection::WEBHOOK_ROTATE->value, requirements: ['teamId' => Requirement::UUID, 'webhookId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class RotateWebhookSecretController
{
    public const string DIALOG_PREFIX = 'rotate-webhook-';

    public function __construct(private FormFactoryInterface $forms, private DialogForm $dialog, private TokenGenerator $tokens, private Environment $twig)
    {
    }

    public function __invoke(Request $request, string $teamId, string $webhookId): Response
    {
        $form = $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => 'webhook_rotate:'.$webhookId]);
        $secret = WebhookSecret::generate($this->tokens);
        $dialog = self::DIALOG_PREFIX.$webhookId;

        return $this->dialog->handle(
            $request,
            $form,
            $dialog,
            'notification.webhooks.rotate_confirm',
            static fn (): RotateWebhookSecret => new RotateWebhookSecret($webhookId, $secret),
            new SecretRevealingResponse(fn (): Response => new Response($this->twig->render('notification/dialog/webhook_secret.html.twig', [
                'frame_id' => $dialog.DialogForm::FRAME_SUFFIX,
                'secret' => $secret,
                'message' => 'notification.webhooks.rotated',
            ]))),
            new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
