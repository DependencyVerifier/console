<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Identity\Domain\Model\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new passkey: the name its holder gives it, and the answer of the authenticator (filled by passkey_controller.ts).
 *
 * @extends AbstractType<array{name: string, response: string}>
 */
final class PasskeyType extends AbstractType
{
    /** An attestation is a few kilobytes; anything far larger is not one. */
    public const int RESPONSE_MAX = 65_536;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'identity.account.passkeys.name',
                'help' => 'identity.account.passkeys.name_help',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: User::PASSKEY_NAME_MAX)],
                'attr' => ['maxlength' => User::PASSKEY_NAME_MAX, 'autocomplete' => 'off'],
            ])
            ->add('response', HiddenType::class, [
                'constraints' => [new Assert\NotBlank(message: 'identity.account.passkeys.no_response'), new Assert\Length(max: self::RESPONSE_MAX)],
                'attr' => ['data-passkey-target' => 'response'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
    }
}
