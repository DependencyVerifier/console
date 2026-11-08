import { Controller } from '@hotwired/stimulus';

/*
 * Light / dark / system theme switcher.
 * The preference is stored in localStorage ("system" when absent); an inline script in
 * base.html.twig applies it before first paint to avoid a flash.
 */
export default class extends Controller {
    static values = { storageKey: { type: String, default: 'depradar-theme' } };

    connect() {
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.onSystemChange = () => this.apply();
        this.media.addEventListener('change', this.onSystemChange);
        this.apply();
    }

    disconnect() {
        this.media.removeEventListener('change', this.onSystemChange);
    }

    cycle() {
        const order = ['system', 'light', 'dark'];
        const next = order[(order.indexOf(this.preference) + 1) % order.length];
        this.preference = next;
        this.apply();
    }

    apply() {
        const pref = this.preference;
        const dark = pref === 'dark' || (pref === 'system' && this.media.matches);
        document.documentElement.classList.toggle('dark', dark);
        this.element.dataset.themePreference = pref;
        this.element.setAttribute('title', `Theme: ${pref}`);
    }

    get preference() {
        try {
            return localStorage.getItem(this.storageKeyValue) ?? 'system';
        } catch {
            return 'system';
        }
    }

    set preference(value) {
        try {
            value === 'system'
                ? localStorage.removeItem(this.storageKeyValue)
                : localStorage.setItem(this.storageKeyValue, value);
        } catch {
            // Storage unavailable: the choice only lasts for this page.
        }
    }
}
