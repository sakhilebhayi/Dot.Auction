// B7. Paying as the winner, through a FAKE Paystack (tests/e2e only: AUCTION_E2E_FAKE_PAYSTACK=1 in local/testing). Success, failed,
// abandoned, pending, unreachable gateway, a lost confirmation, duplicate callback and webhook; the seller sees the paid state.
import crypto from 'node:crypto';
import { runSpec } from '../lib/harness.mjs';
import { flat, mainText, money } from '../lib/common.mjs';

const KEY = 'sk_test_e2e_not_a_real_key';

export default () => runSpec('b7', 'Payment as the winner (fake Paystack)', async (t) => {
    const uid = (w) => t.user_id(w);
    const sale = (title) => t.sql(`select s.id from auction_settlements s join auctions a on a.id=s.auction_id where a.title='${title.replace(/'/g, "''")}'`);
    const status = (id) => t.sql(`select status from auction_settlements where id='${id}'`);
    const payments = (id) => t.sql(`select status from settlement_payments where settlement_id='${id}' order by id`).split('\n').filter(Boolean);
    const events = (id, type) => Number(t.sql(`select count(*) from settlement_events where settlement_id='${id}' and type='${type}'`));
    const setMode = async (p, mode) => { const r = await p.ctx.request.get(`/dev/fake-paystack-mode/${mode}`); t.eq(`fake gateway switched to "${mode}"`, r.status(), 200); };

    const laptop = sale('Dell Latitude laptop, i5, 16 GB');
    const chairs = sale('Six leather dining chairs');
    const nikon = sale('Nikon D3500 camera body');
    // The demo world already holds attempts on the laptop sale (a started and a failed checkout, for the operator page): judge only what this run adds.
    const base = { [laptop]: payments(laptop).length, [chairs]: payments(chairs).length, [nikon]: payments(nikon).length };
    const mine = (id) => payments(id).slice(base[id]);
    const paidNotices = (uidn) => Number(t.sql(`select count(*) from notifications where type like '%SettlementPaid%' and notifiable_id=${uidn}`));
    const noticeBase = [paidNotices(uid('sipho')), paidNotices(1)];
    const sipho = await t.person('sipho', { to: `/settlements/${laptop}` });
    const page = sipho.page;

    // ---------- the sale page, before paying ----------
    let body = await mainText(page);
    t.eq('h1 names the payment and the lot', (await page.locator('h1').innerText()).trim(), 'Payment for Dell Latitude laptop, i5, 16 GB');
    t.has('says what he owes', body, 'You owe R3,700.00');
    t.has('says what to do', body, 'Pay to complete your purchase.');
    t.has('says a deadline in SAST', body, 'SAST');
    t.has('explains that card details are entered on Paystack', body, 'Card details are entered on Paystack, never on Dot.Auction');
    t.has('test mode is stated honestly', body, 'No real money moves in test mode');
    t.eq('one Pay now button', await page.getByRole('button', { name: 'Pay now' }).count(), 1);
    t.has('the history starts with the sale being recorded', body, 'Sale recorded at R3,700.00');
    await t.audit(sipho, 'sale-awaiting');
    t.clean(sipho, 'sale page');

    // ---------- success ----------
    await page.getByRole('button', { name: 'Pay now' }).click();
    await page.waitForURL(/\/dev\/fake-paystack\/AUC-/, { timeout: 10000 });
    const ref = new URL(page.url()).pathname.split('/').pop();
    t.check('Pay now sent him to the gateway\'s checkout with our reference', /^AUC-[0-9A-Z]{26}$/.test(ref), ref);
    const row = t.sql(`select status, amount_cents, currency, provider, initiated_by from settlement_payments where reference='${ref}'`).split('\t');
    t.eq('one payment attempt was recorded, initiated, for the sale amount and currency taken from the sale', row, ['initiated', '370000', 'ZAR', 'paystack', String(uid('sipho'))]);
    t.has('the (fake) checkout shows the right amount', await mainText(page), 'ZAR3,700.00');
    t.eq('the sale is still awaiting payment while he is on the checkout', status(laptop), 'awaiting_payment');
    // He opens a second tab and presses Pay now again: the same checkout is reused, not a second payment.
    {
        const tab2 = await sipho.ctx.newPage();
        await tab2.goto(`/settlements/${laptop}`);
        await tab2.getByRole('button', { name: 'Pay now' }).click();
        await tab2.waitForURL(/\/dev\/fake-paystack\//, { timeout: 10000 });
        t.eq('Pay now in a second tab reuses the same checkout', new URL(tab2.url()).pathname.split('/').pop(), ref);
        t.eq('still exactly one attempt of ours', mine(laptop), ['initiated']);
        await tab2.close();
    }
    await page.locator('#fake-pay').click();
    await page.waitForURL(new RegExp(`/settlements/${laptop}$`), { timeout: 10000 });
    await page.waitForLoadState('load');
    body = await mainText(page);
    t.eq('the return lands on the sale page', new URL(page.url()).pathname, `/settlements/${laptop}`);
    t.has('it says payment received, titled', body, 'Payment received');
    t.has('it says thank you', body, 'Payment received. Thank you.');
    t.has('the headline is the paid state', body, 'The seller has been told. This was a test payment: no real money moved.');
    t.eq('the database: settlement paid with a paid_at, attempt paid', [status(laptop), mine(laptop), t.sql(`select paid_at is not null from auction_settlements where id='${laptop}'`)], ['paid', ['paid'], '1']);
    t.eq('exactly one paid event in the history', events(laptop, 'paid'), 1);
    t.eq('no Pay now button is left', await page.getByRole('button', { name: 'Pay now' }).count(), 0);
    t.has('the receipt reference is shown', body, ref);
    t.eq('the winner and the seller were each told', [paidNotices(uid('sipho')) - noticeBase[0], paidNotices(1) - noticeBase[1]], [1, 1]);
    await t.audit(sipho, 'sale-paid-return');
    t.clean(sipho, 'success return');

    // Duplicate callback and duplicate webhook change nothing.
    await page.goto(`/settlements/${laptop}/payment/callback`);
    await page.waitForLoadState('load');
    t.has('revisiting the return address says it is paid, not an error', await mainText(page), 'Payment received. Thank you.');
    t.eq('...and records nothing twice', [mine(laptop), events(laptop, 'paid')], [['paid'], 1]);
    const payload = JSON.stringify({ event: 'charge.success', data: { id: 424242, status: 'success', reference: ref, amount: 370000, currency: 'ZAR', paid_at: new Date().toISOString(), metadata: { settlement_id: laptop } } });
    const sig = crypto.createHmac('sha512', KEY).update(payload).digest('hex');
    const hook = async (body2, signature) => sipho.ctx.request.post('/webhooks/paystack', { data: body2, headers: { 'content-type': 'application/json', 'x-paystack-signature': signature } });
    t.eq('a correctly signed duplicate webhook is accepted (200)', (await hook(payload, sig)).status(), 200);
    t.eq('...and changes nothing', [status(laptop), mine(laptop), events(laptop, 'paid')], ['paid', ['paid'], 1]);
    t.eq('a webhook with a wrong signature is refused (401)', (await hook(payload, 'f'.repeat(128))).status(), 401);
    t.eq('a webhook with no signature is refused (401)', (await hook(payload, '')).status(), 401);
    sipho.tolerate(/http401/);

    // The seller sees the paid state, and the winner sees it on every page that talks about the sale.
    const thandi = await t.person('thandi', { to: `/settlements/${laptop}` });
    body = await mainText(thandi.page);
    t.has('the seller sees "The buyer has paid."', body, 'The buyer has paid.');
    t.has('...and that payout is manual, honestly', body, 'pays sellers out by hand');
    t.eq('the seller has no Pay now', await thandi.page.getByRole('button', { name: 'Pay now' }).count(), 0);
    await thandi.go('/settlements?role=selling');
    t.has('the seller list shows the sale as Paid', flat(await thandi.page.locator('tr', { hasText: 'Dell Latitude' }).first().innerText()), 'Paid');
    await thandi.go(`/auctions/${t.id_of('Dell Latitude laptop, i5, 16 GB')}`);
    t.has('the lot page tells the seller the buyer paid', flat(await thandi.page.locator('.lot-pay').innerText()), 'The buyer paid R3,700.00');
    await t.audit(thandi, 'sale-paid-seller', { full: false });
    t.clean(thandi, 'seller view');
    await sipho.go('/dashboard');
    t.lacks('his Home no longer lists the laptop as owed', await mainText(page), 'You won Dell Latitude');
    await sipho.go('/won');
    t.has('Won shows the laptop as Paid with View sale', flat(await page.locator('tr', { hasText: 'Dell Latitude' }).first().innerText()), 'Paid');

    // ---------- failed, then try again (an overdue sale can still be paid) ----------
    await sipho.go(`/settlements/${chairs}`);
    body = await mainText(page);
    t.has('an overdue sale says so and that he can still pay', body, 'Payment is overdue.');
    t.has('...and until when', body, 'You can still pay until the seller cancels the sale.');
    await page.getByRole('button', { name: 'Pay now' }).click();
    await page.waitForURL(/\/dev\/fake-paystack\/AUC-/);
    const ref2 = new URL(page.url()).pathname.split('/').pop();
    await page.locator('#fake-fail').click();
    await page.waitForURL(new RegExp(`/settlements/${chairs}$`));
    body = await mainText(page);
    t.has('a declined payment is titled and explained', body, 'The payment did not go through');
    t.has('...with "you were not charged"', body, 'you were not charged');
    t.eq('database: the attempt is failed, the sale is still overdue and unpaid', [mine(chairs), status(chairs)], [['failed'], 'payment_overdue']);
    t.eq('he can try again: Pay now is still offered', await page.getByRole('button', { name: 'Pay now' }).count(), 1);
    await t.audit(sipho, 'sale-failed-return');
    await page.getByRole('button', { name: 'Pay now' }).click();
    await page.waitForURL(/\/dev\/fake-paystack\/AUC-/);
    const ref3 = new URL(page.url()).pathname.split('/').pop();
    t.check('a fresh attempt has a new reference', ref3 !== ref2, ref3);
    await page.locator('#fake-pay').click();
    await page.waitForURL(new RegExp(`/settlements/${chairs}$`));
    t.eq('the second attempt pays the overdue sale', [status(chairs), mine(chairs)], ['paid', ['failed', 'paid']]);
    t.has('and the page says so', await mainText(page), 'Payment received. Thank you.');
    t.clean(sipho, 'failed then paid');

    // ---------- unreachable gateway, abandoned, pending, lost confirmation (johan, the Buy Now sale) ----------
    const johan = await t.person('johan', { to: `/settlements/${nikon}` });
    const jp = johan.page;
    await setMode(johan, 'unreachable');
    await jp.getByRole('button', { name: 'Pay now' }).click();
    await jp.waitForLoadState('load');
    body = await mainText(jp);
    t.has('an unreachable gateway is explained in plain words, titled', body, 'Payment could not be started');
    t.has('...with "Nothing was charged"', body, 'Nothing was charged');
    t.eq('...and no half-made payment is left behind', mine(nikon), []);
    t.eq('...the sale is untouched', status(nikon), 'awaiting_payment');
    t.lacks('the message does not leak a key, a URL or a library error', body, 'sk_test');
    t.eq('he can try again', await jp.getByRole('button', { name: 'Pay now' }).count(), 1);
    await t.audit(johan, 'sale-gateway-down');
    await setMode(johan, 'error');
    await jp.getByRole('button', { name: 'Pay now' }).click();
    await jp.waitForLoadState('load');
    t.has('a gateway error says it is having trouble and nothing was charged', await mainText(jp), 'Paystack is having trouble right now. Nothing was charged.');
    await setMode(johan, 'ok');
    await jp.getByRole('button', { name: 'Pay now' }).click();
    await jp.waitForURL(/\/dev\/fake-paystack\//);
    const r4 = new URL(jp.url()).pathname.split('/').pop();
    await jp.locator('#fake-abandon').click();
    await jp.waitForURL(new RegExp(`/settlements/${nikon}$`));
    t.has('closing the checkout without paying: "did not go through, not charged"', await mainText(jp), 'The payment did not go through and you were not charged');
    t.eq('database: attempt failed, sale unpaid', [mine(nikon), status(nikon)], [['failed'], 'awaiting_payment']);
    // pending
    await jp.getByRole('button', { name: 'Pay now' }).click();
    await jp.waitForURL(/\/dev\/fake-paystack\//);
    const r5 = new URL(jp.url()).pathname.split('/').pop();
    await jp.goto(`/dev/fake-paystack/${r5}/complete?result=pending`);
    await jp.waitForURL(new RegExp(`/settlements/${nikon}$`));
    body = await mainText(jp);
    t.has('a payment still being processed is titled so', body, 'Payment is being confirmed');
    t.has('...and says what happens next', body, 'will show it as paid once Paystack confirms');
    t.eq('database: still initiated, still unpaid', [status(nikon), mine(nikon).at(-1)], ['awaiting_payment', 'initiated']);
    await t.audit(johan, 'sale-pending-return', { full: false });
    // The shopper paid at the gateway, but the gateway cannot be asked when he comes back: nothing changes, and he is told so.
    await setMode(johan, 'unreachable');
    await jp.goto(`/dev/fake-paystack/${r5}/complete?result=success`);
    await jp.waitForURL(new RegExp(`/settlements/${nikon}$`));
    body = await mainText(jp);
    t.has('when the gateway cannot be asked, the return says it could not confirm', body, 'We could not confirm your payment yet');
    t.has('...and that nothing has changed on the sale', body, 'Nothing has changed on this sale');
    t.eq('database: still unpaid', status(nikon), 'awaiting_payment');
    await t.audit(johan, 'sale-unconfirmed-return', { full: false });
    await setMode(johan, 'ok');
    await jp.goto(`/settlements/${nikon}/payment/callback`);
    await jp.waitForLoadState('load');
    t.has('once the gateway answers, reloading the return confirms it', await mainText(jp), 'Payment received. Thank you.');
    t.eq('database: paid, with one paid event', [status(nikon), events(nikon, 'paid')], ['paid', 1]);
    t.clean(johan, 'payment variants');

    // ---------- who may pay ----------
    {
        const lerato = await t.person('lerato');
        const csrf = async (p, path) => { await p.go(path); return p.page.locator('meta[name="csrf-token"]').getAttribute('content'); };
        const tok = await csrf(lerato, '/settlements');
        const stranger = await lerato.ctx.request.post(`/settlements/${nikon}/pay`, { form: { _token: tok }, maxRedirects: 0 });
        t.eq("a stranger cannot start a payment on someone's sale (404, not even confirmed to exist)", stranger.status(), 404);
        const tt = await csrf(thandi, '/settlements');
        const sellerPays = await thandi.ctx.request.post(`/settlements/${chairs}/pay`, { form: { _token: tt }, maxRedirects: 0 });
        t.eq('the seller cannot pay for the buyer (403)', sellerPays.status(), 403);
        const tj = await csrf(johan, '/settlements');
        const paidAgain = await johan.ctx.request.post(`/settlements/${nikon}/pay`, { form: { _token: tj, amount: '1', amount_cents: '1' }, maxRedirects: 0 });
        t.check('paying an already paid sale is refused politely (a redirect with a message, never a charge)', [302, 303, 403].includes(paidAgain.status()), String(paidAgain.status()));
        t.eq('a forged amount in the request changed nothing', t.sql(`select amount_cents from auction_settlements where id='${nikon}'`), '520000');
        t.eq('no new payment attempt was created for the paid sale', mine(nikon).length, 2);
        lerato.tolerate(/http404/); thandi.tolerate(/http403/); johan.tolerate(/http40/);
    }
    for (const p of [sipho, thandi, johan]) { t.clean(p, 'end'); }
});
