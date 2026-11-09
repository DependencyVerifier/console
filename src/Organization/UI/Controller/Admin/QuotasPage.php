<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Organization\UI\Form\QuotasType;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Bus\CommandBus;
use Shared\UI\Http\RefusalResponseTable;
use Shared\UI\Security\CsrfFailure;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Twig\Environment;

/**
 * The quota form of a team or of the instance defaults (plain page, the 9 quotas, empty = unlimited).
 */
final readonly class QuotasPage
{
    public function __construct(
        private FormFactoryInterface $forms,
        private CommandBus $commands,
        private CsrfRejection $csrfRejection,
        private Environment $twig,
    ) {
    }

    /**
     * @param array<string, ?int>                             $current
     * @param \Closure(array<string, ?int>): AuditableCommand $command
     * @param array<string, mixed>                            $context
     */
    public function handle(Request $request, string $tokenId, array $current, \Closure $command, string $successUrl, array $context): Response
    {
        $form = $this->forms->createNamed('quotas', QuotasType::class, $current, ['csrf_token_id' => $tokenId]);
        $form->handleRequest($request);
        $csrf = CsrfFailure::tokenIdOf($form);
        if (null !== $csrf) {
            return $this->csrfRejection->reject($csrf);
        }

        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, ?int> $values */
            $values = $form->getData();
            try {
                $this->commands->dispatch($command($values));
                $session = $request->getSession();
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add('success', 'organization.quotas.saved');
                }

                return new RedirectResponse($successUrl, Response::HTTP_SEE_OTHER);
            } catch (AlreadyAudited $audited) {
                $form->addError(new FormError(RefusalResponseTable::messageKeyOf($audited->cause())));
                $status = RefusalResponseTable::statusOf($audited->cause());
            }
        }

        return new Response($this->twig->render('organization/admin/quotas.html.twig', [...$context, 'form' => $form->createView(), 'nav_active' => 'admin_teams']), $status);
    }
}
