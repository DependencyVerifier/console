<?php

declare(strict_types=1);

namespace Exposure\UI\Chart;

use Exposure\Application\Query\View\ExposureSummary;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * The charts of a dashboard (symfony/ux-chartjs). Colours are written `var(--…)`: chart_theme_controller.ts resolves
 * them from the theme before the chart is drawn. The figures are always written next to a chart.
 */
final readonly class ExposureCharts
{
    private const array SEVERITIES = ['critical' => 'var(--sev-critical)', 'high' => 'var(--sev-high)', 'medium' => 'var(--sev-medium)', 'low' => 'var(--sev-low)', 'unknown' => 'var(--muted-foreground)'];

    public function __construct(private ChartBuilderInterface $charts, private TranslatorInterface $translator)
    {
    }

    /**
     * Open vulnerabilities by severity; null when there is none.
     */
    public function severities(ExposureSummary $summary): ?Chart
    {
        if (0 === $summary->vulnerabilities()) {
            return null;
        }

        $chart = $this->charts->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => array_map(fn (string $severity): string => $this->translator->trans('exposure.severity.'.$severity), array_keys(self::SEVERITIES)),
            'datasets' => [[
                'data' => array_map(static fn (string $severity): int => $summary->severities[$severity] ?? 0, array_keys(self::SEVERITIES)),
                'backgroundColor' => array_values(self::SEVERITIES),
                'borderColor' => 'var(--card)',
            ]],
        ]);
        $chart->setOptions(['maintainAspectRatio' => false, 'cutout' => '65%', 'plugins' => ['legend' => ['position' => 'right']]]);

        return $chart;
    }

    /**
     * Findings opened and fixed each of the last 30 days.
     */
    public function trend(ExposureSummary $summary): Chart
    {
        $chart = $this->charts->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(static fn (array $day): string => new \DateTimeImmutable($day['day'])->format('d/m'), $summary->trend),
            'datasets' => [
                ['label' => $this->translator->trans('exposure.dashboard.opened'), 'data' => array_column($summary->trend, 'opened'), 'borderColor' => 'var(--sev-high)', 'backgroundColor' => 'var(--sev-high)', 'tension' => 0.3],
                ['label' => $this->translator->trans('exposure.dashboard.fixed'), 'data' => array_column($summary->trend, 'fixed'), 'borderColor' => 'var(--status-ok)', 'backgroundColor' => 'var(--status-ok)', 'tension' => 0.3, 'borderDash' => [6, 4], 'pointStyle' => 'rectRot'],
            ],
        ]);
        // Fixed is dashed with diamond points: the two lines never differ by their colour alone.
        $chart->setOptions(['maintainAspectRatio' => false, 'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]], 'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true]]]]);

        return $chart;
    }
}
