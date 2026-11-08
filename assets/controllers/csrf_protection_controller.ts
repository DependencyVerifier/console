// From the symfony/stimulus-bundle recipe, converted to TypeScript.
// Not a real Stimulus controller: it double-submits a CSRF token (field + cookie), as defined by Symfony's
// SameOriginCsrfTokenManager. Use `form.requestSubmit()` so the submit event (and this listener) fires;
// `form.submit()` would skip it.

const nameCheck = /^[-_a-zA-Z0-9]{4,22}$/;
const tokenCheck = /^[-_/+a-zA-Z0-9]{24,}$/;

/** The part of Turbo's form submission events used here. */
interface TurboSubmitEvent extends Event {
    detail: {
        formSubmission: {
            formElement: HTMLFormElement;
            fetchRequest: { headers: Record<string, string> };
        };
    };
}

document.addEventListener(
    'submit',
    (event) => {
        if (event.target instanceof HTMLFormElement) {
            generateCsrfToken(event.target);
        }
    },
    true,
);

// When @hotwired/turbo handles form submissions, send the CSRF token in a header in addition to a cookie.
// The `framework.csrf_protection.check_header` config option needs to be enabled for the header to be checked.
document.addEventListener('turbo:submit-start', (event) => {
    const { formSubmission } = (event as TurboSubmitEvent).detail;
    Object.assign(formSubmission.fetchRequest.headers, generateCsrfHeaders(formSubmission.formElement));
});

// When @hotwired/turbo handles form submissions, remove the CSRF cookie once a form has been submitted.
document.addEventListener('turbo:submit-end', (event) => {
    removeCsrfToken((event as TurboSubmitEvent).detail.formSubmission.formElement);
});

export function generateCsrfToken(formElement: HTMLFormElement): void {
    const csrfField = getCsrfField(formElement);
    if (null === csrfField) {
        return;
    }

    let csrfCookie = csrfField.getAttribute('data-csrf-protection-cookie-value');
    let csrfToken = csrfField.value;

    if (null === csrfCookie && nameCheck.test(csrfToken)) {
        csrfCookie = csrfToken;
        csrfField.setAttribute('data-csrf-protection-cookie-value', csrfCookie);
        csrfToken = btoa(String.fromCharCode(...window.crypto.getRandomValues(new Uint8Array(18))));
        csrfField.defaultValue = csrfToken;
    }
    csrfField.dispatchEvent(new Event('change', { bubbles: true }));

    if (null !== csrfCookie && tokenCheck.test(csrfToken)) {
        const cookie = `${csrfCookie}_${csrfToken}=${csrfCookie}; path=/; samesite=strict`;
        document.cookie = 'https:' === window.location.protocol ? `__Host-${cookie}; secure` : cookie;
    }
}

export function generateCsrfHeaders(formElement: HTMLFormElement): Record<string, string> {
    const headers: Record<string, string> = {};
    const csrfField = getCsrfField(formElement);
    if (null === csrfField) {
        return headers;
    }

    const csrfCookie = csrfField.getAttribute('data-csrf-protection-cookie-value');
    if (null !== csrfCookie && tokenCheck.test(csrfField.value) && nameCheck.test(csrfCookie)) {
        headers[csrfCookie] = csrfField.value;
    }

    return headers;
}

export function removeCsrfToken(formElement: HTMLFormElement): void {
    const csrfField = getCsrfField(formElement);
    if (null === csrfField) {
        return;
    }

    const csrfCookie = csrfField.getAttribute('data-csrf-protection-cookie-value');
    if (null !== csrfCookie && tokenCheck.test(csrfField.value) && nameCheck.test(csrfCookie)) {
        const cookie = `${csrfCookie}_${csrfField.value}=0; path=/; samesite=strict; max-age=0`;
        document.cookie = 'https:' === window.location.protocol ? `__Host-${cookie}; secure` : cookie;
    }
}

function getCsrfField(formElement: HTMLFormElement): HTMLInputElement | null {
    const field = Array.from(formElement.elements).find(
        (element) => element instanceof HTMLInputElement && element.matches('input[data-controller="csrf-protection"], input[name="_csrf_token"]'),
    );

    return field instanceof HTMLInputElement ? field : null;
}

/* stimulusFetch: 'lazy' */
export default 'csrf-protection-controller';
