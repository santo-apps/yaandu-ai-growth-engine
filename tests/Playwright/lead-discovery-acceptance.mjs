import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { setTimeout as delay } from 'node:timers/promises'
import { chromium } from 'playwright'

const baseUrl = 'http://127.0.0.1:5181'
const tenantId = '71000000-0000-4000-8000-000000000001'
const campaignId = '73000000-0000-4000-8000-000000000001'
const candidateId = '74000000-0000-4000-8000-000000000001'
const sourceRows = [
  ['osm_overpass', 'OpenStreetMap / Overpass', 'OPEN_PERSISTABLE', true, true, [], '© OpenStreetMap contributors'],
  ['wikidata', 'Wikidata', 'OPEN_PERSISTABLE', true, true, [], null],
  ['india_ogd_mca', 'India OGD / MCA', 'OPEN_PERSISTABLE', false, true, ['IN'], 'Government dataset attribution'],
  ['gleif', 'GLEIF', 'OPEN_PERSISTABLE', true, true, [], null],
  ['brave_search', 'Brave Search', 'CONDITIONAL', false, true, [], null],
].map(([key, name, type, global, persistent, countries, attribution], index) => ({
  id: `72000000-0000-4000-8000-${String(index + 1).padStart(12, '0')}`, source_key: key, provider_name: name,
  source_type: type, authorization_status: 'authorized', allowed_collection_method: 'official_public_api',
  authorization_summary: 'Synthetic reviewed source terms', enabled: true, health_state: 'ready', credential_status: key === 'brave_search' ? 'missing' : 'not_required',
  configuration_status: 'available', storage_rights_status: 'confirmed', storage_rights_confirmed: true, execution_ready: true, readiness_reason_code: null,
}))
const capabilities = [
  ...sourceRows.map((source) => ({ source_key: source.source_key, display_name: source.provider_name, source_type: source.source_type,
    supports_live_query: source.source_key !== 'brave_search' || true, global_coverage: ['osm_overpass', 'wikidata', 'gleif'].includes(source.source_key),
    supported_countries: source.source_key === 'india_ogd_mca' ? ['IN'] : [], persistent_storage_allowed: true,
    license_name: source.source_key === 'osm_overpass' ? 'Open Database License (ODbL)' : source.source_key === 'brave_search' ? 'Brave Search agreement' : 'CC0 / open dataset terms',
    license_url: null, attribution_required: source.source_key === 'osm_overpass', attribution_text: source.source_key === 'osm_overpass' ? '© OpenStreetMap contributors' : null,
    governance_class: source.source_type, selectable: true, configured: true, tenant_enabled: true,
    credential_status: source.credential_status, configuration_status: 'available', authorization_status: 'authorized', storage_rights_status: 'confirmed', health_status: 'ready',
  })),
  { source_key: 'csv_upload', display_name: 'Licensed CSV', selectable: true, source_type: 'LICENSED_PERSISTABLE', supports_live_query: false,
    global_coverage: false, supported_countries: [], persistent_storage_allowed: true, license_name: 'Operator-supplied dataset license', governance_class: 'LICENSED_PERSISTABLE' },
  { source_key: 'google_maps', display_name: 'Google Maps', selectable: false, source_type: 'prohibited_bulk_source', supports_live_query: false,
    global_coverage: false, supported_countries: [], persistent_storage_allowed: false, license_name: 'Google Maps Platform terms', governance_class: 'PROHIBITED_FOR_BULK_STORAGE', restriction_reason: 'Not available for bulk persistent discovery.' },
]
let campaign = null
let started = false
let campaignCreatePayload = null
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
    let status = 200
    let body = { data: [] }
    if (path === '/me') body = { id: 1, name: 'Synthetic Owner', email: 'owner@example.test' }
    else if (path === '/tenants') body = [{ id: tenantId, name: 'Synthetic Pilot Tenant', role: 'owner', status: 'active' }]
    else if (path === '/companies' || path.startsWith('/companies?')) body = { data: [] }
    else if (path === '/lead-discovery/source-health') body = { live_enabled: true, source_states: sourceRows.map((source) => ({ source_key: source.source_key, credential_status: source.credential_status, authorization_status: 'authorized', storage_rights_status: 'confirmed', health_status: 'ready', execution_ready: true })) }
    else if (path === '/lead-discovery/source-catalog') body = { data: capabilities }
    else if (path === '/lead-discovery/industries') body = { data: [{ key: 'healthcare', label: 'Healthcare' }, { key: 'ecommerce_retail', label: 'E-commerce & Retail' }, { key: 'it_software', label: 'IT & Software' }] }
    else if (path === '/lead-discovery/countries') body = { data: [{ code: 'IN', label: 'India' }, { code: 'AE', label: 'United Arab Emirates' }, { code: 'SA', label: 'Saudi Arabia' }] }
    else if (path === '/lead-discovery/sources' && route.request().method() === 'GET') body = { data: sourceRows }
    else if (path === '/lead-discovery/campaigns' && route.request().method() === 'GET') body = { data: campaign ? [campaign] : [] }
    else if (path === '/lead-discovery/campaigns' && route.request().method() === 'POST') {
      campaignCreatePayload = route.request().postDataJSON()
      campaign = { id: campaignId, name: 'Synthetic Open Business Search', industry: null, country_code: null, target_leads: 5, status: 'draft', auto_promotion_enabled: false }
      status = 201; body = campaign
    }
    else if (path === `/lead-discovery/campaigns/${campaignId}/start` && route.request().method() === 'POST') {
      started = true; campaign = { ...campaign, status: 'running' }; status = 202; body = { campaign_id: campaignId, status: 'queued', auto_promotion: false }
    }
    else if (path === `/lead-discovery/campaigns/${campaignId}`) body = { campaign, counts: { discovered: started ? 1 : 0, verified: 0, eligible: 0, needs_review: 1, rejected: 0, duplicates: 0, invalid: 0, promoted: 0, processing_errors: 0 }, contacts_enriched: 0, source_errors: 0,
      providers: started ? [{ source_id: sourceRows[0].id, source_key: 'osm_overpass', source: sourceRows[0].provider_name, status: 'running', partitions_completed: 1, partitions_total: 4, requests: 1, raw_records: 1, unique_candidates: 1, duplicates: 0, eligible: 0, errors: 0 }] : [],
      runs: started ? [{ source_requests: 1, raw_candidates: 1 }] : [], checkpoints: started ? [{ id: 'checkpoint', partition_key: 'IN:TN:Chennai', query_key: 'ecommerce_retail-retail', status: 'completed' }] : [] }
    else if (path === '/lead-discovery/candidates') body = { data: started ? [{ id: candidateId, source_id: sourceRows[0].id, campaign_id: campaignId, company_name: 'Synthetic Retail Company', normalized_domain: 'synthetic-retail.example.com', industry: null, country_code: null, city: null, eligibility_status: 'eligible', official_domain_status: 'verified', promotion_status: 'not_promoted', processing_status: 'eligible', raw_metadata: { query: 'retail business', rank: 1 } }] : [] }
    else if (path === `/lead-discovery/candidates/${candidateId}`) body = { candidate: { id: candidateId, company_name: 'Synthetic Retail Company', claimed_website: 'https://synthetic-retail.example.com/', official_domain_status: 'verified', eligibility_status: 'eligible', validation_reason: 'Synthetic website match', validation_reason_code: 'VERIFIED_TARGET_MATCH', promotion_status: 'not_promoted' }, campaign, source: sourceRows[0], evidence: [{ id: 'ev1', field_name: 'company_name', evidence_type: 'open_source_record', normalized_value: 'Synthetic Retail Company', confidence_score: 0.8, collected_at: '2026-10-14T00:00:00Z', source_url: 'https://www.openstreetmap.org/node/123', attribution: '{"license_name":"ODbL","attribution_text":"© OpenStreetMap contributors"}' }], matched_company: null }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  })
  await page.goto(baseUrl, { waitUntil: 'networkidle' })
  await page.getByText('Synthetic Pilot Tenant').waitFor()
  await page.getByRole('button', { name: /Prospects/ }).first().click()
  await page.getByRole('button', { name: 'Lead Discovery' }).click()
  await page.getByRole('heading', { name: 'Lead Discovery', exact: true }).waitFor()
  await page.getByRole('heading', { name: 'Create a discovery campaign' }).waitFor()
  await page.getByLabel('Industry').fill('')
  await page.getByLabel('Country').selectOption('')
  await page.getByText('All supported business categories').waitFor()
  const mca = page.locator('.source-choice label.check').filter({ hasText: 'India OGD / MCA' }).locator('input[type="checkbox"]')
  await page.getByLabel('Country').selectOption('IN')
  await mca.waitFor()
  await page.getByLabel('Country').selectOption('AE')
  await page.getByText(/India OGD \/ MCA: not applicable — India only\./).waitFor()
  await page.getByLabel('Country').selectOption('SA')
  assert.equal(await page.getByLabel('Country').inputValue(), 'SA', 'The complete catalog accepts the Saudi Arabia country option')
  await page.locator('.source-choice label.check').filter({ hasText: 'Wikidata' }).waitFor()
  await page.locator('.source-choice label.check').filter({ hasText: 'GLEIF' }).waitFor()
  assert.equal(await page.locator('.source-choice label.check').filter({ hasText: 'India OGD / MCA' }).count(), 0, 'MCA is not offered for Saudi Arabia')
  await page.getByLabel('Industry').fill('Marine Engineering')
  assert.equal(await page.getByLabel('Industry').inputValue(), 'Marine Engineering', 'Custom industry text remains editable')
  await page.getByLabel('Country').selectOption('')
  await page.getByLabel('Industry').fill('')
  assert.equal(await page.locator('.source-choice label.check').filter({ hasText: 'Brave Search' }).count(), 0, 'Conditional Brave is not selected by default for an unfiltered global campaign')
  await page.getByText('Google Maps', { exact: true }).waitFor()
  await page.getByText('Not available for bulk persistent discovery.').waitFor()
  await page.getByLabel('Campaign name').fill('Synthetic Open Business Search')
  await page.getByRole('button', { name: 'Create campaign' }).click()
  await page.getByText('Campaign created.', { exact: false }).waitFor()
  assert.ok(Array.isArray(campaignCreatePayload?.source_ids), 'Campaign submits the compatible selected source IDs')
  assert.ok(!campaignCreatePayload.source_ids.includes(sourceRows.find((source) => source.source_key === 'brave_search').id), 'Brave remains excluded from the default campaign selection')
  await page.getByRole('button', { name: 'Start Discovery' }).click()
  await page.getByText('Discovery queued.', { exact: false }).waitFor()
  await page.getByText(/Partitions 1\/4/).waitFor()
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
  console.log('PASS: mocked capability catalog, optional filters, India/UAE source compatibility, prohibited Google Maps, unchecked conditional Brave, provider progress, candidate provenance, manual promotion, CSV fallback, and responsive 390/768/1280/1440.')
} finally {
  await browser?.close()
  server.kill('SIGTERM')
}
