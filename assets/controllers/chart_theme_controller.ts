import { Controller } from '@hotwired/stimulus';
import type { Chart } from 'chart.js';

type ChartPayload = { data?: unknown; options?: Record<string, unknown> };

/**
 * Colours of the Chart.js charts (symfony/ux-chartjs) from the theme: the server writes `var(--sev-critical)` in the
 * chart data, this controller resolves every such value to the computed one before the chart is built
 * (`chartjs:pre-connect`), and gives the axes and the labels the muted foreground and border colours. Nothing goes
 * through a `style` attribute (strict CSP). The numbers are always written next to the chart: it only illustrates.
 *
 * The raw configuration (still written `var(--…)`) is kept per canvas: on `theme:changed` (theme_controller.ts) each
 * chart is resolved again and redrawn without animation, so a theme switch never leaves the colours of the other one.
 */
export default class extends Controller<HTMLElement> {
    readonly #raw = new WeakMap<HTMLCanvasElement, ChartPayload>();
    readonly #charts = new Set<Chart>();

    theme(event: CustomEvent<{ config: ChartPayload }>): void {
        const config = event.detail.config;
        if (event.target instanceof HTMLCanvasElement) {
            this.#raw.set(event.target, structuredClone({ data: config.data, options: config.options }));
        }
        Object.assign(config, this.#resolved(config));
    }

    register(event: CustomEvent<{ chart: Chart }>): void {
        this.#charts.add(event.detail.chart);
    }

    unregister(event: CustomEvent<{ chart: Chart }>): void {
        this.#charts.delete(event.detail.chart);
    }

    refresh(): void {
        for (const chart of this.#charts) {
            const raw = this.#raw.get(chart.canvas);
            if (undefined === raw) {
                continue;
            }
            const resolved = this.#resolved(structuredClone(raw));
            chart.data = resolved.data as Chart['data'];
            chart.options = resolved.options as Chart['options'];
            chart.update('none');
        }
    }

    disconnect(): void {
        this.#charts.clear();
    }

    #resolved(config: ChartPayload): Required<ChartPayload> {
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

        const text = styles.getPropertyValue('--muted-foreground').trim();
        const grid = styles.getPropertyValue('--border').trim();
        const options = (resolve(config.options ?? {}) ?? {}) as Record<string, unknown>;

        return {
            data: resolve(config.data),
            options: {
                ...options,
                color: text,
                borderColor: grid,
                scales: Object.fromEntries(Object.entries((options.scales ?? {}) as Record<string, Record<string, unknown>>).map(([axis, scale]) => [
                    axis,
                    { ...scale, ticks: { color: text, ...(scale.ticks as object) }, grid: { color: grid, ...(scale.grid as object) } },
                ])),
            },
        };
    }
}
