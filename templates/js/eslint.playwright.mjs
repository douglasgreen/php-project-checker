import pluginPlaywright from 'eslint-plugin-playwright';

export default [
    {
        ...pluginPlaywright.configs['flat/recommended'],
        files: [
            'tests/**/*.ts',
            'tests/**/*.js',
            '**/*.spec.ts',
            '**/*.test.ts',
        ],
    },
];
