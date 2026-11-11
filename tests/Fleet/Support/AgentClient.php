<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Application\Protocol\SignedRequest;
use Fleet\Domain\Model\AgentPublicKey;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function Symfony\Component\String\u;

/**
 * Plays the agent: a fresh Ed25519 key, requests signed with the depverify-agent-v1 profile (protocol §4), each with a
 * new nonce. `$tamper` bends one element to test a refusal: created offset, nonce, keyid, digest, body sent.
 */
final readonly class AgentClient
{
    public AgentPublicKey $key;

    /**
     * @param non-empty-string $secretKey
     */
    private function __construct(private string $secretKey, string $publicKey)
    {
        $this->key = AgentPublicKey::fromRaw($publicKey);
    }

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair));
    }

    /**
     * @param array<string, mixed>                                                                                                 $body
     * @param array{created?: int, expires?: int, nonce?: string, keyid?: string, digest?: string, body?: string, unsigned?: bool} $tamper
     */
    public function post(KernelBrowser $client, string $path, array $body, array $tamper = []): void
    {
        $signed = $this->sign('https://localhost'.$path, $body, time(), $tamper);
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($signed->headers as $name => $value) {
            $server['HTTP_'.u($name)->upper()->replace('-', '_')->toString()] = $value;
        }

        $client->request('POST', $signed->targetUri, server: $server, content: $signed->body);
    }

    /**
     * A POST signed at $now (plus the `created` offset of $tamper).
     *
     * @param array<string, mixed>                                                                                                 $body
     * @param array{created?: int, expires?: int, nonce?: string, keyid?: string, digest?: string, body?: string, unsigned?: bool} $tamper
     */
    public function sign(string $uri, array $body, int $now, array $tamper = []): SignedRequest
    {
        $json = json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $created = $now + ($tamper['created'] ?? 0);
        $date = gmdate('D, d M Y H:i:s \G\M\T', $created);
        $digest = $tamper['digest'] ?? RequestVerifier::digestOf($json);
        $parameters = \sprintf(
            '("@method" "@target-uri" "content-digest" "date");created=%d;expires=%d;keyid="%s";alg="ed25519";nonce="%s";tag="depverify-agent-v1"',
            $created, $created + ($tamper['expires'] ?? 300), $tamper['keyid'] ?? $this->key->fingerprint(), $tamper['nonce'] ?? self::nonce(),
        );
        $base = u("\n")->join(['"@method": POST', '"@target-uri": '.$uri, '"content-digest": '.$digest, '"date": '.$date, '"@signature-params": '.$parameters])->toString();
        $headers = ['Date' => $date, 'Content-Digest' => $digest];
        if (!($tamper['unsigned'] ?? false)) {
            $headers['Signature-Input'] = 'sig1='.$parameters;
            $headers['Signature'] = 'sig1=:'.base64_encode(sodium_crypto_sign_detached($base, $this->secretKey)).':';
        }

        return new SignedRequest('POST', $uri, $headers, $tamper['body'] ?? $json);
    }

    /**
     * @param array<string, mixed> $host
     */
    public function enroll(KernelBrowser $client, string $token, array $host = [], string $mode = 'container'): void
    {
        $this->post($client, '/agent/v1/enrollments', [
            'protocol_version' => 1,
            'enrollment_token' => $token,
            'public_key' => $this->key->text(),
            'agent_version' => '0.1.0',
            'mode' => $mode,
            'scope' => 'container' === $mode ? 'compose' : null,
            'compose_project' => 'container' === $mode ? 'shop' : null,
            'host' => $host + ['hostname' => 'web-01', 'os' => ['id' => 'debian', 'version_id' => '12', 'pretty_name' => 'Debian 12'], 'arch' => 'x86_64', 'machine_id_hash' => Fleet::MACHINE],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed>       $tamper
     */
    public function poll(KernelBrowser $client, array $results = [], int $configVersion = 0, array $tamper = []): void
    {
        /** @var array{created?: int, expires?: int, nonce?: string, keyid?: string, digest?: string, body?: string, unsigned?: bool} $tamper */
        $this->post($client, '/agent/v1/poll', ['protocol_version' => 1, 'agent_version' => '0.1.0', 'config_version' => $configVersion, 'order_results' => $results], $tamper);
    }

    /**
     * A request without body (no Content-Digest, profile `"@method" "@target-uri" "date"`).
     */
    public function signBodyless(string $uri, int $now): SignedRequest
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', $now);
        $parameters = \sprintf('("@method" "@target-uri" "date");created=%d;expires=%d;keyid="%s";alg="ed25519";nonce="%s";tag="depverify-agent-v1"', $now, $now + 300, $this->key->fingerprint(), self::nonce());
        $base = u("\n")->join(['"@method": POST', '"@target-uri": '.$uri, '"date": '.$date, '"@signature-params": '.$parameters])->toString();

        return new SignedRequest('POST', $uri, [
            'Date' => $date,
            'Signature-Input' => 'sig1='.$parameters,
            'Signature' => 'sig1=:'.base64_encode(sodium_crypto_sign_detached($base, $this->secretKey)).':',
        ], '');
    }

    public static function nonce(): string
    {
        return u(base64_encode(random_bytes(16)))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString();
    }
}
