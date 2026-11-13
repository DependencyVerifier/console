<?php

declare(strict_types=1);

namespace Inventory\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

use function Symfony\Component\String\u;

/**
 * The documentation links of an application, one per line: the URL, then an optional label after a space. The domain
 * checks them again (DocumentationLink).
 *
 * @extends AbstractType<array{links: ?string}>
 */
final class ApplicationLinksType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('links', TextareaType::class, [
            'label' => 'inventory.applications.links',
            'required' => false,
            'help' => 'inventory.applications.links_help',
            'attr' => ['rows' => 5, 'class' => 'font-mono text-xs'],
            'constraints' => [new Assert\Length(max: 25000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['csrf_token_id']);
    }

    /**
     * @param list<array{url: string, label: ?string}> $links
     */
    public static function text(array $links): string
    {
        return u("\n")->join(array_map(static fn (array $l): string => u($l['url'])->append(null === $l['label'] ? '' : ' '.$l['label'])->toString(), $links))->toString();
    }

    /**
     * @return list<array{url: string, label: ?string}>
     */
    public static function links(?string $text): array
    {
        $links = [];
        foreach (u($text ?? '')->split("\n") as $line) {
            $parts = $line->trim()->split(' ', 2);
            $url = $parts[0]->toString();
            if ('' !== $url) {
                $links[] = ['url' => $url, 'label' => isset($parts[1]) ? $parts[1]->trim()->toString() : null];
            }
        }

        return $links;
    }
}
