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
const sourceCatalog = ref<any[]>([])
const industryOptions = ref<any[]>([])
const countryOptions = ref<any[]>([])
const selectedCampaign = ref('')
const geographicFilter = ref('')
const selectedSource = ref('')
const selectedLiveSource = ref('')
const selectedSourceIds = ref<string[]>([])
const sourceType = ref<'osm_overpass' | 'wikidata' | 'india_ogd_mca' | 'gleif' | 'brave_search' | 'csv_upload'>('osm_overpass')
const file = ref<File | null>(null)
const sourceDraft = ref({ provider_name: 'OpenStreetMap / Overpass', license_or_authorization: 'Open Database License (ODbL)', storage_rights_reference: 'Open Database License (ODbL)', storage_rights_confirmed: true, allowed_collection_method: 'official_public_api', retention_policy: '', attribution_requirement: '© OpenStreetMap contributors', enabled: false })
const campaignDraft = ref({ name: 'Open Business Discovery', industry: '', country_code: '', region: '', city: '', keywords: '', target_leads: 25, pilot_mode: false, pilot_target: 25 })
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
const compatibleLiveSources = computed(() => sources.value.filter((item) => {
  const capability = sourceCatalog.value.find((entry) => entry.source_key === item.source_key)
  if (!capability?.supports_live_query || !capability.persistent_storage_allowed || !item.execution_ready) return false
  return sourceCompatibleWithCountry(item)
}))
const compatibleSelectedSourceIds = computed(() => selectedSourceIds.value.filter((id) => compatibleLiveSources.value.some((source) => source.id === id)))
const hasSafeOsmPartition = computed(() => sourceCompatibleWithCountry({ source_key: 'osm_overpass' }))
const liveSources = computed(() => sources.value.filter((item) => item.execution_ready && item.source_key !== 'csv_upload'))
const csvSources = computed(() => sources.value.filter((item) => item.source_key === 'csv_upload' && item.enabled && item.authorization_status === 'authorized'))
const sourceRequests = computed(() => (campaignProgress.value?.runs ?? []).reduce((sum: number, run: any) => sum + Number(run.source_requests || 0), 0))
const rawResults = computed(() => (campaignProgress.value?.runs ?? []).reduce((sum: number, run: any) => sum + Number(run.raw_candidates || 0), 0))
let pollTimer: ReturnType<typeof setInterval> | undefined

