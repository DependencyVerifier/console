<?php

declare(strict_types=1);

namespace Inventory\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{environment: string}>
 */
final class AttachApplicationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('environment', ChoiceType::class, [
            'label' => 'inventory.field.environment',
            'choices' => $options['environments'],
            'choice_translation_domain' => false,
            'placeholder' => 'inventory.applications.choose_environment',
            'constraints' => [new Assert\NotBlank()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id', 'environments']);
        $resolver->setAllowedTypes('environments', 'array');
    }
}
