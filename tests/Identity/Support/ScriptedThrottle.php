<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\ThrottleDecision;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;

/**
 * Accepts everything unless a limiter is refused (or exhausted) for any subject; records the calls.
 */
final class ScriptedThrottle implements AttemptThrottle
{
    /** @var list<string> "peek|consume name subject" */
    public array $calls = [];

    /** @var array<string, true> */
    private array $refused = [];

    /** @var array<string, true> */
    private array $exhausting = [];

    public function __construct(public readonly \DateTimeImmutable $retryAfter = new \DateTimeImmutable('2026-11-09 09:00:00'))
    {
    }

    public function refuse(ThrottleName $name): self
    {
        $this->refused[$name->value] = true;

        return $this;
    }

    public function exhaustOnConsume(ThrottleName $name): self
    {
        $this->exhausting[$name->value] = true;

        return $this;
    }

    public function peek(ThrottleName $name, ThrottleSubject $subject): ThrottleDecision
    {
        $this->calls[] = 'peek '.$name->value.' '.$subject->key;

        return $this->decide($name, false);
    }

    public function consume(ThrottleName $name, ThrottleSubject $subject, int $tokens = 1): ThrottleDecision
    {
        $this->calls[] = 'consume '.$name->value.' '.$subject->key;

        return $this->decide($name, true);
    }

    private function decide(ThrottleName $name, bool $consuming): ThrottleDecision
    {
        if (isset($this->refused[$name->value])) {
            return ThrottleDecision::refused($this->retryAfter);
        }

        return $consuming && isset($this->exhausting[$name->value])
            ? ThrottleDecision::acceptedAndExhausted($this->retryAfter)
            : ThrottleDecision::accepted($this->retryAfter);
    }
}
