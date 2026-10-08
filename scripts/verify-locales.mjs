#!/usr/bin/env node
/**
 * Checks that the translations have not drifted from the source language.
 *
 * A missing key is invisible at runtime: `translate` falls back to English, so
 * a half-finished language renders as a page that is mostly translated with
 * English sentences scattered through it, and nobody notices until a speaker
 * of that language complains. An extra key is a string nobody will ever see,
 * usually left behind when an English one was renamed.
 *
 * Both are build-time facts, so they are checked at build time.
 *
 * Run:  npm run verify:locales
 */

import { readdirSync, readFileSync } from 'node:fs'
// Strip TS so the object literals can be evaluated as data.
function load(path) {
  let src = readFileSync(path, 'utf8')
  src = src.replace(/^import[^\n]*\n/gm, '').replace(/:\s*Messages\b/, '').replace(/^export default \w+\s*$/m, '')
  src = src.replace(/^const (\w+)\s*=\s*/m, 'return ')
  return new Function(src)()
}
const keys = (o, p = '') => Object.entries(o).flatMap(([k, v]) =>
  v && typeof v === 'object' && !('one' in v) ? keys(v, `${p}${k}.`) : [`${p}${k}`])

const base = 'resources/js/i18n/locales/'
const en = new Set(keys(load(base + 'en.ts')))
// Read from the directory rather than a list kept here, so that a language
// added without being listed is checked rather than silently skipped.
const locales = readdirSync(base)
  .filter((f) => f.endsWith('.ts') && f !== 'en.ts')
  .map((f) => f.slice(0, -3))
  .sort()
let bad = 0
for (const loc of locales) {
  const got = new Set(keys(load(base + loc + '.ts')))
  const missing = [...en].filter(k => !got.has(k))
  const extra = [...got].filter(k => !en.has(k))
  console.log(`${loc}: ${got.size} keys, missing ${missing.length}, extra ${extra.length}`)
  if (missing.length) { console.log('  MISSING:', missing.join(', ')); bad++ }
  if (extra.length) { console.log('  EXTRA:', extra.join(', ')); bad++ }
}
console.log(`en: ${en.size} keys (source)`)
if (!bad) console.log('locales are in step')
process.exit(bad ? 1 : 0)
