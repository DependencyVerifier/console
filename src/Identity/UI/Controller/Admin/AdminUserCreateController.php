<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\Application\Command\Administration\InviteUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Form\UserInviteType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The "Create a user" dialog of /admin/users: an email, then the setup link leaves (the account has no password).
 */
#[Route(path: '/admin/users/new', name: RouteCollection::ADMIN_USER_CREATE->value, methods: ['GET', 'POST'])]
final readonly class AdminUserCreateController
{
    public const string DIALOG = 'create-user';

    public function __construct(private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->forms->createNamed('user', UserInviteType::class, null, ['csrf_token_id' => 'user_invite']);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'admin.users.invite',
            static fn (): InviteUser => new InviteUser(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), FormData::string($form, 'email')),
            $this->redirect->withSuccessKey('admin.users.invited'),
            new RouteReference(RouteCollection::ADMIN_USERS->prefixed()),
        );
    }
}
