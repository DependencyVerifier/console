<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Doctrine\ORM\EntityManagerInterface;
use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenId;
use Psr\Container\ContainerInterface;
use Shared\Application\Crypto\TokenHasher;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Functional fixtures of Fleet, written through the aggregates.
 */
final class FleetSeed
{
    /**
     * @return array{EnrollmentToken, string} the token and its plain value (shown once in the real flow)
     */
    public static function token(ContainerInterface $container, string $teamId, bool $reusable = false, bool $approved = false, string $expiresIn = '+30 days'): array
    {
        $plain = 'dvet_'.u(base64_encode(random_bytes(32)))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString();
        $hasher = $container->get('test.token_hasher');
        \assert($hasher instanceof TokenHasher);
        $now = new \DateTimeImmutable();
        $id = EnrollmentTokenId::of(Uuid::v7());
        $token = $reusable
            ? EnrollmentToken::reusable($id, Uuid::fromString($teamId), Uuid::v7(), $hasher->hash($plain), Uuid::v7(), $now, $now->modify($expiresIn), 10)
            : EnrollmentToken::single($id, Uuid::fromString($teamId), null, $hasher->hash($plain), Uuid::v7(), $now);
        if ($approved) {
            $token->approve(Uuid::v7(), $now);
            $token->releaseEvents();
        }

        self::manager($container)->persist($token);
        self::manager($container)->flush();

        return [$token, $plain];
    }

    public static function manager(ContainerInterface $container): EntityManagerInterface
    {
        return $container->get('doctrine.orm.entity_manager');
    }
}
