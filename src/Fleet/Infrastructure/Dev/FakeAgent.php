<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Dev;

use Fleet\Application\Protocol\CanonicalJson;
use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Domain\Model\AgentPublicKey;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * A stand-in for the Rust agent, for local demos only (`make fleet-fake-agent`): it keeps its state in a JSON file
 * (private key, agent id, pinned console key, applied configuration, pending order results) and speaks protocol v1
 * exactly as the agent must: signed requests (§4), order checks (§7.2), results at the next poll (§7.5).
 * It executes nothing: SCAN_NOW is reported `executed`, SET_CONFIG applies its version.
 */
final class FakeAgent
{
    private const string VERSION = '0.0.0-fake';

    /**
     * @param array{secret_key: string, public_key: string, console_key: string, agent_id: ?string, config_version: int, results: list<array<string, string>>, seen_orders: list<string>, hostname: string, mode: string} $state
     */
    private function __construct(private array $state, private readonly string $file)
    {
    }

    private mixed $lastRaw = null;

    public static function create(string $file, string $consoleKey, string $hostname, string $mode): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self([
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public_key' => AgentPublicKey::fromRaw(sodium_crypto_sign_publickey($pair))->text(),
            'console_key' => AgentPublicKey::fromText($consoleKey)->text(),
            'agent_id' => null,
            'config_version' => 0,
            'results' => [],
            'seen_orders' => [],
            'hostname' => $hostname,
            'mode' => $mode,
        ], $file);
    }

    public static function load(string $file): self
    {
        $json = @file_get_contents($file);
        if (false === $json) {
            throw new \RuntimeException(\sprintf('No fake agent state in %s: enroll first.', $file));
        }

        /** @var array{secret_key: string, public_key: string, console_key: string, agent_id: ?string, config_version: int, results: list<array<string, string>>, seen_orders: list<string>, hostname: string, mode: string} $state */
        $state = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);

        return new self($state, $file);
    }

    public function fingerprint(): string
    {
        return AgentPublicKey::fromText($this->state['public_key'])->fingerprint();
    }

    /**
     * @return array<string, mixed> the console's answer
     */
    public function enroll(HttpClientInterface $http, string $consoleUrl, string $token): array
    {
        $container = 'container' === $this->state['mode'];
        $answer = $this->post($http, $consoleUrl, '/agent/v1/enrollments', [
            'protocol_version' => 1,
            'enrollment_token' => $token,
            'public_key' => $this->state['public_key'],
            'agent_version' => self::VERSION,
            'mode' => $this->state['mode'],
            'scope' => $container ? 'compose' : null,
            'compose_project' => $container ? 'demo' : null,
            'host' => [
                'hostname' => $this->state['hostname'],
                'os' => ['id' => 'debian', 'version_id' => '12', 'pretty_name' => 'Debian GNU/Linux 12 (bookworm)'],
                'kernel' => '6.1.0-27-amd64',
                'arch' => 'x86_64',
                'machine_id_hash' => hash('sha256', $this->state['hostname']),
            ],
        ]);
        if (($answer['console_key_id'] ?? null) !== AgentPublicKey::fromText($this->state['console_key'])->fingerprint()) {
            throw new \RuntimeException('console_key_mismatch: the console does not hold the pinned key.');
        }

        $this->state['agent_id'] = \is_string($answer['agent_id'] ?? null) ? $answer['agent_id'] : null;
        $this->save();

        return $answer;
    }

    /**
     * One poll: sends the pending results, checks and "executes" the orders received, keeps their results for the
     * next poll.
     *
     * @return array{answer: array<string, mixed>, handled: list<array<string, string>>}
     */
    public function poll(HttpClientInterface $http, string $consoleUrl): array
    {
        $answer = $this->post($http, $consoleUrl, '/agent/v1/poll', [
            'protocol_version' => 1,
            'agent_version' => self::VERSION,
            'config_version' => $this->state['config_version'],
            'order_results' => $this->state['results'],
        ]);
        $this->state['results'] = [];
        $handled = [];
        // The orders as received, objects kept as objects: `{}` must stay `{}` in the canonical JSON that was signed.
        $raw = $this->lastRaw instanceof \stdClass && \is_array($this->lastRaw->orders ?? null) ? $this->lastRaw->orders : [];
        foreach (\is_array($answer['orders'] ?? null) ? $answer['orders'] : [] as $index => $envelope) {
            $handled[] = $this->state['results'][] = $this->handle($envelope, $raw[$index] ?? null);
        }

        $this->save();

        return ['answer' => $answer, 'handled' => $handled];
    }

    /**
     * Protocol §7.2, in order: signature with the pinned key, agent id, validity window, replay, catalogue.
     *
     * @return array<string, string>
     */
    private function handle(mixed $envelope, mixed $received): array
    {
        $signed = $received instanceof \stdClass ? ($received->order ?? null) : null;
        $order = \is_array($envelope) && \is_array($envelope['order'] ?? null) ? $envelope['order'] : [];
        $signature = \is_array($envelope) && \is_array($envelope['signature'] ?? null) ? $envelope['signature'] : [];
        $id = \is_string($order['order_id'] ?? null) ? $order['order_id'] : 'unknown';
        $now = time();
        $value = base64_decode(u(\is_string($signature['value'] ?? null) ? $signature['value'] : '')->replace('-', '+')->replace('_', '/')->toString(), true);
        $key = AgentPublicKey::fromText($this->state['console_key']);
        $reason = match (true) {
            false === $value || \SODIUM_CRYPTO_SIGN_BYTES !== b($value)->length(),
            ($signature['key_id'] ?? null) !== $key->fingerprint(),
            !self::verify($value, CanonicalJson::encode($signed), $key) => 'bad_signature',
            ($order['agent_id'] ?? null) !== $this->state['agent_id'] => 'wrong_agent',
            $now < self::time($order['not_before'] ?? null) - 60 || $now > self::time($order['expires_at'] ?? null) + 60 => 'expired',
            \in_array($id, $this->state['seen_orders'], true) => 'replayed',
            !\in_array($order['type'] ?? null, ['SCAN_NOW', 'SET_CONFIG'], true) => 'unknown_type',
            default => null,
        };
        $this->state['seen_orders'][] = $id;
        if (null === $reason && 'SET_CONFIG' === $order['type'] && \is_array($order['payload'] ?? null) && \is_int($order['payload']['config_version'] ?? null)) {
            $this->state['config_version'] = max($this->state['config_version'], $order['payload']['config_version']);
        }

        $result = ['order_id' => $id, 'status' => null === $reason ? 'executed' : 'rejected', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z')];

        return null === $reason ? $result : $result + ['reason' => $reason];
    }

    private static function time(mixed $value): int
    {
        $time = \is_string($value) ? strtotime($value) : false;

        return false === $time ? 0 : $time;
    }

    private static function verify(string|false $signature, string $message, AgentPublicKey $key): bool
    {
        $raw = $key->raw;

        return \is_string($signature) && '' !== $signature && '' !== $raw && sodium_crypto_sign_verify_detached($signature, $message, $raw);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(HttpClientInterface $http, string $consoleUrl, string $path, array $body): array
    {
        $json = json_encode(array_filter($body, static fn (mixed $value): bool => null !== $value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $uri = u($consoleUrl)->trimEnd('/')->toString().$path;
        $created = time();
        $date = gmdate('D, d M Y H:i:s \G\M\T', $created);
        $digest = RequestVerifier::digestOf($json);
        $parameters = \sprintf(
            '("@method" "@target-uri" "content-digest" "date");created=%d;expires=%d;keyid="%s";alg="ed25519";nonce="%s";tag="depverify-agent-v1"',
            $created, $created + 300, $this->fingerprint(), u(base64_encode(random_bytes(16)))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString(),
        );
        $base = u("\n")->join(['"@method": POST', '"@target-uri": '.$uri, '"content-digest": '.$digest, '"date": '.$date, '"@signature-params": '.$parameters])->toString();
        $secret = base64_decode($this->state['secret_key'], true);
        if (false === $secret || '' === $secret) {
            throw new \RuntimeException('Corrupted fake agent state.');
        }

        $response = $http->request('POST', $uri, ['headers' => [
            'Content-Type' => 'application/json',
            'User-Agent' => 'depverify-agent/'.self::VERSION.' (fake)',
            'Date' => $date,
            'Content-Digest' => $digest,
            'Signature-Input' => 'sig1='.$parameters,
            'Signature' => 'sig1=:'.base64_encode(sodium_crypto_sign_detached($base, $secret)).':',
        ], 'body' => $json, 'max_redirects' => 0]);

        $content = $response->getContent(false);
        $this->lastRaw = json_decode($content, false, 32);

        return self::answer($response, $content);
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(ResponseInterface $response, string $content): array
    {
        $decoded = json_decode($content, true, 32);
        if ($response->getStatusCode() >= 300 || !\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('HTTP %d: %s', $response->getStatusCode(), u($content)->truncate(500)));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function save(): void
    {
        $directory = \dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create '.$directory);
        }

        file_put_contents($this->file, json_encode($this->state, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        chmod($this->file, 0o600);
    }
}
