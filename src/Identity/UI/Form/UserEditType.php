<?php

declare(strict_types=1);

namespace Identity\UI\Form;

use Identity\Domain\Model\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{displayName: ?string}>
 */
final class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('displayName', TextType::class, [
            'label' => 'identity.field.display_name',
            'help' => 'admin.users.display_name_help',
            'required' => false,
            'empty_data' => '',
            'constraints' => [new Assert\Length(max: User::DISPLAY_NAME_MAX)],
        ]);
    }
}
