<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { csrfToken, safeRequestError, salesRequest, unwrap } from '../salesApi'

const props = defineProps<{ tenantId: string; manager: boolean }>()
const emit = defineEmits<{ openProspect: [company: any] }>()
const sources = ref<any[]>([])
const campaigns = ref<any[]>([])
const candidates = ref<any[]>([])
const campaignProgress = ref<any>(null)
const sourceReadiness = ref<any>(null)
const selectedCampaign = ref('')
const selectedSource = ref('')
const selectedLiveSource = ref('')
const sourceType = ref<'brave_search' | 'csv_upload'>('brave_search')
const file = ref<File | null>(null)
const sourceDraft = ref({ provider_name: 'Brave Search API', license_or_authorization: '', storage_rights_reference: '', storage_rights_confirmed: false, allowed_collection_method: 'documented_search_api', retention_policy: '', attribution_requirement: 'Brave Search API result provenance', enabled: false })
const campaignDraft = ref({ name: 'India E-commerce & Retail Discovery', industry: 'E-commerce & Retail', country_code: 'IN', region: '', city: '', target_leads: 25 })
const busy = ref(false)
const error = ref('')
const notice = ref('')
const detail = ref<any>(null)
const activeCampaign = computed(() => campaigns.value.find((item) => item.id === selectedCampaign.value))
const metrics = computed(() => ({
  discovered: campaignProgress.value?.counts?.discovered ?? candidates.value.length,
  verified: campaignProgress.value?.counts?.verified ?? candidates.value.filter((item) => item.official_domain_status === 'verified').length,
  eligible: campaignProgress.value?.counts?.eligible ?? candidates.value.filter((item) => item.eligibility_status === 'eligible').length,
  duplicates: campaignProgress.value?.counts?.duplicates ?? candidates.value.filter((item) => item.processing_status === 'duplicate').length,
  invalid: campaignProgress.value?.counts?.invalid ?? candidates.value.filter((item) => item.processing_status === 'invalid').length,
  enriched: campaignProgress.value?.contacts_enriched ?? 0,
  promoted: campaignProgress.value?.counts?.promoted ?? candidates.value.filter((item) => item.promotion_status === 'promoted').length,
  errors: (campaignProgress.value?.source_errors ?? 0) + (campaignProgress.value?.counts?.processing_errors ?? candidates.value.filter((item) => item.processing_status === 'failed').length),
  running: activeCampaign.value?.status ?? 'No campaign',
}))
const liveSources = computed(() => sources.value.filter((item) => item.source_key === 'brave_search' && item.execution_ready))
const csvSources = computed(() => sources.value.filter((item) => item.source_key === 'csv_upload' && item.enabled && item.authorization_status === 'authorized'))
const sourceRequests = computed(() => (campaignProgress.value?.runs ?? []).reduce((sum: number, run: any) => sum + Number(run.source_requests || 0), 0))
const rawResults = computed(() => (campaignProgress.value?.runs ?? []).reduce((sum: number, run: any) => sum + Number(run.raw_candidates || 0), 0))
let pollTimer: ReturnType<typeof setInterval> | undefined

async function load() {
  try {
    const [sourceResponse, campaignResponse] = await Promise.all([
      salesRequest('/lead-discovery/sources', props.tenantId), salesRequest('/lead-discovery/campaigns', props.tenantId),
    ])
    sources.value = unwrap(sourceResponse); campaigns.value = unwrap(campaignResponse)
    sourceReadiness.value = await salesRequest('/lead-discovery/source-health', props.tenantId).catch(() => null)
    if (!selectedLiveSource.value && liveSources.value.length) selectedLiveSource.value = liveSources.value[0].id
    if (!selectedCampaign.value && campaigns.value.length) selectedCampaign.value = campaigns.value[0].id
    const [candidateResponse, progressResponse] = selectedCampaign.value
      ? await Promise.all([
          salesRequest(`/lead-discovery/candidates?campaign_id=${encodeURIComponent(selectedCampaign.value)}`, props.tenantId),
          salesRequest(`/lead-discovery/campaigns/${encodeURIComponent(selectedCampaign.value)}`, props.tenantId),
        ])
      : [[], null]
    candidates.value = unwrap(candidateResponse); campaignProgress.value = progressResponse
    if (['queued', 'running', 'paused'].includes(activeCampaign.value?.status) || candidates.value.some((item) => ['discovered', 'validating'].includes(item.processing_status))) {
      if (!pollTimer) pollTimer = setInterval(() => { void load() }, 5000)
    } else if (pollTimer) { clearInterval(pollTimer); pollTimer = undefined }
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to load lead discovery.' }
}

async function createSource() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const isLive = sourceType.value === 'brave_search'
    const source = await salesRequest('/lead-discovery/sources', props.tenantId, 'POST', {
      source_key: sourceType.value, ...sourceDraft.value,
      provider_name: isLive ? 'Brave Search API' : sourceDraft.value.provider_name,
      allowed_collection_method: isLive ? 'documented_search_api' : 'licensed_csv_upload',
    })
    notice.value = isLive ? (source.execution_ready ? 'Live source is fully approved. Promotion remains manual.' : `Live source saved but disabled: ${sourceStatusMessage(source.readiness_reason_code)}.`) : 'Authorized CSV source configured as a manual fallback.'
    sourceDraft.value.license_or_authorization = ''; sourceDraft.value.storage_rights_reference = ''; sourceDraft.value.storage_rights_confirmed = false
    sources.value.unshift(source); selectedSource.value = source.id; selectedLiveSource.value = source.execution_ready ? source.id : ''
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to configure source.' }
  finally { busy.value = false }
}

