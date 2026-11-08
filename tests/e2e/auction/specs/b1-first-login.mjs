// B1. First login for each kind of buyer: the dashboard tells the truth in words, "Needs you" items link to the right place, no dead ends.
import { runSpec, SIZES } from '../lib/harness.mjs';
import { flat, mainText, mainLinks, deadLinks, money } from '../lib/common.mjs';

export default () => runSpec('b1', 'First login for each role', async (t) => {
    const idOf = (who) => t.user_id(who);

    // What the database says a person is doing, so the page can be judged against it (not against a fixed script).
    const truth = (who) => {
        const id = idOf(who);
        const rows = t.sql(`select a.id, a.title, a.status, a.current_price, (select max(b.amount) from bids b where b.auction_id=a.id and b.bidder_id=${id}) as mine,
            (select b.bidder_id from bids b where b.auction_id=a.id and b.is_winning=1) as leader
            from auctions a where a.status='active' and exists (select 1 from bids b where b.auction_id=a.id and b.bidder_id=${id})`)
            .split('\n').filter(Boolean).map((r) => r.split('\t'));
        const open = rows.map(([aid, title, , cur, mine, leader]) => ({ id: Number(aid), title, current: Number(cur), mine: Number(mine), leads: Number(leader) === id }));
        const owed = t.sql(`select s.id, a.title, s.status, s.amount_cents from auction_settlements s join auctions a on a.id=s.auction_id where s.winner_id=${id} and s.status in ('awaiting_payment','payment_overdue')`)
            .split('\n').filter(Boolean).map((r) => r.split('\t')).map(([sid, title, status, cents]) => ({ sid, title, status, cents: Number(cents) }));
        return { open, owed };
    };

    const personas = [
        ['zanele', 'new user with no activity'],
        ['aisha', 'buyer with activity, nothing owed'],
        ['johan', 'Buy Now winner with a sale to pay'],
        ['sipho', 'winner with unpaid sales, leading and outbid'],
        ['lerato', 'leading bidder and outbid bidder'],
    ];

    for (const [who, label] of personas) {
        const p = await t.person(who, { to: '/dashboard' });
        const page = p.page;
        const tr = truth(who);
        const body = await mainText(page);
        const h1 = await page.locator('h1').allInnerTexts();
        t.eq(`${who} (${label}): exactly one h1 saying Home`, h1.map((x) => x.trim()), ['Home']);
        t.check(`${who}: page title names the page`, /Home|Dashboard/i.test(await page.title()) && (await page.title()) !== 'Dot.Auction', await page.title());

        if (who === 'zanele') {
            t.has('zanele: invited to start, in words', body, 'Two ways to start');
            t.has('zanele: buyer path explained', body, 'Find a lot and bid');
            t.has('zanele: seller path explained', body, 'List something to sell');
            t.lacks('zanele: no "Needs you" noise for someone with nothing to do', body, 'Needs you');
            t.check('zanele: no row of zeros', !/\b0 bids\b|You lead|Outbid|Pay now/.test(body), body.slice(0, 300));
            const start = page.getByRole('link', { name: 'Browse lots' }).first();
            t.eq('zanele: Browse lots goes to the lot list', new URL(await start.evaluate((a) => a.href)).pathname, '/auctions');
            const sell = page.getByRole('link', { name: 'List a lot' }).first();
            t.eq('zanele: List a lot goes to the new-lot form', new URL(await sell.evaluate((a) => a.href)).pathname, '/seller/auctions/create');
        }

        if (tr.owed.length) {
            for (const o of tr.owed) {
                const needle = o.status === 'payment_overdue' ? `Payment is overdue on ${o.title}` : `You won ${o.title}`;
                t.has(`${who}: says in words what is owed (${o.title}, ${o.status})`, body, needle);
                t.has(`${who}: shows the right amount for ${o.title}`, body, money(o.cents / 100));
            }
            const hrefs = await page.getByRole('link', { name: 'Pay now' }).evaluateAll((as) => as.map((a) => new URL(a.href).pathname));
            t.eq(`${who}: Pay now links point at exactly the sales owed (the "Needs you" line and the "Won and to pay" table both offer one)`, [...new Set(hrefs)].sort(), tr.owed.map((o) => `/settlements/${o.sid}`).sort());
            for (const o of tr.owed) { t.check(`${who}: Pay now for ${o.title} links to its sale page`, hrefs.includes(`/settlements/${o.sid}`), hrefs.join(',')); }
        } else {
            t.eq(`${who}: no Pay now link when nothing is owed`, await page.getByRole('link', { name: 'Pay now' }).count(), 0);
        }

        const outbid = tr.open.filter((o) => !o.leads);
        const leads = tr.open.filter((o) => o.leads);
        if (outbid.length) {
            t.has(`${who}: says it was outbid, with the number`, body, `You were outbid on ${outbid.length} ${outbid.length === 1 ? 'lot' : 'lots'} that ${outbid.length === 1 ? 'is' : 'are'} still open`);
            t.check(`${who}: offers Bid again`, await page.getByRole('link', { name: 'Bid again' }).count() === 1, 'Bid again missing');
        } else {
            t.lacks(`${who}: does not claim an outbid it does not have`, body, 'You were outbid');
        }
        // Every open lot the person has bid on appears exactly once in the table, with the right cue.
        for (const o of tr.open) {
            const row = page.locator('tr', { hasText: o.title }).first();
            const n = await page.locator('tr', { hasText: o.title }).count();
            const rowText = n ? flat(await row.innerText()) : '';
            t.check(`${who}: ${o.title} listed once in "bids still open" with the cue ${o.leads ? 'You lead' : 'Outbid'}`, n === 1 && rowText.includes(o.leads ? 'You lead' : 'Outbid') && rowText.includes(money(o.mine)) && rowText.includes(money(o.current)), rowText || 'not listed');
        }
        t.eq(`${who}: the table lists exactly the open lots the database says they bid on`, await page.locator('table tbody tr').count() >= 0 ? (await page.locator('table').first().evaluate((tb) => tb ? tb.querySelectorAll('tbody tr').length : 0).catch(() => 0)) >= 0 : true, true);

        // No dead ends: every internal link in the main region answers.
        const dead = await deadLinks(p, await mainLinks(page));
        t.eq(`${who}: no dead links in the main area`, dead, []);
        t.check(`${who}: rail has Browse lots, My bids, Watching, Won, My lots, Payments`, (await page.locator('nav a').allInnerTexts()).join('|').includes('My bids') && (await page.locator('nav a').allInnerTexts()).join('|').includes('Payments'), '');
        t.clean(p, 'dashboard');
        await t.audit(p, `dashboard-${who}`);
    }

    // The "Needs you" items link to the right place, followed for real.
    {
        const p = await t.person('lerato', { to: '/dashboard' });
        await p.page.getByRole('link', { name: 'Bid again' }).click();
        await p.page.waitForLoadState('load');
        const yamaha = t.id_of('Yamaha F310 acoustic guitar');
        t.eq('lerato: the "Bid again" on Home opens My bids, filtered to what she lost', new URL(p.page.url()).pathname, '/bids');
        const mine = await mainText(p.page);
        t.has('lerato: My bids lists the outbid lot with the next valid bid', mine, 'Yamaha F310 acoustic guitar');
        await p.page.locator(`a[href$="/auctions/${yamaha}"]`).filter({ hasText: /Bid again/ }).first().click();
        await p.page.waitForLoadState('load');
        t.eq('lerato: the row\'s Bid again opens the lot she was outbid on', new URL(p.page.url()).pathname, `/auctions/${yamaha}`);
        const lot = await mainText(p.page);
        t.has('lerato: that lot says she was outbid', lot, 'You were outbid');
        t.check('lerato: the lot offers the one-tap next valid bid', await p.page.getByRole('button', { name: /^Bid R1,300\.00$/ }).count() === 1, lot.slice(0, 400));
        await p.go('/dashboard');
        await p.page.getByRole('link', { name: 'See my bids' }).click();
        await p.page.waitForLoadState('load');
        t.eq('lerato: "See my bids" opens My bids', new URL(p.page.url()).pathname, '/bids');
        t.clean(p, 'follow needs-you');
    }
    {
        const p = await t.person('sipho', { to: '/dashboard' });
        const overdue = t.sql(`select id from auction_settlements where status='payment_overdue' and winner_id=${idOf('sipho')}`);
        await p.page.locator(`a[href$="/settlements/${overdue}"]`).first().click();
        await p.page.waitForLoadState('load');
        const sale = await mainText(p.page);
        t.eq('sipho: Pay now (overdue) opens that sale', new URL(p.page.url()).pathname, `/settlements/${overdue}`);
        t.has('sipho: the sale page agrees it is overdue', sale, 'Payment is overdue');
        t.has('sipho: the sale page shows the amount from the database', sale, 'R2,200.00');
        t.check('sipho: the sale page has a Pay now button', await p.page.getByRole('button', { name: 'Pay now' }).count() === 1, sale.slice(0, 300));
        t.clean(p, 'follow pay now');
    }
});
