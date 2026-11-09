<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\UI\Controller\RouteCollection;
use Identity\UI\Frame\TurboFrame;
use Shared\Application\Audit\AuditableCommand;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Action\ActionForm;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One button of the user list: a field-less form named `action`, CSRF token id `<action>:<user id>`. Success
 * refreshes the list frame (Turbo Stream); a refusal is rendered inside that frame with its status.
 */
final readonly class AdminUserAction
{
    public const string FORM_NAME = 'action';

    public function __construct(private ActionResponder $responder, private FormFactoryInterface $forms, private StreamOrRedirect $redirect)
    {
    }

    public static function tokenId(string $action, string $userId): string
    {
        return $action.':'.$userId;
    }

    /**
     * @param \Closure(): AuditableCommand $command
     */
    public function handle(Request $request, string $action, string $userId, \Closure $command): Response
    {
        $form = $this->forms->createNamed(self::FORM_NAME, ActionButtonType::class, null, ['csrf_token_id' => self::tokenId($action, $userId)]);

        return $this->responder->handle(
            $request,
            new ActionForm($form, TurboFrame::AdminUsers->id(), 'identity/admin/user_action_refused.html.twig'),
            $command,
            $this->redirect->withSuccessKey('identity.admin.users.done.'.$action),
            new RouteReference(RouteCollection::ADMIN_USERS->prefixed()),
        );
    }
}
