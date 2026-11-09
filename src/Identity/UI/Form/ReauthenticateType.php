<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Password, then the second factor unless the device is trusted (knowledge and possession, or an approved device).
 *
 * @extends AbstractType<array{password: string, code?: ?string}>
 */
final class ReauthenticateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'label' => 'identity.field.password',
            'always_empty' => true,
            'constraints' => [new Assert\NotBlank()],
            'attr' => ['autocomplete' => 'current-password'],
        ]);
        if ($options['with_code']) {
            $builder->add('code', TextType::class, [
                'label' => 'identity.field.code',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 32)],
                'attr' => ['autocomplete' => 'one-time-code', 'value' => ''],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'reauthenticate', 'with_code' => true]);
        $resolver->setAllowedTypes('with_code', 'bool');
    }
}
