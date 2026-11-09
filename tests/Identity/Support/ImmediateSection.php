<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Security\Contended;
use Shared\Application\Security\ExclusiveSection;
use Shared\Application\Security\ThrottleSubject;

final class ImmediateSection implements ExclusiveSection
{
    /** @var list<string> */
    public array $subjects = [];

    public function __construct(private readonly bool $contended = false)
    {
    }

    public function run(ThrottleSubject $subject, callable $section): mixed
    {
        $this->subjects[] = $subject->key;
        if ($this->contended) {
            throw new Contended('busy');
        }

        return $section();
    }
}
