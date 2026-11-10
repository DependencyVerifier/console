<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\Application\Command\Administration\UpdateUser;
use Identity\Application\Query\User\GetUserProfile;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Form\UserEditType;
use Shared\Application\Bus\QueryBus;
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
 * The "Edit" dialog of a row of /admin/users: the display name, and the instance role (super admin, through its own
 * re-authenticated buttons, shown under the form). The email has its own re-authenticated dialog (AdminUserEmailController).
 */
#[Route(path: '/admin/users/{userId}/edit', name: RouteCollection::ADMIN_USER_EDIT->value, requirements: ['userId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class AdminUserEditController
{
    public const string DIALOG_PREFIX = 'edit-user-';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $userId): Response
    {
        $user = $this->queries->ask(new GetUserProfile($userId));
        $form = $this->forms->createNamed('user_edit', UserEditType::class, ['displayName' => $user->displayName], ['csrf_token_id' => 'user_edit:'.$userId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG_PREFIX.$userId,
            'action.save',
            static fn (): UpdateUser => new UpdateUser($userId, FormData::string($form, 'displayName')),
            $this->redirect->withSuccessKey('admin.users.updated'),
            new RouteReference(RouteCollection::ADMIN_USERS->prefixed()),
            'identity/admin/user_edit_form.html.twig',
            ['user' => $user],
        );
    }
}
