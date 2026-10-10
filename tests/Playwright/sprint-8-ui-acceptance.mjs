import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import { chromium } from 'playwright'

const baseUrl = 'http://127.0.0.1:5179'
const tenantId = '10000000-0000-4000-8000-000000000001'
const companyId = '20000000-0000-4000-8000-000000000001'
const fixtureCompany = {
  id: companyId,
  name: 'Synthetic Pilot Company',
  industry: 'Manufacturing',
  location: 'Pune, India',
  normalized_domain: 'synthetic-pilot.example.test',
  status: 'new',
  source: 'synthetic_ui_fixture',
  websites: [{ id: '30000000-0000-4000-8000-000000000001', url: 'https://synthetic-pilot.example.test', verification_status: 'verified' }],
  import_provenance: null,
}

const server = spawn('npm', ['run', 'dev', '--', '--host', '127.0.0.1', '--port', '5179', '--strictPort'], {
  stdio: 'ignore',
  env: { ...process.env, BROWSER: 'none' },
})
const outputDirectory = await mkdtemp(join(tmpdir(), 'yaandu-sprint8-ui-'))
let browser

try {
  let serverReady = false
  for (let attempt = 0; attempt < 50; attempt++) {
    try {
      const response = await fetch(baseUrl)
      if (response.ok) { serverReady = true; break }
    } catch {}
    await delay(200)
  }
  assert.ok(serverReady, 'Vite development server did not become ready')

  browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] })
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })
  await page.addInitScript((id) => localStorage.setItem('tenant_id', id), tenantId)
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '')
    let body = { data: [] }
    if (path === '/me') body = { id: 1, name: 'Synthetic Reviewer', email: 'reviewer@example.test' }
    else if (path === '/tenants') body = [{ id: tenantId, name: 'Synthetic Pilot Workspace', role: 'owner', status: 'active' }]
    else if (path === '/companies' || path.startsWith('/companies?')) body = { data: [fixtureCompany] }
    else if (path === `/companies/${companyId}`) body = fixtureCompany
    else if (path === `/companies/${companyId}/intelligence`) body = {
      latest_scan: { status: 'completed', created_at: '2026-01-01T00:00:00Z' },
      issues: [{ id: 'issue-1', type: 'mobile_ux', severity: 'medium', summary: 'Navigation may be difficult on small screens.', confidence: 0.8, evidence: JSON.stringify({ source_url: 'https://synthetic-pilot.example.test', excerpt: 'Synthetic fixture evidence excerpt.' }) }],
      technologies: [{ id: 'tech-1', name: 'Synthetic CMS' }],
      pages: [{ id: 'page-1', final_url: 'https://synthetic-pilot.example.test', requested_url: 'https://synthetic-pilot.example.test', title: 'Synthetic Pilot', http_status: 200, extracted_text: 'Synthetic page evidence.' }],
      scores: [{ id: 'score-1', score: null, evidence_coverage: 50, rule_version: 1, components: { industry_fit: { points: 5, evidence: 'Synthetic business profile' } } }],
      intelligence_results: [{ structured_output: { business_identity: { description: 'Synthetic company summary.' }, observations: [{ statement: 'The site lists a contact page.', evidence_id: 'page-1', confidence: 0.9 }], opportunities: [], technical_findings: [], unknowns: ['CRM use is unknown.'], evidence: [{ evidence_id: 'page-1', source_url: 'https://synthetic-pilot.example.test', excerpt: 'Synthetic page evidence.' }] } }],
      screenshots: [],
    }
    else if (path === '/pilot/cohorts') body = { data: [] }
    else if (path.startsWith('/pilot/dashboard')) body = { cohort: null, simulated: true, sales_intelligence_mode: 'human_assisted', metrics: {}, funnel: {}, rates_percent: {} }
    else if (path === '/pilot/sales-intelligence-mode') body = { sales_intelligence_mode: 'human_assisted' }
    else if (path === '/local-acceptance/status') body = { mode: 'disabled', providers: { ai: 'Synthetic AI', outbound: 'Disabled', scheduling: 'Disabled' }, queue: 'isolated', company_id: null, campaign_id: null, campaign_status: 'disabled', message: null, inbound_count: 0, conversation_id: null, opportunity_id: null, meeting_id: null, proposal_id: null, proposal_delivery_count: 0 }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
  })

  const pageErrors = []
  page.on('pageerror', (error) => pageErrors.push(`${error.message} ${error.stack ?? ''}`))
  await page.goto(baseUrl, { waitUntil: 'networkidle' })
  await page.getByText('Synthetic Pilot Workspace').waitFor({ timeout: 5000 })

  const screens = [
    ['Home', 'Home'],
    ['Prospects', 'Prospects'],
  ]
  const results = []
  for (const [label, navLabel] of screens) {
    if (navLabel !== 'Home') await page.getByRole('button', { name: new RegExp(navLabel) }).first().click()
    await page.locator('h1').first().waitFor()
    const heading = await page.locator('h1').first().innerText()
    assert.ok(heading.length > 0, `${label} page heading is visible`)
    if (label === 'Prospects') {
      await page.getByText('Synthetic Pilot Company', { exact: true }).first().click()
      await page.locator('h1').getByText('Synthetic Pilot Company').waitFor()
      results.push(await inspect(page, 'Prospect 360', outputDirectory))
      await page.getByRole('button', { name: 'Website', exact: true }).click()
      await page.getByText('Latest scan').waitFor()
      await page.getByText('Executive summary', { exact: true }).waitFor()
      assert.equal(await page.locator('.intelligence-workspace pre').count(), 0, 'Website Intelligence must not render raw JSON')
      assert.ok(!(await page.locator('.intelligence-workspace').innerText()).includes('{\"'), 'Website Intelligence content is human-readable')
      results.push(await inspect(page, 'Website Intelligence', outputDirectory))
    }
    results.push(await inspect(page, label, outputDirectory))
  }

  for (const label of ['Inbox', 'Pipeline', 'Meetings']) {
    await page.getByRole('button', { name: new RegExp(label) }).first().click()
    await page.locator('h1').first().waitFor()
    results.push(await inspect(page, label, outputDirectory))
  }

  assert.deepEqual(pageErrors, [], `No browser runtime exceptions: ${pageErrors.join('; ')}`)
  console.log(JSON.stringify({ browser: 'Chromium', data: 'synthetic only', screens: results }, null, 2))
} finally {
  await browser?.close()
  server.kill('SIGTERM')
  await rm(outputDirectory, { recursive: true, force: true })
}

