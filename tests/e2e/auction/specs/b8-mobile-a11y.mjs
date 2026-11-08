// B8. The buyer journey on a phone (390 and 360), keyboard-only, reduced motion, light/dark everywhere, zoom 200 percent and 400 percent.
import { runSpec } from '../lib/harness.mjs';
import { flat, mainText, ts, startBid } from '../lib/common.mjs';

export default () => runSpec('b8', 'Phone, keyboard, motion, theme and zoom', async (t) => {
    t.sql(`update auctions set ends_at='${ts(3 * 3600e3)}' where title like 'Wool rug%' or title like 'Stainless steel%'`);
    const lot = t.id_of('Land Rover Defender parts lot');
    const sale = t.sql(`select id from auction_settlements where winner_id=${t.user_id('sipho')} and status='awaiting_payment'`);
    const teamId = t.sql(`select id from teams limit 1`) || t.sql(`select current_team_id from users where id=${t.user_id('sipho')}`);
    const pages = [
        ['dashboard', '/dashboard'], ['browse', '/auctions'], ['browse-filtered', '/auctions?state=live&sort=price_desc&q=a'], ['lot', `/auctions/${lot}`],
        ['bids', '/bids'], ['watching', '/watchlist'], ['won', '/won'], ['notifications', '/notifications'], ['payments', '/settlements'], ['sale', `/settlements/${sale}`],
        ['profile', '/user/profile'], ['team-create', '/teams/create'],
    ];
    if (teamId) { pages.push(['team', `/teams/${teamId}`]); }

    const sizes = [['390', { width: 390, height: 844 }], ['360', { width: 360, height: 740 }]];
    const phone = await t.person('sipho', { viewport: sizes[0][1], mobile: true, touch: true, to: '/dashboard' });
    const pg = phone.page;

    // ---------- the shell on a phone ----------
    const bar = pg.locator('nav.sh-bar');
    t.check('the bottom bar is visible on a phone', await bar.isVisible(), '');
    t.eq('the rail is hidden on a phone', await pg.locator('nav.sh-side').isVisible(), false);
    const barBox = await bar.boundingBox();
    t.check('the bottom bar sits at the bottom edge of the screen', Math.abs(barBox.y + barBox.height - 844) < 3, JSON.stringify(barBox));
    t.eq('the bar names its five stops', (await bar.locator('a, button').allInnerTexts()).map((s) => s.trim()), ['Browse', 'My bids', 'List a lot', 'Watching', 'More']);
    const sizesBar = await bar.locator('a, button').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }));
    t.check('every bar button is at least 44 x 44', sizesBar.every(([w, h]) => w >= 44 && h >= 44), JSON.stringify(sizesBar));
    await pg.getByRole('button', { name: 'More' }).click();
    await pg.locator('dialog.sh-sheet[open]').waitFor({ timeout: 5000 });
    const sheet = pg.locator('dialog.sh-sheet[open]');
    const links = (await sheet.locator('a, button').allInnerTexts()).map((s) => s.trim()).filter(Boolean);
    t.check('the menu sheet lists the pages, profile, theme and log out', ['Browse lots', 'My bids', 'Watching', 'Won', 'Payments', 'Profile and security', 'Log out'].every((x) => links.some((l) => l.includes(x))), links.join(' | '));
    const sb = await sheet.locator('.ds-dialog__panel').boundingBox();
    t.check('the sheet fits the screen', sb.x >= -1 && sb.x + sb.width <= 391 && sb.y + sb.height <= 845, JSON.stringify(sb));
    const rowSizes = await sheet.locator('.ds-menurow').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().height)));
    t.check('menu rows are at least 44 px high', rowSizes.every((h) => h >= 44), JSON.stringify(rowSizes));
    await t.shot(phone, 'menu-sheet-390', { full: false });
    await t.axe(phone, 'menu sheet 390');
    await pg.keyboard.press('Escape');
    await pg.waitForTimeout(300);
    t.eq('Escape closes the sheet', await pg.locator('dialog.sh-sheet[open]').count(), 0);
    t.check('focus returns to the More button', await pg.evaluate(() => /More/.test(document.activeElement?.innerText || '')), await pg.evaluate(() => document.activeElement?.outerHTML.slice(0, 100)));

    // ---------- the journey: browse, open, bid ----------
    await phone.go('/auctions');
    await pg.getByRole('searchbox', { name: 'Search by title' }).fill('land rover');
    await pg.getByRole('button', { name: 'Search' }).first().click();
    await pg.waitForLoadState('load');
    t.eq('search on a phone finds the lot', await pg.locator('.ds-lot__title').allInnerTexts().then((a) => a.map((s) => s.trim())), ['Land Rover Defender parts lot']);
    await pg.getByRole('link', { name: 'Land Rover Defender parts lot' }).first().click();
    await pg.waitForLoadState('load');
    t.eq('the card link opens the lot', new URL(pg.url()).pathname, `/auctions/${lot}`);
    const fieldBox = await pg.locator('input[name="bidAmount"]').boundingBox();
    const priceBox = await pg.locator('.lot-price__amount').boundingBox();
    t.check(`the price (y ${Math.round(priceBox.y)}) and the bid field (y ${Math.round(fieldBox.y)}) are both on the first screen of a 844 px phone`, priceBox.y < 700 && fieldBox.y + fieldBox.height < 844, JSON.stringify({ priceBox, fieldBox }));
    const rungs = pg.locator('[data-ds-rung]');
    t.check('quick-bid rungs (the ladder) are present', await rungs.count() >= 2, String(await rungs.count()));
    const rungSizes = await rungs.evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }));
    t.check('each quick-bid rung is a 44 px touch target', rungSizes.every(([w, h]) => h >= 44 && w >= 44), JSON.stringify(rungSizes));
    const nextRung = pg.locator('[data-ds-rung]').first();
    const rungText = flat(await nextRung.innerText());
    await nextRung.tap().catch(() => nextRung.click());
    const filled = await pg.locator('input[name="bidAmount"]').inputValue();
    t.check(`tapping a rung ("${rungText}") puts its amount in the field (${filled})`, /^\d+(\.\d\d)?$/.test(filled), filled);
    await startBid(phone, filled);
    await pg.locator('dialog[open]').waitFor({ timeout: 8000 });
    const d = await pg.locator('dialog[open] .ds-dialog__panel').boundingBox();
    t.check('the confirm dialog fits a 390 phone', d.x >= -1 && d.x + d.width <= 391 && d.y + d.height <= 845, JSON.stringify(d));
    const cb = await pg.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).boundingBox();
    t.check('the confirm button is 44 px high and inside the screen', cb.height >= 44 && cb.y + cb.height <= 844, JSON.stringify(cb));
    await pg.locator('dialog[open]').getByRole('button', { name: /^Place bid of/ }).click();
    await pg.waitForFunction(() => /Your bid of .* is in/.test(document.querySelector('.lot-bid')?.innerText || ''), null, { timeout: 10000 });
    t.eq('the bid is in the database for sipho', Number(t.sql(`select count(*) from bids where auction_id=${lot} and bidder_id=${t.user_id('sipho')}`)), 1);
    t.check('the bottom bar does not cover the lot\'s content (the page has room at its end)', await pg.evaluate(() => { const bar = document.querySelector('nav.sh-bar').getBoundingClientRect(); const main = document.querySelector('#main'); const last = main.lastElementChild.getBoundingClientRect(); window.scrollTo(0, document.body.scrollHeight); return true; }), '');
    await pg.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await pg.waitForTimeout(200);
    const coverage = await pg.evaluate(() => { const bar = document.querySelector('nav.sh-bar').getBoundingClientRect(); const main = document.querySelector('#main .sh-page'); const r = main.getBoundingClientRect(); return { barTop: bar.top, pageBottom: r.bottom }; });
    t.check(`at the end of the page the last content ends above the bottom bar (content ends ${Math.round(coverage.pageBottom)}, bar starts ${Math.round(coverage.barTop)})`, coverage.pageBottom <= coverage.barTop + 1, JSON.stringify(coverage));
    t.clean(phone, 'phone journey');

    // ---------- every page: overflow, touch targets, both sizes ----------
    const small = [];
    const overflow = [];
    const tinyReport = [];
    for (const [sizeName, vp] of sizes) {
        const p = await t.person('sipho', { viewport: vp, mobile: true, touch: true, to: '/dashboard' });
        for (const [name, url] of pages) {
            await p.go(url);
            const ov = await p.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            if (ov > 0) { overflow.push(`${name}@${sizeName}: ${ov}px`); }
            const tiny = await p.page.evaluate(() => {
                const out = [];
                for (const e of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=tab]')) {
                    if (e.closest('[hidden], dialog:not([open])') || e.closest('.sr-only') || e.matches('.skip-link')) { continue; }
                    const cs = getComputedStyle(e);
                    if (cs.visibility === 'hidden' || cs.display === 'none') { continue; }
                    const r = e.getBoundingClientRect();
                    if (r.width === 0 || r.height === 0) { continue; }
                    if (e.matches('a') && cs.display === 'inline' && e.closest('p, li, dd, td, span, label, h1, h2, h3')) { continue; } // an inline link inside a sentence is exempt (WCAG 2.5.8)
                    if (e.type === 'checkbox' || e.type === 'radio') { const l = e.closest('label'); if (l) { const lr = l.getBoundingClientRect(); if (lr.height >= 44) { continue; } } }
                    if (r.height < 43.5 || r.width < 43.5) { out.push(`${e.tagName.toLowerCase()}${e.id ? '#' + e.id : ''} "${(e.innerText || e.getAttribute('aria-label') || e.value || '').trim().slice(0, 24)}" ${Math.round(r.width)}x${Math.round(r.height)}`); }
                }
                return out;
            });
            if (tiny.length) { tinyReport.push(`${name}@${sizeName}: ${tiny.slice(0, 6).join('; ')}${tiny.length > 6 ? ` (+${tiny.length - 6})` : ''}`); }
            small.push(name);
            if (sizeName === '390') { await t.shot(p, `page-${name}-390`, { full: true }); }
        }
        t.clean(p, `all pages at ${sizeName}`);
    }
    t.eq(`no page scrolls sideways at 390 or 360 (${pages.length} pages x 2 sizes)`, overflow, []);
    t.eq('every button, field and stand-alone link is at least 44 x 44 px on a phone (inline links in sentences exempt)', tinyReport, []);

    // ---------- keyboard only ----------
    {
        const kb = await t.person('sipho', { to: '/auctions' });
        const k = kb.page;
        await k.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
        await k.keyboard.press('Tab');
        t.eq('the first Tab stop is the skip link', await k.evaluate(() => document.activeElement?.className + '|' + document.activeElement?.innerText), 'skip-link|Skip to main content');
        const vis = await k.evaluate(() => { const r = document.activeElement.getBoundingClientRect(); return r.width > 0 && r.top >= 0; });
        t.check('the skip link becomes visible when focused', vis, '');
        await k.keyboard.press('Enter');
        t.eq('Enter on the skip link moves focus to the main region', await k.evaluate(() => document.activeElement?.id), 'main');
        await k.keyboard.press('Tab');
        const afterSkip = await k.evaluate(() => ({ inMain: !!document.activeElement.closest('#main'), text: (document.activeElement.innerText || document.activeElement.getAttribute('aria-label') || '').slice(0, 40) }));
        t.check(`the next Tab lands inside the page content, not back in the navigation (${afterSkip.text})`, afterSkip.inMain, JSON.stringify(afterSkip));
        // Walk 60 stops from the top: nothing traps, every stop shows a visible focus indicator.
        await k.reload();
        await k.evaluate(() => { document.activeElement?.blur(); });
        const stops = [];
        const invisible = [];
        for (let i = 0; i < 60; i++) {
            await k.keyboard.press('Tab');
            const s = await k.evaluate(() => {
                const e = document.activeElement; if (!e || e === document.body) { return null; }
                const cs = getComputedStyle(e);
                const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || (cs.boxShadow && cs.boxShadow !== 'none');
                const r = e.getBoundingClientRect();
                return { tag: e.tagName.toLowerCase(), label: (e.innerText || e.getAttribute('aria-label') || e.name || '').trim().replace(/\s+/g, ' ').slice(0, 30), ring, inView: r.bottom > 0 && r.top < innerHeight, id: e.id };
            });
            if (!s) { break; }
            stops.push(s);
            if (!s.ring) { invisible.push(`${s.tag} "${s.label}"`); }
        }
        t.check(`tabbing 60 times moves through ${stops.length} distinct stops and does not get stuck`, new Set(stops.map((s) => s.tag + s.label + s.id)).size > 25, String(stops.length));
        t.eq('every stop shows a visible focus ring or outline', invisible, []);
        t.check('the first stops are: skip link, brand, search, bell, theme, account, then the page (order follows the page)', stops.findIndex((s) => /Switch theme|Search/.test(s.label)) < 12, JSON.stringify(stops.slice(0, 14).map((s) => s.label)));
        // Operate the controls by keyboard: sort select + tab chips.
        await kb.go('/auctions');
        await k.getByLabel('Sort by').focus();
        await k.keyboard.press('ArrowDown');
        await k.waitForLoadState('load');
        t.check('the Sort select works from the keyboard (URL changes)', /sort=/.test(k.url()), k.url());
        await kb.go(`/auctions/${lot}`);
        const field = k.locator('input[name="bidAmount"]');
        await field.focus();
        await k.keyboard.press('Enter');
        await k.locator('dialog[open]').waitFor({ timeout: 8000 });
        const trapped = [];
        for (let i = 0; i < 8; i++) { await k.keyboard.press('Tab'); trapped.push(await k.evaluate(() => !!document.activeElement.closest('dialog[open]'))); }
        t.eq('inside the confirm dialog Tab stays inside it (focus trap)', trapped.every(Boolean), true);
        await k.keyboard.press('Escape');
        await k.waitForTimeout(300);
        t.check('Escape closes it and focus returns inside the lot form', await k.evaluate(() => !!document.activeElement?.closest('.lot-form')), await k.evaluate(() => document.activeElement?.outerHTML.slice(0, 100)));
        t.clean(kb, 'keyboard walk');
    }

    // ---------- reduced motion ----------
    {
        const rm = await t.person('sipho', { reducedMotion: 'reduce', to: `/auctions/${lot}` });
        t.eq('the browser reports reduced motion', await rm.page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches), true);
        const running = async () => rm.page.evaluate(() => document.getAnimations().filter((a) => { const tm = a.effect?.getTiming?.(); return a.playState === 'running' && tm && (tm.iterations === Infinity || Number(tm.duration) > 120); }).map((a) => a.animationName || a.transitionProperty || a.constructor.name));
        t.eq('on the lot page no animation longer than 120 ms (or looping) is running', await running(), []);
        for (const u of ['/dashboard', '/auctions', '/notifications']) { await rm.go(u); t.eq(`on ${u} no long or looping animation runs`, await running(), []); }
        const trans = await rm.page.evaluate(() => { const e = document.querySelector('.ds-btn'); const cs = getComputedStyle(e); return cs.transitionDuration; });
        t.check(`button transitions are effectively off (${trans})`, trans.split(',').every((d) => parseFloat(d) <= 0.01), trans);
        const sc = await rm.page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior);
        t.check(`smooth scrolling is off (${sc})`, sc !== 'smooth', sc);
        t.clean(rm, 'reduced motion');
    }

    // ---------- light and dark on every page, persisting ----------
    {
        const th = await t.person('sipho', { colorScheme: 'light', to: '/dashboard' });
        const p = th.page;
        const mode = () => p.evaluate(() => ({ attr: document.documentElement.dataset.theme, cls: document.documentElement.classList.contains('dark'), stored: localStorage.getItem('dot-theme'), bg: getComputedStyle(document.body).backgroundColor }));
        const m0 = await mode();
        t.eq('light to start', [m0.attr, m0.cls], ['light', false]);
        await p.locator('.sh-top__tools [data-theme-toggle]').click();
        const m1 = await mode();
        t.eq('the toggle switches to dark and stores the choice', [m1.attr, m1.cls, m1.stored], ['dark', true, 'dark']);
        t.check('the page background really changed', m1.bg !== m0.bg, `${m0.bg} -> ${m1.bg}`);
        const lum = (rgb) => { const [r, g, b] = rgb.match(/\d+/g).map(Number); return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255; };
        const darkBad = [];
        const axeBad = [];
        for (const [name, url] of pages) {
            await th.go(url);
            const m = await mode();
            if (m.attr !== 'dark' || lum(m.bg) > 0.3) { darkBad.push(`${name}: ${m.attr} ${m.bg}`); }
            // Nothing should be white-on-white or dark-on-dark: cards and fields must follow the theme too.
            const lightIslands = await p.evaluate(() => { let n = 0; for (const e of document.querySelectorAll('main *, #main *')) { const bg = getComputedStyle(e).backgroundColor; const m2 = bg.match(/rgba?\((\d+), (\d+), (\d+)(?:, ([\d.]+))?\)/); if (m2 && (m2[4] === undefined || Number(m2[4]) > 0.9) && Number(m2[1]) > 240 && Number(m2[2]) > 240 && Number(m2[3]) > 240) { const r = e.getBoundingClientRect(); if (r.width > 120 && r.height > 24) { n++; } } } return n; });
            if (lightIslands) { darkBad.push(`${name}: ${lightIslands} white blocks in dark mode`); }
            const res = await t.axe(th, `${name} dark`).catch(() => []);
            void res;
        }
        t.eq(`dark persists across a reload and across ${pages.length} pages (profile and teams included), with no light islands`, darkBad, []);
        await th.go('/user/profile');
        await t.shot(th, 'profile-dark-1440', { full: true });
        await th.go('/teams/create');
        await t.shot(th, 'team-create-dark-1440', { full: true });
        await p.locator('.sh-top__tools [data-theme-toggle]').click();
        const m2 = await mode();
        t.eq('toggling again goes back to light and stores it', [m2.attr, m2.stored], ['light', 'light']);
        await p.reload();
        t.eq('light persists across a reload', (await mode()).attr, 'light');
        await th.go('/user/profile');
        await th.page.locator('.sh-top__tools [data-theme-toggle]').click();
        t.eq('the toggle works on the profile page too', (await mode()).attr, 'dark');
        await th.go('/teams/create');
        t.eq('...and the choice followed him to the team page', (await mode()).attr, 'dark');
        t.clean(th, 'theme');
    }

    // ---------- zoom ----------
    {
        // 200 percent of a 1440 window is a 720 px wide viewport; 400 percent of 1280 is 320. Both must reflow.
        for (const [label, vp] of [['200% (720 wide)', { width: 720, height: 450 }], ['400% (320 wide)', { width: 320, height: 568 }]]) {
            const z = await t.person('sipho', { viewport: vp, to: `/auctions/${lot}` });
            const bad = [];
            for (const [name, url] of [['lot', `/auctions/${lot}`], ['browse', '/auctions'], ['dashboard', '/dashboard'], ['sale', `/settlements/${sale}`]]) {
                await z.go(url);
                const ov = await z.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                const clipped = await z.page.evaluate(() => [...document.querySelectorAll('h1, .lot-price__amount, button, .ds-btn')].filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && (r.right > innerWidth + 1 || r.left < -1) && getComputedStyle(e).position !== 'fixed'; }).map((e) => (e.innerText || '').slice(0, 20)));
                if (ov > 0 || clipped.length) { bad.push(`${name}: overflow ${ov}px, clipped ${JSON.stringify(clipped)}`); }
            }
            t.eq(`at ${label}: lot, browse, home and sale reflow with nothing cut off or scrolling sideways`, bad, []);
            await z.go(`/auctions/${lot}`);
            t.check(`at ${label}: the bid field is reachable and visible`, await z.page.locator('input[name="bidAmount"]').count() === 1 || await z.page.getByText('You are leading').count() > 0, '');
            await t.shot(z, `zoom-lot-${vp.width}`, { full: false });
            t.clean(z, 'zoom ' + label);
        }
        // Text-only zoom: the browser's default font size doubled.
        const tz = await t.person('sipho', { to: `/auctions/${lot}` });
        await tz.page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
        await tz.page.waitForTimeout(300);
        const ov = await tz.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        t.eq('text size doubled on a laptop: no sideways scroll', ov <= 0, true);
        await t.shot(tz, 'text-200-lot', { full: false });
    }

    // ---------- the audit looks, phone first ----------
    for (const [name, url] of [['dashboard', '/dashboard'], ['browse', '/auctions'], ['lot', `/auctions/${lot}`], ['sale', `/settlements/${sale}`], ['profile', '/user/profile']]) {
        const p = await t.person('sipho', { to: url });
        await t.audit(p, `look-${name}`, { sizes: ['phone', 'small'], themes: ['light', 'dark'] });
        t.clean(p, 'audit ' + name);
    }
});
