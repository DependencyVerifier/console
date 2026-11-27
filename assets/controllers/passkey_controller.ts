/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// A WebAuthn ceremony (docs/cadrage.md §7): asks the server for its options (POST, the CSRF token of the form in
// `X-CSRF-Token`), lets the browser create or use a passkey, then puts the answer (JSON, base64url fields, as
// PublicKeyCredential.toJSON() gives it) in the `response` target and submits the form. No library, nothing external.
//
// Progressive enhancement (passkey-authentication, passkey-registration; Browser Support in CLAUDE.md): the JSON
// helpers of WebAuthn (parse*OptionsFromJSON, toJSON) when the browser has them, the hand-written conversions below
// otherwise. `conditional` (sign-in page): where conditional mediation exists, passkeys are offered in the autofill of
// the email field (`autocomplete="username webauthn"`); the button aborts that request first, because the options
// kept in the session serve once.
//
// Errors land in an always-present `role="alert"` element, whose text changes (a live region that is shown rather
// than changed is not announced reliably). A cancellation says nothing; a passkey already on the device has its own
// message.

type Json = Record<string, unknown>;

/** WebAuthn statics that are newer than Baseline widely available: always feature-detected. */
type WebAuthnStatics = {
    getClientCapabilities?: () => Promise<Record<string, boolean | undefined>>;
    isConditionalMediationAvailable?: () => Promise<boolean>;
    parseCreationOptionsFromJSON?: (options: Json) => PublicKeyCredentialCreationOptions;
    parseRequestOptionsFromJSON?: (options: Json) => PublicKeyCredentialRequestOptions;
};

const statics = (): WebAuthnStatics => window.PublicKeyCredential as unknown as WebAuthnStatics;

const nameOf = (error: unknown): string => (error instanceof DOMException ? error.name : '');

const toBytes = (value: string): ArrayBuffer => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
};

