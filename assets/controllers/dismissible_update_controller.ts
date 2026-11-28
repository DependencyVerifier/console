import { Controller } from '@hotwired/stimulus';

const KEY = 'depverify.console-update.dismissed';

/**
 * The update banner, hidden for the version it announces once dismissed. The choice stays in this browser only
 * (localStorage); the stored value is only compared, never written into the page. Without storage (private mode,
 * blocked site data) the banner simply stays visible.
 */
export default class extends Controller<HTMLElement> {
    static values = { version: String };

    declare readonly versionValue: string;

    connect(): void {
        if (this.#dismissed() === this.versionValue) {
            this.element.hidden = true;
        }
    }

    dismiss(): void {
        this.element.hidden = true;
        try {
            window.localStorage.setItem(KEY, this.versionValue);
        } catch {
            // Storage unavailable: hidden for this page only.
        }
    }

    #dismissed(): string | null {
        try {
            return window.localStorage.getItem(KEY);
        } catch {
            return null;
        }
    }
}
