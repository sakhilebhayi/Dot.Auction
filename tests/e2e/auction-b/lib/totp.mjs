// RFC 6238 TOTP (SHA-1, 6 digits, 30 s) for the two-factor flow.
import crypto from 'node:crypto';
const ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
function b32(s) { let bits = ''; for (const c of s.replace(/=+$/, '').toUpperCase().replace(/\s/g, '')) bits += ALPHA.indexOf(c).toString(2).padStart(5, '0'); const bytes = []; for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2)); return Buffer.from(bytes); }
export function totp(secret, t = Date.now()) {
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(t / 30000)));
  const h = crypto.createHmac('sha1', b32(secret)).update(counter).digest();
  const o = h[h.length - 1] & 0xf;
  const code = ((h[o] & 0x7f) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1e6;
  return String(code).padStart(6, '0');
}
