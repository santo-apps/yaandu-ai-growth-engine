<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { humanize, salesRequest, unwrap, csrfToken } from '../salesApi'

const props = defineProps<{ tenantId: string }>()
const emit = defineEmits<{ openProspect: [company: any] }>()
const companies = ref<any[]>([])
const query = ref('')
const busy = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const showAdd = ref(false)
const showDiscovery = ref(false)
const discoveryTab = ref<'search' | 'results' | 'csv'>('search')
const draft = ref({ name: '', website: '', industry: '', location: '' })
const searchDraft = ref({ name: 'Dubai Furniture Prospects', locations: 'Dubai', industries: 'Furniture', keywords: 'furniture, home decor', websiteCriteria: 'outdated design, poor mobile experience, slow site, weak conversion', services: 'E-commerce modernization, performance optimization, AI customer engagement', companySize: 'Unknown', maxCandidates: 20, source: 'location_open_web' })
const runs = ref<any[]>([])
const candidates = ref<any[]>([])
const selected = ref<string[]>([])
const candidateFilter = ref({ location: '', industry: '', status: '', contact: 'any', service: '', minScore: '' })
const candidateDetail = ref<any>(null)
const csvFile = ref<File | null>(null)
const csvPreview = ref<any>(null)
const csvConfirmed = ref(false)
const csvIdempotencyKey = ref(crypto.randomUUID())
let pollTimer: ReturnType<typeof setInterval> | undefined

const latestRun = computed(() => runs.value[0] ?? null)
const discoveryCountry = computed(() => {
  const city = splitValues(searchDraft.value.locations)[0]?.toLocaleLowerCase()
  return city === 'coimbatore' || city === 'salem'
    ? { code: 'India', label: 'India' }
    : { code: 'UAE', label: 'United Arab Emirates' }
})
const filteredCandidates = computed(() => candidates.value.filter((candidate) =>
  (!candidateFilter.value.location || candidate.country === candidateFilter.value.location || candidate.city === candidateFilter.value.location)
  && (!candidateFilter.value.industry || candidate.industry === candidateFilter.value.industry)
  && (!candidateFilter.value.status || candidate.lifecycle_status === candidateFilter.value.status)
  && (candidateFilter.value.contact === 'any' || (candidateFilter.value.contact === 'yes' ? Number(candidate.contact_count) > 0 : Number(candidate.contact_count) === 0))
  && (!candidateFilter.value.service || candidate.recommended_service === candidateFilter.value.service)
  && (candidateFilter.value.minScore === '' || Number(candidate.lead_score ?? -1) >= Number(candidateFilter.value.minScore))))
const selectedWebsiteLess = computed(() => filteredCandidates.value.filter((candidate: any) => selected.value.includes(candidate.id) && !candidate.normalized_domain && candidate.verification_state === 'not_required'))
const resultCounts = computed(() => latestRun.value?.counts ? (typeof latestRun.value.counts === 'string' ? JSON.parse(latestRun.value.counts) : latestRun.value.counts) : {})

async function loadCompanies() {
  busy.value = true; error.value = ''
  try { companies.value = unwrap(await salesRequest(`/companies?search=${encodeURIComponent(query.value)}`, props.tenantId)) }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to load prospects.' }
  finally { busy.value = false }
}

