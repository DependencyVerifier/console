<?php

declare(strict_types=1);

namespace Identity\UI\Command;

use Identity\Application\Command\Account\CreateSuperAdmin;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\UI\Http\RefusalResponseTable;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\String\u;

/**
 * Creates a super admin (actor `cli`). The password is typed hidden, or read from a file only its owner can read;
 * it is never displayed. Writes with the runtime role (no owner credentials needed).
 */
#[AsCommand(name: 'app:user:create-super-admin', description: 'Create a super admin account')]
final readonly class CreateSuperAdminCommand
{
    public function __construct(private CommandBus $commands, private TranslatorInterface $translator)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email address of the account')] string $email,
        #[Option('Read the password from this file (must not be readable by group or others)')] ?string $passwordFile = null,
    ): int {
        $password = null !== $passwordFile ? $this->readPasswordFile($passwordFile, $io) : $this->askPassword($io);
        if (null === $password) {
            return Command::FAILURE;
        }

        $id = Uuid::v7()->toRfc4122();
        try {
            $this->commands->dispatch(new CreateSuperAdmin($id, $email, $password));
        } catch (AlreadyAudited $audited) {
            $io->error($this->translator->trans(RefusalResponseTable::messageKeyOf($audited->cause())));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Super admin %s created (id %s). Sign in, then enable TOTP from "Account security".', u($email)->trim()->lower(), $id));

        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io): ?string
    {
        $password = $io->askHidden('Password (12 characters at least)');
        $repeat = $io->askHidden('Repeat the password');
        if (!\is_string($password) || $password !== $repeat) {
            $io->error('The passwords do not match.');

            return null;
        }

        return $password;
    }

    private function readPasswordFile(string $path, SymfonyStyle $io): ?string
    {
        $permissions = @fileperms($path);
        if (false === $permissions || !is_readable($path)) {
            $io->error('The password file cannot be read.');

            return null;
        }

        if (0 !== ($permissions & 0o077)) {
            $io->error('The password file must be readable by its owner only (chmod 600).');

            return null;
        }

        $content = file_get_contents($path);

        return false === $content ? null : u($content)->trimEnd("\r\n")->toString();
    }
}
