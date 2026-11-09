<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One-time code (TOTP, email code or backup code), never rendered back.
 *
 * @extends AbstractType<array{code: string}>
 */
final class CodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => 'identity.field.code',
            'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 32)],
            'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'text', 'value' => ''],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
    }
}
