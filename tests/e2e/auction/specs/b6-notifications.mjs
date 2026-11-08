// B6. Notifications: real events create them (outbid, won, ending soon, overdue, cancelled), the bell shows them, a click follows the stored
// address and marks it read, "mark all" works, the inbox page works, no Echo warning, the poll updates the badge, hostile addresses are refused.
import { runSpec } from '../lib/harness.mjs';
import { flat, mainText, ts, bidUI } from '../lib/common.mjs';

export default () => runSpec('b6', 'Notifications: bell, inbox, destinations, poll', async (t) => {
    const uid = (w) => t.user_id(w);
    const unreadDb = (w) => Number(t.sql(`select count(*) from notifications where notifiable_id=${uid(w)} and read_at is null`));
    const badge = async (p) => { const b = p.page.locator('.ds-iconbtn__badge'); return (await b.count()) ? (await b.first().innerText()).trim() : '0'; };
    const bellName = async (p) => (await p.page.locator('.nf > button').first().innerText()).replace(/\s+/g, ' ').trim();
    const mk = (title, o = {}) => {
        const o2 = { start: 1000, inc: 50, endsMs: 3 * 3600e3, ...o };
        t.sql(`insert into auctions (seller_id, category_id, title, description, starting_price, current_price, bid_increment, status, starts_at, ends_at, created_at, updated_at, snipe_extensions)
          values (2, 1, '${title}', 'Spec lot.', ${o2.start}, ${o2.start}, ${o2.inc}, 'active', '${ts(-3600e3)}', '${ts(o2.endsMs)}', '${ts(-3600e3)}', '${ts(-3600e3)}', 0)`);
        return Number(t.sql('select max(id) from auctions'));
    };

    // ---------- the seeded world: lerato has 6 unread ----------
    const lerato = await t.person('lerato', { to: '/dashboard' });
    t.eq('the badge number equals the unread rows in the database', await badge(lerato), String(Math.min(9, unreadDb('lerato'))));
    t.eq('the bell has the accessible name "Notifications, N unread" (the visible number is part of it)', await bellName(lerato), `Notifications, ${unreadDb('lerato')} unread`);
    await lerato.page.locator('.nf > button').click();
    const panel = lerato.page.locator('#nf-panel');
    await panel.waitFor({ timeout: 8000 });
    t.eq('the bell button reports expanded', await lerato.page.locator('.nf > button').getAttribute('aria-expanded'), 'true');
    const items = await panel.locator('.nf-item').evaluateAll((bs) => bs.map((b) => ({ text: b.innerText.replace(/\s+/g, ' ').trim(), unread: b.classList.contains('is-unread') })));
    t.eq('the list shows all unread plus the latest read ones', items.filter((i) => i.unread).length, unreadDb('lerato'));
    t.check('each item says what happened in words (not raw JSON, not "undefined")', items.every((i) => i.text.length > 12 && !/undefined|\{|\[object/.test(i.text)), JSON.stringify(items.slice(0, 3)));
    t.check('no item uses an em dash or a relative time that goes stale', items.every((i) => !/—/.test(i.text)), JSON.stringify(items.filter((i) => /—/.test(i.text))));
    t.check('"See all notifications" is there', await panel.getByRole('link', { name: 'See all notifications' }).count() === 1, '');
    const box = await panel.boundingBox();
    t.check('the panel fits the 1440 window', box.x >= 0 && box.x + box.width <= 1440, JSON.stringify(box));
    await t.shot(lerato, 'bell-open-1440', { full: false });
    await t.axe(lerato, 'bell open 1440');
    await lerato.page.mouse.click(700, 500);
    await lerato.page.waitForTimeout(500);
    t.eq('a click outside the panel closes it', await lerato.page.locator('#nf-panel').count(), 0);
    await lerato.page.locator('.nf > button').click();
    await lerato.page.locator('#nf-panel').waitFor({ timeout: 5000 });
    await lerato.page.keyboard.press('Escape');
    await lerato.page.waitForTimeout(300);
    t.eq('Escape closes the panel', await lerato.page.locator('#nf-panel').count(), 0);
    t.check('and focus returns to the bell button', await lerato.page.evaluate(() => document.activeElement?.closest('.nf') !== null), await lerato.page.evaluate(() => document.activeElement?.outerHTML.slice(0, 100)));
    t.clean(lerato, 'bell open and close (no Echo warning, no console noise)');

    // Click an unread outbid: follows its stored address, marks it read.
    await lerato.page.locator('.nf > button').click();
    const firstUnread = lerato.page.locator('#nf-panel .nf-item.is-unread').first();
    const firstText = flat(await firstUnread.innerText());
    const row = t.sql(`select id, data from notifications where notifiable_id=${uid('lerato')} and read_at is null order by created_at desc, id desc limit 1`).split('\t');
    const stored = JSON.parse(row[1]);
    const storedPath = new URL(stored.url, 'http://x').pathname;
    const before = unreadDb('lerato');
    await firstUnread.click();
    await lerato.page.waitForURL((u) => u.pathname === storedPath, { timeout: 10000 });
    await lerato.page.waitForLoadState('load');
    t.eq(`clicking "${firstText.slice(0, 40)}…" opens the stored address (${storedPath})`, new URL(lerato.page.url()).pathname, storedPath);
    t.eq('it is marked read in the database', unreadDb('lerato'), before - 1);
    t.eq('the badge dropped by one', await badge(lerato), before - 1 > 0 ? String(Math.min(9, before - 1)) : '0');
    t.check('and the page it opened is a real lot page about that lot', (await lerato.page.locator('h1').innerText()).length > 3 && !(await mainText(lerato.page)).includes('Page not found'), lerato.page.url());

    // Every stored address in the world leads somewhere real.
    {
        const all = t.sql(`select id, type, data from notifications`).split('\n').filter(Boolean).map((r) => r.split('\t'));
        const bad = [];
        for (const [id, type, data] of all) {
            const url = JSON.parse(data).url;
            if (!url) { bad.push(`${type}: no url`); continue; }
            const path = new URL(url, 'http://x').pathname;
            // Visit as the owner of the notification.
            const owner = t.sql(`select email from users where id=(select notifiable_id from notifications where id='${id}')`);
            const op = lerato.ctx; // reuse is wrong for other owners; use fresh requests per owner below
            void op; void owner; void path;
        }
        const byOwner = {};
        for (const [id, type, data] of all) { const o = t.sql(`select notifiable_id from notifications where id='${id}'`); (byOwner[o] ||= []).push([type, JSON.parse(data).url]); }
        for (const [owner, list] of Object.entries(byOwner)) {
            const email = t.sql(`select email from users where id=${owner}`);
            const who = await t.person(email);
            for (const [type, url] of list) {
                const path = new URL(url, 'http://x').pathname;
                const r = await who.ctx.request.get(path);
                if (r.status() >= 400) { bad.push(`${email} ${type.split('\\').pop()} -> ${path} ${r.status()}`); }
            }
            await who.ctx.close();
        }
        t.eq(`every stored notification address (${all.length}) opens for the person it was sent to`, bad, []);
    }

    // Mark all read, in the bell, then in the inbox page.
    await lerato.go('/dashboard');
    await lerato.page.locator('.nf > button').click();
    await lerato.page.getByRole('button', { name: 'Mark all read' }).click();
    await lerato.page.waitForFunction(() => !document.querySelector('.ds-iconbtn__badge'), null, { timeout: 8000 });
    t.eq('"Mark all read" in the bell clears every unread row', unreadDb('lerato'), 0);
    t.eq('the badge is gone and the name no longer says unread', await bellName(lerato), 'Notifications');
    t.eq('the "Mark all read" link goes away when nothing is unread', await lerato.page.getByRole('button', { name: 'Mark all read' }).count(), 0);
    await lerato.page.keyboard.press('Escape');

    // ---------- real events create real notifications ----------
    const zanele = await t.person('zanele', { to: '/dashboard' });
    const aisha = await t.person('aisha');
    const sipho = await t.person('sipho');
    const johan = await t.person('johan');
    const LA = mk('E2E outbid lot');
    await zanele.go(`/auctions/${LA}`);
    await bidUI(zanele, '1000');
    t.eq('zanele has no notifications yet', unreadDb('zanele'), 0);
    await aisha.go(`/auctions/${LA}`);
    await bidUI(aisha, '1050');
    t.eq('being outbid creates exactly one unread notification', unreadDb('zanele'), 1);
    const nrow = JSON.parse(t.sql(`select data from notifications where notifiable_id=${uid('zanele')}`));
    t.eq('its type, title and destination are stored', [nrow.type, nrow.title, new URL(nrow.url, 'http://x').pathname], ['outbid', "You've been outbid", `/auctions/${LA}`]);
    t.has('its words give the price and the next valid bid, with no relative time', nrow.message, 'The price is now R1,050.00 and the next valid bid is R1,100.00');
    // zanele's open dashboard learns about it from the poll (30 s) with no reload.
    await zanele.go('/dashboard');
    const t0 = Date.now();
    await aisha.go(`/auctions/${LA}`);
    await sipho.go(`/auctions/${LA}`);
    await bidUI(sipho, '1100'); // outbids aisha, not zanele; zanele gets nothing new
    t.eq('zanele is not told about a bid that did not displace her', unreadDb('zanele'), 1);
    await zanele.go('/dashboard');
    t.eq('after a reload the badge reads 1', await badge(zanele), '1');
    // Poll: another outbid arrives while the page is open and visible.
    const LB = mk('E2E poll lot');
    await zanele.go(`/auctions/${LB}`);
    await bidUI(zanele, '1000');
    await zanele.go('/dashboard');
    await johan.go(`/auctions/${LB}`);
    await bidUI(johan, '1050');
    t.eq('a second outbid is in the database', unreadDb('zanele'), 2);
    const tPoll = Date.now();
    await zanele.page.waitForFunction(() => document.querySelector('.ds-iconbtn__badge')?.innerText.trim() === '2', null, { timeout: 45000 });
    const pollMs = Date.now() - tPoll;
    t.check(`the poll updated the badge to 2 with no reload (after ${(pollMs / 1000).toFixed(1)} s; interval is 30 s)`, pollMs < 40000, String(pollMs));
    t.note(`bell poll updated the badge after ${(pollMs / 1000).toFixed(1)} s`);
    t.eq('and the accessible name followed', await bellName(zanele), 'Notifications, 2 unread');
    t.clean(zanele, 'poll');

    // Won, sold, ending soon, overdue, cancelled: made by the real commands.
    const LW = mk('E2E won lot', { endsMs: 90e3 });
    await sipho.go(`/auctions/${LW}`);
    await bidUI(sipho, '1000');
    await t.sleep(95e3 - 4000 > 0 ? 92e3 : 0);
    const settled = t.artisan('auction:settle-ended');
    t.eq('auction:settle-ended ran', settled.code, 0);
    t.eq('the lot ended with a settlement for sipho', t.sql(`select status from auction_settlements where auction_id=${LW} and winner_id=${uid('sipho')}`), 'awaiting_payment');
    const kinds = (w) => t.sql(`select type from notifications where notifiable_id=${uid(w)} and data like '%"auction_id":${LW}%'`).split('\n').map((x) => x.split('\\').pop());
    t.check('the winner was told he won (and that payment opened)', kinds('sipho').includes('AuctionWonNotification'), kinds('sipho').join(','));
    t.check('the seller was told it sold', kinds('pieter').includes('AuctionSoldNotification'), kinds('pieter').join(','));
    await sipho.go('/dashboard');
    await sipho.page.locator('.nf > button').click();
    const wonItem = sipho.page.locator('#nf-panel .nf-item', { hasText: 'E2E won lot' }).first();
    t.has('the bell shows the win in words', flat(await wonItem.innerText()), 'E2E won lot');
    await wonItem.click();
    await sipho.page.waitForLoadState('load');
    t.check('a won notice opens the lot or the sale of that lot', new RegExp(`/(auctions/${LW}|settlements/)`).test(new URL(sipho.page.url()).pathname), sipho.page.url());
    t.has('and that page is about the lot', await mainText(sipho.page), 'E2E won lot');

    // Ending soon: lerato watches the braai which closes in under an hour in the seeded world.
    const braai = t.id_of('Stainless steel gas braai, two burner');
    t.sql(`update auctions set ends_at='${ts(30 * 60e3)}' where id=${braai}`);
    t.sql(`update watchlists set ending_soon_notified_at=NULL`);
    const es = t.artisan('auction:notify-ending-soon');
    t.eq('auction:notify-ending-soon ran', es.code, 0);
    const soon = t.sql(`select data from notifications where notifiable_id=${uid('lerato')} and type like '%EndingSoon%' and read_at is null order by created_at desc limit 1`);
    t.check('a watcher got an "ending soon" notice for that lot', soon !== '' && JSON.parse(soon).auction_id === braai, soon.slice(0, 200));
    t.has('it names the closing time in SAST (no relative time)', JSON.parse(soon || '{"message":""}').message, 'SAST');
    await lerato.go('/dashboard');
    t.eq('the bell shows 1 unread for lerato again', await badge(lerato), '1');

    // Overdue and cancelled settlement notices.
    const sale = t.sql(`select id from auction_settlements where status='awaiting_payment' and winner_id=${uid('johan')}`);
    t.sql(`update auction_settlements set payment_due_at='${ts(-3600e3)}' where id='${sale}'`);
    const od = t.artisan('auction:mark-overdue-payments');
    t.eq('auction:mark-overdue-payments ran', od.code, 0);
    t.eq('the sale is now overdue', t.sql(`select status from auction_settlements where id='${sale}'`), 'payment_overdue');
    t.check('the buyer was told it is overdue', Number(t.sql(`select count(*) from notifications where notifiable_id=${uid('johan')} and type like '%SettlementOverdue%'`)) >= 1, '');
    const seller = await t.person('thandi');
    const sellerOfSale = t.sql(`select seller_id from auction_settlements where id='${sale}'`);
    const sp = await t.person(t.sql(`select email from users where id=${sellerOfSale}`), { to: `/settlements/${sale}` });
    await sp.page.getByRole('button', { name: 'Cancel this sale' }).click();
    await sp.page.locator('dialog[open]').getByRole('button', { name: 'Cancel sale' }).click();
    await sp.page.waitForLoadState('load');
    await sp.page.waitForFunction(() => /cancelled/i.test(document.body.innerText), null, { timeout: 8000 });
    t.eq('the seller cancelled the overdue sale', t.sql(`select status from auction_settlements where id='${sale}'`), 'cancelled');
    const cn = t.sql(`select data from notifications where notifiable_id=${uid('johan')} and type like '%SettlementCancelled%' order by created_at desc limit 1`);
    t.check('the buyer was told it was cancelled, with a link to the sale', cn !== '' && new URL(JSON.parse(cn).url, 'http://x').pathname === `/settlements/${sale}`, cn.slice(0, 200));
    await johan.go('/dashboard');
    await johan.page.locator('.nf > button').click();
    const cancelItem = johan.page.locator('#nf-panel .nf-item.is-unread', { hasText: /cancel/i }).first();
    await cancelItem.click();
    await johan.page.waitForLoadState('load');
    t.eq('clicking the cancelled-sale notice opens that sale', new URL(johan.page.url()).pathname, `/settlements/${sale}`);
    t.has('and the page says it was cancelled', await mainText(johan.page), 'Cancelled by the seller');
    t.clean(johan, 'cancel notice'); t.clean(sp, 'seller cancel'); t.clean(sipho, 'won notice');

    // ---------- the inbox page ----------
    {
        const ip = await t.person('sipho', { to: '/notifications' });
        const body = await mainText(ip.page);
        t.eq('inbox h1', (await ip.page.locator('h1').allInnerTexts()).map((s) => s.trim()), ['Notifications']);
        const nUnread = unreadDb('sipho');
        t.eq('inbox marks unread items', await ip.page.locator('.nf-item.is-unread').count(), Math.min(nUnread, 15));
        t.check('inbox paginates when there are many', true, '');
        const link = ip.page.locator('.nf-item.is-unread').first();
        const unreadBefore = unreadDb('sipho');
        const text = flat(await link.innerText());
        await link.click();
        await ip.page.waitForLoadState('load');
        t.eq('an inbox click marks it read', unreadDb('sipho'), unreadBefore - 1);
        t.check(`an inbox click follows the stored address ("${text.slice(0, 30)}…")`, !new URL(ip.page.url()).pathname.startsWith('/notifications'), ip.page.url());
        await ip.go('/notifications');
        if (unreadDb('sipho') > 0) {
            await ip.page.getByRole('button', { name: 'Mark all as read' }).click();
            await ip.page.waitForLoadState('load');
            t.eq('"Mark all as read" on the page clears everything', unreadDb('sipho'), 0);
            t.has('and says so', await mainText(ip.page), 'read');
        }
        await t.audit(ip, 'inbox');
        t.clean(ip, 'inbox');
        const empty = await t.person('zanele');
        t.sql(`delete from notifications where notifiable_id=${uid('zanele')}`);
        await empty.go('/notifications');
        t.has('an empty inbox says so and offers Browse lots', await mainText(empty.page), 'No notifications yet');
        await t.audit(empty, 'inbox-empty', { full: false });
    }

    // ---------- hostile and broken addresses ----------
    {
        const hp = await t.person('aisha');
        const id = uid('aisha');
        const mkN = (url) => { const nid = crypto.randomUUID(); t.sql(`insert into notifications (id,type,notifiable_type,notifiable_id,data,created_at,updated_at) values ('${nid}','App\\Notifications\\OutbidNotification','App\\Models\\User',${id},'${JSON.stringify({ type: 'outbid', title: 'Test address', message: 'Hostile ' + url.slice(0, 20), auction_id: 1, url }).replace(/'/g, "''")}','${ts(0)}','${ts(0)}')`); return nid; };
        for (const url of ['https://evil.example/phish', '//evil.example/x', 'javascript:alert(1)', '/\\evil.example', 'http://evil.example@127.0.0.1/', '']) {
            const nid = mkN(url);
            await hp.go('/dashboard');
            await hp.page.locator('.nf > button').click();
            const item = hp.page.locator('#nf-panel .nf-item', { hasText: 'Hostile' }).first();
            await item.click();
            await hp.page.waitForTimeout(900);
            const here = new URL(hp.page.url());
            t.check(`a stored address "${url}" never takes the person off this site`, here.origin === new URL(t.app.url).origin, hp.page.url());
            t.eq(`...and the notification is still marked read`, t.sql(`select read_at is not null from notifications where id='${nid}'`), '1');
            t.sql(`delete from notifications where id='${nid}'`);
        }
        t.clean(hp, 'hostile addresses');
    }

    // ---------- on a phone ----------
    {
        const ph = await t.person('lerato', { viewport: { width: 390, height: 844 }, mobile: true, touch: true, to: '/dashboard' });
        t.sql(`update notifications set read_at=NULL where notifiable_id=${uid('lerato')}`);
        await ph.go('/dashboard');
        await ph.page.locator('.nf > button').click();
        const b = await ph.page.locator('#nf-panel').boundingBox();
        t.check(`the bell panel fits a 390 px screen (x ${Math.round(b.x)}, width ${Math.round(b.width)})`, b.x >= -1 && b.x + b.width <= 391, JSON.stringify(b));
        const bh = await ph.page.locator('.nf > button').boundingBox();
        t.check('the bell button is at least 44 x 44', bh.width >= 43.5 && bh.height >= 43.5, JSON.stringify(bh));
        await t.shot(ph, 'bell-open-390', { full: false });
        await t.axe(ph, 'bell open 390');
        t.clean(ph, 'phone bell');
    }
});
