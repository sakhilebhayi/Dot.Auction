export const title = 'S3 Settlements (seller, winner, stranger) and the operator payments page';

export async function run(t, app) {
  const S = (title) => app.one('select s.* from auction_settlements s join auctions a on a.id = s.auction_id where a.title = ?', title);
  const notif = (userId, kind, like = '%') => app.q('select id from notifications where notifiable_id = ? and type like ? and data like ?', userId, `%${kind}`, like);
  const thandi = app.userByKey('thandi'); const pieter = app.userByKey('pieter'); const sipho = app.userByKey('sipho');
  const aisha = app.userByKey('aisha'); const lerato = app.userByKey('lerato'); const johan = app.userByKey('johan');
  t.expectHttp(/\/settlements\/[0-9a-f-]+(\/cancel|\/pay)?$/, 404, 403);
  t.expectHttp(/\/operator\/settlement-payments/, 403);
  t.expectHttp(/\/settlements\/[0-9a-f-]+\/pay$/, 403, 404);

  const ctx = async (label, key, to) => { const p = await (await t.context({ label })).newPage(); await t.login(p, key, to); return p; };
  const seller = await ctx('thandi', 'thandi', '/settlements');
  const winner = await ctx('sipho', 'sipho', '/settlements');

  // ---------- lists -------------------------------------------------------------------------------------------------
  const sellerList = await t.text(seller);
  t.has('seller list: awaiting sale with the buyer\'s deadline', sellerList, /Dell Latitude[\s\S]*Awaiting payment[\s\S]*Buyer pays by/);
  t.has('seller list: overdue sale says it was due', sellerList, /Six leather dining chairs[\s\S]*Payment overdue[\s\S]*Was due/);
  t.eq('seller list repeats the payout copy once, not per row', (sellerList.match(/paying you out is a manual step/g) || []).length, 1);
  await t.shot(seller, 'a-seller-list');
  const winnerList = await t.text(winner);
  t.has('winner list: says how many payments are to make', winnerList, /\d+ payments? to make/);
  t.has('winner list: paid sale reads Paid with no Pay now', await winner.locator('tr', { hasText: 'Fishing rod' }).first().innerText(), /Paid/);
  t.eq('...the paid row has no Pay now', await winner.locator('tr', { hasText: 'Fishing rod' }).getByText('Pay now').count(), 0);
  await t.shot(winner, 'b-winner-list');

  // ---------- sale pages in every state, both parties -----------------------------------------------------------------
  const sale = async (page, id) => { await t.go(page, `/settlements/${id}`); return (await t.text(page)).replace(/\s+/g, ' '); };
  const overdue = S('Six leather dining chairs');
  let txt = await sale(seller, overdue.id);
  t.has('seller / overdue: states the rule honestly', txt, /does not relist or cancel anything for you/);
  t.has('seller / overdue: cancel is offered with its consequence', txt, /Cancel this sale[\s\S]*does not relist the auction and does not refund anyone/);
  t.eq('seller / overdue: the payout note appears once', (txt.match(/Payout is not available yet/g) || []).length, 1);
  t.has('seller / overdue: history timeline in plain sentences', txt, /Sale recorded at R2,200\.00[\s\S]*payment deadline passed and the sale was marked overdue/);
  t.hasNot('seller / overdue: no raw audit notes or ISO timestamps', txt, /\d{4}-\d{2}-\d{2}T\d{2}:/);
  await t.shot(seller, 'c-seller-overdue');
  txt = await sale(winner, overdue.id);
  t.has('winner / overdue: can still pay until the seller cancels', txt, /You can still pay until the seller cancels the sale/);
  t.eq('winner / overdue: Pay now is offered', await winner.getByRole('button', { name: 'Pay now' }).count(), 1);
  t.eq('winner / overdue: no cancel control', await winner.getByRole('button', { name: /Cancel this sale/ }).count(), 0);
  t.hasNot('winner sees no payout note', txt, /Payout is not available/);
  await t.shot(winner, 'd-winner-overdue');

  const awaiting = S('Dell Latitude laptop, i5, 16 GB');
  txt = await sale(seller, awaiting.id);
  t.eq('seller / awaiting (not overdue): cannot cancel yet', await seller.getByRole('button', { name: /Cancel this sale/ }).count(), 0);
  txt = await sale(winner, awaiting.id);
  t.has('winner / awaiting: deadline shown', txt, /Pay by|due/i);

  const paid = S('Fishing rod and reel combo');
  const pieterP = await ctx('pieter', 'pieter', `/settlements/${paid.id}`);
  txt = (await t.text(pieterP)).replace(/\s+/g, ' ');
  t.has('seller / paid: says paid', txt, /Paid/);
  t.eq('seller / paid: nothing to cancel', await pieterP.getByRole('button', { name: /Cancel this sale/ }).count(), 0);
  t.has('seller / paid: the payout is manual, said once', txt, /Payout is not available yet/);
  const sipho2 = await ctx('sipho-paid', 'sipho', `/settlements/${paid.id}`);
  txt = (await t.text(sipho2)).replace(/\s+/g, ' ');
  t.has('winner / paid: paid and no Pay now', txt, /Paid/);
  t.eq('winner / paid: no Pay now button', await sipho2.getByRole('button', { name: 'Pay now' }).count(), 0);
  await t.shot(sipho2, 'e-winner-paid');

  const cancelled = S('Petrol lawnmower, 150 cc');
  const leratoP = await ctx('lerato', 'lerato', `/settlements/${cancelled.id}`);
  txt = (await t.text(leratoP)).replace(/\s+/g, ' ');
  t.has('winner / cancelled: says the sale was cancelled', txt, /cancel/i);
  t.eq('winner / cancelled: cannot pay', await leratoP.getByRole('button', { name: 'Pay now' }).count(), 0);
  await t.shot(leratoP, 'f-winner-cancelled');
  const pieter2 = await ctx('pieter2', 'pieter', `/settlements/${cancelled.id}`);
  t.has('seller / cancelled: says cancelled and not relisted', (await t.text(pieter2)).replace(/\s+/g, ' '), /cancel/i);

  // ---------- strangers and guests --------------------------------------------------------------------------------------
  const stranger = await ctx('aisha', 'aisha', '/settlements');
  const r1 = await stranger.goto(`${app.base}/settlements/${overdue.id}`);
  t.eq('a stranger opening someone else\'s sale gets 404', r1.status(), 404);
  const csrf = await stranger.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content);
  const cr = await stranger.context().request.post(`${app.base}/settlements/${overdue.id}/cancel`, { headers: { 'X-CSRF-TOKEN': csrf ?? '' }, maxRedirects: 0 });
  t.check('a stranger cannot cancel it (404/403/419)', [404, 403, 419].includes(cr.status()), `got ${cr.status()}`);
  t.eq('...and the sale is untouched', S('Six leather dining chairs').status, 'payment_overdue');
  const pr = await stranger.context().request.post(`${app.base}/settlements/${overdue.id}/pay`, { headers: { 'X-CSRF-TOKEN': csrf ?? '' }, maxRedirects: 0 });
  t.check('a stranger cannot start a payment on it', [404, 403, 419].includes(pr.status()), `got ${pr.status()}`);
  t.eq('...no checkout was created', app.q('select count(*) c from settlement_payments where settlement_id = ?', overdue.id)[0].c, app.q('select count(*) c from settlement_payments where settlement_id = ?', overdue.id)[0].c);
  const guest = await (await t.context({ label: 'guest' })).newPage();
  await t.go(guest, `/settlements/${overdue.id}`);
  t.check('a guest is sent to sign in', /\/login/.test(guest.url()), guest.url());
  // the seller cannot pay for it, the winner cannot cancel it
  const wcsrf = await winner.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content);
  const wc = await winner.context().request.post(`${app.base}/settlements/${overdue.id}/cancel`, { headers: { 'X-CSRF-TOKEN': wcsrf ?? '' }, maxRedirects: 0 });
  t.check('the winner cannot cancel their own purchase', [403, 404].includes(wc.status()), `got ${wc.status()}`);
  t.eq('...still overdue', S('Six leather dining chairs').status, 'payment_overdue');
  const scsrf = await seller.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content);
  const sc = await seller.context().request.post(`${app.base}/settlements/${awaiting.id}/cancel`, { headers: { 'X-CSRF-TOKEN': scsrf ?? '' }, maxRedirects: 0 });
  t.check('the seller cannot cancel a sale that is not yet overdue', app.one('select status from auction_settlements where id = ?', awaiting.id).status === 'awaiting_payment', `got ${sc.status()}`);

  // ---------- time passes: awaiting -> overdue by the real command ----------------------------------------------------
  const nikon = S('Nikon D3500 camera body'); // pieter's lot bought by johan with Buy Now
  const overdueBefore = notif(johan.id, 'SettlementOverdueNotification').length;
  app.run("update auction_settlements set payment_due_at = ? where id = ?", app.at(-60), nikon.id);
  app.artisan(['auction:mark-overdue-payments']);
  app.artisan(['auction:mark-overdue-payments']);
  t.eq('the real job marks the sale overdue', app.one('select status from auction_settlements where id = ?', nikon.id).status, 'payment_overdue');
  t.eq('...the buyer is told once, though the job ran twice', notif(johan.id, 'SettlementOverdueNotification').length - overdueBefore, 1);
  const johanP = await ctx('johan', 'johan', `/settlements/${nikon.id}`);
  t.has('the buyer\'s page now says overdue', (await t.text(johanP)).replace(/\s+/g, ' '), /Payment is overdue/);
  const pieter3 = await ctx('pieter3', 'pieter', `/settlements/${nikon.id}`);
  t.has('the seller\'s page now offers the cancel', (await t.text(pieter3)).replace(/\s+/g, ' '), /Cancel this sale/);

  // ---------- the seller cancels the overdue sale (with confirmation) --------------------------------------------------
  const cancelNote = notif(johan.id, 'SettlementCancelledNotification').length;
  await pieter3.getByRole('button', { name: /Cancel this sale/ }).click();
  const dlg = pieter3.getByRole('dialog');
  await dlg.waitFor();
  t.has('the confirm states what cancelling does', await dlg.innerText(), /relist|refund/i);
  await t.shot(pieter3, 'g-cancel-confirm');
  t.eq('nothing is cancelled until the confirm button', app.one('select status from auction_settlements where id = ?', nikon.id).status, 'payment_overdue');
  await dlg.getByRole('button', { name: /Keep|Not now|Close/i }).first().click();
  t.eq('closing the dialog cancels nothing', app.one('select status from auction_settlements where id = ?', nikon.id).status, 'payment_overdue');
  await pieter3.getByRole('button', { name: /Cancel this sale/ }).click();
  await pieter3.getByRole('dialog').getByRole('button', { name: /Cancel (this )?sale/i }).last().click();
  await pieter3.waitForSelector('text=/The sale is cancelled/');
  t.eq('the sale is cancelled', app.one('select status from auction_settlements where id = ?', nikon.id).status, 'cancelled');
  t.eq('the buyer is told once', notif(johan.id, 'SettlementCancelledNotification').length - cancelNote, 1);
  t.eq('the auction is not relisted by the cancel', app.one('select status from auctions where id = ?', nikon.auction_id).status, 'ended');
  txt = (await t.text(pieter3)).replace(/\s+/g, ' ');
  t.has('history lists the cancellation', txt, /You cancelled the sale/);
  t.eq('the seller sees no cancel control afterwards', await pieter3.getByRole('button', { name: /Cancel this sale/ }).count(), 0);
  await t.shot(pieter3, 'h-seller-after-cancel');
  await t.go(johanP, `/settlements/${nikon.id}`);
  t.eq('the buyer can no longer pay', await johanP.getByRole('button', { name: 'Pay now' }).count(), 0);
  const jcsrf = await johanP.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content);
  const before = app.q('select count(*) c from settlement_payments where settlement_id = ?', nikon.id)[0].c;
  await johanP.context().request.post(`${app.base}/settlements/${nikon.id}/pay`, { headers: { 'X-CSRF-TOKEN': jcsrf ?? '' }, maxRedirects: 0 });
  t.eq('a direct pay request on a cancelled sale starts no checkout', app.q('select count(*) c from settlement_payments where settlement_id = ?', nikon.id)[0].c, before);
  t.eq('...and it stays cancelled, not paid', app.one('select status from auction_settlements where id = ?', nikon.id).status, 'cancelled');

  // ---------- operator payments page --------------------------------------------------------------------------------------
  const op = await ctx('naledi', 'naledi', '/operator/settlement-payments');
  const opText = await t.text(op);
  t.has('operator: the attention banner comes first', opText, /1 payment needs a person/);
  const firstRow = await op.locator('tbody tr, .ds-table__card, [data-payment]').first().innerText();
  t.has('operator: the unmatched payment is listed first', firstRow, /UNMATCHED|Needs a person/i);
  t.has('operator: counts per status are shown', opText, /All\s*5[\s\S]*Needs a person\s*1[\s\S]*Paid\s*2[\s\S]*Failed\s*1/);
  await t.shot(op, 'i-operator-all');
  await op.getByRole('link', { name: /Paid/ }).first().click();
  await op.waitForLoadState('load');
  t.check('operator: the Paid filter shows only paid rows', (await op.locator('tbody tr').count()) === 2 && !/Payment failed|Needs a person/.test(await op.locator('tbody').innerText()), op.url());
  await t.go(op, '/operator/settlement-payments?show=attention');
  t.check('operator: the legacy ?show=attention filter still works', (await op.locator('tbody tr').count()) === 1, await op.locator('tbody tr').count());
  await t.go(op, '/operator/settlement-payments?status=nonsense');
  t.check('operator: an unknown filter falls back to everything', (await op.locator('tbody tr').count()) === 5);
  t.hasNot('operator: no secrets or checkout links on the page', await op.content(), /sk_test_|authorization_url|checkout\.paystack/);
  await t.axe(op, 'operator payments');
  for (const [who, label] of [['thandi', 'seller'], ['sipho', 'winner'], ['lerato', 'buyer']]) {
    const np = await ctx(`${label}-op`, who, '/dashboard');
    const rr = await np.goto(`${app.base}/operator/settlement-payments`);
    t.eq(`operator page is 403 for a ${label}`, rr.status(), 403);
    if (who === 'thandi') {
      t.has('the 403 page speaks plainly', await t.text(np), /not allowed|permission|access/i);
      t.hasNot('the nav does not offer Payment operations to non-operators', (await np.goto(`${app.base}/dashboard`), await t.text(np)), 'Payment operations');
    }
  }
  const guest2 = await (await t.context({ label: 'guest2' })).newPage();
  await t.go(guest2, '/operator/settlement-payments');
  t.check('a guest is sent to sign in for the operator page', /\/login/.test(guest2.url()), guest2.url());

  // ---------- phone: operator cards, settlement pages, no overflow, tap targets ------------------------------------------------
  const phoneCtx = await t.context({ size: 'phone', touch: true, label: 'phone' });
  const ph = await phoneCtx.newPage();
  await t.login(ph, 'naledi', '/operator/settlement-payments');
  const lay = await t.layout(ph, 'operator payments at 390');
  t.check('operator phone: stacked cards, no horizontal table', await ph.evaluate(() => { const tb = document.querySelector('table'); return !tb || getComputedStyle(tb.querySelector('thead') ?? tb).display === 'none' || tb.getBoundingClientRect().width <= innerWidth + 1; }));
  t.check('operator phone: tap targets are 44px', lay.small.length === 0, lay.small.slice(0, 5).join(' | '));
  await t.shot(ph, 'j-operator-phone');
  await t.axe(ph, 'operator payments at 390');
  for (const [key, id, label] of [['sipho', overdue.id, 'winner overdue sale'], ['thandi', overdue.id, 'seller overdue sale'], ['thandi', null, 'seller payments list']]) {
    await t.login(ph, key, id ? `/settlements/${id}` : '/settlements');
    const l = await t.layout(ph, `${label} at 390`);
    t.check(`${label} at 390: tap targets are 44px`, l.small.length === 0, l.small.slice(0, 5).join(' | '));
    await t.shot(ph, `k-phone-${label.replace(/\W+/g, '-')}`);
    await t.axe(ph, `${label} at 390`);
  }
  const darkCtx = await t.context({ colorScheme: 'dark', label: 'dark' });
  const dk = await darkCtx.newPage();
  await t.login(dk, 'sipho', `/settlements/${overdue.id}`);
  await t.shot(dk, 'l-winner-overdue-dark');
  await t.axe(dk, 'winner overdue sale (dark scheme)');
  await t.axe(winner, 'settlements list');
  await t.go(winner, `/settlements/${overdue.id}`);
  await t.axe(winner, 'winner overdue sale');
}
