import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/**
 * States of a lazy <turbo-frame> (decision 31), on Turbo 8 events:
 * - turbo:frame-missing: the response has no matching frame. A redirect or a 200 means the session expired or the
 *   frame route sent us to a full page: clear the page cache and visit it in full, same origin only (else reload).
 *   A 5xx shows the server-rendered error template with "Retry".
 * - turbo:fetch-request-error: network failure, same error state, so the placeholder never stays forever.
 */
export default class extends Controller<HTMLElement & { src: string | null; reload(): Promise<void> }> {
    static targets = ['error'];

    declare readonly hasErrorTarget: boolean;
    declare readonly errorTarget: HTMLTemplateElement;

    /** Kept on connect: the template is replaced together with the placeholder once the frame loads. */
    #error: DocumentFragment | null = null;

    connect(): void {
        if (this.hasErrorTarget) {
            this.#error = this.errorTarget.content;
        }
    }

    missing(event: CustomEvent<{ response: Response }>): void {
        event.preventDefault();
        const { response } = event.detail;

        if (response.redirected || response.ok) {
            const url = new URL(response.url, window.location.href);
            Turbo.cache.clear();
            if (url.origin === window.location.origin) {
                Turbo.visit(url.href, { action: 'replace' });
            } else {
                window.location.reload();
            }

            return;
        }

        this.showError();
    }

    failed(): void {
        this.showError();
    }

    retry(): void {
        void this.element.reload();
    }

    private showError(): void {
        if (null !== this.#error) {
            this.element.replaceChildren(this.#error.cloneNode(true));
        }
    }
}
