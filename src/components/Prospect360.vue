<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { humanize, salesRequest, unwrap } from '../salesApi'
import WebsiteIntelligenceView from './WebsiteIntelligenceView.vue'
import OutreachDraftPanel from './OutreachDraftPanel.vue'
import EmptyState from './ui/EmptyState.vue'
import { deriveLeadPriority } from '../leadPriority'

const props = defineProps<{ tenantId: string; companyId: string }>()
const emit = defineEmits<{ back: []; openInbox: [conversationId?: string]; openPipeline: [opportunityId?: string]; openMeetings: []; openOutreach: []; openProposals: [] }>()
const company = ref<any | null>(null)
const intelligence = ref<any | null>(null)
const contacts = ref<any[]>([])
const conversations = ref<any[]>([])
const opportunities = ref<any[]>([])
const meetings = ref<any[]>([])
const proposals = ref<any[]>([])
const activeServices = ref<any[]>([])
const salesIntelligenceMode = ref('human_assisted')
const salesReview = ref<any | null>(null)
const salesReviewLoadError = ref(false)
const salesReviewDraft = ref({ decision: '', selected_service_id: '', notes: '' })
const salesReviewSaving = ref(false)
const salesReviewNotice = ref('')
const websiteDiscovery = ref<any>(null)
const findingWebsite = ref(false)
const websiteDiscoveryNotice = ref('')
const workflowNotice = ref('')
const scanningWebsite = ref(false)
const scoringLead = ref(false)
const screenshotUrls = ref<{ id: string; url: string; viewport?: string; captured_at?: string; status?: string }[]>([])
const loading = ref(true)
const error = ref('')
const tab = ref('Overview')
const reviewSaving = ref(false)
const reviewNotice = ref('')
const reviewDraft = ref({ intelligence_rating: '', intelligence_rubric_score: null as number | null, lead_score_rating: '', recommendation_rating: '',
  technology_accuracy_rating: 'unknown', next_action_rating: 'unavailable', claim_reviews: [] as Array<{ claim: string; status: string; source_url: string; notes: string }>,
  unsupported_claim_count: 0, reviewer_notes: '' })
// The import evidence QA workflow is retained for local/internal review only.
const showInternalEvidenceReview = import.meta.env.DEV && new URLSearchParams(window.location.search).get('internal_evidence_review') === '1'
const tabs = ['Overview', 'Website', 'Contacts', 'Outreach', 'Activity']
const currentOpportunity = computed(() => opportunities.value.find((row) => row.company_id === props.companyId) ?? null)
const currentContact = computed(() => contacts.value[0] ?? null)
const latestScore = computed(() => intelligence.value?.scores?.[0] ?? null)
const scanFailureSummary = computed(() => {
  const category = intelligence.value?.latest_scan?.failure_category
  if (category === 'ROBOTS') return 'Crawl permission could not be verified from robots.txt.'
  if (category === 'HTTP_4XX') return 'The website returned a client error during the latest scan.'
  if (category === 'SSRF_POLICY' || category === 'SSRF_REJECTED') return 'The website destination did not pass safety checks.'
  if (intelligence.value?.latest_scan?.status === 'failed') return 'The latest scan did not complete; no findings are available.'
  return null
})
const leadPriority = computed(() => deriveLeadPriority(latestScore.value, intelligence.value?.latest_scan))
const latestStructuredIntelligence = computed(() => intelligence.value?.intelligence_results?.[0]?.structured_output ?? null)
const serviceRecommendations = computed(() => Array.isArray(latestStructuredIntelligence.value?.service_recommendations)
  ? latestStructuredIntelligence.value.service_recommendations : [])
const positiveScoreFactors = computed(() => componentRows(latestScore.value?.components).filter((row) => row.status === 'POSITIVE' || (!row.status && Number(row.value) > 0)))
const businessFitFactors = computed(() => positiveScoreFactors.value.filter((row) => ['Ideal customer fit', 'Relevant industry'].includes(row.key) && Boolean(row.evidence)))
const existingCapabilities = computed(() => componentRows(latestScore.value?.components).filter((row) => row.status === 'NEGATIVE'))
const opportunityIssueTypes = new Set(['poor_mobile_ux', 'outdated_website', 'poor_lead_capture', 'no_whatsapp', 'no_crm', 'technology_opportunity', 'strong_business_fit'])
const observedOpportunityFindings = computed(() => (intelligence.value?.issues ?? [])
  .filter((row: any) => row.detector_version === 'deterministic-v1' && opportunityIssueTypes.has(row.type))
  .map((row: any) => ({ key: humanize(row.type || row.summary), evidence: row.summary || 'Deterministic website evidence is available.' }))
  .filter((row: any, index: number, rows: any[]) => rows.findIndex((candidate) => candidate.key === row.key) === index))
const opportunitySummaryRows = computed(() => {
  const scored = positiveScoreFactors.value.filter((row) => !['Ideal customer fit', 'Relevant industry'].includes(row.key) && Boolean(row.evidence) && (row.key !== 'Strong business fit' || row.evidence))
    .map((row) => ({ key: row.key, evidence: row.evidence, value: row.value }))
  const all = [...scored, ...observedOpportunityFindings.value]
  return all.filter((row, index) => all.findIndex((candidate) => candidate.key === row.key) === index).slice(0, 5)
})
const deterministicCapabilities = computed(() => (intelligence.value?.technologies ?? [])
  .filter((row: any) => row.detection_method === 'deterministic-v1')
  .map((row: any) => ({ key: String(row.name || 'Observed capability'), evidence: 'Observed in deterministic website evidence.' }))
  .filter((row: any, index: number, rows: any[]) => rows.findIndex((candidate) => candidate.key === row.key) === index))
const scoreParts = computed(() => componentRows(latestScore.value?.components))
const displayOpportunitySignalCount = computed(() => opportunitySummaryRows.value.length)

function componentRows(value: any) {
  if (!value) return []
  let parsed = value
  if (typeof parsed === 'string') { try { parsed = JSON.parse(parsed) } catch { return [] } }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return []
  return Object.entries(parsed).map(([key, data]: [string, any]) => ({ key: dimensionLabel(key), status: typeof data === 'object' ? data?.status : undefined, value: typeof data === 'number' ? data : (data?.points ?? data?.score ?? data?.value ?? 0), evidence: formatEvidence(data?.evidence_reference ?? data?.evidence ?? data?.reference ?? data?.reason) }))
}

function dimensionLabel(key: string): string {
  const labels: Record<string, string> = {
    icp_fit: 'Ideal customer fit', outdated_website: 'Outdated website', poor_mobile_ux: 'Poor mobile UX',
    whatsapp_gap: 'WhatsApp capability', no_whatsapp: 'WhatsApp capability', crm_gap: 'CRM capability', no_crm: 'CRM capability',
    ecommerce_gap: 'E-commerce capability', no_ecommerce: 'E-commerce capability', lead_capture_gap: 'Lead capture capability',
    technology_opportunity: 'Technology opportunity', strong_business_fit: 'Strong business fit', relevant_industry: 'Relevant industry',
  }
  return labels[key] || humanize(key)
}

function scoreStatusLabel(status?: string): string {
  return ({ POSITIVE: 'Opportunity found', NEGATIVE: 'Capability present', UNKNOWN: 'Unknown / insufficient evidence', NOT_CONFIGURED: 'Not configured' } as Record<string, string>)[status || ''] || 'Unknown / insufficient evidence'
}

