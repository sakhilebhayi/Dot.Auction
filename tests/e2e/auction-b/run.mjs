#!/usr/bin/env node
// Dot.Auction seller / operator / account / platform end-to-end runner.
//   node run.mjs            run every spec
//   node run.mjs s1 s3      run the named specs (prefix match on the file name)
// Each spec gets a fresh SQLite file, its own `php -S` server on a free port, a migrate and `auction:demo-seed --fresh`.
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { outDir, pkgDir } from './lib/env.mjs';
import { AppInstance } from './lib/server.mjs';
import { Spec, closeBrowser } from './lib/harness.mjs';

fs.mkdirSync(outDir, { recursive: true });
const wanted = process.argv.slice(2).filter((a) => !a.startsWith('-'));
const files = fs.readdirSync(path.join(pkgDir, 'specs')).filter((f) => f.endsWith('.mjs')).sort()
  .filter((f) => wanted.length === 0 || wanted.some((w) => f.startsWith(w.toLowerCase())));

const summary = [];
for (const file of files) {
  const mod = await import(pathToFileURL(path.join(pkgDir, 'specs', file)).href);
  const name = file.replace(/\.mjs$/, '');
  console.log(`\n=== ${name}: ${mod.title}`);
  const t0 = Date.now();
  const app = new AppInstance(name, mod.options ?? {});
  let spec;
  try {
    await app.start();
    spec = new Spec(name, app);
    await mod.run(spec, app);
  } catch (e) {
    spec ??= new Spec(name, app);
    spec.check(`spec ran to the end without an exception`, false, e.stack || e.message);
  } finally {
    await spec?.closeAll();
  }
  const result = spec.finish();
  result.title = mod.title; result.seconds = Math.round((Date.now() - t0) / 1000);
  fs.writeFileSync(path.join(outDir, `${name}.json`), JSON.stringify(result, null, 2));
  await app.stop();
  summary.push({ spec: name, title: mod.title, passed: result.passed, failed: result.failed, total: result.total, seconds: result.seconds });
  console.log(`--- ${name}: ${result.failed === 0 ? 'PASS' : 'FAIL'}  ${result.passed}/${result.total} checks  (${result.seconds}s)`);
}
await closeBrowser();
fs.writeFileSync(path.join(outDir, 'summary.json'), JSON.stringify(summary, null, 2));
console.log('\nSUMMARY');
for (const s of summary) console.log(`  ${s.failed === 0 ? 'PASS' : 'FAIL'}  ${s.spec.padEnd(28)} ${s.passed}/${s.total}  ${s.title}`);
process.exit(summary.some((s) => s.failed > 0) ? 1 : 0);
