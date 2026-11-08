// B2. Discovery: browse, search, category chips, state filters, sorts, pagination, clear filters, empty states, labels and viewer cues.
import { runSpec } from '../lib/harness.mjs';
import { flat, mainText, money, ts } from '../lib/common.mjs';

export default () => runSpec('b2', 'Discovery: browse, search, filters, sorts', async (t) => {
    // Keep the two lots that were set to close within minutes open for the length of the run, still inside "closing soon".
    t.sql(`update auctions set ends_at='${ts(50 * 60e3)}' where title like 'Wool rug%'`);
    t.sql(`update auctions set ends_at='${ts(45 * 60e3)}' where title like 'Stainless steel gas braai%'`);

    const nowUtc = ts(0);
    const plus60 = ts(60 * 60e3);
    // The independent truth for each tab, straight from the tables (not from the app's own query).
    const where = {
        all: `status != 'draft'`,
        live: `status='active' and starts_at <= '${nowUtc}' and ends_at > '${nowUtc}'`,
        ending: `status='active' and starts_at <= '${nowUtc}' and ends_at > '${nowUtc}' and ends_at <= '${plus60}'`,
        scheduled: `status='active' and starts_at > '${nowUtc}'`,
        pending: `status='pending_review'`,
        sold: `status='ended' and exists (select 1 from bids b where b.auction_id=auctions.id and b.is_winning=1) and not exists (select 1 from reserve_not_met_proposals r where r.auction_id=auctions.id and r.status='rejected')`,
        unsold: `(status='ended' and (not exists (select 1 from bids b where b.auction_id=auctions.id and b.is_winning=1) or exists (select 1 from reserve_not_met_proposals r where r.auction_id=auctions.id and r.status='rejected'))) or (status='active' and ends_at <= '${nowUtc}')`,
        cancelled: `status='cancelled'`,
    };
    const dbTitles = (w, order = 'id') => t.sql(`select title from auctions where ${w} order by ${order}`).split('\n').filter(Boolean);
    const dbCount = (w) => Number(t.sql(`select count(*) from auctions where ${w}`));
    t.note(`demo world: ${Object.entries(where).map(([k, w]) => `${k}=${dbCount(w)}`).join(', ')}`);

    const p = await t.person('lerato', { to: '/auctions' });
    const page = p.page;

    const cards = async () => page.locator('.ds-catalogue > li').evaluateAll((lis) => lis.map((li) => ({
        title: li.querySelector('.ds-lot__title')?.innerText.trim(),
        status: li.querySelector('.ds-status')?.getAttribute('data-state'),
        statusWord: li.querySelector('.ds-status')?.innerText.trim(),
        priceLabel: li.querySelector('.ds-lot__price-label')?.innerText.trim(),
        price: (li.querySelector('.ds-lot__price .ds-money')?.innerText || '').replace(/\s+/g, ''),
        bids: li.querySelector('.ds-lot__bids')?.innerText.trim() ?? null,
        cue: li.querySelector('.ds-lot__cue')?.innerText.trim() ?? null,
        watch: li.querySelector('.ds-lot__watch')?.innerText.trim() ?? null,
        text: li.innerText.replace(/\s+/g, ' ').trim(),
    })));
    const summary = async () => flat(await page.locator('.dc-resultbar__count').innerText());
    const total = async () => Number((await summary()).match(/^([\d,]+)/)?.[1].replace(/,/g, '') ?? NaN);
    const allTitles = async (qs) => {
        // Walk every page of a query and return all card titles in order.
        const out = [];
        for (let n = 1; n < 20; n++) {
            await p.go(`/auctions?${qs}${qs ? '&' : ''}page=${n}`);
            const c = await cards();
            if (!c.length) { break; }
            out.push(...c);
            if (!(await page.locator('a[rel="next"]').count())) { break; }
        }
        return out;
    };

    // ---------- default list ----------
    await p.go('/auctions');
    t.eq('h1 is Browse lots', (await page.locator('h1').allInnerTexts()).map((s) => s.trim()), ['Browse lots']);
    t.eq('title says Browse lots', (await page.title()).includes('Browse lots'), true);
    t.eq('default total equals every non-draft lot in the database', await total(), dbCount(where.all));
    const first = await cards();
    t.eq('first page has 12 cards (page size) when more exist', first.length, Math.min(12, dbCount(where.all)));
    t.check('drafts are never listed', !(await allTitles('')).some((c) => ['Vintage Kodak Brownie camera', 'Oak dining table, seats eight', 'Vinyl record collection, 40 records'].includes(c.title)), 'a draft title appeared');
    await t.audit(p, 'browse-default', { full: false });
    t.clean(p, 'browse default');

    // ---------- state tabs ----------
    for (const [state, word] of [['live', 'Live'], ['ending', 'Closing soon'], ['scheduled', 'Scheduled'], ['pending', 'Awaiting decision'], ['sold', 'Sold'], ['unsold', 'Not sold'], ['cancelled', 'Cancelled']]) {
        await p.go(`/auctions?state=${state}`);
        const tab = page.locator('[aria-label="Lot state"] a', { hasText: word }).first();
        const tabText = flat(await tab.innerText());
        const expected = dbCount(where[state]);
        t.eq(`state=${state}: the result line counts exactly the lots the database has in that state`, await total(), expected);
        t.has(`state=${state}: the tab "${word}" shows the same count`, tabText, String(expected));
        t.eq(`state=${state}: tab is marked as current`, await tab.getAttribute('aria-current'), 'page');
        const got = (await allTitles(`state=${state}`)).map((c) => c.title).sort();
        t.eq(`state=${state}: the same lots, none missing, none extra`, got, dbTitles(where[state]).sort());
    }

    // Awaiting-decision lots are NEVER badged Cancelled (the old bug), anywhere they can appear.
    {
        const pendingTitles = dbTitles(where.pending);
        let bad = [];
        for (const qs of ['', 'state=pending', 'sort=newest', 'sort=price_asc', 'sort=bids']) {
            for (const c of await allTitles(qs)) {
                if (pendingTitles.includes(c.title)) {
                    if (c.status !== 'pending' || !/Awaiting seller decision/.test(c.statusWord) || /Cancelled/.test(c.text)) { bad.push(`${qs || 'default'}: ${c.title} -> ${c.statusWord}`); }
                }
            }
        }
        t.eq('awaiting-decision lots carry "Awaiting seller decision" and never "Cancelled" in every ordering', bad, []);
        const cancelled = (await allTitles('state=cancelled')).every((c) => c.status === 'cancelled' && /Cancelled/.test(c.statusWord));
        t.eq('every lot on the Cancelled tab is badged Cancelled', cancelled, true);
        await p.go('/auctions?state=pending');
        await t.audit(p, 'browse-pending', { full: false });
    }

    // ---------- card labels: Starting bid vs Current bid, by state, checked against the database ----------
    {
        const all = await allTitles('');
        let wrong = [];
        for (const c of all) {
            const row = t.sql(`select status, starting_price, current_price, starts_at, ends_at, (select count(*) from bids where auction_id=auctions.id), exists (select 1 from reserve_not_met_proposals r where r.auction_id=auctions.id and r.status='rejected'), (select count(*) from bids b where b.auction_id=auctions.id and b.is_winning=1) from auctions where title='${c.title.replace(/'/g, "''")}'`).split('\t');
            const [status, start, cur, starts, ends, nb, rejected, winners] = row;
            const bids = Number(nb);
            let label, price;
            if (status === 'ended') { if (Number(winners) > 0 && rejected !== '1') { label = 'Sold for'; price = cur; } else { label = bids > 0 ? 'Highest bid' : 'No bids'; price = bids > 0 ? cur : start; } }
            else if (status === 'pending_review') { label = 'Highest bid'; price = cur; }
            else if (status === 'cancelled') { label = 'Starting bid'; price = start; }
            else if (starts > nowUtc) { label = 'Starting bid'; price = start; }
            else { label = bids > 0 ? 'Current bid' : 'Starting bid'; price = bids > 0 ? cur : start; }
            if (c.priceLabel !== label || c.price !== money(price)) { wrong.push(`${c.title}: shows "${c.priceLabel} ${c.price}", expected "${label} ${money(price)}"`); }
            const exBids = `${bids} ${bids === 1 ? 'bid' : 'bids'}`;
            if (c.bids !== exBids) { wrong.push(`${c.title}: bids "${c.bids}" expected "${exBids}"`); }
        }
        t.eq(`every card (${all.length}) shows the right label, price and bid count for its state`, wrong, []);
        t.check('a live lot with no bids says Starting bid (Bosch drill has bids; Land Rover parts lot has none)', all.some((c) => c.title.startsWith('Land Rover') && c.priceLabel === 'Starting bid') && all.some((c) => c.title.startsWith('Stainless steel') && c.priceLabel === 'Current bid'), JSON.stringify(all.filter((c) => /Land Rover|Stainless/.test(c.title)).map((c) => [c.title, c.priceLabel])));
        t.check('no card prints a reserve or the words "reserve price"', !all.some((c) => /reserve/i.test(c.text.replace(/Awaiting seller decision/i, ''))), all.filter((c) => /reserve/i.test(c.text)).map((c) => c.text).join(' | '));
    }

    // ---------- viewer cues ----------
    {
        const all = await allTitles('');
        const by = Object.fromEntries(all.map((c) => [c.title, c]));
        t.has('lerato leads the braai: card says You lead', by['Stainless steel gas braai, two burner']?.cue ?? '', 'You lead');
        t.has('lerato was outbid on the guitar: card says Outbid', by['Yamaha F310 acoustic guitar']?.cue ?? '', 'Outbid');
        t.eq('a lot lerato never bid on has no cue', by['Brass ship\'s lantern']?.cue, null);
        t.has('watched lot offers Stop watching', by['Wool rug, 2 m by 3 m']?.watch ?? '', 'Stop watching');
        t.has('unwatched lot offers Watch', by['Land Rover Defender parts lot']?.watch ?? '', 'Watch');
        const sipho = await t.person('sipho', { to: '/auctions' });
        const sc = await sipho.page.locator('.ds-catalogue > li').evaluateAll((lis) => lis.map((li) => [li.querySelector('.ds-lot__title')?.innerText.trim(), li.querySelector('.ds-lot__cue')?.innerText.trim() ?? null]));
        const sAll = [];
        for (let n = 1; n < 5; n++) { await sipho.go(`/auctions?page=${n}`); sAll.push(...await sipho.page.locator('.ds-catalogue > li').evaluateAll((lis) => lis.map((li) => [li.querySelector('.ds-lot__title')?.innerText.trim(), li.querySelector('.ds-lot__cue')?.innerText.trim() ?? null]))); }
        const m = Object.fromEntries(sAll);
        t.has('sipho won the laptop: card says You won', m['Dell Latitude laptop, i5, 16 GB'] ?? '', 'You won');
        t.has('sipho leads the guitar', m['Yamaha F310 acoustic guitar'] ?? '', 'You lead');
        t.has('sipho was outbid on the rug', m['Wool rug, 2 m by 3 m'] ?? '', 'Outbid');
        const th = await t.person('thandi', { to: '/auctions' });
        const tAll = [];
        for (let n = 1; n < 5; n++) { await th.go(`/auctions?page=${n}`); tAll.push(...await th.page.locator('.ds-catalogue > li').evaluateAll((lis) => lis.map((li) => ({ t: li.querySelector('.ds-lot__title')?.innerText.trim(), cue: li.querySelector('.ds-lot__cue')?.innerText.trim() ?? null, watch: li.querySelector('.ds-lot__watch') !== null })))); }
        const own = new Set(dbTitles(`seller_id=${t.user_id('thandi')} and status != 'draft'`));
        t.eq('thandi: every one of her own lots says "Your lot" and offers no Watch', tAll.filter((c) => own.has(c.t) && (!/Your lot/.test(c.cue ?? '') || c.watch)).map((c) => c.t), []);
        t.eq('thandi: lots that are not hers have no "Your lot"', tAll.filter((c) => !own.has(c.t) && /Your lot/.test(c.cue ?? '')).map((c) => c.t), []);
        t.clean(sipho, 'sipho browse'); t.clean(th, 'thandi browse');
    }

    // ---------- search ----------
    const searchCount = async (q) => { await p.go('/auctions?q=' + encodeURIComponent(q)); return { n: await total(), body: await mainText(page), status: 200 }; };
    for (const q of ['yamaha', 'YAMAHA', 'YaMaHa', '  yamaha  ']) {
        const r = await searchCount(q);
        t.eq(`search "${q}" finds the guitar regardless of case or padding`, r.n, 1);
    }
    t.eq('search ignores nothing it should find: "ship\'s" (apostrophe)', (await searchCount("ship's")).n, 1);
    t.eq('search "2 m by 3 m" (spaces, digits)', (await searchCount('2 m by 3 m')).n, 1);
    t.eq('search "100%" is literal, not a wildcard (matches nothing)', (await searchCount('100%')).n, 0);
    t.eq('search "%" alone matches nothing (no title has a percent sign)', (await searchCount('%')).n, 0);
    t.eq('search "_" matches nothing', (await searchCount('_')).n, 0);
    t.eq('search "\\" matches nothing and does not error', (await searchCount('\\')).n, 0);
    t.eq('search "Home & Furniture" style ampersand does not break', (await searchCount('R&D')).n, 0);
    {
        const r = await searchCount('<script>window.__xss=1</script>');
        t.eq('search with markup is shown as text, not run', await page.evaluate(() => window.__xss === undefined), true);
        t.eq('search with markup finds nothing', r.n, 0);
        t.has('the empty state offers a way out', r.body, 'No lots match');
        const r2 = await searchCount('😀 café ñ');
        t.eq('search with emoji and accents does not fail', r2.n, 0);
        const long = 'a'.repeat(400);
        const r3 = await searchCount(long);
        t.eq('a 400-character search is cut to 100 and does not fail', r3.n, 0);
        const res = await p.ctx.request.get('/auctions?q[]=x&state[]=live&sort[]=x&category[]=1&page[]=2');
        t.eq('array-shaped query values are ignored, not a 500', res.status(), 200);
        for (const bad of ['state=nonsense', 'sort=nonsense', 'category=abc', 'category=99999999999999999999', 'category=-1', 'page=abc', 'page=-5', 'page=0', 'page=9999']) {
            const rr = await p.ctx.request.get('/auctions?' + bad);
            t.eq(`hostile query "${bad}" answers 200`, rr.status(), 200);
        }
    }
    {
        const r = await searchCount('zzzz-nothing');
        t.has('empty search: result line says 0 lots matching', await summary(), '0 lots matching "zzzz-nothing"');
        t.has('empty search: states what is wrong', r.body, 'No lots match');
        const clear = page.getByRole('link', { name: 'Clear all filters' }).or(page.getByRole('link', { name: 'Clear all' })).first();
        await clear.click(); await page.waitForLoadState('load');
        t.eq('Clear all filters goes back to the whole list', [new URL(page.url()).search, await total()], ['', dbCount(where.all)]);
        t.clean(p, 'search');
        await p.go('/auctions?q=zzzz-nothing');
        await t.audit(p, 'browse-empty-search', { full: false });
    }

    // ---------- categories ----------
    {
        await p.go('/auctions');
        const chips = await page.locator('nav[aria-label="Category"] a').evaluateAll((as) => as.map((a) => ({ text: a.innerText.replace(/\s+/g, ' ').trim(), href: a.getAttribute('href') })));
        t.check('category chips are links with counts', chips.length >= 8 && chips.slice(1).every((c) => /\d$/.test(c.text)), JSON.stringify(chips));
        let mismatches = [];
        for (const c of chips.slice(1)) {
            const id = new URL(c.href, 'http://x').searchParams.get('category');
            const expected = dbCount(`status != 'draft' and category_id=${id}`);
            await p.go(c.href);
            const n = await total();
            const name = c.text.replace(/\s*\d+$/, '');
            if (n !== expected || !(await page.locator('.dc-active').innerText()).includes(name)) { mismatches.push(`${name}: ${n} vs ${expected}`); }
        }
        t.eq('every category chip lists exactly the lots in that category and shows as an active filter', mismatches, []);
        const cat = chips[1];
        await p.go(cat.href + '&state=live&q=a');
        t.check('category + state + search combine (all three active filters shown)', (await page.locator('.dc-active li').count()) >= 3, await page.locator('.dc-active').innerText());
        await page.getByRole('link', { name: 'Clear all' }).click(); await page.waitForLoadState('load');
        t.eq('Clear all removes category, state and search together', new URL(page.url()).search, '');
        await p.go(chips[1].href);
        await page.locator('.dc-active li a', { hasText: chips[1].text.replace(/\s*\d+$/, '') }).first().click(); await page.waitForLoadState('load');
        t.eq('removing one filter chip removes only it', [new URL(page.url()).search, await total()], ['', dbCount(where.all)]);
        t.clean(p, 'categories');
    }

    // ---------- sorts ----------
    {
        const sorted = async (sort, qs = '') => allTitles(`sort=${sort}${qs ? '&' + qs : ''}`);
        const num = (c) => Number(c.price.replace(/[R,]/g, ''));
        const idByTitle = Object.fromEntries(t.sql(`select title,id from auctions`).split('\n').map((r) => r.split('\t')));
        const asc = await sorted('price_asc', 'state=live');
        t.eq('price low to high (live): prices never go down', asc.every((c, i) => i === 0 || num(c) >= num(asc[i - 1])), true);
        const desc = await sorted('price_desc', 'state=live');
        t.eq('price high to low (live): prices never go up', desc.every((c, i) => i === 0 || num(c) <= num(desc[i - 1])), true);
        const newest = await sorted('newest');
        t.eq('newest: lot ids go down', newest.every((c, i) => i === 0 || Number(idByTitle[c.title]) < Number(idByTitle[newest[i - 1].title])), true);
        const bids = await sorted('bids');
        const nb = (c) => Number((c.bids || '0').split(' ')[0]);
        t.eq('most bids: bid counts never go up', bids.every((c, i) => i === 0 || nb(c) <= nb(bids[i - 1])), true);
        const ending = await sorted('ending');
        const rank = (c) => ({ live: 0, ending: 0, scheduled: 1, pending: 2, sold: 3, unsold: 3, cancelled: 4 }[c.status] ?? 9);
        t.eq('ending soonest: open lots, then scheduled, then awaiting decision, then closed, then cancelled', ending.every((c, i) => i === 0 || rank(c) >= rank(ending[i - 1])), true);
        const openOnes = ending.filter((c) => rank(c) === 0).map((c) => t.sql(`select ends_at from auctions where title='${c.title.replace(/'/g, "''")}'`));
        t.eq('ending soonest: among open lots the earliest close is first', openOnes.every((e, i) => i === 0 || e >= openOnes[i - 1]), true);
        t.eq('default order equals ending soonest', (await allTitles('')).map((c) => c.title), ending.map((c) => c.title));
        t.eq('every sort lists the same set of lots (none lost between pages)', [asc.length, desc.length].every((n) => n === dbCount(where.live)) && newest.length === dbCount(where.all) && bids.length === dbCount(where.all), true);
        // The control itself: choose a sort in the page and the list changes with no button press.
        await p.go('/auctions');
        await page.getByLabel('Sort by').selectOption('price_desc');
        await page.waitForURL(/sort=price_desc/, { timeout: 8000 }).catch(() => {});
        t.check('choosing a sort in the control applies it (URL carries sort=price_desc)', page.url().includes('sort=price_desc'), page.url());
        t.eq('...and the control still shows the choice', await page.getByLabel('Sort by').inputValue(), 'price_desc');
        t.clean(p, 'sorts');
    }

    // ---------- pagination ----------
    {
        await p.go('/auctions');
        const nextLink = page.locator('a[rel="next"]').first();
        t.check('page 1 offers Next', await nextLink.count() > 0, 'no next link');
        const p1 = (await cards()).map((c) => c.title);
        await nextLink.click(); await page.waitForLoadState('load');
        t.eq('Next goes to page=2', new URL(page.url()).searchParams.get('page'), '2');
        const p2 = (await cards()).map((c) => c.title);
        t.eq('page 2 has the remaining lots and none repeat', [p2.length, p2.some((x) => p1.includes(x))], [dbCount(where.all) - 12, false]);
        t.check('page 2 says where you are in words', /Page 2|2 of 2|13/.test(await mainText(page)), (await mainText(page)).slice(-300));
        await p.go('/auctions?state=live&q=a&sort=newest');
        const href = await page.locator('a[rel="next"]').first().getAttribute('href').catch(() => null);
        t.check('pagination keeps the filters in its links (or there is only one page)', href === null || (href.includes('state=live') && href.includes('sort=newest')), href);
        await p.go('/auctions?page=9999');
        t.check('a page far past the end is handled calmly (empty state or the last page, not an error)', (await mainText(page)).length > 20, await mainText(page));
        t.clean(p, 'pagination');
    }

    // ---------- empty states, by making the world empty on purpose ----------
    {
        t.sql(`update auctions set starts_at='${ts(-5 * 86400e3)}' where status='active' and starts_at > '${nowUtc}'`);
        await p.go('/auctions?state=scheduled');
        t.has('no scheduled lots: says so', await mainText(page), 'No lots are scheduled');
        t.eq('no scheduled lots: result line says 0', await total(), 0);
        t.sql(`update auctions set status='ended' where status='cancelled'`);
        await p.go('/auctions?state=cancelled');
        t.has('no cancelled lots: says so', await mainText(page), 'No lots were cancelled');
        t.sql(`update auctions set status='ended' where status='pending_review'`);
        await p.go('/auctions?state=pending');
        t.has('no awaiting-decision lots: says so', await mainText(page), 'No lots are waiting on a seller');
        t.sql(`update auctions set status='draft'`);
        await p.go('/auctions');
        const b = await mainText(page);
        t.has('no lots at all: says so', b, 'No lots yet');
        t.eq('no lots at all: result is 0', await total().catch(() => 'n/a'), 0);
        await t.audit(p, 'browse-nothing', { full: false });
        t.clean(p, 'empty states');
    }
});
