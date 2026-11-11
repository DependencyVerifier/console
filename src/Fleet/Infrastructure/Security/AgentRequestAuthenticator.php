<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Security;

use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Application\Protocol\SignatureInput;
use Fleet\Application\Protocol\SignedRequest;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\UI\Http\ProblemResponse;
use Psr\Clock\ClockInterface;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\Target;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The `agent` firewall (stateless, `^/agent/v1`): every request but the enrollment is signed by an enrolled agent.
 * Protocol §4.3 in order (RequestVerifier), then the agent state (a revoked agent is refused, a pending one may
 * poll) and the per-agent limiter, counted only for verified requests (a forged keyid cannot exhaust an agent's
 * window). Each refusal is audited (endpoint, claimed key id, reason, IP), never the body.
 */
final class AgentRequestAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string ENROLLMENT_PATH = '/agent/v1/enrollments';

    public function __construct(
        private readonly RequestVerifier $verifier,
        private readonly AgentRepository $agents,
        private readonly AttemptThrottle $throttle,
        private readonly AuditTrail $auditTrail,
        private readonly AuditEntryFactory $entries,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function signedRequestOf(Request $request): SignedRequest
    {
        $headers = [];
        foreach (['Date', 'Content-Digest', 'Signature-Input', 'Signature'] as $name) {
            $value = $request->headers->get($name);
            if (null !== $value) {
                $headers[$name] = $value;
            }
        }

        return new SignedRequest($request->getMethod(), $request->getSchemeAndHttpHost().$request->getRequestUri(), $headers, $request->getContent());
    }

    public function supports(Request $request): bool
    {
        return self::ENROLLMENT_PATH !== $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $claimed = null;
        try {
            // Before reading the body: the declared length first, then the bytes received.
            $max = RequestVerifier::maxBytesOf($request->getMethod(), $request->getPathInfo());
            if ((int) $request->headers->get('Content-Length', '0') > $max) {
                throw new ProtocolRefusal(Problem::PayloadTooLarge);
            }

            $signed = self::signedRequestOf($request);
            if (RequestVerifier::isBodyTooLarge($signed->body, $max)) {
                throw new ProtocolRefusal(Problem::PayloadTooLarge);
            }

            $claimed = SignatureInput::of($signed)->keyId;
            $agent = null;
            $this->verifier->verify($signed, function (string $keyId) use (&$agent): ?AgentPublicKey {
                $agent = $this->agents->findByFingerprint($keyId);

                return $agent?->publicKey();
            });
            \assert(null !== $agent);
            if (AgentStatus::Revoked === $agent->status()) {
                throw new ProtocolRefusal(Problem::AgentRevoked);
            }

            $decision = $this->throttle->consume(ThrottleName::AgentRequests, ThrottleSubject::forAgent($agent->id()->toString()));
            if (!$decision->accepted) {
                throw new Throttled($decision->retryAfter);
            }
        } catch (ProtocolRefusal|Throttled $refused) {
            throw new AgentAuthenticationFailed($refused, $claimed);
        }

        $agentId = $agent->id()->toString();

        return new SelfValidatingPassport(new UserBadge($agentId, static fn (): AgentUser => new AgentUser($agentId)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $now = $this->clock->now();
        $cause = $exception instanceof AgentAuthenticationFailed ? $exception->cause : null;
        $action = AuditAction::named('fleet.agent_request', ['endpoint', 'key_id']);
        $context = ['endpoint' => $request->getPathInfo()];
        if ($exception instanceof AgentAuthenticationFailed && null !== $exception->claimedKeyId) {
            $context['key_id'] = $exception->claimedKeyId;
        }

        $outcome = $cause instanceof Throttled ? Outcome::denied('throttled') : Outcome::denied('rule:'.($cause instanceof ProtocolRefusal ? $cause->problem->value : Problem::InvalidSignature->value));
        $this->auditTrail->record($this->entries->create($action, Target::none(), $outcome, AuditContext::for($action, $context)));

        return match (true) {
            $cause instanceof Throttled => ProblemResponse::of(Problem::RateLimited, $now, retryAfter: $cause->retryAfter),
            $cause instanceof ProtocolRefusal => ProblemResponse::of($cause->problem, $now, $cause->detail),
            default => ProblemResponse::of(Problem::InvalidSignature, $now),
        };
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ProblemResponse::of(Problem::InvalidSignature, $this->clock->now());
    }
}
