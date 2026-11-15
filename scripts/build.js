const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const root = path.resolve(__dirname, '..');
const dist = path.join(root, 'dist');
// Read only the original logo from the supplied archive when assets were not uploaded.
// Never extract executable files, credentials, or arbitrary archive paths.
function originalLogo() {
  const zip = fs.readFileSync(path.join(root, 'lirazb-photo-cloudflare-ready.zip'));
  let end = zip.length - 22;
  while (end >= Math.max(0, zip.length - 65557) && zip.readUInt32LE(end) !== 0x06054b50) end--;
  if (end < 0) throw new Error('Invalid original asset archive');
  let cursor = zip.readUInt32LE(end + 16);
  const count = zip.readUInt16LE(end + 10);
  for (let i = 0; i < count; i++) {
    if (zip.readUInt32LE(cursor) !== 0x02014b50) throw new Error('Invalid archive directory');
    const size = zip.readUInt32LE(cursor + 20);
    const method = zip.readUInt16LE(cursor + 10);
    const nameLength = zip.readUInt16LE(cursor + 28);
    const name = zip.toString('utf8', cursor + 46, cursor + 46 + nameLength);
    if (name === 'lirazb-photo-cloudflare-ready/assets/logo.png') {
      const offset = zip.readUInt32LE(cursor + 42);
      if (zip.readUInt32LE(offset) !== 0x04034b50) throw new Error('Invalid asset record');
      const start = offset + 30 + zip.readUInt16LE(offset + 26) + zip.readUInt16LE(offset + 28);
      const data = zip.subarray(start, start + size);
      if (method === 0) return data;
      if (method === 8) return zlib.inflateRawSync(data);
      throw new Error('Unsupported asset compression');
    }
    cursor += 46 + nameLength + zip.readUInt16LE(cursor + 30) + zip.readUInt16LE(cursor + 32);
  }
  throw new Error('Original logo missing');
}
const site = JSON.parse(fs.readFileSync(path.join(root, 'content/site.json'), 'utf8'));
const galleries = fs.readdirSync(path.join(root, 'content/galleries'))
  .filter(name => name.endsWith('.json'))
  .map(name => JSON.parse(fs.readFileSync(path.join(root, 'content/galleries', name), 'utf8')))
  .filter(gallery => gallery.published !== false)
  .sort((a, b) => (a.order ?? 99) - (b.order ?? 99));
// Deploy only an explicit static allowlist. Legacy PHP and source archives stay out.
fs.rmSync(dist, { recursive: true, force: true });
fs.mkdirSync(dist, { recursive: true });
for (const filename of ['index.html', 'styles.css', 'app.js']) {
  fs.copyFileSync(path.join(root, filename), path.join(dist, filename));
}
for (const folder of ['assets', 'media']) {
  if (fs.existsSync(path.join(root, folder))) fs.cpSync(path.join(root, folder), path.join(dist, folder), { recursive: true });
}
fs.mkdirSync(path.join(dist, 'assets'), { recursive: true });
if (!fs.existsSync(path.join(dist, 'assets/logo.png'))) fs.writeFileSync(path.join(dist, 'assets/logo.png'), originalLogo());
fs.mkdirSync(path.join(dist, 'media'), { recursive: true });
fs.writeFileSync(path.join(dist, 'site-data.json'), JSON.stringify({ site, galleries }, null, 2));
console.log(`Built ${galleries.length} galleries in dist/`);
