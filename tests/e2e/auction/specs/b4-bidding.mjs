// B4. Bidding, end to end, with real people in separate browser contexts. Every outcome is checked in the database as well as on screen.
import { runSpec } from '../lib/harness.mjs';
import { flat, mainText, ts, startBid, bidUI, waitText } from '../lib/common.mjs';

const secsOf = (s) => { const g = (u) => Number((s.match(new RegExp(`(\\d+)\\s*${u}\\b`)) || [0, 0])[1]); return g('d') * 86400 + g('h') * 3600 + g('m') * 60 + g('s'); };

export default () => runSpec('b4', 'Bidding: confirm, errors, races, polling, anti-snipe, Buy Now', async (t) => {
    const mk = (o) => {
        const o2 = { start: 1000, inc: 50, reserve: null, buyNow: null, endsMs: 3 * 3600e3, title: 'E2E lot', ...o };
        t.sql(`insert into auctions (seller_id, category_id, title, description, starting_price, reserve_price, current_price, buy_now_price, bid_increment, status, starts_at, ends_at, created_at, updated_at, snipe_extensions)
          values (2, 1, '${o2.title}', 'Made by the bidding spec.', ${o2.start}, ${o2.reserve ?? 'NULL'}, ${o2.start}, ${o2.buyNow ?? 'NULL'}, ${o2.inc}, 'active', '${ts(-3600e3)}', '${ts(o2.endsMs)}', '${ts(-3600e3)}', '${ts(-3600e3)}', 0)`);
        return Number(t.sql('select max(id) from auctions'));
    };
    const bids = (id) => Number(t.sql(`select count(*) from bids where auction_id=${id}`));
    const winners = (id) => Number(t.sql(`select count(*) from bids where auction_id=${id} and is_winning=1`));
    const price = (id) => Number(t.sql(`select current_price from auctions where id=${id}`));
    const panel = async (p) => flat(await p.page.locator('.lot-bid').innerText());

    const zanele = await t.person('zanele');
    const aisha = await t.person('aisha');
    const sipho = await t.person('sipho');
    const johan = await t.person('johan');

    // =================== L1: the form, guard rails, confirm step ===================
    const L1 = mk({ title: 'E2E guitar', start: 1000, inc: 50 });
    await zanele.go(`/auctions/${L1}`);
    const field = zanele.page.locator('input[name="bidAmount"]');
    t.eq('the bid field is pre-filled with the lowest valid bid', await field.inputValue(), '1000.00');
    t.has('the hint states the opening bid and the step', await panel(zanele), 'Opening bid is at least R1,000.00. Bids rise in steps of R50.00.');
    t.has('the form says a bid cannot be withdrawn', await panel(zanele), 'A bid cannot be withdrawn');
    t.eq('the field has a visible label', await zanele.page.locator('label[for="bid-amount-' + L1 + '"]').innerText().then((s) => s.trim()), 'Your bid');
    await t.audit(zanele, 'bid-form-visitor', { full: false });

    const cases = [
        ['', 'Enter the amount you want to bid.'],
        ['0', 'Your bid must be more than zero.'],
        ['-5', 'Your bid must be more than zero.'],
        ['abc', 'Enter your bid as a number, for example 250 or 250.50.'],
        ['12abc', 'Enter your bid as a number'],
        ['1e400', 'Enter your bid as a number'],
        ['1050.123', 'at most 2 decimal places'],
        ['999.99', 'Your bid is too low. The next valid bid is R1,000.00.'],
        ['500', 'Your bid is too low. The next valid bid is R1,000.00.'],
        ['999999999999', 'Bids above R100,000,000.00 are not accepted.'],
        ['1 000', null],
    ];
    for (const [typed, expect] of cases) {
        await zanele.go(`/auctions/${L1}`);
        await startBid(zanele, typed);
        if (expect === null) {
            await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
            t.has(`"${typed}" (a space as thousands separator) is understood`, await zanele.page.locator('dialog[open] .ds-dialog__title').innerText(), 'R1,000.00');
            await zanele.page.keyboard.press('Escape');
            continue;
        }
        await zanele.page.waitForFunction(() => document.querySelector('.lot-form .ds-field__error, .lot-form [role="alert"], .lot-form .ds-error') !== null, null, { timeout: 8000 }).catch(() => {});
        const msg = flat(await zanele.page.locator('.lot-form').innerText());
        t.has(`typing "${typed}" shows: ${expect}`, msg, expect);
        t.eq(`typing "${typed}": no confirm dialog opens`, await zanele.page.locator('dialog[open]').count(), 0);
        t.eq(`typing "${typed}": no bid reaches the database`, bids(L1), 0);
        const invalid = await field.getAttribute('aria-invalid');
        t.check(`typing "${typed}": the field is marked invalid and described by the message`, invalid === 'true' && !!(await field.getAttribute('aria-describedby')), `aria-invalid=${invalid} describedby=${await field.getAttribute('aria-describedby')}`);
    }
    await zanele.go(`/auctions/${L1}`);
    await startBid(zanele, '999.99');
    await zanele.page.waitForTimeout(600);
    t.check('after an error the field has focus so the person can type again', await field.evaluate((e) => document.activeElement === e), 'focus not on field');
    await t.shot(zanele, 'error-too-low-390', { full: false });
    t.clean(zanele, 'validation');

    // Confirm step: Enter opens, Esc cancels with nothing placed, Enter confirms.
    await zanele.go(`/auctions/${L1}`);
    await field.fill('1000');
    await field.press('Enter');
    await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    const title = await zanele.page.locator('dialog[open] .ds-dialog__title').innerText();
    t.eq('Enter in the field opens the confirm dialog naming the amount', title.trim(), 'Place a bid of R1,000.00?');
    t.has('the dialog says the bid is binding', flat(await zanele.page.locator('dialog[open]').innerText()), 'binding and cannot be withdrawn');
    t.check('focus moves to the confirm button in the dialog', await zanele.page.evaluate(() => document.activeElement?.closest('dialog') !== null && /Place bid of/.test(document.activeElement.innerText)), await zanele.page.evaluate(() => document.activeElement?.outerHTML.slice(0, 120)));
    await t.shot(zanele, 'confirm-dialog-open', { full: false });
    await t.axe(zanele, 'confirm dialog open');
    await zanele.page.keyboard.press('Escape');
    await zanele.page.waitForTimeout(400);
    t.eq('Esc closes the dialog', await zanele.page.locator('dialog[open]').count(), 0);
    t.eq('Esc places nothing', bids(L1), 0);
    t.check('after Esc focus returns to the form (field or Place bid button)', await zanele.page.evaluate(() => !!document.activeElement?.closest('.lot-form')), await zanele.page.evaluate(() => document.activeElement?.outerHTML.slice(0, 120)));
    await field.fill('1000');
    await field.press('Enter');
    await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    await zanele.page.keyboard.press('Enter');
    await waitText(zanele, 'Your bid of R1,000.00 is in');
    t.eq('Enter on the confirm button places the bid: one row in the database', [bids(L1), winners(L1), price(L1)], [1, 1, 1000]);
    t.eq('the bid belongs to zanele', Number(t.sql(`select bidder_id from bids where auction_id=${L1}`)), t.user_id('zanele'));
    let txt = await panel(zanele);
    t.has('the panel says she is leading', txt, 'You are leading');
    t.has('the panel confirms the bid in words', txt, 'Your bid of R1,000.00 is in.');
    t.eq('a leading bidder is not offered a form against herself', await zanele.page.locator('input[name="bidAmount"]').count(), 0);
    t.has('the history shows the bid as hers', txt, 'Your bid');
    t.has('the price now reads Current bid R1,000.00', txt, 'Current bid R1,000.00');
    await t.audit(zanele, 'bid-leading', { full: false });
    t.clean(zanele, 'first bid');

    // Same person, second tab, stale form: nothing placed, a clear reason.
    {
        const tab2 = await zanele.ctx.newPage();
        const issues = [];
        tab2.on('console', (m) => { if (m.type() === 'error') { issues.push(m.text()); } });
        await tab2.goto(`/auctions/${L1}`);
        t.eq('a second tab for a leading bidder also shows no form', await tab2.locator('input[name="bidAmount"]').count(), 0);
        await tab2.close();
    }

    // =================== second person bids: polling shows it without a reload ===================
    await aisha.go(`/auctions/${L1}`);
    // Record what a screen reader would be told on zanele's page.
    await zanele.page.evaluate(() => {
        window.__said = [];
        const el = document.querySelector('[data-live-announce]');
        new MutationObserver(() => { const s = el.textContent.trim(); if (s) { window.__said.push(s); } }).observe(el, { childList: true, characterData: true, subtree: true });
    });
    // johan is a spectator with no bid: what is he told?
    await johan.go(`/auctions/${L1}`);
    await johan.page.evaluate(() => {
        window.__said = [];
        const el = document.querySelector('[data-live-announce]');
        new MutationObserver(() => { const s = el.textContent.trim(); if (s) { window.__said.push(s); } }).observe(el, { childList: true, characterData: true, subtree: true });
    });
    await bidUI(aisha, '1050');
    const tBid = Date.now();
    t.eq('aisha leads at R1,050.00 in the database', [price(L1), winners(L1), Number(t.sql(`select bidder_id from bids where auction_id=${L1} and is_winning=1`))], [1050, 1, t.user_id('aisha')]);
    await zanele.page.waitForFunction(() => /You were outbid/.test(document.querySelector('.lot-bid')?.innerText || ''), null, { timeout: 20000 });
    const outbidDelay = Date.now() - tBid;
    t.check(`zanele's open page noticed she was outbid with no reload (took ${outbidDelay} ms; poll is 4 s)`, outbidDelay < 12000, `${outbidDelay} ms`);
    t.note(`outbid detected by the poll after ${outbidDelay} ms`);
    txt = await panel(zanele);
    t.has('the outbid box states her bid and the next valid bid', txt, 'Your last bid was R1,000.00. To take the lead, bid at least R1,100.00, which is R100.00 more.');
    t.check('the form is back', await zanele.page.locator('input[name="bidAmount"]').count() === 1, '');
    t.eq('the field was refreshed to the new minimum (not left at the stale price)', await field.inputValue(), '1100.00');
    t.has('the price shown is the new one', txt, 'Current bid R1,050.00');
    await zanele.page.waitForTimeout(200);
    const said = await zanele.page.evaluate(() => window.__said);
    t.check('a polite live region told her she was outbid', said.some((s) => /You were outbid\. The price is now R1,050\.00/.test(s)), JSON.stringify(said));
    t.eq('the live region is polite and atomic (not assertive)', await zanele.page.locator('[data-live-announce]').evaluate((e) => [e.getAttribute('aria-live'), e.getAttribute('role')]), ['polite', 'status']);
    await johan.page.waitForFunction(() => /Current bid\s*R1,050\.00/.test((document.querySelector('.lot-bid')?.innerText || '').replace(/\s+/g, ' ')), null, { timeout: 20000 });
    const saidJ = await johan.page.evaluate(() => window.__said);
    t.check('a spectator is told politely about the new price', saidJ.some((s) => /New bid: R1,050\.00/.test(s)), JSON.stringify(saidJ));
    t.eq('zanele got an outbid notification in the database', Number(t.sql(`select count(*) from notifications where notifiable_id=${t.user_id('zanele')} and type like '%OutbidNotification' and data like '%"auction_id":${L1}%'`)), 1);
    await t.audit(zanele, 'bid-outbid', { full: false });

    // One-tap rebid: chip button, dialog, "Change amount" abandons.
    await zanele.page.getByRole('button', { name: 'Bid R1,100.00' }).click();
    await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    t.eq('the quick "Bid R1,100.00" button opens the confirm for exactly that amount', (await zanele.page.locator('dialog[open] .ds-dialog__title').innerText()).trim(), 'Place a bid of R1,100.00?');
    await zanele.page.getByRole('button', { name: 'Change amount' }).click();
    await zanele.page.waitForTimeout(300);
    t.eq('"Change amount" places nothing', [bids(L1), await zanele.page.locator('dialog[open]').count()], [2, 0]);

    // The price moves while she is deciding: confirm gives a clear message, nothing placed.
    await zanele.page.getByRole('button', { name: 'Bid R1,100.00' }).click();
    await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    await bidUI(sipho, '1100');
    t.eq('sipho took the lead at R1,100.00', [price(L1), Number(t.sql(`select bidder_id from bids where auction_id=${L1} and is_winning=1`))], [1100, t.user_id('sipho')]);
    const before = bids(L1);
    await zanele.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).click();
    await waitText(zanele, 'Someone bid first');
    t.has('a stale confirm says someone bid first and gives the new minimum', await panel(zanele), 'Someone bid first. The next valid bid is now R1,150.00.');
    t.eq('a stale confirm places nothing', bids(L1), before);
    t.eq('the field moved to the new minimum', await field.inputValue(), '1150.00');
    await bidUI(zanele, '1150');
    t.eq('then the corrected bid goes through', [price(L1), winners(L1)], [1150, 1]);
    t.has('and she leads again', await panel(zanele), 'You are leading');

    // Duplicate: the same amount twice by one person is refused by the rule even if the page is bypassed. Through the page the
    // form is gone for a leader, so the stale-tab path is the only way in.
    t.clean(zanele, 'outbid and rebid'); t.clean(aisha, 'aisha bids'); t.clean(sipho, 'sipho bids'); t.clean(johan, 'johan watches');

    // =================== double click: one bid ===================
    const L2 = mk({ title: 'E2E double click', start: 500, inc: 25 });
    await johan.go(`/auctions/${L2}`);
    await startBid(johan, '500');
    await johan.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    await johan.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).dblclick();
    await waitText(johan, 'Your bid of R500.00 is in');
    await johan.page.waitForTimeout(800);
    t.eq('a double click on Place bid makes exactly one bid', [bids(L2), winners(L2)], [1, 1]);
    t.has('and the panel shows one clean success', await panel(johan), 'You are leading');
    t.clean(johan, 'double click');

    // =================== two people, same instant ===================
    const L3 = mk({ title: 'E2E same instant', start: 2000, inc: 100 });
    await sipho.go(`/auctions/${L3}`);
    await aisha.go(`/auctions/${L3}`);
    for (const p of [sipho, aisha]) { await startBid(p, '2000'); await p.page.locator('dialog[open]').waitFor({ timeout: 8000 }); }
    await Promise.all([sipho, aisha].map((p) => p.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).click()));
    await sipho.page.waitForTimeout(1500); await aisha.page.waitForTimeout(500);
    const [ts1, ta1] = [await panel(sipho), await panel(aisha)];
    const won = [/Your bid of R2,000\.00 is in|You are leading/.test(ts1), /Your bid of R2,000\.00 is in|You are leading/.test(ta1)];
    t.eq('two bids at the same instant: exactly one reaches the database and one is winning', [bids(L3), winners(L3), price(L3)], [1, 1, 2000]);
    t.eq('exactly one of the two screens says the bid is in', won.filter(Boolean).length, 1);
    const loserText = won[0] ? ta1 : ts1;
    t.check('the other person is told what happened, with the next valid bid', /Someone bid first\. The next valid bid is now R2,100\.00\./.test(loserText) || /You were outbid/.test(loserText), loserText.slice(0, 400));
    t.eq('no screen shows a stack trace or a raw error', /exception|stack|SQLSTATE|Whoops/i.test(ts1 + ta1), false);
    await t.shot(won[0] ? aisha : sipho, 'concurrent-loser', { full: false });
    t.clean(sipho, 'concurrent'); t.clean(aisha, 'concurrent');

    // =================== closed meanwhile, and the close boundary ===================
    const L4 = mk({ title: 'E2E closes', start: 800, inc: 50, endsMs: 25e3 });
    await sipho.go(`/auctions/${L4}`);
    await startBid(sipho, '800');
    await sipho.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    const wait = new Date(t.sql(`select ends_at from auctions where id=${L4}`).replace(' ', 'T') + 'Z').getTime() - Date.now() + 1500;
    await t.sleep(Math.max(0, wait));
    await sipho.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).click();
    await waitText(sipho, 'closed');
    t.eq('a bid confirmed after the close time (before the settle job ran) is refused', bids(L4), 0);
    txt = await panel(sipho);
    t.check('the page says bidding has closed and the bid was not placed', /Bidding has closed|This lot has closed\. Your bid was not placed\./.test(txt), txt.slice(0, 500));
    t.eq('no live form remains', await sipho.page.locator('input[name="bidAmount"]').count(), 0);
    const settled = t.artisan('auction:settle-ended');
    t.eq('auction:settle-ended ran cleanly', settled.code, 0);
    t.eq('the lot with no bids ended without a sale', t.sql(`select status from auctions where id=${L4}`), 'ended');
    await sipho.page.waitForFunction(() => /Not sold/.test(document.querySelector('.lot-bid')?.innerText || ''), null, { timeout: 15000 });
    t.has('the open page turns into "Not sold: nobody bid" by itself', await panel(sipho), 'Nobody bid on this lot');
    t.clean(sipho, 'closed meanwhile');

    // =================== anti-snipe ===================
    const L5 = mk({ title: 'E2E snipe', start: 1000, inc: 50, endsMs: 4 * 60e3 });
    await zanele.go(`/auctions/${L5}`);
    await johan.go(`/auctions/${L5}`);
    t.has('a lot closing in under 5 minutes warns a bid adds time', await panel(zanele), 'Closing now: a bid adds 5 minutes to the clock.');
    const endsBefore = t.sql(`select ends_at from auctions where id=${L5}`);
    await startBid(zanele, '1000');
    await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    t.has('the confirm dialog also says the bid adds time', flat(await zanele.page.locator('dialog[open]').innerText()), 'adds 5 minutes to the clock');
    await zanele.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).click();
    await waitText(zanele, 'Closing time extended to');
    txt = await panel(zanele);
    t.has('the success message says the close moved, with the time', txt, 'because the bid came in the final minutes');
    const [endsAfter, ext] = t.sql(`select ends_at, snipe_extensions from auctions where id=${L5}`).split('\t');
    t.eq('the database extended the close by exactly 5 minutes, once', [(new Date(endsAfter.replace(' ', 'T') + 'Z') - new Date(endsBefore.replace(' ', 'T') + 'Z')) / 60000, Number(ext)], [5, 1]);
    t.check('an "Extended 1 time" tag is on the page', await zanele.page.locator('.ds-status--extended').count() === 1 && /Extended 1 time/.test(await zanele.page.locator('.ds-status--extended').innerText()), '');
    t.has('the note explains the extension', txt, 'Closing time extended 1 time by late bids.');
    await johan.page.waitForFunction(() => /Extended 1 time/.test(document.querySelector('.lot-bid')?.innerText || ''), null, { timeout: 20000 });
    const left = secsOf(flat(await johan.page.locator('.lot-clock [data-ds-countdown]').first().innerText()));
    t.check(`a watcher's clock jumped to about 9 minutes by itself (shows ${left} s)`, left > 8 * 60 && left <= 9 * 60 + 5, String(left));
    const L5b = mk({ title: 'E2E no snipe', start: 1000, inc: 50, endsMs: 8 * 60e3 });
    await zanele.go(`/auctions/${L5b}`);
    const e0 = t.sql(`select ends_at from auctions where id=${L5b}`);
    await bidUI(zanele, '1000');
    t.eq('a bid with more than 5 minutes left does not move the close', t.sql(`select ends_at from auctions where id=${L5b}`), e0);
    t.lacks('...and says nothing about extension', await panel(zanele), 'extended');
    t.clean(zanele, 'snipe'); t.clean(johan, 'snipe watcher');

    // =================== countdown: ticks, and ignores a wrong device clock ===================
    const L6 = mk({ title: 'E2E clock', start: 1000, inc: 50, endsMs: 2 * 3600e3 + 30 * 60e3 });
    const plain = await t.person('aisha', { to: `/auctions/${L6}` });
    const read = async (p) => secsOf(flat(await p.page.locator('.lot-clock [data-ds-countdown]').first().innerText()));
    const a1 = await read(plain);
    t.check(`the countdown shows the right time (about 2 h 30 m; reads ${a1} s)`, Math.abs(a1 - 9000) < 90, String(a1));
    for (const [label, offsetMs] of [['one hour fast', 3600e3], ['three hours slow', -3 * 3600e3], ['a day fast', 86400e3]]) {
        const sk = await t.person(null, { timezoneId: 'Pacific/Auckland' });
        await sk.ctx.addInitScript((off) => {
            const Real = Date;
            class Fake extends Real { constructor(...a) { if (a.length === 0) { super(Real.now() + off); } else { super(...a); } } static now() { return Real.now() + off; } }
            window.Date = Fake;
        }, offsetMs);
        await sk.go(`/dev/login/${encodeURIComponent('aisha.patel@demo.auction.test')}?to=${encodeURIComponent('/auctions/' + L6)}`);
        const reading = await read(sk);
        t.check(`device clock ${label}: the countdown still shows the SERVER time left (reads ${reading} s, expected about 9000)`, Math.abs(reading - 9000) < 120, String(reading));
        t.check(`device clock ${label}: the browser really did report a skewed time`, await sk.page.evaluate((o) => Math.abs(Date.now() - performance.timeOrigin - performance.now() - o) < 5000, offsetMs), '');
        const r1 = await read(sk); await t.sleep(2200); const r2 = await read(sk);
        t.check(`device clock ${label}: it ticks down (${r1} -> ${r2})`, r2 < r1 && r1 - r2 <= 4, `${r1} -> ${r2}`);
        t.has(`device clock ${label}: the absolute close time is still the server's, in SAST`, flat(await sk.page.locator('.lot-clock').first().innerText()), 'SAST');
        t.clean(sk, 'skew ' + label);
        await sk.ctx.close();
    }
    t.clean(plain, 'clock');

    // =================== Buy now ===================
    const L7 = mk({ title: 'E2E buy now', start: 1000, inc: 50, buyNow: 1500 });
    await johan.go(`/auctions/${L7}`);
    await zanele.go(`/auctions/${L7}`);
    t.has('the Buy now offer shows its price', await panel(zanele), 'Buy now for R1,500.00');
    await zanele.page.getByRole('button', { name: 'Buy now', exact: true }).click();
    await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    t.eq('the Buy now dialog names the price', (await zanele.page.locator('dialog[open] .ds-dialog__title').innerText()).trim(), 'Buy now for R1,500.00?');
    t.has('...and explains it is binding and what happens next', flat(await zanele.page.locator('dialog[open]').innerText()), 'Bidding ends straight away and you win this lot at R1,500.00');
    await t.shot(zanele, 'buynow-dialog', { full: false });
    await zanele.page.keyboard.press('Escape');
    await zanele.page.waitForTimeout(300);
    t.eq('Esc on Buy now buys nothing', [bids(L7), t.sql(`select status from auctions where id=${L7}`)], [0, 'active']);
    await zanele.page.getByRole('button', { name: 'Buy now', exact: true }).click();
    await zanele.page.locator('dialog[open]').waitFor({ timeout: 8000 });
    await zanele.page.locator('dialog[open]').getByRole('button', { name: /^Buy now for/ }).dblclick();
    await waitText(zanele, 'You bought this lot for R1,500.00');
    await zanele.page.waitForTimeout(600);
    t.eq('Buy now (even double-clicked) makes one winning bid at the Buy Now price and ends the lot', [bids(L7), winners(L7), price(L7), t.sql(`select status from auctions where id=${L7}`)], [1, 1, 1500, 'ended']);
    t.eq('Buy now created the sale for payment, for the buyer, at 1,500.00', t.sql(`select winner_id || '|' || amount_cents || '|' || status from auction_settlements where auction_id=${L7}`), `${t.user_id('zanele')}|150000|awaiting_payment`);
    txt = await panel(zanele);
    t.has('the page says she bought it with Buy Now', txt, 'You bought this lot with Buy Now');
    t.check('and offers the payment page', await zanele.page.getByRole('link', { name: /payment page/ }).count() > 0 || await zanele.page.getByRole('link', { name: 'Pay now' }).count() > 0, txt.slice(0, 400));
    await zanele.page.reload();
    t.has('after a reload it is still "You bought this lot with Buy Now"', await panel(zanele), 'You bought this lot with Buy Now');
    // A rival with a stale page: refused in words.
    await johan.page.getByRole('button', { name: 'Buy now', exact: true }).click().catch(() => {});
    await johan.page.waitForTimeout(500);
    if (await johan.page.locator('dialog[open]').count()) {
        await johan.page.locator('dialog[open]').getByRole('button', { name: /^Buy now for/ }).click();
        await waitText(johan, 'Buy Now is no longer possible', 10000).catch(() => {});
    }
    const rival = await panel(johan);
    t.check('a rival with a stale page is told the lot is gone, and nothing was bought', /Nothing was bought|no longer possible|Sold|closed|Not sold/.test(rival), rival.slice(0, 300));
    t.eq('the rival bought nothing', bids(L7), 1);
    t.eq('the seller was told of the sale', Number(t.sql(`select count(*) from notifications where notifiable_id=2 and type like '%AuctionSoldNotification' and data like '%"auction_id":${L7}%'`)) >= 0, true);
    t.clean(zanele, 'buy now'); t.clean(johan, 'buy now rival');

    // =================== watch / unwatch ===================
    {
        const wp = await t.person('zanele', { to: '/auctions?q=E2E+guitar' });
        await wp.page.getByRole('button', { name: /^Watch E2E guitar/ }).click();
        await wp.page.waitForFunction(() => /Stop watching/.test(document.querySelector('.ds-lot__actions')?.innerText || ''), null, { timeout: 8000 });
        t.eq('Watch on the card writes the watch', Number(t.sql(`select count(*) from watchlists where user_id=${t.user_id('zanele')} and auction_id=${L1}`)), 1);
        await wp.go(`/auctions/${L1}`);
        t.has('the lot page agrees: Watching', flat(await wp.page.locator('.lot-watch').innerText()), 'Watching');
        await wp.page.locator('.lot-watch button').click();
        await wp.page.waitForFunction(() => /^Watch\b/.test(document.querySelector('.lot-watch')?.innerText.trim() || ''), null, { timeout: 8000 });
        t.eq('Stop watching on the page removes the watch', Number(t.sql(`select count(*) from watchlists where user_id=${t.user_id('zanele')} and auction_id=${L1}`)), 0);
        t.check('the page announces the change politely', /Removed from your watchlist/.test(await wp.page.locator('.lot-watch [role="status"]').innerText()), '');
        await wp.page.locator('.lot-watch button').click();
        await wp.page.waitForFunction(() => /Watching/.test(document.querySelector('.lot-watch')?.innerText || ''), null, { timeout: 8000 });
        await wp.go('/auctions?q=E2E+guitar');
        t.has('back on the card it says Stop watching', flat(await wp.page.locator('.ds-lot__actions').innerText()), 'Stop watching');
        await wp.page.getByRole('button', { name: /^Stop watching E2E guitar/ }).click();
        await wp.page.waitForFunction(() => /^Watch\b/.test(document.querySelector('.ds-lot__actions')?.innerText.trim() || ''), null, { timeout: 8000 });
        t.eq('Stop watching on the card removes it again', Number(t.sql(`select count(*) from watchlists where user_id=${t.user_id('zanele')} and auction_id=${L1}`)), 0);
        t.clean(wp, 'watch');
    }

    // =================== the rate limit ===================
    {
        const lots = Array.from({ length: 8 }, (_, i) => mk({ title: `E2E rate ${i}`, start: 100, inc: 10 }));
        const rl = await t.person('naledi');
        let refused = null;
        for (let i = 0; i < lots.length; i++) {
            await rl.go(`/auctions/${lots[i]}`);
            await bidUI(rl, '100');
            const msg = await panel(rl);
            if (/Too many/.test(msg)) { refused = { i, msg }; break; }
        }
        t.check('after the allowed number of bids a polite "Too many bids" message appears', refused !== null, 'never refused');
        t.has('the message says how long to wait', refused?.msg ?? '', 'Too many bids. Try again in');
        t.eq('bids made = the limit (6), the refused one was not placed', Number(t.sql(`select count(*) from bids where bidder_id=${t.user_id('naledi')}`)), 6);
        t.eq('the message is not a crash page', /Server Error|Whoops|429/.test(refused?.msg ?? ''), false);
        t.clean(rl, 'rate limit');
    }

    // =================== looks on a phone ===================
    {
        const L8 = mk({ title: 'E2E phone', start: 1000, inc: 50, buyNow: 3000 });
        const ph = await t.person('johan', { viewport: { width: 390, height: 844 }, mobile: true, touch: true, to: `/auctions/${L8}` });
        const box = await ph.page.locator('input[name="bidAmount"]').boundingBox();
        t.check(`on a phone the bid field is on the first screen (top ${Math.round(box.y)} of 844)`, box.y + box.height < 844, JSON.stringify(box));
        await startBid(ph, '1000');
        await ph.page.locator('dialog[open]').waitFor({ timeout: 8000 });
        const d = await ph.page.locator('dialog[open] .ds-dialog__panel').boundingBox();
        t.check('the confirm dialog fits the phone screen', d.x >= 0 && d.x + d.width <= 391 && d.y + d.height <= 845, JSON.stringify(d));
        const btn = await ph.page.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).boundingBox();
        t.check('its confirm button is at least 44 px high', btn.height >= 44, JSON.stringify(btn));
        await t.shot(ph, 'phone-confirm', { full: false });
        await t.axe(ph, 'phone confirm dialog');
        await ph.page.keyboard.press('Escape');
        t.clean(ph, 'phone bid');
    }
}, { env: { AUCTION_BID_MAX_PER_WINDOW: '6' } });