function formatEvidence(value: any): string | null {
  if (value === null || value === undefined || value === '') return null
  if (typeof value !== 'object') return String(value)
  if (Array.isArray(value)) return value.map(formatEvidence).filter(Boolean).join(' · ') || null
  const fields = ['source', 'field', 'observed', 'value', 'criteria', 'name', 'url']
  const parts = fields.filter((key) => value[key] !== null && value[key] !== undefined && value[key] !== '')
    .map((key) => `${humanize(key)}: ${key === 'url' ? safeEvidenceUrl(value[key]) : Array.isArray(value[key]) ? value[key].join(', ') : String(value[key])}`)
    .filter((part) => !part.endsWith(': '))
  return parts.join(' · ') || null
}

function safeEvidenceUrl(value: unknown): string {
  if (typeof value !== 'string') return ''
  try { const url = new URL(value); if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return ''; url.search = ''; url.hash = ''; return url.toString() }
  catch { return '' }
}

function recommendationEvidence(recommendation: any) {
  const evidence = Array.isArray(latestStructuredIntelligence.value?.evidence) ? latestStructuredIntelligence.value.evidence : []
  const ids = Array.isArray(recommendation?.evidence_ids) ? recommendation.evidence_ids : []
  return evidence.filter((item: any) => ids.includes(item.evidence_id))
}


