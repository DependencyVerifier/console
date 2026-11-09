<?php

declare(strict_types=1);

namespace Tests\Identity\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\AuditLog;

final class CreateSuperAdminCommandTest extends KernelTestCase
{
    #[Test]
    public function it_should_create_a_super_admin_with_a_hidden_password(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a long enough passphrase', 'a long enough passphrase']);

        self::assertSame(0, $tester->execute(['email' => 'Root@Example.com']));
        self::assertStringNotContainsString('a long enough passphrase', $tester->getDisplay());
        $row = $this->connection()->fetchAssociative('SELECT super_admin, password_hash FROM identity_user WHERE email = :email', ['email' => 'root@example.com']);
        self::assertIsArray($row);
        self::assertTrue((bool) $row['super_admin']);
        self::assertIsString($row['password_hash']);
        self::assertStringStartsWith('$argon2id$', $row['password_hash']);
        $entries = AuditLog::entries($this->connection(), 'identity.create_super_admin');
        self::assertSame('success', $entries[0]['outcome'] ?? null);
    }

    #[Test]
    public function it_should_refuse_mismatching_or_weak_passwords_and_open_password_files(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a long enough passphrase', 'another one']);
        self::assertSame(1, $tester->execute(['email' => 'root@example.com']));

        $tester->setInputs(['short', 'short']);
        self::assertSame(1, $tester->execute(['email' => 'root@example.com']));
        self::assertStringContainsString('12 to 4096 characters', $tester->getDisplay());

        $file = tempnam(sys_get_temp_dir(), 'pw');
        self::assertIsString($file);
        file_put_contents($file, "a long enough passphrase\n");
        chmod($file, 0o644);
        self::assertSame(1, $tester->execute(['email' => 'root@example.com', '--password-file' => $file]));
        self::assertStringContainsString('chmod 600', $tester->getDisplay());

        chmod($file, 0o600);
        self::assertSame(0, $tester->execute(['email' => 'root@example.com', '--password-file' => $file]));
        unlink($file);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:user:create-super-admin'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
