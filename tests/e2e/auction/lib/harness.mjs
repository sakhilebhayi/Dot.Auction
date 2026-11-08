// The spec harness: a browser, "people" (separate browser contexts), recorded checks, screenshots, axe and a
// strict watch on the console and the network. Every check is recorded in out/results/<spec>.json whether it passes or not.
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { bootApp, OUT_DIR } from './server.mjs';

const require = createRequire(import.meta.url);
const AXE_SOURCE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

export const DEMO = {
    thandi: 'thandi.mokoena@demo.auction.test',
    pieter: 'pieter.merwe@demo.auction.test',
    lerato: 'lerato.dlamini@demo.auction.test',
    sipho: 'sipho.nkosi@demo.auction.test',
    aisha: 'aisha.patel@demo.auction.test',
    johan: 'johan.botha@demo.auction.test',
    naledi: 'naledi.khumalo@demo.auction.test',
    zanele: 'zanele.mthembu@demo.auction.test',
};

export const SIZES = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 }, small: { width: 360, height: 740 } };

const chromePath = () => {
    if (process.env.E2E_CHROME) { return { executablePath: process.env.E2E_CHROME }; }
    const mac = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    return fs.existsSync(mac) ? { executablePath: mac } : { channel: 'chrome' };
};

export async function runSpec(id, title, body, opts = {}) {
    const started = Date.now();
    const shotDir = path.join(OUT_DIR, 'shots', id);
    fs.rmSync(shotDir, { recursive: true, force: true });
    fs.mkdirSync(shotDir, { recursive: true });
    fs.mkdirSync(path.join(OUT_DIR, 'results'), { recursive: true });

    const checks = [];
    const shots = [];
    const notes = [];
    const axeSummary = [];
    let app = null;
    let browser = null;
    const people = [];
    let fatal = null;

    const record = (name, ok, detail) => {
        checks.push({ name, ok: !!ok, detail: detail === undefined ? undefined : String(detail).slice(0, 3000) });
        console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${!ok && detail !== undefined ? '  -> ' + String(detail).slice(0, 300) : ''}`);
        return !!ok;
    };

    console.log(`\n== ${id} ${title}`);
    try {
        app = await bootApp(id, opts.env || {});
        browser = await chromium.launch({ ...chromePath(), headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });

        const t = {
            id, app, browser, notes,
            check: (name, ok, detail) => record(name, ok, detail),
            eq: (name, actual, expected) => record(name, JSON.stringify(actual) === JSON.stringify(expected), `got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`),
            has: (name, text, needle) => record(name, String(text).includes(needle), `expected to find "${needle}" in "${String(text).slice(0, 240).replace(/\s+/g, ' ')}"`),
            lacks: (name, text, needle) => record(name, !String(text).includes(needle), `found forbidden "${needle}" in "${String(text).slice(0, 240).replace(/\s+/g, ' ')}"`),
            note: (n) => { notes.push(n); console.log('  note ' + n); },
            sql: app.sql,
            artisan: app.artisan,
            sleep: (ms) => new Promise((r) => setTimeout(r, ms)),
            id_of: (title2) => Number(app.sql(`select id from auctions where title = '${title2.replace(/'/g, "''")}'`)) || null,
            user_id: (who) => Number(app.sql(`select id from users where email = '${DEMO[who] || who}'`)) || null,
        };

        /** A person: a browser context of their own, logged in through the dev route, with every console error and failed request recorded. */
        t.person = async (who, o = {}) => {
            const ctx = await browser.newContext({
                viewport: o.viewport || SIZES.desktop,
                colorScheme: o.colorScheme || 'light',
                reducedMotion: o.reducedMotion || 'no-preference',
                timezoneId: o.timezoneId,
                locale: 'en-ZA',
                javaScriptEnabled: o.javaScript !== false,
                deviceScaleFactor: o.deviceScaleFactor || 1,
                hasTouch: !!o.touch,
                isMobile: !!o.mobile,
                baseURL: app.url,
            });
            const page = await ctx.newPage();
            page.setDefaultTimeout(10000);
            const p = { who, ctx, page, issues: [], tolerated: [], label: who || 'guest' };
            people.push(p);
            page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') { p.issues.push({ kind: 'console.' + m.type(), text: m.text(), url: page.url() }); } });
            page.on('pageerror', (e) => p.issues.push({ kind: 'pageerror', text: String(e.message || e), url: page.url() }));
            page.on('requestfailed', (r) => { const f = r.failure()?.errorText || ''; if (f !== 'net::ERR_ABORTED') { p.issues.push({ kind: 'requestfailed', text: `${r.method()} ${r.url()} ${f}`, url: page.url() }); } });
            page.on('response', (r) => { if (r.status() >= 400) { p.issues.push({ kind: 'http' + r.status(), text: `${r.request().method()} ${r.url()}`, url: page.url() }); } });
            p.tolerate = (re) => { p.tolerated.push(re); };
            p.drain = () => { const out = p.issues.filter((i) => !p.tolerated.some((re) => re.test(i.kind + ' ' + i.text))); p.issues.length = 0; return out; };
            p.go = async (url, wait = 'load') => { const r = await page.goto(url, { waitUntil: wait }); await settle(page); return r; };
            if (who) { await p.go(`/dev/login/${encodeURIComponent(DEMO[who] || who)}${o.to ? '?to=' + encodeURIComponent(o.to) : ''}`); }
            return p;
        };

        /** Fail if the person's browser logged an unexpected console message, page error, failed request or 4xx/5xx since the last call. */
        t.clean = (p, label) => {
            const bad = p.drain();
            record(`${p.label}: no console errors or failed requests ${label ? '(' + label + ')' : ''}`.trim(), bad.length === 0, bad.map((b) => `${b.kind} ${b.text}`).join(' | '));
            return bad.length === 0;
        };

        t.shot = async (p, name, o = {}) => {
            const file = `${name}.png`;
            await p.page.screenshot({ path: path.join(shotDir, file), fullPage: o.full !== false });
            shots.push(`${id}/${file}`);
            return file;
        };

        /** axe-core on the current page. Zero serious or critical is the pass bar; the others are counted and noted. */
        t.axe = async (p, label) => {
            const page = p.page;
            await page.evaluate(AXE_SOURCE);
            const res = await page.evaluate(async () => {
                const r = await window.axe.run(document, { resultTypes: ['violations'] });
                return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, target: v.nodes.slice(0, 2).map((n) => n.target.join(' ')) }));
            });
            const hard = res.filter((v) => v.impact === 'serious' || v.impact === 'critical');
            const soft = res.filter((v) => !(v.impact === 'serious' || v.impact === 'critical'));
            axeSummary.push({ label, hard: hard.length, soft: soft.length, rules: res.map((v) => v.id + ':' + v.impact) });
            record(`axe: zero serious or critical (${label})`, hard.length === 0, hard.map((v) => `${v.id}[${v.nodes}] ${v.target.join(' ; ')}`).join(' | '));
            if (soft.length) { notes.push(`axe moderate/minor on ${label}: ${soft.map((v) => v.id).join(', ')}`); }
            return res;
        };

        /** Screenshot and axe on the page as it is now, at 1440 and 390 in light and dark (or the sets you pass). */
        t.audit = async (p, name, o = {}) => {
            const sizes = o.sizes || ['desktop', 'phone'];
            const themes = o.themes || ['light', 'dark'];
            const original = p.page.viewportSize();
            for (const th of themes) {
                await p.page.emulateMedia({ colorScheme: th });
                for (const sz of sizes) {
                    await p.page.setViewportSize(SIZES[sz]);
                    await settle(p.page, 150);
                    await t.shot(p, `${name}--${sz === 'desktop' ? '1440' : sz === 'phone' ? '390' : '360'}-${th}`, { full: o.full });
                    if (o.axe !== false) { await t.axe(p, `${name} ${sz} ${th}`); }
                    if (o.overflow !== false && sz !== 'desktop') {
                        const ov = await p.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                        record(`no horizontal overflow: ${name} ${sz} ${th}`, ov <= 0, `overflow ${ov}px`);
                    }
                }
            }
            await p.page.setViewportSize(original);
            await p.page.emulateMedia({ colorScheme: 'light' });
        };

        await body(t);
        for (const p of people) { const rest = p.drain(); if (rest.length) { record(`${p.label}: unexpected browser messages at end of spec`, false, rest.map((b) => `${b.kind} ${b.text}`).slice(0, 6).join(' | ')); } }
    } catch (e) {
        fatal = e;
        record('spec ran to the end without an exception', false, e && e.stack ? e.stack.split('\n').slice(0, 4).join(' / ') : e);
        try { for (const p of people.slice(0, 1)) { await p.page.screenshot({ path: path.join(shotDir, 'zz-failure.png') }); } } catch { /* ignore */ }
    } finally {
        for (const p of people) { try { await p.ctx.close(); } catch { /* ignore */ } }
        try { await browser?.close(); } catch { /* ignore */ }
        try { await app?.stop(); } catch { /* ignore */ }
    }

    const failed = checks.filter((c) => !c.ok);
    const result = { id, title, passed: failed.length === 0 && !fatal, checks: checks.length, failed: failed.length, failures: failed, shots, notes, axe: axeSummary, seconds: Math.round((Date.now() - started) / 100) / 10 };
    fs.writeFileSync(path.join(OUT_DIR, 'results', `${id}.json`), JSON.stringify({ ...result, allChecks: checks }, null, 1));
    console.log(`== ${id}: ${result.passed ? 'PASS' : 'FAIL'}  ${checks.length - failed.length}/${checks.length} checks  ${result.seconds}s`);
    return result;
}

/** Wait for the page to be quiet: load, network idle (Livewire), then a short pause for scripts. */
export async function settle(page, ms = 250) {
    try { await page.waitForLoadState('networkidle', { timeout: 8000 }); } catch { /* polling pages are never idle for long */ }
    await page.waitForTimeout(ms);
}

export const text = async (page, sel = 'body') => (await page.locator(sel).first().innerText()).replace(/[ \t]+\n/g, '\n');
