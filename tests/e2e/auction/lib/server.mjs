// Boots one throw-away copy of the app for one spec: a fresh SQLite file, migrated, with the demo world, behind
// `php artisan serve` on a free port. Nothing here touches your .env database.
import { spawn, spawnSync, execFileSync } from 'node:child_process';
import net from 'node:net';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const HERE = path.dirname(fileURLToPath(import.meta.url));
export const E2E_DIR = path.resolve(HERE, '..');
export const APP_DIR = path.resolve(E2E_DIR, '../../..');
export const OUT_DIR = path.join(E2E_DIR, 'out');

const freePort = () => new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); });
    s.on('error', reject);
});

export async function bootApp(name, extraEnv = {}) {
    const dbDir = path.join(OUT_DIR, 'db');
    fs.mkdirSync(dbDir, { recursive: true });
    const dbFile = path.join(dbDir, `${name}.sqlite`);
    for (const f of [dbFile, `${dbFile}-wal`, `${dbFile}-shm`]) { fs.rmSync(f, { force: true }); }
    fs.writeFileSync(dbFile, '');

    const port = await freePort();
    const url = `http://127.0.0.1:${port}`;
    const env = {
        ...process.env,
        APP_ENV: 'local',
        APP_DEBUG: 'false',
        APP_URL: url,
        LOG_CHANNEL: 'single',
        LOG_LEVEL: 'warning',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: dbFile,
        DB_URL: '',
        SESSION_DRIVER: 'database',
        CACHE_STORE: 'database',
        QUEUE_CONNECTION: 'sync',
        BROADCAST_CONNECTION: 'null',
        AUCTION_REALTIME_ENABLED: 'false',
        AUCTION_POLL_SECONDS: '4',
        BCRYPT_ROUNDS: '4',
        MAIL_MAILER: 'array',
        PULSE_ENABLED: 'false',
        TELESCOPE_ENABLED: 'false',
        NIGHTWATCH_ENABLED: 'false',
        ALLOW_REGISTRATION: 'false',
        NOTIFY_INGEST_URL: '',
        ANALYTICS_INGEST_URL: '',
        PAYSTACK_SECRET_KEY: 'sk_test_e2e_not_a_real_key',
        PAYSTACK_BASE_URL: 'https://api.paystack.co',
        AUCTION_E2E_FAKE_PAYSTACK: '1',
        PHP_CLI_SERVER_WORKERS: '6',
        ...extraEnv,
    };
    const php = (args, opts = {}) => spawnSync('php', ['-d', 'memory_limit=1G', 'artisan', ...args], { cwd: APP_DIR, env, encoding: 'utf8', ...opts });

    let r = php(['migrate', '--force', '--no-interaction']);
    if (r.status !== 0) { throw new Error('migrate failed: ' + r.stdout + r.stderr); }
    execFileSync('sqlite3', [dbFile, 'PRAGMA journal_mode=WAL;']);
    r = php(['auction:demo-seed', '--fresh', '--no-interaction']);
    if (r.status !== 0) { throw new Error('demo-seed failed: ' + r.stdout + r.stderr); }

    // `php -S` with Laravel's own router script (what `artisan serve` runs), started directly so that the whole process group can be
    // stopped: `artisan serve` leaves its PHP server running when it is killed.
    const router = path.join(APP_DIR, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
    const child = spawn('php', ['-d', 'memory_limit=1G', '-S', `127.0.0.1:${port}`, router], { cwd: path.join(APP_DIR, 'public'), env: { ...env, LARAVEL_SAIL: '0' }, stdio: ['ignore', 'pipe', 'pipe'], detached: true });
    const logFile = path.join(OUT_DIR, 'logs', `${name}.server.log`);
    fs.mkdirSync(path.dirname(logFile), { recursive: true });
    const log = fs.createWriteStream(logFile);
    child.stdout.pipe(log); child.stderr.pipe(log);

    for (let i = 0; i < 100; i++) {
        try { const res = await fetch(url + '/up'); if (res.ok) { break; } } catch { /* not yet */ }
        await new Promise((r2) => setTimeout(r2, 150));
        if (i === 99) { try { process.kill(-child.pid, 'SIGTERM'); } catch { /* gone */ } throw new Error('server did not start; see ' + logFile); }
    }

    const sql = (q) => execFileSync('sqlite3', ['-separator', '\t', dbFile, q], { encoding: 'utf8' }).trim();
    const artisan = (...args) => { const res = php(args); return { code: res.status, out: (res.stdout || '') + (res.stderr || '') }; };
    const stop = async () => { try { process.kill(-child.pid, 'SIGTERM'); } catch { /* already gone */ } log.end(); await new Promise((r2) => setTimeout(r2, 200)); };

    return { url, port, dbFile, sql, artisan, stop, env, logFile };
}
