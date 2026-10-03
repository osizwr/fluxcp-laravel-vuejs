import { existsSync, readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

/**
 * Every installed theme's stylesheet becomes its own Vite entrypoint.
 *
 * Blade then links only the active theme's CSS, which is what lets APP_THEME
 * take effect on the next request rather than the next deploy: all installed
 * themes are built, and the server picks one per request.
 *
 * The consequence worth knowing is the other half of that trade: a theme added
 * to the directory after a build is not in the manifest, so adding a theme does
 * require `npm run build`. Switching between built themes does not.
 */
const THEME_ROOT = 'resources/themes'

function themeStylesheets(): string[] {
    const root = resolve(__dirname, THEME_ROOT)

    if (!existsSync(root)) {
        return []
    }

    return readdirSync(root, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => `${THEME_ROOT}/${entry.name}/styles/theme.css`)
        .filter((path) => existsSync(resolve(__dirname, path)))
}

export default defineConfig({
    resolve: {
        alias: {
            /*
             * Themes live outside resources/js, so without this a theme
             * component importing a core composable has to count directory
             * levels back up the tree. Matches the `@/*` path already declared
             * in tsconfig.json, so the editor and the bundler agree.
             */
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    plugins: [
        laravel({
            input: ['resources/js/app.ts', ...themeStylesheets()],
            refresh: [
                'resources/views/**',
                // So editing a theme's CSS or components reloads during `npm run dev`.
                `${THEME_ROOT}/**`,
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
})
