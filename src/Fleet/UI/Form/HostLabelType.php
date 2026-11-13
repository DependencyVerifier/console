<?php

declare(strict_types=1);

namespace Fleet\UI\Form;

use Fleet\Domain\Model\HostLabel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The display name of a host (empty: its hostname) and its IP addresses, one per line. The domain checks them again
 * (HostLabel).
 *
 * @extends AbstractType<array{display_name: ?string, addresses: ?string}>
 */
final class HostLabelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('display_name', TextType::class, [
                'label' => 'fleet.field.display_name',
                'required' => false,
                'help' => 'fleet.hosts.display_name_help',
                'attr' => ['placeholder' => $options['hostname'], 'maxlength' => HostLabel::MAX_NAME],
                'constraints' => [new Assert\Length(max: HostLabel::MAX_NAME)],
            ])
            ->add('addresses', TextareaType::class, [
                'label' => 'fleet.field.addresses',
                'required' => false,
                'help' => 'fleet.hosts.addresses_help',
                'attr' => ['rows' => 4, 'class' => 'font-mono text-xs'],
                'constraints' => [new Assert\Length(max: 1000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id', 'hostname']);
        $resolver->setAllowedTypes('hostname', 'string');
    }
}
