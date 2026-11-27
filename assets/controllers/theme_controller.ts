import { Controller } from '@hotwired/stimulus';

type ThemePreference = 'system' | 'light' | 'dark';

const ORDER: readonly ThemePreference[] = ['system', 'light', 'dark'];

/*
 * Light / dark / system theme switcher.
 * The preference is stored in localStorage ("system" when absent); an inline script in
 * base.html.twig applies it before first paint to avoid a flash. The `.dark` class also sets `color-scheme`
 * (app.css), so scrollbars and native controls follow.
 *
 * Every change (button, system preference, another tab through `storage`) dispatches `theme:changed` on the
 * document: charts resolve their colours again (chart_theme_controller.ts).
 */
export default class extends Controller<HTMLElement> {
    static values = { storageKey: { type: String, default: 'depverify-theme' } };

    declare readonly storageKeyValue: string;

    #media: MediaQueryList | null = null;

    readonly #onSystemChange = (): void => {
        this.apply();
    };

    readonly #onStorage = (event: StorageEvent): void => {
        if (null === event.key || this.storageKeyValue === event.key) {
            this.apply();
        }
    };

    connect(): void {
        this.#media = window.matchMedia('(prefers-color-scheme: dark)');
        this.#media.addEventListener('change', this.#onSystemChange);
        window.addEventListener('storage', this.#onStorage);
        this.apply();
    }

    disconnect(): void {
        this.#media?.removeEventListener('change', this.#onSystemChange);
        window.removeEventListener('storage', this.#onStorage);
    }

    cycle(): void {
        const next = ORDER[(ORDER.indexOf(this.#preference) + 1) % ORDER.length] ?? 'system';
        this.#preference = next;
        this.apply();
    }

    apply(): void {
        const preference = this.#preference;
        const dark = 'dark' === preference || ('system' === preference && (this.#media?.matches ?? false));
        const root = document.documentElement;
        const changed = root.classList.contains('dark') !== dark;
        root.classList.toggle('dark', dark);
        this.element.dataset.themePreference = preference;
        if (changed) {
            document.dispatchEvent(new CustomEvent('theme:changed', { detail: { dark } }));
        }
    }

    get #preference(): ThemePreference {
        try {
            const stored = localStorage.getItem(this.storageKeyValue);

            return 'light' === stored || 'dark' === stored ? stored : 'system';
        } catch {
            return 'system';
        }
    }

    set #preference(value: ThemePreference) {
        try {
            if ('system' === value) {
                localStorage.removeItem(this.storageKeyValue);
            } else {
                localStorage.setItem(this.storageKeyValue, value);
            }
        } catch {
            // Storage unavailable: the choice only lasts for this page.
        }
    }
}
