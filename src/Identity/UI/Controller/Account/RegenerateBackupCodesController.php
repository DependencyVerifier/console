<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Command\SecondFactor\RegenerateBackupCodes;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Security\RequiresRecentAuthentication;
use Shared\Application\Crypto\TokenGenerator;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\SecretRevealingResponse;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;

#[Route(path: '/account/security/backup-codes', name: RouteCollection::ACCOUNT_BACKUP_CODES->value, methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_account_security')]
final readonly class RegenerateBackupCodesController
{
    public function __construct(
        private ActionResponder $responder,
        private AccountForms $forms,
        private TokenGenerator $tokens,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        $codes = BackupCodes::generate($this->tokens);

        return $this->responder->handle(
            $request,
            $this->forms->action($this->forms->button(AccountForms::BACKUP_CODES), 'identity.account.backup_codes.title', RouteCollection::ACCOUNT_BACKUP_CODES->prefixed()),
            static fn (): RegenerateBackupCodes => new RegenerateBackupCodes($user->id, $codes),
            new SecretRevealingResponse(fn (): Response => new Response($this->twig->render('identity/account/backup_codes.html.twig', ['codes' => $codes, 'first_enrollment' => false]))),
            new RouteReference(RouteCollection::ACCOUNT_SECURITY->prefixed()),
        );
    }
}
