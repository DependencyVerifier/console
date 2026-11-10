<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Organization\Application\Query\View\PermissionChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One checkbox per ○ permission of the member's role. A permission the actor does not hold and the member has not got
 * is shown disabled (granting it would be refused); a granted one stays enabled, since revoking needs no holding.
 *
 * @extends AbstractType<array{permissions: list<string>}>
 */
final class ExtraPermissionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<PermissionChoice> $choices */
        $choices = $options['permission_choices'];
        $values = array_map(static fn (PermissionChoice $c): string => $c->permission, $choices);
        $disabled = array_map(static fn (PermissionChoice $c): string => $c->permission, array_filter($choices, static fn (PermissionChoice $c): bool => !$c->isGrantableByActor()));

        $builder->add('permissions', ChoiceType::class, [
            'label' => 'organization.permissions.field',
            'help' => [] === $disabled ? null : 'organization.permissions.not_held_help',
            'choices' => array_combine(array_map(static fn (string $v): string => 'permission.'.$v, $values), $values),
            'choice_attr' => static fn (string $value): array => \in_array($value, $disabled, true) ? ['disabled' => 'disabled'] : [],
            'expanded' => true,
            'multiple' => true,
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('permission_choices');
        $resolver->setAllowedTypes('permission_choices', 'array');
    }
}
