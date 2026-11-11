<?php

declare(strict_types=1);

namespace Fleet\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

use function Symfony\Component\String\u;

/**
 * Collection settings of an agent (SET_CONFIG): the interval, and absolute paths one per line. The domain checks the
 * paths again (AgentConfig), the handler the team's minimum interval.
 *
 * @extends AbstractType<array{scan_interval_minutes: int, discovery_roots: ?string, declared_paths: ?string, exclusions: ?string}>
 */
final class AgentConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('scan_interval_minutes', IntegerType::class, [
            'label' => 'fleet.field.scan_interval',
            'data' => $options['interval'],
            'help' => 'fleet.configure.interval_help',
            'constraints' => [new Assert\NotNull(), new Assert\Range(min: 5, max: 10080)],
        ]);
        foreach (['discovery_roots', 'declared_paths', 'exclusions'] as $field) {
            $builder->add($field, TextareaType::class, [
                'label' => 'fleet.field.'.$field,
                'required' => false,
                'data' => u("\n")->join(self::pathsOf($options['paths'], $field))->toString(),
                'help' => 'fleet.configure.'.$field.'_help',
                'attr' => ['rows' => 3, 'class' => 'font-mono text-xs'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id', 'interval', 'paths']);
        $resolver->setAllowedTypes('interval', 'int');
        $resolver->setAllowedTypes('paths', 'array');
    }

    /**
     * The paths of a field of the current SET_CONFIG payload (strings only).
     *
     * @return list<string>
     */
    private static function pathsOf(mixed $payload, string $field): array
    {
        $paths = \is_array($payload) ? ($payload[$field] ?? []) : [];

        return \is_array($paths) ? array_values(array_filter($paths, \is_string(...))) : [];
    }

    /**
     * One path per line, blank lines ignored.
     *
     * @return list<string>
     */
    public static function lines(?string $text): array
    {
        $lines = [];
        foreach (u($text ?? '')->split("\n") as $line) {
            $line = $line->trim()->toString();
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
