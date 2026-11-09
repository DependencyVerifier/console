<?php

declare(strict_types=1);

namespace Organization\UI\Form;

use Organization\Domain\Model\QuotaName;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The 9 quotas; an empty field is unlimited.
 *
 * @extends AbstractType<array<string, ?int>>
 */
final class QuotasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (QuotaName::cases() as $name) {
            $builder->add($name->value, IntegerType::class, [
                'label' => 'quota.'.$name->value,
                'required' => false,
                'constraints' => [new Assert\PositiveOrZero(), new Assert\LessThanOrEqual(1_000_000)],
            ]);
        }
    }
}
