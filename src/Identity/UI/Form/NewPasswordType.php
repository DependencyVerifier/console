<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Identity\Domain\Model\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * New password, typed once (autofill-sign-up-form: a confirmation field is extra work and password managers fill
 * one field); the form theme adds a "Show password" toggle against typos. Its rules are the field's help, and the
 * browser knows the length bounds. Never rendered back (always_empty), even on a 422.
 *
 * @extends AbstractType<array{password: string}>
 */
final class NewPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'label' => 'identity.field.new_password',
            'help' => 'identity.password.rules',
            'always_empty' => true,
            'attr' => ['autocomplete' => 'new-password', 'minlength' => PasswordPolicy::MIN_LENGTH, 'maxlength' => PasswordPolicy::MAX_LENGTH],
            'constraints' => [new Assert\NotBlank(), new Assert\Length(min: PasswordPolicy::MIN_LENGTH, max: PasswordPolicy::MAX_LENGTH)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
    }
}