async function loadDiscovery() {
  try {
    const [runResult, candidateResult] = await Promise.all([
      salesRequest('/discovery/runs', props.tenantId),
      salesRequest('/discovery/candidates', props.tenantId),
    ])
    runs.value = unwrap(runResult)
    candidates.value = unwrap(candidateResult)
    if (runs.value.some((run) => ['queued', 'running', 'verifying', 'analyzing'].includes(run.status))) {
      if (!pollTimer) pollTimer = setInterval(() => { void loadDiscovery() }, 4000)
    } else if (pollTimer) { clearInterval(pollTimer); pollTimer = undefined }
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to load discovery results.' }
}

async function saveProspect() {
  saving.value = true; error.value = ''; notice.value = ''
  try { await salesRequest('/companies', props.tenantId, 'POST', draft.value); draft.value = { name: '', website: '', industry: '', location: '' }; showAdd.value = false; notice.value = 'Prospect added.'; await loadCompanies() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to add prospect.' }
  finally { saving.value = false }
}

function splitValues(value: string) { return value.split(',').map((entry) => entry.trim()).filter(Boolean) }
function showEvidence(value: unknown) {
  if (typeof value !== 'string') return value || 'No finding recorded'
  try { return JSON.stringify(JSON.parse(value)) } catch { return value }
}
function hasOsmSource() { return (candidateDetail.value?.sources ?? []).some((source: any) => source.source === 'openstreetmap') }
function scoreComponents(value: unknown): Array<[string, any]> {
  if (typeof value === 'string') { try { value = JSON.parse(value) } catch { return [] } }
  return value && typeof value === 'object' ? Object.entries(value as Record<string, any>) : []
}
async function startSearch() {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const search = await salesRequest('/discovery/searches', props.tenantId, 'POST', {
      name: searchDraft.value.name, source: searchDraft.value.source,
      locations: splitValues(searchDraft.value.locations), industries: splitValues(searchDraft.value.industries),
      city: splitValues(searchDraft.value.locations)[0] || '', business_category: splitValues(searchDraft.value.industries)[0] || '',
      country: discoveryCountry.value.code, radius_m: 10000,
      keywords: splitValues(searchDraft.value.keywords), website_criteria: splitValues(searchDraft.value.websiteCriteria),
      desired_services: splitValues(searchDraft.value.services), company_size: searchDraft.value.companySize, max_candidates: Number(searchDraft.value.maxCandidates),
    })
    const run = await salesRequest(`/discovery/searches/${encodeURIComponent(search.id)}/runs`, props.tenantId, 'POST', { idempotency_key: crypto.randomUUID() })
    notice.value = `Search queued. Candidate collection is limited to ${run.budget ? JSON.parse(run.budget).max_candidates : search.max_candidates} results.`
    discoveryTab.value = 'results'; await loadDiscovery()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to start prospect discovery.' }
  finally { saving.value = false }
}

async function uploadCsv(preview: boolean) {
  if (!csvFile.value) { error.value = 'Choose a CSV file first.'; return }
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const form = new FormData(); form.append('csv', csvFile.value)
    if (!preview) { form.append('confirmed', '1'); form.append('idempotency_key', csvIdempotencyKey.value) }
    const response = await fetch(`/api/v1/discovery/import${preview ? '/preview' : ''}`, {
      method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-Tenant-ID': props.tenantId, 'X-XSRF-TOKEN': csrfToken() }, body: form,
    })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? 'Unable to process this CSV.')
    if (preview) { csvPreview.value = result; csvConfirmed.value = false }
    else { csvConfirmed.value = true; notice.value = `Import queued: ${result.candidate_count} rows entered review. No outreach was sent.`; discoveryTab.value = 'results'; await loadDiscovery() }
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to process this CSV.' }
  finally { saving.value = false }
}

function selectCsvFile(event: Event) {
  csvFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
  csvPreview.value = null
  csvConfirmed.value = false
  csvIdempotencyKey.value = crypto.randomUUID()
}

async function openCandidate(candidate: any) {
  candidateDetail.value = null; error.value = ''
  try { candidateDetail.value = await salesRequest(`/discovery/candidates/${encodeURIComponent(candidate.id)}`, props.tenantId) }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to open candidate details.' }
}

async function review(candidate: any, action: 'accept' | 'reject') {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const response = await salesRequest(`/discovery/candidates/${encodeURIComponent(candidate.id)}/review`, props.tenantId, 'POST', { action })
    notice.value = action === 'accept' ? 'Candidate accepted into Prospects. Its reviewed intelligence and score are preserved; no outreach was sent.' : 'Candidate rejected.'
    candidateDetail.value = null; await loadDiscovery(); await loadCompanies()
    if (action === 'accept' && response.company) emit('openProspect', response.company)
  } catch (e) { error.value = e instanceof Error ? e.message : `Unable to ${action} candidate.` }
  finally { saving.value = false }
}

async function bulkReview(action: 'accept' | 'reject' | 'reverify' | 'analyze') {
  if (!selected.value.length) return
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest('/discovery/candidates/bulk-review', props.tenantId, 'POST', { action, candidate_ids: selected.value })
    const queued = Number(result.queued_count ?? result.results?.filter((row: any) => row.status === 'queued').length ?? 0)
    const completed = Number(result.processed_count ?? result.results?.filter((row: any) => row.status === 'processed').length ?? 0)
    const notActionable = Number(result.not_actionable_count ?? 0)
    const noticeText = action === 'reverify' ? `${queued} website check${queued === 1 ? '' : 's'} queued` : action === 'analyze' ? `${queued} intelligence and scoring job${queued === 1 ? '' : 's'} queued` : `${completed} candidate${completed === 1 ? '' : 's'} ${action === 'accept' ? 'accepted' : 'rejected'}`
    notice.value = `${noticeText}${notActionable ? ` · ${notActionable} not actionable` : ''}. No outreach was sent.`
    selected.value = []; await loadDiscovery(); await loadCompanies()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to review selected candidates.' }
  finally { saving.value = false }
}

async function resolveWebsite(candidate: any) {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    await salesRequest(`/discovery/candidates/${encodeURIComponent(candidate.id)}/website-resolution`, props.tenantId, 'POST', { idempotency_key: crypto.randomUUID() })
    notice.value = `Website resolution queued for ${candidate.company_name || 'this business'}. Candidate domains remain untrusted until reviewed; no outreach was sent.`
    await loadDiscovery(); await openCandidate(candidate)
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to queue website resolution.' }
  finally { saving.value = false }
}

async function bulkResolveWebsites() {
  const websiteLess = filteredCandidates.value.filter((candidate: any) => selected.value.includes(candidate.id) && !candidate.normalized_domain && candidate.verification_state === 'not_required')
  if (!websiteLess.length) { error.value = 'Select website-less candidates to resolve.'; return }
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest('/discovery/candidates/bulk-resolve-websites', props.tenantId, 'POST', { candidate_ids: websiteLess.map((candidate: any) => candidate.id), idempotency_key: crypto.randomUUID() })
    notice.value = `Queued ${result.business_count} website resolution${result.business_count === 1 ? '' : 's'} · up to ${result.estimated_source_lookups} public source lookups. No outreach was sent.`
    selected.value = []; await loadDiscovery()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to queue selected website resolutions.' }
  finally { saving.value = false }
}

async function reviewWebsiteResolution(action: 'confirm' | 'reject' | 'mark_unresolved', resolutionCandidate?: any) {
  if (!candidateDetail.value?.website_resolution?.resolution) return
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const resolution = candidateDetail.value.website_resolution.resolution
    const response = await salesRequest(`/discovery/website-resolutions/${encodeURIComponent(resolution.id)}/review`, props.tenantId, 'POST', {
      action, candidate_id: resolutionCandidate?.id, reason: action === 'mark_unresolved' ? 'Sales reviewer could not confirm an official website.' : undefined,
    })
    candidateDetail.value.website_resolution = response
    notice.value = action === 'confirm' ? 'Official website confirmed by a human reviewer. Safe verification is queued; no outreach was sent.' : action === 'reject' ? 'Candidate rejected and retained as evidence.' : 'Resolution marked unresolved; evidence is retained.'
    await loadDiscovery(); await openCandidate(candidateDetail.value.candidate)
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to record the website-resolution review.' }
  finally { saving.value = false }
}

