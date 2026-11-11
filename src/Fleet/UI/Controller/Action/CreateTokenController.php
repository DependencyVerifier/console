<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Token\CreateEnrollmentToken;
use Fleet\UI\Controller\RouteCollection;
use Fleet\UI\Controller\Team\FleetPage;
use Fleet\UI\Form\EnrollmentTokenType;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Crypto\TokenGenerator;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\SecretRevealingResponse;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * The "New token" dialog of the Enrollment section. On success, the same dialog shows the token once, with the
 * Compose snippet and the console key the agent pins: 200 in the response of the POST, never cached, never through
 * the session. CreateEnrollmentToken judges the permission, the environment and the quotas.
 */
#[Route(path: '/teams/{teamId}/enrollment/new', name: RouteCollection::TOKEN_CREATE->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class CreateTokenController
{
    public const string DIALOG = 'create-token';

    public function __construct(
        private FleetPage $page,
        private QueryBus $queries,
        private FormFactoryInterface $forms,
        private DialogForm $dialog,
        private TokenGenerator $tokens,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $fleet = $this->page->fleet($teamId);
        if (!$fleet->can('host.enroll')) {
            throw new AccessDeniedException();
        }

        $environments = [];
        foreach ($this->queries->ask(new ListTeamEnvironments($teamId)) as $environment) {
            $environments[$environment->projectName.' / '.$environment->name] = $environment->environmentId;
        }

        $form = $this->forms->createNamed('token', EnrollmentTokenType::class, null, ['csrf_token_id' => 'token_create:'.$teamId, 'environments' => $environments]);
        $plain = 'dvet_'.$this->tokens->urlToken();

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'fleet.tokens.create',
            static fn (): CreateEnrollmentToken => new CreateEnrollmentToken(
                Uuid::v7()->toRfc4122(),
                $teamId,
                'reusable' === FormData::string($form, 'kind'),
                FormData::nullableString($form, 'environment'),
                $plain,
                FormData::int($form, 'validity_days'),
                FormData::int($form, 'max_uses'),
            ),
            new SecretRevealingResponse(fn (): Response => new Response($this->twig->render('fleet/dialog/token_created.html.twig', [
                'frame_id' => self::DIALOG.DialogForm::FRAME_SUFFIX,
                'token' => $plain,
                'fleet' => $fleet,
                'console_url' => $request->getSchemeAndHttpHost(),
            ]))),
            new RouteReference(RouteCollection::TEAM_ENROLLMENT->prefixed(), ['teamId' => $teamId]),
        );
    }
}
