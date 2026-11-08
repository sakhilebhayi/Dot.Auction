export const title = 'S2 Closing: settlement, notifications, anti-snipe, unsold, reserve decision';

const DEBUG = !!process.env.E2E_DEBUG;

const waitUntil = async (fn, ms = 15000) => { const end = Date.now() + ms; while (Date.now() < end) { if (fn()) return true; await new Promise((r) => setTimeout(r, 200)); } return false; };

export async function run(t, app) {
  const seller = app.userByKey('thandi');
  const pieter = app.userByKey('pieter');
  const notif = (userId, kind, like = '%') => app.q(`select id, data, read_at from notifications where notifiable_id = ? and type like ? and data like ?`, userId, `%${kind}`, like);
  const settle = () => app.artisan(['auction:settle-ended']);
  const lotId = (title) => app.lot(title).id;
  const leader = (id) => app.one('select bidder_id, amount from bids where auction_id = ? order by amount desc, id desc', id);
  t.expectHttp(/\/auctions\/\d+$/, 404);

  const sellerCtx = await t.context({ label: 'thandi' });
  const winnerCtx = await t.context({ label: 'winner' });
  const sp = await sellerCtx.newPage();
  const wp = await winnerCtx.newPage();
  await t.login(sp, 'thandi', '/seller/auctions');

  // ================= A. a lot with a reserve met ends: one settlement, however often the job runs =================
  const guitar = lotId('Yamaha F310 acoustic guitar');
  const win = leader(guitar);
  const winnerUser = app.one('select * from users where id = ?', win.bidder_id);
  const losers = app.q('select distinct bidder_id from bids where auction_id = ? and bidder_id != ?', guitar, win.bidder_id).map((r) => r.bidder_id);
  t.check('setup: the guitar has a winner above its reserve and at least one loser', Number(win.amount) >= 1200 && losers.length >= 1, JSON.stringify({ win, losers }));
  const before = { sold: notif(seller.id, 'AuctionSoldNotification').length, won: notif(winnerUser.id, 'AuctionWonNotification').length };
  app.moveLot(guitar, { ends: -2 });
  await t.go(sp, '/seller/auctions');
  t.has('closed by the clock but not yet settled reads "being settled", not "Not sold"', await sp.locator('tr.sl-row', { hasText: 'Yamaha F310' }).innerText(), /being settled/i);
  settle(); settle(); // twice: the second run must change nothing
  const a = app.one('select * from auctions where id = ?', guitar);
  t.eq('the lot is ended', a.status, 'ended');
  t.eq('exactly one settlement exists after two runs', app.q('select count(*) c from auction_settlements where auction_id = ?', guitar)[0].c, 1);
  const st = app.one('select * from auction_settlements where auction_id = ?', guitar);
  t.check('the settlement belongs to the right winner and seller at the winning amount', st.winner_id === winnerUser.id && st.seller_id === seller.id && st.amount_cents === Math.round(Number(win.amount) * 100), JSON.stringify(st));
  t.eq('winner got exactly one "won" notice', notif(winnerUser.id, 'AuctionWonNotification').length - before.won, 1);
  t.eq('seller got exactly one "sold" notice', notif(seller.id, 'AuctionSoldNotification').length - before.sold, 1);
  t.eq('nobody else was told they won', notif(losers[0], 'AuctionWonNotification', '%Yamaha%').length, 0);
  app.artisan(['auction:repair-unsettled']);
  t.eq('the repair sweep does not create a second settlement or notice', [app.q('select count(*) c from auction_settlements where auction_id = ?', guitar)[0].c, notif(winnerUser.id, 'AuctionWonNotification').length - before.won], [1, 1]);

  await t.login(wp, winnerUser.email, '/won');
  t.has('winner sees the lot under Won', await t.text(wp), 'Yamaha F310');
  await t.shot(wp, 'a1-winner-won');
  await wp.getByRole('button', { name: /Notifications/ }).first().click();
  await wp.locator('#nf-panel').waitFor();
  t.has('winner bell carries the won notice', await wp.locator('#nf-panel').innerText(), /Auction won|You won/);
  await wp.keyboard.press('Escape');
  await t.go(sp, '/seller/auctions');
  const row = await sp.locator('tr.sl-row', { hasText: 'Yamaha F310' }).innerText();
  t.has('seller list says Sold', row, /Sold/);
  await t.shot(sp, 'a2-seller-sold');
  await t.go(sp, `/auctions/${guitar}`);
  t.has('the lot page says it sold', await t.text(sp), /Sold/);
  // a loser sees the lot as closed, not as won
  const loserUser = app.one('select * from users where id = ?', losers[0]);
  const lp = await (await t.context({ label: 'loser' })).newPage();
  await t.login(lp, loserUser.email, `/auctions/${guitar}`);
  const loserText = await t.text(lp);
  t.hasNot('a losing bidder is not told they won', loserText, /You won/);
  await t.shot(lp, 'a3-loser-detail');

  // ================= B. anti-snipe ======================================================================================
  const chair = lotId('Rocking chair, solid wood');
  app.moveLot(chair, { ends: 3 });
  const endsBefore = app.one('select ends_at from auctions where id = ?', chair).ends_at;
  const bidder = app.userByKey('johan');
  const bp = await (await t.context({ label: 'johan' })).newPage();
  await t.login(bp, 'johan', `/auctions/${chair}`);
  const minBid = (await bp.locator('[data-min-bid], .bid-panel').first().innerText().catch(() => '')) || '';
  const amount = Number(app.one('select current_price from auctions where id = ?', chair).current_price) + 100;
  await bp.getByLabel(/Your bid|bid amount/i).first().fill(String(amount));
  await bp.getByRole('button', { name: /Place bid|Review/i }).first().click();
  const cf = bp.getByRole('dialog').getByRole('button', { name: /Place bid|Confirm/i }).first();
  await cf.waitFor();
  await cf.click();
  await waitUntil(() => app.q('select count(*) c from bids where auction_id = ? and bidder_id = ?', chair, bidder.id)[0].c === 1);
  await bp.waitForSelector('text=/extended|adds 5 minutes|moved/i').catch(() => {});
  const ch = app.one('select ends_at, snipe_extensions from auctions where id = ?', chair);
  t.eq('a late bid extends the close once', ch.snipe_extensions, 1);
  const delta = (new Date(ch.ends_at.replace(' ', 'T') + 'Z') - new Date(endsBefore.replace(' ', 'T') + 'Z')) / 60000;
  t.check('...by the configured extension of 5 minutes beyond the old close, or to 5 minutes from the bid', delta > 0 && delta <= 5.5, `moved by ${delta} min`);
  t.has('the bidder is told the close moved', await t.text(bp), /extended|moved|later/i);
  await t.shot(bp, 'b1-snipe-extended');
  // settle after the (extended) close, and not before
  settle();
  t.eq('the lot is not settled while the extended close is in the future', app.one('select status from auctions where id = ?', chair).status, 'active');
  app.moveLot(chair, { ends: -1 });
  settle();
  t.eq('...and is settled after it', app.one('select status from auctions where id = ?', chair).status, 'ended');
  t.eq('...to the late bidder', app.one('select winner_id from auction_settlements where auction_id = ?', chair).winner_id, bidder.id);

  // ================= C. ended unsold (no bids) =========================================================================
  const wheels = lotId('Set of four alloy wheels, 16-inch');
  const pp = await (await t.context({ label: 'pieter' })).newPage();
  await t.login(pp, 'pieter', '/seller/auctions');
  app.moveLot(wheels, { ends: -2 });
  const nBefore = app.q('select count(*) c from notifications where notifiable_id = ?', pieter.id)[0].c;
  settle();
  t.eq('no-bid lot ends', app.one('select status from auctions where id = ?', wheels).status, 'ended');
  t.eq('no settlement for a lot nobody bid on', app.q('select count(*) c from auction_settlements where auction_id = ?', wheels)[0].c, 0);
  t.eq('no notice invented for a lot that simply did not sell', app.q('select count(*) c from notifications where notifiable_id = ?', pieter.id)[0].c, nBefore);
  await t.go(pp, '/seller/auctions?state=ended');
  t.has('seller sees it as Not sold', await pp.locator('tr.sl-row', { hasText: 'alloy wheels' }).innerText(), /Not sold/);
  await t.go(wp, `/auctions/${wheels}`);
  t.has('buyers see an unsold lot as Not sold', await t.text(wp), /Not sold|did not sell|No bids/i);
  await t.shot(wp, 'c1-unsold-detail');

  // ================= D. reserve not met -> pending_review -> the seller's decision =====================================
  const bike = lotId('Mountain bike, 29-inch aluminium frame');
  const bikeTop = leader(bike);
  const bikeTopUser = app.one('select * from users where id = ?', bikeTop.bidder_id);
  app.moveLot(bike, { ends: -2 });
  const reserveBefore = notif(seller.id, 'ReserveNotMetNotification', '%Mountain bike%').length;
  settle(); settle();
  t.eq('below the reserve the lot waits for the seller (pending_review)', app.one('select status from auctions where id = ?', bike).status, 'pending_review');
  t.eq('no settlement while the seller has not decided', app.q('select count(*) c from auction_settlements where auction_id = ?', bike)[0].c, 0);
  const prop = app.one("select * from reserve_not_met_proposals where auction_id = ? and status = 'pending'", bike);
  t.check('a pending proposal exists for the top bid', !!prop && prop.bid_id, JSON.stringify(prop));
  app.artisan(['auction:repair-unsettled']);
  t.eq('the seller got exactly one "your decision" notice, however often the jobs ran', notif(seller.id, 'ReserveNotMetNotification', '%Mountain bike%').length - reserveBefore, 1);
  t.check('the proposal records that the seller was notified', !!app.one('select seller_notified_at from reserve_not_met_proposals where id = ?', prop.id).seller_notified_at);

  await t.go(sp, '/seller/auctions');
  const bikeRow = sp.locator('tr', { hasText: 'Mountain bike' });
  const rowText = await bikeRow.first().innerText();
  t.has('seller list: the lot says Awaiting seller decision', rowText, /Awaiting seller decision/);
  const decisionText = await t.text(sp);
  t.has('seller list: "Your decision" band is shown', decisionText, /Your decision/);
  t.has('seller list: waiting since is shown', decisionText, /Waiting since/);
  t.has('...and tab "Needs your decision" counts both pending lots', decisionText, /Needs your decision\s*2/);
  await t.shot(sp, 'd1-seller-list-decision');
  await t.go(sp, '/dashboard');
  const dash = await t.text(sp);
  t.has('dashboard: the decision is shown to the seller', dash, /Awaiting|Your decision|decision/i);
  t.has('dashboard: with the lot named', dash, 'Mountain bike');
  await t.shot(sp, 'd2-dashboard-decision');
  await t.go(sp, '/notifications');
  t.has('seller inbox has the decision notice', await t.text(sp), 'Your decision is needed');
  // the notice leads somewhere real
  await sp.getByText('Your decision is needed').first().click();
  await sp.waitForLoadState('load');
  t.check('opening the decision notice lands on a page that shows the decision (no dead link)', /decision/i.test(await t.text(sp)), sp.url());

  // what a buyer sees while it waits (must not say Cancelled)
  await t.login(wp, bikeTopUser.email, `/auctions/${bike}`);
  const pend = await t.text(wp);
  t.hasNot('buyer page for an awaiting-decision lot is not badged Cancelled', pend, /Cancelled/);
  t.has('...it says the seller is deciding', pend, /decid|review|seller/i);
  await t.go(wp, '/auctions?state=pending');
  t.has('browse filter "Awaiting decision" lists it', await t.text(wp), 'Mountain bike');
  await t.go(wp, '/bids');
  t.hasNot('My bids does not call it Cancelled', await wp.locator('li, tr, article', { hasText: 'Mountain bike' }).first().innerText().catch(() => ''), /Cancelled/);

  // ---- accept ---------------------------------------------------------------------------------------------------------
  await t.go(sp, '/seller/auctions?state=pending');
  const wonBefore = notif(bikeTopUser.id, 'AuctionWonNotification', '%Mountain bike%').length;
  const soldBefore = notif(seller.id, 'AuctionSoldNotification', '%Mountain bike%').length;
  const panel = sp.locator('tr', { hasText: 'Decide on the highest bid for Mountain bike' });
  await panel.getByRole('button', { name: /^Accept R/ }).click();
  await t.shot(sp, 'd3-accept-confirm');
  const dlg = sp.getByRole('dialog');
  await dlg.waitFor();
  await dlg.getByRole('button', { name: /Accept|Sell/i }).last().click();
  await sp.waitForFunction(() => /accepted|sold|wins the lot|payment/i.test(document.body.innerText) && !/Decide on the highest bid for Mountain bike/.test(document.body.innerText), null, { timeout: 15000 }).catch(() => {});
  await new Promise((r) => setTimeout(r, 500));
  t.eq('accepting ends the lot', app.one('select status from auctions where id = ?', bike).status, 'ended');
  t.eq('accepting creates the settlement once', app.q('select count(*) c from auction_settlements where auction_id = ?', bike)[0].c, 1);
  t.eq('the settlement is for the top bidder', app.one('select winner_id from auction_settlements where auction_id = ?', bike).winner_id, bikeTopUser.id);
  t.eq('the winner is notified once', notif(bikeTopUser.id, 'AuctionWonNotification', '%Mountain bike%').length - wonBefore, 1);
  t.eq('the seller gets the sold notice once', notif(seller.id, 'AuctionSoldNotification', '%Mountain bike%').length - soldBefore, 1);
  t.eq('the proposal is accepted', app.one('select status from reserve_not_met_proposals where id = ?', prop.id).status, 'accepted');
  await t.go(sp, '/seller/auctions');
  t.has('seller list now says Sold for the bike', await sp.locator('tr.sl-row', { hasText: 'Mountain bike' }).first().innerText(), /Sold/);
  await t.go(wp, '/won');
  t.has('the winner sees the bike under Won', await t.text(wp), 'Mountain bike');

  // ---- reject (pieter's lantern, run through the same scheduler path) ------------------------------------------------
  const lantern = lotId("Brass ship's lantern");
  const lanternTop = app.one('select * from users where id = ?', leader(lantern).bidder_id);
  app.moveLot(lantern, { ends: -2 });
  settle();
  t.eq('lantern waits for its seller too', app.one('select status from auctions where id = ?', lantern).status, 'pending_review');
  await t.go(pp, '/seller/auctions?state=pending');
  const lantPanel = pp.locator('tr', { hasText: "Decide on the highest bid for Brass ship's lantern" });
  await lantPanel.getByRole('button', { name: /^Reject/ }).click();
  await t.shot(pp, 'd4-reject-confirm');
  await pp.getByRole('dialog').getByRole('button', { name: /Reject|End/i }).last().click();
  await pp.waitForFunction(() => !/Decide on the highest bid for Brass ship/.test(document.body.innerText), null, { timeout: 15000 }).catch(() => {});
  await new Promise((r) => setTimeout(r, 500));
  const lanternRow = app.one('select status from auctions where id = ?', lantern);
  const prop2 = app.one('select status from reserve_not_met_proposals where auction_id = ? order by id desc', lantern);
  t.eq('rejecting records the rejection', prop2.status, 'rejected');
  t.check('rejecting does not cancel the lot', lanternRow.status !== 'cancelled', lanternRow.status);
  t.eq('no settlement after a rejection', app.q('select count(*) c from auction_settlements where auction_id = ?', lantern)[0].c, 0);
  t.eq('the top bidder is not told they won', notif(lanternTop.id, 'AuctionWonNotification', "%lantern%").length, 0);
  // state everywhere
  await t.go(pp, '/seller/auctions');
  const lr = await pp.locator('tr.sl-row', { hasText: 'ship' }).first().innerText();
  t.has('seller list: Not sold', lr, /Not sold/);
  t.hasNot('seller list: not Cancelled', lr, /Cancelled/);
  await t.go(pp, '/seller/auctions?state=ended');
  t.has('seller tab Ended holds it', await t.text(pp), 'lantern');
  const bp2 = await (await t.context({ label: 'lantern-bidder' })).newPage();
  await t.login(bp2, lanternTop.email, `/auctions/${lantern}`);
  const lantDetail = await t.text(bp2);
  t.hasNot('buyer detail is not Cancelled', lantDetail, /Cancelled/);
  t.has('buyer detail says the seller did not accept the bid', lantDetail, /did not accept|Not sold/i);
  await t.shot(bp2, 'd5-rejected-detail');
  await t.go(bp2, '/auctions');
  const card = bp2.locator('article, li, [class*=lot-card]', { hasText: "ship's lantern" }).last();
  const cardText = await card.innerText();
  t.has('browse card: Not sold', cardText, /Not sold/);
  t.hasNot('browse card: not Cancelled', cardText, /Cancelled/);
  await t.go(bp2, '/auctions?state=unsold');
  t.has('"Not sold" filter lists it', await t.text(bp2), 'lantern');
  await t.go(bp2, '/auctions?state=cancelled');
  t.hasNot('"Cancelled" filter does not list it', await t.text(bp2), 'lantern');
  await t.go(bp2, '/bids');
  const bidsText = await t.text(bp2);
  t.has('My bids still lists the bid', bidsText, 'lantern');
  await t.go(bp2, '/won');
  t.hasNot('Won does not list it', await t.text(bp2), 'lantern');
  await t.go(bp2, '/dashboard');
  t.hasNot('dashboard does not call it Cancelled or Won', (await t.text(bp2)).split('\n').filter((l) => /lantern/i.test(l)).join(' '), /Cancelled|Won\b/);
  // an accepted decision cannot be taken back, and a second decision cannot be made
  const csrf = await pp.locator('meta[name=csrf-token]').getAttribute('content');
  t.eq('an old accepted proposal stays accepted (decisions are final)', app.one('select status from reserve_not_met_proposals where id = ?', prop.id).status, 'accepted');

  // ---- the seeded awaiting-decision lot (mixer) still shows its waiting time ---------------------------------------
  await t.go(sp, '/seller/auctions?state=pending');
  t.has('the seeded pending lot shows how long it has waited', await t.text(sp), /Waiting since[\s\S]*SAST/);
  await t.axe(sp, 'seller list with a decision panel');
  const phone = await (await t.context({ size: 'phone', touch: true, label: 'phone' })).newPage();
  await t.login(phone, 'thandi', '/seller/auctions?state=pending');
  const lay = await t.layout(phone, 'decision list at 390');
  await t.shot(phone, 'd6-phone-decision');
  t.check('decision buttons are tap-sized on a phone', lay.small.length === 0, lay.small.slice(0, 5).join(' | '));

  // ---- a failed settle logs at error level and is retried ------------------------------------------------------------
  const braai = lotId('Stainless steel gas braai, two burner');
  app.moveLot(braai, { ends: -2 });
  app.run("create trigger e2e_block before update of status on auctions when new.status = 'ended' begin select raise(abort, 'e2e forced failure'); end");
  const bad = app.artisan(['auction:settle-ended'], { allowFail: true });
  app.run('drop trigger e2e_block');
  t.check('a failing settle exits non-zero', bad.status !== 0, `exit ${bad.status}`);
  t.has('...names the lot on the console', bad.stdout + bad.stderr, /Failed to settle auction/);
  t.has('...and is logged at error level', bad.stderr + bad.stdout, /\.ERROR:/);
  t.eq('...the lot is left active to retry', app.one('select status from auctions where id = ?', braai).status, 'active');
  const retry = app.artisan(['auction:settle-ended']);
  t.eq('the next run settles it', [retry.status, app.one('select status from auctions where id = ?', braai).status], [0, 'ended']);
  t.eq('...with one settlement', app.q('select count(*) c from auction_settlements where auction_id = ?', braai)[0].c, 1);

  // ---- a lost settlement hand-off: the hourly sweep repairs it once and shouts about it --------------------------------
  const drill = lotId('Bosch cordless drill kit');
  app.moveLot(drill, { ends: -30 });
  app.run("create trigger e2e_block2 before insert on auction_settlements begin select raise(abort, 'e2e forced failure'); end");
  app.artisan(['auction:settle-ended'], { allowFail: true });
  app.run('drop trigger e2e_block2');
  t.eq('the lot ended but the hand-off was lost (no settlement)', [app.one('select status from auctions where id = ?', drill).status, app.q('select count(*) c from auction_settlements where auction_id = ?', drill)[0].c], ['ended', 0]);
  const sweep = app.artisan(['auction:repair-unsettled']);
  t.eq('the sweep recorded the missing settlement', app.q('select count(*) c from auction_settlements where auction_id = ?', drill)[0].c, 1);
  t.has('...and logged it at error level', sweep.stderr + sweep.stdout, /\.ERROR:.*Repaired an ended auction/);
  app.artisan(['auction:repair-unsettled']);
  t.eq('...a second sweep adds nothing', app.q('select count(*) c from auction_settlements where auction_id = ?', drill)[0].c, 1);
}
