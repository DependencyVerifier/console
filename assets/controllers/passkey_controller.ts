/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// A WebAuthn ceremony (docs/cadrage.md §7): asks the server for its options (POST, the CSRF token of the form in
// `X-CSRF-Token`), lets the browser create or use a passkey, then puts the answer (JSON, base64url fields, as
// PublicKeyCredential.toJSON() gives it) in the `response` target and submits the form. No library, nothing external.

type Json = Record<string, unknown>;

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
    };

    declare readonly responseTarget: HTMLInputElement;
    declare readonly hasErrorTarget: boolean;
    declare readonly errorTarget: HTMLElement;
    declare optionsValue: string;
    declare csrfValue: string;
    declare modeValue: string;

    connect(): void {
        if (!('PublicKeyCredential' in window)) {
            this.element.hidden = true;
        }
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
        this.showError(false);
        try {
            const answer = await fetch(this.optionsValue, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' }, credentials: 'same-origin' });
            if (!answer.ok) {
                throw new Error(`options: ${answer.status}`);
            }
            const options = (await answer.json()) as Json;
            const credential = 'create' === this.modeValue ? await this.create(options) : await this.get(options);
            this.responseTarget.value = JSON.stringify(credential);
            this.element.requestSubmit();
        } catch (error) {
            console.warn('Passkey ceremony failed', error);
            this.responseTarget.value = '';
            this.showError(true);
        }
    }

    private async create(options: Json): Promise<Json> {
        const user = options.user as Json;
        const credential = (await navigator.credentials.create({
            publicKey: {
                ...(options as unknown as PublicKeyCredentialCreationOptions),
                challenge: toBytes(String(options.challenge)),
                user: { id: toBytes(String(user.id)), name: String(user.name), displayName: String(user.displayName) },
                excludeCredentials: descriptors(options.excludeCredentials),
            },
        })) as PublicKeyCredential | null;
        if (null === credential) {
            throw new Error('no credential');
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

    private async get(options: Json): Promise<Json> {
        const credential = (await navigator.credentials.get({
            publicKey: {
                ...(options as unknown as PublicKeyCredentialRequestOptions),
                challenge: toBytes(String(options.challenge)),
                allowCredentials: descriptors(options.allowCredentials),
            },
        })) as PublicKeyCredential | null;
        if (null === credential) {
            throw new Error('no credential');
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

    private showError(visible: boolean): void {
        if (this.hasErrorTarget) {
            this.errorTarget.hidden = !visible;
        }
    }
}
