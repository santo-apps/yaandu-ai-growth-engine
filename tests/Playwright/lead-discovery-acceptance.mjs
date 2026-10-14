import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { setTimeout as delay } from 'node:timers/promises'
import { chromium } from 'playwright'

const baseUrl = 'http://127.0.0.1:5181'
const tenantId = '71000000-0000-4000-8000-000000000001'
const sourceId = '72000000-0000-4000-8000-000000000001'
const campaignId = '73000000-0000-4000-8000-000000000001'
const candidateId = '74000000-0000-4000-8000-000000000001'
let campaign = null
let source = null
let started = false

const server = spawn('npm', ['run', 'dev', '--', '--host', '127.0.0.1', '--port', '5181', '--strictPort'], { stdio: 'ignore', env: { ...process.env, BROWSER: 'none' } })
let browser
try {
  let ready = false
  for (let attempt = 0; attempt < 50; attempt++) {
    try { if ((await fetch(baseUrl)).ok) { ready = true; break } } catch {}
    await delay(200)
  }
  assert.ok(ready, 'Vite server must start')
  browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] })
  const page = await browser.newPage({ viewport: { width: 1440, height: 950 } })
  await page.addInitScript((id) => localStorage.setItem('tenant_id', id), tenantId)
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '')
    const method = route.request().method()
    let status = 200
    let body = { data: [] }
    if (path === '/me') body = { id: 1, name: 'Synthetic Owner', email: 'owner@example.test' }
    else if (path === '/tenants') body = [{ id: tenantId, name: 'Synthetic Pilot Tenant', role: 'owner', status: 'active' }]
    else if (path === '/companies' || path.startsWith('/companies?')) body = { data: [] }
    else if (path === '/lead-discovery/source-health') body = { live_enabled: true, credential_status: 'available', credential_detected: true, authorization_status: source ? 'approved' : 'not_approved', storage_rights_status: source ? 'confirmed' : 'not_confirmed', health_status: source ? 'ready' : 'blocked', storage_rights_required: true, status: source ? 'ready' : 'STORAGE_RIGHTS_NOT_CONFIRMED', sources: [] }
    else if (path === '/lead-discovery/sources' && method === 'GET') body = { data: source ? [source] : [] }
    else if (path === '/lead-discovery/sources' && method === 'POST') {
      source = { id: sourceId, source_key: 'brave_search', provider_name: 'Brave Search API', source_type: 'licensed_search_api', authorization_status: 'authorized',
        allowed_collection_method: 'documented_search_api', authorization_summary: 'Synthetic reviewed plan', enabled: true, health_state: 'ready',
        credential_status: 'available', storage_rights_status: 'confirmed', storage_rights_confirmed: true, execution_ready: true, readiness_reason_code: null }
      status = 201; body = source
    }
    else if (path === '/lead-discovery/campaigns' && method === 'GET') body = { data: campaign ? [campaign] : [] }
    else if (path === '/lead-discovery/campaigns' && method === 'POST') {
      campaign = { id: campaignId, name: 'Synthetic India Retail Search', industry: 'E-commerce & Retail', country_code: 'IN', target_leads: 25, status: 'draft', auto_promotion_enabled: false }
      status = 201; body = campaign
    }
    else if (path === `/lead-discovery/campaigns/${campaignId}/start` && method === 'POST') {
      started = true; campaign = { ...campaign, status: 'running' }; status = 202; body = { campaign_id: campaignId, status: 'queued', auto_promotion: false }
    }
    else if (path === `/lead-discovery/campaigns/${campaignId}`) body = { campaign, counts: { discovered: started ? 1 : 0, verified: 0, eligible: 0, needs_review: 1, rejected: 0, duplicates: 0, invalid: 0, promoted: 0, processing_errors: 0 }, contacts_enriched: 0, source_errors: 0,
      runs: started ? [{ source_requests: 1, raw_candidates: 1 }] : [], checkpoints: started ? [{ id: 'checkpoint', query_key: 'online-retailer', status: 'completed' }] : [] }
    else if (path === '/lead-discovery/candidates') body = { data: started ? [{ id: candidateId, source_id: sourceId, campaign_id: campaignId, company_name: 'Synthetic Retail Company', normalized_domain: 'synthetic-retail.example.com', industry: null, country_code: null, city: null, eligibility_status: 'eligible', official_domain_status: 'verified', promotion_status: 'not_promoted', processing_status: 'eligible', raw_metadata: { query: 'online retailer Chennai Tamil Nadu IN', rank: 1 } }] : [] }
    else if (path === `/lead-discovery/candidates/${candidateId}`) body = { candidate: { id: candidateId, company_name: 'Synthetic Retail Company', claimed_website: 'https://synthetic-retail.example.com/', official_domain_status: 'verified', eligibility_status: 'eligible', validation_reason: 'Synthetic website match', validation_reason_code: 'VERIFIED_TARGET_MATCH', promotion_status: 'not_promoted' }, campaign, source, evidence: [{ id: 'ev1', field_name: 'company_name', evidence_type: 'live_search_result', normalized_value: 'Synthetic Retail Company', confidence_score: 0.5, collected_at: '2026-10-14T00:00:00Z', source_url: 'https://synthetic-retail.example.com/', attribution: '{}' }], matched_company: null }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  })
  await page.goto(baseUrl, { waitUntil: 'networkidle' })
  await page.getByText('Synthetic Pilot Tenant').waitFor()
  await page.getByRole('button', { name: /Prospects/ }).first().click()
  await page.getByRole('button', { name: 'Lead Discovery' }).click()
  await page.getByRole('heading', { name: 'Lead Discovery', exact: true }).waitFor()
  await page.getByRole('heading', { name: 'Live source authorization' }).waitFor()
  await page.getByText('Credential available', { exact: true }).waitFor()
  await page.getByText('Storage rights not_confirmed', { exact: true }).waitFor()
  await page.getByLabel('Plan / permission basis').fill('Synthetic plan review')
  await page.getByLabel('Storage-rights agreement reference').fill('Synthetic order form')
  await page.getByLabel('I confirmed the active provider agreement explicitly permits storing search-result data for this workflow').check()
  await page.getByLabel('Enable after permission is documented').check()
  await page.getByRole('button', { name: 'Save source configuration' }).click()
  await page.getByText('Live source is fully approved.', { exact: false }).waitFor()
  await page.getByRole('button', { name: 'Create campaign' }).click()
  await page.getByText('Campaign created.', { exact: false }).waitFor()
  await page.getByRole('button', { name: 'Start Discovery' }).click()
  await page.getByText('Discovery queued.', { exact: false }).waitFor()
  await page.locator('.campaign-progress').getByText('Source requests 1').waitFor()
  await page.getByText('Synthetic Retail Company', { exact: true }).waitFor()
  await page.getByRole('button', { name: 'Synthetic Retail Company' }).click()
  await page.getByRole('heading', { name: 'Synthetic Retail Company' }).waitFor()
  await page.getByRole('button', { name: 'Promote to Prospect' }).waitFor()
  await page.getByRole('button', { name: 'Close', exact: true }).click()
  await page.getByText('Manual / advanced import · CSV fallback').click()
  await page.getByText('Import a licensed CSV').waitFor()
  for (const width of [390, 768, 1280, 1440]) {
    await page.setViewportSize({ width, height: 900 })
    await page.waitForTimeout(100)
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
    assert.equal(overflow, false, `No page-level horizontal overflow at ${width}px`)
    assert.equal(await page.locator('.lead-discovery').count(), 1, `Lead Discovery stays visible at ${width}px`)
  }
  console.log('PASS: mocked Lead Discovery configure → create → start → progress → candidate detail → manual promotion affordance; CSV advanced fallback; responsive 390/768/1280/1440.')
} finally {
  await browser?.close()
  server.kill('SIGTERM')
}
