<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A button alone is a form without fields, with its own CSRF token id: every state change goes through the Form
 * component (and its CSRF check) and through ActionResponder.
 *
 * @extends AbstractType<null>
 */
final class ActionButtonType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
        $resolver->setDefaults(['csrf_protection' => true, 'data_class' => null]);
    }
}
