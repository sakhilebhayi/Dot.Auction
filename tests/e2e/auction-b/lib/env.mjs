// Paths and the environment every spec's server and artisan commands run with.
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const here = path.dirname(fileURLToPath(import.meta.url));
export const pkgDir = path.resolve(here, '..');
export const repo = path.resolve(pkgDir, '../../..');
export const outDir = path.join(pkgDir, 'out');
export const CHROME = process.env.E2E_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

/** Environment for the app under test. Process env beats .env (Dotenv never overwrites), so the repo's .env stays untouched. */
export function appEnv({ db, port, host = '127.0.0.1', appEnv = 'local', extra = {} }) {
  const e = {
    ...process.env,
    APP_ENV: appEnv,
    APP_DEBUG: appEnv === 'local' ? 'true' : 'false',
    APP_URL: `http://${host}:${port}`,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: db,
    SESSION_DRIVER: 'database',
    SESSION_DOMAIN: 'null',
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'null',
    MAIL_MAILER: 'log',
    LOG_CHANNEL: 'stderr',
    LOG_LEVEL: 'debug',
    AUCTION_REALTIME_ENABLED: 'false',
    AUCTION_POLL_SECONDS: '4',
    AUCTION_E2E_FAKE_INFODOT: '1',
    AUCTION_E2E_FAKE_PAYSTACK: '1',
    INFODOT_REDEEM_URL: 'https://infodot.test/api/ecosystem/redeem',
    INFODOT_SERVICE_SECRET: 'e2e-not-a-real-secret',
    PAYSTACK_SECRET_KEY: 'sk_test_e2e_not_real',
    PHP_CLI_SERVER_WORKERS: '1',
    CSP_REPORT: '1',
    ...extra,
  };
  return e;
}
