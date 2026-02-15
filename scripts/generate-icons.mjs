#!/usr/bin/env node
import { readFile, writeFile, copyFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

const __dirname = dirname(fileURLToPath(import.meta.url))
const root = join(__dirname, '..')
const svgPath = join(root, 'public', 'logo.svg')
const publicDir = join(root, 'public')

const svg = await readFile(svgPath)

// ── Helper: wrap a PNG buffer in a minimal ICO container ──
function pngToIco(pngBuf, width, height) {
  const dirEntry = Buffer.alloc(16)
  dirEntry.writeUInt8(width < 256 ? width : 0, 0)
  dirEntry.writeUInt8(height < 256 ? height : 0, 1)
  dirEntry.writeUInt8(0, 2) // color palette
  dirEntry.writeUInt8(0, 3) // reserved
  dirEntry.writeUInt16LE(1, 4) // color planes
  dirEntry.writeUInt16LE(32, 6) // bits per pixel
  dirEntry.writeUInt32LE(pngBuf.length, 8) // image size
  dirEntry.writeUInt32LE(6 + 16, 12) // offset to image data

  const header = Buffer.alloc(6)
  header.writeUInt16LE(0, 0) // reserved
  header.writeUInt16LE(1, 2) // ICO type
  header.writeUInt16LE(1, 4) // number of images

  return Buffer.concat([header, dirEntry, pngBuf])
}

// ── Generate PNGs at various sizes ──
async function generatePng(size, outputPath) {
  const buf = await sharp(svg, { density: Math.round((size / 475) * 72 * 2) })
    .resize(size, size, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png()
    .toBuffer()
  await writeFile(outputPath, buf)
  console.log(`  ✓ ${outputPath} (${size}x${size})`)
  return buf
}

// ── Generate OG image (1200x630, navy bg, logo centered top, text below) ──
async function generateOgImage(outputPath) {
  const logoSize = 280
  const logoBuf = await sharp(svg, { density: Math.round((logoSize / 475) * 72 * 2) })
    .resize(logoSize, logoSize, { fit: 'contain', background: { r: 33, g: 52, b: 99, alpha: 1 } })
    .png()
    .toBuffer()

  const textSvg = `<svg width="1200" height="630">
    <text x="600" y="460" text-anchor="middle"
          font-family="Georgia, 'Times New Roman', serif"
          font-size="48" font-weight="bold" fill="white">
      Catholic Digital Commons Foundation
    </text>
  </svg>`

  await sharp({
    create: {
      width: 1200,
      height: 630,
      channels: 4,
      background: { r: 33, g: 52, b: 99, alpha: 1 }, // #213463
    },
  })
    .composite([
      {
        input: logoBuf,
        top: 80,
        left: Math.round((1200 - logoSize) / 2),
      },
      {
        input: Buffer.from(textSvg),
        top: 0,
        left: 0,
      },
    ])
    .png()
    .toFile(outputPath)

  console.log(`  ✓ ${outputPath} (1200x630)`)
}

// ── Main ──
console.log('Generating icons from public/logo.svg...\n')

// 1. Copy SVG → public/icon.svg
await copyFile(svgPath, join(publicDir, 'icon.svg'))
console.log('  ✓ public/icon.svg')

// 2. favicon.ico (32x32 PNG wrapped in ICO)
const png32 = await generatePng(32, join(root, '_tmp_32.png'))
const icoBuf = pngToIco(png32, 32, 32)
await writeFile(join(publicDir, 'favicon.ico'), icoBuf)
const { unlink } = await import('node:fs/promises')
await unlink(join(root, '_tmp_32.png'))
console.log('  ✓ public/favicon.ico (32x32)')

// 3. apple-icon.png (180x180)
await generatePng(180, join(publicDir, 'apple-icon.png'))

// 4. PWA icons
await generatePng(192, join(publicDir, 'icon-192.png'))
await generatePng(512, join(publicDir, 'icon-512.png'))

// 5. OG image
await generateOgImage(join(publicDir, 'og-image.png'))

console.log('\nDone!')
