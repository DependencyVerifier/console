<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Command\SecondFactor\IssueEmailLoginCode;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Http\RefusalResponseTable;
use Shared\UI\Security\CsrfFailure;
use Shared\UI\Security\CsrfRejection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Sends a new email code during the second factor (3 resends per 15 minutes). Authentication page: no
 * ActionResponder, but the same CSRF rejection and refusal table.
 */
#[Route(path: '/2fa/resend', name: RouteCollection::TWO_FACTOR_RESEND->value, methods: ['POST'])]
final class TwoFactorResendController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'two_factor_resend';

    public function __construct(private readonly CommandBus $commands, private readonly CsrfRejection $csrfRejection, private readonly FormFactoryInterface $forms)
    {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        $form = $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => self::CSRF_TOKEN_ID]);
        $form->handleRequest($request);
        $csrf = CsrfFailure::tokenIdOf($form);
        if (null !== $csrf || !$form->isSubmitted()) {
            return $this->csrfRejection->reject(self::CSRF_TOKEN_ID);
        }

        $message = 'identity.two_factor.code_resent';
        try {
            $this->commands->dispatch(new IssueEmailLoginCode($user->id, Uuid::v7()->toRfc4122()));
        } catch (AlreadyAudited $audited) {
            $message = RefusalResponseTable::messageKeyOf($audited->cause());
        }

        $request->getSession()->set(SessionKeys::AUTH_ERROR, $message);

        return $this->redirectToRoute('2fa_login', status: Response::HTTP_SEE_OTHER);
    }
}
