<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Email and role: the team roles for a team invitation, the project roles for a project invitation (the handler
 * checks that the actor may grant it).
 *
 * @extends AbstractType<array{email: string, role: string}>
 */
final class InviteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $roles = $options['project'] ? ProjectRole::cases() : TeamRole::cases();
        $choices = [];
        foreach ($roles as $role) {
            $choices['role.'.$role->value] = $role->value;
        }

        $builder
            ->add('email', EmailType::class, ['label' => 'identity.field.email', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)]])
            ->add('role', ChoiceType::class, ['label' => 'organization.field.role', 'choices' => $choices, 'constraints' => [new Assert\NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
        $resolver->setDefaults(['project' => false]);
        $resolver->setAllowedTypes('project', 'bool');
    }
}
