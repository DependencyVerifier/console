<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{email: string}>
 */
final class UserEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'identity.field.email',
            'help' => 'admin.users.email_help',
            'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)],
        ]);
    }
}
