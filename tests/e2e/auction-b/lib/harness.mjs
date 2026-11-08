// Checks, browser contexts with error watchers, axe, screenshots and the per-spec JSON result.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright-core';
import { CHROME, outDir, pkgDir } from './env.mjs';

const AXE = path.join(pkgDir, 'node_modules/axe-core/axe.min.js');

export const SIZES = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 }, small: { width: 360, height: 740 } };

let sharedBrowser = null;
export async function browser() {
  if (!sharedBrowser) sharedBrowser = await chromium.launch({ executablePath: CHROME, headless: true });
  return sharedBrowser;
}
export async function closeBrowser() { if (sharedBrowser) { await sharedBrowser.close(); sharedBrowser = null; } }

export class Spec {
  constructor(name, app) {
    this.name = name; this.app = app;
    this.checks = []; this.notes = []; this.defects = []; this.problems = []; this.shots = []; this.axeRuns = [];
    this.allowed = []; // [{ url: RegExp, status: number[] }] responses that are expected to be 4xx/5xx
    this.shotDir = path.join(outDir, 'shots', name);
    fs.mkdirSync(this.shotDir, { recursive: true });
    this.contexts = [];
  }

  // -- checks ---------------------------------------------------------------------------------------------------
  check(label, ok, detail = '') {
    const pass = !!ok;
    this.checks.push({ label, pass, detail: String(detail ?? '') });
    if (!pass) console.log(`    FAIL  ${label}${detail ? '  -> ' + String(detail).slice(0, 300) : ''}`);
    return pass;
  }
  eq(label, actual, expected) {
    return this.check(label, JSON.stringify(actual) === JSON.stringify(expected), `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
  has(label, text, needle) {
    const ok = needle instanceof RegExp ? needle.test(text) : String(text).includes(needle);
    return this.check(label, ok, ok ? '' : `"${needle}" not found in: ${String(text).replace(/\s+/g, ' ').slice(0, 240)}`);
  }
  hasNot(label, text, needle) {
    const bad = needle instanceof RegExp ? needle.test(text) : String(text).includes(needle);
    return this.check(label, !bad, bad ? `unexpected "${needle}" in: ${String(text).replace(/\s+/g, ' ').slice(0, 240)}` : '');
  }
  note(text) { this.notes.push(text); }
  defect(text) { this.defects.push(text); }
  /** Responses matching this URL pattern may legitimately be an error status (an intended 404, 403, 419 ...). */
  expectHttp(urlPattern, ...statuses) { this.allowed.push({ url: urlPattern, status: statuses }); }

  // -- browser --------------------------------------------------------------------------------------------------
  async context({ size = 'desktop', colorScheme = 'light', reducedMotion = 'no-preference', touch = false, label = '' } = {}) {
    const b = await browser();
    const ctx = await b.newContext({
      viewport: SIZES[size] ?? size, colorScheme, reducedMotion, hasTouch: touch, isMobile: touch, acceptDownloads: true,
      deviceScaleFactor: size === 'desktop' ? 1 : 2,
    });
    ctx.__touch = touch;
    ctx.setDefaultTimeout(12000);
    ctx.setDefaultNavigationTimeout(30000);
    const tag = label || `${size}/${colorScheme}`;
    ctx.on('page', (p) => this.watch(p, tag));
    this.contexts.push(ctx);
    return ctx;
  }

  async page(opts = {}) {
    const ctx = await this.context(opts);
    const p = await ctx.newPage();
    return p;
  }

  watch(p, tag) {
    const isAllowed = (url, status) => this.allowed.some((a) => a.url.test(url) && (a.status.length === 0 || a.status.includes(status)));
    p.on('console', (m) => {
      if (m.type() !== 'error') return;
      const loc = m.location()?.url ?? '';
      const text = m.text();
      // "Failed to load resource: the server responded with a status of 404" for a response the spec expected.
      const st = /status of (\d{3})/.exec(text);
      if (st && isAllowed(loc, Number(st[1]))) return;
      this.problems.push(`[${tag}] console error: ${text.slice(0, 200)} (${loc})`);
    });
    p.on('pageerror', (e) => this.problems.push(`[${tag}] page error: ${String(e.message).slice(0, 200)}`));
    p.on('requestfailed', (r) => {
      const err = r.failure()?.errorText ?? '';
      if (/ERR_ABORTED|cancelled/i.test(err)) return; // a navigation or a poll replaced by the next
      if (/\/csp-report/.test(r.url())) return;
      this.problems.push(`[${tag}] request failed: ${r.method()} ${r.url()} ${err}`);
    });
    p.on('response', (r) => {
      const s = r.status();
      if (s < 400) return;
      if (isAllowed(r.url(), s)) return;
      this.problems.push(`[${tag}] HTTP ${s}: ${r.request().method()} ${r.url()}`);
    });
  }

  /** Navigate and return the response; asserts nothing. */
  async go(p, urlPath, { wait = 'load' } = {}) {
    const url = urlPath.startsWith('http') ? urlPath : this.app.base + urlPath;
    return p.goto(url, { waitUntil: wait });
  }

  /** Sign in through the local-only dev route. */
  async login(p, who, to = '/dashboard', opts = {}) {
    // A second dev sign-in in a browser that is already signed in as someone else is logged out by AuthenticateSession (the
    // stored password hash is the first person's), so leave first. (The real InfoDot hand-off has the same trap: see S5.)
    if (!opts.keep) await p.goto(`${this.app.base}/dev/logout`, { waitUntil: 'load' });
    const target = typeof who === 'string' && who.includes('@') ? who : this.app.userByKey(who).email;
    const r = await p.goto(`${this.app.base}/dev/login/${encodeURIComponent(target)}?to=${encodeURIComponent(to)}`, { waitUntil: 'load' });
    return r;
  }

  /**
   * The InfoDot hand-off as a browser does it: a page on InfoDot's origin posts a token form to /auth/ecosystem.
   * `launch` is the page InfoDot would serve; the origin is what the app's login-CSRF check looks at.
   * Returns the navigation response of the POST (the redirect target is page.url() afterwards).
   */
  async ecosystemSignIn(p, token, { origin = 'http://infodot.test', redirect = '', base = this.app.base } = {}) {
    await p.route(`${origin}/**`, (route) => route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><title>InfoDot launch</title><link rel="icon" href="data:,"><form id="f" method="post" action="${base}/auth/ecosystem"><input name="token" value="${token}"><input name="redirect" value="${redirect}"><button>Go</button></form>`,
    }));
    await p.goto(`${origin}/launch`);
    const [nav] = await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.evaluate(() => document.getElementById('f').submit())]);
    await p.unroute(`${origin}/**`);
    return nav;
  }

  async text(p) { return (await p.locator('body').innerText()).replace(/[ \t]+/g, ' '); }

  // -- evidence -------------------------------------------------------------------------------------------------
  async shot(p, name, { full = true } = {}) {
    const file = path.join(this.shotDir, `${name}.png`);
    try {
      // A fullPage screenshot drops the touch emulation (pointer: coarse) of a mobile context, which would change what is
      // measured afterwards; switching it back on over CDP restores it.
      await p.screenshot({ path: file, fullPage: full });
      if (p.context().__touch) {
        const cdp = await p.context().newCDPSession(p);
        await cdp.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
        await cdp.detach();
      }
      this.shots.push(path.relative(outDir, file));
    } catch (e) { this.note(`screenshot ${name} failed: ${e.message}`); }
  }

  /** Run axe on the current page; serious and critical violations fail the check. */
  async axe(p, label) {
    await p.evaluate(() => { try { document.querySelectorAll('[data-poll],[wire\\:poll]').length; } catch { /* ignore */ } });
    if (!(await p.evaluate(() => !!window.axe))) await p.addScriptTag({ path: AXE });
    const res = await p.evaluate(async () => {
      const r = await window.axe.run(document, { resultTypes: ['violations'] });
      return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 3).map((n) => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\n').slice(1, 3).join(' | ').slice(0, 160)) }));
    });
    const bad = res.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    this.axeRuns.push({ label, serious: bad.length, minor: res.length - bad.length, violations: res });
    this.check(`axe: no serious or critical issues on ${label}`, bad.length === 0, bad.map((v) => `${v.id}(${v.impact}): ${v.nodes.join(' ; ')}`).join(' // '));
    return res;
  }

  /** Horizontal overflow and small tap targets, measured in the page. */
  async layout(p, label, { minTarget = 44 } = {}) {
    const m = await p.evaluate((minT) => {
      const doc = document.documentElement;
      const overflow = Math.max(doc.scrollWidth, document.body.scrollWidth) - window.innerWidth;
      const small = [];
      for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=tab]')) {
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') continue;
        if (el.closest('[hidden], [inert]')) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        if (el.matches('input[type=checkbox], input[type=radio], input[type=file]') ) continue; // custom-drawn or wrapped by a larger label
        // Skip links that sit inside running text (WCAG 2.5.8 allows inline targets).
        if (el.tagName === 'A' && el.closest('p, li, dd, .prose') && getComputedStyle(el).display === 'inline') continue;
        if (el.matches('.sr-only, [class*="skip"]') ) continue;
        if (r.bottom < 0 || r.top > document.documentElement.scrollHeight) continue;
        if (r.height < minT - 0.5 || r.width < minT - 0.5) small.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}.${String(el.className).split(' ')[0]} "${(el.innerText || el.getAttribute('aria-label') || el.value || '').trim().slice(0, 24)}" ${Math.round(r.width)}x${Math.round(r.height)}`);
      }
      return { overflow, small };
    }, minTarget);
    this.check(`no horizontal overflow: ${label}`, m.overflow <= 1, `scrollWidth exceeds viewport by ${m.overflow}px`);
    return m;
  }

  async closeAll() { for (const c of this.contexts) { try { await c.close(); } catch { /* closed */ } } this.contexts = []; }

  // -- result ---------------------------------------------------------------------------------------------------
  finish() {
    const unexpected = [...new Set(this.problems)];
    this.check('no console errors, page errors, failed or error-status requests across all browser contexts', unexpected.length === 0, unexpected.slice(0, 12).join(' // '));
    const passed = this.checks.filter((c) => c.pass).length;
    const failed = this.checks.length - passed;
    const result = { spec: this.name, passed, failed, total: this.checks.length, checks: this.checks, notes: this.notes, defects: this.defects, problems: unexpected, shots: this.shots, axe: this.axeRuns.map(({ label, serious, minor }) => ({ label, serious, minor })) };
    fs.writeFileSync(path.join(outDir, `${this.name}.json`), JSON.stringify(result, null, 2));
    return result;
  }
}
