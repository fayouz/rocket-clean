// Generates the PWA icons (public/icon.svg + PNGs) from a tiny vector description, with Node built-ins only:
//   node scripts/pwa-icons.mjs
// Edit GLYPH / COLORS below, then rerun. Shapes are polygons in a 0..1 square; PNGs are supersampled 4×4.
import { mkdirSync, writeFileSync } from 'node:fs'
import { deflateSync } from 'node:zlib'

const APP = 'clean'
const BG = '#059669' // emerald-600, theme colour of the app
// Glyph: a big four-point sparkle and a small one (the "sparkles" icon of the app).
function star(cx, cy, R, r) {
  const pts = []
  for (let i = 0; i < 8; i++) {
    const a = -Math.PI / 2 + i * Math.PI / 4
    const d = i % 2 ? r : R
    pts.push([cx + d * Math.cos(a), cy + d * Math.sin(a)])
  }
  return pts
}
const GLYPH = [
  { fill: '#ffffff', pts: star(0.45, 0.55, 0.3, 0.075) },
  { fill: '#d1fae5', pts: star(0.73, 0.28, 0.12, 0.03) },
]

const hex = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16))
function inside(pts, x, y) {
  let c = false
  for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
    const [xi, yi] = pts[i], [xj, yj] = pts[j]
    if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) c = !c
  }
  return c
}
function inRounded(x, y, rad) {
  const cx = Math.min(Math.max(x, rad), 1 - rad), cy = Math.min(Math.max(y, rad), 1 - rad)
  return (x - cx) ** 2 + (y - cy) ** 2 <= rad * rad
}
// scale: glyph size factor around the centre (maskable icons keep the glyph inside the 80 % safe zone).
function layers(maskable) {
  const s = maskable ? 0.72 : 1
  return GLYPH.map(g => ({ rgb: hex(g.fill), pts: g.pts.map(([x, y]) => [0.5 + (x - 0.5) * s, 0.5 + (y - 0.5) * s]) }))
}
function png(size, maskable) {
  const L = layers(maskable), bg = hex(BG), N = 4
  const raw = Buffer.alloc((size * 4 + 1) * size)
  for (let py = 0; py < size; py++) {
    raw[py * (size * 4 + 1)] = 0
    for (let px = 0; px < size; px++) {
      let r = 0, g = 0, b = 0, a = 0
      for (let sy = 0; sy < N; sy++) for (let sx = 0; sx < N; sx++) {
        const x = (px + (sx + 0.5) / N) / size, y = (py + (sy + 0.5) / N) / size
        if (!maskable && !inRounded(x, y, 0.22)) continue
        let c = bg
        for (const l of L) if (inside(l.pts, x, y)) c = l.rgb
        r += c[0]; g += c[1]; b += c[2]; a += 255
      }
      const n = N * N, o = py * (size * 4 + 1) + 1 + px * 4
      // Premultiplied average → straight alpha.
      raw[o] = a ? Math.round(r * 255 / a) : 0; raw[o + 1] = a ? Math.round(g * 255 / a) : 0
      raw[o + 2] = a ? Math.round(b * 255 / a) : 0; raw[o + 3] = Math.round(a / n)
    }
  }
  const crcT = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; return c >>> 0 })
  const crc = buf => { let c = 0xFFFFFFFF; for (const x of buf) c = crcT[(c ^ x) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0 }
  const chunk = (type, data) => {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length)
    const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td))
    return Buffer.concat([len, td, c])
  }
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 6
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}
function svg() {
  const poly = L => L.map(l => `<polygon fill="rgb(${l.rgb.join(',')})" points="${l.pts.map(p => p.map(v => (v * 512).toFixed(1)).join(',')).join(' ')}"/>`).join('')
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="113" fill="${BG}"/>${poly(layers(false))}</svg>\n`
}

const out = new URL('../public/', import.meta.url)
mkdirSync(new URL('icons/', out), { recursive: true })
writeFileSync(new URL('icon.svg', out), svg())
for (const s of [192, 512]) {
  writeFileSync(new URL(`icons/${APP}-${s}.png`, out), png(s, false))
  writeFileSync(new URL(`icons/${APP}-maskable-${s}.png`, out), png(s, true))
}
// iOS ignores transparency: full-bleed square, iOS rounds it itself.
writeFileSync(new URL('apple-touch-icon.png', out), png(180, true))
console.log('Icons written to public/')
