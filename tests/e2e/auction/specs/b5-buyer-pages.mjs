// B5. Buyer pages: My bids, Watching and Won show the person's own rows and nothing else; Stop watching works with and without JavaScript.
import { runSpec } from '../lib/harness.mjs';
import { flat, mainText, money, ts } from '../lib/common.mjs';

export default () => runSpec('b5', 'Buyer pages: My bids, Watching, Won', async (t) => {
    t.sql(`update auctions set ends_at='${ts(50 * 60e3)}' where title like 'Wool rug%'`);
    t.sql(`update auctions set ends_at='${ts(45 * 60e3)}' where title like 'Stainless steel gas braai%'`);
    const now = ts(0);
    const uid = (w) => t.user_id(w);
    const titles = (q) => t.sql(q).split('\n').filter(Boolean);
    const rowsOf = async (p, sel) => p.page.locator(`${sel} tbody tr`).evaluateAll((trs) => trs.map((tr) => tr.querySelector('.dc-lotname')?.innerText.trim()));

    // ---------------- My bids ----------------
    for (const who of ['lerato', 'sipho', 'aisha', 'johan', 'zanele']) {
        const p = await t.person(who, { to: '/bids' });
        const body = await mainText(p.page);
        const id = uid(who);
        const mine = titles(`select a.title from auctions a where a.status != 'draft' and exists (select 1 from bids b where b.auction_id=a.id and b.bidder_id=${id}) order by a.title`);
        // Every lot named on the page, across the four groups (the ended group is paginated).
        const shown = [];
        for (let n = 1; n < 6; n++) {
            await p.go(`/bids?page=${n}`);
            const all = await p.page.locator('.dc-lotname').allInnerTexts();
            shown.push(...all.map((s) => s.trim()));
            if (!(await p.page.locator('a[rel="next"]').count())) { break; }
        }
        t.eq(`${who}: My bids lists exactly the lots this person bid on, no one else's`, [...new Set(shown)].sort(), mine);
        t.eq(`${who}: no lot appears twice`, shown.length, new Set(shown).size);
        await p.go('/bids');
        if (mine.length === 0) { t.has(`${who}: nothing bid yet says so with a way forward`, body, 'You have not bid on anything yet'); }
        const open = (q) => titles(`select a.title from auctions a where a.status='active' and a.starts_at <= '${now}' and a.ends_at > '${now}' and exists (select 1 from bids b where b.auction_id=a.id and b.bidder_id=${id}) and ${q} order by a.title`);
        const leading = open(`exists (select 1 from bids b where b.auction_id=a.id and b.bidder_id=${id} and b.is_winning=1)`);
        const outbid = open(`not exists (select 1 from bids b where b.auction_id=a.id and b.bidder_id=${id} and b.is_winning=1)`);
        if (mine.length) {
            t.eq(`${who}: the "Outbid" group holds exactly the open lots they lost the lead on`, (await rowsOf(p, 'section#outbid').catch(() => [])).filter(Boolean).sort(), outbid);
            t.eq(`${who}: the "You lead" group holds exactly the open lots they lead`, (await rowsOf(p, 'section#leading').catch(() => [])).filter(Boolean).sort(), leading);
        }
        const waiting = titles(`select a.title from auctions a where a.status='pending_review' and exists (select 1 from bids b where b.auction_id=a.id and b.bidder_id=${id} and b.is_winning=1) order by a.title`);
        if (waiting.length) { t.has(`${who}: lots waiting on a seller are said to be`, body, 'Waiting on the seller'); }
        t.clean(p, 'bids');
        if (['lerato', 'sipho'].includes(who)) { await t.audit(p, `bids-${who}`); }
    }

    // ---------------- Watching ----------------
    {
        const p = await t.person('lerato', { to: '/watchlist' });
        const id = uid('lerato');
        const expected = titles(`select a.title from watchlists w join auctions a on a.id=w.auction_id where w.user_id=${id} order by a.title`);
        const shown = await p.page.locator('.ds-lot__title').allInnerTexts();
        t.eq('lerato: Watching shows exactly her watched lots', shown.map((s) => s.trim()).sort(), expected);
        t.has('lerato: the count line says how many are watched', await mainText(p.page), `${expected.length} lots watched`);
        // Order: open lots, soonest close first.
        const ends = await Promise.all(shown.map((s) => t.sql(`select status || '|' || ends_at from auctions where title='${s.trim().replace(/'/g, "''")}'`)));
        const openEnds = ends.filter((e) => e.startsWith('active|')).map((e) => e.split('|')[1]);
        t.eq('lerato: open watched lots come first, the one closing soonest at the top', openEnds, [...openEnds].sort());
        t.check('lerato: open lots precede closed ones', ends.findIndex((e) => !e.startsWith('active|')) === -1 || ends.slice(ends.findIndex((e) => !e.startsWith('active|'))).every((e) => !e.startsWith('active|')), ends.join(' ; '));
        t.eq('lerato: every card has a visible "Stop watching" control', await p.page.getByRole('button', { name: /Stop watching/ }).count(), expected.length);
        await t.audit(p, 'watchlist-lerato');

        // With JavaScript: press Stop watching on the first lot.
        const first = shown[0].trim();
        const firstId = t.id_of(first);
        await Promise.all([p.page.waitForNavigation(), p.page.getByRole('button', { name: new RegExp(`Stop watching\\s+${first.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`) }).click()]);
        t.has('Stop watching: says what it did', await mainText(p.page), `Stopped watching "${first}"`);
        t.eq('Stop watching: the row is gone from the database', Number(t.sql(`select count(*) from watchlists where user_id=${id} and auction_id=${firstId}`)), 0);
        t.eq('Stop watching: other people\'s watches are untouched', Number(t.sql(`select count(*) from watchlists where user_id != ${id}`)), 8);
        t.eq('Stop watching: the page now lists one fewer', (await p.page.locator('.ds-lot__title').count()), expected.length - 1);
        // Hostile: removing a lot that someone else watches, and one nobody watches, is harmless.
        const siphoOnly = t.sql(`select auction_id from watchlists where user_id=${uid('sipho')} and auction_id not in (select auction_id from watchlists where user_id=${id}) limit 1`);
        const tokenPage = await p.page.locator('input[name="_token"]').first().getAttribute('value');
        const r1 = await p.ctx.request.post(`/watchlist/${siphoOnly}`, { form: { _method: 'DELETE', _token: tokenPage }, maxRedirects: 0 });
        t.check('deleting a watch on a lot she does not watch redirects calmly', [302, 303].includes(r1.status()), String(r1.status()));
        t.eq("...and sipho's watch is still there", Number(t.sql(`select count(*) from watchlists where user_id=${uid('sipho')} and auction_id=${siphoOnly}`)), 1);
        const draft = t.id_of('Oak dining table, seats eight');
        const r2 = await p.ctx.request.post(`/watchlist/${draft}`, { form: { _method: 'DELETE', _token: tokenPage }, maxRedirects: 0 });
        t.eq("another seller's draft answers 404 (its title is not leaked)", r2.status(), 404);
        t.clean(p, 'watchlist');
    }
    {
        // Without JavaScript the same form works.
        const p = await t.person('sipho', { javaScript: false });
        p.tolerate(/requestfailed .*\/build\/assets\/.* csp/); // Chrome blocks scripts with a CSP of its own when JavaScript is switched off
        await p.go('/watchlist');
        const before = await p.page.locator('.ds-lot__title').count();
        const name = (await p.page.locator('.ds-lot__title').first().innerText()).trim();
        await Promise.all([p.page.waitForNavigation(), p.page.getByRole('button', { name: /Stop watching/ }).first().click()]);
        t.has('no JavaScript: Stop watching works and says so', await mainText(p.page), `Stopped watching "${name}"`);
        t.eq('no JavaScript: one fewer lot', await p.page.locator('.ds-lot__title').count(), before - 1);
        t.eq('no JavaScript: the database agrees', Number(t.sql(`select count(*) from watchlists where user_id=${uid('sipho')}`)), before - 1);
        t.clean(p, 'no-js watchlist');
    }
    {
        const p = await t.person('zanele', { to: '/watchlist' });
        t.has('zanele: empty watchlist says so and offers live lots', await mainText(p.page), 'You are not watching any lots');
        await t.audit(p, 'watchlist-empty', { full: false });
        t.clean(p, 'watchlist empty');
    }

    // ---------------- Won ----------------
    for (const who of ['sipho', 'johan', 'aisha', 'lerato', 'zanele']) {
        const id = uid(who);
        const p = await t.person(who, { to: '/won' });
        const body = await mainText(p.page);
        const sales = t.sql(`select a.title, s.status, s.amount_cents, s.id from auction_settlements s join auctions a on a.id=s.auction_id where s.winner_id=${id} order by a.title`).split('\n').filter(Boolean).map((r) => r.split('\t'));
        const noSale = titles(`select a.title from auctions a where a.status='ended' and exists (select 1 from bids b where b.auction_id=a.id and b.is_winning=1 and b.bidder_id=${id}) and not exists (select 1 from auction_settlements s where s.auction_id=a.id) and not exists (select 1 from reserve_not_met_proposals r where r.auction_id=a.id and r.status='rejected')`);
        const shown = (await p.page.locator('.dc-lotname').allInnerTexts()).map((s) => s.trim()).sort();
        t.eq(`${who}: Won lists exactly the lots this person won (and only theirs)`, shown, [...sales.map((s) => s[0]), ...noSale].sort());
        if (!sales.length) { t.has(`${who}: nothing won says so`, body, 'You have not won a lot yet'); }
        const owed = sales.filter((s) => ['awaiting_payment', 'payment_overdue'].includes(s[1]));
        if (owed.length) {
            t.has(`${who}: banner counts the sales waiting`, body, `${owed.length} ${owed.length === 1 ? 'sale waits' : 'sales wait'} for payment`);
            t.has(`${who}: banner totals what is owed`, body, `You owe ${money(owed.reduce((a, s) => a + Number(s[2]), 0) / 100)} in total`);
        } else { t.lacks(`${who}: no payment banner when nothing is owed`, body, 'for payment'); }
        for (const [title, status, cents, sid] of sales) {
            const row = p.page.locator('tr', { hasText: title }).first();
            const text = flat(await row.innerText());
            t.has(`${who}: "${title}" shows ${money(Number(cents) / 100)}`, text, money(Number(cents) / 100));
            const link = await row.locator('a.ds-btn').getAttribute('href');
            t.check(`${who}: "${title}" (${status}) links to its sale page`, new URL(link, 'http://x').pathname === `/settlements/${sid}`, link);
            const word = { awaiting_payment: 'Awaiting payment', payment_overdue: 'Payment overdue', paid: 'Paid', cancelled: 'Cancelled' }[status];
            t.has(`${who}: "${title}" says its payment state in words (${word})`, text, word);
            t.has(`${who}: "${title}" action matches state`, text, ['awaiting_payment', 'payment_overdue'].includes(status) ? 'Pay now' : 'View sale');
        }
        // Following a row really opens the sale of that lot.
        if (sales.length) {
            const [title, , , sid] = sales[0];
            await p.page.locator('tr', { hasText: title }).first().locator('a.ds-btn').click();
            await p.page.waitForLoadState('load');
            t.eq(`${who}: following the first row opens its sale page`, new URL(p.page.url()).pathname, `/settlements/${sid}`);
            t.has(`${who}: the sale page is about the same lot`, await mainText(p.page), title);
        }
        t.clean(p, 'won');
        if (['sipho', 'johan'].includes(who)) { await p.go('/won'); await t.audit(p, `won-${who}`); }
    }
    // Strangers: another person's sale is not even confirmed to exist.
    {
        const p = await t.person('lerato');
        const siphoSale = t.sql(`select id from auction_settlements where winner_id=${uid('sipho')} limit 1`);
        const r = await p.ctx.request.get(`/settlements/${siphoSale}`);
        t.eq("lerato asking for sipho's sale gets 404", r.status(), 404);
        const r2 = await p.ctx.request.get('/won');
        t.eq('/won for lerato answers 200 and never the others', r2.status(), 200);
        p.tolerate(/http404/);
        t.clean(p, 'strangers');
    }
});
