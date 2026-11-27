<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{email: string}>
 */
final class EmailRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'identity.field.email',
            'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)],
            // The account's identifier, as on the sign-in page: the password manager offers the saved one.
            'attr' => ['autocomplete' => 'username'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'password_forgot']);
    }
}
