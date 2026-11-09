<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{name: string}>
 */
final class NameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $max = $options['max_length'];
        \assert(\is_int($max) && $max > 0);
        $builder->add('name', TextType::class, [
            'label' => $options['label_key'],
            'constraints' => [new Assert\NotBlank(), new Assert\Length(max: $max)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
        $resolver->setDefaults(['label_key' => 'organization.field.name', 'max_length' => 120]);
    }
}
