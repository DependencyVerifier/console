<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Symfony\Component\Form\FormInterface;

/**
 * Typed read of a submitted and valid form field, for the command closures of ActionResponder.
 */
final class FormData
{
    /**
     * @param FormInterface<covariant mixed> $form
     */
    public static function string(FormInterface $form, string $field): string
    {
        $value = $form->get($field)->getData();
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Field "%s" is not a string.', $field));
        }

        return $value;
    }

    /**
     * A compound field of optional integers (an empty field is null), keyed by child name.
     *
     * @param FormInterface<covariant mixed> $form
     *
     * @return array<string, ?int>
     */
    public static function nullableInts(FormInterface $form, string $field): array
    {
        $values = [];
        foreach ($form->get($field) as $name => $child) {
            $value = $child->getData();
            if (null !== $value && !\is_int($value)) {
                throw new \UnexpectedValueException(\sprintf('Field "%s.%s" is not an integer.', $field, $name));
            }

            $values[(string) $name] = $value;
        }

        return $values;
    }
}
