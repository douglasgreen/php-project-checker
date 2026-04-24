import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

const isCI = !!process.env.CI;

export default defineConfig({
    plugins: [vue()],

    test: {
        // Environment and globals
        environment: 'happy-dom',
        globals: false, // MUST: explicit imports

        // Discovery
        include: ['**/*.{test,spec}.{js,mjs,cjs,ts,mts,cts,jsx,tsx}'],
        exclude: [
            'node_modules/**',
            'dist/**',
            '.idea/**',
            '.git/**',
            '.cache/**',
            'playwright/**',
        ],

        // Lifecycle and setup
        setupFiles: ['./src/test/setup.ts'],
        globalSetup: ['./src/test/global-setup.ts'],

        // CI safety (MUST)
        allowOnly: !isCI,
        passWithNoTests: false,
        forbidOnly: isCI,

        // Mock hygiene (MUST)
        restoreMocks: true,
        mockReset: false,
        clearMocks: false,

        // Performance
        pool: 'threads',
        poolOptions: {
            threads: {
                minThreads: 1,
                maxThreads: isCI ? 2 : '50%',
            },
        },

        // Timeouts
        testTimeout: 10000,
        hookTimeout: 10000,
        teardownTimeout: 5000,

        // Reporters
        reporters: [
            'default',
            isCI ? ['junit', { outputFile: 'reports/junit.xml' }] : undefined,
            isCI ? ['github'] : undefined,
        ].filter(Boolean),

        // Coverage (MUST thresholds)
        coverage: {
            provider: 'v8',
            enabled: isCI,
            reporter: ['text', 'html', 'json-summary', 'lcov'],
            reportsDirectory: './coverage',
            clean: true,
            cleanOnRerun: true,

            thresholds: {
                lines: 80,
                functions: 80,
                statements: 80,
                branches: 75,
            },

            include: ['src/**/*.{ts,vue,js,tsx}'],
            exclude: [
                'node_modules/**',
                'dist/**',
                'src/test/**',
                '**/*.d.ts',
                '**/*.config.*',
                'src/main.ts',
                'src/index.ts',
            ],

            reportOnFailure: true,
        },

        // TypeScript
        typecheck: {
            enabled: true,
            checker: 'tsc',
        },
    },

    resolve: {
        alias: {
            '@': resolve(__dirname, './src'),
        },
    },

    // Build optimization
    build: {
        target: 'es2022',
    },
});
