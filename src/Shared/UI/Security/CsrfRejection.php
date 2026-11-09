<?php

declare(strict_types=1);

namespace Shared\UI\Security;

use Psr\Log\LoggerInterface;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Single answer to an invalid CSRF token, wherever it comes from (ActionResponder, the authentication pages, the
 * login and second factor failure handlers, the logout): HTTP 403 and exactly one `csrf.rejected` / denied entry,
 * whose context holds the token id only, never the submitted data.
 */
final readonly class CsrfRejection
{
    public function __construct(
        private AuditTrail $auditTrail,
        private AuditEntryFactory $entries,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    public static function action(): AuditAction
    {
        return AuditAction::named('csrf.rejected', ['csrf_token_id']);
    }

    public function reject(string $tokenId): Response
    {
        try {
            $this->auditTrail->record($this->entries->create(
                self::action(),
                Target::none(),
                Outcome::denied('csrf'),
                AuditContext::for(self::action(), ['csrf_token_id' => $tokenId]),
                Scope::none(),
            ));
        } catch (\Throwable $failure) {
            $this->logger->error('The CSRF rejection could not be audited.', ['exception_class' => $failure::class]);
        }

        return new Response($this->twig->render('shared/error/csrf.html.twig'), Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
    }
}
