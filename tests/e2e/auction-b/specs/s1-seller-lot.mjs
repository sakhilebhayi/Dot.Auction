import { writeFixtures } from '../lib/fixtures.mjs';

export const title = 'S1 Seller creates a lot end to end';

const sast = (d) => new Date(d.getTime() + 2 * 3600000).toISOString().slice(0, 16); // value for datetime-local, in SAST
const utcToSast = (s) => sast(new Date(s.replace(' ', 'T') + 'Z'));

export async function run(t, app) {
  const F = writeFixtures();
  const sellerCtx = await t.context({ label: 'seller' });
  const buyerCtx = await t.context({ label: 'buyer' });
  const p = await sellerCtx.newPage();
  const b = await buyerCtx.newPage();
  const zanele = app.userByKey('zanele');
  t.expectHttp(/\/seller\/auctions\/\d+\/images$/, 422); // refusals of bad photos are the point of the test
  t.expectHttp(/\/auctions\/\d+$/, 404);                   // a buyer opening a draft
  await t.login(p, 'zanele', '/seller/auctions');
  await t.login(b, 'lerato', '/auctions');

  // ---- empty list for a brand-new seller ----------------------------------------------------------------------------
  t.has('new seller sees the honest empty state', await t.text(p), 'You have not listed anything yet');
  await t.shot(p, '01-list-empty');

  // ---- validation errors: summary, focus, jump links ----------------------------------------------------------------
  await t.go(p, '/seller/auctions/create');
  await t.shot(p, '02-create-blank');
  await p.getByRole('button', { name: 'Save draft and add photos' }).click();
  await p.waitForSelector('#error-summary');
  t.has('error summary names the count', await p.locator('#error-summary').innerText(), /There are \d+ problems to fix/);
  t.eq('error summary receives focus', await p.evaluate(() => document.activeElement?.id), 'error-summary');
  const jumps = await p.$$eval('#error-summary [data-jump]', (a) => a.map((x) => x.getAttribute('href')));
  t.check('every required field has a jump link', ['#title', '#category_id', '#starting_price', '#bid_increment'].every((h) => jumps.includes(h)), jumps.join(','));
  await p.locator('#error-summary a[href="#starting_price"]').click();
  t.eq('a jump link moves focus to its field', await p.evaluate(() => document.activeElement?.id), 'starting_price');
  t.check('the field is marked invalid and described by its message', await p.evaluate(() => { const e = document.getElementById('starting_price'); return e.getAttribute('aria-invalid') === 'true' && !!e.getAttribute('aria-describedby'); }));
  await t.shot(p, '03-create-errors');
  await t.axe(p, 'create form with errors');

  // a rule-level error (closing before opening / reserve under start) is reported per field too
  await p.fill('#title', 'Dot E2E Walnut sideboard');
  await p.selectOption('#category_id', { label: 'Home & Furniture' });
  await p.fill('#starting_price', '500');
  await p.fill('#bid_increment', '50');
  await p.fill('#reserve_price', '300');
  await p.fill('#buy_now_price', '400');
  await p.fill('#ends_at', '2020-01-01T10:00');
  await p.getByRole('button', { name: 'Save draft and add photos' }).click();
  await p.waitForSelector('#error-summary');
  const ruleText = await p.locator('#error-summary').innerText();
  t.has('reserve below the start is refused with a reason', ruleText, /reserve/i);
  t.has('a closing time in the past is refused with a reason', ruleText, /clos/i);
  t.eq('nothing was saved by the refused submit', app.q('select count(*) c from auctions where seller_id = ?', zanele.id)[0].c, 0);
  t.eq('the typed values survive a refused submit', await p.inputValue('#title'), 'Dot E2E Walnut sideboard');

  // ---- times in SAST, stored as UTC ---------------------------------------------------------------------------------
  const closeAt = new Date(Date.now() + 3 * 86400000); closeAt.setUTCHours(16, 0, 0, 0); // 18:00 SAST
  const closeInput = sast(closeAt);
  await p.fill('#reserve_price', '600');
  await p.fill('#buy_now_price', '900');
  await p.fill('#description', 'A solid walnut sideboard, two doors and three drawers. Small scratch on the left side.');
  await p.fill('#ends_at', closeInput);
  t.has('the form says times are South Africa time', await t.text(p), 'South Africa time (SAST)');
  await t.shot(p, '04-create-filled');
  await p.getByRole('button', { name: 'Save draft and add photos' }).click();
  await p.waitForURL(/\/seller\/auctions\/\d+\/edit/);
  const lot = app.one('select * from auctions where seller_id = ? order by id desc', zanele.id);
  t.check('the draft is saved', lot && lot.status === 'draft', JSON.stringify(lot?.status));
  t.eq('closing time typed in SAST (18:00) is stored as UTC (16:00)', lot.ends_at.slice(11, 16), '16:00');
  t.eq('the date is the one typed', lot.ends_at.slice(0, 10), closeAt.toISOString().slice(0, 10));
  t.eq('the edit form shows the same SAST time back', await p.inputValue('#ends_at'), closeInput);
  t.has('draft-saved message is shown', await t.text(p), 'Draft saved');
  t.eq('money is stored exactly', [lot.starting_price, lot.reserve_price, lot.buy_now_price, lot.bid_increment].map(Number), [500, 600, 900, 50]);
  const id = lot.id;

  // ---- photos --------------------------------------------------------------------------------------------------------
  const fileInput = p.locator('input[type=file]#images');
  const queueText = async () => (await p.locator('[data-queue]').innerText()).replace(/\s+/g, ' ');
  // wrong type: the page says so before sending anything
  await fileInput.setInputFiles(F['notes.txt']);
  t.has('a text file is refused in the page before upload', await queueText(), /Not a JPG, PNG or WebP photo/);
  t.eq('...and nothing is saved', app.q('select count(*) c from auction_images where auction_id = ?', id)[0].c, 0);
  await fileInput.setInputFiles(F['invoice.pdf']);
  t.has('a PDF is refused too', await queueText(), /Not a JPG, PNG or WebP photo/);
  // oversize
  await fileInput.setInputFiles(F['oversize.png']);
  t.has('an oversize photo is refused before upload', await queueText(), /Larger than 4 MB/);
  await t.shot(p, '05-upload-refused-client');
  t.expectHttp(/\/seller\/auctions\/\d+\/images$/, 422); // the server refusals below are the point of the test
  // a file the page lets through but the server refuses: disguised script named .jpg (the browser type comes from the name)
  await fileInput.setInputFiles({ name: 'sideboard.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('<?php echo 1; ?> <html></html>') });
  await p.locator('[data-upload-submit]').click();
  await p.waitForFunction(() => !document.querySelector('[data-upload-error]').hidden, null, { timeout: 15000 });
  const disguisedMsg = await p.locator('[data-upload-error]').innerText();
  t.has('a script disguised as .jpg is refused by the server with words', disguisedMsg, /JPG, PNG or WebP|valid/i);
  t.eq('...nothing stored', app.q('select count(*) c from auction_images where auction_id = ?', id)[0].c, 0);
  await t.shot(p, '06-upload-refused-server');
  // decompression bomb, truncated, via the page (these pass the client check)
  for (const [name, label] of [['bigdim.png', 'a 25-megapixel image'], ['truncated.png', 'a truncated PNG']]) {
    await fileInput.setInputFiles({ name, mimeType: 'image/png', buffer: (await import('node:fs')).readFileSync(F[name]) });
    await p.locator('[data-upload-submit]').click();
    await p.waitForFunction(() => !document.querySelector('[data-upload-error]').hidden && document.querySelector('[data-upload-error] span').textContent.length > 0, null, { timeout: 15000 });
    t.has(`${label} is refused by the server`, await p.locator('[data-upload-error]').innerText(), /JPG, PNG or WebP|valid/i);
  }
  t.eq('...still nothing stored', app.q('select count(*) c from auction_images where auction_id = ?', id)[0].c, 0);

  // good photos: two at once
  await fileInput.setInputFiles([F['photo-1.png'], F['photo-2.png']]);
  t.has('two good photos are queued', await queueText(), /photo-1\.png.*photo-2\.png/);
  await p.locator('[data-upload-submit]').click();
  await p.waitForFunction(() => document.querySelectorAll('.sl-grid__item').length === 2, null, { timeout: 20000 });
  t.eq('two photos stored', app.q('select count(*) c from auction_images where auction_id = ?', id)[0].c, 2);
  t.has('counter reads 2 of 6', await p.locator('[data-photo-count]').innerText(), '2 of 6');
  t.has('first photo is the cover', await p.locator('.sl-grid__item').first().innerText(), 'Cover');

  // six-photo limit: four more fit, a seventh does not
  await fileInput.setInputFiles([F['photo-3.png'], F['photo-4.png'], F['photo-5.png'], F['photo-6.png']]);
  await p.locator('[data-upload-submit]').click();
  await p.waitForFunction(() => document.querySelectorAll('.sl-grid__item').length === 6, null, { timeout: 20000 });
  t.has('six photos: the limit is stated and the upload box is gone', await t.text(p), /maximum of 6 photos/);
  t.eq('no file input once the limit is reached', await p.locator('input[type=file]#images').count(), 0);
  // the server still refuses a seventh sent around the page (policy and race, not the UI)
  const csrf = await p.locator('meta[name=csrf-token]').getAttribute('content');
  const seventh = await sellerCtx.request.post(`${app.base}/seller/auctions/${id}/images`, {
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    multipart: { 'images[]': { name: 'photo-7.png', mimeType: 'image/png', buffer: (await import('node:fs')).readFileSync(F['photo-7.png']) } },
  });
  t.eq('a seventh photo sent directly is refused (422)', seventh.status(), 422);
  t.has('...with the reason', (await seventh.json()).message, /at most 6 photos/);
  t.eq('still six stored', app.q('select count(*) c from auction_images where auction_id = ?', id)[0].c, 6);
  await t.shot(p, '07-six-photos');

  // photo files are private: a stranger gets 404, the seller gets a real image
  const img = app.one('select * from auction_images where auction_id = ? order by position', id);
  const own = await sellerCtx.request.get(`${app.base}/auctions/${id}/images/${img.id}`);
  t.eq('seller can read their own photo', [own.status(), own.headers()['content-type']], [200, 'image/png']);
  t.eq('photo is served with nosniff', own.headers()['x-content-type-options'], 'nosniff');
  const stranger = await buyerCtx.request.get(`${app.base}/auctions/${id}/images/${img.id}`);
  t.eq('a buyer cannot read a draft photo (404, not 403)', stranger.status(), 404);

  // remove one photo with its confirm dialog, then add the polyglot (valid image with script appended)
  await p.locator('.sl-grid__item').nth(5).getByRole('button', { name: /Remove/ }).click();
  await p.getByRole('dialog').getByRole('button', { name: 'Remove photo' }).click();
  await p.waitForLoadState('load');
  t.eq('removed one photo', app.q('select count(*) c from auction_images where auction_id = ?', id)[0].c, 5);
  await p.locator('input[type=file]#images').setInputFiles(F['polyglot.png']);
  await p.locator('[data-upload-submit]').click();
  await p.waitForFunction(() => document.querySelectorAll('.sl-grid__item').length === 6, null, { timeout: 20000 });
  const poly = app.one('select * from auction_images where original_name = ?', 'polyglot.png');
  const polyRes = await sellerCtx.request.get(`${app.base}/auctions/${id}/images/${poly.id}`);
  t.eq('a PNG with script appended is served as an image, nosniff, never as HTML', [polyRes.headers()['content-type'], polyRes.headers()['x-content-type-options']], ['image/png', 'nosniff']);

  // ---- the draft is invisible to buyers ----------------------------------------------------------------------------
  const draftDetail = await b.goto(`${app.base}/auctions/${id}`);
  t.eq('buyers get 404 for a draft lot page', draftDetail.status(), 404);
  t.expectHttp(new RegExp(`/auctions/${id}$`), 404);
  await t.go(b, '/auctions?q=Walnut');
  t.hasNot('draft is not in browse for buyers', await t.text(b), 'Dot E2E Walnut sideboard');

  // ---- seller list: tab counts -------------------------------------------------------------------------------------
  await t.go(p, '/seller/auctions');
  const tabCounts = async () => Object.fromEntries((await p.$$eval('[aria-label="Filter your lots by state"] a, nav[aria-label="Filter your lots by state"] a', (as) => as.map((a) => a.innerText.replace(/\s+/g, ' ').trim()))).map((s) => { const m = /^(.*?)\s*(\d+)$/.exec(s); return m ? [m[1], Number(m[2])] : [s, null]; }));
  let tabs = await tabCounts();
  t.eq('list tabs: 1 lot in all, 1 draft', [tabs['All lots'], tabs['Drafts']], [1, 1]);
  await t.shot(p, '08-list-one-draft');

  // ---- edit a draft, then publish ----------------------------------------------------------------------------------
  await t.go(p, `/seller/auctions/${id}/edit`);
  await p.fill('#title', 'Dot E2E Walnut sideboard (edited)');
  await p.getByRole('button', { name: 'Save changes' }).click();
  await p.waitForSelector('text=Changes saved');
  t.eq('title edit stored', app.one('select title from auctions where id = ?', id).title, 'Dot E2E Walnut sideboard (edited)');
  await p.getByRole('button', { name: 'Publish lot' }).click();
  await t.shot(p, '09-publish-confirm');
  await p.getByRole('dialog').getByRole('button', { name: 'Publish lot' }).click();
  await p.waitForURL(/\/seller\/auctions$/);
  t.has('publish message says the lot is live', await t.text(p), /is live/);
  t.eq('status is active', app.one('select status from auctions where id = ?', id).status, 'active');
  tabs = await tabCounts();
  t.eq('tabs after publish: live 1, drafts gone', [tabs['Live'], tabs['Drafts'] ?? 0], [1, 0]);

  // ---- the buyer sees it now ---------------------------------------------------------------------------------------
  await t.go(b, '/auctions?q=Walnut');
  t.has('published lot appears in browse for a buyer', await t.text(b), 'Dot E2E Walnut sideboard (edited)');
  const live = await b.goto(`${app.base}/auctions/${id}`);
  t.eq('buyer opens the live lot', live.status(), 200);
  t.hasNot('reserve amount is never shown to the buyer', await t.text(b), 'R600');

  // ---- edit a live lot with no bids: terms still editable -----------------------------------------------------------
  await t.go(p, `/seller/auctions/${id}/edit`);
  t.has('live lot without bids says terms can still change', await t.text(p), /Until the first bid arrives you can still change the terms/);
  await p.fill('#bid_increment', '25');
  await p.getByRole('button', { name: 'Save changes' }).click();
  await p.waitForSelector('text=Changes saved');
  t.eq('increment edit stored on a live lot', Number(app.one('select bid_increment from auctions where id = ?', id).bid_increment), 25);

  // ---- a bid locks the terms (stale form race) ----------------------------------------------------------------------
  await t.go(p, `/seller/auctions/${id}/edit`); // seller keeps this open
  await t.go(b, `/auctions/${id}`);
  await b.getByLabel(/Your bid|bid amount/i).first().fill('550');
  await b.getByRole('button', { name: /Place bid|Review/i }).first().click();
  const confirm = b.getByRole('dialog').getByRole('button', { name: /Place bid|Confirm/i }).first();
  await confirm.waitFor();
  await t.shot(b, '10-bid-confirm');
  await confirm.click();
  await b.waitForFunction(() => /You are leading|leading/i.test(document.body.innerText), null, { timeout: 15000 });
  t.eq('a bid exists', app.q('select count(*) c from bids where auction_id = ?', id)[0].c, 1);
  await p.fill('#description', 'Description changed while the bid arrived.');
  await p.fill('#bid_increment', '5');
  await p.getByRole('button', { name: 'Save changes' }).click();
  await p.waitForSelector('.ds-alert--success.sl-gap:has-text("Description saved")');
  t.has('stale form after a bid: the seller is told the terms were not changed (not "Changes saved")', await p.locator('.ds-alert--success.sl-gap').first().innerText(), /A bid arrived while this page was open.*not changed/);
  t.eq('increment unchanged by the stale form', Number(app.one('select bid_increment from auctions where id = ?', id).bid_increment), 25);
  t.eq('the description from the stale form was saved', app.one('select description from auctions where id = ?', id).description, 'Description changed while the bid arrived.');
  await t.go(p, `/seller/auctions/${id}/edit`);
  t.has('edit page now explains the locked terms', await t.text(p), 'The terms are locked');
  t.eq('price fields are not offered when locked', await p.locator('#starting_price').count(), 0);
  t.eq('cancel is not offered once a bid exists', await p.getByRole('button', { name: 'Cancel lot' }).count(), 0);
  await p.fill('#description', 'Description can still change after a bid.');
  await p.getByRole('button', { name: 'Save changes' }).click();
  await p.waitForSelector('text=Changes saved');
  t.eq('description edit stored while locked', app.one('select description from auctions where id = ?', id).description, 'Description can still change after a bid.');
  // direct request around the UI: cancel with bids
  const csrf2 = await p.locator('meta[name=csrf-token]').getAttribute('content');
  const cancelWithBids = await sellerCtx.request.post(`${app.base}/seller/auctions/${id}/cancel`, { headers: { 'X-CSRF-TOKEN': csrf2 }, maxRedirects: 0 });
  t.check('cancel with bids sent directly does not cancel', app.one('select status from auctions where id = ?', id).status === 'active', `status ${cancelWithBids.status()}`);
  await t.shot(p, '11-edit-locked');

  // ---- cancel a lot with no bids -----------------------------------------------------------------------------------
  await t.go(p, '/seller/auctions/create');
  const mk = async (name, extra = {}) => {
    await t.go(p, '/seller/auctions/create');
    await p.fill('#title', name);
    await p.selectOption('#category_id', { label: 'Electronics' });
    await p.fill('#starting_price', '100');
    await p.fill('#bid_increment', '10');
    if (extra.starts) await p.fill('#starts_at', extra.starts);
    await p.fill('#ends_at', extra.ends ?? closeInput);
    await p.getByRole('button', { name: 'Save draft and add photos' }).click();
    await p.waitForURL(/\/edit/);
    return Number(/auctions\/(\d+)\/edit/.exec(p.url())[1]);
  };
  const cancelId = await mk('Dot E2E lot to cancel');
  await p.getByRole('button', { name: 'Publish lot' }).click();
  await p.getByRole('dialog').getByRole('button', { name: 'Publish lot' }).click();
  await p.waitForURL(/\/seller\/auctions$/);
  await t.go(p, `/seller/auctions/${cancelId}/edit`);
  await p.getByRole('button', { name: 'Cancel lot' }).click();
  await p.getByRole('dialog').getByRole('button', { name: 'Cancel lot' }).click();
  await p.waitForURL(/\/seller\/auctions$/);
  t.has('cancel message', await t.text(p), 'was cancelled');
  t.eq('status cancelled', app.one('select status from auctions where id = ?', cancelId).status, 'cancelled');
  await t.go(b, '/auctions?q=to cancel');
  const cancelledCard = b.locator('article, li, .ds-lot, [class*=lot-card]', { hasText: 'Dot E2E lot to cancel' }).last();
  t.has('browse shows a cancelled lot as Cancelled (by design browse lists every lot except drafts)', await cancelledCard.innerText(), /Cancelled/);
  await t.go(b, '/auctions?state=live&q=E2E');
  t.hasNot('...but the Live filter does not include it', await t.text(b), 'Dot E2E lot to cancel');
  t.has('...and does include the live lot', await t.text(b), 'Dot E2E Walnut sideboard (edited)');
  await b.goto(`${app.base}/auctions/${cancelId}`);
  t.has('buyer opening the cancelled lot is told it was cancelled', await t.text(b), /cancel/i);

  // ---- delete a draft -----------------------------------------------------------------------------------------------
  const delId = await mk('Dot E2E draft to delete');
  await p.locator('input[type=file]#images').setInputFiles(F['photo-1.png']);
  await p.locator('[data-upload-submit]').click();
  await p.waitForFunction(() => document.querySelectorAll('.sl-grid__item').length === 1, null, { timeout: 20000 });
  const delImg = app.one('select path from auction_images where auction_id = ?', delId);
  await p.getByRole('button', { name: 'Delete draft' }).click();
  await p.getByRole('dialog').getByRole('button', { name: 'Delete draft' }).click();
  await p.waitForURL(/\/seller\/auctions$/);
  t.has('delete message', await t.text(p), 'Draft deleted');
  t.eq('draft row gone', app.q('select count(*) c from auctions where id = ?', delId)[0].c, 0);
  t.eq('its photo rows gone', app.q('select count(*) c from auction_images where auction_id = ?', delId)[0].c, 0);
  const fsm = await import('node:fs'); const pth = await import('node:path'); const { repo } = await import('../lib/env.mjs');
  t.check('its photo file is gone from the private disk', delImg && !fsm.existsSync(pth.join(repo, 'storage/app/private/auction-images', delImg.path)), delImg?.path);

  // ---- scheduled lot: tab, and not "live" for buyers ----------------------------------------------------------------
  const opens = new Date(Date.now() + 2 * 86400000); opens.setUTCHours(7, 0, 0, 0);
  const closes = new Date(opens.getTime() + 2 * 86400000);
  const schedId = await mk('Dot E2E scheduled lot', { starts: sast(opens), ends: sast(closes) });
  t.eq('opening time typed in SAST is stored as UTC', app.one('select starts_at from auctions where id = ?', schedId).starts_at.slice(11, 16), '07:00');
  await p.getByRole('button', { name: 'Publish lot' }).click();
  await p.getByRole('dialog').getByRole('button', { name: 'Publish lot' }).click();
  await p.waitForURL(/\/seller\/auctions$/);
  t.has('scheduled message names the opening time in SAST', await t.text(p), /is scheduled and opens .*09:00 SAST/);
  tabs = await tabCounts();
  t.eq('tabs: Scheduled 1', tabs['Scheduled'], 1);
  await t.go(b, '/auctions?q=scheduled lot');
  const schedCard = b.locator('article, li, .ds-lot, [class*=lot-card]', { hasText: 'Dot E2E scheduled lot' }).last();
  t.has('a scheduled lot is presented as Scheduled, not Live', await schedCard.innerText(), /Scheduled|Opens/i);
  await t.go(b, '/auctions?state=live&q=scheduled lot');
  t.hasNot('the Live filter does not include a scheduled lot', await t.text(b), 'Dot E2E scheduled lot');

  // ---- final list counts match the rows ----------------------------------------------------------------------------
  await t.go(p, '/seller/auctions');
  tabs = await tabCounts();
    t.eq('All lots = 3 (live with bid, cancelled, scheduled)', tabs['All lots'], 3);
  t.eq('Live 1 / Scheduled 1 / Cancelled 1', [tabs['Live'], tabs['Scheduled'], tabs['Cancelled']], [1, 1, 1]);
  for (const key of ['live', 'scheduled', 'cancelled']) {
    await t.go(p, `/seller/auctions?state=${key}`);
    const n = await p.evaluate(() => document.querySelectorAll('tr.sl-row').length);
    t.check(`?state=${key} shows its count of rows`, n === 1, `rows ${n}`);
  }
  await t.go(p, '/seller/auctions');
  await t.shot(p, '12-list-final');
  await t.axe(p, 'seller list');
  await t.go(p, `/seller/auctions/${id}/edit`);
  await t.axe(p, 'edit locked lot');
  await t.go(p, `/seller/auctions/create`);
  await t.axe(p, 'create form');

  // ---- phone ---------------------------------------------------------------------------------------------------------
  const phone = await t.context({ size: 'phone', label: 'seller-phone', touch: true });
  const pp = await phone.newPage();
  await t.login(pp, 'zanele', '/seller/auctions');
  await t.shot(pp, '13-phone-list');
  const m1 = await t.layout(pp, 'seller list at 390');
  t.check('seller list tap targets >= 44px at 390', m1.small.length === 0, m1.small.slice(0, 6).join(' | '));
  await t.axe(pp, 'seller list at 390');
  await t.go(pp, `/seller/auctions/${id}/edit`);
  await t.shot(pp, '14-phone-edit-locked');
  const m2 = await t.layout(pp, 'locked edit at 390');
  t.check('locked edit tap targets >= 44px at 390', m2.small.length === 0, m2.small.slice(0, 6).join(' | '));
  await t.go(pp, '/seller/auctions/create');
  await t.shot(pp, '15-phone-create');
  const m3 = await t.layout(pp, 'create at 390');
  t.check('create form tap targets >= 44px at 390', m3.small.length === 0, m3.small.slice(0, 8).join(' | '));
  await t.axe(pp, 'create form at 390');
  // dark theme once
  const dark = await t.context({ colorScheme: 'dark', label: 'seller-dark' });
  const dp = await dark.newPage();
  await t.login(dp, 'zanele', '/seller/auctions/create');
  await t.shot(dp, '16-create-dark');
  await t.axe(dp, 'create form (dark scheme)');
}
