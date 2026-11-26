<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps what a handler logs, to check it names a field and never carries a value an agent sent.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $logs = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = [\is_string($level) ? $level : '', (string) $message, $context];
    }
}
