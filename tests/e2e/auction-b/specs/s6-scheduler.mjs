import { AppInstance } from '../lib/server.mjs';

export const title = 'S6 Scheduler, operations and an empty (no categories) platform';

export async function run(t, app) {
  // ---------- the schedule ---------------------------------------------------------------------------------------------
  const out = app.artisan(['tinker', '--execute', 'echo json_encode(array_map(fn ($e) => ["cmd" => trim(preg_replace("/^.*artisan.? /", "", str_replace("\\"", "", $e->command))), "expr" => $e->expression, "overlap" => $e->withoutOverlapping, "expires" => $e->expiresAt, "one" => $e->onOneServer], app(Illuminate\\Console\\Scheduling\\Schedule::class)->events()));']);
  const events = JSON.parse(out.stdout.trim().split('\n').pop());
  const list = app.artisan(['schedule:list']).stdout;
  const want = { 'auction:settle-ended': '*/5 * * * *', 'auction:repair-unsettled': '0 * * * *', 'auction:notify-ending-soon': '*/5 * * * *', 'auction:mark-overdue-payments': '*/15 * * * *', 'auction:publish-metrics': '15 0 * * *' };
  t.eq('the schedule has exactly the five platform jobs', events.map((e) => e.cmd).sort(), Object.keys(want).sort());
  for (const e of events) {
    t.eq(`${e.cmd}: cadence`, e.expr, want[e.cmd]);
    t.check(`${e.cmd}: has an overlap guard with a short lock (not the 24 h default)`, e.overlap === true && e.expires > 0 && e.expires <= 60, `overlap=${e.overlap} expires=${e.expires}`);
    t.check(`${e.cmd}: runs on one server only`, e.one === true);
    t.has(`${e.cmd}: appears in schedule:list`, list, e.cmd);
  }
  t.check('every auction:* command that touches money or state is scheduled', ['settle-ended', 'repair-unsettled', 'notify-ending-soon', 'mark-overdue-payments'].every((c) => list.includes(`auction:${c}`)));
  const lst = app.artisan(['list']).stdout;
  t.note(`auction:seed-categories ${/auction:seed-categories/.test(lst) ? 'exists' : 'does not exist on this branch (it belongs to feat/r42-sp); skipped'}`);

  // ---------- each job is quiet and succeeds with nothing to do, and once with something to do -----------------------------
  for (const cmd of Object.keys(want)) {
    const r = app.artisan([cmd], { allowFail: true });
    t.eq(`${cmd}: exits 0`, r.status, 0);
    t.hasNot(`${cmd}: logs no error`, r.stderr + r.stdout, /\.ERROR:|Exception/);
  }
  const run1 = app.artisan(['schedule:run']);
  t.check('schedule:run executes without error', /No scheduled commands are ready to run|Running \[/.test(run1.stdout), run1.stdout.slice(0, 200));

  // ---------- ending-soon notices: once per watcher, however often the job runs ---------------------------------------------
  const lot = app.lot('Stainless steel gas braai, two burner');
  const watchers = app.q('select user_id from watchlists where auction_id = ?', lot.id).map((r) => r.user_id);
  app.moveLot(lot.id, { ends: 30 });
  app.run('update watchlists set ending_soon_notified_at = null where auction_id = ?', lot.id); // the seed already announced it once
  const allBefore = app.q("select count(*) c from notifications where type like '%AuctionEndingSoonNotification' and data like '%gas braai%'")[0].c;
  const count = (uid) => app.q("select count(*) c from notifications where notifiable_id = ? and type like '%AuctionEndingSoonNotification' and data like '%gas braai%'", uid)[0].c;
  const before = watchers.map(count);
  app.artisan(['auction:notify-ending-soon']); app.artisan(['auction:notify-ending-soon']);
  t.check('the demo lot has watchers', watchers.length > 0, watchers.length);
  t.eq('each watcher got exactly one ending-soon notice after two runs', watchers.map(count).map((c, i) => c - before[i]), watchers.map(() => 1));
  t.eq('...and nobody else was told', app.q("select count(*) c from notifications where type like '%AuctionEndingSoonNotification' and data like '%gas braai%'")[0].c - allBefore, watchers.length);
  app.moveLot(lot.id, { ends: 600 });
  const far = app.lot('Rocking chair, solid wood');
  app.moveLot(far.id, { ends: 600 });
  const n0 = app.q("select count(*) c from notifications where type like '%AuctionEndingSoonNotification'")[0].c;
  app.artisan(['auction:notify-ending-soon']);
  t.eq('a lot with hours left is not announced as ending soon', app.q("select count(*) c from notifications where type like '%AuctionEndingSoonNotification'")[0].c, n0);

  // ---------- a platform with no categories ------------------------------------------------------------------------------
  const bare = new AppInstance('s6-bare', { seed: 'bare' });
  await bare.start();
  try {
    const spec = t;
    const prevApp = spec.app; spec.app = bare;
    t.expectHttp(/\/seller\/auctions$/, 422);
    const p = await t.page();
    await t.ecosystemSignIn(p, 'e2e:first.seller@e2e.test:First Seller');
    t.check('a person provisioned through the hand-off lands signed in on an empty platform', /\/dashboard/.test(p.url()), p.url());
    t.has('the dashboard of an empty platform is an honest empty state', await t.text(p), /./);
    await t.shot(p, 'a-empty-dashboard');
    await t.axe(p, 'dashboard of an empty platform');
    await t.go(p, '/auctions');
    await t.shot(p, 'b-empty-browse');
    await t.axe(p, 'browse of an empty platform');
    await t.go(p, '/seller/auctions');
    t.has('the seller list invites a first lot', await t.text(p), 'You have not listed anything yet');
    await t.go(p, '/seller/auctions/create');
    const txt = await t.text(p);
    t.has('the create form says no categories exist, instead of an empty select', txt, 'No categories exist yet');
    t.has('...and who can fix it', txt, /added by whoever runs Dot\.Auction/);
    t.eq('...there is no category select to get stuck on', await p.locator('#category_id').count(), 0);
    t.eq('...and the save button is disabled and points at the explanation', [await p.getByRole('button', { name: 'Save draft and add photos' }).isDisabled(), await p.getByRole('button', { name: 'Save draft and add photos' }).getAttribute('aria-describedby')], [true, 'no-categories']);
    await t.shot(p, 'c-no-categories');
    await t.axe(p, 'create form without categories');
    const ph = await (await t.context({ size: 'phone', touch: true, label: 'phone' })).newPage();
    await t.ecosystemSignIn(ph, 'e2e:first.seller@e2e.test');
    await t.go(ph, '/seller/auctions/create');
    const l = await t.layout(ph, 'create form without categories at 390');
    t.check('the notice and the form fit a phone with 44px targets', l.small.length === 0, l.small.slice(0, 5).join(' | '));
    await t.shot(ph, 'd-no-categories-phone');
    // the operator provisions a category (database): the same page then works
    bare.run("insert into auction_categories (name, slug, created_at, updated_at) values ('Provisioned', 'provisioned', datetime('now'), datetime('now'))");
    await t.go(p, '/seller/auctions/create');
    t.eq('after a category exists the normal form appears', [await p.locator('#category_id').count(), await p.getByRole('button', { name: 'Save draft and add photos' }).isDisabled()], [1, false]);
    t.hasNot('...and the notice is gone', await t.text(p), 'No categories exist yet');
    spec.app = prevApp;
  } finally {
    await bare.stop();
  }
}
