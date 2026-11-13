<?php

declare(strict_types=1);

namespace Fleet\UI\Command;

use Fleet\Application\Port\ConsoleSigner;
use Fleet\Infrastructure\Dev\FakeAgent;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

/**
 * Dev only: a fake agent speaking protocol v1 to this console, to demo enrollment, approval and orders without the
 * Rust agent. It calls the console over its own HTTPS name, resolved to the local Caddy (the local CA is not checked).
 *   make fleet-fake-agent a='enroll --token=dvet_…'      enrolls (state in var/fake-agent/<name>.json)
 *   make fleet-fake-agent a=poll                        one poll: status, orders checked and answered
 *   make fleet-fake-agent a='run --polls=10'            polls again and again, as told by next_poll_in (capped)
 *   make fleet-fake-agent a=inventory                   sends an inventory now (a SCAN_NOW order does the same).
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:fleet:fake-agent', description: 'Play a fake agent against this console (dev only)')]
final readonly class FakeAgentCommand
{
    public function __construct(
        private HttpClientInterface $http,
        private ConsoleSigner $signer,
        #[Autowire('%kernel.project_dir%/var/fake-agent')]
        private string $stateDirectory,
        #[Autowire(env: 'SERVER_NAME')]
        private string $serverName,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('enroll, poll, run or inventory')] string $action,
        #[Option('Enrollment token (enroll)')] ?string $token = null,
        #[Option('Name of the fake agent (one state file each)')] string $name = 'demo',
        #[Option('Hostname it reports (enroll)')] ?string $hostname = null,
        #[Option('host, container or embedded (enroll)')] string $mode = 'container',
        #[Option('Number of polls (run)')] int $polls = 10,
        #[Option('Longest wait between two polls, in seconds (run)')] int $maxWait = 15,
    ): int {
        $file = \sprintf('%s/%s.json', $this->stateDirectory, u($name)->replaceMatches('/[^a-z0-9_-]/i', '-')->toString());
        $host = u($this->serverName)->split(',')[0]->trim()->toString();
        $url = 'https://'.$host;
        $http = $this->http->withOptions(['resolve' => [$host => '127.0.0.1'], 'verify_peer' => false, 'verify_host' => false]);

        try {
            return match ($action) {
                'enroll' => $this->enroll($io, $http, $file, $url, $token, $hostname ?? 'fake-'.$name, $mode),
                'poll' => $this->poll($io, $http, FakeAgent::load($file), $url),
                'run' => $this->run($io, $http, FakeAgent::load($file), $url, $polls, $maxWait),
                'inventory' => $this->inventory($io, $http, FakeAgent::load($file), $url),
                default => throw new \InvalidArgumentException('The action is enroll, poll, run or inventory.'),
            };
        } catch (\RuntimeException|\InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }
    }

    private function enroll(SymfonyStyle $io, HttpClientInterface $http, string $file, string $url, ?string $token, string $hostname, string $mode): int
    {
        if (null === $token) {
            throw new \InvalidArgumentException('--token is required: create one in the Enrollment section of a team.');
        }

        // Pinned at "install" time, as AGENT_CONSOLE_PUBKEY would be.
        $agent = FakeAgent::create($file, $this->signer->publicKey()->text(), $hostname, $mode);
        $io->writeln(\sprintf('Fingerprint to compare at approval: <info>%s</info>', $agent->fingerprint()));
        $answer = $agent->enroll($http, $url, $token);
        $io->success(\sprintf('Enrolled as %s, status %s. Approve it in the Agents section, then poll.', (string) json_encode($answer['agent_id'] ?? null), (string) json_encode($answer['status'] ?? null)));

        return Command::SUCCESS;
    }

    private function poll(SymfonyStyle $io, HttpClientInterface $http, FakeAgent $agent, string $url): int
    {
        $this->report($io, $agent->poll($http, $url));

        return Command::SUCCESS;
    }

    private function inventory(SymfonyStyle $io, HttpClientInterface $http, FakeAgent $agent, string $url): int
    {
        $this->reportInventory($io, $agent->inventory($http, $url));

        return Command::SUCCESS;
    }

    private function run(SymfonyStyle $io, HttpClientInterface $http, FakeAgent $agent, string $url, int $polls, int $maxWait): int
    {
        for ($i = 1; $i <= $polls; ++$i) {
            $poll = $agent->poll($http, $url);
            $this->report($io, $poll);
            if ($i < $polls) {
                $wait = \is_int($poll['answer']['next_poll_in'] ?? null) ? $poll['answer']['next_poll_in'] : 60;
                sleep(max(1, min($wait, $maxWait)));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{answer: array<string, mixed>, handled: list<array<string, string>>, inventory: ?array{inventory_id: string, missing: int}} $poll
     */
    private function report(SymfonyStyle $io, array $poll): void
    {
        $io->writeln(\sprintf('[%s] status %s, next poll in %s s, %d order(s)', gmdate('H:i:s'), (string) json_encode($poll['answer']['status'] ?? null), (string) json_encode($poll['answer']['next_poll_in'] ?? null), \count($poll['handled'])));
        foreach ($poll['handled'] as $result) {
            $io->writeln(\sprintf('  %s → %s%s', $result['order_id'], $result['status'], isset($result['reason']) ? ' ('.$result['reason'].')' : ''));
        }

        if (null !== $poll['inventory']) {
            $this->reportInventory($io, $poll['inventory']);
        }
    }

    /**
     * @param array{inventory_id: string, missing: int} $inventory
     */
    private function reportInventory(SymfonyStyle $io, array $inventory): void
    {
        $io->writeln(\sprintf('  inventory %s sent, %d snapshot(s) uploaded', $inventory['inventory_id'], $inventory['missing']));
    }
}
