// modified: 2026-02-26

import pluginVue from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';
import tseslint from 'typescript-eslint';

export default [
    ...pluginVue.configs['flat/recommended'],
    {
        files: ['**/*.vue'],
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: tseslint.parser, // TS parser inside .vue
                project: true,
                tsconfigRootDir: import.meta.dirname,
                extraFileExtensions: ['.vue'],
            },
        },
    },
];
