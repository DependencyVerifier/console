// @hotwired/turbo ships no type declarations: the subset used by the controllers.
declare module '@hotwired/turbo' {
    export const cache: { clear(): void };
    export function visit(location: string, options?: { action?: 'advance' | 'replace' }): void;
}
