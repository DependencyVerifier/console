/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// A destructive action asks before it leaves (charter: Delete, revoke): the browser's own confirmation, accessible and
// keyboard-ready. Refused, the submit is cancelled before Turbo sees it.
export default class extends Controller<HTMLFormElement> {
    static values = { message: String };

    declare readonly messageValue: string;

    ask(event: Event): void {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }
}
