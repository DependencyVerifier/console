<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Identity\Domain\Model\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * New password, typed twice. Never rendered back (always_empty), even on a 422.
 *
 * @extends AbstractType<array{password: string}>
 */
final class NewPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'identity.password.mismatch',
            'first_options' => ['label' => 'identity.field.new_password', 'always_empty' => true, 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'identity.field.repeat_password', 'always_empty' => true, 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [new Assert\NotBlank(), new Assert\Length(min: PasswordPolicy::MIN_LENGTH, max: PasswordPolicy::MAX_LENGTH)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
    }
}
