<?php

declare(strict_types=1);

namespace Notification\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A webhook: a name and an HTTPS URL (the domain refuses anything else).
 *
 * @extends AbstractType<array{name: string, url: string}>
 */
final class WebhookType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'notification.field.name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)]])
            ->add('url', UrlType::class, ['label' => 'notification.field.url', 'default_protocol' => 'https', 'help' => 'notification.webhooks.url_help', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 2048)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id']);
    }
}
