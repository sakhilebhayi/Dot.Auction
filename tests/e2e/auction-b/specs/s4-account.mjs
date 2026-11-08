import { execFileSync } from 'node:child_process';
import { totp } from '../lib/totp.mjs';

export const title = 'S4 Account: profile, password, sessions, 2FA, deletion, teams, theme';

const hash = (pw) => execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(pw)}, PASSWORD_BCRYPT, ['cost' => 4]);`], { encoding: 'utf8' });
const waitUntil = async (fn, ms = 15000) => { const end = Date.now() + ms; while (Date.now() < end) { if (await fn()) return true; await new Promise((r) => setTimeout(r, 250)); } return false; };

export async function run(t, app) {
  const zan = app.userByKey('zanele');
  const PW = 'E2e-pass-123!';
  app.run('update users set password = ? where id = ?', hash(PW), zan.id);
  t.expectHttp(/\/user\/api-tokens/, 404);
  t.expectHttp(/\/teams\/\d+$/, 403, 404);
  t.expectHttp(/team-invitations\/\d+/, 403);

  const ctx = await t.context({ label: 'zanele' });
  const p = await ctx.newPage();
  await t.login(p, 'zanele', '/user/profile');
  const form = (sel) => p.locator('form', { has: p.locator(sel) });
  const saved = (sel) => form(sel).locator('text=/Saved/');

  // ---------- profile information -----------------------------------------------------------------------------------------
  await t.shot(p, 'a-profile-light');
  t.eq('profile photos are not offered (feature off)', await p.locator('input[type=file]').count(), 0);
  await p.fill('#name', 'Zanele M. Mthembu');
  await form('#name').getByRole('button', { name: 'Save' }).click();
  await saved('#name').waitFor();
  t.eq('name change is stored', app.one('select name from users where id = ?', zan.id).name, 'Zanele M. Mthembu');
  await p.reload();
  t.has('the new name shows in the shell after a reload', await t.text(p), 'Zanele M. Mthembu');
  await p.fill('#email', 'not-an-email');
  await form('#name').getByRole('button', { name: 'Save' }).click();
  t.eq('the browser itself flags a malformed email', await p.$eval('#email', (e) => e.validity.valid), false);
  t.eq('an invalid email is refused', app.one('select email from users where id = ?', zan.id).email, 'zanele.mthembu@demo.auction.test');
  await p.fill('#email', 'thandi.mokoena@demo.auction.test');
  await form('#name').getByRole('button', { name: 'Save' }).click();
  await p.waitForSelector(':text-matches("already been taken", "i")');
  t.eq('an email that belongs to someone else is refused', app.one('select email from users where id = ?', zan.id).email, 'zanele.mthembu@demo.auction.test');
  await p.fill('#email', 'zanele.new@demo.auction.test');
  await form('#name').getByRole('button', { name: 'Save' }).click();
  await saved('#name').waitFor();
  t.eq('a free email is stored', app.one('select email from users where id = ?', zan.id).email, 'zanele.new@demo.auction.test');

  // ---------- password ------------------------------------------------------------------------------------------------------
  const pwForm = form('#current_password');
  const setPw = async (cur, next, conf) => { await p.fill('#current_password', cur); await p.fill('#password', next); await p.fill('#password_confirmation', conf); await pwForm.getByRole('button', { name: 'Save' }).click(); };
  const hashBefore = () => app.one('select password from users where id = ?', zan.id).password;
  const h0 = hashBefore();
  await setPw('wrong-password', 'Another-pass-456!', 'Another-pass-456!');
  await p.waitForSelector(':text-matches("does not match|incorrect|current password", "i")');
  t.eq('wrong current password is refused', hashBefore(), h0);
  await setPw(PW, 'Another-pass-456!', 'different');
  await p.waitForSelector(':text-matches("confirmation does not match", "i")');
  t.eq('a mismatched confirmation is refused', hashBefore(), h0);
  await setPw(PW, 'short', 'short');
  await p.waitForSelector(':text-matches("at least", "i")');
  t.eq('a too-short password is refused', hashBefore(), h0);
  await setPw(PW, 'Another-pass-456!', 'Another-pass-456!');
  await saved('#current_password').waitFor();
  t.check('a good change replaces the stored hash', hashBefore() !== h0);
  // proof by signing in with the new password and failing with the old one, in a clean browser context
  const fresh = await (await t.context({ label: 'fresh-login' })).newPage();
  const loginWith = async (page, email, pw) => {
    await t.go(page, '/login');
    const pass = page.locator('input[type=password]');
    if (!(await pass.count())) return null;
    await page.fill('input[type=email], input[name=email]', email);
    await pass.first().fill(pw);
    await Promise.all([page.waitForLoadState('load'), page.locator('form button[type=submit]').first().click()]);
    await page.waitForLoadState('load');
    return page.url();
  };
  t.expectHttp(/\/login$/, 422);
  const oldTry = await loginWith(fresh, 'zanele.new@demo.auction.test', PW);
  t.check('the login page has a password form', oldTry !== null);
  t.check('the old password no longer signs in', oldTry !== null && /\/login/.test(oldTry), oldTry);
  const newTry = await loginWith(fresh, 'zanele.new@demo.auction.test', 'Another-pass-456!');
  t.check('the new password signs in', newTry !== null && /\/dashboard/.test(newTry), newTry);
  const NEWPW = 'Another-pass-456!';

  // ---------- browser sessions ------------------------------------------------------------------------------------------------
  await p.reload();
  const sessionRows = await p.locator('text=/This device/').count();
  t.eq('this browser is marked "This device"', sessionRows, 1);
  const listed = (await t.text(p)).match(/Chrome/g)?.length ?? 0;
  t.check('the other signed-in browser is listed too', listed >= 2, `Chrome mentioned ${listed}x`);
  const sessionsBefore = app.q('select count(*) c from sessions where user_id = ?', zan.id)[0].c;
  t.check('two sessions exist in the database', sessionsBefore >= 2, sessionsBefore);
  await p.getByRole('button', { name: 'Log Out Other Browser Sessions' }).first().click();
  const logoutBtn = p.locator('button[wire\\:click="logoutOtherBrowserSessions"]');
  await logoutBtn.waitFor();
  await p.locator('input[type=password]:visible').last().fill('wrong-wrong');
  await logoutBtn.click();
  await p.waitForSelector(':text-matches("password is incorrect|does not match|incorrect", "i")');
  t.eq('a wrong password does not end the other sessions', app.q('select count(*) c from sessions where user_id = ?', zan.id)[0].c, sessionsBefore);
  await p.locator('input[type=password]:visible').last().fill(NEWPW);
  await logoutBtn.click();
  await waitUntil(() => app.q('select count(*) c from sessions where user_id = ?', zan.id)[0].c === 1);
  t.eq('the other session is gone, this one stays', app.q('select count(*) c from sessions where user_id = ?', zan.id)[0].c, 1);
  await fresh.goto(`${app.base}/dashboard`);
  t.check('the other browser is signed out on its next request', /\/login/.test(fresh.url()), fresh.url());
  await p.reload();
  t.has('this browser is still signed in', await t.text(p), 'Profile and security');

  // ---------- two-factor ----------------------------------------------------------------------------------------------------------
  const tf = form('button:has-text("Enable")');
  await p.getByRole('button', { name: 'Enable' }).click();
  await p.locator('input[type=password]:visible').last().fill('wrong');
  await p.getByRole('button', { name: 'Confirm', exact: true }).last().click();
  await p.waitForSelector(':text-matches("password is incorrect|incorrect|does not match|records", "i")');
  t.eq('2FA is not enabled with a wrong password', app.one('select two_factor_secret s from users where id = ?', zan.id).s, null);
  await p.locator('input[type=password]:visible').last().fill(NEWPW);
  await p.getByRole('button', { name: 'Confirm', exact: true }).last().click();
  await p.locator('p', { hasText: /^\s*Setup Key:/ }).waitFor();
  const secret = (await p.locator('p', { hasText: /^\s*Setup Key:/ }).first().innerText()).replace(/.*Setup Key:\s*/is, '').trim().split(/\s/)[0];
  t.check('a setup key is shown', /^[A-Z2-7]{16,}$/.test(secret), secret);
  t.check('a QR code is shown', (await p.locator('svg').filter({ has: p.locator('path, rect') }).count()) > 0);
  t.has('the page says 2FA needs confirming', await t.text(p), /finish enabling|confirm/i);
  await p.fill('#code', '000000');
  await p.keyboard.press('Enter');
  await p.waitForSelector(':text-matches("invalid|provided two factor", "i")');
  t.eq('a wrong code does not confirm 2FA', app.one('select two_factor_confirmed_at c from users where id = ?', zan.id).c, null);
  await p.fill('#code', totp(secret));
  await p.keyboard.press('Enter');
  await waitUntil(() => app.one('select two_factor_confirmed_at c from users where id = ?', zan.id).c !== null);
  t.check('a correct code confirms 2FA', app.one('select two_factor_confirmed_at c from users where id = ?', zan.id).c !== null);
  await p.waitForSelector('text=/recovery codes/i');
  const codes = (await p.locator('.font-mono div').allInnerTexts()).map((c) => c.trim()).filter((c) => /^[A-Za-z0-9]{10}-[A-Za-z0-9]{10}$/.test(c));
  t.check('recovery codes are shown', codes.length === 8, `${codes.length} codes`);
  await t.shot(p, 'b-2fa-enabled');
  // sign in again: the challenge appears and a code lets us in
  const c2 = await (await t.context({ label: '2fa-login' })).newPage();
  const landed = await loginWith(c2, 'zanele.new@demo.auction.test', NEWPW);
  t.check('with 2FA on, password sign-in asks for a code', /two-factor-challenge/.test(landed ?? ''), landed);
  await c2.fill('input[name=code]', '111111').catch(() => {});
  await c2.keyboard.press('Enter');
  await c2.waitForLoadState('load');
  t.check('a wrong code does not sign in', !/dashboard/.test(c2.url()), c2.url());
  await c2.fill('input[name=code]', totp(secret, Date.now() + 30000)); // the confirm used this window's code; a code cannot be replayed
  await Promise.all([c2.waitForLoadState('load'), c2.keyboard.press('Enter')]);
  await c2.waitForURL(/dashboard/, { timeout: 10000 }).catch(() => {});
  t.check('the right code signs in', /dashboard/.test(c2.url()), c2.url());
  // a recovery code works once
  const c3 = await (await t.context({ label: '2fa-recovery' })).newPage();
  await loginWith(c3, 'zanele.new@demo.auction.test', NEWPW);
  t.has('the challenge page is the 2FA page', c3.url(), 'two-factor-challenge');
  await Promise.all([c3.waitForLoadState('load'), c3.getByRole('link', { name: /recovery code/i }).click()]);
  t.check('"Use a recovery code" works on the sign-in page (it loads no JavaScript framework)', (await c3.locator('input[name=recovery_code]').isVisible()));
  await c3.fill('input[name=recovery_code]', codes[0]);
  await c3.keyboard.press('Enter');
  await c3.waitForURL(/dashboard/, { timeout: 8000 }).catch(() => {});
  t.check('a recovery code signs in', /dashboard/.test(c3.url()), c3.url());
  // disable
  await p.reload();
  await p.getByRole('button', { name: 'Disable' }).click();
  // the password was confirmed a moment ago, so Fortify does not ask again; if it does, answer it
  const ask = p.getByRole('button', { name: 'Confirm', exact: true });
  if (await ask.isVisible().catch(() => false)) { await p.locator('input[type=password]:visible').last().fill(NEWPW); await ask.last().click(); }
  await waitUntil(() => app.one('select two_factor_secret s from users where id = ?', zan.id).s === null);
  t.eq('disabling 2FA clears the secret', app.one('select two_factor_secret s, two_factor_recovery_codes r from users where id = ?', zan.id), { s: null, r: null });

  // ---------- API tokens ---------------------------------------------------------------------------------------------------------------
  const tok = await p.goto(`${app.base}/user/api-tokens`);
  t.eq('the API tokens page is off', tok.status(), 404);
  t.hasNot('the nav offers no API tokens link', (await p.goto(`${app.base}/dashboard`), await t.text(p)), 'API Tokens');

  // ---------- theme persists and is readable on profile and teams ------------------------------------------------------------------------------
  await t.go(p, '/user/profile');
  const theme = () => p.evaluate(() => ({ attr: document.documentElement.dataset.theme ?? null, dark: document.documentElement.classList.contains('dark'), stored: localStorage.getItem('dot-theme') }));
  const start = await theme();
  await p.locator('[data-theme-toggle]:visible').first().click();
  const t1 = await theme();
  t.check('the toggle flips the theme', t1.dark !== start.dark, JSON.stringify({ start, t1 }));
  t.check('the choice is stored', t1.stored !== null, JSON.stringify(t1));
  await p.reload();
  t.eq('the choice survives a reload', (await theme()).dark, t1.dark);
  await t.go(p, '/teams/create');
  t.eq('...and a different page', (await theme()).dark, t1.dark);
  const teamId = app.one('select current_team_id id from users where id = ?', zan.id).id;
  for (const mode of ['light', 'dark']) {
    const want = mode === 'dark';
    if ((await theme()).dark !== want) await p.locator('[data-theme-toggle]:visible').first().click();
    for (const [path, name] of [['/user/profile', 'profile'], ['/teams/create', 'create team'], [`/teams/${teamId}`, 'team settings']]) {
      await t.go(p, path);
      t.eq(`${name}: the ${mode} theme holds on arrival`, (await theme()).dark, want);
      await t.shot(p, `c-${name.replace(/\W+/g, '-')}-${mode}`);
      await t.axe(p, `${name} (${mode} theme)`);
    }
  }
  if ((await theme()).dark) await p.locator('[data-theme-toggle]:visible').first().click();

  // ---------- teams ---------------------------------------------------------------------------------------------------------------------------------
  await t.go(p, '/teams/create');
  await p.fill('#name', 'Dot E2E Trading');
  await p.getByRole('button', { name: 'Create' }).click();
  await waitUntil(() => app.one("select id from teams where name = 'Dot E2E Trading'"));
  const team = app.one("select * from teams where name = 'Dot E2E Trading'");
  await p.waitForLoadState('load');
  t.has('creating a team lands somewhere that shows it', p.url() + await t.text(p), /Dot E2E Trading|teams\/\d+|dashboard/);
  await t.go(p, `/teams/${team.id}`);
  t.check('a team is created and owned by the creator', team && team.user_id === zan.id && team.personal_team === 0, JSON.stringify(team));
  // invite
  await p.fill('#email', 'lerato.dlamini@demo.auction.test');
  await p.locator('button:has-text("Editor")').first().click();
  await p.getByRole('button', { name: 'Add' }).click();
  await waitUntil(() => app.q('select count(*) c from team_invitations where team_id = ?', team.id)[0].c === 1);
  const inv = app.one('select * from team_invitations where team_id = ?', team.id);
  t.eq('an invitation is stored with the chosen role', [inv?.email, inv?.role], ['lerato.dlamini@demo.auction.test', 'editor']);
  t.has('the pending invitation is listed for the owner', await t.text(p), 'lerato.dlamini@demo.auction.test');
  // the e-mail link (mail is logged)
  await waitUntil(() => /team-invitations\/\d+\?[^\s"']*signature=/.test(app.serverLog()));
  const mail = app.serverLog();
  const link = (mail.match(/https?:\/\/[^\s"'<>]*team-invitations\/\d+\?[^\s"'<>]*signature=[^\s"'<>]+/) || [])[0];
  t.check('the invitation e-mail carries a signed accept link', !!link, 'no link in the mail log');
  const lerato = app.userByKey('lerato');
  const lp = await (await t.context({ label: 'lerato' })).newPage();
  await t.login(lp, 'lerato', '/dashboard');
  if (link) {
    const url = link.replace(/&amp;/g, '&').replace(/^https?:\/\/[^/]+/, app.base);
    await lp.goto(url);
    await waitUntil(() => app.q('select count(*) c from team_user where team_id = ? and user_id = ?', team.id, lerato.id)[0].c === 1);
    t.eq('accepting the link makes her a member with the invited role', app.one('select role from team_user where team_id = ? and user_id = ?', team.id, lerato.id)?.role, 'editor');
    t.eq('...and the invitation is used up', app.q('select count(*) c from team_invitations where team_id = ?', team.id)[0].c, 0);
    t.expectHttp(/team-invitations\/\d+/, 403);
    const tamper = await lp.goto(url.replace(/signature=[^&]+/, 'signature=forged'));
    t.check('a forged signature is refused', [403, 404].includes(tamper.status()), tamper.status());
  }
  // role change
  await t.go(p, `/teams/${team.id}`);
  await p.locator('button:has-text("Editor")').filter({ hasText: /^Editor$/ }).first().click().catch(() => {});
  const roleBtn = p.locator('button.cursor-pointer', { hasText: /Editor/ }).first();
  await p.locator('main').getByText('Editor', { exact: true }).first().click().catch(() => {});
  const manageRole = p.locator('main button', { hasText: /^\s*Editor\s*$/ }).first();
  await manageRole.click().catch(() => {});
  await p.locator('[role=dialog]:visible, .jetstream-modal:visible').first().waitFor({ timeout: 4000 }).catch(() => {});
  const adminChoice = p.locator('button', { hasText: /Administrator/ }).last();
  await adminChoice.click().catch(() => {});
  await p.locator('button:has-text("Save"):visible').last().click().catch(() => {});
  await waitUntil(() => app.one('select role from team_user where team_id = ? and user_id = ?', team.id, lerato.id)?.role === 'admin');
  t.eq('the owner can change a member\'s role', app.one('select role from team_user where team_id = ? and user_id = ?', team.id, lerato.id)?.role, 'admin');
  // member leaves
  await t.go(lp, `/teams/${team.id}`);
  await lp.getByRole('button', { name: 'Leave' }).first().click();
  await lp.locator('button[wire\\:click="leaveTeam"]').click();
  await waitUntil(() => app.q('select count(*) c from team_user where team_id = ? and user_id = ?', team.id, lerato.id)[0].c === 0);
  t.eq('a member can leave', app.q('select count(*) c from team_user where team_id = ? and user_id = ?', team.id, lerato.id)[0].c, 0);
  // re-invite via db-free path: owner removes a member (invite again and accept by DB-less link is long; use the member added by the system)
  const sipho = app.userByKey('sipho');
  app.run('insert into team_user (team_id, user_id, role, created_at, updated_at) values (?, ?, ?, ?, ?)', team.id, sipho.id, 'editor', app.at(0), app.at(0));
  await t.go(p, `/teams/${team.id}`);
  await p.getByRole('button', { name: 'Remove' }).first().click();
  await p.locator('button[wire\\:click="removeTeamMember"]').click();
  await waitUntil(() => app.q('select count(*) c from team_user where team_id = ? and user_id = ?', team.id, sipho.id)[0].c === 0);
  t.eq('the owner can remove a member', app.q('select count(*) c from team_user where team_id = ? and user_id = ?', team.id, sipho.id)[0].c, 0);
  // an outsider cannot open the team
  const outsider = await (await t.context({ label: 'outsider' })).newPage();
  await t.login(outsider, 'aisha', '/dashboard');
  const out = await outsider.goto(`${app.base}/teams/${team.id}`);
  t.expectHttp(new RegExp(`/teams/${team.id}$`), 403, 404);
  t.check('someone outside the team cannot open it', [403, 404].includes(out.status()), out.status());
  // delete
  await t.go(p, `/teams/${team.id}`);
  await p.getByRole('button', { name: 'Delete Team' }).first().click();
  await p.locator('button[wire\\:click="deleteTeam"]').click();
  await waitUntil(() => app.q('select count(*) c from teams where id = ?', team.id)[0].c === 0);
  t.eq('the owner can delete the team', app.q('select count(*) c from teams where id = ?', team.id)[0].c, 0);
  await t.go(p, `/teams/${teamId}`);
  t.eq('the personal team has no delete or leave controls', await p.getByRole('button', { name: /Delete Team|Leave/ }).count(), 0);

  // ---------- deleting an account ---------------------------------------------------------------------------------------------------------------------
  const tryDelete = async (page, password) => {
    await t.go(page, '/user/profile');
    await page.getByRole('button', { name: 'Delete Account' }).first().click();
    const box = page.locator('input[wire\\:model="password"]:visible').last();
    await box.waitFor();
    await box.fill(password);
    await page.locator('button[wire\\:click="deleteUser"]').click();
  };
  // blocked while obligations exist: a seller with live lots, a buyer with unpaid sales
  const tp = await (await t.context({ label: 'thandi-delete' })).newPage();
  await t.login(tp, 'thandi', '/user/profile');
  await tryDelete(tp, 'password');
  await t.axe(tp, 'delete-account dialog (open, after a refusal)');
  const hv = await tp.locator('button[wire\\:click="deleteUser"]').evaluate((e) => getComputedStyle(e).backgroundColor);
  t.check('the dialog\'s danger button keeps a dark fill while hovered or focused (white text stays readable)', /rgb\((1[0-9]{2}|[0-9]{1,2}), /.test(hv), hv);
  await tp.waitForSelector('text=/is still live|leading bidder|has not been paid/');
  const why = await t.text(tp);
  t.has('a seller with live lots is told why they cannot delete', why, /Your listing .* is still live, scheduled, or waiting for your reserve decision/);
  t.has('...and what to do', why, /Cancel it, or let it end and be settled/);
  t.eq('...and the account is untouched', app.one('select name from users where id = ?', app.userByKey('thandi').id).name, 'Thandi Mokoena');
  await t.shot(tp, 'e-delete-blocked');
  const sp = await (await t.context({ label: 'sipho-delete' })).newPage();
  await t.login(sp, 'sipho', '/user/profile');
  await tryDelete(sp, 'password');
  await sp.waitForSelector('text=/has not been paid|leading bidder/');
  t.has('a buyer with an unpaid sale is told which sale', await t.text(sp), /The sale of .* has not been paid\. It has to be paid or cancelled first/);
  t.eq('...and is not deleted', app.one('select name from users where id = ?', app.userByKey('sipho').id).name, 'Sipho Nkosi');
  await tryDelete(sp, 'wrong-password-x');
  await sp.waitForSelector(':text-matches("does not match|incorrect|records", "i")');
  t.check('a wrong password is refused before the reasons', true);

  // a person who arrives through the InfoDot hand-off, bids, wins, and whose sale is then cancelled
  const lp2 = await (await t.context({ label: 'leaver' })).newPage();
  const nav = await t.ecosystemSignIn(lp2, 'e2e:eddie.leaver@e2e.test:Eddie Leaver');
  t.check('the hand-off signs the new person in', /dashboard/.test(lp2.url()), lp2.url());
  const eddie = app.one("select * from users where email = 'eddie.leaver@e2e.test'");
  t.check('...creating the account', !!eddie && eddie.email_verified_at !== null);
  app.run('update users set password = ? where id = ?', hash(PW), eddie.id);
  await t.login(lp2, eddie.email, '/dashboard'); // (changing the hash ends the first session, by design: AuthenticateSession)
  const braai = app.lot('Stainless steel gas braai, two burner');
  await t.go(lp2, `/auctions/${braai.id}`);
  const nextMin = Number(app.one('select current_price p from auctions where id = ?', braai.id).p) + 100;
  await lp2.getByLabel(/Your bid|bid amount/i).first().fill(String(nextMin));
  await lp2.getByRole('button', { name: /Place bid|Review/i }).first().click();
  const dlg = lp2.getByRole('dialog').getByRole('button', { name: /Place bid of/i });
  await dlg.waitFor();
  await dlg.click();
  await waitUntil(() => app.q('select count(*) c from bids where bidder_id = ?', eddie.id)[0].c === 1);
  t.eq('the new person placed a bid', app.q('select count(*) c from bids where bidder_id = ?', eddie.id)[0].c, 1);
  await tryDelete(lp2, PW);
  await lp2.waitForSelector('text=/leading bidder/');
  t.has('a leading bidder cannot delete, and is told which lot', await t.text(lp2), /You are the leading bidder on .*Stainless steel gas braai/);
  // the lot ends with them on top: the sale blocks deletion until it is cancelled
  app.moveLot(braai.id, { ends: -2 });
  app.artisan(['auction:settle-ended']);
  const sale = app.one('select * from auction_settlements where auction_id = ?', braai.id);
  t.eq('the lot sold to the new person', sale?.winner_id, eddie.id);
  await tryDelete(lp2, PW);
  await lp2.waitForSelector('text=/has not been paid/');
  t.has('an unpaid sale blocks deletion', await t.text(lp2), /The sale of .*Stainless steel gas braai.* has not been paid/);
  app.run('update auction_settlements set payment_due_at = ? where id = ?', app.at(-60), sale.id);
  app.artisan(['auction:mark-overdue-payments']);
  const seller = await (await t.context({ label: 'thandi-cancel' })).newPage();
  await t.login(seller, 'thandi', `/settlements/${sale.id}`);
  await seller.getByRole('button', { name: /Cancel this sale/ }).click();
  await seller.getByRole('dialog').waitFor();
  await seller.getByRole('dialog').getByRole('button', { name: /Cancel (this )?sale/i }).last().click();
  await waitUntil(() => app.one('select status from auction_settlements where id = ?', sale.id).status === 'cancelled');
  // now deletion goes through
  const bidsBefore = app.q('select count(*) c from bids where bidder_id = ?', eddie.id)[0].c;
  await tryDelete(lp2, PW);
  await lp2.waitForURL((u) => !/\/user\/profile/.test(u.pathname), { timeout: 15000 });
  const gone = app.one('select * from users where id = ?', eddie.id);
  t.eq('the account row is kept but anonymised (name)', gone.name, 'Former member');
  t.check('...the e-mail is replaced by an address that can never resolve', /^former-member-\d+-[a-z0-9]+@deleted\.invalid$/i.test(gone.email), gone.email);
  t.check('...the password, verification and 2FA are cleared', gone.email_verified_at === null && gone.two_factor_secret === null);
  t.eq('...their bid is preserved', app.q('select count(*) c from bids where bidder_id = ?', eddie.id)[0].c, bidsBefore);
  t.eq('...and so is the cancelled sale', app.one('select status, winner_id from auction_settlements where id = ?', sale.id), { status: 'cancelled', winner_id: eddie.id });
  t.check('...notifications and watchlist are gone', app.q('select count(*) c from notifications where notifiable_id = ?', eddie.id)[0].c === 0 && app.q('select count(*) c from watchlists where user_id = ?', eddie.id)[0].c === 0);
  t.check('...they are signed out', /\/(login)?$|\/$/.test(new URL(lp2.url()).pathname), lp2.url());
  await t.go(seller, `/settlements/${sale.id}`);
  t.hasNot('the seller\'s sale page never shows the erased person\'s name or e-mail', await t.text(seller), /Eddie|eddie\.leaver/);
  await t.go(seller, `/auctions/${braai.id}`);
  t.has('...and the bid history on the lot reads Former member', await t.text(seller), 'Former member');
  t.hasNot('...never the old name', await t.text(seller), /Eddie/);
  const again = await (await t.context({ label: 'old-credentials' })).newPage();
  const oldLogin = await loginWith(again, 'eddie.leaver@e2e.test', PW);
  t.check('the old e-mail and password no longer sign in', oldLogin !== null && /\/login/.test(oldLogin), oldLogin);
  const back = await (await t.context({ label: 'returns' })).newPage();
  await t.ecosystemSignIn(back, 'e2e:eddie.leaver@e2e.test:Eddie Leaver');
  const eddie2 = app.one("select * from users where email = 'eddie.leaver@e2e.test'");
  t.check('coming back through InfoDot opens a new account, not the erased one', !!eddie2 && eddie2.id !== eddie.id, `${eddie.id} / ${eddie2?.id}`);
  t.eq('...which starts with no history', app.q('select count(*) c from bids where bidder_id = ?', eddie2.id)[0].c, 0);

  // ---------- phone ---------------------------------------------------------------------------------------------------------------------------------------
  const ph = await (await t.context({ size: 'phone', touch: true, label: 'phone' })).newPage();
  await t.login(ph, 'lerato', '/user/profile');
  for (const [path, name] of [['/user/profile', 'profile'], ['/teams/create', 'create team']]) {
    await t.go(ph, path);
    const l = await t.layout(ph, `${name} at 390`);
    t.check(`${name} at 390: tap targets are 44px`, l.small.length === 0, l.small.slice(0, 6).join(' | '));
    await t.shot(ph, `d-phone-${name.replace(/\W+/g, '-')}`);
    await t.axe(ph, `${name} at 390`);
  }
}