async function retryWebsiteResolution() {
  const resolution = candidateDetail.value?.website_resolution?.resolution
  if (!resolution) return
  saving.value = true; error.value = ''; notice.value = ''
  try {
    await salesRequest(`/discovery/website-resolutions/${encodeURIComponent(resolution.id)}/retry`, props.tenantId, 'POST', { idempotency_key: crypto.randomUUID() })
    notice.value = 'Website resolution retry queued.'
    await loadDiscovery(); await openCandidate(candidateDetail.value.candidate)
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to retry website resolution.' }
  finally { saving.value = false }
}

function toggleSelected(id: string) { selected.value = selected.value.includes(id) ? selected.value.filter((item) => item !== id) : [...selected.value, id] }
function openProspect(company: any) { emit('openProspect', company) }
watch(query, () => { void loadCompanies() })
watch(() => props.tenantId, () => { void loadCompanies(); if (showDiscovery.value) void loadDiscovery() })
onMounted(() => { void loadCompanies() })
onBeforeUnmount(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
  <section class="prospects-workspace">
    <div class="prospects-toolbar"><label class="prospect-search"><span>⌕</span><input v-model="query" placeholder="Search existing prospects" aria-label="Search prospects" /></label><button class="quiet" @click="showDiscovery = !showDiscovery; if (showDiscovery) loadDiscovery()">{{ showDiscovery ? 'Close discovery' : 'Find Prospects' }}</button><button class="primary" @click="showAdd = !showAdd">＋ Add prospect</button></div>
    <form v-if="showAdd" class="discovery-card add-card" @submit.prevent="saveProspect"><h2>Add an existing company</h2><div class="discovery-form-grid"><label>Company name<input v-model="draft.name" required maxlength="255" /></label><label>Website<input v-model="draft.website" type="url" placeholder="https://company.com" /></label><label>Industry<input v-model="draft.industry" /></label><label>Location<input v-model="draft.location" /></label></div><button class="primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save prospect' }}</button></form>
    <section v-if="showDiscovery" class="discovery-shell">
      <div class="discovery-intro"><div><span class="eyebrow">PROSPECT ACQUISITION</span><h2>Find and review prospects</h2><p>Discovery gathers candidates; verification checks public websites. You decide what becomes a prospect. Outreach is always a separate action.</p></div><span class="discovery-safe-pill">Review before acceptance</span></div>
      <nav class="discovery-tabs" aria-label="Discovery tools"><button :class="{ active: discoveryTab === 'search' }" @click="discoveryTab = 'search'">New search</button><button :class="{ active: discoveryTab === 'results' }" @click="discoveryTab = 'results'; loadDiscovery()">Runs & candidates</button><button :class="{ active: discoveryTab === 'csv' }" @click="discoveryTab = 'csv'">Import CSV</button></nav>
      <div v-if="discoveryTab === 'search'" class="discovery-card"><form @submit.prevent="startSearch"><div class="discovery-form-grid"><label>Search name<input v-model="searchDraft.name" required maxlength="160" /></label><label>Source<select v-model="searchDraft.source"><option value="location_open_web">Location Discovery · OpenStreetMap</option><option value="deterministic_local">Local test fixtures</option></select><small>Automated web search is not enabled.</small></label><label>City<input v-model="searchDraft.locations" placeholder="Dubai" required /><small>Currently supported: Dubai, Abu Dhabi, Sharjah (UAE); Coimbatore and Salem (India).</small></label><label>Country<input :value="discoveryCountry.label" disabled /><small>© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap contributors</a> · ODbL</small></label><label>Business category<input v-model="searchDraft.industries" placeholder="Furniture" required /><small>Examples: furniture, interior design, textile, jewellery, electronics, fashion, retail, healthcare.</small></label><label>Keywords<input v-model="searchDraft.keywords" placeholder="furniture, home decor" /><small>Comma-separated; query count is bounded.</small></label><label>Website signals<input v-model="searchDraft.websiteCriteria" placeholder="slow site, weak lead capture" /><small>Used by later deterministic analysis.</small></label><label class="wide-field">Desired Yaandu services<input v-model="searchDraft.services" placeholder="Website redesign, performance optimization" /></label><label>Maximum candidates<input v-model.number="searchDraft.maxCandidates" type="number" min="1" max="100" /></label></div><div class="discovery-actions"><span>Website checks remain bounded and use existing URL policy and robots rules. No business is promoted or contacted automatically.</span><button class="primary" :disabled="saving">{{ saving ? 'Starting…' : 'Start discovery' }}</button></div></form></div>
      <div v-else-if="discoveryTab === 'csv'" class="discovery-card csv-card"><div><h3>Import a company list</h3><p>CSV headers: <code>company_name, website, country, city, industry</code>. Website or domain is required. Preview does not create prospects.</p></div><div class="csv-upload-row"><input type="file" accept=".csv,text/csv" @change="selectCsvFile"/><button class="quiet" :disabled="saving || !csvFile" @click="uploadCsv(true)">{{ saving ? 'Checking…' : 'Preview CSV' }}</button><button v-if="csvPreview && !csvConfirmed" class="primary" :disabled="saving || !csvPreview.valid_count" @click="uploadCsv(false)">Confirm import</button></div><div v-if="csvPreview" class="csv-summary"><b>{{ csvPreview.valid_count }} valid</b><span>{{ csvPreview.duplicate_count }} duplicates</span><span>{{ csvPreview.invalid_count }} invalid</span><small>Duplicates in file: {{ csvPreview.rows.filter((row: any) => row.duplicate_type === 'within_file').length }} · Existing company/candidate: {{ csvPreview.rows.filter((row: any) => ['existing_company', 'existing_candidate'].includes(row.duplicate_type)).length }}</small><span>{{ csvPreview.total }} rows</span></div><div v-if="csvPreview" class="table-scroll"><table class="discovery-table"><thead><tr><th>Row</th><th>Company</th><th>Website</th><th>Location</th><th>Industry</th><th>Result</th></tr></thead><tbody><tr v-for="row in csvPreview.rows" :key="row.row"><td>{{ row.row }}</td><td>{{ row.company_name || 'Name to verify' }}</td><td>{{ row.website }}</td><td>{{ [row.city, row.country].filter(Boolean).join(', ') || '—' }}</td><td>{{ row.industry || '—' }}</td><td><span class="status-chip" :class="row.status">{{ humanize(row.status) }}</span><small v-if="row.reason">{{ row.reason }}</small></td></tr></tbody></table></div></div>
      <div v-else class="discovery-results">
        <div v-if="latestRun" class="run-summary"><div><span class="eyebrow">LATEST DISCOVERY RUN</span><h3>{{ latestRun.status === 'verifying' ? 'Checking public websites' : humanize(latestRun.status) }}</h3><p>{{ latestRun.started_at || latestRun.created_at }} · Limit {{ (typeof latestRun.budget === 'string' ? JSON.parse(latestRun.budget) : latestRun.budget)?.max_candidates ?? '—' }} candidates · AI analysis {{ (typeof latestRun.budget === 'string' ? JSON.parse(latestRun.budget) : latestRun.budget)?.ai_analyses_reserved ?? 0 }}/{{ (typeof latestRun.budget === 'string' ? JSON.parse(latestRun.budget) : latestRun.budget)?.max_ai_analyses ?? '—' }}</p></div><div class="run-stats"><span><b>{{ resultCounts.found ?? candidates.length }}</b>Found</span><span><b>{{ resultCounts.duplicates ?? 0 }}</b>Duplicates</span><span><b>{{ resultCounts.no_website ?? 0 }}</b>No website</span><span><b>{{ resultCounts.invalid ?? 0 }}</b>Invalid</span><span><b>{{ resultCounts.verified ?? 0 }}</b>Verified</span><span><b>{{ resultCounts.eligible_for_analysis ?? 0 }}</b>Eligible</span><span><b>{{ resultCounts.analyzed ?? 0 }}</b>Analyzed</span><span><b>{{ resultCounts.scored ?? 0 }}</b>Scored</span><span><b>{{ resultCounts.accepted ?? 0 }}</b>Accepted</span><span><b>{{ resultCounts.rejected ?? 0 }}</b>Rejected</span><span><b>{{ resultCounts.failures ?? 0 }}</b>Failed</span></div></div>
        <div class="candidate-filters"><label>Location<select v-model="candidateFilter.location"><option value="">All locations</option><option v-for="value in [...new Set(candidates.flatMap((item: any) => [item.country, item.city]).filter(Boolean))]" :key="value">{{ value }}</option></select></label><label>Industry<select v-model="candidateFilter.industry"><option value="">All industries</option><option v-for="value in [...new Set(candidates.map((item: any) => item.industry).filter(Boolean))]" :key="value">{{ value }}</option></select></label><label>Review status<select v-model="candidateFilter.status"><option value="">All statuses</option><option value="verified">Verified</option><option value="accepted">Accepted</option><option value="rejected">Rejected</option><option value="discovered">In progress</option></select></label><label>Public contact<select v-model="candidateFilter.contact"><option value="any">Any</option><option value="yes">Found</option><option value="no">Not found</option></select></label><label>Minimum score<select v-model="candidateFilter.minScore"><option value="">Any score</option><option value="40">40+</option><option value="60">60+</option><option value="70">70+</option></select></label><label>Recommended service<select v-model="candidateFilter.service"><option value="">Any service</option><option v-for="value in [...new Set(candidates.map((item: any) => item.recommended_service).filter(Boolean))]" :key="value">{{ value }}</option></select></label><span>{{ filteredCandidates.length }} candidates</span></div>
        <div v-if="selected.length" class="bulk-review-bar"><span>{{ selected.length }} selected</span><button class="quiet" :disabled="saving" @click="bulkReview('reverify')">Retry website checks</button><button class="quiet" :disabled="saving" @click="bulkReview('analyze')">Run intelligence & scoring</button><button class="quiet" :disabled="saving" @click="bulkReview('reject')">Reject selected</button><button class="primary" :disabled="saving" @click="bulkReview('accept')">Accept selected</button></div>
        <div v-if="selectedWebsiteLess.length" class="bulk-review-bar"><span>Find websites for {{ selectedWebsiteLess.length }} selected businesses. Bulk requests are capped at 25; each business uses bounded queries and source requests.</span><button class="primary" :disabled="saving || selectedWebsiteLess.length > 25" @click="bulkResolveWebsites">Find Websites</button></div>
        <div v-if="filteredCandidates.length" class="table-scroll"><table class="discovery-table"><thead><tr><th aria-label="Select"></th><th>Company</th><th>Website & location</th><th>Industry</th><th>Finding & service</th><th>Lead score</th><th>Contact</th><th>Source / status</th></tr></thead><tbody><tr v-for="candidate in filteredCandidates" :key="candidate.id" @click="openCandidate(candidate)"><td @click.stop><input type="checkbox" :checked="selected.includes(candidate.id)" aria-label="Select candidate" @change="toggleSelected(candidate.id)" /></td><td><b>{{ candidate.company_name || 'Name not provided' }}</b><small>{{ candidate.normalized_domain || (candidate.verification_state === 'not_required' ? 'Website not found' : 'Invalid domain') }}</small></td><td>{{ candidate.country || candidate.city ? [candidate.city, candidate.country].filter(Boolean).join(', ') : 'Location unknown' }}<small>{{ candidate.original_url || 'Website not found' }}</small></td><td>{{ candidate.industry || 'Unknown' }}</td><td>{{ candidate.recommended_service || candidate.page_title || candidate.analysis_reason || candidate.failure_summary || 'Website evidence pending' }}<small>{{ candidate.response_time_ms ? `${candidate.response_time_ms} ms first-page response` : candidate.meta_description || '' }}</small></td><td>{{ candidate.lead_score ?? 'Pending' }}</td><td>{{ candidate.contact_count ? `${candidate.contact_count} public contacts` : 'Not found yet' }}</td><td><small>{{ candidate.source_count > 1 ? 'Multiple Sources' : humanize(candidate.source) }}</small><span class="status-chip" :class="candidate.lifecycle_status">{{ humanize(candidate.verification_state === 'verified' ? candidate.lifecycle_status : candidate.verification_state) }}</span></td></tr></tbody></table></div><div v-else class="discovery-empty">{{ busy ? 'Loading candidates…' : 'No discovery candidates yet. Start a search or import a CSV.' }}</div>
        <div v-for="candidate in filteredCandidates.filter((item: any) => !item.normalized_domain && item.verification_state === 'not_required')" :key="`resolution-${candidate.id}`" class="resolution-row"><div><b>{{ candidate.company_name || 'Unnamed business' }}</b><span>{{ [candidate.city, candidate.country, candidate.industry].filter(Boolean).join(' · ') }}</span><small>Website not confirmed · {{ candidate.website_resolution ? humanize(candidate.website_resolution.state) : 'Not searched' }}</small></div><button v-if="!candidate.website_resolution || ['FAILED','UNRESOLVED','NO_CANDIDATES'].includes(candidate.website_resolution.state)" class="quiet" :disabled="saving" @click="resolveWebsite(candidate)">{{ candidate.website_resolution ? 'Retry Find Website' : 'Find Website' }}</button><button v-else class="quiet" @click="openCandidate(candidate)">Review candidates</button></div>
      </div>
    </section>
    <div v-if="notice" class="notice success-notice">{{ notice }}</div><div v-if="error" class="notice">{{ error }} <button class="quiet" @click="loadDiscovery">Retry</button></div>
    <div class="prospect-list-heading"><div><h2>Your prospects</h2><p>Companies accepted into the tenant workspace.</p></div><span>{{ companies.length }} shown</span></div>
    <div class="panel prospect-list-panel"><div v-if="busy && !companies.length" class="prospect-state">Loading prospects…</div><div v-else-if="!companies.length" class="prospect-state"><b>No prospects yet</b><span>Use Find Prospects to search or import a company list.</span></div><div v-else class="table-scroll"><table class="discovery-table"><thead><tr><th>Company</th><th>Website</th><th>Location</th><th>Industry</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="company in companies" :key="company.id" @click="openProspect(company)"><td><b>{{ company.name }}</b></td><td>{{ company.normalized_domain || company.websites?.[0]?.host || '—' }}</td><td>{{ company.location || '—' }}</td><td>{{ company.industry || '—' }}</td><td><span class="status-chip accepted">{{ humanize(company.status) }}</span></td><td><button class="quiet" @click.stop="openProspect(company)">Open Prospect 360 →</button></td></tr></tbody></table></div></div>
    <div v-if="candidateDetail" class="candidate-backdrop" @click.self="candidateDetail = null"><aside class="candidate-drawer" aria-label="Candidate details"><button class="drawer-close" @click="candidateDetail = null">Close</button><span class="eyebrow">DISCOVERY CANDIDATE</span><h2>{{ candidateDetail.candidate.company_name || 'Company candidate' }}</h2><a v-if="candidateDetail.candidate.original_url" :href="candidateDetail.candidate.original_url" target="_blank" rel="noreferrer">{{ candidateDetail.candidate.original_url }}</a><p v-else class="discovery-safety-note">Website not found. No website was invented and no crawl was attempted.</p><button v-if="!candidateDetail.website_resolution && candidateDetail.candidate.verification_state === 'not_required'" class="primary" :disabled="saving" @click="resolveWebsite(candidateDetail.candidate)">Find Website</button><section v-if="candidateDetail.website_resolution" class="candidate-evidence resolution-detail"><h3>Website discovery · {{ humanize(candidateDetail.website_resolution.resolution.discovery_status || candidateDetail.website_resolution.resolution.state) }}</h3><p>{{ candidateDetail.website_resolution.resolution.failure_summary || 'Candidate domains are suggestions only until the existing checks and review policy accept them.' }}</p><article v-for="candidate in candidateDetail.website_resolution.candidates" :key="candidate.id"><b>{{ candidate.normalized_domain }}</b><small>Discovery rank #{{ candidate.discovery_rank || '—' }} · discovery signal {{ candidate.discovery_score ?? '—' }}/100 · identity confidence {{ candidate.score }}/100 ({{ candidate.confidence_band }})</small><a :href="candidate.candidate_url" target="_blank" rel="noreferrer">{{ candidate.candidate_url }}</a><small>{{ humanize(candidate.result_type) }} · {{ humanize(candidate.status) }}</small><small>{{ candidate.match_summary?.discovery?.source_count || 0 }} independent source(s)</small><p v-for="item in candidate.evidence" :key="item.id">{{ item.polarity === 'negative' ? 'Conflict' : 'Evidence' }} · {{ item.summary }} ({{ item.points > 0 ? '+' : '' }}{{ item.points }})</p><div v-if="candidate.status === 'proposed'" class="drawer-actions"><button class="quiet" :disabled="saving" @click="reviewWebsiteResolution('reject', candidate)">Reject candidate</button><button class="primary" :disabled="saving" @click="reviewWebsiteResolution('confirm', candidate)">Confirm Official Website</button></div></article><details v-if="candidateDetail.website_resolution.search_results?.length"><summary class="source-evidence-summary">Inspect source evidence</summary><article v-for="(result, index) in candidateDetail.website_resolution.search_results" :key="result.id || index" class="source-evidence-row"><b>{{ result.title || result.source }}</b><small>{{ humanize(result.source) }} · {{ humanize(result.result_type) }} · source rank {{ result.source_rank || '—' }}</small><p>{{ result.snippet || 'No excerpt provided by this source.' }}</p><small v-if="result.result_url" class="untrusted-source-url">Source URL: {{ result.result_url }}</small><small v-if="result.target_url && result.target_url !== result.result_url" class="untrusted-source-url">Linked target: {{ result.target_url }}</small><details v-if="result.query_text"><summary>Query provenance</summary><small>{{ result.query_text }}</small></details></article></details><div class="drawer-actions"><button v-if="!['RESOLVED','UNRESOLVED'].includes(candidateDetail.website_resolution.resolution.state)" class="quiet" :disabled="saving" @click="reviewWebsiteResolution('mark_unresolved')">Mark Unresolved</button><button v-if="['FAILED','UNRESOLVED','AMBIGUOUS','NO_CANDIDATES'].includes(candidateDetail.website_resolution.resolution.state)" class="quiet" :disabled="saving" @click="retryWebsiteResolution">Retry Discovery</button></div></section><dl><dt>Normalized domain</dt><dd>{{ candidateDetail.candidate.normalized_domain || 'Website not found' }}</dd><dt>Location / industry</dt><dd>{{ [candidateDetail.candidate.city, candidateDetail.candidate.country].filter(Boolean).join(', ') || 'Unknown' }} · {{ candidateDetail.candidate.industry || 'Industry unknown' }}</dd><dt>Discovered</dt><dd>{{ candidateDetail.candidate.discovered_at }}</dd><dt>Source provenance</dt><dd><div v-for="source in candidateDetail.sources || []" :key="source.id">{{ humanize(source.source) }} · {{ source.source_reference }} · {{ source.discovered_at }}<small v-if="source.source_metadata?.osm_type" class="discovery-source-metadata">OSM {{ source.source_metadata.osm_type }}/{{ source.source_metadata.osm_id }} · {{ source.source_metadata.lat }}, {{ source.source_metadata.lon }} <a :href="`https://www.openstreetmap.org/${source.source_metadata.osm_type}/${source.source_metadata.osm_id}`" target="_blank" rel="noreferrer">View map record</a><span v-if="Object.keys(source.source_metadata.public_contact || {}).length"> · Public OSM contact fields: {{ JSON.stringify(source.source_metadata.public_contact) }}</span></small></div><small v-if="!candidateDetail.sources?.length">{{ humanize(candidateDetail.candidate.source) }}</small><small v-if="hasOsmSource()">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap contributors</a> · Open Database License</small></dd><dt>Duplicate result</dt><dd>{{ humanize(candidateDetail.candidate.deduplication_state) }} · {{ candidateDetail.candidate.deduplication_reason || 'No duplicate found' }}</dd><dt>Website verification</dt><dd>{{ humanize(candidateDetail.candidate.verification_state) }} · HTTP {{ candidateDetail.candidate.http_status || '—' }}</dd><dt>Analysis eligibility</dt><dd>{{ candidateDetail.candidate.analysis_status === 'budget_reached' ? 'Analysis budget reached' : candidateDetail.candidate.analysis_reason || humanize(candidateDetail.candidate.analysis_status) }}</dd><dt>Website title</dt><dd>{{ candidateDetail.candidate.page_title || 'Not available' }}</dd><dt>Mobile viewport</dt><dd>{{ candidateDetail.candidate.has_mobile_viewport === null ? 'Unknown' : candidateDetail.candidate.has_mobile_viewport ? 'Present' : 'Not found' }}</dd><dt>HTTPS</dt><dd>{{ candidateDetail.candidate.uses_https ? 'Yes' : 'No' }}</dd><dt>Recommended service</dt><dd>{{ candidateDetail.candidate.recommended_service || 'No evidence-backed match yet' }}</dd><dt>Recommendation evidence</dt><dd>{{ showEvidence(candidateDetail.candidate.recommendation_evidence) || candidateDetail.candidate.failure_summary || 'No finding recorded' }}</dd><dt>Public contacts</dt><dd>{{ candidateDetail.contacts?.length ? `${candidateDetail.contacts.length} public contacts with source provenance` : 'No public contact recorded' }}</dd><dt>Website intelligence</dt><dd>{{ candidateDetail.candidate.lifecycle_status === 'analyzed' || candidateDetail.candidate.lifecycle_status === 'reviewable' || candidateDetail.candidate.lifecycle_status === 'accepted' ? 'Complete' : candidateDetail.candidate.lifecycle_status === 'analysis_failed' ? 'Needs retry / review' : 'Pending' }}</dd><dt>Lead scoring</dt><dd>{{ candidateDetail.scores?.[0]?.score != null ? `Complete · ${candidateDetail.scores[0].score} / 100` : 'Pending' }}</dd><dt>Review readiness</dt><dd>{{ candidateDetail.candidate.lifecycle_status === 'reviewable' ? 'Ready for human review' : candidateDetail.candidate.lifecycle_status === 'accepted' ? 'Accepted' : candidateDetail.candidate.failure_summary || 'Not ready — processing is incomplete' }}</dd></dl><section v-if="candidateDetail.scores?.[0]?.components" class="candidate-evidence"><h3>Score contributions</h3><article v-for="[rule, contribution] in scoreComponents(candidateDetail.scores[0].components)" :key="rule"><b>{{ humanize(rule) }} · {{ contribution.status === 'confirmed_present' ? `+${contribution.points}` : contribution.status === 'confirmed_absent' ? '0 · absent' : 'Unknown' }}</b><small v-if="contribution.evidence">Evidence: {{ showEvidence(contribution.evidence) }}</small></article></section><section v-if="candidateDetail.website_issues?.length" class="candidate-evidence"><h3>Website intelligence findings</h3><article v-for="issue in candidateDetail.website_issues" :key="issue.id"><b>{{ humanize(issue.type) }} · {{ humanize(issue.severity) }}</b><p>{{ issue.summary }}</p><small>{{ showEvidence(issue.evidence) }}</small></article></section><section v-if="candidateDetail.findings?.length" class="candidate-evidence"><h3>Lead insights</h3><article v-for="finding in candidateDetail.findings" :key="finding.id"><b>{{ humanize(finding.kind) }}</b><p>{{ finding.statement }}</p></article></section><div v-if="candidateDetail.contacts?.length" class="candidate-contact-list"><article v-for="contact in candidateDetail.contacts" :key="contact.id"><b>{{ contact.name || 'Public business contact' }}</b><small>{{ contact.title || 'Role not stated' }} · {{ contact.source_url }}</small><span v-for="method in contact.methods || []" :key="method.id">{{ humanize(method.classification || method.type) }}: {{ method.value }} <small>{{ method.source_url }} · {{ method.confidence }} confidence</small></span></article></div><div v-if="candidateDetail.company && candidateDetail.company.status !== 'discovery_candidate'"><button class="quiet" @click="openProspect(candidateDetail.company)">Open Prospect 360 →</button></div><div v-if="['verified', 'existing_company'].includes(candidateDetail.candidate.verification_state) || candidateDetail.candidate.deduplication_state === 'existing_company'" class="drawer-actions"><button class="quiet" :disabled="saving" @click="review(candidateDetail.candidate, 'reject')">Reject</button><button class="primary" :disabled="saving || (candidateDetail.candidate.deduplication_state !== 'existing_company' && candidateDetail.candidate.lifecycle_status !== 'reviewable')" @click="review(candidateDetail.candidate, 'accept')">Accept candidate</button></div><p class="discovery-safety-note">Acceptance adds a prospect only. Campaign enrollment and outbound messages are not created.</p></aside></div>
  </section>
</template>

<style scoped>
.prospects-workspace{display:grid;gap:18px;min-width:0}.prospects-toolbar{display:flex;gap:10px;align-items:center}.prospect-search{display:flex;gap:8px;align-items:center;flex:1;max-width:460px;border:1px solid #e1e6ee;border-radius:10px;background:#fff;padding:0 12px;color:#697386}.prospect-search input{width:100%;height:42px;border:0;outline:0}.discovery-shell,.discovery-card,.add-card{min-width:0;border:1px solid #e1e7f0;border-radius:14px;background:#fff;padding:18px}.discovery-shell{display:grid;gap:16px;background:#f9fbfe}.discovery-intro,.prospect-list-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.discovery-intro h2,.prospect-list-heading h2{margin:4px 0;font-size:18px;color:#1b2b45}.discovery-intro p,.prospect-list-heading p{margin:0;color:#68778b;font-size:12px;line-height:1.5;max-width:720px}.eyebrow{font-size:9px;font-weight:750;letter-spacing:.12em;color:#77859a}.discovery-safe-pill{flex:none;border-radius:999px;padding:7px 10px;background:#ecf6f1;color:#277b5b;font-size:10px;font-weight:700}.discovery-tabs{display:flex;gap:6px;border-bottom:1px solid #e2e8f0;overflow:auto}.discovery-tabs button{border:0;background:transparent;padding:10px 12px;color:#68778b;white-space:nowrap}.discovery-tabs button.active{color:#2f4f95;border-bottom:2px solid #435fa9;font-weight:700}.discovery-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.discovery-form-grid label,.candidate-filters label{display:grid;gap:5px;font-size:11px;font-weight:650;color:#59687b;min-width:0}.discovery-form-grid input,.discovery-form-grid select,.candidate-filters select{width:100%;min-width:0;height:39px;border:1px solid #dfe5ed;border-radius:8px;padding:0 10px;background:#fff;color:#24334a}.discovery-form-grid small{font-size:10px;color:#8a95a4;font-weight:400}.wide-field{grid-column:1/-1}.discovery-actions,.csv-upload-row,.csv-summary,.bulk-review-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}.discovery-actions span{flex:1;min-width:210px;color:#758197;font-size:10px}.csv-card p{font-size:12px;line-height:1.5;color:#68778b}.csv-upload-row input{max-width:100%}.csv-summary span,.csv-summary b{padding:7px 10px;border-radius:8px;background:#f1f4f8;font-size:11px}.table-scroll{max-width:100%;overflow-x:auto;border:1px solid #e5eaf1;border-radius:10px;background:#fff}.discovery-table{width:100%;min-width:850px;border-collapse:collapse;text-align:left;font-size:11px}.discovery-table th{background:#f6f8fb;color:#778398;font-size:9px;letter-spacing:.04em}.discovery-table th,.discovery-table td{padding:11px 10px;border-bottom:1px solid #edf0f4;vertical-align:middle}.discovery-table tbody tr{cursor:pointer}.discovery-table tbody tr:hover{background:#f8faff}.discovery-table td small{display:block;margin-top:4px;color:#8490a1;max-width:250px;overflow-wrap:anywhere}.status-chip{display:inline-flex;margin-top:4px;padding:4px 7px;border-radius:999px;background:#f0f2f6;color:#536174;font-size:9px;white-space:nowrap}.status-chip.verified,.status-chip.accepted,.status-chip.new{background:#eaf5ef;color:#247653}.status-chip.rejected,.status-chip.failed,.status-chip.invalid,.status-chip.unreachable,.status-chip.robots_denied{background:#fff0ed;color:#a84d3f}.candidate-filters{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.candidate-filters label{min-width:130px}.candidate-filters>span{margin-left:auto;color:#78859a;font-size:10px;padding-bottom:9px}.run-summary{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:13px;border:1px solid #e6ebf2;border-radius:11px;background:#fff}.run-summary h3{margin:4px 0;color:#263b5b;font-size:15px}.run-summary p{margin:0;color:#8490a1;font-size:10px}.run-stats{display:flex;gap:10px;flex-wrap:wrap}.run-stats span{display:grid;gap:3px;text-align:center;color:#758196;font-size:9px}.run-stats b{font-size:15px;color:#263b5b}.bulk-review-bar{margin:0;padding:9px 12px;background:#edf2fb;border-radius:9px}.bulk-review-bar span{flex:1;font-size:11px}.discovery-empty,.prospect-state{padding:36px 14px;text-align:center;color:#7c8899;font-size:12px}.prospect-state{display:grid;gap:8px}.prospect-state b{color:#243650}.prospect-list-heading{align-items:center}.prospect-list-heading>span{font-size:10px;color:#7c8899}.prospect-list-panel{overflow:hidden}.candidate-backdrop{position:fixed;inset:0;z-index:50;display:flex;justify-content:flex-end;background:#15223866}.candidate-drawer{width:min(520px,100vw);height:100%;overflow:auto;background:#fff;padding:24px;box-shadow:-12px 0 35px #16243a22}.candidate-drawer h2{margin:10px 0 5px;color:#20324c}.candidate-drawer>a{color:#405fa6;font-size:12px;overflow-wrap:anywhere}.drawer-close{float:right;border:0;background:#eff2f6;border-radius:7px;padding:7px 10px;color:#59687b}.candidate-drawer dl{display:grid;grid-template-columns:minmax(120px,.8fr) minmax(0,1.2fr);gap:8px 12px;margin:22px 0;font-size:11px}.candidate-drawer dt{color:#7a8798}.candidate-drawer dd{margin:0;color:#33435b;overflow-wrap:anywhere}.drawer-actions{display:flex;gap:9px;margin-top:16px}.discovery-safety-note{padding:10px;border-radius:8px;background:#f5f7fa;color:#6e7a8d;font-size:10px;line-height:1.5}.prospect-list-heading h2{font-size:16px}.add-card h2{font-size:15px;color:#263b5b}.notice{overflow-wrap:anywhere}
.candidate-evidence{display:grid;gap:8px;margin:14px 0}.candidate-evidence h3{margin:0;color:#263b5b;font-size:13px}.candidate-evidence article{padding:10px;border:1px solid #e7ebf1;border-radius:8px}.candidate-evidence article b{font-size:11px}.candidate-evidence article p{margin:5px 0;color:#526176;font-size:11px}.candidate-evidence article small{color:#8490a1;font-size:10px;overflow-wrap:anywhere}.candidate-drawer .discovery-source-metadata{display:block;margin-top:4px;line-height:1.5}.resolution-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid #e5eaf1;border-radius:10px;background:#fff}.resolution-row>div{display:grid;gap:4px;min-width:0}.resolution-row span,.resolution-row small{font-size:11px;color:#68778b;overflow-wrap:anywhere}.resolution-detail>article>a{display:block;overflow-wrap:anywhere;color:#405fa6;font-size:11px}.resolution-detail>article>p{font-size:10px;color:#59687b}
@media(max-width:760px){.prospects-toolbar{flex-wrap:wrap}.prospect-search{flex-basis:100%;max-width:none}.discovery-shell,.discovery-card,.add-card{padding:13px}.discovery-intro{display:grid}.discovery-safe-pill{justify-self:start}.discovery-form-grid{grid-template-columns:1fr}.wide-field{grid-column:auto}.discovery-actions{align-items:stretch}.discovery-actions button{width:100%}.run-summary{align-items:flex-start;flex-direction:column}.run-stats{width:100%;justify-content:space-between}.candidate-filters label{flex:1}.candidate-filters>span{margin-left:0}.candidate-drawer{padding:17px}.candidate-drawer dl{grid-template-columns:1fr;gap:4px}.candidate-drawer dd{margin-bottom:8px}.drawer-actions{position:sticky;bottom:0;background:#fff;padding:10px 0}.csv-upload-row>*{width:100%;max-width:none}.prospect-list-heading{align-items:flex-start}}
</style>

<style scoped>
@media(max-width:900px){
  .prospects-workspace,.discovery-shell,.discovery-card,.discovery-results{min-width:0;max-width:100%}
  .discovery-intro{display:grid}
  .discovery-safe-pill{justify-self:start}
  .run-summary{align-items:flex-start;flex-direction:column}
  .candidate-filters label{flex:1 1 140px}
}
</style>
