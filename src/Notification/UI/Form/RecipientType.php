<?php

declare(strict_types=1);

namespace Notification\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An address invited to the notifications of a project, and how often it hears.
 *
 * @extends AbstractType<array{email: string, frequency: string}>
 */
final class RecipientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'notification.field.email', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)]])
            ->add('frequency', ChoiceType::class, [
                'label' => 'notification.field.frequency',
                'choices' => ['notification.frequency.immediate' => 'immediate', 'notification.frequency.daily' => 'daily', 'notification.frequency.weekly' => 'weekly'],
                'data' => 'daily',
                'constraints' => [new Assert\NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id']);
    }
}
