// modified: 2026-02-26

import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import pluginVue from 'eslint-plugin-vue';
import pluginPlaywright from 'eslint-plugin-playwright';
import pluginSecurity from 'eslint-plugin-security';
import pluginUnicorn from 'eslint-plugin-unicorn';
import pluginJsxA11y from 'eslint-plugin-jsx-a11y';
import globals from 'globals';
import vueParser from 'vue-eslint-parser';
import eslintConfigPrettier from 'eslint-config-prettier';
import pluginYaml from 'eslint-plugin-yml';
import * as yamlParser from 'yaml-eslint-parser';

// ------------------------------------------------------------------
// Global ignore patterns (replaces .eslintignore)
// ------------------------------------------------------------------
const ignorePatterns = [
    // Build / tooling directories
    'dist/**',
    'node_modules/**',
    'coverage/**',
    '*.config.*',
    'playwright-report/**',
    'test-results/**',
    'build/**',
    '.cache/**',
    '.next/**',
    // Composer / PHP
    'composer.lock',
    'vendor/**',
    // ESLint cache
    '.eslintcache',
    // Grunt
    '.grunt/**',
    // Husky
    '.husky/_/**',
    // Minified assets
    '*.min.*',
    // Node REPL history & npm stuff
    '.node_repl_history',
    '.npm/**',
    'npm-debug.log*',
    'package-lock.json',
    // PHPUnit
    '.phpunit.result.cache',
    // Python
    '*.pyc',
    '__pycache__/**',
    '*.pyo',
    // Symfony
    '.env.local.php',
    'parameters.yml',
    'var/**',
];

// ------------------------------------------------------------------
// Export the flat config
// ------------------------------------------------------------------
export default tseslint.config(
    // ----------------------------------------------------------------
    // 1️⃣ Base JavaScript
    // ----------------------------------------------------------------
    js.configs.recommended,

    // ----------------------------------------------------------------
    // 2️⃣ TypeScript & Vue
    // ----------------------------------------------------------------
    ...tseslint.configs.recommendedTypeChecked,
    ...tseslint.configs.strictTypeChecked,
    ...pluginVue.configs['flat/recommended'],

    // ----------------------------------------------------------------
    // 3️⃣ Global language options & parsers
    // ----------------------------------------------------------------
    {
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.node,
                ...globals.es2023,
            },
            parser: tseslint.parser,
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
            parser: vueParser, // Vue files need vue-eslint-parser
            parserOptions: {
                parser: tseslint.parser, // TS parser inside .vue
                project: true,
                tsconfigRootDir: import.meta.dirname,
                extraFileExtensions: ['.vue'],
            },
        },
    },

    // ----------------------------------------------------------------
    // 4️⃣ YAML files
    // ----------------------------------------------------------------
    ...pluginYaml.configs.recommended,
    {
        files: ['**/*.{yml,yaml}'],
        languageOptions: {
            parser: yamlParser,
        },
        rules: {
            'yml/quotes': ['error', { prefer: 'single', avoidEscape: true }],
            'yml/no-empty-document': 'error',
            'yml/indent': ['error', 2],
            'yml/block-mapping-question-indicator-newline': 'error',
        },
    },

    // ----------------------------------------------------------------
    // 5️⃣ Security baseline
    // ----------------------------------------------------------------
    {
        plugins: { security: pluginSecurity },
        rules: {
            ...pluginSecurity.configs.recommended.rules,
            'security/detect-object-injection': 'off', // often noisy
        },
    },

    // ----------------------------------------------------------------
    // 6️⃣ Code‑quality (unicorn)
    // ----------------------------------------------------------------
    {
        plugins: { unicorn: pluginUnicorn },
        rules: {
            'unicorn/consistent-function-scoping': 'off',
            'unicorn/no-abusive-eslint-disable': 'error',
        },
    },

    // ----------------------------------------------------------------
    // 7️⃣ Test‑file overrides (Playwright)
    // ----------------------------------------------------------------
    {
        files: [
            'tests/**/*.ts',
            'tests/**/*.js',
            '**/*.spec.ts',
            '**/*.test.ts',
        ],
        ...pluginPlaywright.configs['flat/recommended'],
    },

    // ----------------------------------------------------------------
    // 8️⃣ Accessibility for Vue/JSX
    // ----------------------------------------------------------------
    {
        files: ['**/*.{vue,jsx,tsx}'],
        plugins: { 'jsx-a11y': pluginJsxA11y },
        rules: {
            ...pluginJsxA11y.configs.recommended.rules,
        },
    },

    // ----------------------------------------------------------------
    // 9️⃣ Custom TypeScript / general rules
    // ----------------------------------------------------------------
    {
        rules: {
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/consistent-type-imports': 'error',
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            '@typescript-eslint/restrict-template-expressions': [
                'error',
                {
                    allowNumber: true,
                    allowBoolean: true,
                    allowAny: false,
                    allowNullish: false,
                },
            ],
        },
    },

    // ----------------------------------------------------------------
    // 🔟 Global ignore patterns (replaces .eslintignore)
    // ----------------------------------------------------------------
    {
        ignores: ignorePatterns,
    },

    // ----------------------------------------------------------------
    // 1️⃣1️⃣ Prettier – must be last to override conflicting rules
    // ----------------------------------------------------------------
    eslintConfigPrettier,
);
