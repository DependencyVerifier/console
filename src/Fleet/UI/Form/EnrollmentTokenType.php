<?php

declare(strict_types=1);

namespace Fleet\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new enrollment token: single (1 hour, one agent: nothing to set) or reusable (an environment, 1–90 days, 1–10 000
 * agents). The fields of a reusable token are hidden while "single" is checked (CSS, app.css) and ignored by the
 * command for a single token.
 *
 * @extends AbstractType<array{kind: string, environment: ?string, validity_days: int, max_uses: int}>
 */
final class EnrollmentTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', ChoiceType::class, [
                'label' => 'fleet.field.kind',
                'choices' => ['fleet.token_kind.single' => 'single', 'fleet.token_kind.reusable' => 'reusable'],
                'expanded' => true,
                'data' => 'single',
                'help' => 'fleet.tokens.kind_help',
                'attr' => ['data-token-kind' => ''],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('environment', ChoiceType::class, [
                'label' => 'fleet.field.environment',
                'choices' => $options['environments'],
                'choice_translation_domain' => false,
                'required' => false,
                'placeholder' => 'fleet.tokens.no_environment',
                'help' => 'fleet.tokens.environment_help',
                'row_attr' => ['data-environment' => ''],
            ])
            ->add('validity_days', IntegerType::class, [
                'label' => 'fleet.field.validity_days',
                'data' => 30,
                'row_attr' => ['data-reusable-only' => ''],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 90)],
            ])
            ->add('max_uses', IntegerType::class, [
                'label' => 'fleet.field.max_uses',
                'data' => 10,
                'row_attr' => ['data-reusable-only' => ''],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 10000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id', 'environments']);
        $resolver->setAllowedTypes('environments', 'array');
    }
}
