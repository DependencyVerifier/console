<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Application\Port\PasskeyCeremony;
use Identity\Application\Port\PasskeyClaim;
use Identity\Application\Port\PasskeyOptions;
use Identity\Domain\Model\NewPasskey;
use Identity\Domain\Model\PasskeyCredential;
use Identity\Domain\Model\PasskeyRejected;

/**
 * PasskeyCeremony without cryptography: a registration answer is the credential id ('bad' is refused), an assertion
 * the JSON `{"id": credential id, "user": user id, "count": counter, "valid": bool}`.
 */
final class ScriptedCeremony implements PasskeyCeremony
{
    /** @var list<string> */
    public array $calls = [];

    public function creationOptions(string $userId, string $userName, string $displayName, array $excluded): PasskeyOptions
    {
        return new PasskeyOptions(json_encode(['create' => [$userId, $userName, $displayName, $excluded]], \JSON_THROW_ON_ERROR));
    }

    public function verifyRegistration(string $response, PasskeyOptions $options): NewPasskey
    {
        $this->calls[] = 'register '.$response.' '.$options->json;

        return 'bad' === $response ? throw new PasskeyRejected() : new NewPasskey($response, 'cose:'.$response, 0, ['internal'], '00000000-0000-0000-0000-000000000000');
    }

    public function requestOptions(array $allowed): PasskeyOptions
    {
        return new PasskeyOptions(json_encode(['get' => $allowed], \JSON_THROW_ON_ERROR));
    }

    public function claimOf(string $response): ?PasskeyClaim
    {
        $answer = self::answer($response);

        return null === $answer ? null : new PasskeyClaim($answer['id'], $answer['user']);
    }

    public function verifyAssertion(string $response, PasskeyOptions $options, PasskeyCredential $credential, string $userId): int
    {
        $answer = self::answer($response);
        $this->calls[] = 'assert '.$credential->credentialId().' '.$userId.' '.$options->json;
        if (null === $answer || !$answer['valid']) {
            throw new PasskeyRejected();
        }

        return $answer['count'];
    }

    public static function assertion(string $credentialId, string $userId = Users::ID, int $count = 1, bool $valid = true): string
    {
        return json_encode(['id' => $credentialId, 'user' => $userId, 'count' => $count, 'valid' => $valid], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{id: string, user: string, count: int, valid: bool}|null
     */
    private static function answer(string $response): ?array
    {
        $answer = json_decode($response, true);

        /** @var array{id: string, user: string, count: int, valid: bool}|null */
        return \is_array($answer) && isset($answer['id'], $answer['user'], $answer['count'], $answer['valid']) ? $answer : null;
    }
}
