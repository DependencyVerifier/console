<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Recipient\AttachRecipient;
use Notification\UI\Controller\RouteCollection;
use Notification\UI\Form\RecipientType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The "Add recipient" dialog of a project: an address invited, the same answer whatever the address.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/notifications/recipients/new', name: RouteCollection::RECIPIENT_ATTACH->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class AttachRecipientController
{
    public const string DIALOG = 'attach-recipient';

    public function __construct(private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $form = $this->forms->createNamed('recipient', RecipientType::class, null, ['csrf_token_id' => 'recipient_attach:'.$projectId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'notification.recipients.invite',
            static fn (): AttachRecipient => new AttachRecipient($teamId, $projectId, FormData::string($form, 'email'), FormData::string($form, 'frequency')),
            $this->redirect->withSuccessKey('notification.recipients.invited'),
            new RouteReference(RouteCollection::PROJECT_NOTIFICATIONS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
