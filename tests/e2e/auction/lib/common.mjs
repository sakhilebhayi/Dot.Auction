// Small helpers shared by the specs.
import { settle } from './harness.mjs';

/** Collapse whitespace and glue "R 1,300 .00" back into "R1,300.00" so the page text can be compared with plain strings. */
export const flat = (s) => String(s).replace(/\s+/g, ' ').replace(/R ?([\d,]+) ?\.(\d\d)/g, 'R$1.$2').replace(/ ([.,])/g, '$1').trim();

export const pageText = async (page, sel = 'body') => flat(await page.locator(sel).first().innerText());

/** The text of <main> only (no rail, no top bar). */
export const mainText = async (page) => pageText(page, '#main, main');

/** Internal links inside the main region, de-duplicated, as {href, text}. */
export async function mainLinks(page) {
    return page.evaluate(() => {
        const root = document.querySelector('#main, main') || document.body;
        const seen = new Map();
        for (const a of root.querySelectorAll('a[href]')) {
            const href = a.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('javascript:')) { continue; }
            const u = new URL(href, location.href);
            if (u.origin !== location.origin) { continue; }
            if (!seen.has(u.pathname + u.search)) { seen.set(u.pathname + u.search, (a.innerText || a.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim()); }
        }
        return [...seen.entries()].map(([href, text]) => ({ href, text }));
    });
}

/** Ask the server (with this person's cookies) for each link and report the ones that do not answer 2xx after redirects. */
export async function deadLinks(p, links, skip = /\/(logout|dev\/logout)/) {
    const dead = [];
    for (const l of links) {
        if (skip.test(l.href)) { continue; }
        const r = await p.ctx.request.get(l.href, { maxRedirects: 5 });
        if (r.status() >= 400) { dead.push(`${l.href} -> ${r.status()}`); }
    }
    return dead;
}

export const money = (n) => 'R' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Move an auction's close (and optionally start) in the database: UTC "Y-m-d H:i:s". */
export const ts = (msFromNow) => new Date(Date.now() + msFromNow).toISOString().replace('T', ' ').slice(0, 19);

export function setEnds(t, auctionId, msFromNow, extra = '') {
    t.sql(`update auctions set ends_at='${ts(msFromNow)}'${extra ? ', ' + extra : ''} where id=${auctionId}`);
}

export async function gotoLot(p, id) { await p.go(`/auctions/${id}`); }

export { settle };

/** Type an amount into the lot page's bid field and press "Place bid". Leaves the confirm dialog open. */
export async function startBid(p, amount) {
    const field = p.page.locator('input[name="bidAmount"]');
    await field.fill(String(amount));
    await p.page.getByRole('button', { name: 'Place bid', exact: true }).click();
}

/** The whole happy path: type, review, confirm. Resolves when the panel says the bid is in (or an error shows). */
export async function bidUI(p, amount) {
    await startBid(p, amount);
    const dlg = p.page.locator('dialog[open]');
    await dlg.waitFor({ state: 'visible', timeout: 8000 });
    await dlg.getByRole('button', { name: /^Place bid of/ }).click();
    await p.page.locator('dialog[open]').waitFor({ state: 'detached', timeout: 8000 }).catch(() => {});
    await p.page.waitForFunction(() => /Your bid of .* is in|You are leading|You bought|Someone bid first|too low|closed|already|Too many/i.test(document.querySelector('.lot-bid')?.innerText || ''), null, { timeout: 10000 });
}

/** Wait until text shows up anywhere in the main region (case-insensitive substring). */
export async function waitText(p, needle, timeout = 15000) {
    await p.page.waitForFunction((n) => (document.querySelector('#main, main')?.innerText || '').replace(/\s+/g, ' ').toLowerCase().includes(n.toLowerCase()), needle, { timeout });
}
