import { Controller } from '@hotwired/stimulus';

type ThemePreference = 'system' | 'light' | 'dark';

const ORDER: readonly ThemePreference[] = ['system', 'light', 'dark'];

/*
 * Light / dark / system theme switcher.
 * The preference is stored in localStorage ("system" when absent); an inline script in
 * base.html.twig applies it before first paint to avoid a flash.
 */
export default class extends Controller<HTMLElement> {
    static values = { storageKey: { type: String, default: 'depverify-theme' } };

    declare readonly storageKeyValue: string;

    #media: MediaQueryList | null = null;

    readonly #onSystemChange = (): void => {
        this.apply();
    };

    connect(): void {
        this.#media = window.matchMedia('(prefers-color-scheme: dark)');
        this.#media.addEventListener('change', this.#onSystemChange);
        this.apply();
    }

    disconnect(): void {
        this.#media?.removeEventListener('change', this.#onSystemChange);
    }

    cycle(): void {
        const next = ORDER[(ORDER.indexOf(this.#preference) + 1) % ORDER.length] ?? 'system';
        this.#preference = next;
        this.apply();
    }

    apply(): void {
        const preference = this.#preference;
        const dark = 'dark' === preference || ('system' === preference && (this.#media?.matches ?? false));
        document.documentElement.classList.toggle('dark', dark);
        this.element.dataset.themePreference = preference;
        this.element.setAttribute('title', `Theme: ${preference}`);
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
