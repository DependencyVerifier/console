<?php

declare(strict_types=1);

namespace Tests\Fleet\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Tests\Support\IdentityWebTestCase;

/**
 * The install trust chain (cadrage §6): a release imported only once verified, then install.sh holding the console
 * URL, its key and the SHA-256 of each binary, and the binaries mirrored, public.
 */
final class AgentInstallTest extends IdentityWebTestCase
{
    private const string RELEASE = __DIR__.'/../Fixtures/release';

    protected function setUp(): void
    {
        parent::setUp();
        new Filesystem()->remove(self::getContainer()->getParameter('kernel.cache_dir').'/agent-releases');
    }

    #[Test]
    public function it_should_serve_nothing_before_a_verified_release(): void
    {
        $this->client->request('GET', '/install.sh');
        self::assertResponseStatusCodeSame(503);
        self::assertStringContainsString('No verified release', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/downloads/agent/0.1.0/depverify-agent-x86_64-linux');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function it_should_install_from_a_verified_release(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:fleet:import-agent-release'));
        self::assertSame(0, $tester->execute(['dir' => self::RELEASE]));

        $this->client->request('GET', '/install.sh');
        self::assertResponseIsSuccessful();
        self::assertSame('text/x-shellscript; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        $script = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('#!/bin/sh', $script);
        self::assertStringContainsString("CONSOLE_URL='https://localhost'", $script);
        self::assertMatchesRegularExpression("#CONSOLE_PUBKEY='ed25519:[A-Za-z0-9+/]{43}='#", $script);
        self::assertStringContainsString("AGENT_VERSION='0.1.0'", $script);
        self::assertStringContainsString("SHA256='".hash_file('sha256', self::RELEASE.'/depverify-agent-aarch64-linux')."'", $script);
        self::assertStringEndsWith("main \"\$@\"\n", $script, 'Runs nothing until fully downloaded.');

        $this->client->request('GET', '/downloads/agent/0.1.0/depverify-agent-x86_64-linux');
        self::assertResponseIsSuccessful();
        self::assertSame('application/octet-stream', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('depverify-agent-x86_64-linux', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', '/downloads/agent/0.1.0/depverify-agent-riscv64-linux');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/downloads/agent/0.0.1/depverify-agent-x86_64-linux');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function it_should_refuse_a_release_not_signed_by_the_project(): void
    {
        $dir = self::getContainer()->getParameter('kernel.cache_dir').'/forged-release';
        $filesystem = new Filesystem();
        $filesystem->mirror(self::RELEASE, $dir);
        $filesystem->dumpFile($dir.'/depverify-agent-x86_64-linux', "malicious\n");
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:fleet:import-agent-release'));

        self::assertSame(1, $tester->execute(['dir' => $dir]));
        self::assertStringContainsString('Release refused: depverify-agent-x86_64-linux does not match', $tester->getDisplay());
        $this->client->request('GET', '/install.sh');
        self::assertResponseStatusCodeSame(503);
    }
}
