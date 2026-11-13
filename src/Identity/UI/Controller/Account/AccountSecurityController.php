<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Query\User\GetUserProfile;
use Identity\Application\Query\User\PasskeySummary;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Identity\Infrastructure\Crypto\TotpProvisioning;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Password, TOTP (QR code, then confirmation), backup codes and passkeys. Never cached: it may show a TOTP secret.
 */
#[Route(path: '/account/security', name: RouteCollection::ACCOUNT_SECURITY->value, methods: ['GET'])]
final class AccountSecurityController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly UserRepository $users,
        private readonly TotpProvisioning $provisioning,
        private readonly AccountForms $forms,
    ) {
    }

    public function __invoke(#[CurrentUser] SecurityUser $user): Response
    {
        $profile = $this->queries->ask(new GetUserProfile($user->id));
        $account = $this->users->find(UserId::fromString($user->id));
        $provisioning = null !== $account && $profile->isTotpPending() ? $this->provisioning->of($account) : null;

        $response = $this->render('identity/account/security.html.twig', [
            'profile' => $profile,
            'provisioning' => $provisioning,
            'password_form' => $this->forms->password()->createView(),
            'totp_start_form' => $this->forms->button(AccountForms::TOTP_START)->createView(),
            'totp_confirm_form' => $this->forms->totpConfirm()->createView(),
            'totp_disable_form' => $this->forms->button(AccountForms::TOTP_DISABLE)->createView(),
            'backup_codes_form' => $this->forms->button(AccountForms::BACKUP_CODES)->createView(),
            'passkey_form' => $this->forms->passkey()->createView(),
            'passkey_revoke_forms' => array_combine(
                array_map(static fn (PasskeySummary $p): string => $p->id, $profile->passkeys),
                array_map(fn (PasskeySummary $p): FormView => $this->forms->button(AccountForms::passkeyRevoke($p->id))->createView(), $profile->passkeys),
            ),
            'max_passkeys' => User::PASSKEYS_MAX,
            'nav_active' => 'account',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
