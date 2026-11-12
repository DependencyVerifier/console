<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Action;

use Exposure\Application\Command\Risk\AcceptRisk;
use Exposure\Application\Query\GetFinding;
use Exposure\UI\Controller\RouteCollection;
use Exposure\UI\Form\AcceptRiskType;
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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The "Accept risk" dialog of a finding: a justification and an expiry. AcceptRisk judges again.
 */
#[Route(path: '/teams/{teamId}/findings/{findingId}/accept', name: RouteCollection::FINDING_ACCEPT->value, requirements: ['teamId' => Requirement::UUID, 'findingId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class AcceptRiskController
{
    public const string DIALOG = 'accept-risk';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $findingId): Response
    {
        if (!$this->queries->ask(new GetFinding($teamId, $findingId))->mayDecide) {
            throw new AccessDeniedException();
        }

        $form = $this->forms->createNamed('accept', AcceptRiskType::class, null, ['csrf_token_id' => 'finding_accept:'.$findingId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'exposure.risk.accept',
            static function () use ($form, $findingId): AcceptRisk {
                $expiresOn = $form->get('expiresOn')->getData();

                return new AcceptRisk($findingId, FormData::string($form, 'justification'), $expiresOn instanceof \DateTimeImmutable ? $expiresOn->format('Y-m-d') : '');
            },
            $this->redirect->withSuccessKey('exposure.risk.accepted'),
            new RouteReference(RouteCollection::FINDING->prefixed(), ['teamId' => $teamId, 'findingId' => $findingId]),
        );
    }
}
