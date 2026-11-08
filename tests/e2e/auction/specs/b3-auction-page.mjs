// B3. The lot page for every status and every kind of viewer, judged against the database: outcome copy, no live words on lots that
// are not live, the reserve never printed, own lot has Manage and no Watch, bidder names masked, times in South African time.
import { runSpec, DEMO } from '../lib/harness.mjs';
import { flat, mainText, money, ts } from '../lib/common.mjs';

export default () => runSpec('b3', 'Lot page: every status, every viewer', async (t) => {
    // Lots meant to be live stay live for the run.
    t.sql(`update auctions set ends_at='${ts(3 * 3600e3)}' where title like 'Wool rug%'`);
    t.sql(`update auctions set ends_at='${ts(2 * 3600e3)}' where title like 'Stainless steel gas braai%'`);
    const now = ts(0);

    const users = t.sql('select id,name,email from users').split('\n').map((r) => r.split('\t'));
    const lots = t.sql(`select a.id, a.title, a.status, a.seller_id, a.starting_price, a.current_price, a.reserve_price, a.buy_now_price, a.starts_at, a.ends_at, a.snipe_extensions,
        (select count(*) from bids b where b.auction_id=a.id), (select bidder_id from bids b where b.auction_id=a.id and b.is_winning=1),
        (select amount from bids b where b.auction_id=a.id and b.is_winning=1), (select r.status from reserve_not_met_proposals r where r.auction_id=a.id),
        (select s.status from auction_settlements s where s.auction_id=a.id) from auctions a order by a.id`).split('\n').map((r) => r.split('\t'))
        .map(([id, title, status, seller, start, cur, reserve, bn, starts, ends, ext, nbids, winner, winAmt, proposal, sale]) => ({ id: +id, title, status, seller: +seller, start: +start, cur: +cur, reserve: reserve === '' ? null : +reserve, buyNow: bn === '' ? null : +bn, starts, ends, ext: +ext, nbids: +nbids, winner: winner === '' ? null : +winner, winAmt: winAmt === '' ? null : +winAmt, proposal: proposal || null, sale: sale || null }));
    const bidders = (lotId) => new Set(t.sql(`select distinct bidder_id from bids where auction_id=${lotId}`).split('\n').filter(Boolean).map(Number));

    const lotState = (l) => {
        if (l.status === 'draft') { return 'draft'; }
        if (l.status === 'cancelled') { return 'cancelled'; }
        if (l.status === 'pending_review') { return 'pending'; }
        if (l.status === 'ended') { return l.winner !== null && l.proposal !== 'rejected' ? 'sold' : 'unsold'; }
        if (l.starts > now) { return 'scheduled'; }
        if (l.ends <= now) { return 'closing'; }
        return 'live';
    };
    const buyNowSale = (l) => l.buyNow !== null && l.winAmt === l.buyNow;

    // What the page must say, as facts about the viewer. Mirrors the product's copy rules in DESIGN.md, derived only from database rows.
    const expectation = (l, uid) => {
        const st = lotState(l);
        const mine = bidders(l.id).has(uid);
        const leads = l.winner === uid;
        const seller = l.seller === uid;
        const role = seller ? 'seller' : !mine ? 'visitor' : st === 'live' ? (leads ? 'leader' : 'outbid') : st === 'sold' ? (leads ? 'winner' : 'loser') : st === 'pending' ? (leads ? 'leader' : 'outbid') : (leads ? 'leader' : 'loser');
        let headline = null;
        if (st === 'live') { headline = { seller: 'This is your lot', leader: 'You are leading', outbid: 'You were outbid' }[role] ?? null; }
        else if (st === 'scheduled') { headline = seller ? `Your lot opens on` : 'Not open yet'; }
        else if (st === 'sold') {
            if (l.sale === 'cancelled' && (role === 'winner' || role === 'seller')) { headline = 'Sale cancelled'; } // only the two parties can see the sale; everyone else sees "Sold"
            else { const bn = buyNowSale(l); headline = { winner: bn ? 'You bought this lot with Buy Now' : 'You won this lot', loser: 'You did not win this lot', seller: bn ? 'Sold with Buy Now' : 'Sold', visitor: 'Sold' }[role]; }
        } else if (st === 'unsold') {
            headline = l.nbids === 0 ? 'Not sold' : l.proposal === 'rejected' ? ({ seller: 'You ended this lot without a sale', leader: 'The seller did not accept your bid' }[role] ?? 'Not sold') : 'Not sold';
        } else if (st === 'pending') { headline = role === 'seller' ? 'This lot ended below your reserve' : 'The seller is deciding'; }
        else if (st === 'cancelled') { headline = role === 'seller' ? 'You cancelled this lot' : 'Cancelled'; }
        const badge = { live: l.ends <= ts(60 * 60e3) ? 'ending' : 'live', scheduled: 'scheduled', sold: 'sold', unsold: 'unsold', pending: 'pending', cancelled: 'cancelled', closing: 'unsold', draft: 'draft' }[st];
        const priceLabel = { sold: 'Sold for', pending: 'Highest bid', unsold: l.nbids ? 'Highest bid' : 'Starting bid', cancelled: l.nbids ? 'Last bid' : 'Starting bid', scheduled: 'Starting bid', draft: 'Starting bid' }[st] ?? (l.nbids ? 'Current bid' : 'Starting bid');
        return { st, role, headline, badge, priceLabel, live: st === 'live' };
    };

    const viewers = ['thandi', 'pieter', 'lerato', 'sipho', 'aisha', 'johan', 'zanele'];
    const people = {};
    for (const v of viewers) { people[v] = await t.person(v); }
    const visible = lots.filter((l) => l.status !== 'draft');
    const reserveHits = [];
    const nameHits = [];
    const utcHits = [];
    const voiceHits = [];
    const liveWordsOnDeadLots = [];
    const wrong = [];
    let pages = 0;

    for (const l of visible) {
        const html = await (await people.zanele.ctx.request.get(`/auctions/${l.id}`)).text();
        // The reserve is never printed, in text or in the markup (including Livewire's snapshot), for anyone.
        if (l.reserve !== null) {
            const forms = [String(l.reserve), l.reserve.toLocaleString('en-US'), l.reserve.toFixed(2), l.reserve.toLocaleString('en-US', { minimumFractionDigits: 2 })];
            const found = forms.filter((f) => new RegExp(`(?<![\\d.,])${f.replace(/\./g, '\\.')}(?![\\d])`).test(html.replace(/\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(\.\d+)?Z?/g, '')));
            // The starting, current or Buy Now price of the same lot could coincide with the reserve; that is not a leak.
            const innocent = [l.start, l.cur, l.buyNow, ...t.sql(`select amount from bids where auction_id=${l.id}`).split('\n').filter(Boolean).map(Number)].filter((x) => x !== null).map(String); // prices and bids printed on purpose
            const real = found.filter((f) => !innocent.some((i) => Number(f.replace(/,/g, '')) === Number(i)));
            if (real.length) { reserveHits.push(`${l.title}: ${real.join(',')}`); }
        }
    }
    t.eq('the reserve price never appears in the page source of any lot (text, markup, Livewire snapshot), seen as a plain visitor', reserveHits, []);

    for (const v of viewers) {
        const p = people[v];
        const uid = t.user_id(v);
        for (const l of visible) {
            const ex = expectation(l, uid);
            await p.go(`/auctions/${l.id}`);
            pages++;
            const page = p.page;
            const body = await mainText(page);
            const html = await page.content();
            const tag = `${v} on "${l.title}" (${ex.st}, ${ex.role})`;
            const h1 = (await page.locator('h1').allInnerTexts()).map((s) => s.trim());
            if (h1.join('|') !== l.title || !(await page.title()).includes(l.title)) { wrong.push(`${tag}: h1/title ${JSON.stringify(h1)} / ${await page.title()}`); }
            const heading = await page.evaluate(() => document.querySelector('.lot-outcome__title')?.innerText ?? '');
            if (ex.headline === null) { if (heading) { wrong.push(`${tag}: expected no outcome box, got "${heading}"`); } }
            else if (!heading.startsWith(ex.headline)) { wrong.push(`${tag}: outcome "${heading}" expected "${ex.headline}"`); }
            const badge = await page.evaluate(() => document.querySelector('.lot-bid__tags .ds-status')?.getAttribute('data-state'));
            if (badge !== ex.badge) { wrong.push(`${tag}: badge ${badge} expected ${ex.badge}`); }
            const label = flat(await page.evaluate(() => document.querySelector('.lot-price__label span')?.innerText ?? ''));
            if (label !== ex.priceLabel) { wrong.push(`${tag}: price label "${label}" expected "${ex.priceLabel}"`); }
            const price = flat(await page.evaluate(() => document.querySelector('.lot-price__amount')?.innerText ?? ''));
            const expPrice = ex.priceLabel === 'Starting bid' ? l.start : l.cur;
            if (price !== money(expPrice)) { wrong.push(`${tag}: price ${price} expected ${money(expPrice)}`); }

            const hasForm = await page.locator('input[name="bidAmount"]').count();
            const canBid = ex.st === 'live' && ex.role !== 'seller' && ex.role !== 'leader';
            if (!!hasForm !== canBid) { wrong.push(`${tag}: bid form present=${!!hasForm} expected ${canBid}`); }
            const buyNowShown = await page.getByRole('button', { name: 'Buy now', exact: true }).count();
            const canBuy = canBid || (ex.st === 'live' && ex.role === 'leader');
            if (!ex.live && buyNowShown) { wrong.push(`${tag}: Buy now offered on a lot that is not live`); }
            if (ex.live && ex.role === 'seller' && buyNowShown) { wrong.push(`${tag}: Buy now offered to its own seller`); }

            if (!ex.live) {
                const live = [/Closes in/, /Place bid/, /You were outbid/, /You are leading/, /Be the first/, /Closing now/, /Opening bid is at least/, /Leading\b/].filter((re) => re.test(body) && !(re.source === 'You were outbid' && false));
                if (live.length && ex.st !== 'closing') { liveWordsOnDeadLots.push(`${tag}: ${live.map(String).join(' ')}`); }
                if (ex.st === 'scheduled' && /Current bid/.test(body)) { liveWordsOnDeadLots.push(`${tag}: Current bid on a scheduled lot`); }
                if (ex.st === 'scheduled' && /Reserve not yet met|reserve is not met/i.test(body)) { liveWordsOnDeadLots.push(`${tag}: reserve status on a scheduled lot`); }
                if (ex.st === 'cancelled' && /This auction has ended/.test(body)) { liveWordsOnDeadLots.push(`${tag}: ended copy on a cancelled lot`); }
            }
            // Own lot: Manage (not Watch), and no bidding.
            const watchBtn = await page.getByRole('button', { name: /^(Watch|Watching)/ }).count();
            if (ex.role === 'seller') {
                if (watchBtn) { wrong.push(`${tag}: own lot offers Watch`); }
                if (!(await page.getByRole('link', { name: /Manage your lots/ }).count())) { wrong.push(`${tag}: own lot has no Manage link`); }
            } else if (['scheduled', 'live'].includes(ex.st) && !watchBtn) { wrong.push(`${tag}: Watch missing on a followable lot`); }
            else if (['sold', 'unsold', 'cancelled'].includes(ex.st) && watchBtn) { /* a closed lot may still be watched; D-6 */ }
            if (ex.role !== 'seller' && await page.getByRole('link', { name: /Manage your lots|Edit lot/ }).count()) { wrong.push(`${tag}: seller controls shown to a buyer`); }

            // Masking and zone.
            for (const [id, name, email] of users) {
                if (Number(id) === uid) { continue; }
                const [first, ...rest] = name.split(' ');
                const last = rest[rest.length - 1];
                const isLotSeller = Number(id) === l.seller;
                if (html.includes(email)) { nameHits.push(`${tag}: email of ${name}`); }
                if (!isLotSeller && html.includes(name)) { nameHits.push(`${tag}: full name ${name}`); }
                if (!isLotSeller && last && new RegExp(`${first}\\s+${last}`).test(body)) { nameHits.push(`${tag}: ${first} ${last} in text`); }
            }
            if (/\bUTC\b/.test(body) || /\bGMT\b/.test(body)) { utcHits.push(tag); }
            // Voice (DESIGN.md): no exclamation marks, no em or en dashes in what the interface itself says. Seller-written text (title, description, items) is left out.
            const own = await page.evaluate(() => { const c = document.querySelector('#main').cloneNode(true); c.querySelectorAll('.lot-desc, .lot-items, h1, .lot__gallery, .lot-history__who, time').forEach((e) => e.remove()); return c.innerText.replace(/\s+/g, ' '); });
            if (/[!\u2014\u2013]/.test(own)) { voiceHits.push(`${tag}: ${own.match(/.{0,30}[!\u2014\u2013].{0,30}/)?.[0]}`); }
        }
        t.clean(p, `${v} across ${visible.length} lots`);
    }
    t.note(`${pages} lot pages loaded across ${viewers.length} viewers`);
    t.eq('every lot page, for every viewer: right h1, title, outcome headline, badge, price label and amount, form/Buy now/Watch/Manage', wrong, []);
    t.eq('no live wording on lots that are not live (Closes in, Place bid, outbid/leading, Be the first, Current bid on scheduled, ended on cancelled)', liveWordsOnDeadLots, []);
    t.eq('bidder names are masked everywhere and no e-mail address appears (the lot\'s seller is named by design)', nameHits, []);
    t.eq('no page prints UTC or GMT', utcHits, []);
    t.eq('voice: no exclamation marks and no em or en dashes in the interface text of any lot page', voiceHits, []);

    // ---------- time zone, concretely ----------
    {
        const l = lots.find((x) => x.title.startsWith('Wool rug'));
        const p = people.lerato;
        await p.go(`/auctions/${l.id}`);
        const endsUtc = new Date(l.ends.replace(' ', 'T') + 'Z');
        const sast = new Intl.DateTimeFormat('en-ZA', { timeZone: 'Africa/Johannesburg', hour: '2-digit', minute: '2-digit', hour12: false }).format(endsUtc);
        const body = await mainText(p.page);
        t.has('the close time on the lot page is the SAST time, not UTC', body, sast.replace(/\s/g, ''));
        t.has('the page says times are South African time', body, 'South African time');
        t.check('the history shows times with SAST context', /Times in South African time/.test(body), '');
        const hist = await p.page.locator('.lot-history__when').first().innerText().catch(() => '');
        const dbFirst = t.sql(`select created_at from bids where auction_id=${l.id} order by id desc limit 1`);
        const sastBid = new Intl.DateTimeFormat('en-ZA', { timeZone: 'Africa/Johannesburg', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(dbFirst.replace(' ', 'T') + 'Z'));
        t.has('the newest bid time is the SAST time of the stored UTC time', hist.replace(/\s/g, ''), sastBid.replace(/\s/g, ''));
        // The browser's own zone must not change what is printed.
        const far = await t.person('lerato', { timezoneId: 'America/Los_Angeles', to: `/auctions/${l.id}` });
        const body2 = await mainText(far.page);
        t.has('a visitor whose device is in Los Angeles still sees the SAST close time', body2, sast.replace(/\s/g, ''));
        t.clean(far, 'tz');
    }

    // ---------- images ----------
    {
        const p = people.lerato;
        await p.go(`/auctions/${t.id_of('Yamaha F310 acoustic guitar')}`);
        const srcs = await p.page.locator('.lot__gallery img').evaluateAll((is) => [...new Set(is.map((i) => i.getAttribute('src')))]);
        const codes = [];
        for (const src of srcs) { const r = await p.ctx.request.get(src); codes.push([r.status(), r.headers()['content-type'], (await r.body()).length > 100]); }
        t.check('every photo of a lot is served as an image (the strip loads them lazily, so the answer is checked, not the paint)', srcs.length > 1 && codes.every(([c, ct, big]) => c === 200 && /^image\//.test(ct) && big), JSON.stringify(codes));
        const first = await p.page.locator('.lot__gallery img').first().evaluate((i) => i.complete && i.naturalWidth > 0);
        t.check('the first photo is painted', first, '');
    }

    // ---------- looks: one lot per state, 1440 and 390, light and dark, with axe ----------
    for (const [name, title, who] of [
        ['live-visitor', 'Land Rover Defender parts lot', 'zanele'], ['live-leading', 'Stainless steel gas braai, two burner', 'lerato'], ['live-outbid', 'Yamaha F310 acoustic guitar', 'lerato'],
        ['live-extended', 'Wool rug, 2 m by 3 m', 'zanele'], ['live-own', 'Stainless steel gas braai, two burner', 'thandi'], ['scheduled', 'Samsung 55-inch QLED television', 'zanele'],
        ['sold-winner', 'Dell Latitude laptop, i5, 16 GB', 'sipho'], ['sold-visitor', 'Six leather dining chairs', 'zanele'], ['pending-seller', 'KitchenAid stand mixer, 4.8 L', 'thandi'], ['pending-visitor', 'KitchenAid stand mixer, 4.8 L', 'zanele'],
        ['unsold-rejected', 'Gold-plated pocket watch', 'zanele'], ['unsold-nobids', 'Box of assorted hardcover novels', 'zanele'], ['cancelled', 'Garden tool set', 'zanele'], ['buynow-sold', 'Nikon D3500 camera body', 'johan'],
    ]) {
        const p = people[who];
        await p.go(`/auctions/${t.id_of(title)}`);
        await t.audit(p, `lot-${name}`, { full: true });
        t.clean(p, `look ${name}`);
    }
});
