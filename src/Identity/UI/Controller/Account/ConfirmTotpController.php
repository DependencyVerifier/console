<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Command\SecondFactor\ConfirmTotp;
use Identity\Application\Query\SecondFactor\GetCodeVerificationOutcome;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Security\RequiresRecentAuthentication;
use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Crypto\TokenGenerator;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\FormData;
use Shared\UI\Action\SecretRevealingResponse;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * First TOTP code: on acceptance only, the backup codes generated here (never form fields) are shown once in the
 * response of this POST. A wrong code answers 422 (429 when throttled), without any code; TOTP stays pending.
 */
#[Route(path: '/account/security/totp/confirm', name: RouteCollection::ACCOUNT_TOTP_CONFIRM->value, methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_account_security')]
final readonly class ConfirmTotpController
{
    public function __construct(
        private ActionResponder $responder,
        private AccountForms $forms,
        private TokenGenerator $tokens,
        private QueryBus $queries,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        $form = $this->forms->totpConfirm();
        $action = $this->forms->action($form, 'identity.account.totp.title', RouteCollection::ACCOUNT_TOTP_CONFIRM->prefixed());
        $codes = BackupCodes::generate($this->tokens);

        return $this->responder->handle(
            $request,
            $action,
            static fn (): ConfirmTotp => new ConfirmTotp($user->id, FormData::string($form, 'code'), Uuid::v7()->toRfc4122(), $codes),
            new SecretRevealingResponse(function (AuditableCommand $command) use ($codes, $form, $action): Response {
                $outcome = $command instanceof ConfirmTotp ? $this->queries->ask(new GetCodeVerificationOutcome($command->attemptId)) : CodeVerificationOutcome::Rejected;
                if ($outcome->isAccepted()) {
                    return new Response($this->twig->render('identity/account/backup_codes.html.twig', ['codes' => $codes, 'first_enrollment' => true]));
                }

                $form->addError(new FormError(CodeVerificationOutcome::Throttled === $outcome ? 'refusal.throttled' : 'identity.two_factor.error.invalid_code'));

                return new Response($this->twig->render($action->template, [...$action->templateContext, 'form' => $form->createView()]), CodeVerificationOutcome::Throttled === $outcome ? 429 : 422);
            }),
            new RouteReference(RouteCollection::ACCOUNT_SECURITY->prefixed()),
        );
    }
}
