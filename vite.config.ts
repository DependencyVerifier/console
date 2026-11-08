import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import Symfony from '@symfony/reprise/vite';

export default defineConfig(({ command }) => ({
    build: {
        rolldownOptions: {
            input: {
                app: './assets/app.ts',
            },
        },
    },
    server: {
        port: 5173,
        strictPort: true,
    },
    plugins: [
        tailwindcss(),
        Symfony({
            stimulus: './assets/controllers.json',
            // The dev server listens on 0.0.0.0 inside Docker: tell the browser where to reach it.
            devServerOrigin: process.env.VITE_DEV_SERVER_ORIGIN ?? 'http://localhost:5173',
            integrity: { enabled: command === 'build', algorithms: ['sha384'] },
        }),
    ],
}));
