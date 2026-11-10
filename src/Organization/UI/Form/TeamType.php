<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The first admin is picked among the instance accounts (Symfony UX Autocomplete on Identity's search endpoint), or
 * typed as a new email: the value posted is the email either way.
 *
 * @extends AbstractType<array{name: string, firstAdminEmail: string}>
 */
final class TeamType extends AbstractType
{
    public function __construct(private readonly UrlGeneratorInterface $urls, private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'organization.field.team_name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('firstAdminEmail', TextType::class, [
                'label' => 'organization.field.first_admin_email',
                'help' => 'organization.field.first_admin_email_help',
                'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)],
                'autocomplete' => true,
                // Identity's route, by name: the UI may reference another context's routes.
                'autocomplete_url' => $this->urls->generate('identity_admin_account_search'),
                'min_characters' => 2,
                'preload' => false,
                // The bundle translates these in its own domain: they are translated here.
                'create_option_text' => $this->translator->trans('organization.field.first_admin_create'),
                'no_results_found_text' => $this->translator->trans('organization.field.first_admin_no_results'),
                'tom_select_options' => ['create' => true, 'createOnBlur' => true, 'maxItems' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'team_create']);
    }
}
