<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

enum EnrollmentTokenKind: string
{
    /** One enrollment, valid 1 hour; its agent always waits for a human approval. */
    case Single = 'single';
    /** Several enrollments (images, autoscaling); once approved, its agents are approved at enrollment (§6). */
    case Reusable = 'reusable';
}
