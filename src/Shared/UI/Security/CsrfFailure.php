<?php

declare(strict_types=1);

namespace Shared\UI\Security;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * A submitted form whose error is caused by a CsrfToken: a CSRF attempt, not a typing mistake.
 */
final class CsrfFailure
{
    /**
     * @param FormInterface<covariant mixed> $form
     */
    public static function tokenIdOf(FormInterface $form): ?string
    {
        foreach ($form->getErrors(true) as $error) {
            $cause = $error->getCause();
            if ($cause instanceof CsrfToken) {
                return $cause->getId();
            }
        }

        return null;
    }
}
