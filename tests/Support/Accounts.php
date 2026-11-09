<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\TotpSecretBinding;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Security\TotpVerifier;
use Identity\Infrastructure\Security\SecurityUser;
use OTPHP\TOTP;
use Shared\Application\Crypto\SecretBox;
use Shared\Application\Crypto\TokenHasher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Test accounts written straight through the ORM (inside the DAMA transaction of the test).
 */
final class Accounts
{
    public const string PASSWORD = 'correct horse battery staple';

    public static function create(ContainerInterface $container, string $email, bool $superAdmin = false): User
    {
        $hash = self::hasher($container)->getPasswordHasher(SecurityUser::class)->hash(self::PASSWORD);
        $id = UserId::of(Uuid::v7());
        $user = $superAdmin
            ? User::createSuperAdmin($id, EmailAddress::of($email), $hash, new \DateTimeImmutable())
            : User::activateFromInvitation($id, EmailAddress::of($email), $hash, new \DateTimeImmutable());
        self::manager($container)->persist($user);
        self::manager($container)->flush();

        return $user;
    }

    /**
     * Enrols and confirms TOTP; returns the plain secret (to compute codes) and the backup codes.
     *
     * @return array{secret: string, backupCodes: list<string>}
     */
    public static function withTotp(ContainerInterface $container, User $user): array
    {
        $secretBox = $container->get('test.secret_box');
        $tokens = $container->get('test.token_hasher');
        if (!$secretBox instanceof SecretBox || !$tokens instanceof TokenHasher) {
            throw new \LogicException('Test aliases of config/services_test.yaml are missing.');
        }

        $secret = TOTP::generate()->getSecret();
        $user->startTotpEnrollment($secretBox->encrypt($secret, TotpSecretBinding::for($user->id())));
        $backupCodes = ['aaaaa-bbbbb', 'ccccc-ddddd'];
        $verifier = new readonly class($secret) implements TotpVerifier {
            public function __construct(private string $secret)
            {
            }

            public function matchingStep(string $encryptedSecret, UserId $owner, string $code, \DateTimeImmutable $now): ?int
            {
                return '' !== $this->secret ? intdiv($now->getTimestamp(), 30) - 10 : null;
            }
        };
        $user->confirmTotp('000000', $verifier, array_map($tokens->hash(...), $backupCodes), new \DateTimeImmutable());
        $user->releaseEvents();
        self::manager($container)->flush();

        return ['secret' => $secret, 'backupCodes' => $backupCodes];
    }

    public static function totpCode(string $secret, int $offsetSteps = 0): string
    {
        if ('' === $secret) {
            throw new \LogicException('Empty secret.');
        }

        return TOTP::createFromSecret($secret)->at(max(0, time() + 30 * $offsetSteps));
    }

    public static function manager(ContainerInterface $container): EntityManagerInterface
    {
        return $container->get('doctrine.orm.entity_manager');
    }

    private static function hasher(ContainerInterface $container): PasswordHasherFactoryInterface
    {
        $hasher = $container->get('test.password_hasher_factory');
        if (!$hasher instanceof PasswordHasherFactoryInterface) {
            throw new \LogicException('Test alias test.password_hasher_factory is missing.');
        }

        return $hasher;
    }
}
