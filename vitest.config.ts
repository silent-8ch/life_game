import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * Component tests. Separate from `vite.config.ts` on purpose: that one carries
 * the Laravel, Inertia, Tailwind and Wayfinder plugins, none of which mean
 * anything here, and Wayfinder in particular wants a running PHP to generate
 * routes from. This config is React and a DOM and nothing else.
 *
 * The rest of the project's TypeScript is tested by running it under node in a
 * subprocess, which works well and stays. It cannot render a component, which
 * is why this exists — `inspector.tsx` and the two canvas views have had no
 * coverage at all and no way to write any.
 *
 * The React Compiler babel plugin is left out. It is a build-time optimisation
 * and running tests through it would test the optimiser as much as the
 * component.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['tests/js/setup.ts'],
        include: ['tests/js/**/*.test.{ts,tsx}'],
        // The node-subprocess tests are Pest's business and are not Vitest
        // files; anything under tests/Unit or tests/Feature is PHP.
        exclude: ['node_modules', 'vendor', 'public', '.claude'],
    },
});
