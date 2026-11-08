// Boots one app instance per spec on a free port against its own SQLite file, and runs artisan commands against it.
import { spawn, spawnSync } from 'node:child_process';
import net from 'node:net';
import fs from 'node:fs';
import path from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import { repo, outDir, appEnv } from './env.mjs';

export function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); });
    s.on('error', reject);
  });
}

export class AppInstance {
  /**
   * seed: 'demo' (migrate + auction:demo-seed --fresh) or 'bare' (migrate only).
   * appEnvName: 'local' (dev login, fake InfoDot) or 'production' (host pinning, prod error pages).
   * appUrlHost: the host name in APP_URL (default 127.0.0.1); the server still listens on 127.0.0.1.
   */
  constructor(name, { seed = 'demo', appEnvName = 'local', appUrlHost = '127.0.0.1', extra = {} } = {}) {
    this.name = name; this.seed = seed; this.appEnvName = appEnvName; this.appUrlHost = appUrlHost; this.extra = extra;
    this.dbPath = path.join(outDir, `${name}.sqlite`);
    this.logPath = path.join(outDir, `${name}.server.log`);
    this.proc = null; this.port = null;
  }

  env() {
    return appEnv({ db: this.dbPath, port: this.port, host: this.appUrlHost, appEnv: this.appEnvName, extra: this.extra });
  }

  /** Run an artisan command; returns { status, stdout, stderr }. */
  artisan(args, { allowFail = false } = {}) {
    const r = spawnSync('php', ['-d', 'memory_limit=1G', 'artisan', ...args, '--no-interaction'], { cwd: repo, env: this.env(), encoding: 'utf8', timeout: 180000 });
    if (r.status !== 0 && !allowFail) throw new Error(`artisan ${args.join(' ')} failed (${r.status}): ${r.stderr || r.stdout}`);
    return { status: r.status, stdout: r.stdout, stderr: r.stderr };
  }

  async start() {
    this.port = await freePort();
    fs.mkdirSync(outDir, { recursive: true });
    for (const f of [this.dbPath, `${this.dbPath}-wal`, `${this.dbPath}-shm`]) fs.rmSync(f, { force: true });
    fs.writeFileSync(this.dbPath, '');
    this.artisan(['migrate', '--force']);
    if (this.seed === 'demo') this.artisan(['auction:demo-seed', '--fresh', '--payments'], { allowFail: false });
    const router = path.join(repo, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
    const log = fs.openSync(this.logPath, 'w');
    // `php artisan serve` strips every variable but a short allow-list from the child, which would lose the per-spec
    // database; this is the same `php -S ... server.php` command it runs, with the environment kept.
    this.proc = spawn('php', ['-d', 'memory_limit=1G', '-S', `127.0.0.1:${this.port}`, '-t', path.join(repo, 'public'), router], {
      cwd: path.join(repo, 'public'), env: { ...this.env(), LARAVEL_SERVER: '1' }, stdio: ['ignore', log, log],
    });
    for (let i = 0; i < 100; i++) {
      try { const r = await fetch(`${this.base}/up`); if (r.ok) break; } catch { /* not yet */ }
      await new Promise((r) => setTimeout(r, 150));
    }
    // sqlite handle for assertions and for moving time; wait on a busy writer instead of failing.
    this.db = new DatabaseSync(this.dbPath);
    this.db.exec('PRAGMA busy_timeout = 15000');
    return this;
  }

  get base() { return `http://127.0.0.1:${this.port}`; }

  /** The server's own log output so far (PHP -S access lines plus the stderr log channel). */
  serverLog() { try { return fs.readFileSync(this.logPath, 'utf8'); } catch { return ''; } }

  async stop() {
    try { this.db?.close(); } catch { /* ignore */ }
    if (this.proc) { this.proc.kill('SIGTERM'); await new Promise((r) => setTimeout(r, 200)); }
  }

  q(sql, ...args) { return this.db.prepare(sql).all(...args); }
  one(sql, ...args) { return this.db.prepare(sql).get(...args) ?? null; }
  run(sql, ...args) { return this.db.prepare(sql).run(...args); }

  userByKey(key) {
    const map = { thandi: 'thandi.mokoena', pieter: 'pieter.merwe', lerato: 'lerato.dlamini', sipho: 'sipho.nkosi', aisha: 'aisha.patel', johan: 'johan.botha', naledi: 'naledi.khumalo', zanele: 'zanele.mthembu' };
    return this.one('select * from users where email = ?', `${map[key]}@demo.auction.test`);
  }
  lot(title) { return this.one('select * from auctions where title = ?', title); }
  /** UTC "Y-m-d H:i:s" at an offset in minutes from now, the way Laravel stores timestamps. */
  at(minutes) { return new Date(Date.now() + minutes * 60000).toISOString().replace('T', ' ').slice(0, 19); }
  /** Move a lot's window (minutes from now). The real scheduler commands are then run, never a shortcut. */
  moveLot(id, { starts, ends }) {
    if (starts !== undefined) this.run('update auctions set starts_at = ? where id = ?', this.at(starts), id);
    if (ends !== undefined) this.run('update auctions set ends_at = ? where id = ?', this.at(ends), id);
  }
}
