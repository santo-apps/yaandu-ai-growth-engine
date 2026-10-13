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
  import_provenance: {
    review_status: 'reviewed', source_url: 'https://synthetic-pilot.example.test', collected_at: '2026-10-12T08:00:00Z',
    imported_by: 'Synthetic QA reviewer', provenance_note: 'Synthetic-only QA fixture.', intelligence_rating: 'strong',
    intelligence_rubric_score: 15, lead_score_rating: 'reasonable', recommendation_rating: 'strongly_relevant',
    technology_accuracy_rating: 'correct', next_action_rating: 'useful', unsupported_claim_count: 0,
    reviewer_notes: 'Synthetic QA notes must stay out of the sales interface.',
    claim_reviews: [{ claim: 'Synthetic claim', status: 'supported', source_url: 'https://synthetic-pilot.example.test', notes: 'Synthetic claim note.' }],
  },
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
let fixtureReview = null
let fixtureOutreachDraft = null
const draftMutationRequests = []
let fixtureMode = 'full'
const fixtureServices = [{ id: '40000000-0000-4000-8000-000000000001', sku: 'YND-WEB-REVAMP', canonical_service_key: 'website_modernization', name: 'Website Revamp', description: 'Website modernization', unit_price: null, currency: 'INR', unit: 'project', commercial_model: 'custom_quote', active: true, capabilities: ['website_modernization'], standard_deliverables: [] }]
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
const outputDirectory = process.env.PLAYWRIGHT_ARTIFACT_DIR || await mkdtemp(join(tmpdir(), 'yaandu-sprint8-ui-'))
const removeOutputDirectory = !process.env.PLAYWRIGHT_ARTIFACT_DIR
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
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseUrl })
  const mutatingRequests = []
  const salesReviewRequests = []
  await page.addInitScript((id) => localStorage.setItem('tenant_id', id), tenantId)
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '')
    const method = route.request().method()
    let body = { data: [] }
    const injectedServerFailure = fixtureMode === 'server_error' && (path === '/companies' || path.startsWith('/companies?'))
    const responseStatus = injectedServerFailure ? 500 : path === `/companies/${companyId}/sales-review` && route.request().method() === 'GET' && fixtureMode === 'review_error' ? 503 : 200
    if (injectedServerFailure) body = { message: 'SQLSTATE[HY000]: secret database detail at vendor/framework/Connection.php:42' }
    else if (path === '/me') body = { id: 1, name: 'Synthetic Reviewer', email: 'reviewer@example.test' }
    else if (path === '/tenants') body = [{ id: tenantId, name: 'Synthetic Pilot Workspace', role: 'owner', status: 'active' }]
    else if (path === '/companies' || path.startsWith('/companies?')) body = { data: [fixtureCompany] }
    else if (path === `/companies/${companyId}/outreach-drafts` && method === 'GET') body = { eligible: fixtureReview?.decision === 'pursue' && Boolean(fixtureReview?.selected_service_id), generation_ready: true, readiness: { checks: [
      { key: 'sales_decision', label: 'Prospect marked Pursue', ready: true, detail: 'Approved sales decision is in place.' },
      { key: 'service', label: 'Active service selected', ready: true, detail: 'The selected service is active and approved.' },
      { key: 'findings', label: 'Approved findings selected', ready: true, detail: '1 validated finding available for human selection.' },
      { key: 'route', label: 'AI writing route configured', ready: true, detail: 'A tenant-specific route is enabled.' },
      { key: 'credential', label: 'AI provider available', ready: true, detail: 'Provider credentials are configured.' },
      { key: 'prompt', label: 'Approved outreach prompt', ready: true, detail: 'An approved active prompt is available.' },
    ], route: { provider: 'openai', model: 'synthetic-test', enabled: true }, prompt: { approved_active: true } }, review: fixtureReview, findings: [{ id: '50000000-0000-4000-8000-000000000001', type: 'poor_mobile_ux', label: 'Poor mobile experience', summary: 'Viewport metadata disables user zoom on the inspected page.', confidence: .99, source: 'deterministic website finding' }], drafts: fixtureOutreachDraft ? [fixtureOutreachDraft] : [] }
    else if (path === `/companies/${companyId}/outreach-drafts` && method === 'POST') {
      draftMutationRequests.push(route.request().postDataJSON())
      fixtureOutreachDraft = { id: '60000000-0000-4000-8000-000000000001', version: fixtureOutreachDraft ? fixtureOutreachDraft.version + 1 : 1,
        provider: 'synthetic', model: 'synthetic-test', prompt_version: 1, validated_findings: [{ id: '50000000-0000-4000-8000-000000000001', label: 'Poor mobile experience' }],
        draft_payload: { pitch_summary: 'A measured improvement opportunity.', recommended_channel: 'contact_page', recommended_channel_reason: 'No verified decision maker is available.',
          email: { subject: 'A thought on mobile usability', body: 'We noticed a mobile usability detail that may be worth reviewing.' }, whatsapp: '', linkedin: '', follow_up_email: '', follow_up_short: '', evidence_used: [{ finding_id: '50000000-0000-4000-8000-000000000001', summary: 'Viewport metadata disables user zoom.' }] } }
      body = fixtureOutreachDraft
    }
    else if (path.startsWith('/outreach-drafts/') && path.endsWith('/regenerate') && method === 'POST') {
      draftMutationRequests.push(route.request().postDataJSON())
      fixtureOutreachDraft = { ...fixtureOutreachDraft, id: '60000000-0000-4000-8000-000000000002', version: fixtureOutreachDraft.version + 1 }
      body = fixtureOutreachDraft
    }
    else if (path === `/companies/${companyId}`) body = fixtureCompany
    else if (path === `/companies/${companyId}/sales-review` && route.request().method() === 'PUT') {
      const input = route.request().postDataJSON()
      fixtureReview = { decision: input.decision, selected_service_id: input.selected_service_id, notes: input.notes,
        reviewer_name: 'Synthetic Reviewer', reviewed_at: '2026-10-12T09:00:00Z', service_name: fixtureServices.find((service) => service.id === input.selected_service_id)?.name ?? null }
      body = { review: fixtureReview }
    }
    else if (path === `/companies/${companyId}/sales-review`) body = { review: fixtureReview }
    else if (path === '/tenant-services') body = fixtureServices
    else if (path === `/companies/${companyId}/intelligence`) body = {
      latest_scan: { status: 'completed', created_at: '2026-01-01T00:00:00Z' },
      issues: [...deterministicIssues, { id: 'ai-issue-1', type: 'ai_observation', severity: 'low', summary: 'AI-only advisory observation.', confidence: 0.8, evidence: JSON.stringify({ source_url: 'https://synthetic-pilot.example.test', excerpt: 'Synthetic fixture evidence excerpt.' }) }],
      technologies: [...deterministicTechnologies, { id: 'ai-tech-1', name: 'AI identified framework', category: 'observed', detection_method: 'ai_inference', confidence: 0.7 }],
      pages: fixturePages,
      scores: [{ id: 'score-1', score: 67, evidence_coverage: 60, rule_version: 1, components: { icp_fit: { status: 'POSITIVE', raw_points: 10, points: 10, evidence_reference: { source: 'company_record', field: 'industry', value: 'Manufacturing', criteria: ['manufacturing'], url: 'https://synthetic-pilot.example.test' } }, outdated_website: { status: 'POSITIVE', raw_points: 15, points: 15 }, whatsapp_gap: { status: 'NEGATIVE', raw_points: 0, points: 0 }, crm_gap: { status: 'UNKNOWN', raw_points: 0, points: 0 } } }],
      intelligence_results: [{ structured_output: { business_identity: { description: 'Synthetic company summary.' }, observations: [{ statement: 'The site lists a contact page.', evidence_id: 'page-1', confidence: 0.9 }], opportunities: [], technical_findings: [], unknowns: ['CRM use is unknown.'], evidence: [{ evidence_id: 'page-1', source_url: 'https://synthetic-pilot.example.test', excerpt: 'Synthetic page evidence.' }] } }],
      screenshots: [],
    }
    else if (path === '/pilot/cohorts') body = { data: [] }
    else if (path.startsWith('/pilot/dashboard')) body = { cohort: null, simulated: true, sales_intelligence_mode: 'human_assisted', metrics: {}, funnel: {}, rates_percent: {} }
    else if (path === '/pilot/sales-intelligence-mode') body = { sales_intelligence_mode: 'human_assisted' }
    else if (path === '/ai-configurations') body = ['website_reasoning','outreach_drafting'].map((task_key) => ({ id: null, task_key, provider: 'openai', model: 'synthetic-test', enabled: true,
      parameters: {}, version: 1, is_tenant_override: false, available_providers: ['openai','anthropic','gemini'] }))
    else if (path === '/local-acceptance/status') body = { mode: 'disabled', providers: { ai: 'Synthetic AI', outbound: 'Disabled', scheduling: 'Disabled' }, queue: 'isolated', company_id: null, campaign_id: null, campaign_status: 'disabled', message: null, inbound_count: 0, conversation_id: null, opportunity_id: null, meeting_id: null, proposal_id: null, proposal_delivery_count: 0 }
    else if (path === '/marketing-configuration/knowledge') body = []
    if (path === `/companies/${companyId}/intelligence` && ['blocked', 'danube', 'homecentre'].includes(fixtureMode)) body = {
      latest_scan: { status: 'failed', failure_category: fixtureMode === 'homecentre' ? 'HTTP_4XX' : 'ROBOTS', created_at: '2026-10-12T00:00:00Z' },
      issues: [], technologies: [], pages: [], scores: [], intelligence_results: [], screenshots: [],
    }
    if (path === `/companies/${companyId}/intelligence` && fixtureMode === 'bo_concept') body = {
      latest_scan: { status: 'completed', created_at: '2026-10-12T00:00:00Z' }, issues: [], technologies: [], pages: [], intelligence_results: [], screenshots: [],
      scores: [{ id: 'score-bo', score: 67, evidence_coverage: 30, components: { icp_fit: { status: 'POSITIVE', points: 10, evidence_reference: { source: 'company_record', field: 'industry', value: 'Retail' } }, relevant_industry: { status: 'POSITIVE', points: 10, evidence_reference: { source: 'company_record', field: 'industry', value: 'Retail' } } } }],
    }
    if (path === `/companies/${companyId}/intelligence` && fixtureMode === 'the_home') body = {
      latest_scan: { status: 'completed', created_at: '2026-10-12T00:00:00Z' },
      issues: [],
      technologies: [], pages: [], intelligence_results: [], screenshots: [],
      scores: [{ id: 'score-home', score: 67, evidence_coverage: 60, components: { outdated_website: { status: 'POSITIVE', points: 15, evidence_reference: { source: 'website_scan', field: 'technology', value: 'Legacy platform' } }, technology_opportunity: { status: 'POSITIVE', points: 5, evidence_reference: { source: 'website_scan', field: 'technology', value: 'Legacy platform' } } } }],
    }
    if (path === `/companies/${companyId}/intelligence` && fixtureMode === 'empty') body = {
      latest_scan: null, issues: [], technologies: [], pages: [], scores: [], intelligence_results: [], screenshots: [],
    }
    await route.fulfill({ status: responseStatus, contentType: 'application/json', body: JSON.stringify(body) })
  })

  const pageErrors = []
  const consoleErrors = []
  page.on('pageerror', (error) => pageErrors.push(`${error.message} ${error.stack ?? ''}`))
  page.on('console', (message) => {
    if (message.type() === 'error' && !(message.text().includes('503 (Service Unavailable)') && message.location().url.includes('/sales-review')) && !(message.text().includes('500 (Internal Server Error)') && fixtureMode === 'server_error')) consoleErrors.push(message.text())
  })
  page.on('request', (request) => {
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method()) && /intelligence|score|campaign|message|outreach/i.test(request.url()) && !/outreach-drafts/i.test(request.url())) mutatingRequests.push(`${request.method()} ${request.url()}`)
    if (request.method() === 'PUT' && request.url().includes('/sales-review')) salesReviewRequests.push(request.url())
  })
  await page.goto(baseUrl, { waitUntil: 'networkidle' })
  await page.getByText('Synthetic Pilot Workspace').waitFor({ timeout: 5000 })

  const screens = [
    ['Home', 'Home'],
    ['Prospects', 'Prospects'],
  ]
  const results = []
  for (const [label, navLabel] of screens) {
    await page.setViewportSize({ width: 1440, height: 1000 })
    if (navLabel !== 'Home') await page.getByRole('button', { name: new RegExp(navLabel) }).first().click()
    await page.locator('h1').first().waitFor()
    const heading = await page.locator('h1').first().innerText()
    assert.ok(heading.length > 0, `${label} page heading is visible`)
    if (label === 'Prospects') {
      results.push(await inspect(page, 'Prospects', outputDirectory))
      await page.getByText('Synthetic Pilot Company', { exact: true }).first().click()
      await page.locator('h1').getByText('Synthetic Pilot Company').waitFor()
      assert.equal(await page.locator('.local-acceptance').count(), 0, 'Local acceptance tooling is not displayed in the sales workspace')
      await page.getByText('Prospect overview', { exact: true }).waitFor()
      assert.equal(await page.getByRole('heading', { name: 'Evidence review' }).count(), 0, 'Internal Evidence Review is hidden from the production sales workspace')
      assert.equal(await page.getByText('17-point intelligence rubric', { exact: true }).count(), 0, 'Internal QA rubric is not exposed')
      assert.equal(await page.getByText('Synthetic QA notes must stay out of the sales interface.', { exact: true }).count(), 0, 'Internal reviewer notes are not exposed')
      assert.equal(await page.getByRole('button', { name: 'Save human review', exact: true }).count(), 0, 'Internal QA save control is not exposed')
      assert.equal(await page.locator('.sales-review-card').count(), 1, 'Human Sales Decision is the single commercial review workflow')
      assert.equal(await page.locator('.pilot-review').count(), 0, 'No internal review form appears on Overview')
      assert.ok((await page.locator('.prospect-tabs').evaluate((element) => element.getBoundingClientRect().bottom)) < (await page.locator('.sales-review-card').evaluate((element) => element.getBoundingClientRect().top)), 'Prospect tabs sit above Overview content without cutting through the decision card')
      assert.notEqual(await page.locator('.prospect-tabs').evaluate((element) => getComputedStyle(element).position), 'sticky', 'Tabs remain in document flow instead of overlaying the decision card')
      await page.locator('[aria-label="Derived lead priority"]').getByText('High Priority', { exact: true }).waitFor()
      await page.locator('[aria-label="Derived lead priority"]').getByText('EVIDENCE COVERAGE', { exact: true }).waitFor()
      assert.match(await page.locator('[aria-label="Derived lead priority"]').innerText(), /67 \/ 100[\s\S]*60%[\s\S]*High Priority[\s\S]*High evidence/)
      const scoreEvidence = await page.locator('body').innerText()
      assert.match(scoreEvidence, /Source: company_record · Field: industry · Value: Manufacturing/)
      assert.ok(!scoreEvidence.includes('[object Object]'), 'Structured score evidence renders as concise readable text')
      assert.match(await page.locator('.opportunity-summary-card').innerText(), /Business fit[\s\S]*Opportunity signals[\s\S]*Existing capabilities/)
      const summaryColumns = page.locator('.opportunity-summary-columns > div')
      assert.match(await summaryColumns.nth(0).innerText(), /Ideal customer fit/)
      assert.ok(!(await summaryColumns.nth(1).innerText()).includes('Ideal customer fit'), 'Business fit stays separate from opportunity signals')
      await page.getByText('Full score details', { exact: true }).click()
      assert.match(await page.locator('.prospect-overview-grid').first().innerText(), /Capability present[\s\S]*Unknown \/ insufficient evidence/)
      await page.getByRole('heading', { name: 'What should happen with this prospect?' }).waitFor()
      assert.equal(await page.getByLabel('Sales decision').inputValue(), '', 'Human decision is not prefilled from AI priority')
      assert.equal(await page.locator('.priority-summary').getByText('Not Reviewed', { exact: true }).count(), 1, 'Unreviewed state is explicit')
      const decisionControl = page.getByLabel('Sales decision')
      await decisionControl.focus()
      await page.keyboard.press('Tab')
      assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('aria-label') || document.activeElement?.tagName), 'SELECT', 'Review controls follow keyboard focus order')
      await page.getByLabel('Sales decision').selectOption('pursue')
      await page.getByLabel(/Relevant service/).selectOption(fixtureServices[0].id)
      await page.getByLabel(/Decision notes/).fill('<script>synthetic note</script>')
      await page.getByRole('button', { name: 'Save sales decision' }).click()
      await page.getByText('AI priority remains advisory and unchanged.', { exact: false }).waitFor()
      assert.equal(await page.getByText('Reviewed by Synthetic Reviewer').count(), 1, 'Saved reviewer and timestamp are visible')
      assert.equal(await page.locator('.sales-review-card').locator('script').count(), 0, 'Reviewer notes render as text, never markup')
      assert.equal(salesReviewRequests.length, 1, 'Saving human review makes one review-only PUT')
      assert.deepEqual(mutatingRequests, [], 'Human review does not call scoring, crawling, AI, campaign, or outreach endpoints')
      await page.reload({ waitUntil: 'networkidle' })
      await page.setViewportSize({ width: 1440, height: 1000 })
      await page.getByRole('button', { name: /Prospects/ }).first().click()
      await page.getByText('Synthetic Pilot Company', { exact: true }).first().click()
      await page.getByRole('heading', { name: 'What should happen with this prospect?' }).waitFor()
      assert.equal(await page.getByLabel('Sales decision').inputValue(), 'pursue', 'Saved review reloads correctly')
      assert.equal(await page.getByLabel(/Relevant service/).inputValue(), fixtureServices[0].id)
      await page.getByRole('button', { name: 'Outreach', exact: true }).click()
      await page.getByRole('heading', { name: 'Outreach draft' }).waitFor()
      await page.getByRole('heading', { name: 'Outreach draft readiness' }).waitFor()
      assert.equal(await page.getByText('Select findings', { exact: true }).count(), 1, 'The UI requires explicit finding selection before generation')
      assert.equal(await page.locator('.finding-option input').first().isChecked(), false, 'Evidence findings are never preselected')
      await page.locator('.finding-option input').first().check()
      assert.equal(await page.getByText('Ready to generate', { exact: true }).count(), 1, 'The generation gate becomes ready after explicit selection')
      await page.getByRole('button', { name: 'Generate outreach draft' }).click()
      await page.getByText('Draft saved for your review. Nothing was sent.').waitFor()
      await page.getByText('A thought on mobile usability', { exact: true }).waitFor()
      await page.getByRole('button', { name: 'Copy email' }).click()
      await page.getByText('Copied to clipboard.', { exact: true }).waitFor()
      assert.match(await page.evaluate(() => navigator.clipboard.readText()), /A thought on mobile usability[\s\S]*We noticed a mobile usability detail/)
      assert.equal(await page.getByRole('button', { name: /Send/i }).count(), 0, 'Draft workspace has no send action')
      assert.equal(draftMutationRequests.length, 1, 'One explicit reviewer action creates the draft')
      assert.deepEqual(draftMutationRequests[0].finding_ids, ['50000000-0000-4000-8000-000000000001'])
      await page.reload({ waitUntil: 'networkidle' })
      await page.getByRole('button', { name: /Prospects/ }).first().click()
      await page.getByText('Synthetic Pilot Company', { exact: true }).first().click()
      await page.getByRole('button', { name: 'Outreach', exact: true }).click()
      await page.getByRole('heading', { name: 'Outreach draft' }).waitFor()
      await page.getByText('Draft 1').waitFor()
      await page.setViewportSize({ width: 390, height: 740 })
      await page.getByRole('button', { name: 'Open navigation' }).click()
      const nav = page.locator('.sidebar-navigation')
      assert.ok(await nav.getByRole('button', { name: /AI Configuration/ }).count(), 'Lower management navigation remains available on mobile')
      await nav.evaluate((element) => { element.scrollTop = element.scrollHeight })
      assert.ok(await nav.getByRole('button', { name: /Workflow diagnostics/ }).count(), 'Lower operations navigation remains reachable in the scrollable navigation')
      assert.ok(await page.locator('.sidebar-bottom').isVisible(), 'Sidebar account/footer actions remain visible on mobile')
      await page.setViewportSize({ width: 1440, height: 1000 })
      await page.setViewportSize({ width: 1440, height: 420 })
      await page.locator('.sidebar-navigation').evaluate((element) => { element.scrollTop = element.scrollHeight })
      assert.ok(await page.locator('.sidebar-navigation').getByRole('button', { name: /Workflow diagnostics/ }).isVisible(), 'Sidebar body scrolls independently in a short viewport')
      assert.ok(await page.locator('.sidebar-bottom').getByRole('button', { name: 'Sign out' }).isVisible(), 'Sidebar footer actions remain reachable in a short viewport')
      await page.setViewportSize({ width: 1440, height: 1000 })
      results.push(await inspect(page, 'Prospect 360', outputDirectory))
      await page.setViewportSize({ width: 1440, height: 1000 })
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
      await page.setViewportSize({ width: 1440, height: 1000 })
      await page.getByRole('button', { name: 'Contacts', exact: false }).click()
      results.push(await inspect(page, 'Contacts', outputDirectory))
      await page.setViewportSize({ width: 1440, height: 1000 })
      await page.getByRole('button', { name: /^Activity/ }).click()
      results.push(await inspect(page, 'Activity', outputDirectory))
    }
    if (label !== 'Prospects') results.push(await inspect(page, label, outputDirectory))
  }

  for (const label of ['Outreach', 'Inbox', 'Pipeline', 'Meetings', 'Proposals', 'Approvals', 'Automation', 'Agent Runs', 'Services & Pricing', 'AI Configuration', 'Setup']) {
    await page.setViewportSize({ width: 1440, height: 1000 })
    await page.getByRole('button', { name: new RegExp(label) }).first().click()
    await page.locator('h1').first().waitFor()
    results.push(await inspect(page, label, outputDirectory))
  }

  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.getByRole('button', { name: /Outreach/ }).first().click()
  await page.getByRole('button', { name: 'Drafts / Review' }).click()
  await page.getByRole('button', { name: 'Knowledge & prompts' }).click()
  await page.getByRole('button', { name: 'Use outreach draft starter' }).waitFor()
  results.push(await inspect(page, 'Prompt Configuration', outputDirectory))
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.getByRole('button', { name: 'Use outreach draft starter' }).click()
  assert.equal(await page.locator('.marketing-workspace .config-form select').first().inputValue(), 'OutreachDraftAgent', 'The prompt starter selects the correct agent without approving it')
  assert.match(await page.locator('textarea').first().inputValue(), /draft-only[\s\S]*never send/i, 'The prepared prompt keeps outbound draft-only restrictions')
  assert.equal(await page.getByRole('button', { name: 'Save prompt draft' }).count(), 1, 'Prompt creation remains an explicit action in tenant prompt governance')

  for (const fixture of [
    { name: 'The Home', mode: 'the_home', priority: 'High Priority', score: '67 / 100', coverage: '60%', opportunityCount: '2 evidenced opportunity signals' },
    { name: 'Bo Concept', mode: 'bo_concept', priority: 'Low Confidence', score: '67 / 100', coverage: '30%' },
    { name: 'Danube', mode: 'danube', priority: 'Blocked / Unscored', score: '—', coverage: '—', reason: 'Crawl permission could not be verified from robots.txt.' },
    { name: 'Homecentre', mode: 'homecentre', priority: 'Blocked / Unscored', score: '—', coverage: '—', reason: 'The website returned a client error response.' },
  ]) {
    fixtureMode = fixture.mode
    fixtureCompany.name = fixture.name
    fixtureReview = null
    await page.reload({ waitUntil: 'networkidle' })
    await page.setViewportSize({ width: 1440, height: 1000 })
    await page.getByRole('button', { name: /Prospects/ }).first().click()
    await page.getByText(fixture.name, { exact: true }).first().click()
    await page.getByText('What should happen with this prospect?', { exact: true }).waitFor()
    await page.setViewportSize({ width: 1440, height: 1000 })
    const priority = page.locator('[aria-label="Derived lead priority"]')
    await priority.getByText(fixture.priority, { exact: true }).waitFor()
    assert.ok((await priority.innerText()).includes(fixture.score), `${fixture.name} score semantics are preserved`)
    assert.ok((await priority.innerText()).includes(fixture.coverage), `${fixture.name} coverage semantics are preserved`)
    const opportunityCard = page.locator('.opportunity-summary-card')
    if (fixture.opportunityCount) {
      const opportunityText = fixture.name === 'The Home' ? await priority.innerText() : await opportunityCard.innerText()
      assert.ok(opportunityText.includes(fixture.opportunityCount), `${fixture.name} opportunity summary is accurate`)
    }
    if (fixture.name === 'Bo Concept') {
      const opportunityColumn = opportunityCard.locator('.opportunity-summary-columns > div').nth(1)
      assert.match(await opportunityColumn.innerText(), /Good business fit, but insufficient evidence of a specific digital opportunity\./, `Bo Concept low-confidence summary: ${await opportunityColumn.innerText()}`)
      assert.ok(!(await opportunityColumn.innerText()).includes('Ideal customer fit'), 'ICP fit is not presented as an opportunity')
      assert.ok(!(await opportunityColumn.innerText()).includes('Relevant industry'), 'Relevant industry is not presented as an opportunity')
    }
    if (fixture.reason) {
      assert.ok((await page.locator('.sales-review-card').innerText()).includes(fixture.reason), `${fixture.name} failure reason is shown safely`)
      assert.match(await page.locator('.sales-review-card').innerText(), /Insufficient evidence/)
      assert.equal(await page.getByLabel('Sales decision').inputValue(), '', `${fixture.name} decision remains manual and unselected`)
      assert.match(await opportunityCard.innerText(), /No usable website evidence is available/)
    }
    await page.screenshot({ path: join(outputDirectory, `${fixture.mode}-overview.png`), fullPage: true })
    assert.equal(await page.locator('.pilot-review').count(), 0, `${fixture.name} has no internal Evidence Review section`)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, `${fixture.name} has no horizontal page overflow`)
    if (fixture.name === 'Danube') {
      await page.getByRole('button', { name: 'Website', exact: true }).click()
      assert.ok(await page.getByText('Crawl permission could not be verified from robots.txt.', { exact: true }).count() >= 1, 'Blocked crawl retains its safe Website-tab explanation')
      assert.equal(await page.locator('.score-row').count(), 0, 'Blocked fixture does not display stale score rows')
    }
  }

  fixtureMode = 'empty'
  fixtureCompany.name = 'Synthetic Pilot Company'
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('button', { name: /Prospects/ }).first().click()
  await page.getByText('Synthetic Pilot Company', { exact: true }).first().click()
  await page.getByRole('button', { name: 'Website', exact: true }).click()
  await page.getByText('No Website Intelligence yet', { exact: true }).waitFor()
  fixtureMode = 'review_error'
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('button', { name: /Prospects/ }).first().click()
  await page.getByText('Synthetic Pilot Company', { exact: true }).first().click()
  await page.getByText('Saved sales review could not be loaded.', { exact: true }).waitFor()
  await page.getByLabel('Sales decision').selectOption('hold')
  assert.equal(await page.getByRole('button', { name: 'Save sales decision' }).isDisabled(), true, 'A failed review read cannot be overwritten as if no decision existed')

  fixtureMode = 'server_error'
  await page.reload({ waitUntil: 'networkidle' })
  const safeServerError = page.getByRole('alert').filter({ hasText: 'Something went wrong. Please try again or contact your workspace administrator.' })
  await safeServerError.waitFor()
  assert.ok(!(await page.locator('body').innerText()).includes('SQLSTATE'), 'Server diagnostics are not rendered in the sales UI')

  assert.deepEqual(pageErrors, [], `No browser runtime exceptions: ${pageErrors.join('; ')}`)
  assert.deepEqual(consoleErrors, [], `No browser console errors: ${consoleErrors.join('; ')}`)
  assert.deepEqual(mutatingRequests, [], 'Acceptance did not trigger scoring, campaign, or outbound mutations')
  await page.locator('.sidebar-bottom').getByRole('button', { name: 'Sign out' }).click()
  await page.locator('.tenant-card').getByText('Welcome to Yaandu Growth', { exact: true }).waitFor()
  results.push(await inspect(page, 'Sign in', outputDirectory))
  await page.emulateMedia({ reducedMotion: 'reduce' })
  assert.match(await page.locator('.content').evaluate((element) => getComputedStyle(element).animationDuration), /0\.00001s|1e-05s/, 'Reduced-motion mode disables non-essential transitions')
  console.log(JSON.stringify({ browser: 'Chromium', data: 'synthetic only', screens: results }, null, 2))
} finally {
  await browser?.close()
  server.kill('SIGTERM')
  if (removeOutputDirectory) await rm(outputDirectory, { recursive: true, force: true })
}

async function inspect(page, label, directory) {
  const widths = [1440, 1280, 1024, 768, 390]
  const row = { screen: label, widths: {} }
  for (const width of widths) {
    await page.setViewportSize({ width, height: 1000 })
    if (label === 'Prospect 360' && width === 390) {
      await page.evaluate(() => window.scrollTo(0, 0))
      await page.locator('.sidebar-navigation').evaluate((element) => { element.scrollLeft = 0; element.scrollTop = 0 })
    }
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
    if (width === 1440 || (label === 'Prospect 360' && [390, 768].includes(width))) await page.screenshot({ path: join(directory, `${label.toLowerCase().replaceAll(' ', '-')}${width === 390 || width === 768 ? `-${width}` : ''}.png`), fullPage: true })
  }
  return row
}
