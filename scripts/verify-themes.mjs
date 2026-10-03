#!/usr/bin/env node
/**
 * Checks that what the themes put on disk is what the build actually exposes.
 *
 * Two things can go wrong quietly, and this catches both:
 *
 *   1. A theme's stylesheet is not a Vite entrypoint, so Blade links nothing
 *      and the skin simply does not apply.
 *   2. A theme's override is not in the resolver's glob, so the application
 *      falls back to the core component. Nothing errors; the override is just
 *      ignored, which is the hardest kind of bug to notice.
 *
 * Run after `npm run build`:  npm run verify:themes
 */

import { readdirSync, readFileSync, existsSync } from 'node:fs'
import { join, resolve } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const themeRoot = join(root, 'resources/themes')
const manifestPath = join(root, 'public/build/manifest.json')

const problems = []
const notes = []

if (!existsSync(manifestPath)) {
    console.error('No build manifest found. Run `npm run build` first.')
    process.exit(1)
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))

/*
 * The bundled JavaScript, searched for the glob keys Vite compiled in. This is
 * the only way to confirm the resolver will actually find an override: the glob
 * is resolved at build time, so its keys are a build artefact.
 */
const bundledJs = readdirSync(join(root, 'public/build/assets'))
    .filter((file) => file.endsWith('.js'))
    .map((file) => readFileSync(join(root, 'public/build/assets', file), 'utf8'))
    .join('\n')

const themes = existsSync(themeRoot)
    ? readdirSync(themeRoot, { withFileTypes: true })
          .filter((entry) => entry.isDirectory())
          .map((entry) => entry.name)
    : []

if (themes.length === 0) {
    console.error(`No themes found in ${themeRoot}.`)
    process.exit(1)
}

for (const slug of themes) {
    const themeDir = join(themeRoot, slug)

    if (!existsSync(join(themeDir, 'theme.json'))) {
        problems.push(`${slug}: no theme.json, so the server will ignore this directory.`)
        continue
    }

    // 1. The stylesheet, if the theme ships one, must be an entrypoint.
    const styleEntry = `resources/themes/${slug}/styles/theme.css`

    if (existsSync(join(themeDir, 'styles/theme.css'))) {
        if (manifest[styleEntry] === undefined) {
            problems.push(
                `${slug}: styles/theme.css is not a Vite entrypoint. ` +
                    'Check themeStylesheets() in vite.config.ts.',
            )
        } else {
            notes.push(`${slug}: stylesheet -> ${manifest[styleEntry].file}`)
        }
    } else {
        notes.push(`${slug}: no stylesheet (valid; overrides only)`)
    }

    // 2. Every override must appear as a glob key in the bundle.
    for (const kind of ['pages', 'layouts', 'components']) {
        const dir = join(themeDir, kind)

        if (!existsSync(dir)) {
            continue
        }

        for (const file of readdirSync(dir).filter((name) => name.endsWith('.vue'))) {
            const name = file.replace(/\.vue$/, '')
            const expected = `/themes/${slug}/${kind}/${name}.vue`

            /*
             * Components a theme imports directly (rather than overriding a
             * core file) legitimately never appear in the glob, so only the
             * two override kinds are required to.
             */
            if (bundledJs.includes(expected)) {
                notes.push(`${slug}: ${kind}/${name} resolves as an override`)
            } else if (kind === 'components') {
                notes.push(`${slug}: ${kind}/${name} is theme-internal (not a core override)`)
            } else {
                problems.push(
                    `${slug}: ${kind}/${name}.vue is not in the resolver's glob, so it will ` +
                        'be silently ignored in favour of the core version.',
                )
            }
        }
    }
}

for (const note of notes) {
    console.log(`  ok    ${note}`)
}

if (problems.length > 0) {
    console.error('')
    for (const problem of problems) {
        console.error(`  FAIL  ${problem}`)
    }
    console.error(`\n${problems.length} theme problem(s).`)
    process.exit(1)
}

console.log(`\n${themes.length} theme(s) verified: ${themes.join(', ')}`)
