<?php

declare(strict_types=1);

namespace Notification\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One's own subscription: its frequency (`off` stops it).
 *
 * @extends AbstractType<array{frequency: string}>
 */
final class FrequencyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('frequency', ChoiceType::class, [
            'label' => 'notification.field.my_frequency',
            'choices' => ['notification.frequency.off' => 'off', 'notification.frequency.immediate' => 'immediate', 'notification.frequency.daily' => 'daily', 'notification.frequency.weekly' => 'weekly'],
            'constraints' => [new Assert\NotBlank()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id']);
    }
}
