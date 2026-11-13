<?php

declare(strict_types=1);

namespace Notification\UI\Form;

use Notification\Application\Command\Rule\RuleSettings;
use Notification\Application\Query\View\NotificationsView;
use Notification\Application\Query\View\Option;
use Symfony\Component\Form\FormInterface;

/**
 * Between a rule form and RuleSettings: the options of the form (from the view of the scope), its initial data, and
 * the settings read from a submitted form (typed, never trusted beyond their type: the command checks them).
 */
final class RuleForm
{
    /**
     * @return array<string, mixed>
     */
    public static function options(NotificationsView $view, string $csrfTokenId): array
    {
        $choices = static function (array $options): array {
            $choices = [];
            foreach ($options as $option) {
                \assert($option instanceof Option);
                $choices[$option->label] = $option->id;
            }

            return $choices;
        };

        return [
            'csrf_token_id' => $csrfTokenId,
            'events' => $view->events,
            'webhooks' => $choices($view->webhooks),
            'environments' => $choices($view->environments),
            'project' => null !== $view->projectId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(RuleSettings $settings): array
    {
        return [
            'name' => $settings->name,
            'events' => $settings->events,
            'environment' => $settings->environmentId,
            'min_severity' => $settings->minSeverity,
            'min_cvss' => $settings->minCvss,
            'min_epss' => $settings->minEpss,
            'known_exploited_only' => $settings->knownExploitedOnly,
            'silent_hours' => $settings->silentHours,
            'webhooks' => $settings->webhookIds,
            'email_subscribers' => $settings->emailSubscribers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return ['email_subscribers' => true, 'silent_hours' => 24];
    }

    /**
     * @param FormInterface<array<string, mixed>> $form
     */
    public static function settings(FormInterface $form): RuleSettings
    {
        $value = static fn (string $field): mixed => $form->has($field) ? $form->get($field)->getData() : null;
        $strings = static fn (string $field): array => array_values(array_filter((array) $value($field), \is_string(...)));
        $float = static fn (string $field): ?float => is_numeric($value($field)) ? (float) $value($field) : null;
        $string = static fn (string $field): ?string => \is_string($value($field)) && '' !== $value($field) ? $value($field) : null;
        $silent = $value('silent_hours');

        return new RuleSettings(
            $string('name') ?? '',
            $strings('events'),
            $string('environment'),
            $string('min_severity'),
            $float('min_cvss'),
            true === $value('known_exploited_only'),
            $float('min_epss'),
            \is_int($silent) ? $silent : 24,
            $strings('webhooks'),
            true === $value('email_subscribers'),
        );
    }
}