async function createCampaign() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    if (!selectedLiveSource.value) { error.value = 'Choose an enabled, authorized live source before creating an automatic discovery campaign.'; return }
    const campaign = await salesRequest('/lead-discovery/campaigns', props.tenantId, 'POST', { ...campaignDraft.value, source_ids: [selectedLiveSource.value] })
    campaigns.value.unshift(campaign); selectedCampaign.value = campaign.id; notice.value = 'Campaign created. Auto-promotion is off.'; await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to create campaign.' }
  finally { busy.value = false }
}

async function startDiscovery() {
  if (!selectedCampaign.value || !selectedLiveSource.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest(`/lead-discovery/campaigns/${encodeURIComponent(selectedCampaign.value)}/start`, props.tenantId, 'POST', { source_ids: [selectedLiveSource.value] })
    notice.value = `Discovery ${result.status}. Businesses will be staged for website verification; no companies or sales actions are created automatically.`
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to start discovery.' }
  finally { busy.value = false }
}

function rawMetadata(candidate: any): any {
  if (typeof candidate?.raw_metadata === 'string') { try { return JSON.parse(candidate.raw_metadata) } catch { return {} } }
  return candidate?.raw_metadata ?? {}
}

function sourceStatusMessage(code: string | undefined): string {
  return ({
    SOURCE_DISABLED: 'Live discovery is disabled in server configuration.',
    SOURCE_CREDENTIAL_MISSING: 'The server search credential is not configured.',
    SOURCE_NOT_AUTHORIZED: 'Source authorization has not been approved for this tenant.',
    STORAGE_RIGHTS_NOT_CONFIRMED: 'Persistent result-storage rights are not explicitly confirmed.',
    SOURCE_AUTH_FAILED: 'The provider rejected its server credential.',
    SOURCE_RATE_LIMITED: 'The provider rate limit was reached; the run will pause safely.',
    SOURCE_QUOTA_EXHAUSTED: 'The provider quota is exhausted.',
    SOURCE_TIMEOUT: 'The provider did not respond before the request timeout.',
    SOURCE_RESPONSE_INVALID: 'The provider response could not be processed safely.',
  } as Record<string, string>)[code ?? ''] ?? 'Provider status is unavailable.'
}

async function importCsv() {
  if (!file.value || !selectedCampaign.value || !selectedSource.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const form = new FormData(); form.append('csv', file.value); form.append('source_id', selectedSource.value); form.append('idempotency_key', crypto.randomUUID())
    const response = await fetch(`/api/v1/lead-discovery/campaigns/${encodeURIComponent(selectedCampaign.value)}/import`, { method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-Tenant-ID': props.tenantId, 'X-XSRF-TOKEN': csrfToken() }, body: form })
    const result = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(safeRequestError(response, result, 'Unable to import this CSV.'))
    notice.value = `${result.candidate_count} CSV rows staged; ${result.verification_queued} safe website checks queued. No companies were created and no outreach was sent.`
    file.value = null; await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to import this CSV.' }
  finally { busy.value = false }
}

async function openCandidate(candidate: any) {
  try { detail.value = await salesRequest(`/lead-discovery/candidates/${encodeURIComponent(candidate.id)}`, props.tenantId) }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to open candidate.' }
}

async function promote(candidate: any) {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest(`/lead-discovery/candidates/${encodeURIComponent(candidate.id)}/promote`, props.tenantId, 'POST', {})
    notice.value = `Candidate promoted to the existing prospect workspace${result.replayed ? '' : '; Website Intelligence and scoring queued'}. No outreach was sent.`
    detail.value = null; await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to promote candidate.' }
  finally { busy.value = false }
}

async function reject(candidate: any) {
  const reason = window.prompt('Reason for rejecting this candidate?')?.trim()
  if (!reason) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await salesRequest(`/lead-discovery/candidates/${encodeURIComponent(candidate.id)}/reject`, props.tenantId, 'POST', { reason })
    notice.value = 'Candidate rejected and retained in the audit trail.'; detail.value = null; await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to reject candidate.' }
  finally { busy.value = false }
}

async function controlCampaign(operation: 'pause' | 'resume' | 'cancel') {
  if (!selectedCampaign.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest(`/lead-discovery/campaigns/${encodeURIComponent(selectedCampaign.value)}/${operation}`, props.tenantId, 'POST', {})
    notice.value = `Campaign ${result.status}.`; await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to update campaign.' }
  finally { busy.value = false }
}

function setFile(event: Event) { file.value = (event.target as HTMLInputElement).files?.[0] ?? null }
onMounted(() => { void load() })
onBeforeUnmount(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
  <section class="lead-discovery">
    <header class="lead-discovery-heading"><div><span class="eyebrow">LIVE PROSPECT DISCOVERY</span><h2>Lead Discovery</h2><p>Find public business candidates, verify their official websites, and review each candidate before promotion.</p></div><span class="safe-tag">Manual promotion · No outreach</span></header>
    <p v-if="notice" class="notice success-notice" role="status">{{ notice }}</p><p v-if="error" class="notice" role="alert">{{ error }}</p>

    <div v-if="manager" class="lead-discovery-grid">
      <form class="panel lead-card" @submit.prevent="createSource"><h3>Live source authorization</h3><p class="source-required">Brave remains unavailable until each gate is independently satisfied. Credentials are held only in server configuration; credential presence does not grant authorization or storage rights.</p>
        <div class="source-gates" aria-label="Live source readiness"><span>Credential <b>{{ sourceReadiness?.credential_status || 'unknown' }}</b></span><span>Tenant authorization <b>{{ sourceReadiness?.authorization_status || 'not approved' }}</b></span><span>Storage rights <b>{{ sourceReadiness?.storage_rights_status || 'not confirmed' }}</b></span><span>Provider health <b>{{ sourceReadiness?.health_status || 'blocked' }}</b></span></div>
        <label>Source adapter<select v-model="sourceType"><option value="brave_search">Brave Search API</option><option value="csv_upload">Manual CSV fallback</option></select></label>
        <label v-if="sourceType === 'csv_upload'">Provider / dataset<input v-model="sourceDraft.provider_name" required maxlength="160"></label>
        <label>Plan / permission basis<textarea v-model="sourceDraft.license_or_authorization" required maxlength="500" rows="2" placeholder="Describe the provider plan and commercial authorization"></textarea></label>
        <template v-if="sourceType === 'brave_search'"><label>Storage-rights agreement reference<input v-model="sourceDraft.storage_rights_reference" required maxlength="255" placeholder="Order form or plan clause reference"></label><label class="check"><input v-model="sourceDraft.storage_rights_confirmed" type="checkbox"> I confirmed the active provider agreement explicitly permits storing search-result data for this workflow</label><p class="terms-note">Standard Brave Search API terms restrict storing or creating a database of search results. Do not enable this source without explicit storage rights in the applicable agreement.</p></template>
        <label>Retention restrictions<input v-model="sourceDraft.retention_policy" maxlength="120"></label>
        <label>Attribution requirements<input v-model="sourceDraft.attribution_requirement" maxlength="255"></label>
        <label class="check"><input v-model="sourceDraft.enabled" type="checkbox"> Enable after permission is documented</label>
        <button class="primary" :disabled="busy">Save source configuration</button>
      </form>
      <form class="panel lead-card" @submit.prevent="createCampaign"><h3>Create a discovery campaign</h3><p>Start with a small verified-lead target. A 5,000-lead run is never started as a pilot.</p>
        <label>Campaign name<input v-model="campaignDraft.name" required maxlength="160"></label><label>Target industry<input v-model="campaignDraft.industry" required maxlength="150"></label>
        <div class="two-col"><label>Country code<input v-model="campaignDraft.country_code" required minlength="2" maxlength="2"></label><label>City<input v-model="campaignDraft.city" maxlength="120"></label></div>
        <label>State / region<input v-model="campaignDraft.region" maxlength="120"></label><label>Eligible-company target<input v-model.number="campaignDraft.target_leads" type="number" min="1" max="5000"></label>
        <label>Authorized live source<select v-model="selectedLiveSource" required><option value="">Choose source</option><option v-for="source in liveSources" :key="source.id" :value="source.id">{{ source.provider_name }}</option></select></label>
        <div class="locked-config">Small default target · bounded source requests · safe website verification · public business contacts only · manual promotion · no outreach</div>
        <button class="primary" :disabled="busy">Create campaign</button>
      </form>
    </div>

    <section v-if="manager" class="panel start-discovery"><div><span class="eyebrow">AUTOMATIC WORKFLOW</span><h3>Start Discovery</h3><p>Select a draft campaign and an approved live source. Search results are candidates only; Pulam verifies websites before eligibility, and promotion always stays with a person.</p></div><div class="start-controls"><label>Campaign<select v-model="selectedCampaign"><option value="">Choose campaign</option><option v-for="campaign in campaigns.filter((item) => item.status === 'draft')" :key="campaign.id" :value="campaign.id">{{ campaign.name }}</option></select></label><label>Live source<select v-model="selectedLiveSource"><option value="">Choose source</option><option v-for="source in liveSources" :key="source.id" :value="source.id">{{ source.provider_name }}</option></select></label><button class="primary" :disabled="busy || !selectedCampaign || !selectedLiveSource" @click="startDiscovery">{{ busy ? 'Starting…' : 'Start Discovery' }}</button></div></section>

    <section class="metric-grid" aria-label="Lead discovery progress"><article><small>CANDIDATES</small><b>{{ metrics.discovered }}</b></article><article><small>WEBSITE VERIFIED</small><b>{{ metrics.verified }}</b></article><article><small>ELIGIBLE</small><b>{{ metrics.eligible }}</b></article><article><small>DUPLICATES</small><b>{{ metrics.duplicates }}</b></article><article><small>INVALID</small><b>{{ metrics.invalid }}</b></article><article><small>CONTACTS ENRICHED</small><b>{{ metrics.enriched }}</b></article><article><small>PROMOTED</small><b>{{ metrics.promoted }}</b></article><article><small>ERRORS</small><b>{{ metrics.errors }}</b></article><article><small>CAMPAIGN STATUS</small><b>{{ metrics.running }}</b></article></section>

    <section class="panel source-list"><div class="panel-title"><div><h3>Source health</h3><p>Credential availability, tenant authorization, storage-retention rights and operational health are checked separately.</p></div></div><article v-for="source in sources" :key="source.id"><div><b>{{ source.provider_name }}</b><small>{{ source.source_type }} · {{ source.allowed_collection_method }}</small><small>Credential: {{ source.credential_status }} · Authorization: {{ source.authorization_status }} · Storage rights: {{ source.storage_rights_status }} · Health: {{ source.health_state }}</small><small>{{ source.authorization_summary || 'No authorization summary recorded' }}</small></div><span :class="['state', source.execution_ready ? 'eligible' : 'needs_review']">{{ source.execution_ready ? 'ready' : sourceStatusMessage(source.readiness_reason_code) }}</span><small v-if="source.last_error_code">{{ sourceStatusMessage(source.last_error_code) }}</small></article><p v-if="!sources.length" class="source-required">Brave readiness · credential {{ sourceReadiness?.credential_status || 'unknown' }} · authorization {{ sourceReadiness?.authorization_status || 'not approved' }} · storage rights {{ sourceReadiness?.storage_rights_status || 'not confirmed' }} · health {{ sourceReadiness?.health_status || 'blocked' }}. {{ sourceStatusMessage(sourceReadiness?.status) }}</p></section>

    <details class="panel lead-import advanced-import"><summary>Manual / advanced import · CSV fallback</summary><div class="panel-title"><div><h3>Import a licensed CSV</h3><p>Required columns: company_name, website. Optional: industry, country_code, region, city, source_url, business_email, business_phone, source_reference.</p></div></div>
      <div class="import-controls"><label>Campaign<select v-model="selectedCampaign" @change="load"><option value="">Choose campaign</option><option v-for="campaign in campaigns" :key="campaign.id" :value="campaign.id">{{ campaign.name }}</option></select></label>
        <label>Authorized source<select v-model="selectedSource"><option value="">Choose source</option><option v-for="source in csvSources" :key="source.id" :value="source.id">{{ source.provider_name }}</option></select></label>
        <label>CSV file<input type="file" accept=".csv,text/csv" @change="setFile"></label><button class="primary" :disabled="busy || !file || !selectedSource || !selectedCampaign" @click="importCsv">{{ busy ? 'Processing…' : 'Stage and verify candidates' }}</button>
      </div><p v-if="!csvSources.length" class="source-required">CSV fallback is unavailable until an owner authorizes a licensed dataset.</p>
    </details>

    <section class="panel lead-candidates"><div class="panel-title"><div><h3>Candidate workbench</h3><p>{{ activeCampaign?.name || 'All campaigns' }} · staging records stay separate from prospects until manual promotion</p></div><div class="campaign-actions"><button v-if="activeCampaign?.status === 'running'" class="quiet" :disabled="busy || !manager" @click="controlCampaign('pause')">Pause</button><button v-if="activeCampaign?.status === 'paused'" class="quiet" :disabled="busy || !manager" @click="controlCampaign('resume')">Resume</button><button v-if="['running','paused','draft'].includes(activeCampaign?.status)" class="quiet" :disabled="busy || !manager" @click="controlCampaign('cancel')">Cancel</button><button class="quiet" @click="load">Refresh</button></div></div>
      <div class="campaign-progress"><span>Source requests <b>{{ sourceRequests }}</b></span><span>Raw results <b>{{ rawResults }}</b></span><span>Verified websites <b>{{ metrics.verified }}</b></span><span>Eligible leads <b>{{ metrics.eligible }} / {{ activeCampaign?.target_leads || '—' }}</b></span></div>
      <div v-if="campaignProgress?.checkpoints?.length" class="query-progress"><span v-for="checkpoint in campaignProgress.checkpoints" :key="checkpoint.id">{{ checkpoint.query_key }} · {{ checkpoint.status }}</span></div>
      <div class="candidate-table-wrap"><table><thead><tr><th>Business</th><th>Website</th><th>Discovery provenance</th><th>Eligibility</th><th>Verification</th><th>Promotion</th><th>Action</th></tr></thead><tbody>
        <tr v-for="candidate in candidates" :key="candidate.id"><td><button class="text-action" @click="openCandidate(candidate)">{{ candidate.company_name }}</button><small>{{ sources.find((item) => item.id === candidate.source_id)?.provider_name || candidate.source_id }}</small></td><td>{{ candidate.normalized_domain || 'Unverified / invalid' }}</td><td>{{ rawMetadata(candidate).query || 'Manual CSV import' }}<small>{{ rawMetadata(candidate).rank ? `Result #${rawMetadata(candidate).rank}` : '' }}</small></td><td><span :class="['state', candidate.eligibility_status]">{{ candidate.eligibility_status.replaceAll('_',' ') }}</span><small>{{ candidate.validation_reason || candidate.validation_reason_code || 'Verification pending' }}</small></td><td>{{ candidate.official_domain_status.replaceAll('_',' ') }}</td><td>{{ candidate.promotion_status.replaceAll('_',' ') }}</td><td><button v-if="candidate.eligibility_status === 'eligible' || (candidate.matched_company_id && candidate.official_domain_status === 'verified')" class="quiet" :disabled="busy || !manager" @click="promote(candidate)">{{ candidate.matched_company_id ? 'Review & reuse' : 'Promote' }}</button></td></tr>
        <tr v-if="!candidates.length"><td colspan="7" class="empty">No candidates yet. Configure an authorized live source, create a campaign, and start discovery. CSV remains in manual/advanced import.</td></tr>
      </tbody></table></div>
    </section>

    <div v-if="detail" class="candidate-modal" @click.self="detail = null"><section class="candidate-detail panel"><button class="quiet close" @click="detail = null">Close</button><span class="eyebrow">CANDIDATE PROVENANCE</span><h3>{{ detail.candidate.company_name }}</h3><p>{{ detail.candidate.validation_reason || 'Candidate review details' }}</p><dl><dt>Campaign</dt><dd>{{ detail.campaign?.name }}</dd><dt>Website</dt><dd>{{ detail.candidate.claimed_website || 'Not provided' }} · {{ detail.candidate.official_domain_status }}</dd><dt>Industry / geography</dt><dd>{{ detail.candidate.industry || 'Unknown' }} · {{ detail.candidate.location || 'Unknown' }}</dd><dt>Eligibility</dt><dd>{{ detail.candidate.eligibility_status }} · {{ detail.candidate.validation_reason_code || 'Pending' }}</dd><dt>Source</dt><dd>{{ detail.source?.provider_name }} · {{ detail.source?.authorization_status }} · {{ detail.source?.authorization_summary }}</dd></dl><h4>Evidence and provenance</h4><article v-for="item in detail.evidence" :key="item.id" class="evidence-row"><b>{{ item.field_name.replaceAll('_',' ') }}</b><span>{{ item.normalized_value || item.raw_value }}</span><small>{{ item.evidence_type }} · {{ item.confidence_score }} confidence · {{ item.collected_at }}</small><small>{{ item.source_url || 'CSV row provenance' }} · {{ item.attribution }}</small></article><div v-if="detail.matched_company" class="match-card"><b>Existing company match</b><span>{{ detail.matched_company.name }} · {{ detail.matched_company.normalized_domain }}</span><button class="quiet" @click="emit('openProspect', detail.matched_company)">Open Prospect 360</button></div><div class="detail-actions"><button v-if="detail.candidate.eligibility_status === 'eligible' || (detail.candidate.matched_company_id && detail.candidate.official_domain_status === 'verified')" class="primary" :disabled="busy || !manager" @click="promote(detail.candidate)">Promote to Prospect</button><button v-if="detail.candidate.promotion_status === 'not_promoted'" class="quiet" :disabled="busy || !manager" @click="reject(detail.candidate)">Reject candidate</button></div></section></div>
  </section>
</template>

<style scoped>
.lead-discovery{display:grid;gap:16px;min-width:0}.lead-discovery-heading,.panel-title{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.lead-discovery-heading h2{margin:3px 0;color:#1e2f49;font-size:21px}.lead-discovery-heading p,.lead-card p,.panel-title p,.start-discovery p{max-width:750px;margin:0;color:#728096;font-size:12px;line-height:1.5}.safe-tag{white-space:nowrap;padding:7px 10px;border-radius:99px;background:#eff5ff;color:#355b9c;font-size:10px;font-weight:700}.lead-discovery-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.panel{min-width:0;padding:16px;border:1px solid #e2e8f0;border-radius:13px;background:#fff}.lead-card{display:grid;gap:10px;align-content:start}.lead-card h3,.lead-import h3,.lead-candidates h3,.source-list h3,.start-discovery h3{margin:0;color:#283c5b;font-size:14px}.lead-card label,.import-controls label,.start-controls label{display:grid;gap:5px;color:#5e6d81;font-size:11px;font-weight:650}.lead-card input,.lead-card textarea,.lead-card select,.import-controls select,.import-controls input[type=file],.start-controls select{width:100%;min-width:0;border:1px solid #dce3ed;border-radius:8px;background:#fff;padding:9px;color:#273953;font:inherit}.lead-card .check{display:flex;align-items:center;gap:7px}.lead-card .check input{width:auto}.terms-note{padding:9px;background:#fff7e8;border-radius:8px;color:#845e24;font-size:10px;line-height:1.5}.two-col{display:grid;grid-template-columns:1fr 1fr;gap:10px}.locked-config,.source-required{padding:10px;border-radius:8px;background:#f5f7fa;color:#65738a;font-size:10px;line-height:1.5}.lead-import,.lead-candidates,.source-list{display:grid;gap:13px}.import-controls{display:grid;grid-template-columns:1fr 1fr 1.2fr auto;align-items:end;gap:10px}.import-controls button{white-space:nowrap}.campaign-actions,.detail-actions{display:flex;gap:6px;flex-wrap:wrap}.start-discovery{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,1.1fr);align-items:center;gap:20px;border-color:#cfddf7;background:linear-gradient(120deg,#f8fbff,#fff)}.start-discovery h3{margin:4px 0}.start-controls{display:grid;grid-template-columns:1fr 1fr auto;gap:9px;align-items:end}.start-controls button{white-space:nowrap}.metric-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px}.metric-grid article{display:grid;gap:5px;padding:11px;border:1px solid #e3e9f1;border-radius:9px;background:#fff}.metric-grid small{font-size:8px;letter-spacing:.05em;color:#8490a1}.metric-grid b{font-size:17px;color:#283c5b}.source-list article{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:10px;border:1px solid #e7ebf1;border-radius:8px}.source-list article div{display:grid;gap:4px;min-width:0}.source-list article b{font-size:11px}.source-list article small{font-size:10px;color:#77859a;overflow-wrap:anywhere}.campaign-progress{display:flex;flex-wrap:wrap;gap:8px}.campaign-progress span,.query-progress span{padding:7px 9px;border:1px solid #e5eaf1;border-radius:8px;color:#6f7c8e;font-size:10px}.campaign-progress b{color:#2b3e5a}.query-progress{display:flex;gap:5px;flex-wrap:wrap}.candidate-table-wrap{max-width:100%;overflow:auto;border:1px solid #e7ebf1;border-radius:9px}.candidate-table-wrap table{width:100%;min-width:850px;border-collapse:collapse;text-align:left;font-size:11px}.candidate-table-wrap th,.candidate-table-wrap td{padding:10px;border-bottom:1px solid #edf0f4;vertical-align:top}.candidate-table-wrap th{background:#f7f9fb;color:#728096;font-size:9px}.candidate-table-wrap td small{display:block;margin-top:4px;color:#8490a1;max-width:240px;overflow-wrap:anywhere}.text-action{border:0;padding:0;background:none;color:#315ca8;font:inherit;font-weight:700;text-align:left;cursor:pointer}.state{display:inline-flex;padding:4px 7px;border-radius:999px;background:#f0f2f6;color:#5f6a7c;font-size:9px;text-transform:capitalize}.state.eligible{background:#e9f5ee;color:#28734e}.state.rejected{background:#fff0ed;color:#9d493d}.empty{text-align:center;color:#7e8b9d;padding:25px!important}.candidate-modal{position:fixed;z-index:80;inset:0;display:grid;place-items:center;background:#14223977;padding:20px}.candidate-detail{position:relative;width:min(650px,100%);max-height:90vh;overflow:auto;display:grid;gap:10px;box-shadow:0 16px 55px #14223933}.candidate-detail h3{margin:0;color:#203550}.candidate-detail h4{margin:7px 0 0;font-size:12px}.candidate-detail p{color:#65738a;font-size:11px}.candidate-detail dl{display:grid;grid-template-columns:150px 1fr;gap:7px;margin:5px 0;font-size:11px}.candidate-detail dt{color:#7a8799}.candidate-detail dd{margin:0;overflow-wrap:anywhere;color:#34445a}.evidence-row{display:grid;gap:4px;padding:9px;border:1px solid #e8ecf2;border-radius:8px}.evidence-row b{font-size:11px;text-transform:capitalize}.evidence-row span,.evidence-row small{font-size:10px;overflow-wrap:anywhere;color:#65738a}.match-card{display:grid;gap:5px;padding:10px;border:1px solid #dce5f0;border-radius:8px;font-size:10px}.close{position:absolute;right:14px;top:14px}.success-notice{background:#ecf8f0;color:#276a45;padding:10px;border-radius:8px;font-size:11px}.notice:not(.success-notice){background:#fff4ed;color:#9b4f32;padding:10px;border-radius:8px}
 .source-gates{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.source-gates span{display:flex;justify-content:space-between;gap:8px;padding:7px;border:1px solid #e5eaf1;border-radius:7px;color:#65748a;font-size:9px}.source-gates b{color:#304869;text-transform:capitalize}
@media(max-width:800px){.lead-discovery-grid{grid-template-columns:1fr}.import-controls{grid-template-columns:1fr 1fr}.import-controls button{width:100%}.metric-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:800px){.start-discovery{grid-template-columns:1fr}.start-controls{grid-template-columns:1fr 1fr}.start-controls button{grid-column:1/-1}}
@media(max-width:520px){.lead-discovery-heading,.panel-title{display:grid}.safe-tag{justify-self:start}.panel{padding:12px}.import-controls,.start-controls{grid-template-columns:1fr}.start-controls button{grid-column:auto}.two-col{grid-template-columns:1fr}.metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.candidate-detail dl{grid-template-columns:1fr;gap:3px}.candidate-detail dd{margin-bottom:7px}.candidate-modal{padding:8px}}
</style>
