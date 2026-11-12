<?php

declare(strict_types=1);

namespace Exposure\UI\Form;

use Exposure\Domain\Model\RiskAcceptance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{justification: string, expiresOn: \DateTimeImmutable}>
 */
final class AcceptRiskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('justification', TextareaType::class, [
                'label' => 'exposure.risk.justification',
                'help' => 'exposure.risk.justification_help',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: RiskAcceptance::MAX_JUSTIFICATION)],
                'attr' => ['rows' => 4, 'maxlength' => RiskAcceptance::MAX_JUSTIFICATION],
            ])
            ->add('expiresOn', DateType::class, [
                'label' => 'exposure.risk.expires_on',
                'help' => 'exposure.risk.expires_on_help',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                // Said in the form at once; the domain judges again (RiskAcceptance::accept).
                'constraints' => [new Assert\NotBlank(), new Assert\GreaterThan('today'), new Assert\LessThanOrEqual(\sprintf('+%d days', RiskAcceptance::MAX_DAYS))],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id']);
    }
}