async function load() {
  try {
    const [sourceResponse, campaignResponse, catalogResponse, industryResponse, countryResponse] = await Promise.all([
      salesRequest('/lead-discovery/sources', props.tenantId), salesRequest('/lead-discovery/campaigns', props.tenantId),
      salesRequest('/lead-discovery/source-catalog', props.tenantId), salesRequest('/lead-discovery/industries', props.tenantId), salesRequest('/lead-discovery/countries', props.tenantId),
    ])
    sources.value = unwrap(sourceResponse); campaigns.value = unwrap(campaignResponse)
    sourceCatalog.value = unwrap(catalogResponse); industryOptions.value = unwrap(industryResponse); countryOptions.value = unwrap(countryResponse)
    sourceReadiness.value = await salesRequest('/lead-discovery/source-health', props.tenantId).catch(() => null)
    if (!selectedLiveSource.value && liveSources.value.length) selectedLiveSource.value = liveSources.value[0].id
    if (selectedSourceIds.value.length === 0) selectedSourceIds.value = compatibleLiveSources.value.filter((item) => item.source_key !== 'brave_search').map((item) => item.id)
    if (!selectedCampaign.value && campaigns.value.length) selectedCampaign.value = campaigns.value[0].id
    const [candidateResponse, progressResponse] = selectedCampaign.value
      ? await Promise.all([
          salesRequest(`/lead-discovery/candidates?campaign_id=${encodeURIComponent(selectedCampaign.value)}${geographicFilter.value ? `&geographic_relevance=${encodeURIComponent(geographicFilter.value)}` : ''}`, props.tenantId),
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
    const isCsv = sourceType.value === 'csv_upload'
    const capability = sourceCatalog.value.find((item) => item.source_key === sourceType.value)
    const source = await salesRequest('/lead-discovery/sources', props.tenantId, 'POST', {
      source_key: sourceType.value, ...sourceDraft.value,
      provider_name: sourceDraft.value.provider_name || capability?.display_name,
      allowed_collection_method: isCsv ? 'licensed_csv_upload' : (capability?.recommended_sync_mode || 'official_public_api'),
    })
    notice.value = isCsv ? 'Authorized CSV source configured as a manual fallback.' : (source.execution_ready ? 'Source configured. Promotion remains manual.' : `Source saved but unavailable: ${sourceStatusMessage(source.readiness_reason_code)}.`)
    sourceDraft.value.license_or_authorization = ''; sourceDraft.value.storage_rights_reference = ''; sourceDraft.value.storage_rights_confirmed = false
    sources.value.unshift(source); selectedSource.value = source.id
    if (source.execution_ready && source.source_key !== 'csv_upload' && source.source_key !== 'brave_search') selectedSourceIds.value = [...new Set([...selectedSourceIds.value, source.id])]
    selectedLiveSource.value = source.execution_ready ? source.id : ''
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to configure source.' }
  finally { busy.value = false }
}

async function createCampaign() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    if (selectedSourceIds.value.length === 0) { error.value = 'Select at least one compatible, approved open source before creating a campaign.'; return }
    const campaign = await salesRequest('/lead-discovery/campaigns', props.tenantId, 'POST', {
      ...campaignDraft.value, industry: campaignDraft.value.industry || null, country_code: campaignDraft.value.country_code || null,
      pilot_mode: campaignDraft.value.pilot_mode, pilot_target: campaignDraft.value.pilot_mode ? Number(campaignDraft.value.pilot_target) : null,
      source_mode: 'selected', source_ids: compatibleSelectedSourceIds.value,
    })
    campaigns.value.unshift(campaign); selectedCampaign.value = campaign.id; notice.value = 'Campaign created. Auto-promotion is off.'; await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to create campaign.' }
  finally { busy.value = false }
}

async function startDiscovery() {
  if (!selectedCampaign.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest(`/lead-discovery/campaigns/${encodeURIComponent(selectedCampaign.value)}/start`, props.tenantId, 'POST', {})
    notice.value = `Discovery ${result.status}. Businesses will be staged for website verification; no companies or sales actions are created automatically.`
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to start discovery.' }
  finally { busy.value = false }
}

function rawMetadata(candidate: any): any {
  if (typeof candidate?.raw_metadata === 'string') { try { return JSON.parse(candidate.raw_metadata) } catch { return {} } }
  return candidate?.raw_metadata ?? {}
}

function geography(candidate: any): any {
  return candidate?.geographic_relevance && typeof candidate.geographic_relevance === 'object'
    ? candidate.geographic_relevance
    : { classification: 'location_unknown', requested_location: null, evidence_location: null, explanation: 'No reliable source location evidence is available.' }
}

function geographyLabel(candidate: any): string {
  const classification = geography(candidate).classification ?? 'location_unknown'
  return ({ city_match: 'City match', region_match: 'Region match', country_match: 'Country match', location_unknown: 'Location unknown', location_mismatch: 'Location mismatch' } as Record<string, string>)[classification] ?? 'Location unknown'
}

function geographyTone(candidate: any): string {
  const classification = geography(candidate).classification ?? 'location_unknown'
  return classification === 'location_mismatch' ? 'geography-mismatch' : classification === 'location_unknown' ? 'geography-unknown' : 'geography-match'
}

function locationText(location: any): string {
  if (!location || typeof location !== 'object') return 'Unknown'
  return [location.city, location.region, location.country].filter((part) => typeof part === 'string' && part.trim()).join(', ') || 'Unknown'
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
    SOURCE_CONFIGURATION_MISSING: 'Required official endpoint or operator identification is not configured.',
    SOURCE_NOT_APPLICABLE: 'This source does not support automatic live queries.',
    NO_COMPATIBLE_SOURCE_PARTITIONS: 'No bounded source partitions are configured for this geography.',
  } as Record<string, string>)[code ?? ''] ?? 'Provider status is unavailable.'
}

function sourceDisplayName(sourceKey: string): string {
  return sourceCatalog.value.find((item) => item.source_key === sourceKey)?.display_name || sourceKey.replaceAll('_', ' ')
}

function sourceTypeChanged() {
  const capability = sourceCatalog.value.find((item) => item.source_key === sourceType.value)
  if (capability) {
    sourceDraft.value.provider_name = capability.display_name
    sourceDraft.value.attribution_requirement = capability.attribution_text || ''
    sourceDraft.value.allowed_collection_method = capability.recommended_sync_mode
    const needsExplicitRights = ['india_ogd_mca', 'brave_search', 'csv_upload'].includes(sourceType.value)
    sourceDraft.value.license_or_authorization = needsExplicitRights ? '' : (capability.license_name || '')
    sourceDraft.value.storage_rights_confirmed = !needsExplicitRights && Boolean(capability.persistent_storage_allowed)
    sourceDraft.value.storage_rights_reference = needsExplicitRights ? '' : (capability.license_name || '')
  }
  if (sourceType.value === 'csv_upload') {
    sourceDraft.value.provider_name = 'Licensed CSV dataset'
    sourceDraft.value.allowed_collection_method = 'licensed_csv_upload'
    sourceDraft.value.license_or_authorization = ''
  }
  sourceDraft.value.enabled = false
  if (['india_ogd_mca', 'brave_search', 'csv_upload'].includes(sourceType.value)) {
    sourceDraft.value.storage_rights_confirmed = false
    sourceDraft.value.storage_rights_reference = ''
  }
}

function sourceCompatibleWithCountry(item: any): boolean {
  const capability = sourceCatalog.value.find((entry) => entry.source_key === item.source_key)
  if (!capability) return false
  if (item.source_key === 'osm_overpass') {
    const wantedCountry = campaignDraft.value.country_code
    const wantedRegion = campaignDraft.value.region.trim().toLocaleLowerCase()
    const wantedCity = campaignDraft.value.city.trim().toLocaleLowerCase()
    return (capability.safe_partitions || []).some((partition: any) =>
      (!wantedCountry || partition.country_code === wantedCountry)
      && (!wantedRegion || (partition.region || '').toLocaleLowerCase() === wantedRegion)
      && (!wantedCity || (partition.city || '').toLocaleLowerCase() === wantedCity))
  }
  if (capability.governance_class === 'CONDITIONAL') {
    return Boolean(campaignDraft.value.country_code && capability.supports_country_filter && item.execution_ready)
  }
  if (!campaignDraft.value.country_code) return Boolean(capability.global_coverage || (capability.governance_class !== 'CONDITIONAL' && capability.supported_countries?.length))
  return Boolean(capability.global_coverage || capability.supported_countries?.includes(campaignDraft.value.country_code))
}

function sourceSelected(sourceId: string): boolean { return selectedSourceIds.value.includes(sourceId) }
function toggleSource(sourceId: string, checked: boolean) {
  selectedSourceIds.value = checked ? [...new Set([...selectedSourceIds.value, sourceId])] : selectedSourceIds.value.filter((id) => id !== sourceId)
}

async function setSourceEnabled(source: any, enabled: boolean) {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const updated = await salesRequest(`/lead-discovery/sources/${encodeURIComponent(source.id)}`, props.tenantId, 'PATCH', { enabled })
    sources.value = sources.value.map((item) => item.id === source.id ? updated : item)
    if (updated.execution_ready && updated.source_key !== 'csv_upload' && updated.source_key !== 'brave_search') selectedSourceIds.value = [...new Set([...selectedSourceIds.value, updated.id])]
    else selectedSourceIds.value = selectedSourceIds.value.filter((id) => id !== updated.id)
    notice.value = enabled ? 'Source is enabled after server-side readiness checks.' : 'Source disabled.'
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to update source.' }
  finally { busy.value = false }
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
      <form class="panel lead-card" @submit.prevent="createSource"><h3>Configure a discovery source</h3><p class="source-required">A source runs only after tenant authorization, persistent-storage rights, application enablement, and required provider configuration are verified separately. Public visibility alone does not grant reuse rights.</p>
        <label>Source adapter<select v-model="sourceType" @change="sourceTypeChanged"><option value="osm_overpass">OpenStreetMap / Overpass</option><option value="wikidata">Wikidata</option><option value="india_ogd_mca">India OGD / MCA</option><option value="gleif">GLEIF</option><option value="brave_search">Brave Search · conditional</option><option value="csv_upload">Licensed CSV · manual</option></select></label>
        <label>Provider / dataset name<input v-model="sourceDraft.provider_name" required maxlength="160"></label>
        <label>License / permission basis<textarea v-model="sourceDraft.license_or_authorization" required maxlength="500" rows="2" placeholder="Record the applicable source terms or dataset license"></textarea></label>
        <template v-if="sourceType === 'brave_search' || sourceType === 'india_ogd_mca'"><label>Storage-rights agreement or resource license reference<input v-model="sourceDraft.storage_rights_reference" required maxlength="255" placeholder="Agreement clause or selected resource terms"></label><label class="check"><input v-model="sourceDraft.storage_rights_confirmed" type="checkbox"> I confirmed that these terms permit persistent storage for this workflow</label></template>
        <p v-else-if="['osm_overpass', 'wikidata', 'gleif'].includes(sourceType)" class="terms-note">Persistent storage follows the source's documented {{ sourceDraft.storage_rights_reference }} license; required attribution and license metadata are retained.</p>
        <p v-if="sourceType === 'brave_search'" class="terms-note">Brave remains disabled unless the applicable agreement explicitly permits persistent storage of search results. Credential presence does not grant storage rights.</p>
        <label>Retention restrictions<input v-model="sourceDraft.retention_policy" maxlength="120"></label><label>Attribution requirements<input v-model="sourceDraft.attribution_requirement" maxlength="255"></label>
        <label class="check"><input v-model="sourceDraft.enabled" type="checkbox"> Enable after permission is documented</label><button class="primary" :disabled="busy">Save source configuration</button>
      </form>
      <form class="panel lead-card" @submit.prevent="createCampaign"><h3>Create a discovery campaign</h3><p>Industry and country are optional. Empty filters use bounded source-specific partitions rather than an unrestricted global request.</p>
        <label>Campaign name<input v-model="campaignDraft.name" required maxlength="160"></label>
        <label>Industry<input v-model="campaignDraft.industry" list="lead-discovery-industry-suggestions" maxlength="150" placeholder="Any industry or enter a custom industry"><datalist id="lead-discovery-industry-suggestions"><option v-for="industry in industryOptions" :key="industry.key" :value="industry.label"></option></datalist><small>Suggestions are optional; custom industry labels are preserved.</small></label>
        <div class="two-col"><label>Country<select v-model="campaignDraft.country_code"><option value="">Any country</option><option v-for="country in countryOptions" :key="country.code" :value="country.code">{{ country.label }} ({{ country.code }})</option></select></label><label>City<input v-model="campaignDraft.city" maxlength="120" placeholder="Optional"></label></div>
        <label>State / region<input v-model="campaignDraft.region" maxlength="120" placeholder="Optional"></label><label>Keywords<input v-model="campaignDraft.keywords" maxlength="150" placeholder="Optional"></label><label>Eligible-company target<input v-model.number="campaignDraft.target_leads" type="number" min="1" max="500"></label>
        <label class="check"><input v-model="campaignDraft.pilot_mode" type="checkbox"><span>Controlled pilot mode</span></label>
        <small v-if="campaignDraft.pilot_mode" class="pilot-help">Stops after this many unique reviewable candidates. Duplicates and location mismatches do not count.</small>
        <label v-if="campaignDraft.pilot_mode">Pilot target<input v-model.number="campaignDraft.pilot_target" type="number" min="1" max="25" required aria-describedby="pilot-target-help"></label>
        <small v-if="campaignDraft.pilot_mode" id="pilot-target-help" class="pilot-help">Choose between 1 and 25 candidates. Default: 25.</small>
        <fieldset class="source-choice"><legend>Sources</legend><p v-if="!campaignDraft.industry">All supported business categories</p><label v-for="source in compatibleLiveSources" :key="source.id" class="check"><input type="checkbox" :checked="sourceSelected(source.id)" @change="toggleSource(source.id, ($event.target as HTMLInputElement).checked)"><span>{{ sourceDisplayName(source.source_key) }} · {{ source.source_type }}</span></label>
          <p v-if="campaignDraft.country_code && campaignDraft.country_code !== 'IN' && sourceCatalog.some((entry) => entry.source_key === 'india_ogd_mca')" class="terms-note">India OGD / MCA: not applicable — India only.</p>
          <p v-if="!hasSafeOsmPartition" class="terms-note">OpenStreetMap: no configured safe bounded area matches these filters. Other compatible sources can still run.</p>
          <p v-if="!compatibleLiveSources.length" class="source-required">No configured source is currently ready for this geography. Configure and approve an open source first.</p>
        </fieldset>
        <div class="catalog-grid"><article v-for="provider in sourceCatalog" :key="provider.source_key" :class="{ prohibited: !provider.selectable }"><b>{{ provider.display_name }}</b><small>{{ provider.global_coverage ? 'Global' : (provider.supported_countries || []).join(', ') || provider.governance_class }}</small><small>{{ provider.license_name }} · storage {{ provider.persistent_storage_allowed ? 'supported subject to approval' : 'not permitted' }}</small><small>Credential {{ provider.configured ? provider.credential_status : 'not configured' }} · configuration {{ provider.configuration_status || 'unknown' }} · authorization {{ provider.authorization_status }} · retention {{ provider.storage_rights_status }} · health {{ provider.health_status }}</small><small v-if="provider.attribution_required">Attribution: {{ provider.attribution_text || 'Required' }}</small><small v-if="provider.restriction_reason">{{ provider.restriction_reason }}</small></article></div>
        <div class="locked-config">Small target · bounded provider requests and partitions · safe website verification · public business contacts only · manual promotion · no outreach</div>
        <button class="primary" :disabled="busy || compatibleSelectedSourceIds.length === 0">Create campaign</button>
      </form>
    </div>

    <section v-if="manager" class="panel start-discovery"><div><span class="eyebrow">AUTOMATIC WORKFLOW</span><h3>Start Discovery</h3><p>Each provider uses bounded tasks and approved storage rights. Candidates remain staged until manual promotion. No outreach is created.</p></div><div class="start-controls"><label>Campaign<select v-model="selectedCampaign"><option value="">Choose campaign</option><option v-for="campaign in campaigns.filter((item) => item.status === 'draft')" :key="campaign.id" :value="campaign.id">{{ campaign.name }}{{ campaign.pilot_mode ? ` · Controlled pilot (${campaign.pilot_target})` : '' }}</option></select></label><button class="primary" :disabled="busy || !selectedCampaign" @click="startDiscovery">{{ busy ? 'Starting…' : 'Start Discovery' }}</button></div></section>

    <section class="metric-grid" aria-label="Lead discovery progress"><article><small>CANDIDATES</small><b>{{ metrics.discovered }}</b></article><article><small>WEBSITE VERIFIED</small><b>{{ metrics.verified }}</b></article><article><small>ELIGIBLE</small><b>{{ metrics.eligible }}</b></article><article><small>DUPLICATES</small><b>{{ metrics.duplicates }}</b></article><article><small>INVALID</small><b>{{ metrics.invalid }}</b></article><article><small>CONTACTS ENRICHED</small><b>{{ metrics.enriched }}</b></article><article><small>PROMOTED</small><b>{{ metrics.promoted }}</b></article><article><small>ERRORS</small><b>{{ metrics.errors }}</b></article><article><small>CAMPAIGN STATUS</small><b>{{ metrics.running }}</b></article></section>

    <section v-if="campaignProgress?.pilot_summary" class="panel pilot-summary" aria-label="Lead Discovery pilot quality summary">
      <div class="panel-title"><div><span class="eyebrow">CONTROLLED PILOT</span><h3>Pilot quality summary</h3><p>Operational counts only. No AI quality score or automatic promotion.</p></div><span class="state">{{ campaignProgress.pilot_summary.unique_staged }} / {{ campaignProgress.pilot_summary.target }} unique</span></div>
      <p class="pilot-rule">{{ campaignProgress.pilot_summary.target_counting_rule }}</p>
      <div v-if="campaignProgress.pilot_summary.skipped_sources?.length" class="pilot-skipped" aria-label="Sources skipped safely">
        <b>Sources skipped safely</b>
        <span v-for="source in campaignProgress.pilot_summary.skipped_sources" :key="source.source_id">{{ source.source }} · {{ source.reason_code }}</span>
      </div>
      <div class="pilot-kpis"><article><small>RAW RESULTS</small><b>{{ campaignProgress.pilot_summary.total_raw_candidates }}</b></article><article><small>UNIQUE STAGED</small><b>{{ campaignProgress.pilot_summary.unique_staged }}</b></article><article><small>DUPLICATES</small><b>{{ campaignProgress.pilot_summary.duplicates }}</b></article><article><small>VERIFIED</small><b>{{ campaignProgress.pilot_summary.verified_candidates }}</b></article><article><small>UNVERIFIABLE</small><b>{{ campaignProgress.pilot_summary.unverifiable_candidates }}</b></article><article><small>REVIEWABLE</small><b>{{ campaignProgress.pilot_summary.eligible_reviewable }}</b></article><article><small>REJECTED</small><b>{{ campaignProgress.pilot_summary.rejected }}</b></article><article><small>LOCATION MISMATCH</small><b>{{ campaignProgress.pilot_summary.geographic_mismatches }}</b></article></div>
      <div class="pilot-tables"><div><h4>Source contribution</h4><div class="pilot-table-wrap"><table><thead><tr><th>Source</th><th>Raw</th><th>Unique</th><th>Dupes</th><th>Verified</th><th>Reviewable</th><th>Rejected</th><th>Geo mismatch</th><th>Unverifiable</th><th>Requests</th><th>Retries</th><th>Errors</th><th>Elapsed</th></tr></thead><tbody><tr v-for="source in campaignProgress.pilot_summary.source_contribution" :key="source.source_id"><td>{{ source.source }}</td><td>{{ source.raw_results }}</td><td>{{ source.staged_candidates }}</td><td>{{ source.duplicates }}</td><td>{{ source.verified }}</td><td>{{ source.reviewable }}</td><td>{{ source.rejected }}</td><td>{{ source.geographic_mismatches }}</td><td>{{ source.unverifiable_websites }}</td><td>{{ source.provider_requests }}</td><td>{{ source.retries }}</td><td>{{ source.source_errors + source.processing_errors }}</td><td>{{ source.elapsed_seconds }}s</td></tr><tr v-if="!campaignProgress.pilot_summary.source_contribution.length"><td colspan="13">No source runs yet.</td></tr></tbody></table></div></div>
        <div><h4>Geographic relevance</h4><div class="pilot-breakdown"><span>City match <b>{{ campaignProgress.pilot_summary.geographic_distribution.city_match }}</b></span><span>Region match <b>{{ campaignProgress.pilot_summary.geographic_distribution.region_match }}</b></span><span>Country match <b>{{ campaignProgress.pilot_summary.geographic_distribution.country_match }}</b></span><span>Unknown <b>{{ campaignProgress.pilot_summary.geographic_distribution.location_unknown }}</b></span><span>Mismatch <b>{{ campaignProgress.pilot_summary.geographic_distribution.location_mismatch }}</b></span></div></div>
        <div><h4>Website verification</h4><div class="pilot-breakdown"><span>Verified <b>{{ campaignProgress.pilot_summary.verification_distribution.verified }}</b></span><span>Robots blocked <b>{{ campaignProgress.pilot_summary.verification_distribution.robots_blocked }}</b></span><span>DNS failure <b>{{ campaignProgress.pilot_summary.verification_distribution.dns_failure }}</b></span><span>Timeout <b>{{ campaignProgress.pilot_summary.verification_distribution.timeout }}</b></span><span>Connection unavailable <b>{{ campaignProgress.pilot_summary.verification_distribution.connection_unavailable }}</b></span><span>Redirect blocked <b>{{ campaignProgress.pilot_summary.verification_distribution.redirect_policy_blocked }}</b></span><span>Other unverifiable <b>{{ campaignProgress.pilot_summary.verification_distribution.other_unverifiable }}</b></span><span>No website <b>{{ campaignProgress.pilot_summary.verification_distribution.no_website }}</b></span></div></div>
        <div><h4>Human review</h4><div class="pilot-breakdown"><span>Not reviewed <b>{{ campaignProgress.pilot_summary.human_review.not_reviewed }}</b></span><span>Promoted <b>{{ campaignProgress.pilot_summary.human_review.promoted }}</b></span><span>Manually rejected <b>{{ campaignProgress.pilot_summary.human_review.manually_rejected }}</b></span></div></div>
        <div><h4>Identity and deduplication</h4><div class="pilot-breakdown"><span>Cross-source matches <b>{{ campaignProgress.pilot_summary.deduplication.cross_source_matched_candidates }}</b></span><span>Matched existing companies <b>{{ campaignProgress.pilot_summary.deduplication.matched_existing_companies }}</b></span><span>Possible matches for review <b>{{ campaignProgress.pilot_summary.deduplication.possible_identity_matches_for_review }}</b></span><span>Automatic merge <b>No</b></span></div></div></div>
    </section>

    <section class="panel source-list"><div class="panel-title"><div><h3>Source health</h3><p>Credential/configuration, tenant authorization, retention rights, and runtime health are independent gates.</p></div></div><article v-for="source in sources" :key="source.id"><div><b>{{ sourceDisplayName(source.source_key) }}</b><small>{{ source.source_type }} · {{ source.allowed_collection_method }}</small><small>Credential: {{ source.credential_status }} · Configuration: {{ source.configuration_status }} · Authorization: {{ source.authorization_status }} · Storage rights: {{ source.storage_rights_status }} · Health: {{ source.health_state }}</small><small>{{ source.authorization_summary || 'No authorization summary recorded' }}</small></div><span :class="['state', source.execution_ready ? 'eligible' : 'needs_review']">{{ source.execution_ready ? 'ready' : sourceStatusMessage(source.readiness_reason_code) }}</span><small v-if="source.last_error_code">{{ sourceStatusMessage(source.last_error_code) }}</small><button v-if="manager && source.source_key !== 'csv_upload'" class="quiet" :disabled="busy" @click="setSourceEnabled(source, !source.enabled)">{{ source.enabled ? 'Disable' : 'Enable' }}</button></article></section>

    <details class="panel lead-import advanced-import"><summary>Manual / advanced import · CSV fallback</summary><div class="panel-title"><div><h3>Import a licensed CSV</h3><p>Required columns: company_name, website. Optional: industry, country_code, region, city, source_url, business_email, business_phone, source_reference.</p></div></div>
      <div class="import-controls"><label>Campaign<select v-model="selectedCampaign" @change="load"><option value="">Choose campaign</option><option v-for="campaign in campaigns" :key="campaign.id" :value="campaign.id">{{ campaign.name }}</option></select></label>
        <label>Authorized source<select v-model="selectedSource"><option value="">Choose source</option><option v-for="source in csvSources" :key="source.id" :value="source.id">{{ source.provider_name }}</option></select></label>
        <label>CSV file<input type="file" accept=".csv,text/csv" @change="setFile"></label><button class="primary" :disabled="busy || !file || !selectedSource || !selectedCampaign" @click="importCsv">{{ busy ? 'Processing…' : 'Stage and verify candidates' }}</button>
      </div><p v-if="!csvSources.length" class="source-required">CSV fallback is unavailable until an owner authorizes a licensed dataset.</p>
    </details>

    <section class="panel lead-candidates"><div class="panel-title"><div><h3>Candidate workbench</h3><p>{{ activeCampaign?.name || 'All campaigns' }} · staging records stay separate from prospects until manual promotion</p></div><div class="campaign-actions"><button v-if="activeCampaign?.status === 'running'" class="quiet" :disabled="busy || !manager" @click="controlCampaign('pause')">Pause</button><button v-if="activeCampaign?.status === 'paused'" class="quiet" :disabled="busy || !manager" @click="controlCampaign('resume')">Resume</button><button v-if="['running','paused','draft'].includes(activeCampaign?.status)" class="quiet" :disabled="busy || !manager" @click="controlCampaign('cancel')">Cancel</button><button class="quiet" @click="load">Refresh</button></div></div>
      <div class="campaign-progress"><span>Source requests <b>{{ sourceRequests }}</b></span><span>Raw results <b>{{ rawResults }}</b></span><span>Verified websites <b>{{ metrics.verified }}</b></span><span>Eligible leads <b>{{ metrics.eligible }} / {{ activeCampaign?.target_leads || '—' }}</b></span></div>
      <div v-if="campaignProgress?.providers?.length" class="provider-progress"><article v-for="provider in campaignProgress.providers" :key="provider.source_id"><b>{{ provider.source }}</b><span>{{ provider.status }}</span><small>Partitions {{ provider.partitions_completed }}/{{ provider.partitions_total }} · Requests {{ provider.requests }} · Raw {{ provider.raw_records }} · Candidates {{ provider.unique_candidates }} · Duplicates {{ provider.duplicates }} · Eligible {{ provider.eligible }} · Errors {{ provider.errors }}</small></article></div><div v-if="campaignProgress?.checkpoints?.length" class="query-progress"><span v-for="checkpoint in campaignProgress.checkpoints" :key="checkpoint.id">{{ checkpoint.partition_key }} · {{ checkpoint.query_key }} · {{ checkpoint.status }}</span></div>
      <div class="geo-filter"><label for="lead-discovery-geography">Geography</label><select id="lead-discovery-geography" v-model="geographicFilter" @change="load"><option value="">All classifications</option><option value="city_match">City match</option><option value="region_match">Region match</option><option value="country_match">Country match</option><option value="location_unknown">Location unknown</option><option value="location_mismatch">Location mismatch</option></select><small>Requested campaign intent is shown separately from source evidence.</small></div>
      <div class="candidate-table-wrap"><table><thead><tr><th>Business</th><th>Website</th><th>Requested / evidence location</th><th>Geographic relevance</th><th>Discovery provenance</th><th>Eligibility</th><th>Verification</th><th>Promotion</th><th>Action</th></tr></thead><tbody>
        <tr v-for="candidate in candidates" :key="candidate.id"><td><button class="text-action" @click="openCandidate(candidate)">{{ candidate.company_name }}</button><small>{{ sources.find((item) => item.id === candidate.source_id)?.provider_name || candidate.source_id }}</small></td><td>{{ candidate.normalized_domain || 'Unverified / invalid' }}</td><td><small>Requested: {{ locationText(geography(candidate).requested_location) }}</small><small>Evidence: {{ locationText(geography(candidate).evidence_location) }}</small></td><td><span :class="['state', geographyTone(candidate)]">{{ geographyLabel(candidate) }}</span><small>{{ geography(candidate).explanation }}</small></td><td>{{ rawMetadata(candidate).query || 'Manual CSV import' }}<small>{{ rawMetadata(candidate).rank ? `Result #${rawMetadata(candidate).rank}` : '' }}</small></td><td><span :class="['state', candidate.eligibility_status]">{{ candidate.eligibility_status.replaceAll('_',' ') }}</span><small>{{ candidate.validation_reason || candidate.validation_reason_code || 'Verification pending' }}</small></td><td>{{ candidate.official_domain_status.replaceAll('_',' ') }}</td><td>{{ candidate.promotion_status.replaceAll('_',' ') }}</td><td><button v-if="candidate.eligibility_status === 'eligible' || (candidate.matched_company_id && candidate.official_domain_status === 'verified')" class="quiet" :disabled="busy || !manager" @click="promote(candidate)">{{ candidate.matched_company_id ? 'Review & reuse' : 'Promote' }}</button></td></tr>
        <tr v-if="!candidates.length"><td colspan="9" class="empty">No candidates match this view. Candidates without verifiable location evidence remain available under Location unknown.</td></tr>
      </tbody></table></div>
    </section>

    <div v-if="detail" class="candidate-modal" @click.self="detail = null"><section class="candidate-detail panel"><button class="quiet close" @click="detail = null">Close</button><span class="eyebrow">CANDIDATE PROVENANCE</span><h3>{{ detail.candidate.company_name }}</h3><p>{{ detail.candidate.validation_reason || 'Candidate review details' }}</p><div class="geography-detail"><span :class="['state', geographyTone(detail.candidate)]">{{ geographyLabel(detail.candidate) }}</span><p>{{ geography(detail.candidate).explanation }}</p><div><b>Requested campaign location</b><span>{{ locationText(geography(detail.candidate).requested_location) }}</span></div><div><b>Location from source evidence</b><span>{{ locationText(geography(detail.candidate).evidence_location) }}</span></div></div><dl><dt>Campaign</dt><dd>{{ detail.campaign?.name }}</dd><dt>Website</dt><dd>{{ detail.candidate.claimed_website || 'Not provided' }} · {{ detail.candidate.official_domain_status }}</dd><dt>Industry / source location</dt><dd>{{ detail.candidate.industry || 'Unknown' }} · {{ detail.candidate.location || 'Unknown' }}</dd><dt>Eligibility</dt><dd>{{ detail.candidate.eligibility_status }} · {{ detail.candidate.validation_reason_code || 'Pending' }}</dd><dt>Source</dt><dd>{{ detail.source?.provider_name }} · {{ detail.source?.authorization_status }} · {{ detail.source?.authorization_summary }}</dd></dl><h4>Evidence and provenance</h4><article v-for="item in detail.evidence" :key="item.id" class="evidence-row"><b>{{ item.field_name.replaceAll('_',' ') }}</b><span>{{ item.normalized_value || item.raw_value }}</span><small>{{ item.evidence_type }} · {{ item.confidence_score }} confidence · {{ item.collected_at }}</small><small>{{ item.source_url || 'CSV row provenance' }} · {{ item.attribution }}</small></article><div v-if="detail.matched_company" class="match-card"><b>Existing company match</b><span>{{ detail.matched_company.name }} · {{ detail.matched_company.normalized_domain }}</span><button class="quiet" @click="emit('openProspect', detail.matched_company)">Open Prospect 360</button></div><div class="detail-actions"><button v-if="detail.candidate.eligibility_status === 'eligible' || (detail.candidate.matched_company_id && detail.candidate.official_domain_status === 'verified')" class="primary" :disabled="busy || !manager" @click="promote(detail.candidate)">Promote to Prospect</button><button v-if="detail.candidate.promotion_status === 'not_promoted'" class="quiet" :disabled="busy || !manager" @click="reject(detail.candidate)">Reject candidate</button></div></section></div>
  </section>
</template>

<style scoped>
.geo-filter{display:grid;grid-template-columns:auto minmax(180px,260px);align-items:center;gap:6px 10px;color:#5e6d81;font-size:11px;font-weight:650}.geo-filter select{min-width:0;border:1px solid #dce3ed;border-radius:8px;background:#fff;padding:8px;color:#273953;font:inherit}.geo-filter small{grid-column:2;color:#8490a1;font-size:10px;font-weight:400}.state.geography-match{background:#e9f5ee;color:#28734e}.state.geography-unknown{background:#f3f4f6;color:#626d7d}.state.geography-mismatch{background:#fff0ed;color:#9d493d}.geography-detail{display:grid;gap:7px;padding:11px;border:1px solid #e5eaf1;border-radius:9px;background:#f8fafc}.geography-detail p{margin:0;color:#65738a;font-size:11px}.geography-detail div{display:grid;grid-template-columns:180px 1fr;gap:8px;font-size:10px}.geography-detail div b{color:#728096}.geography-detail div span{color:#34445a;overflow-wrap:anywhere}
.pilot-summary{display:grid;gap:12px}.pilot-summary h3{margin:3px 0;color:#283c5b;font-size:14px}.pilot-summary h4{margin:0 0 7px;color:#4f617a;font-size:11px}.pilot-rule{margin:0;padding:9px;border-radius:8px;background:#f6f8fb;color:#69778b;font-size:10px;line-height:1.5}.pilot-skipped{display:flex;flex-wrap:wrap;gap:7px;align-items:center;color:#7b5b2b;font-size:10px}.pilot-skipped b,.pilot-skipped span{padding:6px 8px;border:1px solid #f0dfbd;border-radius:7px;background:#fffaf0}.pilot-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}.pilot-kpis article{display:grid;gap:4px;padding:9px;border:1px solid #e5eaf1;border-radius:8px}.pilot-kpis small{font-size:8px;color:#8490a1}.pilot-kpis b{font-size:15px;color:#283c5b}.pilot-tables{display:grid;gap:11px}.pilot-tables>div{min-width:0}.pilot-table-wrap{max-width:100%;overflow:auto;border:1px solid #e5eaf1;border-radius:8px}.pilot-table-wrap table{width:100%;min-width:850px;border-collapse:collapse;font-size:10px;text-align:left}.pilot-table-wrap th,.pilot-table-wrap td{padding:7px;border-bottom:1px solid #edf0f4}.pilot-table-wrap th{background:#f7f9fb;color:#718097;font-size:9px}.pilot-breakdown{display:flex;flex-wrap:wrap;gap:6px}.pilot-breakdown span{display:flex;gap:9px;padding:6px 8px;border:1px solid #e5eaf1;border-radius:7px;color:#69778b;font-size:9px}.pilot-breakdown b{color:#304869}
.pilot-help{display:block;color:#6b7a90;font-size:10px;line-height:1.5}
.lead-discovery{display:grid;gap:16px;min-width:0}.lead-discovery-heading,.panel-title{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.lead-discovery-heading h2{margin:3px 0;color:#1e2f49;font-size:21px}.lead-discovery-heading p,.lead-card p,.panel-title p,.start-discovery p{max-width:750px;margin:0;color:#728096;font-size:12px;line-height:1.5}.safe-tag{white-space:nowrap;padding:7px 10px;border-radius:99px;background:#eff5ff;color:#355b9c;font-size:10px;font-weight:700}.lead-discovery-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.panel{min-width:0;padding:16px;border:1px solid #e2e8f0;border-radius:13px;background:#fff}.lead-card{display:grid;gap:10px;align-content:start}.lead-card h3,.lead-import h3,.lead-candidates h3,.source-list h3,.start-discovery h3{margin:0;color:#283c5b;font-size:14px}.lead-card label,.import-controls label,.start-controls label{display:grid;gap:5px;color:#5e6d81;font-size:11px;font-weight:650}.lead-card input,.lead-card textarea,.lead-card select,.import-controls select,.import-controls input[type=file],.start-controls select{width:100%;min-width:0;border:1px solid #dce3ed;border-radius:8px;background:#fff;padding:9px;color:#273953;font:inherit}.lead-card .check{display:flex;align-items:center;gap:7px}.lead-card .check input{width:auto}.terms-note{padding:9px;background:#fff7e8;border-radius:8px;color:#845e24;font-size:10px;line-height:1.5}.two-col{display:grid;grid-template-columns:1fr 1fr;gap:10px}.locked-config,.source-required{padding:10px;border-radius:8px;background:#f5f7fa;color:#65738a;font-size:10px;line-height:1.5}.lead-import,.lead-candidates,.source-list{display:grid;gap:13px}.import-controls{display:grid;grid-template-columns:1fr 1fr 1.2fr auto;align-items:end;gap:10px}.import-controls button{white-space:nowrap}.campaign-actions,.detail-actions{display:flex;gap:6px;flex-wrap:wrap}.start-discovery{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,1.1fr);align-items:center;gap:20px;border-color:#cfddf7;background:linear-gradient(120deg,#f8fbff,#fff)}.start-discovery h3{margin:4px 0}.start-controls{display:grid;grid-template-columns:1fr 1fr auto;gap:9px;align-items:end}.start-controls button{white-space:nowrap}.metric-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px}.metric-grid article{display:grid;gap:5px;padding:11px;border:1px solid #e3e9f1;border-radius:9px;background:#fff}.metric-grid small{font-size:8px;letter-spacing:.05em;color:#8490a1}.metric-grid b{font-size:17px;color:#283c5b}.source-list article{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:10px;border:1px solid #e7ebf1;border-radius:8px}.source-list article div{display:grid;gap:4px;min-width:0}.source-list article b{font-size:11px}.source-list article small{font-size:10px;color:#77859a;overflow-wrap:anywhere}.campaign-progress{display:flex;flex-wrap:wrap;gap:8px}.campaign-progress span,.query-progress span{padding:7px 9px;border:1px solid #e5eaf1;border-radius:8px;color:#6f7c8e;font-size:10px}.campaign-progress b{color:#2b3e5a}.query-progress{display:flex;gap:5px;flex-wrap:wrap}.candidate-table-wrap{max-width:100%;overflow:auto;border:1px solid #e7ebf1;border-radius:9px}.candidate-table-wrap table{width:100%;min-width:850px;border-collapse:collapse;text-align:left;font-size:11px}.candidate-table-wrap th,.candidate-table-wrap td{padding:10px;border-bottom:1px solid #edf0f4;vertical-align:top}.candidate-table-wrap th{background:#f7f9fb;color:#728096;font-size:9px}.candidate-table-wrap td small{display:block;margin-top:4px;color:#8490a1;max-width:240px;overflow-wrap:anywhere}.text-action{border:0;padding:0;background:none;color:#315ca8;font:inherit;font-weight:700;text-align:left;cursor:pointer}.state{display:inline-flex;padding:4px 7px;border-radius:999px;background:#f0f2f6;color:#5f6a7c;font-size:9px;text-transform:capitalize}.state.eligible{background:#e9f5ee;color:#28734e}.state.rejected{background:#fff0ed;color:#9d493d}.empty{text-align:center;color:#7e8b9d;padding:25px!important}.candidate-modal{position:fixed;z-index:80;inset:0;display:grid;place-items:center;background:#14223977;padding:20px}.candidate-detail{position:relative;width:min(650px,100%);max-height:90vh;overflow:auto;display:grid;gap:10px;box-shadow:0 16px 55px #14223933}.candidate-detail h3{margin:0;color:#203550}.candidate-detail h4{margin:7px 0 0;font-size:12px}.candidate-detail p{color:#65738a;font-size:11px}.candidate-detail dl{display:grid;grid-template-columns:150px 1fr;gap:7px;margin:5px 0;font-size:11px}.candidate-detail dt{color:#7a8799}.candidate-detail dd{margin:0;overflow-wrap:anywhere;color:#34445a}.evidence-row{display:grid;gap:4px;padding:9px;border:1px solid #e8ecf2;border-radius:8px}.evidence-row b{font-size:11px;text-transform:capitalize}.evidence-row span,.evidence-row small{font-size:10px;overflow-wrap:anywhere;color:#65738a}.match-card{display:grid;gap:5px;padding:10px;border:1px solid #dce5f0;border-radius:8px;font-size:10px}.close{position:absolute;right:14px;top:14px}.success-notice{background:#ecf8f0;color:#276a45;padding:10px;border-radius:8px;font-size:11px}.notice:not(.success-notice){background:#fff4ed;color:#9b4f32;padding:10px;border-radius:8px}
.source-gates{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.source-gates span{display:flex;justify-content:space-between;gap:8px;padding:7px;border:1px solid #e5eaf1;border-radius:7px;color:#65748a;font-size:9px}.source-gates b{color:#304869;text-transform:capitalize}.source-choice{display:grid;gap:6px;margin:0;padding:10px;border:1px solid #e3e9f1;border-radius:9px}.source-choice legend{padding:0 4px;color:#586a81;font-size:11px;font-weight:700}.source-choice p{margin:0}.source-choice .check{font-weight:500}.catalog-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.catalog-grid article,.provider-progress article{display:grid;gap:4px;min-width:0;padding:9px;border:1px solid #e5eaf1;border-radius:8px;background:#fafbfd}.catalog-grid article b,.provider-progress article b{font-size:10px;color:#33465f}.catalog-grid article small,.provider-progress article small,.provider-progress article span{font-size:9px;line-height:1.4;color:#738198;overflow-wrap:anywhere}.catalog-grid article.prohibited{background:#fff8f5;border-color:#f0d8d1}.provider-progress{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}
@media(max-width:800px){.lead-discovery-grid{grid-template-columns:1fr}.import-controls{grid-template-columns:1fr 1fr}.import-controls button{width:100%}.metric-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:600px){.pilot-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:800px){.start-discovery{grid-template-columns:1fr}.start-controls{grid-template-columns:1fr 1fr}.start-controls button{grid-column:1/-1}}
@media(max-width:520px){.lead-discovery-heading,.panel-title{display:grid}.safe-tag{justify-self:start}.panel{padding:12px}.import-controls,.start-controls{grid-template-columns:1fr}.start-controls button{grid-column:auto}.two-col,.catalog-grid{grid-template-columns:1fr}.metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.candidate-detail dl{grid-template-columns:1fr;gap:3px}.candidate-detail dd{margin-bottom:7px}.candidate-modal{padding:8px}.geo-filter{grid-template-columns:auto minmax(0,1fr)}.geo-filter small{grid-column:1/-1}.geography-detail div{grid-template-columns:1fr;gap:2px}}
</style>
