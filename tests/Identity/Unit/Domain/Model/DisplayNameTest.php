<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Model\InvalidDisplayName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Identity\Support\Users;

use function Symfony\Component\String\u;

final class DisplayNameTest extends TestCase
{
    #[Test]
    public function it_should_name_an_account_and_clear_its_name(): void
    {
        $user = Users::member();
        self::assertSame('', $user->displayName(), 'No name by default, never null.');

        $user->rename('  Jane Doe  ');
        self::assertSame('Jane Doe', $user->displayName());

        $longest = u('j')->repeat(100)->toString();
        $user->rename($longest);
        self::assertSame($longest, $user->displayName());

        $user->rename('   ');
        self::assertSame('', $user->displayName(), 'Empty clears the name.');
        self::assertSame([], $user->releaseEvents());
    }

    #[Test]
    public function it_should_refuse_a_name_longer_than_one_hundred_characters(): void
    {
        $user = Users::member();
        $user->rename('Jane');

        try {
            $user->rename(u('j')->repeat(101)->toString());
            self::fail('Too long.');
        } catch (InvalidDisplayName $refused) {
            self::assertSame('invalid_display_name', $refused->ruleKey());
        }

        self::assertSame('Jane', $user->displayName(), 'Unchanged.');
    }
}
