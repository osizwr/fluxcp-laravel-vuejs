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
 *   3. A page composition names a block that nothing provides, so the renderer
 *      drops it. The page loads, just missing a section.
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

/** 'server-status' -> 'ServerStatus', matching the client block registry. */
function blockFileName(name) {
    return name
        .split(/[-_]/)
        .filter((part) => part !== '')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join('')
}

const coreBlockDir = join(root, 'resources/js/blocks')
const coreBlocks = existsSync(coreBlockDir)
    ? readdirSync(coreBlockDir).filter((file) => file.endsWith('.vue'))
    : []

for (const slug of themes) {
    const themeDir = join(themeRoot, slug)
    const manifestFile = join(themeDir, 'theme.json')

    if (!existsSync(manifestFile)) {
        problems.push(`${slug}: no theme.json, so the server will ignore this directory.`)
        continue
    }

    /*
     * 0. Every block a composition names must resolve somewhere.
     *
     * Named themeManifest, not manifest: the Vite build manifest is already in
     * scope above, and shadowing it made the stylesheet check below silently
     * look in the wrong file.
     */
    let themeManifest
    try {
        themeManifest = JSON.parse(readFileSync(manifestFile, 'utf8'))
    } catch (error) {
        problems.push(`${slug}: theme.json is not valid JSON (${error.message}).`)
        continue
    }

    for (const [pageKey, page] of Object.entries(themeManifest.pages ?? {})) {
        const blocks = (page.blocks ?? []).map((entry) =>
            typeof entry === 'string' ? entry : entry.block,
        )

        if (blocks.length === 0) {
            problems.push(`${slug}: page '${pageKey}' declares no blocks, so it would render blank.`)
            continue
        }

        for (const block of blocks) {
            const file = `${blockFileName(block)}.vue`
            const inTheme = existsSync(join(themeDir, 'blocks', file))
            const inCore = coreBlocks.includes(file)

            if (inTheme) {
                notes.push(`${slug}: ${pageKey} block '${block}' -> theme's ${file}`)
            } else if (inCore) {
                notes.push(`${slug}: ${pageKey} block '${block}' -> core ${file} (fallback)`)
            } else {
                problems.push(
                    `${slug}: page '${pageKey}' names the block '${block}', but neither ` +
                        `${slug}/blocks/${file} nor resources/js/blocks/${file} exists. ` +
                        'The renderer drops it, so the section would be silently missing.',
                )
            }
        }
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
    for (const kind of ['pages', 'layouts', 'components', 'blocks']) {
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
            } else if (kind === 'blocks') {
                problems.push(
                    `${slug}: blocks/${name}.vue is not in the block registry's glob, so the ` +
                        'core block would be used instead with no error.',
                )
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
