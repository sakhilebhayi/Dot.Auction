import http from 'node:http';
import { AppInstance } from '../lib/server.mjs';

// fetch() will not let a script choose the Host header; http.request does.
const raw = (port, path, { host, method = 'GET', headers = {}, body } = {}) => new Promise((resolve, reject) => {
  const req = http.request({ host: '127.0.0.1', port, path, method, headers: { ...(host ? { Host: host } : {}), ...headers } }, (res) => {
    let data = ''; res.on('data', (c) => { data += c; }); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, text: data }));
  });
  req.on('error', reject); if (body) req.write(body); req.end();
});

export const title = 'S5 Platform: public pages, login, InfoDot hand-off, health, errors, headers';
// Errors are checked the way production serves them: debug off.
export const options = { extra: { APP_DEBUG: 'false' } };

const waitUntil = async (fn, ms = 15000) => { const end = Date.now() + ms; while (Date.now() < end) { if (await fn()) return true; await new Promise((r) => setTimeout(r, 250)); } return false; };

export async function run(t, app) {
  // ======================================== public pages =============================================================
  const pages = [['/', 'Dot.Auction', 'Bid on lots until the hammer falls.'], ['/login', 'Sign in | Dot.Auction', 'Sign in'], ['/forgot-password', 'Reset your password | Dot.Auction', 'Reset your password'],
    ['/terms-of-service', 'Terms of service | Dot.Auction', 'Terms of service'], ['/privacy-policy', 'Privacy policy | Dot.Auction', 'Privacy policy'], ['/cookies', 'Cookie policy | Dot.Auction', 'Cookie policy']];
  const titles = [];
  const guest = await t.page();
  for (const [path, ttl, h1] of pages) {
    const r = await guest.goto(app.base + path);
    t.eq(`${path} loads`, r.status(), 200);
    titles.push(await guest.title());
    t.eq(`${path}: page title`, await guest.title(), ttl);
    t.eq(`${path}: exactly one h1`, await guest.locator('h1').allInnerTexts(), [h1]);
    t.eq(`${path}: one main landmark and a skip link`, [await guest.locator('main').count(), await guest.locator('a[href="#main"], a.skip-link').count() > 0], [1, true]);
    await t.axe(guest, path);
    await t.shot(guest, `guest${path.replace(/\W+/g, '-')}-light`);
  }
  t.eq('page titles are unique', new Set(titles).size, titles.length);

  // welcome page honesty: it must not promise what the product does not do
  await guest.goto(app.base + '/');
  const welcome = await t.text(guest);
  for (const bad of [/real[- ]?time/i, /instant(ly)? (notif|updat)/i, /escrow/i, /guarantee/i, /verified seller/i, /insured/i, /no fees?\b/i, /\bfree\b/i, /Reverb|WebSocket/i, /money[- ]back/i, /24\/7 support/i]) {
    t.hasNot(`welcome makes no claim matching ${bad}`, welcome, bad);
  }
  t.has('welcome states payments are not on yet, honestly', welcome, /opens when Dot\.Auction switches payments on/);
  t.has('welcome states payouts are manual', welcome, /payouts to sellers are arranged by hand/);
  t.has('welcome snipe-window numbers match the configuration (5 minutes, 6 times)', welcome, /last 5 minutes moves the close out by 5 minutes, up to 6 times/);
  t.has('welcome says the reserve stays private', welcome, /reserve has been met\. The amount stays with the seller/);
  const links = await guest.$$eval('a[href]', (a) => a.map((x) => [x.innerText.trim(), x.getAttribute('href')]));
  const dead = links.filter(([, h]) => h === '#' || h === '' || /^javascript:/i.test(h));
  t.eq('welcome has no dead links', dead, []);
  for (const [label, href] of [['Terms', '/terms-of-service'], ['Privacy', '/privacy-policy'], ['Cookies', '/cookies']]) {
    t.check(`footer link ${label} points at ${href}`, links.some(([l, h]) => l === label && h.endsWith(href)), JSON.stringify(links.filter(([l]) => l === label)));
  }
  const sitemap = await fetch(app.base + '/robots.txt'); t.note(`robots.txt: ${sitemap.status}`);
  // a signed-out visitor to an app page is sent to sign in, and comes back
  const gp = await t.page();
  await gp.goto(`${app.base}/auctions/1`);
  t.check('a guest on a lot page is sent to sign in', /\/login/.test(gp.url()), gp.url());
  await gp.goto(`${app.base}/register`);
  t.check('/register lands on the login page (sign-up is closed)', /\/login/.test(gp.url()), gp.url());
  t.has('...which explains where accounts come from', await t.text(gp), 'Dot.Auction accounts come from InfoDot');

  // ======================================== login ====================================================================
  t.expectHttp(/\/login$/, 422, 429);
  const lp = await t.page();
  const attempt = async (email, pw) => {
    await lp.goto(app.base + '/login');
    await lp.fill('input[name=email]', email); await lp.fill('input[type=password]', pw);
    await Promise.all([lp.waitForLoadState('load'), lp.locator('form button[type=submit]').first().click()]);
    await lp.waitForLoadState('load');
    return (await t.text(lp)).replace(/\s+/g, ' ');
  };
  const wrong = await attempt('thandi.mokoena@demo.auction.test', 'not-the-password');
  const unknown = await attempt('nobody@nowhere.test', 'not-the-password');
  const msgOf = (txt) => (txt.match(/These credentials[^.]*\.|do not match[^.]*\./i) || [''])[0];
  t.check('a wrong password is refused with a plain message', /credentials|do not match/i.test(wrong), wrong.slice(0, 200));
  t.eq('an unknown e-mail gets the same message (no account enumeration)', msgOf(unknown), msgOf(wrong));
  t.check('the e-mail typed is kept, the password is not', (await lp.inputValue('input[name=email]')) === 'nobody@nowhere.test' && (await lp.inputValue('input[type=password]')) === '');
  await t.shot(lp, 'login-error');
  await attempt('thandi.mokoena@demo.auction.test', 'password');
  t.check('the right password signs in', /\/dashboard/.test(lp.url()), lp.url());

  // ======================================== InfoDot hand-off ==========================================================
  const hp = await t.page();
  const nav = await t.ecosystemSignIn(hp, 'e2e:thandi.mokoena@demo.auction.test:Thandi Mokoena');
  t.check('the hand-off lands signed in on the dashboard', /\/dashboard/.test(hp.url()), hp.url());
  t.has('...as the right person', await t.text(hp), 'Thandi Mokoena');
  t.check('the session cookie is HttpOnly and SameSite', (await hp.context().cookies()).filter((c) => /session/i.test(c.name)).every((c) => c.httpOnly && /Lax|Strict/.test(c.sameSite)), JSON.stringify((await hp.context().cookies()).map((c) => [c.name, c.httpOnly, c.sameSite])));
  // the next Livewire call: the bell
  const lw = hp.waitForResponse((r) => /\/livewire[^/]*\/update/.test(r.url()) && r.request().method() === 'POST');
  await hp.getByRole('button', { name: /Notifications/ }).first().click();
  const lwRes = await lw;
  t.eq('the next Livewire call after the hand-off succeeds (no 401/419)', lwRes.status(), 200);
  await hp.locator('#nf-panel').waitFor();
  t.has('...and the bell panel opens with the person\'s notices', await hp.locator('#nf-panel').innerText(), /Notifications/);
  await hp.keyboard.press('Escape');
  // the bell's own poll (every 30 s while visible) and the lot page's snapshot poll
  const callsMethod = (r, name) => /\/livewire[^/]*\/update/.test(r.url()) && r.request().method() === 'POST' && (r.request().postData() ?? '').includes(`"method":"${name}"`);
  const poll = await hp.waitForResponse((r) => callsMethod(r, 'refresh'), { timeout: 45000 }).catch(() => null);
  t.check('the notification poll succeeds after the hand-off', !!poll && poll.status() === 200, poll ? poll.status() : 'no poll within 40 s');
  const live = app.q("select id from auctions where status = 'active' and starts_at <= datetime('now') limit 1")[0].id;
  await hp.goto(`${app.base}/auctions/${live}`);
  const snap = hp.waitForResponse((r) => callsMethod(r, 'snapshot'), { timeout: 20000 }).catch(() => null);
  const snapRes = await snap;
  t.check('the lot page\'s live poll (snapshot) succeeds after the hand-off', !!snapRes && snapRes.status() === 200, snapRes ? snapRes.status() : 'none');

  // the Sanctum "stateful host" trap: the app reached by a host that is not APP_URL's (here auction.localhost) must still keep a session
  const other = `http://auction.localhost:${app.port}`;
  const op = await t.page();
  await t.ecosystemSignIn(op, 'e2e:johan.botha@demo.auction.test', { base: other });
  t.check('hand-off on a second host name lands signed in', op.url().startsWith(other) && /\/dashboard/.test(op.url()), op.url());
  const lw2 = op.waitForResponse((r) => callsMethod(r, 'toggle'));
  await op.getByRole('button', { name: /Notifications/ }).first().click();
  t.eq('...and the first Livewire call there succeeds', (await lw2).status(), 200);
  await op.goto(`${other}/auctions/${live}`);
  const snap2 = await op.waitForResponse((r) => callsMethod(r, 'snapshot'), { timeout: 20000 }).catch(() => null);
  t.check('...as does the lot page poll', !!snap2 && snap2.status() === 200, snap2 ? snap2.status() : 'none');

  // redirect honoured only when it is a path on this site
  const rd = await t.page();
  await t.ecosystemSignIn(rd, 'e2e:aisha.patel@demo.auction.test', { redirect: '/auctions' });
  t.check('a path redirect is honoured', new URL(rd.url()).pathname === '/auctions', rd.url());
  for (const bad of ['//evil.test/x', 'https://evil.test/', '/\\evil.test', '/a/../b', '/%2e%2e/x']) {
    const bp = await t.page();
    await t.ecosystemSignIn(bp, 'e2e:aisha.patel@demo.auction.test', { redirect: bad });
    t.check(`redirect "${bad}" is ignored (dashboard instead)`, new URL(bp.url()).origin === app.base && new URL(bp.url()).pathname === '/dashboard', bp.url());
  }
  // a refused hand-off is calm and leaks nothing
  const rp = await t.page();
  await t.ecosystemSignIn(rp, 'garbage-token');
  t.check('a bad token lands on the login page', /\/login/.test(rp.url()), rp.url());
  t.has('...with a plain message that names no token', await t.text(rp), /could not sign you in through InfoDot/);
  t.hasNot('...and does not echo the token', await t.text(rp), 'garbage-token');
  await t.ecosystemSignIn(rp, 'e2e-down');
  t.has('InfoDot being down says so, differently', await t.text(rp), /unavailable right now/);
  await t.ecosystemSignIn(rp, 'e2e:x@y.test', { origin: 'http://evil.test' });
  t.has('a POST from another site is refused (login CSRF)', await t.text(rp), /could not sign you in/);
  t.eq('...and created no account', app.q("select count(*) c from users where email = 'x@y.test'")[0].c, 0);
  const getTok = await fetch(`${app.base}/auth/ecosystem?token=e2e:z@z.test`, { redirect: 'manual' });
  t.check('an old GET link with a token in the URL is not honoured', getTok.status === 302 && /\/login$/.test(getTok.headers.get('location') ?? ''), `${getTok.status} ${getTok.headers.get('location')}`);

  // someone else is already signed in in this browser: the hand-off must switch person and keep a working session
  const sw = await t.page();
  await t.login(sw, 'lerato', '/dashboard');
  t.has('(setup) Lerato is signed in', await t.text(sw), 'Lerato Dlamini');
  await t.ecosystemSignIn(sw, 'e2e:aisha.patel@demo.auction.test');
  await sw.goto(`${app.base}/dashboard`);
  const who = await t.text(sw);
  t.check('hand-off over another person\'s session: the new person is signed in and stays signed in', /Aisha Patel/.test(who) && !/\/login/.test(sw.url()), sw.url());

  // ======================================== other hosts, health, headers =============================================
  const prod = new AppInstance('s5-prod', { seed: 'bare', appEnvName: 'production', appUrlHost: 'auction.example.test' });
  await prod.start();
  const get = async (path, host) => { const r = await raw(prod.port, path, { host }); return { status: r.status, headers: { get: (k) => r.headers[k.toLowerCase()] ?? null }, text: async () => r.text, json: async () => JSON.parse(r.text) }; };
  t.eq('production: the real host is served', (await get('/login', `auction.example.test:${prod.port}`)).status, 200);
  const evil = await get('/login', `evil.test:${prod.port}`);
  t.eq('production: an unknown Host header is rejected (400)', evil.status, 400);
  t.eq('production: a forged Host never reaches a link in the page (no body from the app)', /evil\.test/.test(await evil.text()), false);
  t.eq('production: /up still answers for a probe by IP', (await get('/up', `127.0.0.1:${prod.port}`)).status, 200);
  t.eq('production: /up answers with an unknown Host too (health probes)', (await get('/up', `evil.test:${prod.port}`)).status, 200);
  const rt = await get('/up/realtime', `evil.test:${prod.port}`);
  t.eq('production: /up/realtime answers 200 in polling mode', [rt.status, (await rt.json()).mode], [200, 'polling']);
  const dev = await get('/dev/login/1', `auction.example.test:${prod.port}`);
  t.eq('production: the dev sign-in route does not exist', dev.status, 404);
  const devKit = await get('/dev/kit', `auction.example.test:${prod.port}`);
  t.eq('production: neither does the design gallery', devKit.status, 404);
  const hdr = (await get('/login', `auction.example.test:${prod.port}`)).headers;
  t.check('production: no debug output on an error', !/Whoops|Illuminate\\|vendor\//.test(await (await get('/nope', `auction.example.test:${prod.port}`)).text()));
  await prod.stop();

  const r = await fetch(app.base + '/up'); t.eq('/up is 200', r.status, 200);
  const rt2 = await fetch(app.base + '/up/realtime');
  t.eq('/up/realtime: 200 with mode polling', [rt2.status, await rt2.json()], [200, { status: 'degraded', mode: 'polling' }]);
  const h = (await fetch(app.base + '/login')).headers;
  t.eq('header X-Content-Type-Options', h.get('x-content-type-options'), 'nosniff');
  t.check('header X-Frame-Options or frame-ancestors present', !!h.get('x-frame-options') || /frame-ancestors/.test(h.get('content-security-policy-report-only') ?? h.get('content-security-policy') ?? ''));
  t.check('header Referrer-Policy present', /strict-origin/.test(h.get('referrer-policy') ?? ''));
  t.check('header Permissions-Policy present', /camera=\(\)/.test(h.get('permissions-policy') ?? ''));
  t.check('a Content-Security-Policy (report-only or enforced) is sent', !!(h.get('content-security-policy') || h.get('content-security-policy-report-only')));
  t.note(`CSP mode: ${h.get('content-security-policy') ? 'enforced' : 'report-only'}; script-src has unsafe-eval (documented Livewire/Alpine constraint)`);
  t.note(`X-Powered-By is sent by the dev server: ${h.get('x-powered-by')} (strip with expose_php=0 on the host)`);
  t.check('HSTS is not sent on plain http (only on https)', !h.get('strict-transport-security'));
  const lottery = await fetch(app.base + '/dashboard', { redirect: 'manual' });
  t.eq('a guest on /dashboard is redirected, with no body leaking', [lottery.status, (await lottery.text()).length < 400], [302, true]);

  // CSP report endpoint
  const csp = (body, ct = 'application/csp-report') => fetch(app.base + '/csp-report', { method: 'POST', headers: { 'content-type': ct }, body: typeof body === 'string' ? body : JSON.stringify(body) });
  const ok1 = await csp({ 'csp-report': { 'effective-directive': 'script-src', 'blocked-uri': 'https://cdn.evil.test/a.js?token=SECRET', 'document-uri': 'http://x/' } });
  t.eq('CSP report (report-uri shape) is accepted with 204', ok1.status, 204);
  t.check('...and sets no cookie', !ok1.headers.get('set-cookie'), ok1.headers.get('set-cookie'));
  t.eq('CSP report (Reporting API shape) is accepted', (await csp([{ type: 'csp-violation', body: { effectiveDirective: 'img-src', blockedURL: 'inline' } }], 'application/reports+json')).status, 204);
  t.eq('junk is accepted quietly too', (await csp('not json at all <script>')).status, 204);
  t.eq('an 8 MB body is not a crash', (await csp('x'.repeat(8 * 1024 * 1024))).status < 500, true);
  await waitUntil(() => /csp\.violation/.test(app.serverLog()));
  const log = app.serverLog();
  t.has('the violation was logged as directive plus host only', log, /csp\.violation.*script-src.*cdn\.evil\.test/);
  t.hasNot('...never the query string or the token', log, 'SECRET');
  t.eq('GET /csp-report is not an endpoint', (await fetch(app.base + '/csp-report')).status, 405);

  // ======================================== error pages ==============================================================
  const errs = await t.page();
  const check = async (code, expectTitle, how) => {
    const res = await how();
    t.eq(`${code}: status`, res.status(), code);
    const txt = (await t.text(errs)).replace(/\s+/g, ' ');
    t.has(`${code}: headline in the product's voice`, txt, expectTitle);
    t.eq(`${code}: one h1, and a way out`, [await errs.locator('h1').count(), (await errs.locator('main a[href]').count()) > 0], [1, true]);
    t.hasNot(`${code}: no stack trace, file path or framework name`, txt, /Whoops|Stack trace|vendor\/|\.php|Illuminate|Symfony|SQLSTATE|laravel/i);
    t.check(`${code}: the title carries the code and the product`, new RegExp(`^${code} .* \\| Dot\\.Auction$`).test(await errs.title()), await errs.title());
    await t.shot(errs, `error-${code}`);
    await t.axe(errs, `error ${code}`);
    return txt;
  };
  for (const c of [403, 404, 419, 429, 500, 503]) t.expectHttp(/.*/, c);
  await check(404, /find|not here|no such|doesn.t exist|missing/i, () => errs.goto(app.base + '/no-such-page'));
  await t.login(errs, 'sipho', '/dashboard');
  await check(403, /not allowed|permission|access|cannot/i, () => errs.goto(app.base + '/operator/settlement-payments'));
  await check(404, /./, () => errs.goto(app.base + '/settlements/00000000-0000-0000-0000-000000000000'));
  // 419: a form posted without a CSRF token and without same-origin proof (what a stale tab or another site sends)
  const r419 = await raw(app.port, '/login', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded', Accept: 'text/html' }, body: 'email=a%40b.test&password=x' });
  t.eq('419: a POST without a CSRF token is refused', r419.status, 419);
  await errs.route(`${app.base}/__419`, (route) => route.fulfill({ status: 419, contentType: 'text/html', body: r419.text }));
  await check(419, /expired|session|refresh|try again/i, () => errs.goto(app.base + '/__419'));
  await errs.unroute(`${app.base}/__419`);
  // 429: Fortify's sign-in limiter first (what it answers), then the product's own error page through a route limiter
  await t.go(errs, '/dev/logout');
  await errs.goto(app.base + '/login');
  const token = await errs.locator('input[name=_token]').inputValue();
  let last = null;
  for (let i = 0; i < 8 && last?.status !== 429; i++) {
    last = await errs.evaluate(async (tok) => { const r = await fetch('/login', { method: 'POST', credentials: 'same-origin', headers: { 'content-type': 'application/x-www-form-urlencoded', Accept: 'text/html' }, body: `_token=${tok}&email=throttle@e2e.test&password=bad` }); return { status: r.status, text: (await r.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 1500) }; }, token);
  }
  t.eq('429: repeated bad sign-ins are throttled', last.status, 429);
  t.check('429: the sign-in limiter answers in words, not a framework page', /Too many|try again|wait/i.test(last.text) && !/Whoops|Symfony/.test(last.text), last.text.slice(0, 200));
  await t.login(errs, 'aisha', '/dashboard');
  const sale = app.one("select id from auction_settlements where status = 'payment_overdue'").id;
  const csrf = await errs.locator('meta[name=csrf-token]').getAttribute('content');
  let page429 = null;
  for (let i = 0; i < 15 && !page429; i++) {
    const r = await errs.evaluate(async ([id, tok]) => { const r = await fetch(`/settlements/${id}/cancel`, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': tok, Accept: 'text/html' } }); return [r.status, await r.text()]; }, [sale, csrf]);
    if (r[0] === 429) page429 = r[1];
  }
  t.check('429: a route limiter engages after repeated requests', !!page429);
  await errs.route(`${app.base}/__429`, (route) => route.fulfill({ status: 429, contentType: 'text/html', body: page429 ?? '' }));
  await check(429, /too many|slow down|wait|try again/i, () => errs.goto(app.base + '/__429'));
  await errs.unroute(`${app.base}/__429`);
  // 500: break a table the dashboard reads, with debug off
  await t.login(errs, 'thandi', '/seller/auctions');
  app.run('alter table watchlists rename to watchlists_off');
  const r500 = await errs.goto(app.base + '/watchlist');
  app.run('alter table watchlists_off rename to watchlists');
  t.eq('500: a broken query is a 500', r500.status(), 500);
  const txt500 = (await t.text(errs)).replace(/\s+/g, ' ');
  t.has('500: says it is on us, plainly', txt500, /Something went wrong on our end/);
  t.hasNot('500: leaks no SQL, table or path', txt500, /watchlist|SQLSTATE|no such table|\/Users\//i);
  await t.shot(errs, 'error-500'); await t.axe(errs, 'error 500');
  t.has('500: is logged at error level on the server', app.serverLog(), /\.ERROR:.*no such table/);
  // 503: maintenance mode
  app.artisan(['down', '--retry=60']);
  let r503;
  try { r503 = await errs.goto(app.base + '/dashboard'); } finally { app.artisan(['up']); }
  t.eq('503: maintenance mode answers 503', r503.status(), 503);
  const txt503 = (await t.text(errs)).replace(/\s+/g, ' ');
  t.has('503: says it is coming back, in the product\'s voice', txt503, /back|moment|maintenance|shortly/i);
  t.eq('503: sends Retry-After', r503.headers()['retry-after'], '60');
  await t.shot(errs, 'error-503'); await t.axe(errs, 'error 503');
  const after = await fetch(app.base + '/login'); t.eq('the site is back after `up`', after.status, 200);

  // phone and dark for the public pages and one error
  const ph = await (await t.context({ size: 'phone', touch: true, label: 'phone' })).newPage();
  for (const [path] of pages) {
    await ph.goto(app.base + path);
    const l = await t.layout(ph, `${path} at 390`);
    t.check(`${path} at 390: tap targets are 44px`, l.small.length === 0, l.small.slice(0, 5).join(' | '));
    await t.shot(ph, `phone${path.replace(/\W+/g, '-')}`);
  }
  await ph.goto(app.base + '/nope');
  await t.layout(ph, '404 at 390');
  const dk = await (await t.context({ colorScheme: 'dark', label: 'dark' })).newPage();
  for (const [path] of pages) { await dk.goto(app.base + path); await t.axe(dk, `${path} (dark scheme)`); }
  await t.shot(dk, 'dark-terms');
}
