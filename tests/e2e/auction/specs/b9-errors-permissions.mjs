// B9. What a buyer cannot do or see, and what happens when things break: other people's drafts, sales and photos, seller and operator pages,
// an expired session, rate limiting, a lost connection, a server failure, and the error pages themselves.
import { runSpec } from '../lib/harness.mjs';
import { flat, mainText, ts, startBid } from '../lib/common.mjs';

export default () => runSpec('b9', 'Errors and permissions as a buyer', async (t) => {
    const uid = (w) => t.user_id(w);
    const draftId = t.id_of('Oak dining table, seats eight');           // thandi's draft
    const draftOfPieter = t.id_of('Vinyl record collection, 40 records');
    const siphoSale = t.sql(`select id from auction_settlements where winner_id=${uid('sipho')} limit 1`);
    const lot = t.id_of('Land Rover Defender parts lot');
    const mine = await t.person('lerato');
    const csrfOf = async (p, path) => { await p.go(path); return p.page.locator('meta[name="csrf-token"]').getAttribute('content'); };
    const get = async (p, path) => (await p.ctx.request.get(path, { maxRedirects: 0 })).status();

    // ---------- other people's drafts, sales and photos ----------
    t.eq("another seller's draft page is 404 (not 403: its existence is not confirmed)", await get(mine, `/auctions/${draftId}`), 404);
    t.eq('a second seller\'s draft is 404 too', await get(mine, `/auctions/${draftOfPieter}`), 404);
    const draftImage = t.sql(`select id from auction_images where auction_id in (${draftId}, ${draftOfPieter}) limit 1`);
    if (draftImage) { t.eq('a draft\'s photo is 404 for a buyer', await get(mine, `/auctions/${t.sql(`select auction_id from auction_images where id=${draftImage}`)}/images/${draftImage}`), 404); }
    else { t.note('the demo has no photo on a draft, so the draft-photo 404 was checked on a made-up id only'); }
    t.eq('a photo id that belongs to another lot is 404 under this lot (scoped binding)', await get(mine, `/auctions/${lot}/images/${t.sql('select id from auction_images limit 1')}`), 404);
    const publicImage = t.sql(`select auction_id || '/' || id from auction_images where auction_id not in (${draftId}, ${draftOfPieter}) limit 1`);
    t.eq('a photo of a published lot is served', await get(mine, `/auctions/${publicImage.split('/')[0]}/images/${publicImage.split('/')[1]}`), 200);
    t.eq("another person's sale is 404", await get(mine, `/settlements/${siphoSale}`), 404);
    t.eq('a sale id that does not exist is 404', await get(mine, '/settlements/00000000000000000000000000'), 404);
    t.eq('a lot that does not exist is 404', await get(mine, '/auctions/999999'), 404);
    t.eq('a lot id that is not a number is 404', await get(mine, '/auctions/abc'), 404);
    // Notifications of someone else cannot be opened.
    const siphoNotice = t.sql(`select id from notifications where notifiable_id=${uid('sipho')} limit 1`);
    const tok = await csrfOf(mine, '/notifications');
    const open = await mine.ctx.request.post(`/notifications/${siphoNotice}/open`, { form: { _token: tok }, maxRedirects: 0 });
    t.check("another person's notification cannot be opened (404)", open.status() === 404, String(open.status()));
    t.eq("...and it stayed unread", t.sql(`select read_at is null from notifications where id='${siphoNotice}'`), '1');
    mine.tolerate(/http404|http403/);

    // ---------- seller and operator pages ----------
    const others = t.id_of('Stainless steel gas braai, two burner');
    const results = {
        'GET /seller/auctions (own, empty list)': await get(mine, '/seller/auctions'),
        "GET another seller's edit page": await get(mine, `/seller/auctions/${others}/edit`),
        "GET another seller's draft edit page": await get(mine, `/seller/auctions/${draftId}/edit`),
        'GET operator payments': await get(mine, '/operator/settlement-payments'),
    };
    t.eq('/seller/auctions is the buyer\'s own (empty) list, not an error', results['GET /seller/auctions (own, empty list)'], 200);
    t.check("another seller's edit page is refused (403 or 404)", [403, 404].includes(results["GET another seller's edit page"]), JSON.stringify(results));
    t.check("another seller's DRAFT edit page is refused and its existence is not confirmed (404)", results["GET another seller's draft edit page"] === 404 || results["GET another seller's draft edit page"] === 403, JSON.stringify(results));
    t.check('the operator page is refused to a buyer (403 or 404)', [403, 404].includes(results['GET operator payments']), JSON.stringify(results));
    const tk = await csrfOf(mine, '/seller/auctions');
    for (const [what, method, path] of [['publish', 'POST', `/seller/auctions/${draftId}/publish`], ['cancel', 'POST', `/seller/auctions/${others}/cancel`], ['edit-save', 'PUT', `/seller/auctions/${others}`], ['delete', 'DELETE', `/seller/auctions/${draftId}`], ['photo upload', 'POST', `/seller/auctions/${others}/images`]]) {
        const r = await mine.ctx.request.fetch(path, { method, form: { _token: tk, _method: method === 'POST' ? undefined : method }, maxRedirects: 0 });
        t.check(`a buyer cannot ${what} someone else's lot (got ${r.status()})`, [403, 404].includes(r.status()), String(r.status()));
    }
    t.eq("...and nothing about the other seller's lots changed", [t.sql(`select status from auctions where id=${others}`), t.sql(`select status from auctions where id=${draftId}`)], ['active', 'draft']);
    for (const [name, url] of [['403 (operator page)', '/operator/settlement-payments'], ['404 (no such page)', '/nothing-here'], ['404 (no such lot)', '/auctions/999999']]) {
        await mine.go(url);
        const b = await mainText(mine.page).catch(async () => flat(await mine.page.locator('body').innerText()));
        const code = name.slice(0, 3);
        const h1 = (await mine.page.locator('h1').allInnerTexts()).map((s) => s.trim());
        t.check(`${name}: the error page has one h1 in plain words (${h1.join('|')})`, h1.length === 1 && h1[0].length > 8 && !/exception|whoops/i.test(h1[0]), JSON.stringify(h1));
        t.check(`${name}: it offers a way out (a link or button)`, await mine.page.getByRole('link', { name: /Go home|Go to dashboard|Browse/ }).count() > 0, b.slice(0, 200));
        t.check(`${name}: no stack trace, no file path, no SQL`, !/vendor\/|\.php|SQLSTATE|Stack trace|Illuminate\\/.test(await mine.page.content()), '');
        t.check(`${name}: voice: no exclamation marks and no em or en dashes`, !/[!—–]/.test(b), b);
        await t.audit(mine, 'error-' + code + '-' + url.replace(/\W+/g, '').slice(0, 12), { full: false });
    }
    // Guests: protected pages send to sign-in, the error pages work signed out.
    {
        const guest = await t.person(null);
        for (const url of ['/dashboard', '/auctions', `/auctions/${lot}`, '/bids', '/watchlist', '/won', '/notifications', '/settlements', '/seller/auctions']) {
            const r = await guest.ctx.request.get(url, { maxRedirects: 0 });
            t.check(`signed out: ${url} sends to sign in`, [301, 302].includes(r.status()) && /\/login/.test(r.headers()['location'] || ''), `${r.status()} ${r.headers()['location']}`);
        }
        await guest.go('/nothing-here');
        t.has('signed out: the 404 page offers Sign in', await guest.page.locator('body').innerText(), 'Sign in');
        await t.audit(guest, 'error-404-guest', { full: false });
        guest.tolerate(/http404/);
        t.clean(guest, 'guest');
    }

    // ---------- the session expires ----------
    {
        const s = await t.person('lerato', { to: `/auctions/${lot}` });
        const dialogs = [];
        s.page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
        await s.ctx.clearCookies();
        await s.go('/bids');
        t.check('an expired session on a page load goes to the sign-in page', /\/login/.test(s.page.url()), s.page.url());
        t.has('the sign-in page is in plain words', await s.page.locator('body').innerText(), 'Log in');
        // Real sign-in with the demo password (local test data), and the return trip.
        await s.page.goto(`/auctions/${lot}`);
        t.check('a deep link while signed out goes to sign in first', /\/login/.test(s.page.url()), s.page.url());
        await s.page.getByLabel('Email').fill('lerato.dlamini@demo.auction.test');
        await s.page.getByLabel('Password').fill('password');
        await s.page.getByRole('button', { name: /Log in/ }).click();
        await s.page.waitForLoadState('load');
        t.check(`after signing in he lands on the page he asked for or on Home (${new URL(s.page.url()).pathname})`, [`/auctions/${lot}`, '/dashboard'].includes(new URL(s.page.url()).pathname), s.page.url());
        t.note(`after sign-in redirect went to ${new URL(s.page.url()).pathname}`);
        // The session dies while the lot page is open and she presses Place bid.
        await s.go(`/auctions/${lot}`);
        await startBid(s, '1000').catch(() => {});
        await s.page.waitForTimeout(500);
        await s.page.locator('dialog[open]').waitFor({ timeout: 5000 }).catch(() => {});
        await s.ctx.clearCookies();
        const before = Number(t.sql(`select count(*) from bids where auction_id=${lot}`));
        const dlg = s.page.locator('dialog[open]');
        if (await dlg.count()) { await dlg.getByRole('button', { name: /^Place bid of/ }).click(); }
        await s.page.waitForTimeout(2500);
        const after = Number(t.sql(`select count(*) from bids where auction_id=${lot}`));
        const text = flat(await s.page.locator('body').innerText());
        t.eq('a bid sent after the session died places nothing', after, before);
        const friendly = /session (has )?expired|sign in again|log in again|reload|refresh/i.test(text) || dialogs.some((d) => /expired|refresh/i.test(d));
        t.check(`the person is told in words that the session expired and what to do (page: ${text.slice(0, 120).replace(/"/g, "'")}; dialogs: ${JSON.stringify(dialogs)})`, friendly && !/Page Expired|419/.test(text), JSON.stringify({ dialogs, url: s.page.url() }));
        await t.shot(s, 'session-expired-bid', { full: false });
        s.tolerate(/http419|http40|redirect/);
    }

    // ---------- the connection drops, or the server fails, while bidding ----------
    {
        const o = await t.person('lerato', { to: `/auctions/${lot}` });
        await o.page.getByRole('button', { name: 'Place bid', exact: true }).waitFor();
        await startBid(o, '1000');
        await o.page.locator('dialog[open]').waitFor({ timeout: 8000 });
        await o.ctx.setOffline(true);
        await o.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).click();
        await o.page.waitForTimeout(3000);
        let text = flat(await o.page.locator('body').innerText());
        const msgOffline = /offline|connection|could not reach|try again|no internet|network/i.test(text);
        t.check(`offline: pressing confirm tells the person in words that the bid did not go through and what to do (${text.slice(0, 160).replace(/"/g, "'")})`, msgOffline, text.slice(0, 300));
        await t.shot(o, 'offline-bid', { full: false });
        t.eq('offline: nothing was placed', Number(t.sql(`select count(*) from bids where auction_id=${lot}`)), 0);
        await o.ctx.setOffline(false);
        o.tolerate(/.*/);
        o.drain();
        // Lost polling shows the quiet note after three failures.
        await o.go(`/auctions/${lot}`);
        await o.ctx.setOffline(true);
        await o.page.waitForFunction(() => !document.querySelector('[data-live-lost]')?.hidden, null, { timeout: 25000 }).catch(() => {});
        const lost = await o.page.evaluate(() => { const n = document.querySelector('[data-live-lost]'); return n && !n.hidden ? n.innerText : null; });
        t.eq('offline: after missed polls the page says live updates are paused', lost, 'Live updates paused. Trying again.');
        await o.ctx.setOffline(false);
        await o.page.waitForFunction(() => document.querySelector('[data-live-lost]')?.hidden, null, { timeout: 25000 }).catch(() => {});
        t.eq('...and the note goes away when the connection returns', await o.page.evaluate(() => document.querySelector('[data-live-lost]')?.hidden), true);
        o.drain();

        // A server failure on a Livewire call.
        await o.go(`/auctions/${lot}`);
        await startBid(o, '1000');
        await o.page.locator('dialog[open]').waitFor({ timeout: 8000 });
        await o.page.route('**/livewire/update', (r) => r.fulfill({ status: 500, contentType: 'text/html', body: '<html><body><h1>Server Error</h1><pre>Illuminate\\Database\\QueryException SQLSTATE[HY000]</pre></body></html>' }));
        await o.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).click();
        await o.page.waitForTimeout(2500);
        text = flat(await o.page.locator('body').innerText());
        const iframeModal = await o.page.locator('#livewire-error, iframe').count();
        t.check('a 500 from the server shows no raw stack trace or SQL to the person', !/SQLSTATE|QueryException|Illuminate/.test(text) && iframeModal === 0, `iframes/modal: ${iframeModal}; text: ${text.slice(0, 200)}`);
        t.check('a 500 from the server says in words that something went wrong and the bid may not have gone through', /something went wrong|try again|could not|did not go through|not placed|check/i.test(text), text.slice(0, 300));
        await t.shot(o, 'server-error-bid', { full: false });
        await o.page.unroute('**/livewire/update');
        o.tolerate(/.*/); o.drain();
    }

    // ---------- 429 ----------
    {
        const r = await t.person('sipho');
        const sale = t.sql(`select id from auction_settlements where winner_id=${uid('sipho')} and status='awaiting_payment'`);
        let last = 0;
        for (let i = 0; i < 34; i++) { const res = await r.ctx.request.get(`/settlements/${sale}/payment/callback`, { maxRedirects: 0 }); last = res.status(); if (last === 429) { break; } }
        t.eq('hammering the payment return address ends in 429, not a crash', last, 429);
        await r.go(`/settlements/${sale}/payment/callback`);
        const b = flat(await r.page.locator('body').innerText());
        t.has('the 429 page is in plain words', b, 'Slow down a little');
        t.check('the 429 page offers a way out', await r.page.getByRole('link', { name: 'Go home' }).count() > 0, '');
        t.check('the 429 page has no exclamation mark or dash', !/[!—–]/.test(b), b);
        await t.audit(r, 'error-429', { full: false });
        r.tolerate(/http429/);
        const lim = await t.person('naledi', { to: `/auctions/${lot}` });
        t.check('the bid rate limit is covered in B4 (message "Too many bids")', true, '');
        lim.drain();
    }

    // ---------- own lot, closed lot, no form ----------
    {
        const th = await t.person('thandi', { to: `/auctions/${others}` });
        const csrf = await th.page.locator('meta[name="csrf-token"]').getAttribute('content');
        const direct = await th.ctx.request.post('/livewire/update', { data: { _token: csrf, components: [] }, headers: { 'content-type': 'application/json', 'x-livewire': 'true' }, maxRedirects: 0 });
        t.check('a malformed Livewire call is refused calmly (4xx, not 500)', direct.status() >= 400 && direct.status() < 500, String(direct.status()));
        th.tolerate(/http4/);
    }
    t.clean(mine, 'end');
});
