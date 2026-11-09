<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{name: string, firstAdminEmail: string}>
 */
final class TeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'organization.field.team_name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('firstAdminEmail', EmailType::class, ['label' => 'organization.field.first_admin_email', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'team_create']);
    }
}
