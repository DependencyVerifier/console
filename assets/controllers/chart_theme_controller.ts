import { Controller } from '@hotwired/stimulus';

type ChartPayload = { data?: unknown; options?: Record<string, unknown> };

/**
 * Colours of the Chart.js charts (symfony/ux-chartjs) from the theme: the server writes `var(--sev-critical)` in the
 * chart data, this controller resolves every such value to the computed one before the chart is built
 * (`chartjs:pre-connect`), and gives the axes and the labels the muted foreground and border colours. Nothing goes
 * through a `style` attribute (strict CSP). The numbers are always written next to the chart: it only illustrates.
 */
export default class extends Controller<HTMLElement> {
    theme(event: CustomEvent<{ config: ChartPayload }>): void {
        const styles = getComputedStyle(this.element);
        const resolve = (value: unknown): unknown => {
            if (typeof value === 'string') {
                const match = /^var\((--[a-z0-9-]+)\)$/.exec(value.trim());

                const name = match?.[1];

                return name ? styles.getPropertyValue(name).trim() || value : value;
            }
            if (Array.isArray(value)) {
                return value.map(resolve);
            }
            if (value !== null && typeof value === 'object') {
                return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, resolve(item)]));
            }

            return value;
        };

        const config = event.detail.config;
        config.data = resolve(config.data);
        const text = styles.getPropertyValue('--muted-foreground').trim();
        const grid = styles.getPropertyValue('--border').trim();
        const options = (resolve(config.options ?? {}) ?? {}) as Record<string, unknown>;
        config.options = {
            ...options,
            color: text,
            borderColor: grid,
            scales: Object.fromEntries(Object.entries((options.scales ?? {}) as Record<string, Record<string, unknown>>).map(([axis, scale]) => [
                axis,
                { ...scale, ticks: { color: text, ...(scale.ticks as object) }, grid: { color: grid, ...(scale.grid as object) } },
            ])),
        };
    }
}
