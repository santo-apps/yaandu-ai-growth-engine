import { chromium } from 'playwright'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const [expectedHostArg, pinnedIp, outputDir] = process.argv.slice(2)
const target = readFileSync(0, 'utf8').trim()
const normalizeHost = (value) => value.toLowerCase().replace(/^\[|\]$/g, '')
const expectedHost = normalizeHost(expectedHostArg)
const initialUrl = new URL(target)

if (!['http:', 'https:'].includes(initialUrl.protocol) || normalizeHost(initialUrl.hostname) !== expectedHost || initialUrl.username || initialUrl.password) {
  throw new Error('Unsafe browser capture URL.')
}

const address = pinnedIp.includes(':') ? `[${pinnedIp}]` : pinnedIp
const browser = await chromium.launch({
  headless: true,
  args: [
    `--host-resolver-rules=MAP ${expectedHost} ${address}`,
    '--disable-background-networking',
    '--disable-extensions',
    '--disable-sync',
  ],
})

try {
  const context = await browser.newContext({
    viewport: { width: 1365, height: 900 },
    serviceWorkers: 'block',
    acceptDownloads: false,
  })
  await context.route('**/*', async (route) => {
    let requested
    try { requested = new URL(route.request().url()) } catch { await route.abort(); return }
    const sameHost = normalizeHost(requested.hostname) === expectedHost
    const validProtocol = ['http:', 'https:'].includes(requested.protocol)
    const validPort = !requested.port || ['80', '443'].includes(requested.port)
    const noDowngrade = initialUrl.protocol !== 'https:' || requested.protocol === 'https:'
    if (!sameHost || !validProtocol || !validPort || !noDowngrade || requested.username || requested.password) {
      await route.abort('blockedbyclient')
      return
    }
    await route.continue()
  })
  if (typeof context.routeWebSocket === 'function') {
    await context.routeWebSocket('**/*', (socket) => socket.close({ code: 1008, reason: 'WebSockets are disabled during capture.' }))
  }

  const page = await context.newPage()
  await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 20000 })
  const html = await page.locator('html').evaluate((element) => element.outerHTML)
  if (Buffer.byteLength(html, 'utf8') > 5_000_000) throw new Error('Rendered document exceeded the capture limit.')
  writeFileSync(join(outputDir, 'page.html'), html, { encoding: 'utf8', mode: 0o600 })
  await page.screenshot({ path: join(outputDir, 'desktop.png'), type: 'png', fullPage: false, timeout: 10000 })
  await context.close()
} finally {
  await browser.close()
}
