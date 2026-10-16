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
const geographyFilterRequests = []
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
      campaign = { id: campaignId, name: 'Synthetic Open Business Search', industry: null, country_code: null, target_leads: 5, status: 'draft', auto_promotion_enabled: false, configuration: { pilot_mode: true, pilot_target: 25 } }
      status = 201; body = campaign
    }
    else if (path === `/lead-discovery/campaigns/${campaignId}/start` && route.request().method() === 'POST') {
      started = true; campaign = { ...campaign, status: 'running' }; status = 202; body = { campaign_id: campaignId, status: 'queued', auto_promotion: false }
    }
    else if (path === `/lead-discovery/campaigns/${campaignId}`) body = { campaign, pilot_summary: { target: 25, target_counting_rule: 'Count unique candidates; duplicates and location mismatch are excluded. Unverifiable website outcomes remain reviewable.', unique_staged: started ? 1 : 0, total_raw_candidates: started ? 5 : 0, duplicates: started ? 1 : 0, verified_candidates: 1, unverifiable_candidates: 0, no_website: 0, eligible_reviewable: 1, rejected: 0, geographic_mismatches: 0, skipped_sources: [{ source_id: 'skipped-source', source_key: 'gleif', source: 'GLEIF', reason_code: 'SOURCE_TEMPORARILY_UNAVAILABLE' }], geographic_distribution: { city_match: 1, region_match: 0, country_match: 0, location_unknown: 0, location_mismatch: 0 }, verification_distribution: { verified: 1, robots_blocked: 0, dns_failure: 0, timeout: 0, connection_unavailable: 0, redirect_policy_blocked: 0, no_website: 0, other_unverifiable: 0 }, human_review: { not_reviewed: 1, promoted: 0, manually_rejected: 0 }, deduplication: { cross_source_matched_candidates: 1, matched_existing_companies: 0, possible_identity_matches_for_review: 0, automatic_merge: false }, source_contribution: started ? [{ source_id: sourceRows[0].id, source: sourceRows[0].provider_name, raw_results: 5, staged_candidates: 1, duplicates: 1, verified: 1, reviewable: 1, rejected: 0, geographic_mismatches: 0, unverifiable_websites: 0, provider_requests: 1, retries: 0, source_errors: 0, processing_errors: 0, elapsed_seconds: 2 }] : [], auto_promotion: false, outreach_created: false }, counts: { discovered: started ? 1 : 0, verified: 0, eligible: 0, needs_review: 1, rejected: 0, duplicates: 0, invalid: 0, promoted: 0, processing_errors: 0 }, contacts_enriched: 0, source_errors: 0,
      providers: started ? [{ source_id: sourceRows[0].id, source_key: 'osm_overpass', source: sourceRows[0].provider_name, status: 'running', partitions_completed: 1, partitions_total: 4, requests: 1, raw_records: 1, unique_candidates: 1, duplicates: 0, eligible: 0, errors: 0 }] : [],
      runs: started ? [{ source_requests: 1, raw_candidates: 1 }] : [], checkpoints: started ? [{ id: 'checkpoint', partition_key: 'IN:TN:Chennai', query_key: 'ecommerce_retail-retail', status: 'completed' }] : [] }
    else if (path === '/lead-discovery/candidates') {
      const filter = new URL(route.request().url()).searchParams.get('geographic_relevance')
      if (filter) geographyFilterRequests.push(filter)
      const states = [
        ['city_match', 'Synthetic Retail Company', 'Chennai, Tamil Nadu, India'],
        ['region_match', 'Regional Retailer', 'Tamil Nadu, India'],
        ['country_match', 'Country Retailer', 'India'],
        ['location_unknown', 'Unknown Location Retailer', 'Unknown'],
        ['location_mismatch', 'Mismatch Retailer', 'Bengaluru, Karnataka, India'],
      ]
      const rows = states.map(([classification, name, location], index) => ({
        id: index === 0 ? candidateId : `74000000-0000-4000-8000-${String(index + 1).padStart(12, '0')}`,
        source_id: sourceRows[0].id, campaign_id: campaignId, company_name: name,
        normalized_domain: `synthetic-retail-${index + 1}.example.com`, industry: null, country_code: null, city: null,
        eligibility_status: classification === 'location_mismatch' ? 'rejected' : 'needs_review', official_domain_status: 'verified',
        promotion_status: 'not_promoted', processing_status: 'needs_review',
        geographic_relevance: { classification, requested_location: { city: 'Chennai', region: 'Tamil Nadu', country: 'India' },
          evidence_location: { city: location === 'Unknown' ? null : location.split(', ')[0], region: location.includes('Karnataka') ? 'Karnataka' : location.includes('Tamil Nadu') ? 'Tamil Nadu' : null, country: location === 'Unknown' ? null : 'India' },
          evidence_source: 'osm_overpass', explanation: classification === 'location_unknown' ? 'No reliable source location evidence is available.' : `Source evidence indicates ${location}.` },
        raw_metadata: { query: 'retail business', rank: index + 1 },
      }))
      body = { data: started ? (filter ? rows.filter((row) => row.geographic_relevance.classification === filter) : rows) : [] }
    }
    else if (path === `/lead-discovery/candidates/${candidateId}`) body = { candidate: { id: candidateId, company_name: 'Synthetic Retail Company', claimed_website: 'https://synthetic-retail.example.com/', official_domain_status: 'verified', eligibility_status: 'eligible', validation_reason: 'Synthetic website match', validation_reason_code: 'VERIFIED_TARGET_MATCH', promotion_status: 'not_promoted', geographic_relevance: { classification: 'city_match', requested_location: { city: 'Chennai', region: 'Tamil Nadu', country: 'India' }, evidence_location: { city: 'Chennai', region: 'Tamil Nadu', country: 'India' }, evidence_source: 'osm_overpass', explanation: 'Source evidence confirms Chennai, Tamil Nadu, India.' } }, campaign, source: sourceRows[0], evidence: [{ id: 'ev1', field_name: 'company_name', evidence_type: 'open_source_record', normalized_value: 'Synthetic Retail Company', confidence_score: 0.8, collected_at: '2026-10-14T00:00:00Z', source_url: 'https://www.openstreetmap.org/node/123', attribution: '{"license_name":"ODbL","attribution_text":"© OpenStreetMap contributors"}' }], matched_company: null }
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
  assert.equal(campaignCreatePayload?.pilot_mode, true, 'New campaign uses the bounded controlled pilot mode by default')
  assert.ok(!campaignCreatePayload.source_ids.includes(sourceRows.find((source) => source.source_key === 'brave_search').id), 'Brave remains excluded from the default campaign selection')
  await page.getByRole('button', { name: 'Start Discovery' }).click()
  await page.getByText('Discovery queued.', { exact: false }).waitFor()
  await page.getByText(/Partitions 1\/4/).waitFor()
  await page.getByRole('heading', { name: 'Pilot quality summary' }).waitFor()
  await page.getByText('1 / 25 unique').waitFor()
  await page.getByLabel('Sources skipped safely').getByText('GLEIF · SOURCE_TEMPORARILY_UNAVAILABLE').waitFor()
  await page.getByRole('columnheader', { name: 'Retries' }).waitFor()
  await page.getByText('Manually rejected').waitFor()
  await page.getByText('Cross-source matches').waitFor()
  await page.getByText('Synthetic Retail Company', { exact: true }).waitFor()
  await page.locator('.candidate-table-wrap').getByText('City match', { exact: true }).waitFor()
  await page.locator('.candidate-table-wrap').getByText('Requested: Chennai, Tamil Nadu, India').first().waitFor()
  await page.locator('.candidate-table-wrap').getByText('Evidence: Chennai, Tamil Nadu, India').first().waitFor()
  for (const [filter, label, candidateName] of [
    ['city_match', 'City match', 'Synthetic Retail Company'],
    ['region_match', 'Region match', 'Regional Retailer'],
    ['country_match', 'Country match', 'Country Retailer'],
    ['location_unknown', 'Location unknown', 'Unknown Location Retailer'],
    ['location_mismatch', 'Location mismatch', 'Mismatch Retailer'],
  ]) {
    await page.getByLabel('Geography').selectOption(filter)
    await page.locator('.candidate-table-wrap').getByText(label, { exact: true }).waitFor()
    await page.getByRole('button', { name: candidateName, exact: true }).waitFor()
    assert.ok(geographyFilterRequests.includes(filter), `The ${filter} filter is sent to the API.`)
  }
  await page.getByLabel('Geography').selectOption('')
  await page.getByText('Synthetic Retail Company', { exact: true }).waitFor()
  await page.getByRole('button', { name: 'Synthetic Retail Company' }).click()
  await page.getByRole('heading', { name: 'Synthetic Retail Company' }).waitFor()
  await page.getByText('Requested campaign location').waitFor()
  await page.getByText('Location from source evidence').waitFor()
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
