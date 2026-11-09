<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

/**
 * The environment does not belong to this project (the caller answers 404).
 */
final class EnvironmentNotFound extends \DomainException
{
}
