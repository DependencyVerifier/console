<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Passkey;

use Cose\Algorithms;
use Identity\Application\Port\PasskeyCeremony;
use Identity\Application\Port\PasskeyClaim;
use Identity\Application\Port\PasskeyOptions;
use Identity\Domain\Model\NewPasskey;
use Identity\Domain\Model\PasskeyCredential;
use Identity\Domain\Model\PasskeyRejected;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

use function Symfony\Component\String\u;

/**
 * PasskeyCeremony with web-auth/webauthn-lib: the relying party is the host of DEFAULT_URI and the only origin is
 * DEFAULT_URI's own. ES256 and RS256 keys, no attestation (`none`: the console trusts no authenticator model), a
 * discoverable credential preferred, user verification required, 5 minutes to answer. The counter is checked by the
 * aggregate (a regression is refused there), not here.
 */
final readonly class WebAuthnCeremony implements PasskeyCeremony
{
    private const int TIMEOUT_MS = 300_000;
    private const int CHALLENGE_BYTES = 32;

    private SerializerInterface $serializer;
    private string $host;
    private string $origin;

    public function __construct(#[Autowire(env: 'DEFAULT_URI')] string $defaultUri)
    {
        $url = parse_url($defaultUri);
        $this->host = \is_array($url) && isset($url['host']) ? $url['host'] : throw new \InvalidArgumentException('DEFAULT_URI has no host.');
        $this->origin = \sprintf('%s://%s%s', $url['scheme'] ?? 'https', $this->host, isset($url['port']) ? ':'.$url['port'] : '');
        $this->serializer = new WebauthnSerializerFactory(new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]))->create();
    }

    public function creationOptions(string $userId, string $userName, string $displayName, array $excluded): PasskeyOptions
    {
        return $this->options(PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('DependencyVerifier', $this->host),
            PublicKeyCredentialUserEntity::create($userName, $userId, $displayName),
            random_bytes(self::CHALLENGE_BYTES),
            [PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256), PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256)],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                // Required: a passkey that is not discoverable could not sign in without an email (claimOf() needs its user handle).
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(self::descriptor(...), $excluded),
            self::TIMEOUT_MS,
        ));
    }

    public function verifyRegistration(string $response, PasskeyOptions $options): NewPasskey
    {
        $credential = $this->credential($response);
        $creation = $this->load($options, PublicKeyCredentialCreationOptions::class);
        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyRejected();
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->steps()->creationCeremony())->check($credential->response, $creation, $this->host);
        } catch (\Throwable) {
            throw new PasskeyRejected();
        }

        return new NewPasskey(
            Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            base64_encode($record->credentialPublicKey),
            $record->counter,
            array_values(array_filter($record->transports, is_string(...))),
            $record->aaguid->toRfc4122(),
        );
    }

    public function requestOptions(array $allowed): PasskeyOptions
    {
        return $this->options(PublicKeyCredentialRequestOptions::create(
            random_bytes(self::CHALLENGE_BYTES),
            $this->host,
            array_map(self::descriptor(...), $allowed),
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::TIMEOUT_MS,
        ));
    }

    public function claimOf(string $response): ?PasskeyClaim
    {
        try {
            $json = json_decode($response, true, 8, \JSON_THROW_ON_ERROR);
            $id = \is_array($json) ? $json['id'] ?? null : null;
            $handle = \is_array($json) && \is_array($json['response'] ?? null) ? $json['response']['userHandle'] ?? null : null;
            $userId = \is_string($handle) ? Base64UrlSafe::decodeNoPadding(u($handle)->trimEnd('=')->toString()) : '';
        } catch (\Throwable) {
            return null;
        }

        return \is_string($id) && '' !== $id && Uuid::isValid($userId) ? new PasskeyClaim($id, $userId) : null;
    }

    public function verifyAssertion(string $response, PasskeyOptions $options, PasskeyCredential $credential, string $userId): int
    {
        $assertion = $this->credential($response);
        $request = $this->load($options, PublicKeyCredentialRequestOptions::class);
        if (!$assertion->response instanceof AuthenticatorAssertionResponse || Base64UrlSafe::encodeUnpadded($assertion->rawId) !== $credential->credentialId()) {
            throw new PasskeyRejected();
        }

        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->steps()->requestCeremony())->check(
                CredentialRecord::create(
                    Base64UrlSafe::decodeNoPadding($credential->credentialId()),
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $credential->transports(),
                    'none',
                    EmptyTrustPath::create(),
                    Uuid::fromString($credential->aaguid()),
                    base64_decode($credential->publicKey(), true) ?: throw new PasskeyRejected(),
                    $userId,
                    $credential->signCount(),
                ),
                $assertion->response,
                $request,
                $this->host,
                $userId,
            );
        } catch (PasskeyRejected $rejected) {
            throw $rejected;
        } catch (\Throwable) {
            throw new PasskeyRejected();
        }

        return $record->counter;
    }

    private function steps(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin]);
        // The aggregate judges the counter (PasskeyCredential::use()).
        $factory->setCounterChecker(new class implements CounterChecker {
            public function check(CredentialRecord $publicKeyCredentialSource, int $currentCounter): void
            {
            }
        });

        return $factory;
    }

    private function options(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): PasskeyOptions
    {
        return new PasskeyOptions($this->serializer->serialize($options, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true, JsonEncode::OPTIONS => \JSON_THROW_ON_ERROR]));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function load(PasskeyOptions $options, string $class): object
    {
        try {
            $loaded = $this->serializer->deserialize($options->json, $class, 'json');
        } catch (\Throwable) {
            throw new PasskeyRejected();
        }

        return $loaded instanceof $class ? $loaded : throw new PasskeyRejected();
    }

    private function credential(string $response): PublicKeyCredential
    {
        try {
            return $this->serializer->deserialize($response, PublicKeyCredential::class, 'json');
        } catch (\Throwable) {
            throw new PasskeyRejected();
        }
    }

    private static function descriptor(string $credentialId): PublicKeyCredentialDescriptor
    {
        return PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, Base64UrlSafe::decodeNoPadding($credentialId));
    }
}
