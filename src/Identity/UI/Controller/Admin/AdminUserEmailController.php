<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\Application\Command\Administration\ChangeUserEmail;
use Identity\Application\Query\User\GetUserProfile;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Form\UserEmailType;
use Identity\UI\Security\RequiresRecentAuthentication;
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
use Symfony\Component\Uid\Uuid;

/**
 * The "Change email" dialog of a row of /admin/users, apart from the edit dialog so that only this gesture asks for a
 * recent authentication (decision 8): with the new address and "forgotten password", it hands the account over. The
 * GET is re-authenticated too, so that the form is never typed for nothing.
 */
#[Route(path: '/admin/users/{userId}/email', name: RouteCollection::ADMIN_USER_EMAIL->value, requirements: ['userId' => Requirement::UUID], methods: ['GET', 'POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_admin_users')]
final readonly class AdminUserEmailController
{
    public const string DIALOG_PREFIX = 'change-email-';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $userId): Response
    {
        $user = $this->queries->ask(new GetUserProfile($userId));
        $form = $this->forms->createNamed('user_email', UserEmailType::class, ['email' => $user->email], ['csrf_token_id' => 'user_email:'.$userId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG_PREFIX.$userId,
            'admin.users.change_email_submit',
            static fn (): ChangeUserEmail => new ChangeUserEmail($userId, FormData::string($form, 'email'), Uuid::v7()->toRfc4122()),
            $this->redirect->withSuccessKey('admin.users.email_requested'),
            new RouteReference(RouteCollection::ADMIN_USERS->prefixed()),
        );
    }
}
