export const title = 'S7 Mobile and accessibility sweep (390 and 360), keyboard, focus, reduced motion, zoom';

export async function run(t, app) {
  const lotId = (title) => app.lot(title).id;
  const sale = (status) => app.one('select id from auction_settlements where status = ? limit 1', status)?.id;
  const draft = app.one("select id from auctions where status = 'draft' and seller_id = ? limit 1", app.userByKey('thandi').id).id;
  const live = lotId('Bosch cordless drill kit');
  const lockedLot = lotId('Yamaha F310 acoustic guitar');
  const mixer = lotId('KitchenAid stand mixer, 4.8 L');
  t.expectHttp(/.*/, 403, 404);

  // who reaches what: [role, path, name]
  const pages = [
    ['thandi', '/dashboard', 'seller dashboard'], ['thandi', '/auctions', 'browse'], ['thandi', `/auctions/${live}`, 'own live lot'], ['thandi', `/auctions/${mixer}`, 'own lot awaiting decision'],
    ['thandi', '/seller/auctions', 'my lots'], ['thandi', '/seller/auctions?state=pending', 'my lots: decision'], ['thandi', '/seller/auctions?state=draft', 'my lots: drafts'], ['thandi', '/seller/auctions?state=ended', 'my lots: ended'],
    ['thandi', '/seller/auctions/create', 'create'], ['thandi', `/seller/auctions/${draft}/edit`, 'edit draft'], ['thandi', `/seller/auctions/${live}/edit`, 'edit live'], ['thandi', `/seller/auctions/${lockedLot}/edit`, 'edit locked'],
    ['thandi', '/settlements', 'payments (seller)'], ['thandi', `/settlements/${sale('payment_overdue')}`, 'sale overdue (seller)'], ['thandi', '/notifications', 'notifications'], ['thandi', '/bids', 'my bids'], ['thandi', '/watchlist', 'watching'], ['thandi', '/won', 'won'],
    ['thandi', '/user/profile', 'profile'], ['thandi', '/teams/create', 'create team'],
    ['sipho', '/settlements', 'payments (winner)'], ['sipho', `/settlements/${sale('payment_overdue')}`, 'sale overdue (winner)'], ['sipho', `/settlements/${sale('paid')}`, 'sale paid'],
    ['naledi', '/operator/settlement-payments', 'payment operations'], ['naledi', '/operator/settlement-payments?status=unmatched', 'payment operations: needs a person'], ['naledi', '/dashboard', 'operator dashboard'],
  ];
  const teamId = app.one('select current_team_id id from users where id = ?', app.userByKey('thandi').id).id;
  pages.push(['thandi', `/teams/${teamId}`, 'team settings']);

  const titles = new Map(); const filterTitles = new Set();
  for (const size of ['phone', 'small']) {
    const ctx = await t.context({ size, touch: true, label: size });
    const p = await ctx.newPage();
    let who = null;
    for (const [role, path, name] of pages) {
      if (who !== role) { await t.login(p, role, '/dashboard'); who = role; }
      const res = await p.goto(app.base + path, { waitUntil: 'load' });
      const label = `${name} @${size === 'phone' ? 390 : 360}`;
      t.eq(`${label}: loads`, res.status(), 200);
      const info = await p.evaluate(() => ({
        h1: [...document.querySelectorAll('h1')].map((h) => h.innerText.trim()),
        main: document.querySelectorAll('main, [role=main]').length,
        nav: document.querySelectorAll('nav, [role=navigation]').length,
        banner: document.querySelectorAll('header, [role=banner]').length,
        lang: document.documentElement.lang,
        title: document.title,
        viewport: document.querySelector('meta[name=viewport]')?.content ?? '',
        imgsNoAlt: [...document.querySelectorAll('img:not([alt])')].length,
        emptyLinks: [...document.querySelectorAll('a[href]')].filter((a) => ![a.innerText, a.getAttribute('aria-label'), a.title].some((x) => x && x.trim()) && !a.querySelector('img[alt]:not([alt=""])')).length,
        emptyButtons: [...document.querySelectorAll('button')].filter((b) => ![b.innerText, b.getAttribute('aria-label'), b.title].some((x) => x && x.trim()) && !b.closest('[hidden], [inert]')).length,
        ids: (() => { const seen = {}; const dup = []; document.querySelectorAll('[id]').forEach((e) => { if (seen[e.id]) dup.push(e.id); seen[e.id] = 1; }); return dup; })(),
      }));
      t.eq(`${label}: exactly one h1`, info.h1.length, 1);
      t.eq(`${label}: one main landmark`, info.main, 1);
      t.check(`${label}: has navigation and a banner landmark`, info.nav >= 1 && info.banner >= 1, JSON.stringify(info));
      t.check(`${label}: page language and viewport are set, zoom is not blocked`, info.lang === 'en' && /width=device-width/.test(info.viewport) && !/user-scalable=no|maximum-scale=1(\.0)?(,|$)/.test(info.viewport), `${info.lang} ${info.viewport}`);
      t.check(`${label}: no unnamed links or buttons, no image without alt, no duplicate ids`, info.emptyLinks === 0 && info.emptyButtons === 0 && info.imgsNoAlt === 0 && info.ids.length === 0, JSON.stringify({ l: info.emptyLinks, b: info.emptyButtons, i: info.imgsNoAlt, ids: info.ids.slice(0, 4) }));
      if (size === 'phone') { const prev = titles.get(info.title) ?? new Set(); prev.add(path.split('?')[0]); titles.set(info.title, prev); if (path.includes('?')) filterTitles.add(info.title); }
      const lay = await t.layout(p, label);
      t.check(`${label}: every tap target is at least 44px`, lay.small.length === 0, lay.small.slice(0, 6).join(' | '));
      await t.axe(p, label);
      if (size === 'phone' && ['seller dashboard', 'my lots', 'create', 'sale overdue (winner)', 'payment operations', 'profile'].includes(name)) await t.shot(p, `${name.replace(/\W+/g, '-')}-390`);
    }
  }
  const dup = [...titles.entries()].filter(([, paths]) => paths.size > 1).map(([ttl, paths]) => [ttl, [...paths]]);
  t.note(`Filtered list views share their page's title (${[...filterTitles].join(', ')}); the filter is in the tab state, not the title.`);
  t.eq('page titles are unique across the pages swept', dup.map(([ttl, paths]) => `${ttl}: ${paths.join(', ')}`), []);
  t.check('page titles name the product', [...titles.keys()].every((x) => /Dot\.Auction/.test(x)), [...titles.keys()].filter((x) => !/Dot\.Auction/.test(x)).join(' / '));

  // ---------- keyboard walk on the seller form ---------------------------------------------------------------------------------
  const kb = await (await t.context({ label: 'keyboard' })).newPage();
  await t.login(kb, 'thandi', `/seller/auctions/${draft}/edit`);
  const stops = [];
  for (let i = 0; i < 70; i++) {
    await kb.keyboard.press('Tab');
    await kb.waitForTimeout(150); // focus styles transition in
    const s = await kb.evaluate(() => {
      const e = document.activeElement; if (!e || e === document.body) return null;
      const cs = getComputedStyle(e); const r = e.getBoundingClientRect();
      const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || (cs.boxShadow && cs.boxShadow !== 'none');
      return { tag: e.tagName.toLowerCase(), id: e.id, name: (e.innerText || e.getAttribute('aria-label') || e.value || e.name || '').trim().slice(0, 30), ring, visible: r.width > 0 && r.height > 0 && cs.visibility !== 'hidden', inView: r.bottom > 0 && r.top < innerHeight };
    });
    if (s) { const prev = stops[stops.length - 1]; if (prev && prev.id && prev.id === s.id) { prev.sub = (prev.sub ?? 0) + 1; } else stops.push(s); } // date fields take several Tab stops (month, day, picker) inside one element
  }
  t.check('the first Tab stop is the skip link', /skip/i.test(stops[0]?.name ?? ''), JSON.stringify(stops[0]));
  const noRing = stops.filter((s) => !s.ring);
  t.eq('every keyboard-focused control shows a focus ring', noRing.map((s) => `${s.tag}#${s.id} ${s.name}`), []);
  t.eq('no keyboard stop is invisible', stops.filter((s) => !s.visible).map((s) => `${s.tag}#${s.id} ${s.name}`), []);
  t.check('the walk reaches the form fields in a sensible order', ['title', 'category_id', 'description', 'starting_price'].every((id) => stops.some((s) => s.id === id)), stops.map((s) => s.id || s.tag).join(','));
  const order = ['title', 'category_id', 'description', 'starting_price', 'bid_increment', 'reserve_price', 'buy_now_price', 'starts_at', 'ends_at'].map((id) => stops.findIndex((s) => s.id === id));
  t.check('...the order matches the reading order of the page', order.every((v, i) => v >= 0 && (i === 0 || v > order[i - 1])), order.join(','));
  // skip link
  await t.go(kb, `/seller/auctions/${draft}/edit`);
  await kb.keyboard.press('Tab');
  t.check('the skip link is visible when focused', await kb.evaluate(() => { const r = document.activeElement.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.top >= 0 && r.left >= 0; }));
  await kb.keyboard.press('Enter');
  t.check('using the skip link moves focus into the main content', await kb.evaluate(() => !!document.activeElement.closest('main') || document.activeElement.id === 'main'));
  // dialog by keyboard: open, focus trapped, Escape closes, focus returns
  await t.go(kb, `/seller/auctions/${draft}/edit`);
  const trigger = kb.getByRole('button', { name: 'Delete draft' });
  await trigger.focus();
  await kb.keyboard.press('Enter');
  const dlg = kb.getByRole('dialog');
  await dlg.waitFor();
  t.check('opening a confirm dialog moves focus inside it', await kb.evaluate(() => !!document.activeElement.closest('dialog, [role=dialog]')));
  const inside = [];
  for (let i = 0; i < 8; i++) { await kb.keyboard.press('Tab'); inside.push(await kb.evaluate(() => (document.activeElement === document.body || document.activeElement === document.documentElement) ? 'browser' : (document.activeElement.closest('dialog, [role=dialog]') ? 'dialog' : 'PAGE'))); }
  t.check('Tab never reaches the page behind the dialog (only its own controls, or the browser\'s own UI)', !inside.includes('PAGE'), inside.join(','));
  await kb.keyboard.press('Escape');
  await dlg.waitFor({ state: 'hidden' });
  t.check('Escape closes the dialog and returns focus to its trigger', await kb.evaluate(() => /Delete draft/.test(document.activeElement.innerText)));
  t.eq('...and nothing was deleted by closing it', app.q('select count(*) c from auctions where id = ?', draft)[0].c, 1);
  // the phone menu by keyboard
  const phone = await (await t.context({ size: 'phone', touch: true, label: 'menu' })).newPage();
  await t.login(phone, 'thandi', '/dashboard');
  await phone.getByRole('button', { name: /More|Menu/ }).first().click();
  await phone.waitForSelector('dialog.ds-dialog[open]');
  t.check('the phone menu opens', (await phone.locator('dialog.ds-dialog[open]').count()) === 1);
  t.check('focus moves into the phone menu', await phone.evaluate(() => !!document.activeElement.closest('dialog[open]')));
  await phone.keyboard.press('Escape');
  await phone.waitForFunction(() => !document.querySelector('dialog.ds-dialog[open]'));
  t.check('Escape closes the phone menu', (await phone.locator('dialog.ds-dialog[open]').count()) === 0);
  t.check('...and focus returns to the More button', await phone.evaluate(() => /More|Menu/.test(document.activeElement.innerText + document.activeElement.getAttribute('aria-label'))));

  // ---------- reduced motion ----------------------------------------------------------------------------------------------------
  const rm = await (await t.context({ reducedMotion: 'reduce', label: 'reduced-motion' })).newPage();
  await t.login(rm, 'thandi', '/seller/auctions');
  const motion = await rm.evaluate(() => {
    const longest = (cs) => Math.max(...cs.animationDuration.split(',').map((x) => parseFloat(x) * (x.includes('ms') ? 0.001 : 1)), ...cs.transitionDuration.split(',').map((x) => parseFloat(x) * (x.includes('ms') ? 0.001 : 1)));
    const bad = []; for (const e of document.querySelectorAll('body *')) { const cs = getComputedStyle(e); const d = longest(cs); if (d > 0.05 && cs.display !== 'none') bad.push(`${e.tagName.toLowerCase()}.${String(e.className).split(' ')[0]} ${d}s`); }
    return { bad: [...new Set(bad)].slice(0, 8), scroll: getComputedStyle(document.documentElement).scrollBehavior };
  });
  t.eq('with reduced motion requested, no element animates or transitions for more than 50 ms', motion.bad, []);
  t.check('...and smooth scrolling is off', motion.scroll !== 'smooth', motion.scroll);
  await rm.getByRole('button', { name: 'Switch to dark theme' }).first().click().catch(() => {});

  // ---------- every width between a phone and a laptop: the table breakpoint, the rail and tablets -----------------------------------------
  const widths = [640, 680, 720, 768, 820, 900, 1024, 1180];
  const wp = await (await t.context({ size: { width: 700, height: 800 }, label: 'widths' })).newPage();
  await t.login(wp, 'thandi', '/dashboard');
  const roleOf = (r) => r;
  for (const w of widths) {
    await wp.setViewportSize({ width: w, height: 800 });
    for (const [role, path, name] of [['thandi', '/seller/auctions', 'my lots'], ['thandi', '/dashboard', 'dashboard'], ['thandi', '/settlements', 'payments'], ['thandi', `/seller/auctions/${draft}/edit`, 'edit lot']]) {
      await wp.goto(app.base + path);
      await t.layout(wp, `${name} at ${w} px wide`);
    }
  }
  const opw = await (await t.context({ size: { width: 700, height: 800 }, label: 'widths-op' })).newPage();
  await t.login(opw, 'naledi', '/dashboard');
  for (const w of widths) { await opw.setViewportSize({ width: w, height: 800 }); await opw.goto(app.base + '/operator/settlement-payments'); await t.layout(opw, `payment operations at ${w} px wide`); }

  // ---------- zoom: 200% (720 css px wide on a 1440 screen), 400% (360) -----------------------------------------------------------
  for (const [w, zoom] of [[720, '200%'], [320, '450%']]) {
    const zc = await t.context({ size: { width: w, height: 800 }, label: `zoom-${zoom}` });
    const zp = await zc.newPage();
    await t.login(zp, 'thandi', '/seller/auctions');
    for (const path of ['/seller/auctions', `/seller/auctions/${draft}/edit`, '/dashboard', '/auctions', '/user/profile']) {
      await zp.goto(app.base + path);
      const lay = await t.layout(zp, `${path} at ${zoom} zoom (${w} css px)`);
      const clipped = await zp.evaluate(() => [...document.querySelectorAll('h1, h2, label, button, a.ds-btn')].filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && !e.closest('.ds-tablewrap, .ds-tabs') && (r.right > innerWidth + 1 || r.left < -1); }).map((e) => e.tagName + ' ' + e.innerText.slice(0, 20)));
      t.eq(`${path} at ${zoom}: no heading, label or button is cut off`, clipped, []);
    }
    await t.shot(zp, `zoom-${w}`);
  }
}
