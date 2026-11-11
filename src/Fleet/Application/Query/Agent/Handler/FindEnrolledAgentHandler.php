<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Agent\Handler;

use Fleet\Application\Port\ConsoleSigner;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Query\Agent\FindEnrolledAgent;
use Fleet\Application\Query\View\EnrollmentAnswer;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Repository\AgentRepository;
use Shared\Application\Bus\QueryHandler;

final readonly class FindEnrolledAgentHandler implements QueryHandler
{
    public function __construct(private AgentRepository $agents, private ConsoleSigner $signer)
    {
    }

    public function __invoke(FindEnrolledAgent $query): EnrollmentAnswer
    {
        $fingerprint = AgentPublicKey::fromText($query->publicKey)->fingerprint();
        $agent = $this->agents->findLiveByFingerprint($fingerprint) ?? throw new ProtocolRefusal(Problem::UnknownAgent);

        return new EnrollmentAnswer(
            $agent->id()->toString(),
            $agent->isApproved() ? 'approved' : 'pending',
            $fingerprint,
            $this->signer->publicKey()->fingerprint(),
            $agent->isApproved() ? GetPollAnswerHandler::APPROVED_POLL : GetPollAnswerHandler::PENDING_POLL,
        );
    }
}
