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
const fixturePages = Array.from({ length: 10 }, (_, index) => ({
  id: `page-${index + 1}`,
  final_url: index === 9 ? 'https://synthetic-pilot.example.test/page-1?token=should-not-render' : `https://synthetic-pilot.example.test/page-${index + 1}`,
  requested_url: `https://synthetic-pilot.example.test/page-${index + 1}`,
  title: `Synthetic Pilot page ${index + 1}`, http_status: 200, extracted_text: 'Synthetic page evidence.',
}))
const deterministicTechnologies = []
for (const [index, page] of fixturePages.entries()) {
  const makeTech = (name, category, marker = `${name} marker`, confidence = 0.94) => ({
    id: `tech-${name.toLowerCase().replaceAll(/[^a-z0-9]+/g, '-')}-${index}`,
    name, category, website_page_id: page.id, detection_method: 'deterministic-v1', confidence,
    evidence: JSON.stringify({ detector: 'deterministic-v1', source_url: page.final_url, excerpt: marker, marker }),
  })
  deterministicTechnologies.push(makeTech('Shopify', 'ecommerce', 'Shopify platform signature', 0.94))
  deterministicTechnologies.push(makeTech('WhatsApp business contact', 'contact_channel', 'WhatsApp contact link present', 0.95))
  deterministicTechnologies.push(makeTech('Telephone contact link', 'contact_channel', 'Telephone link present', 0.99))
  deterministicTechnologies.push(makeTech('Contact / enquiry CTA', 'contact_navigation', 'Contact CTA present', 0.95))
  deterministicTechnologies.push(makeTech('Contact page link', 'contact_navigation', 'Contact path link present', 0.95))
  deterministicTechnologies.push(makeTech('Responsive viewport metadata', 'responsive', 'Viewport declaration present', 0.99))
  deterministicTechnologies.push(makeTech('Responsive CSS media query', 'responsive', 'Embedded CSS @media present', 0.9))
  for (const [name, count] of [['Document title present', 10], ['Meta description present', 8], ['Canonical link present', 10], ['HTML language present', 10], ['JSON-LD structured data present', 10], ['H1 heading present', 9]]) {
    if (index < count) deterministicTechnologies.push(makeTech(name, 'seo_metadata', name, 0.99))
  }
  deterministicTechnologies.push(makeTech('WooCommerce', 'ecommerce', `Commerce platform ${index}`, 0.93))
}
const deterministicIssues = []
for (const [index, page] of fixturePages.entries()) {
  const issue = (type, summary, severity, confidence = 0.98) => ({
    id: `issue-${type}-${index}`, type, summary, severity, confidence, detector_version: 'deterministic-v1', website_page_id: page.id,
    evidence: JSON.stringify({ detector: 'deterministic-v1', source_url: page.final_url, excerpt: `Evidence for ${type}`, marker: type }),
  })
  deterministicIssues.push(issue('poor_mobile_ux', 'Viewport metadata disables user zoom on the inspected page.', 'medium', 0.99))
  if (index < 2) deterministicIssues.push(issue('seo_missing_meta_description', 'No non-empty meta description was found.', 'low'))
  if (index === 0) deterministicIssues.push(issue('seo_missing_h1', 'No non-empty H1 heading was found.', 'low'))
  deterministicIssues.push(issue('lead_capture_mechanism_present', 'A public contact or enquiry mechanism was observed.', 'low', 0.96))
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
  const mutatingRequests = []
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
      issues: [...deterministicIssues, { id: 'ai-issue-1', type: 'ai_observation', severity: 'low', summary: 'AI-only advisory observation.', confidence: 0.8, evidence: JSON.stringify({ source_url: 'https://synthetic-pilot.example.test', excerpt: 'Synthetic fixture evidence excerpt.' }) }],
      technologies: [...deterministicTechnologies, { id: 'ai-tech-1', name: 'AI identified framework', category: 'observed', detection_method: 'ai_inference', confidence: 0.7 }],
      pages: fixturePages,
      scores: [{ id: 'score-1', score: null, evidence_coverage: 50, rule_version: 1, components: { industry_fit: { points: 5, evidence_reference: { source: 'company_record', field: 'industry', value: 'Manufacturing', criteria: ['manufacturing'], url: 'https://synthetic-pilot.example.test' } } } }],
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
  page.on('request', (request) => {
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method()) && /intelligence|score|campaign|message|outreach/i.test(request.url())) mutatingRequests.push(`${request.method()} ${request.url()}`)
  })
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
      await page.getByText('Prospect overview', { exact: true }).waitFor()
      const scoreEvidence = await page.locator('body').innerText()
      assert.match(scoreEvidence, /Source: company_record · Field: industry · Value: Manufacturing/)
      assert.ok(!scoreEvidence.includes('[object Object]'), 'Structured score evidence renders as concise readable text')
      results.push(await inspect(page, 'Prospect 360', outputDirectory))
      await page.getByRole('button', { name: 'Website', exact: true }).click()
      await page.getByText('Latest scan').waitFor()
      await page.getByText('Executive summary', { exact: true }).waitFor()
      assert.equal(await page.locator('.intelligence-workspace pre').count(), 0, 'Website Intelligence must not render raw JSON')
      const intelligenceUi = page.locator('.intelligence-workspace')
      const rendered = await intelligenceUi.innerText()
      assert.ok(!rendered.includes('[object Object]'), 'No object placeholder is rendered')
      assert.ok(!rendered.includes('{"detector"'), 'Raw evidence JSON is not dumped')
      assert.match(rendered, /Shopify[\s\S]*Detected on 10 \/ 10 inspected pages/)
      assert.equal(await intelligenceUi.getByText('Shopify', { exact: true }).count(), 1, 'Ten Shopify observations aggregate into one group')
      assert.equal(await intelligenceUi.getByText('WooCommerce', { exact: true }).count(), 1, 'Different technology names remain separate')
      assert.match(rendered, /WhatsApp business contact[\s\S]*Detected on 10 \/ 10 inspected pages/)
      assert.match(rendered, /Poor mobile UX[\s\S]*10 \/ 10 inspected pages/)
      assert.match(rendered, /Meta description missing[\s\S]*2 \/ 10 inspected pages/)
      assert.match(rendered, /H1 missing[\s\S]*1 \/ 10 inspected pages/)
      assert.match(rendered, /Lead capture mechanism present[\s\S]*Detected on 10 \/ 10 inspected pages/)
      assert.match(rendered, /Responsive viewport metadata/)
      assert.match(rendered, /Responsive CSS media query/)
      assert.match(rendered, /AI-generated technical observations[\s\S]*AI-only advisory observation/)
      const shopifyCard = intelligenceUi.locator('.evidence-group').filter({ hasText: 'Shopify' }).first()
      assert.match(await shopifyCard.innerText(), /94% confidence/)
      assert.match(await shopifyCard.innerText(), /View evidence sources/)
      await shopifyCard.getByText(/View evidence sources/).click()
      assert.ok(await shopifyCard.locator('.source-list a').count() <= 9, 'Duplicate source URLs are shown once')
      assert.ok((await shopifyCard.innerText()).includes('/page-1'), 'Expanded evidence retains source page URLs')
      assert.ok(!(await shopifyCard.innerText()).includes('token=should-not-render'), 'Query parameters are omitted from displayed URLs')
      const issuesSection = intelligenceUi.locator('.issue-group')
      assert.equal(await issuesSection.filter({ hasText: 'Poor mobile UX' }).count(), 1, 'Repeated issue rows with the same type and severity form one group')
      assert.equal(await issuesSection.filter({ hasText: 'Meta description missing' }).count(), 1, 'Two missing-description rows form one issue group')
      assert.equal(await issuesSection.filter({ hasText: 'H1 missing' }).count(), 1, 'H1 issue remains its own group')
      const leadCaptureCard = intelligenceUi.locator('.evidence-group').filter({ hasText: 'Lead capture mechanism present' }).first()
      const whatsappCard = intelligenceUi.locator('.evidence-group').filter({ hasText: 'WhatsApp business contact' }).first()
      assert.ok(!/gap|missing|poor|problem/i.test(await leadCaptureCard.innerText()), 'Lead-capture presence is presented as a positive capability')
      assert.ok(!/gap|missing|poor|problem/i.test(await whatsappCard.innerText()), 'WhatsApp presence is not labeled as a gap')
      assert.deepEqual(mutatingRequests, [], 'Viewing website evidence has no scoring or workflow side effects')
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
  assert.deepEqual(mutatingRequests, [], 'Acceptance did not trigger scoring, campaign, or outbound mutations')
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
