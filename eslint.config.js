import stylistic from '@stylistic/eslint-plugin';
import {
    defineConfigWithVueTs,
    vueTsConfigs,
} from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: [
                        'builtin',
                        'external',
                        'internal',
                        'parent',
                        'sibling',
                        'index',
                    ],
                    alphabetize: { order: 'asc', caseInsensitive: true },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            /*
             * Tooling's own directory in a working copy — settings, and the
             * scratch checkouts agents make under `worktrees/`. None of it is
             * repository source.
             *
             * Ignored because `vendor` above is anchored at the repo root, so
             * a nested checkout's own vendor directory is not covered by it,
             * and linting one dies on a Vue stub inside a Composer package
             * that no tsconfig knows about. A clean clone has nothing here and
             * the entry costs nothing.
             */
            '.claude/**',
            'public',
            'bootstrap/ssr',
            'tailwind.config.js',
            'vite.config.ts',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    prettier,
    /*
     * The service worker is checked against its own tsconfig (#102).
     *
     * `tsconfig.json` excludes `resources/js/sw.ts` because a worker needs
     * `lib: WebWorker`, whose `self` is a `ServiceWorkerGlobalScope` rather
     * than a `Window` — the two cannot share one program. Without this block
     * the type-aware rules cannot find the file at all and eslint fails with
     * a parsing error rather than linting it, which would leave the one file
     * in the codebase running outside the browser's page context as the one
     * file nobody checks.
     */
    {
        files: ['resources/js/sw.ts'],
        languageOptions: {
            parserOptions: {
                projectService: false,
                project: './tsconfig.sw.json',
            },
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
        },
    },
);
