<?php

declare(strict_types=1);

namespace Notification\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A rule: its events, its thresholds (vulnerabilities only), the silence of an agent (the fleet only), an environment
 * (a project only), the webhooks to call and whether the subscribers are emailed. The domain checks it all again.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class RuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $events */
        $events = $options['events'];
        $builder
            ->add('name', TextType::class, ['label' => 'notification.field.name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)]])
            ->add('events', ChoiceType::class, [
                'label' => 'notification.field.events',
                'choices' => array_combine(array_map(static fn (string $e): string => 'notification.event.'.$e, $events), $events),
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Assert\Count(min: 1)],
            ]);
        if ($options['project']) {
            $builder->add('environment', ChoiceType::class, [
                'label' => 'notification.field.environment',
                'choices' => $options['environments'],
                'choice_translation_domain' => false,
                'required' => false,
                'placeholder' => 'notification.rules.every_environment',
            ]);
        }

        $builder
            ->add('min_severity', ChoiceType::class, [
                'label' => 'notification.field.min_severity',
                'choices' => ['exposure.severity.low' => 'low', 'exposure.severity.medium' => 'medium', 'exposure.severity.high' => 'high', 'exposure.severity.critical' => 'critical'],
                'required' => false,
                'placeholder' => 'notification.rules.any',
                'help' => 'notification.rules.thresholds_help',
            ])
            ->add('min_cvss', NumberType::class, ['label' => 'notification.field.min_cvss', 'required' => false, 'scale' => 1, 'constraints' => [new Assert\Range(min: 0, max: 10)]])
            ->add('min_epss', NumberType::class, ['label' => 'notification.field.min_epss', 'required' => false, 'scale' => 2, 'help' => 'notification.rules.epss_help', 'constraints' => [new Assert\Range(min: 0, max: 1)]])
            ->add('known_exploited_only', CheckboxType::class, ['label' => 'notification.field.known_exploited_only', 'required' => false]);
        if (!$options['project']) {
            $builder->add('silent_hours', IntegerType::class, ['label' => 'notification.field.silent_hours', 'data' => $options['silent_hours'], 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 720)]]);
        }

        $builder
            ->add('webhooks', ChoiceType::class, [
                'label' => 'notification.field.webhooks',
                'choices' => $options['webhooks'],
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('email_subscribers', CheckboxType::class, ['label' => 'notification.field.email_subscribers', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id', 'events', 'webhooks', 'project']);
        $resolver->setDefaults(['environments' => [], 'silent_hours' => 24]);
        $resolver->setAllowedTypes('events', 'array');
        $resolver->setAllowedTypes('webhooks', 'array');
        $resolver->setAllowedTypes('environments', 'array');
        $resolver->setAllowedTypes('project', 'bool');
        $resolver->setAllowedTypes('silent_hours', 'int');
    }
}
