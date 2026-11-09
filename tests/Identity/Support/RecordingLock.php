<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Locking\TransactionLock;

final class RecordingLock implements TransactionLock
{
    /** @var list<string> */
    public array $keys = [];

    public function acquire(string $key): void
    {
        $this->keys[] = $key;
    }
}