const toBase64Url = (buffer: ArrayBuffer | null): string | null => {
    if (null === buffer) {
        return null;
    }
    let binary = '';
    for (const byte of new Uint8Array(buffer)) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const descriptors = (list: unknown): PublicKeyCredentialDescriptor[] =>
    (Array.isArray(list) ? (list as Json[]) : []).map((d) => ({
        type: 'public-key',
        id: toBytes(String(d.id)),
        ...(Array.isArray(d.transports) ? { transports: d.transports as AuthenticatorTransport[] } : {}),
    }));

export default class extends Controller<HTMLFormElement> {
    static targets = ['response', 'error'];

    static values = {
        options: String,
        csrf: String,
        mode: { type: String, default: 'get' },
        conditional: { type: Boolean, default: false },
        failedMessage: String,
        duplicateMessage: String,
    };

    declare readonly responseTarget: HTMLInputElement;
    declare readonly hasErrorTarget: boolean;
    declare readonly errorTarget: HTMLElement;
    declare optionsValue: string;
    declare csrfValue: string;
    declare modeValue: string;
    declare conditionalValue: boolean;
    declare failedMessageValue: string;
    declare duplicateMessageValue: string;

    #conditional: AbortController | null = null;

    connect(): void {
        if (!('PublicKeyCredential' in window)) {
            this.element.hidden = true;

            return;
        }
        if (this.conditionalValue) {
            void this.#offerInAutofill();
        }
    }

    disconnect(): void {
        this.#conditional?.abort();
        this.#conditional = null;
    }

    async start(event: Event): Promise<void> {
        // The second submit carries the answer: let it go.
        if ('' !== this.responseTarget.value) {
            return;
        }
        event.preventDefault();
        if (!this.element.reportValidity()) {
            return;
        }
        this.#conditional?.abort();
        this.#conditional = null;
        this.#say('');
        try {
            const options = await this.#options();
            const credential = 'create' === this.modeValue ? await this.create(options) : await this.get(options);
            this.#submit(credential);
        } catch (error) {
            this.responseTarget.value = '';
            const name = nameOf(error);
            if ('NotAllowedError' === name || 'AbortError' === name) {
                // Cancelled by the user (or timed out): nothing to report.
                return;
            }
            console.warn('Passkey ceremony failed', error);
            this.#say('create' === this.modeValue && 'InvalidStateError' === name ? this.duplicateMessageValue : this.failedMessageValue);
        }
    }

    /** Passkeys in the autofill of the email field, where conditional mediation exists. */
    async #offerInAutofill(): Promise<void> {
        const webauthn = statics();
        try {
            const available = undefined !== webauthn.getClientCapabilities
                ? true === (await webauthn.getClientCapabilities()).conditionalGet
                : true === (await webauthn.isConditionalMediationAvailable?.());
            if (!available || !this.element.isConnected) {
                return;
            }
            const conditional = new AbortController();
            this.#conditional = conditional;
            const options = await this.#options();
            if (conditional.signal.aborted) {
                return;
            }
            const credential = await this.get(options, conditional.signal);
            this.#submit(credential);
        } catch (error) {
            // Aborted by the button, or nothing chosen: the button stays the way in.
            if ('AbortError' !== nameOf(error)) {
                console.warn('Passkey autofill unavailable', error);
            }
        }
    }

    async #options(): Promise<Json> {
        const answer = await fetch(this.optionsValue, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' }, credentials: 'same-origin' });
        if (!answer.ok) {
            throw new Error(`options: ${answer.status}`);
        }

        return (await answer.json()) as Json;
    }

    #submit(credential: Json): void {
        this.responseTarget.value = JSON.stringify(credential);
        this.element.requestSubmit();
    }

    private async create(options: Json): Promise<Json> {
        const credential = (await navigator.credentials.create({ publicKey: this.#creationOptions(options) })) as PublicKeyCredential | null;
        if (null === credential) {
            throw new Error('no credential');
        }
        const native = this.#nativeJson(credential);
        if (null !== native) {
            return native;
        }
        const response = credential.response as AuthenticatorAttestationResponse;

        return {
            id: credential.id,
            rawId: toBase64Url(credential.rawId),
            type: credential.type,
            authenticatorAttachment: credential.authenticatorAttachment,
            clientExtensionResults: credential.getClientExtensionResults(),
            response: {
                clientDataJSON: toBase64Url(response.clientDataJSON),
                attestationObject: toBase64Url(response.attestationObject),
                transports: typeof response.getTransports === 'function' ? response.getTransports() : [],
            },
        };
    }

    private async get(options: Json, signal?: AbortSignal): Promise<Json> {
        const credential = (await navigator.credentials.get({
            publicKey: this.#requestOptions(options),
            ...(undefined !== signal ? { mediation: 'conditional' as CredentialMediationRequirement, signal } : {}),
        })) as PublicKeyCredential | null;
        if (null === credential) {
            throw new Error('no credential');
        }
        const native = this.#nativeJson(credential);
        if (null !== native) {
            return native;
        }
        const response = credential.response as AuthenticatorAssertionResponse;

        return {
            id: credential.id,
            rawId: toBase64Url(credential.rawId),
            type: credential.type,
            authenticatorAttachment: credential.authenticatorAttachment,
            clientExtensionResults: credential.getClientExtensionResults(),
            response: {
                clientDataJSON: toBase64Url(response.clientDataJSON),
                authenticatorData: toBase64Url(response.authenticatorData),
                signature: toBase64Url(response.signature),
                userHandle: toBase64Url(response.userHandle),
            },
        };
    }

    #creationOptions(options: Json): PublicKeyCredentialCreationOptions {
        const parse = statics().parseCreationOptionsFromJSON;
        if (undefined !== parse) {
            try {
                return parse.call(window.PublicKeyCredential, options);
            } catch (error) {
                console.warn('parseCreationOptionsFromJSON refused the options, converting by hand', error);
            }
        }
        const user = options.user as Json;

        return {
            ...(options as unknown as PublicKeyCredentialCreationOptions),
            challenge: toBytes(String(options.challenge)),
            user: { id: toBytes(String(user.id)), name: String(user.name), displayName: String(user.displayName) },
            excludeCredentials: descriptors(options.excludeCredentials),
        };
    }

    #requestOptions(options: Json): PublicKeyCredentialRequestOptions {
        const parse = statics().parseRequestOptionsFromJSON;
        if (undefined !== parse) {
            try {
                return parse.call(window.PublicKeyCredential, options);
            } catch (error) {
                console.warn('parseRequestOptionsFromJSON refused the options, converting by hand', error);
            }
        }

        return {
            ...(options as unknown as PublicKeyCredentialRequestOptions),
            challenge: toBytes(String(options.challenge)),
            allowCredentials: descriptors(options.allowCredentials),
        };
    }

    /** PublicKeyCredential.toJSON() where it exists. */
    #nativeJson(credential: PublicKeyCredential): Json | null {
        const toJson = (credential as unknown as { toJSON?: () => Json }).toJSON;

        return typeof toJson === 'function' ? toJson.call(credential) : null;
    }

    #say(message: string): void {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = message;
        }
    }
}
