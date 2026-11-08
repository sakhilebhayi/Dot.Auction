// Image and file fixtures, generated on the fly (no binary files in the repo).
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { outDir } from './env.mjs';

const crcTable = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
const crc = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };

/** A valid RGB PNG. `pattern`: 'flat' compresses to almost nothing, 'noise' does not compress (to make a big file). */
export function png(width, height, { rgb = [200, 120, 40], pattern = 'flat' } = {}) {
  const row = width * 3 + 1;
  const raw = Buffer.alloc(row * height);
  for (let y = 0; y < height; y++) {
    raw[y * row] = 0;
    if (pattern === 'noise') crypto.randomFillSync(raw, y * row + 1, width * 3);
    else for (let x = 0; x < width; x++) { const o = y * row + 1 + x * 3; raw[o] = rgb[0]; raw[o + 1] = rgb[1]; raw[o + 2] = rgb[2]; }
  }
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}

export function writeFixtures() {
  const dir = path.join(outDir, 'fixtures');
  fs.mkdirSync(dir, { recursive: true });
  const f = {};
  const put = (name, buf) => { f[name] = path.join(dir, name); fs.writeFileSync(f[name], buf); return f[name]; };
  for (let i = 1; i <= 7; i++) put(`photo-${i}.png`, png(320 + i, 240, { rgb: [30 * i, 90, 255 - 25 * i] }));
  put('notes.txt', 'These are not a photo.\n');
  put('invoice.pdf', Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'));
  put('disguised.jpg', '<?php echo shell_exec($_GET["c"]); ?>\n<html><script>alert(1)</script></html>\n');
  put('truncated.png', png(200, 200).subarray(0, 120));
  put('bigdim.png', png(5000, 5000));                       // ~25 million pixels, tiny file: a decompression bomb
  put('polyglot.png', Buffer.concat([png(64, 64), Buffer.from('<?php system($_GET["c"]); ?>')]));
  put('oversize.png', png(1500, 1100, { pattern: 'noise' })); // ~5 MB, over the 4 MB limit
  return f;
}
