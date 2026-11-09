<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Name and quotas of a team (super admin).
 *
 * @extends AbstractType<array{name: string, quotas: array<string, ?int>}>
 */
final class TeamEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'organization.field.team_name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('quotas', QuotasType::class, ['label' => 'organization.quotas.team', 'help' => 'organization.quotas.help', 'attr' => ['class' => 'grid gap-4 sm:grid-cols-2']]);
    }
}
