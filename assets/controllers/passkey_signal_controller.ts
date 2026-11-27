import { Controller } from '@hotwired/stimulus';

type SignalApi = {
    signalAllAcceptedCredentials?: (options: { rpId: string; userId: string; allAcceptedCredentialIds: string[] }) => Promise<void>;
};

/**
 * Account security page (passkey-management): tells the password manager which passkeys the account still accepts,
 * so a removed one disappears from the device. The page is reloaded after each change, so connect() is enough.
 * Signal API: limited availability, feature-detected, a failure only logged (Browser Support in CLAUDE.md).
 */
export default class extends Controller<HTMLElement> {
    static values = { rpId: String, userId: String, credentialIds: Array };

    declare readonly rpIdValue: string;
    declare readonly userIdValue: string;
    declare readonly credentialIdsValue: string[];

    connect(): void {
        const signal = (window.PublicKeyCredential as unknown as SignalApi | undefined)?.signalAllAcceptedCredentials;
        if (undefined === signal || '' === this.rpIdValue) {
            return;
        }

        signal
            .call(window.PublicKeyCredential, { rpId: this.rpIdValue, userId: this.userIdValue, allAcceptedCredentialIds: this.credentialIdsValue })
            .catch((error: unknown) => console.warn('signalAllAcceptedCredentials failed', error));
    }
}
