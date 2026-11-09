<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{name: string, production: bool}>
 */
final class EnvironmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'organization.field.environment_name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 60)]])
            ->add('production', CheckboxType::class, ['label' => 'organization.field.production', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
    }
}
