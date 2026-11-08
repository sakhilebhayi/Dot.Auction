#!/usr/bin/env node
// Runs the buyer-side end-to-end specs one after another. Each boots its own app and database.
//   node run.mjs            all specs
//   node run.mjs b4 b7      only the specs whose file name starts with one of these
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const want = process.argv.slice(2).map((s) => s.toLowerCase());
const files = fs.readdirSync(path.join(here, 'specs')).filter((f) => f.endsWith('.mjs')).sort()
    .filter((f) => want.length === 0 || want.some((w) => f.toLowerCase().startsWith(w)));

const results = [];
for (const f of files) {
    const mod = await import(pathToFileURL(path.join(here, 'specs', f)).href);
    results.push(await mod.default());
}

const out = path.join(here, 'out');
fs.mkdirSync(out, { recursive: true });
fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify(results.map(({ failures, ...r }) => ({ ...r, failures })), null, 1));
console.log('\n== SUMMARY');
for (const r of results) { console.log(`${r.passed ? 'PASS' : 'FAIL'}  ${r.id.padEnd(4)} ${String(r.checks - r.failed).padStart(3)}/${String(r.checks).padEnd(3)} ${r.title}`); }
process.exit(results.every((r) => r.passed) ? 0 : 1);
