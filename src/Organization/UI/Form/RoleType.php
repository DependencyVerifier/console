<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{role: string}>
 */
final class RoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['project'] ? ProjectRole::cases() : TeamRole::cases() as $role) {
            $choices['role.'.$role->value] = $role->value;
        }

        $builder->add('role', ChoiceType::class, ['label' => false, 'choices' => $choices, 'constraints' => [new Assert\NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
        $resolver->setDefaults(['project' => false]);
        $resolver->setAllowedTypes('project', 'bool');
    }
}
