import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import prettier from 'eslint-config-prettier'

export default tseslint.config(
    {
        ignores: [
            'node_modules/**',
            'public/build/**',
            'vendor/**',
            // The upstream FluxCP checkout is reference material, not our code.
            'legacy/**',
            'storage/**',
            'bootstrap/cache/**',
        ],
    },

    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...pluginVue.configs['flat/recommended'],

    {
        files: ['**/*.{ts,vue}'],
        languageOptions: {
            parserOptions: {
                parser: tseslint.parser,
                ecmaVersion: 'latest',
                sourceType: 'module',
            },
            globals: {
                document: 'readonly',
                window: 'readonly',
                localStorage: 'readonly',
                fetch: 'readonly',
                URL: 'readonly',
                WebSocket: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
                AbortSignal: 'readonly',
                RegExp: 'readonly',
            },
        },
        rules: {
            /*
             * Component names in this project are multi-word by convention
             * (AppButton, StatusPill), but page components are named for their
             * route and the rule's value does not justify renaming them.
             */
            'vue/multi-word-component-names': 'off',

            /*
             * Misfires on TypeScript-typed optional props: `description?:
             * string` already says absence is meaningful, and inventing a
             * default would turn "not provided" into a value the template has
             * to special-case anyway.
             */
            'vue/require-default-prop': 'off',

            // Unused arguments prefixed with an underscore are deliberate.
            '@typescript-eslint/no-unused-vars': [
                'error',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],

            /*
             * Debug output left in a shipped bundle is a defect: it leaks
             * internals to anyone with a console open.
             */
            'no-console': 'error',
            'no-debugger': 'error',
        },
    },

    {
        /*
         * Build and verification scripts run in Node, not the browser, and
         * their console output is the whole point of them -- `no-console`
         * would be telling a CLI tool not to speak.
         */
        files: ['scripts/**/*.mjs', '*.config.{js,ts}'],
        languageOptions: {
            globals: {
                console: 'readonly',
                process: 'readonly',
                __dirname: 'readonly',
            },
        },
        rules: {
            'no-console': 'off',
        },
    },

    // Must come last so it can turn off rules that conflict with formatting.
    prettier,
)
