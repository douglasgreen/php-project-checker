// modified: 2026-02-26

import pluginPlaywright from 'eslint-plugin-playwright';

export default [
    {
        files:[
            'tests/**/*.ts',
            'tests/**/*.js',
            '**/*.spec.ts',
            '**/*.test.ts',
        ],
        ...pluginPlaywright.configs['flat/recommended'],
    },
];