async function load() {
  loading.value = true; error.value = ''; salesReviewLoadError.value = false
  try {
    const requests = await Promise.allSettled([
      salesRequest(`/companies/${encodeURIComponent(props.companyId)}`, props.tenantId),
      salesRequest(`/companies/${encodeURIComponent(props.companyId)}/intelligence`, props.tenantId),
      salesRequest(`/contacts?company_id=${encodeURIComponent(props.companyId)}`, props.tenantId),
      salesRequest('/conversations', props.tenantId), salesRequest('/opportunities', props.tenantId),
      salesRequest('/meetings', props.tenantId), salesRequest('/proposals', props.tenantId),
      salesRequest(`/companies/${encodeURIComponent(props.companyId)}/website-discovery`, props.tenantId),
      salesRequest('/tenant-services', props.tenantId), salesRequest('/pilot/sales-intelligence-mode', props.tenantId),
      salesRequest(`/companies/${encodeURIComponent(props.companyId)}/sales-review`, props.tenantId),
    ])
    if (requests[0].status === 'rejected') throw requests[0].reason
    company.value = requests[0].value
    const review = showInternalEvidenceReview ? company.value?.import_provenance : null
    if (requests[8].status === 'fulfilled') activeServices.value = unwrap(requests[8].value).filter((service) => service.active !== false)
    if (requests[9].status === 'fulfilled') salesIntelligenceMode.value = requests[9].value.sales_intelligence_mode || 'human_assisted'
    if (requests[10].status === 'fulfilled') {
      salesReview.value = requests[10].value.review
      salesReviewDraft.value = { decision: salesReview.value?.decision || '', selected_service_id: salesReview.value?.selected_service_id || '', notes: salesReview.value?.notes || '' }
    } else {
      salesReview.value = null
      salesReviewDraft.value = { decision: '', selected_service_id: '', notes: '' }
      salesReviewLoadError.value = true
    }
    if (review) reviewDraft.value = {
      intelligence_rating: review.intelligence_rating ?? '', lead_score_rating: review.lead_score_rating ?? '',
      recommendation_rating: review.recommendation_rating ?? '', unsupported_claim_count: Number(review.unsupported_claim_count ?? 0),
      intelligence_rubric_score: review.intelligence_rubric_score === null ? null : Number(review.intelligence_rubric_score),
      technology_accuracy_rating: review.technology_accuracy_rating ?? 'unknown', next_action_rating: review.next_action_rating ?? 'unavailable',
      claim_reviews: typeof review.claim_reviews === 'string' ? JSON.parse(review.claim_reviews || '[]') : (review.claim_reviews ?? []),
      reviewer_notes: review.reviewer_notes ?? '',
    }
    if (requests[1].status === 'fulfilled') {
      intelligence.value = requests[1].value
      screenshotUrls.value.forEach((item) => URL.revokeObjectURL(item.url)); screenshotUrls.value = []
      await Promise.all((intelligence.value.screenshots ?? []).filter((item: any) => item.status === 'stored').map(async (item: any) => {
        try {
          const response = await fetch(`/api/v1/website-screenshots/${encodeURIComponent(item.id)}/content`, { credentials: 'include', headers: { Accept: 'image/png', 'X-Tenant-ID': props.tenantId } })
          if (response.ok) screenshotUrls.value.push({ id: item.id, url: URL.createObjectURL(await response.blob()), viewport: item.viewport, captured_at: item.captured_at, status: item.status })
        } catch { /* An optional screenshot must not block the prospect workspace. */ }
      }))
    }
    if (requests[2].status === 'fulfilled') contacts.value = unwrap(requests[2].value).filter((row) => row.company_id === props.companyId)
    if (requests[3].status === 'fulfilled') conversations.value = unwrap(requests[3].value).filter((row) => row.company_id === props.companyId)
    if (requests[4].status === 'fulfilled') opportunities.value = unwrap(requests[4].value).filter((row) => row.company_id === props.companyId)
    if (requests[5].status === 'fulfilled') meetings.value = unwrap(requests[5].value).filter((row) => conversations.value.some((conversation) => conversation.id === row.conversation_id))
    if (requests[6].status === 'fulfilled') proposals.value = unwrap(requests[6].value).filter((row) => opportunities.value.some((opportunity) => opportunity.id === row.sales_opportunity_id))
    if (requests[7].status === 'fulfilled') websiteDiscovery.value = requests[7].value
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load this prospect.' }
  finally { loading.value = false }
}

async function savePilotReview() {
  const provenance = company.value?.import_provenance
  if (!provenance?.import_row_id) return
  reviewSaving.value = true; error.value = ''; reviewNotice.value = ''
  try {
    await salesRequest(`/pilot/import-rows/${encodeURIComponent(provenance.import_row_id)}/review`, props.tenantId, 'POST', reviewDraft.value)
    reviewNotice.value = 'Human review saved with reviewer and timestamp.'
    await load()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save pilot review.' }
  finally { reviewSaving.value = false }
}

async function saveSalesReview() {
  if (!salesReviewDraft.value.decision) return
  salesReviewSaving.value = true; error.value = ''; salesReviewNotice.value = ''
  try {
    const result = await salesRequest(`/companies/${encodeURIComponent(props.companyId)}/sales-review`, props.tenantId, 'PUT', {
      decision: salesReviewDraft.value.decision, selected_service_id: salesReviewDraft.value.selected_service_id || null,
      notes: salesReviewDraft.value.notes || null,
    })
    salesReview.value = result.review
    salesReviewDraft.value = { decision: result.review.decision, selected_service_id: result.review.selected_service_id || '', notes: result.review.notes || '' }
    salesReviewNotice.value = 'Sales decision saved. AI priority remains advisory and unchanged.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save the sales decision.' }
  finally { salesReviewSaving.value = false }
}

function addClaimReview() { reviewDraft.value.claim_reviews.push({ claim: '', status: 'unable_to_verify', source_url: '', notes: '' }) }
function removeClaimReview(index: number) { reviewDraft.value.claim_reviews.splice(index, 1) }

async function findWebsite() {
  findingWebsite.value = true; error.value = ''; websiteDiscoveryNotice.value = ''
  try {
    await salesRequest(`/companies/${encodeURIComponent(props.companyId)}/website-discovery`, props.tenantId, 'POST', { idempotency_key: crypto.randomUUID() })
    websiteDiscoveryNotice.value = 'Website discovery queued. Candidate domains will be checked and matched before they can be confirmed.'
    websiteDiscovery.value = await salesRequest(`/companies/${encodeURIComponent(props.companyId)}/website-discovery`, props.tenantId)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to find website candidates.' }
  finally { findingWebsite.value = false }
}

async function analyzeWebsite() {
  const website = company.value?.websites?.[0]
  if (!website) { error.value = 'Add a website before starting analysis.'; return }
  scanningWebsite.value = true; error.value = ''; workflowNotice.value = ''
  try {
    const queued = await salesRequest(`/websites/${encodeURIComponent(website.id)}/scan`, props.tenantId, 'POST', { max_pages: 10, max_depth: 2 })
    workflowNotice.value = `Website analysis queued (${queued.scan_id}).`
    for (let attempt = 0; attempt < 40; attempt++) {
      await new Promise((resolve) => setTimeout(resolve, 1000))
      const result = await salesRequest(`/companies/${encodeURIComponent(props.companyId)}/intelligence`, props.tenantId)
      intelligence.value = result
      if (result.latest_scan?.id === queued.scan_id && ['completed', 'partial', 'failed'].includes(result.latest_scan.status)) break
    }
    await load()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to analyze this website.' }
  finally { scanningWebsite.value = false }
}

async function scoreLead() {
  scoringLead.value = true; error.value = ''; workflowNotice.value = ''
  try {
    const queued = await salesRequest(`/companies/${encodeURIComponent(props.companyId)}/score`, props.tenantId, 'POST')
    workflowNotice.value = `Lead scoring queued (${queued.id}).`
    for (let attempt = 0; attempt < 30; attempt++) {
      await new Promise((resolve) => setTimeout(resolve, 700))
      const run = await salesRequest(`/agent-runs/${encodeURIComponent(queued.id)}`, props.tenantId)
      if (['succeeded', 'failed'].includes(run.status)) break
    }
    await load()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to score this prospect.' }
  finally { scoringLead.value = false }
}

async function reviewWebsiteCandidate(action: 'confirm' | 'reject' | 'mark_unresolved', candidate?: any) {
  const resolution = websiteDiscovery.value?.resolution
  if (!resolution) return
  findingWebsite.value = true; error.value = ''; websiteDiscoveryNotice.value = ''
  try {
    await salesRequest(`/discovery/website-resolutions/${encodeURIComponent(resolution.id)}/review`, props.tenantId, 'POST', {
      action, candidate_id: candidate?.id, reason: action === 'mark_unresolved' ? 'Prospect reviewer could not confirm an official website.' : undefined,
    })
    websiteDiscoveryNotice.value = action === 'confirm' ? 'Website confirmed by a human reviewer; safe verification continues.' : action === 'reject' ? 'Candidate rejected and retained as evidence.' : 'Website search marked unresolved.'
    websiteDiscovery.value = await salesRequest(`/companies/${encodeURIComponent(props.companyId)}/website-discovery`, props.tenantId)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to review this website candidate.' }
  finally { findingWebsite.value = false }
}

watch(() => props.companyId, () => { tab.value = 'Overview'; void load() })
onMounted(() => { void load() })
onBeforeUnmount(() => screenshotUrls.value.forEach((item) => URL.revokeObjectURL(item.url)))
</script>

<template>
  <section class="prospect360">
    <button class="back-link" @click="emit('back')">← Back to Prospects</button>
    <div v-if="error" class="notice">{{ error }} <button @click="load">Retry</button></div>
    <div v-if="loading" class="sales-state"><span class="spinner"></span><b>Loading prospect workspace</b><small>Gathering the company’s available sales context…</small></div>
    <template v-else-if="company">
      <header class="prospect-hero"><div class="prospect-monogram">{{ company.name?.slice(0,1)?.toUpperCase() || 'P' }}</div><div class="prospect-identity"><span class="eyebrow">PROSPECT</span><h2>{{ company.name }}</h2><p>{{ company.industry || 'Industry not recorded' }}<span>·</span>{{ company.location || 'Location not recorded' }}<span>·</span>{{ humanize(company.status) }}</p><a v-if="company.websites?.length" :href="company.websites[0].url" target="_blank" rel="noopener noreferrer">{{ company.websites[0].url }}</a><span v-else class="muted">Website not added</span></div><div class="prospect-highlights"><div><small>STATUS</small><b>{{ humanize(company.status) }}</b></div><div><small>PUBLIC CONTACT</small><b>{{ currentContact?.name || currentContact?.title || 'Not identified' }}</b></div><div><small>OPPORTUNITY</small><b>{{ currentOpportunity ? humanize(currentOpportunity.stage) : 'Not created' }}</b></div></div><div class="hero-actions"><small class="mode-label">{{ humanize(salesIntelligenceMode) }} · Human review required</small><button v-if="!company.websites?.length" class="quiet" :disabled="findingWebsite" @click="findWebsite">{{ findingWebsite ? 'Searching…' : 'Find Website' }}</button><button class="quiet" @click="emit('openOutreach')">Plan outreach</button><button class="quiet" @click="emit('openInbox', conversations[0]?.id)">Open inbox</button></div></header>
      <section class="opportunity-summary-card" aria-labelledby="opportunity-summary-title">
        <div class="opportunity-summary-heading"><div><span class="eyebrow">OPPORTUNITY SUMMARY</span><h3 id="opportunity-summary-title">Evidence-backed reasons to review</h3><p>Signals below come from the saved score and existing website evidence.</p></div><button class="text-button" @click="tab = 'Website'">Review evidence →</button></div>
        <div class="opportunity-summary-columns">
          <div><h4>Business fit</h4><ul v-if="businessFitFactors.length" class="summary-signals"><li v-for="row in businessFitFactors" :key="row.key"><span class="signal-mark fit-mark">✓</span><div><b>{{ row.key }}</b><small>{{ row.evidence }}</small></div></li></ul><p v-else class="inline-empty">No business-fit evidence is recorded yet.</p></div>
          <div><h4>Opportunity signals</h4><ul v-if="opportunitySummaryRows.length" class="summary-signals"><li v-for="row in opportunitySummaryRows" :key="row.key"><span class="signal-mark opportunity-mark">+</span><div><b>{{ row.key }}</b><small>{{ row.evidence }}</small></div></li></ul><p v-else-if="leadPriority.key === 'low_confidence' && businessFitFactors.length" class="inline-empty">Good business fit, but insufficient evidence of a specific digital opportunity.</p><p v-else-if="leadPriority.key === 'blocked'" class="inline-empty">No usable website evidence is available, so no opportunity signals are shown.</p><p v-else class="inline-empty">No evidence-backed opportunity signal is recorded yet.</p></div>
          <div><h4>Existing capabilities</h4><ul v-if="existingCapabilities.length || deterministicCapabilities.length" class="summary-signals"><li v-for="row in existingCapabilities.slice(0, 4)" :key="row.key"><span class="signal-mark capability-mark">✓</span><div><b>{{ row.key }}</b><small>{{ row.evidence || 'This capability was found; it is not a negative lead signal.' }}</small></div></li><li v-for="row in deterministicCapabilities.slice(0, 4)" :key="`observed-${row.key}`"><span class="signal-mark capability-mark">✓</span><div><b>{{ row.key }}</b><small>{{ row.evidence }}</small></div></li></ul><p v-else-if="leadPriority.key === 'blocked'" class="inline-empty">No usable website evidence is available to confirm existing capabilities.</p><p v-else class="inline-empty">No existing capability is confirmed by the available evidence.</p></div>
        </div>
      </section>
      <section class="priority-summary" :data-priority="leadPriority.key" aria-label="Derived lead priority" role="status">
        <div><small>LEAD SCORE</small><b>{{ leadPriority.score === null ? '—' : `${leadPriority.score} / 100` }}</b></div>
        <div><small>EVIDENCE COVERAGE</small><b>{{ leadPriority.coverage === null ? '—' : `${leadPriority.coverage}%` }}</b></div>
        <div><small>OPPORTUNITY STRENGTH</small><b>{{ displayOpportunitySignalCount ? `${displayOpportunitySignalCount} evidenced opportunity signal${displayOpportunitySignalCount === 1 ? '' : 's'}` : 'No evidenced opportunity signal' }}</b></div>
        <div><small>DERIVED PRIORITY</small><b class="priority-pill" :class="leadPriority.key">{{ leadPriority.label }}</b></div>
        <div><small>EVIDENCE CONFIDENCE</small><b>{{ leadPriority.confidenceLabel }}</b></div>
        <div><small>HUMAN SALES REVIEW</small><b class="human-decision-pill" :class="salesReviewLoadError ? 'unavailable' : salesReview?.decision || 'not-reviewed'">{{ salesReviewLoadError ? 'Unavailable' : salesReview ? humanize(salesReview.decision) : 'Not Reviewed' }}</b></div>
        <p v-for="reason in leadPriority.reasons" :key="reason">{{ reason }}</p>
      </section>
      <nav class="prospect-tabs" aria-label="Prospect details"><button v-for="item in tabs" :key="item" :class="{ active: tab === item }" @click="tab = item">{{ item }}<span v-if="item === 'Contacts'">{{ contacts.length }}</span><span v-if="item === 'Activity'">{{ conversations.length + meetings.length }}</span></button></nav>
      <template v-if="tab === 'Overview'">
        <div v-if="websiteDiscoveryNotice" class="notice success-notice">{{ websiteDiscoveryNotice }}</div>
        <div v-if="workflowNotice" class="notice success-notice" role="status">{{ workflowNotice }}</div>
        <section class="panel prospect-card sales-review-card" aria-labelledby="sales-review-title">
          <div class="panel-heading"><div><span class="eyebrow">HUMAN SALES DECISION</span><h3 id="sales-review-title">What should happen with this prospect?</h3><p>Record your decision separately from the advisory AI priority. Saving does not start outreach, crawling, scoring, or AI work.</p></div><span v-if="salesReviewLoadError" class="review-status pending">Review unavailable</span><span v-else-if="salesReview" class="review-status">{{ humanize(salesReview.decision) }}</span><span v-else class="review-status pending">No decision yet</span></div>
          <div v-if="salesReviewLoadError" class="sales-review-load-error" role="alert"><b>Saved sales review could not be loaded.</b><span>Retry this prospect before recording a new decision.</span><button class="quiet" type="button" @click="load">Retry</button></div>
          <div class="ai-advisory-in-review"><div><small>AI STATUS · ADVISORY</small><b class="priority-pill" :class="leadPriority.key">{{ leadPriority.label }}</b></div><div><small>LEAD SCORE</small><b>{{ leadPriority.score === null ? '—' : `${leadPriority.score} / 100` }}</b></div><div><small>EVIDENCE COVERAGE</small><b>{{ leadPriority.coverage === null ? '—' : `${leadPriority.coverage}%` }}</b></div><div><small>EVIDENCE CONFIDENCE</small><b>{{ leadPriority.confidenceLabel }}</b></div><div><small>OPPORTUNITY STRENGTH</small><b>{{ displayOpportunitySignalCount }} evidenced signal{{ displayOpportunitySignalCount === 1 ? '' : 's' }}</b></div><div v-if="leadPriority.failureReason" class="ai-blocked-reason"><small>REASON</small><b>{{ leadPriority.failureReason }}</b></div></div>
          <form class="sales-review-form" @submit.prevent="saveSalesReview">
            <label>Sales decision<select v-model="salesReviewDraft.decision" required aria-describedby="sales-review-help"><option value="" disabled>Select a decision…</option><option value="pursue">Pursue</option><option value="hold">Hold</option><option value="reject">Reject</option><option value="needs_more_evidence">Needs more evidence</option></select></label>
            <label>Relevant service <span class="optional-label">Optional</span><select v-model="salesReviewDraft.selected_service_id"><option value="">No service selected</option><option v-for="service in activeServices" :key="service.id" :value="service.id">{{ service.name }}</option></select></label>
            <label class="sales-review-notes">Decision notes <span class="optional-label">Optional</span><textarea v-model="salesReviewDraft.notes" maxlength="4000" placeholder="Add concise business context for the next salesperson." /></label>
            <div class="sales-review-actions"><small id="sales-review-help">This human decision never changes the score or derived priority.</small><button class="primary" type="submit" :disabled="salesReviewLoadError || salesReviewSaving || !salesReviewDraft.decision">{{ salesReviewSaving ? 'Saving…' : salesReview ? 'Update sales decision' : 'Save sales decision' }}</button></div>
          </form>
          <p v-if="salesReviewNotice" class="notice success-notice" role="status">{{ salesReviewNotice }}</p>
          <small v-if="salesReview" class="sales-review-meta">Reviewed by {{ salesReview.reviewer_name || 'Workspace user' }} · {{ salesReview.reviewed_at }}</small>
        </section>
        <section v-if="showInternalEvidenceReview && company.import_provenance" class="panel prospect-card pilot-review">
          <div class="panel-heading"><div><h3>Evidence review</h3><p>Compare imported intelligence and scores with public sources. This review does not change lead scoring.</p></div><span class="review-status">{{ humanize(company.import_provenance.review_status) }}</span></div>
          <div class="review-source"><b>Provenance</b><a v-if="company.import_provenance.source_url" :href="company.import_provenance.source_url" target="_blank" rel="noopener noreferrer">{{ company.import_provenance.source_url }}</a><small>Collected: {{ company.import_provenance.collected_at || 'Not recorded' }} · Imported by {{ company.import_provenance.imported_by || 'Unavailable' }}</small><p>{{ company.import_provenance.provenance_note || 'No provenance note recorded.' }}</p></div>
          <p v-if="reviewNotice" class="notice success-notice" role="status">{{ reviewNotice }}</p>
          <div class="review-fields">
            <label>Intelligence rating<select v-model="reviewDraft.intelligence_rating"><option value="">Select…</option><option value="strong">Strong</option><option value="useful">Useful</option><option value="needs_improvement">Needs improvement</option><option value="poor">Poor</option><option value="unable_to_assess">Unable to assess</option></select></label>
            <label>17-point intelligence rubric<input v-model.number="reviewDraft.intelligence_rubric_score" type="number" min="0" max="17" placeholder="0–17 or leave blank"/></label>
            <label>Lead score rating<select v-model="reviewDraft.lead_score_rating"><option value="">Select…</option><option value="reasonable">Reasonable</option><option value="slightly_high">Slightly high</option><option value="slightly_low">Slightly low</option><option value="clearly_wrong">Clearly wrong</option><option value="insufficient_evidence">Insufficient evidence</option></select></label>
            <label>Recommendation rating<select v-model="reviewDraft.recommendation_rating"><option value="">Select…</option><option value="strongly_relevant">Strongly relevant</option><option value="possibly_relevant">Possibly relevant</option><option value="weak">Weak</option><option value="unsupported">Unsupported</option><option value="unable_to_assess">Unable to assess</option></select></label>
            <label>Technology detection<select v-model="reviewDraft.technology_accuracy_rating"><option value="correct">Correct</option><option value="incorrect">Incorrect</option><option value="unknown">Unknown</option><option value="not_detected">No technology detected</option></select></label>
            <label>Next action rating<select v-model="reviewDraft.next_action_rating"><option value="useful">Useful</option><option value="acceptable">Acceptable</option><option value="weak">Weak</option><option value="incorrect">Incorrect</option><option value="unavailable">Unavailable</option></select></label>
            <label>Unsupported factual claims<input v-model.number="reviewDraft.unsupported_claim_count" type="number" min="0" max="500"/></label>
            <label class="review-notes">Reviewer notes<textarea v-model="reviewDraft.reviewer_notes" maxlength="4000" placeholder="Record overall observations and why fields could not be assessed. Avoid personal data."/></label>
          </div>
          <div class="claim-review-header"><div><h4>Significant factual claims</h4><small>Record each report claim with its evidence status and source. Recommendations are rated separately.</small></div><button class="quiet" @click="addClaimReview">＋ Add claim</button></div>
          <article v-for="(claim, index) in reviewDraft.claim_reviews" :key="index" class="claim-review-row">
            <label>Claim<textarea v-model="claim.claim" maxlength="2000" placeholder="Factual claim from the report"/></label>
            <label>Evidence status<select v-model="claim.status"><option value="supported">Supported</option><option value="partially_supported">Partially supported</option><option value="unsupported">Unsupported</option><option value="unable_to_verify">Unable to verify</option></select></label>
            <label>Source URL<input v-model="claim.source_url" type="url" maxlength="2048" placeholder="https://…"/></label>
            <label>Reviewer note<textarea v-model="claim.notes" maxlength="2000" placeholder="Evidence excerpt or reason"/></label>
            <button class="quiet" @click="removeClaimReview(index)">Remove claim</button>
          </article>
          <button class="primary" :disabled="reviewSaving" @click="savePilotReview">{{ reviewSaving ? 'Saving…' : 'Save human review' }}</button>
          <small v-if="company.import_provenance.reviewer_name || company.import_provenance.reviewed_at" class="muted">Reviewed by {{ company.import_provenance.reviewer_name || 'unavailable' }} · {{ company.import_provenance.reviewed_at || '' }}</small>
        </section>
        <section v-if="!company.websites?.length && websiteDiscovery?.resolution" class="panel prospect-card prospect-website-discovery"><div class="panel-heading"><div><h3>Website candidates</h3><p>{{ humanize(websiteDiscovery.resolution.discovery_status || websiteDiscovery.resolution.state) }} · Candidate suggestions remain unconfirmed until technical verification, identity matching and review policy allow it.</p></div><button v-if="['FAILED','UNRESOLVED','NO_CANDIDATES','AMBIGUOUS'].includes(websiteDiscovery.resolution.state)" class="quiet" :disabled="findingWebsite" @click="findWebsite">Retry discovery</button></div><article v-for="candidate in websiteDiscovery.candidates || []" :key="candidate.id" class="website-candidate-card"><div><b>{{ candidate.normalized_domain }}</b><a :href="candidate.candidate_url" target="_blank" rel="noopener noreferrer">{{ candidate.candidate_url }}</a><small>Discovery rank #{{ candidate.discovery_rank || '—' }} · discovery signal {{ candidate.discovery_score ?? '—' }}/100 · identity confidence {{ candidate.score }}/100 ({{ candidate.confidence_band }}) · {{ candidate.match_summary?.discovery?.source_count || 0 }} source(s)</small><small>Verification: {{ humanize(candidate.status) }}</small><p v-for="evidence in candidate.evidence || []" :key="evidence.id">{{ evidence.summary }}</p></div><div v-if="candidate.status === 'proposed'" class="drawer-actions"><button class="quiet" :disabled="findingWebsite" @click="reviewWebsiteCandidate('reject', candidate)">Reject</button><button class="primary" :disabled="findingWebsite" @click="reviewWebsiteCandidate('confirm', candidate)">Confirm Website</button></div></article><details v-if="websiteDiscovery.search_results?.length"><summary>Source evidence</summary><article v-for="result in websiteDiscovery.search_results" :key="result.id" class="website-source-evidence"><b>{{ result.title || result.source }}</b><small>{{ humanize(result.source) }} · {{ humanize(result.result_type) }}</small><p>{{ result.snippet || 'No excerpt provided.' }}</p><small v-if="result.result_url" class="untrusted-source-url">Source URL: {{ result.result_url }}</small><details v-if="result.query_text"><summary>Query provenance</summary><small>{{ result.query_text }}</small></details></article></details><div class="drawer-actions"><button v-if="!['RESOLVED','UNRESOLVED'].includes(websiteDiscovery.resolution.state)" class="quiet" :disabled="findingWebsite" @click="reviewWebsiteCandidate('mark_unresolved')">Mark Unresolved</button></div></section>
        <div class="prospect-overview-grid"><section class="panel prospect-card"><div class="panel-heading"><div><h3>Prospect overview</h3><p>Known details and the available context for this company.</p></div></div><dl class="prospect-facts"><dt>Company</dt><dd>{{ company.name }}</dd><dt>Industry</dt><dd>{{ company.industry || 'Not recorded' }}</dd><dt>Location</dt><dd>{{ company.location || 'Not recorded' }}</dd><dt>Company description</dt><dd>{{ company.description || 'No company description recorded.' }}</dd><dt>Source</dt><dd>{{ humanize(company.source) }}</dd></dl><div v-if="company.import_provenance" class="import-provenance"><h4>Import provenance</h4><dl class="prospect-facts"><dt>Imported by</dt><dd>{{ company.import_provenance.imported_by || 'Former or unavailable user' }}</dd><dt>Received</dt><dd>{{ company.import_provenance.received_at || 'Not recorded' }}</dd><dt>Processed</dt><dd>{{ company.import_provenance.processed_at || 'Pending' }}</dd><dt>Submission</dt><dd>{{ company.import_provenance.file_name }} · row {{ company.import_provenance.row_number }}</dd><dt>Original website</dt><dd>{{ company.import_provenance.original_website || 'Not recorded' }}</dd><dt>Source</dt><dd>{{ company.import_provenance.source || 'Not recorded' }}</dd><dt>Cohort</dt><dd>{{ company.import_provenance.cohort_name || 'No cohort' }}</dd><dt>Website status</dt><dd>Imported and unverified</dd></dl></div></section><section class="panel prospect-card"><div class="panel-heading"><div><h3>Lead score breakdown</h3><p>Business fit, opportunity signals, existing capabilities, and evidence gaps are shown separately.</p></div><button class="text-button" @click="tab = 'Website'">Review evidence</button></div><div v-if="latestScore" class="fit-score"><strong>{{ latestScore.score ?? '—' }}</strong><span v-if="latestScore.score !== null">/ 100</span><small>{{ latestScore.evaluation_status === 'insufficient_evidence' ? 'Insufficient evidence' : `Scored ${latestScore.scored_at}` }}<span v-if="latestScore.evidence_coverage !== null"> · Evidence coverage {{ latestScore.evidence_coverage }}%</span></small></div><div v-else class="inline-empty">No score is recorded yet.</div><h4 v-if="businessFitFactors.length">Business fit</h4><ul v-if="businessFitFactors.length" class="factor-list"><li v-for="row in businessFitFactors" :key="row.key"><span class="capability-check">✓</span><b>{{ row.key }}</b><span>Supported</span><small v-if="row.evidence">{{ row.evidence }}</small></li></ul><h4 v-if="opportunitySummaryRows.length">Opportunity signals</h4><ul v-if="opportunitySummaryRows.length" class="factor-list"><li v-for="row in opportunitySummaryRows" :key="row.key"><span class="factor-check">+</span><b>{{ row.key }}</b><span>{{ Number(row.value) > 0 ? `+${row.value}` : '' }}</span><small>{{ row.evidence }}</small></li></ul><h4 v-if="existingCapabilities.length">Existing capabilities</h4><ul v-if="existingCapabilities.length" class="factor-list capability-factor-list"><li v-for="row in existingCapabilities" :key="row.key"><span class="capability-check">✓</span><b>{{ row.key }}</b><span>Capability present</span><small v-if="row.evidence">{{ row.evidence }}</small></li></ul><details v-if="scoreParts.length"><summary>Full score details</summary><ul class="factor-list"><li v-for="row in scoreParts" :key="row.key"><span class="score-status" :class="(row.status || 'unknown').toLowerCase()">{{ scoreStatusLabel(row.status) }}</span><b>{{ row.key }}</b><span>{{ row.status === 'UNKNOWN' || row.status === 'NOT_CONFIGURED' ? '—' : row.value }}</span><small v-if="row.evidence">{{ row.evidence }}</small></li></ul><small class="muted">Scoring rule version {{ latestScore.rule_version }}</small></details></section></div>
        <div class="prospect-overview-grid"><section class="panel prospect-card"><div class="panel-heading"><div><h3>What we found</h3><p>Factual website findings from the latest available scan.</p></div><button class="text-button" @click="tab = 'Website'">View website details</button></div><div v-if="intelligence?.issues?.length"><article v-for="issue in intelligence.issues.slice(0,3)" :key="issue.id" class="finding-row"><span class="pill">{{ humanize(issue.severity) }}</span><div><b>{{ issue.summary }}</b><small>{{ humanize(issue.type) }} · {{ Math.round(Number(issue.confidence ?? 0) * 100) }}% confidence</small><small v-if="issue.evidence">Evidence is available in website details.</small></div></article></div><p v-else class="inline-empty">{{ scanFailureSummary || (intelligence?.latest_scan ? 'No findings were recorded for the latest scan.' : 'No Website Intelligence has been recorded yet.') }}</p></section><section class="panel prospect-card"><div class="panel-heading"><div><h3>People and activity</h3><p>Contact records and recent sales activity. Provenance is shown in Contacts.</p></div></div><div v-if="currentContact" class="contact-summary"><b>{{ currentContact.name || 'Business contact' }}</b><span>{{ currentContact.title || 'Role not recorded' }}</span><small>{{ currentContact.methods?.length || 0 }} contact methods · source information available</small></div><p v-else class="inline-empty">No contact record is available.</p><div class="quick-links"><button @click="tab='Contacts'">Contacts ({{ contacts.length }})</button><button @click="tab='Activity'">Activity ({{ conversations.length + meetings.length }})</button><button @click="emit('openPipeline', currentOpportunity?.id)">Opportunity</button></div></section></div>
      </template>
      <template v-if="tab === 'Website'">
        <WebsiteIntelligenceView :company="company" :intelligence="intelligence" :screenshots="screenshotUrls" :loading="scanningWebsite || scoringLead"
          @refresh="load" @scan="analyzeWebsite" @score="scoreLead" />
        <p v-if="intelligence?.screenshots?.some((item: any) => item.status !== 'stored')" class="inline-empty">A screenshot is listed but its image is not available.</p>
      </template>
      <template v-if="tab === 'Contacts'"><div class="contact-grid"><article v-for="contact in contacts" :key="contact.id" class="panel contact-card"><div class="contact-avatar">{{ (contact.name || 'C').slice(0,1).toUpperCase() }}</div><div class="contact-content"><b>{{ contact.name || 'Public business contact' }}</b><small>{{ contact.title || 'Role not recorded' }}</small><div v-for="method in contact.methods ?? []" :key="method.id" class="contact-method"><span class="pill">{{ humanize(method.type) }}</span><a v-if="method.type === 'email'" :href="`mailto:${method.value}`">{{ method.value }}</a><a v-else-if="method.type === 'phone'" :href="`tel:${method.value}`">{{ method.value }}</a><span v-else>{{ method.value }}</span><small>{{ humanize(method.verification_status) }} · {{ Math.round(Number(method.confidence ?? contact.confidence ?? 0)*100) }}% confidence</small></div><small>Source: <a :href="contact.source_url" target="_blank" rel="noopener noreferrer">{{ contact.source_url || 'Not recorded' }}</a></small><small>Observed {{ contact.observed_at || contact.created_at }} · {{ humanize(contact.extraction_method) }}</small></div></article><EmptyState v-if="!contacts.length" title="No verified public contacts yet" description="No contact details are shown until a public source has been recorded and reviewed." icon="♙"/></div></template>
      <template v-if="tab === 'Outreach'"><OutreachDraftPanel :tenant-id="tenantId" :company-id="companyId" /><div class="panel prospect-card"><div class="panel-heading"><div><h3>Outreach and conversations</h3><p>Existing activity linked to this prospect.</p></div><button class="secondary" @click="emit('openOutreach')">Open outreach workspace</button></div><article v-for="conversation in conversations" :key="conversation.id" class="activity-row"><div><b>{{ humanize(conversation.intent || conversation.status) }}</b><small>{{ conversation.ai_summary || conversation.recommended_next_action || 'Conversation activity recorded.' }}</small></div><span class="pill">{{ humanize(conversation.status) }}</span><button class="text-button" @click="emit('openInbox', conversation.id)">Open conversation →</button></article><p v-if="!conversations.length" class="inline-empty">No conversation is linked to this prospect yet.</p></div></template>
      <template v-if="tab === 'Activity'"><div class="activity-columns"><section class="panel prospect-card"><h3>Sales activity</h3><article v-for="conversation in conversations" :key="conversation.id" class="activity-row"><div><b>{{ humanize(conversation.intent || conversation.status) }}</b><small>{{ conversation.ai_summary || conversation.updated_at }}</small></div><button class="text-button" @click="emit('openInbox', conversation.id)">Open inbox</button></article><p v-if="!conversations.length" class="inline-empty">No conversation activity recorded.</p></section><section class="panel prospect-card"><h3>Meetings and proposals</h3><article v-for="meeting in meetings" :key="meeting.id" class="activity-row"><div><b>{{ meeting.title || 'Meeting' }}</b><small>{{ humanize(meeting.status) }} · {{ meeting.starts_at }}</small></div><button class="text-button" @click="emit('openMeetings')">Meetings</button></article><article v-for="proposal in proposals" :key="proposal.id" class="activity-row"><div><b>Proposal</b><small>{{ humanize(proposal.status) }} · updated {{ proposal.updated_at }}</small></div><button class="text-button" @click="emit('openProposals')">Proposals</button></article><p v-if="!meetings.length && !proposals.length" class="inline-empty">No meetings or proposals recorded.</p><div class="quick-links"><button @click="emit('openPipeline', currentOpportunity?.id)">Open pipeline</button><button @click="emit('openMeetings')">Open meetings</button></div></section></div></template>
    </template>
  </section>
</template>

<style scoped>
.prospect360{display:grid;gap:10px}.back-link,.text-button{border:0;background:none;color:#405caa;cursor:pointer;padding:5px 0;font-weight:600}.back-link{justify-self:start}.prospect-hero{display:grid;grid-template-columns:auto minmax(190px,1.2fr) minmax(300px,2fr) auto;align-items:center;gap:17px;background:white;border:1px solid #e4e8ef;border-radius:14px;padding:20px}.prospect-monogram{width:48px;height:48px;display:grid;place-items:center;border-radius:13px;background:#eef2ff;color:#3f58a4;font-weight:750;font-size:20px}.prospect-identity h2{margin:4px 0;font-size:21px}.prospect-identity p{margin:4px 0;color:#77808d;font-size:12px}.prospect-identity p span{margin:0 7px}.prospect-identity a,.prospect-identity .muted{font-size:11px}.prospect-highlights{display:grid;grid-template-columns:repeat(3,minmax(75px,1fr));gap:10px}.prospect-highlights div{display:grid;gap:6px}.prospect-highlights small{font-size:9px;color:#89919d;letter-spacing:.05em}.prospect-highlights b{font-size:12px;color:#303a48}.prospect-highlights b span{font-size:9px;color:#818a98}.hero-actions{display:flex;flex-direction:column;gap:7px}.prospect-tabs{display:flex;gap:22px;border-bottom:1px solid #e0e5ec}.prospect-tabs button{padding:12px 2px;border:0;border-bottom:2px solid transparent;background:none;color:#737d8a;cursor:pointer}.prospect-tabs button.active{border-bottom-color:#465eac;color:#263d7e;font-weight:700}.prospect-tabs span{margin-left:7px;color:#939ba7;font-size:10px}.prospect-overview-grid,.activity-columns{display:grid;grid-template-columns:1fr 1fr;gap:14px}.prospect-card{padding:18px}.prospect-card h3{margin:0;font-size:15px}.prospect-card h4{margin:20px 0 10px;font-size:12px}.prospect-card .panel-heading{margin-bottom:15px}.prospect-card .panel-heading p{margin:5px 0;color:#7c8592;font-size:11px}.prospect-facts{display:grid;grid-template-columns:135px 1fr;gap:12px;margin:8px 0}.prospect-facts dt{color:#858d99;font-size:11px}.prospect-facts dd{margin:0;color:#344050;font-size:12px}.fit-score{display:flex;align-items:baseline;gap:5px}.fit-score strong{font-size:35px;color:#304986}.fit-score>span{color:#929aa5;font-size:12px}.fit-score small{margin-left:auto;color:#929aa5;font-size:10px}.factor-list{list-style:none;padding:0;margin:12px 0;display:grid;gap:10px}.factor-list li{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:8px;font-size:11px}.factor-list li small{grid-column:2/-1;color:#8a93a0}.factor-check{color:#2c9a6c}.factor-list li>span:last-of-type{color:#398867;font-weight:700}.finding-row,.activity-row{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid #eef0f4}.finding-row>div,.activity-row>div{display:grid;gap:5px;flex:1}.finding-row b,.activity-row b{font-size:12px;color:#303a48}.finding-row small,.activity-row small{font-size:10px;color:#828b98;line-height:1.5}.inline-empty{color:#838c99;font-size:12px;padding:12px 0}.contact-summary{display:grid;gap:5px;padding:12px 0}.contact-summary span,.contact-summary small{color:#858e9b;font-size:11px}.quick-links{display:flex;gap:9px;flex-wrap:wrap;margin-top:12px}.quick-links button{background:#f5f7fb;border:1px solid #e7eaf0;color:#43536d;border-radius:8px;padding:8px 10px;font-size:11px;cursor:pointer}.website-actions{display:flex;gap:7px;flex-wrap:wrap}.scan-banner{display:flex;gap:7px;align-items:center;padding:10px;background:#f7f9fc;border-radius:8px;font-size:11px}.scan-banner span{margin-left:auto;color:#8b94a0}.scanned-page{display:grid;gap:4px;padding:11px 0;border-bottom:1px solid #eff1f4;text-decoration:none}.scanned-page b{font-size:11px}.scanned-page small{color:#818b99;font-size:10px;line-height:1.5}.contact-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.contact-card{display:flex;gap:12px;padding:15px}.contact-avatar{flex:0 0 34px;height:34px;border-radius:50%;background:#edf1f9;display:grid;place-items:center;color:#4960a3;font-weight:700}.contact-content{display:grid;gap:5px;min-width:0}.contact-content>b{font-size:13px}.contact-content>small,.contact-method small{color:#858e9b;font-size:10px;overflow-wrap:anywhere}.contact-method{display:flex;flex-wrap:wrap;gap:7px;align-items:center;padding:3px 0;font-size:11px}.contact-method .pill{font-size:9px}.activity-row{align-items:center}.activity-row .pill{white-space:nowrap}.activity-row .text-button{font-size:10px;white-space:nowrap}.prospect-card details{margin-top:10px}.prospect-card details summary{color:#5e6c82;font-size:10px;cursor:pointer}.prospect-card pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f6f7fa;padding:10px;border-radius:7px;font-size:10px}.prospect-card .sales-state{min-height:120px}
.opportunity-summary-card{display:grid;gap:15px;padding:18px 20px;background:#fff;border:1px solid var(--line);border-radius:var(--radius-md);box-shadow:0 1px 2px #13274706;min-width:0}.opportunity-summary-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.opportunity-summary-heading h3{margin:1px 0;font-size:16px}.opportunity-summary-heading p{margin-top:5px;color:var(--muted);font-size:12px}.opportunity-summary-columns{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.opportunity-summary-columns h4{margin:0 0 8px;font-size:12px;color:#46556a}.summary-signals{list-style:none;margin:0;padding:0;display:grid;gap:9px}.summary-signals li{display:flex;gap:9px;align-items:flex-start;min-width:0}.summary-signals li>div{display:grid;gap:3px;min-width:0}.summary-signals b{font-size:12px;color:#27384f}.summary-signals small{font-size:11px;color:#718096;line-height:1.4;overflow-wrap:anywhere}.signal-mark{flex:0 0 20px;width:20px;height:20px;display:grid;place-items:center;border-radius:50%;font-size:12px;font-weight:800}.fit-mark{background:#edf3ff;color:#365a9e}.opportunity-mark{background:#e8f4ed;color:#238153}.capability-mark,.capability-check{background:#edf2f7;color:#51647d}.capability-factor-list li>span:last-of-type{color:#617087}.score-status{justify-self:start;padding:4px 7px;border-radius:12px;background:#eef2f7;color:#596a80;font-size:10px;white-space:nowrap}.score-status.positive{background:#e8f4ed;color:#24744f}.score-status.negative{background:#edf2f7;color:#51647d}.score-status.unknown,.score-status.not_configured{background:#f4f5f7;color:#6e7888}.priority-summary{grid-template-columns:repeat(6,minmax(0,1fr))}.priority-summary .human-decision-pill{justify-self:start;padding:5px 8px;border-radius:999px;background:#f0f2f5;color:#5f6b7b}.human-decision-pill.pursue{background:#e8f4ed;color:#24744f}.human-decision-pill.hold,.human-decision-pill.needs_more_evidence{background:#fff4df;color:#895f1b}.human-decision-pill.reject{background:#f0f2f5;color:#5b6473}.sales-review-card{border-color:#dce5f4}.sales-review-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px 16px}.sales-review-form label{display:grid;gap:6px;color:#4e5e74;font-size:12px;font-weight:650;min-width:0}.sales-review-form select,.sales-review-form textarea{width:100%;min-height:42px;border:1px solid #d8e0eb;border-radius:9px;padding:10px 11px;background:white;color:#26364c;font:inherit}.sales-review-form select:focus,.sales-review-form textarea:focus{outline:3px solid #dbe8ff;border-color:#4c79d1}.sales-review-form textarea{min-height:92px;resize:vertical}.sales-review-notes,.sales-review-actions{grid-column:1/-1}.optional-label{font-weight:400;color:#8995a6}.sales-review-actions{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.sales-review-actions small,.sales-review-meta{color:#718096;font-size:11px;line-height:1.5}.sales-review-actions .primary{min-width:160px}.sales-review-load-error{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 13px;border:1px solid #f0dfb8;border-radius:9px;background:#fffaf0;color:#6f582d}.sales-review-load-error b{font-size:12px}.sales-review-load-error span{font-size:11px;color:#7f6b45}.sales-review-load-error .quiet{margin-left:auto}.human-decision-pill.unavailable{background:#fff4df;color:#895f1b}.ai-advisory-in-review{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:12px 14px;background:#f7f8fb;border:1px solid #e8ecf2;border-radius:10px}.ai-advisory-in-review>div{display:grid;gap:5px}.ai-advisory-in-review small{font-size:9px;letter-spacing:.06em;color:#8290a2}.ai-advisory-in-review>div>b{font-size:12px;color:#26364c}.ai-advisory-in-review .priority-pill{display:inline-flex}.ai-blocked-reason{flex:1 1 100%;padding-top:8px;border-top:1px solid #e5e9ef}
.priority-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;padding:15px 18px;border:1px solid #e1e7f0;border-radius:12px;background:#fff}.priority-summary>div{display:grid;align-content:start;gap:6px;min-width:0}.priority-summary small{font-size:9px;letter-spacing:.05em;color:#8490a1}.priority-summary b{font-size:12px;color:#26364c;overflow-wrap:anywhere}.priority-summary p{grid-column:1/-1;margin:0;color:#69788d;font-size:11px}.priority-pill{justify-self:start;border-radius:999px;padding:6px 9px;background:#eef2f7}.priority-pill.high{background:#e7f5ee;color:#24734f}.priority-pill.medium{background:#edf3ff;color:#365a9e}.priority-pill.low_confidence{background:#fff4df;color:#94620e}.priority-pill.blocked{background:#fff0ed;color:#a44b3d}.priority-pill.review{background:#f1f3f6;color:#5a6677}
.prospect-screenshots{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:11px;margin:13px 0}.prospect-screenshots figure{margin:0;border:1px solid #e7eaf0;border-radius:9px;overflow:hidden;background:#f7f8fa}.prospect-screenshots img{display:block;width:100%;max-height:260px;object-fit:cover;object-position:top}.prospect-screenshots figcaption{padding:7px 9px;color:#818b99;font-size:9px}
.service-recommendation{display:grid;gap:7px;margin:10px 0;padding:12px;border:1px solid #e7ebf0;border-radius:8px;background:#fafbfd}.recommendation-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.service-recommendation p{margin:0;color:#4f5e72;font-size:11px;line-height:1.5}.service-recommendation small{color:#68778c;font-size:10px}.service-recommendation blockquote{margin:0;padding:8px 10px;border-left:3px solid #aab8d5;background:#fff;color:#556276;font-size:10px;line-height:1.5}.service-recommendation blockquote a{margin-left:5px}.recommendation-speculative{background:#fff4df;color:#825b17}
.prospect-website-discovery{display:grid;gap:12px}.website-candidate-card{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:13px;border:1px solid #e5e9ef;border-radius:10px;background:#fbfcfe}.website-candidate-card>div:first-child{display:grid;gap:6px;min-width:0}.website-candidate-card b{font-size:13px}.website-candidate-card a,.website-candidate-card small,.website-source-evidence small{font-size:10px;overflow-wrap:anywhere;color:#778294}.website-candidate-card p,.website-source-evidence p{margin:3px 0;color:#556276;font-size:11px;line-height:1.5}.website-source-evidence{display:grid;gap:5px;padding:10px 0;border-top:1px solid #edf0f4}.prospect-website-discovery>details>summary{font-size:11px;font-weight:650;color:#53627a;cursor:pointer}
.human-decision{display:grid;gap:10px;padding:13px;border:1px solid #cbd8ed;border-radius:9px;background:#f7faff}.human-decision h4{margin:0 0 4px;font-size:12px;color:#344d76}.human-decision p{margin:0;color:#64748b;font-size:10px;line-height:1.5}.service-checkboxes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;padding:8px;border:1px solid #dce3ec;border-radius:7px;background:#fff}.service-checkboxes label{display:flex;align-items:center;gap:6px;color:#42536b}.service-checkboxes input{width:auto;min-height:auto}.mode-label{font-size:9px;color:#68778c;text-align:center}.pilot-review{display:grid;gap:12px}.review-status{padding:6px 9px;border-radius:16px;background:#f4f6f9;color:#5d6a7c;font-size:10px;font-weight:700}.review-source{display:grid;gap:5px;padding:10px;border:1px solid #e7ebf0;border-radius:8px;background:#fafbfd;min-width:0}.review-source a,.review-source small{font-size:10px;color:#63748a;overflow-wrap:anywhere}.review-source p{font-size:11px;line-height:1.5;color:#4f5e72;margin:2px 0}.review-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.review-fields label{display:grid;gap:5px;font-size:10px;color:#68778c}.review-fields select,.review-fields input,.review-fields textarea{width:100%;min-height:36px;border:1px solid #dce3ec;border-radius:7px;padding:7px;background:#fff;color:#273a54;font:inherit}.review-fields textarea{min-height:90px;resize:vertical}.review-notes{grid-column:1/-1}
.claim-review-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.claim-review-header h4{margin:0 0 4px;font-size:12px;color:#40536e}.claim-review-header small{font-size:10px;line-height:1.4;color:#78869a}.claim-review-row{display:grid;grid-template-columns:1.3fr .8fr 1fr 1fr auto;gap:8px;align-items:start;padding:10px;border:1px solid #e7ebf0;border-radius:8px;background:#fafbfd}.claim-review-row label{display:grid;gap:5px;font-size:10px;color:#68778c;min-width:0}.claim-review-row textarea,.claim-review-row select,.claim-review-row input{width:100%;min-height:36px;border:1px solid #dce3ec;border-radius:7px;padding:7px;background:#fff;color:#273a54;font:inherit}.claim-review-row textarea{min-height:76px;resize:vertical}
@media(max-width:1050px){.prospect-hero{grid-template-columns:auto 1fr}.prospect-highlights{grid-column:1/-1;grid-row:2;grid-template-columns:repeat(3,1fr)}.hero-actions{grid-column:1/-1;flex-direction:row}.prospect-overview-grid{grid-template-columns:1fr}}
@media(max-width:900px){.claim-review-row{grid-template-columns:repeat(2,minmax(0,1fr))}.claim-review-row button{justify-self:start}}
@media(max-width:680px){.prospect-hero{padding:14px}.prospect-highlights{grid-template-columns:repeat(2,1fr)}.prospect-tabs{gap:14px;overflow:auto}.prospect-tabs button{white-space:nowrap}.prospect-facts{grid-template-columns:105px 1fr}.contact-grid,.activity-columns{grid-template-columns:1fr}.activity-row{flex-wrap:wrap}.activity-row .text-button{margin-left:auto}.website-candidate-card{display:grid}.website-candidate-card .drawer-actions{justify-content:flex-start;flex-wrap:wrap}.review-fields{grid-template-columns:1fr}.review-notes{grid-column:auto}.claim-review-row{grid-template-columns:1fr}}
@media(max-width:900px){.priority-summary{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.priority-summary{grid-template-columns:repeat(3,minmax(0,1fr));padding:13px}.priority-summary p{grid-column:1/-1}.opportunity-summary-card{padding:15px}.opportunity-summary-columns{grid-template-columns:1fr;gap:16px}.sales-review-form{grid-template-columns:1fr}.sales-review-notes,.sales-review-actions{grid-column:auto}.ai-advisory-in-review{gap:14px}.sales-review-actions{align-items:stretch}.sales-review-actions .primary{width:100%}}
@media(max-width:480px){.priority-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.prospect-highlights{grid-template-columns:repeat(2,minmax(0,1fr))}.opportunity-summary-heading .text-button{min-height:40px}.sales-review-card{padding:16px!important}.ai-advisory-in-review{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}}
</style>
