import { Controller } from '@hotwired/stimulus';

/**
 * A filter form submitted at each change (its button stays for the keyboard and for no script).
 */
export default class extends Controller<HTMLFormElement> {
    submit(): void {
        this.element.requestSubmit();
    }
}