async function inspect(page, label, directory) {
  const widths = [1440, 1280, 768, 390]
  const row = { screen: label, widths: {} }
  for (const width of widths) {
    await page.setViewportSize({ width, height: 1000 })
    await page.waitForTimeout(100)
    const metrics = await page.evaluate(() => ({
      viewport: window.innerWidth,
      document: document.documentElement.scrollWidth,
      body: document.body.scrollWidth,
      headingVisible: Boolean(document.querySelector('h1')?.getBoundingClientRect().width),
      overflowing: [...document.querySelectorAll('body *')].map((element) => ({
        selector: `${element.tagName.toLowerCase()}.${String(element.className?.baseVal ?? element.className ?? '').trim().split(/\s+/).filter(Boolean).join('.')}`,
        right: Math.round(element.getBoundingClientRect().right),
        width: Math.round(element.getBoundingClientRect().width),
      })).filter((element) => element.right > window.innerWidth + 1).slice(0, 8),
    }))
    assert.ok(metrics.headingVisible, `${label} heading is visible at ${width}px`)
    assert.ok(metrics.document <= width + 1, `${label} has no page overflow at ${width}px (scrollWidth ${metrics.document}; offenders ${JSON.stringify(metrics.overflowing)})`)
    row.widths[width] = 'PASS'
    if (width === 1440) await page.screenshot({ path: join(directory, `${label.toLowerCase().replaceAll(' ', '-')}.png`), fullPage: true })
  }
  return row
}
