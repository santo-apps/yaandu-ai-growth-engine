import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { chromium } from 'playwright'

const server = createServer((request, response) => {
  if (request.url === '/slow') return setTimeout(() => { response.writeHead(200); response.end('<html>late</html>') }, 700)
  if (request.url === '/blocked.png') { response.writeHead(200); response.end('should never be requested'); return }
  response.writeHead(200, { 'content-type': 'text/html' })
  response.end('<!doctype html><html><body><h1>Yaandu local capture fixture</h1><img src="http://192.0.2.1/blocked.png"></body></html>')
})

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
const address = server.address()
const baseUrl = `http://127.0.0.1:${address.port}`
const directory = await mkdtemp(join(tmpdir(), 'yaandu-playwright-'))
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] })

try {
  const context = await browser.newContext()
  const observed = { allowed: 0, blocked: 0 }
  await context.route('**/*', async (route) => {
    const target = new URL(route.request().url())
    if (target.origin === baseUrl) { observed.allowed++; await route.continue(); return }
    observed.blocked++
    await route.abort('blockedbyclient')
  })
  const page = await context.newPage()
  await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 3000 })
  await page.evaluate(() => localStorage.setItem('fixture', 'stored'))
  await page.screenshot({ path: join(directory, 'fixture.png') })
  await context.storageState({ path: join(directory, 'storage-state.json') })
  assert.ok(observed.allowed > 0, 'same-origin local fixture should be allowed')
  assert.ok(observed.blocked > 0, 'non-local fixture asset should be blocked before network access')
  assert.ok((await stat(join(directory, 'fixture.png'))).size > 0, 'screenshot should be written')
  assert.match(await readFile(join(directory, 'storage-state.json'), 'utf8'), /127\.0\.0\.1/)

  await assert.rejects(page.goto(`${baseUrl}/slow`, { waitUntil: 'load', timeout: 100 }), /Timeout/)
  console.log(JSON.stringify({ chromium: 'launched', navigation: 'passed', screenshot: 'stored', interception: observed, timeout: 'passed', storage: 'stored' }))
} finally {
  await browser.close()
  server.close()
  await rm(directory, { recursive: true, force: true })
}
