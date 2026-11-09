<?php

declare(strict_types=1);

namespace Shared\Application\Security;

/**
 * Runs a callable under an exclusive lock on a subject, released even when the callable throws. Acquisition is
 * bounded (2 s); beyond it, Contended is thrown (shown to the user as "throttled", audited as `lock_contention`).
 */
interface ExclusiveSection
{
    /**
     * @template T
     *
     * @param callable(): T $section
     *
     * @throws Contended
     *
     * @return T
     */
    public function run(ThrottleSubject $subject, callable $section): mixed;
}
