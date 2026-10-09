<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

type Conversation = {
  id: string; status: string; channel: string; intent: string | null; intent_confidence: number | null
  ownership_state?: string; conversation_stage?: string; sales_opportunity_id?: string | null
  ai_summary: string | null; recommended_next_action: string | null; recommendation_reason: string | null
  recommendation_evidence: string[] | null; handoff_reason: string | null; updated_at: string
  company?: { id: string; name: string; industry: string | null; location: string | null; description?: string | null }
  contact?: { id: string; name: string | null; title: string | null; source_url?: string | null }
}
type Message = { id: string; direction: string; body: string; delivery_status: string | null; created_at: string; intent: string | null; intent_confidence: number | null }
type Evidence = { id: string; evidence_type: string; source_url: string | null; excerpt: string | null; confidence: number; statement: string }
type MeetingSlot = { id: string; starts_at: string; ends_at: string; timezone: string }
type SchedulingConfiguration = { provider: string; enabled: boolean; default_timezone: string; owner_timezone: string; meeting_duration_minutes: number; buffer_before_minutes: number; buffer_after_minutes: number; minimum_notice_minutes: number; maximum_horizon_days: number; allowed_weekdays: number[] | string; working_hours_start: string; working_hours_end: string; meeting_title_template: string; meeting_description_template?: string | null; default_owner_user_id?: number | null }
type AgentDecision = { id: string; action: string; reason: string; confidence: number; evidence_references: string[] | null; requires_human_approval: boolean; status: string }
type ReplyDraft = { id: string; subject: string; body: string; status: string }
type SalesDraft = { id: string; conversation_id: string; status: string; body: string; intent: string; risk_level: string; confidence: number; missing_information: string[]; evidence_references: string[]; knowledge_references: string[] }
type Qualification = { score: number; level: string; components: Record<string, { level: string; score: number }> }
type SalesAnalysis = { intent: string; risk_level: string; confidence: number; missing_information: string[]; evidence_references: string[]; knowledge_references: string[]; qualification: { score: Qualification }; sales_draft: SalesDraft | null; requires_human_review: boolean; conversation: Conversation }

const props = defineProps<{ tenantId: string; focusConversationId?: string }>()
const conversations = ref<Conversation[]>([])
const selected = ref<Conversation | null>(null)
const messages = ref<Message[]>([])
const evidence = ref<Evidence[]>([])
const meetings = ref<{ id: string; status: string; timezone: string; starts_at: string; ends_at: string; meeting_url?: string | null }[]>([])
const decision = ref<AgentDecision | null>(null)
const replyDraft = ref<ReplyDraft | null>(null)
const salesDraft = ref<SalesDraft | null>(null)
const salesQualification = ref<Qualification | null>(null)
const salesResult = ref<SalesAnalysis | null>(null)
const salesPipeline = ref<{ stage: string; count: number }[]>([])
const handoffMode = ref(false)
const slots = ref<MeetingSlot[]>([])
const selectedSlot = ref('')
const schedulingRequestId = ref('')
const schedulingRequestStatus = ref('')
const schedulingRequestDuration = ref<number | null>(null)
const schedulingRequestTimezone = ref('')
const hasActionableSchedulingRequest = computed(() => Boolean(schedulingRequestId.value) && ['REQUESTED', 'AWAITING_SELECTION', 'SELECTED', 'FAILED'].includes(schedulingRequestStatus.value))
const schedulingConfiguration = ref<SchedulingConfiguration>({ provider: 'fake', enabled: false, default_timezone: 'UTC', owner_timezone: 'UTC', meeting_duration_minutes: 30, buffer_before_minutes: 0, buffer_after_minutes: 0, minimum_notice_minutes: 60, maximum_horizon_days: 30, allowed_weekdays: [1,2,3,4,5], working_hours_start: '09:00', working_hours_end: '17:00', meeting_title_template: 'Discovery meeting with {{company}}' })
const leadScore = ref<{ score: number; components: string; rule_version: number } | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const selectedEvidence = computed(() => evidence.value.filter((item) => selected.value?.recommendation_evidence?.includes(item.id)))
const weekdays = [{ value: 1, label: 'Mon' }, { value: 2, label: 'Tue' }, { value: 3, label: 'Wed' }, { value: 4, label: 'Thu' }, { value: 5, label: 'Fri' }, { value: 6, label: 'Sat' }, { value: 7, label: 'Sun' }]

function xsrfToken(): string {
  return decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
}

async function request(path: string, method = 'GET', body?: unknown, idempotencyKey?: string) {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Tenant-ID': props.tenantId }
  if (method !== 'GET') headers['X-XSRF-TOKEN'] = xsrfToken()
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey
  const response = await fetch(`/api/v1${path}`, { method, credentials: 'include', headers, ...(body === undefined ? {} : { body: JSON.stringify(body) }) })
  const result = await response.json()
  if (!response.ok) throw new Error(result.message ?? `Request failed (${response.status}).`)
  return result
}

async function loadList() {
  loading.value = true; error.value = ''
  try {
    const result = await request(handoffMode.value ? '/handoff-queue' : '/conversations')
    conversations.value = result.data ?? []
    const preferred = conversations.value.find((conversation) => conversation.id === props.focusConversationId)
    if (preferred) await loadConversation(preferred.id)
    else if (selected.value && conversations.value.some((conversation) => conversation.id === selected.value?.id)) await loadConversation(selected.value.id)
    else if (conversations.value.length) await loadConversation(conversations.value[0].id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load conversations.' }
  finally { loading.value = false }
}

async function loadConversation(id: string) {
  error.value = ''
  try {
    const result = await request(`/conversations/${encodeURIComponent(id)}`)
    selected.value = result.conversation; messages.value = result.messages ?? []; evidence.value = result.evidence ?? []; leadScore.value = result.lead_score; meetings.value = result.meetings ?? []; schedulingRequestId.value = result.scheduling_request?.id ?? ''; schedulingRequestStatus.value = result.scheduling_request?.status ?? ''; schedulingRequestDuration.value = result.scheduling_request?.duration_minutes ?? null; schedulingRequestTimezone.value = result.scheduling_request?.timezone ?? ''; decision.value = result.decision; replyDraft.value = result.reply_draft
    const drafts = await request('/sales-drafts')
    salesDraft.value = result.sales_draft ?? (drafts ?? []).find((draft: SalesDraft) => draft.conversation_id === id && draft.status === 'pending') ?? null
    salesQualification.value = result.sales_opportunity?.qualification?.score ?? null
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load conversation.' }
}

async function analyzeSales() {
  if (!selected.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request(`/conversations/${encodeURIComponent(selected.value.id)}/sales-analysis`, 'POST', {}, crypto.randomUUID())
    salesResult.value = result; salesQualification.value = result.qualification?.score ?? null; salesDraft.value = result.sales_draft; selected.value = result.conversation
    notice.value = `Sales analysis complete · ${result.intent.replaceAll('_', ' ')} · ${result.risk_level} risk${result.requires_human_review ? ' · human review required' : ''}`
    await loadList(); await loadSalesPipeline()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to analyze sales conversation.' }
  finally { busy.value = false }
}

async function reviewSalesDraft(action: 'approve' | 'reject') {
  if (!salesDraft.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request(`/sales-drafts/${encodeURIComponent(salesDraft.value.id)}/${action}`, 'POST', {})
    salesDraft.value.status = result.status; notice.value = action === 'approve' ? 'Draft approved for human use. It has not been sent.' : 'Draft rejected.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : `Unable to ${action} sales draft.` }
  finally { busy.value = false }
}

async function regenerateSalesDraft() {
  if (!salesDraft.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request(`/sales-drafts/${encodeURIComponent(salesDraft.value.id)}/regenerate`, 'POST', {}, crypto.randomUUID())
    salesResult.value = result; salesDraft.value = result.sales_draft; salesQualification.value = result.qualification?.score ?? salesQualification.value
    notice.value = 'Replacement sales draft generated for review. It has not been sent.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to regenerate sales draft.' }
  finally { busy.value = false }
}

async function returnToAi() {
  if (!selected.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try { selected.value = await request(`/conversations/${encodeURIComponent(selected.value.id)}/return-to-ai`, 'POST', {}); notice.value = 'AI assistance resumed. No message was sent.'; await loadList() }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to return conversation to AI assistance.' }
  finally { busy.value = false }
}

async function loadSalesPipeline() {
  try { salesPipeline.value = (await request('/sales-pipeline')).stages ?? [] }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load sales pipeline.' }
}

async function toggleHandoffQueue() { handoffMode.value = !handoffMode.value; await loadList() }

async function changeOpportunityStage(stage: string) {
  if (!selected.value?.sales_opportunity_id) return
  busy.value = true; error.value = ''; notice.value = ''
  try { await request(`/opportunities/${encodeURIComponent(selected.value.sales_opportunity_id)}/stage`, 'PUT', { stage }); selected.value.conversation_stage = stage; notice.value = `Opportunity moved to ${stage.replaceAll('_', ' ')}.`; await loadSalesPipeline() }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to update opportunity stage.' }
  finally { busy.value = false }
}

async function markQualification(qualified: boolean) {
  if (!selected.value?.sales_opportunity_id) return
  busy.value = true; error.value = ''; notice.value = ''
  try { await request(`/opportunities/${encodeURIComponent(selected.value.sales_opportunity_id)}/${qualified ? 'qualified' : 'not-qualified'}`, 'POST', {}); notice.value = qualified ? 'Opportunity marked qualified.' : 'Opportunity marked not qualified.'; await loadSalesPipeline() }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to update qualification status.' }
  finally { busy.value = false }
}

async function classify() {
  if (!selected.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request(`/conversations/${encodeURIComponent(selected.value.id)}/classify`, 'POST')
    selected.value = result.conversation
    notice.value = `${result.analysis.intent.replaceAll('_', ' ')} · ${Math.round(result.analysis.confidence * 100)}% confidence · ${result.analysis.recommended_action.replaceAll('_', ' ')}`
    await loadList()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to analyze conversation.' }
  finally { busy.value = false }
}

async function createReplyDraft() {
  if (!selected.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    replyDraft.value = await request(`/conversations/${encodeURIComponent(selected.value.id)}/reply-drafts`, 'POST', {})
    notice.value = 'AI reply draft created. Review it before approving.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to draft a reply.' }
  finally { busy.value = false }
}

async function approveReplyDraft() {
  if (!selected.value || !replyDraft.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await request(`/conversations/${encodeURIComponent(selected.value.id)}/reply-drafts/${encodeURIComponent(replyDraft.value.id)}/approve`, 'POST', {})
    replyDraft.value.status = 'approved'
    notice.value = 'Reply approved and queued through the fake email provider.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to approve reply.' }
  finally { busy.value = false }
}

async function saveReplyDraft() {
  if (!selected.value || !replyDraft.value) return
  busy.value = true; error.value = ''
  try {
    replyDraft.value = await request(`/conversations/${encodeURIComponent(selected.value.id)}/reply-drafts/${encodeURIComponent(replyDraft.value.id)}`, 'PUT', { subject: replyDraft.value.subject, body: replyDraft.value.body })
    notice.value = 'Reply draft saved.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save draft.' }
  finally { busy.value = false }
}

async function handoffAction(action: 'takeover' | 'resolve') {
  if (!selected.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request(`/conversations/${encodeURIComponent(selected.value.id)}/${action}`, 'POST')
    selected.value = result
    notice.value = action === 'takeover' ? 'Conversation assigned to you. Automated campaign sends are stopped.' : 'Conversation resolved.'
    await loadList()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : `Unable to ${action} conversation.` }
  finally { busy.value = false }
}

async function decideDecision(action: 'approve' | 'reject') {
  if (!decision.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    decision.value = await request(`/agent-decisions/${encodeURIComponent(decision.value.id)}/${action}`, 'POST', {})
    notice.value = `Recommendation ${action === 'approve' ? 'approved' : 'rejected'}. Approval only records review; autonomous execution is not enabled.`
  } catch (exception) { error.value = exception instanceof Error ? exception.message : `Unable to ${action} recommendation.` }
  finally { busy.value = false }
}

async function loadSchedulingConfiguration() {
  try { schedulingConfiguration.value = await request('/scheduling-configuration') }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load scheduling configuration.' }
}

async function saveSchedulingConfiguration() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    schedulingConfiguration.value = await request('/scheduling-configuration', 'PUT', schedulingConfiguration.value)
    notice.value = 'Scheduling configuration saved.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save scheduling configuration.' }
  finally { busy.value = false }
}

async function loadAvailability() {
  if (!selected.value) return
  busy.value = true; error.value = ''; slots.value = []; selectedSlot.value = ''
  try {
    if (!schedulingRequestId.value) {
      const created = await request(`/conversations/${encodeURIComponent(selected.value.id)}/scheduling-requests`, 'POST', { timezone: schedulingConfiguration.value.default_timezone })
      schedulingRequestId.value = created.id; schedulingRequestStatus.value = created.status ?? 'REQUESTED'
    }
    slots.value = (await request(`/scheduling-requests/${encodeURIComponent(schedulingRequestId.value)}/availability`, 'POST', {})).slots ?? []
    if (!slots.value.length) notice.value = 'No provider availability matched the tenant scheduling rules.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load availability.' }
  finally { busy.value = false }
}

async function bookMeeting() {
  if (!selected.value || !selectedSlot.value) return
  const slot = slots.value.find((item) => item.id === selectedSlot.value)
  if (!slot) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await request(`/scheduling-requests/${encodeURIComponent(schedulingRequestId.value)}/select-slot`, 'POST', { slot_id: slot.id })
    await request(`/scheduling-requests/${encodeURIComponent(schedulingRequestId.value)}/book`, 'POST', { slot_id: slot.id })
    notice.value = 'Meeting booked.'
    slots.value = []; selectedSlot.value = ''
    schedulingRequestId.value = ''; schedulingRequestStatus.value = ''; schedulingRequestDuration.value = null; schedulingRequestTimezone.value = ''
    await loadConversation(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to book meeting.' }
  finally { busy.value = false }
}

async function cancelSchedulingRequest() {
  if (!schedulingRequestId.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await request(`/scheduling-requests/${encodeURIComponent(schedulingRequestId.value)}/cancel`, 'POST', {})
    schedulingRequestId.value = ''; slots.value = []; selectedSlot.value = ''
    notice.value = 'Meeting request cancelled.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to cancel scheduling request.' }
  finally { busy.value = false }
}

async function updateMeetingStatus(meetingId: string, status: 'CANCELLED' | 'COMPLETED' | 'NO_SHOW') {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    if (status === 'CANCELLED') await request(`/meetings/${encodeURIComponent(meetingId)}/cancel`, 'POST', { confirmed: true })
    else await request(`/meetings/${encodeURIComponent(meetingId)}/status`, 'PATCH', { status })
    notice.value = `Meeting marked ${status.toLowerCase().replace('_', ' ')}.`
    if (selected.value) await loadConversation(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to update meeting.' }
  finally { busy.value = false }
}

watch(() => [props.tenantId, props.focusConversationId], () => { selected.value = null; conversations.value = []; salesDraft.value = null; salesResult.value = null; void loadList(); void loadSchedulingConfiguration(); void loadSalesPipeline() })
onMounted(() => { void loadList(); void loadSchedulingConfiguration(); void loadSalesPipeline() })
</script>

<template>
  <div class="conversation-workspace">
    <div v-if="error" class="notice">{{ error }} <button class="quiet" @click="loadList">Retry</button></div>
    <div v-if="notice" class="notice success-notice">{{ notice }}</div>
    <div class="conversation-layout">
      <section class="panel conversation-inbox">
        <div class="panel-heading"><div><h2>{{ handoffMode ? 'Human handoff queue' : 'Sales inbox' }}</h2><p>Customer replies and sales conversations, scoped to this tenant. Unread state is not currently provided.</p></div><div class="conversation-actions"><button class="quiet" @click="toggleHandoffQueue">{{ handoffMode ? 'All conversations' : 'Needs human review' }}</button><button class="quiet" @click="loadList">↻ Refresh</button></div></div>
        <button v-for="item in conversations" :key="item.id" class="conversation-choice" :class="{ selected: selected?.id === item.id }" @click="loadConversation(item.id)">
          <span class="conversation-choice-title"><b>{{ item.contact?.name || item.company?.name || 'Conversation' }}</b><span class="pill">{{ item.status.replaceAll('_', ' ') }}</span></span>
          <small>{{ item.company?.name }} · {{ item.intent?.replaceAll('_', ' ') || item.channel }}</small>
          <span class="conversation-preview">{{ item.ai_summary || 'No AI summary yet.' }}</span>
        </button>
        <div v-if="!conversations.length" class="empty-table">{{ loading ? 'Loading conversations…' : 'No conversations yet. Accepted campaign messages will appear here.' }}</div>
      </section>

      <template v-if="selected">
        <section class="panel conversation-detail">
          <div class="panel-heading"><div><div class="eyebrow">{{ selected.channel.toUpperCase() }} CONVERSATION</div><h2>{{ selected.contact?.name || selected.company?.name }}</h2><p>{{ selected.contact?.title || 'Contact' }} · {{ selected.company?.name }}<span v-if="selected.company?.industry"> · {{ selected.company.industry }}</span><span v-if="selected.company?.location"> · {{ selected.company.location }}</span></p></div><span class="pill">{{ selected.status.replaceAll('_', ' ') }}</span></div>
          <div class="conversation-analysis">
            <div><small>DETECTED INTENT</small><b>{{ selected.intent?.replaceAll('_', ' ') || 'Not analyzed' }}</b></div>
            <div><small>CONFIDENCE</small><b>{{ selected.intent_confidence == null ? '—' : `${Math.round(selected.intent_confidence * 100)}%` }}</b></div>
            <div><small>LEAD SCORE</small><b>{{ leadScore?.score ?? '—' }}<span v-if="leadScore"> / 100</span></b></div>
          </div>
          <div class="message-timeline">
            <h3>Message timeline</h3>
            <article v-for="message in messages" :key="message.id" class="timeline-message" :class="message.direction"><div><b>{{ message.direction === 'inbound' ? 'Customer' : 'Yaandu' }}</b><small>{{ message.delivery_status || message.created_at }}</small></div><p>{{ message.body }}</p></article>
            <div v-if="!messages.length" class="empty-table">No messages have been recorded.</div>
          </div>
          <section class="sales-review-panel">
            <div class="panel-heading"><div><h3>Sales qualification</h3><p>Grounded analysis and human-reviewed drafts.</p></div><button class="primary" :disabled="busy || selected.status === 'resolved' || selected.ownership_state === 'HUMAN_ACTIVE'" @click="analyzeSales">{{ busy ? 'Working…' : 'Analyze sales reply' }}</button></div>
            <div v-if="salesQualification" class="conversation-analysis"><div><small>AI QUALIFICATION · ADVISORY</small><b>{{ salesQualification.score }} / 100 · {{ salesQualification.level }}</b></div><div v-for="(item, key) in salesQualification.components" :key="key"><small>{{ key }}</small><b>{{ item.level }} · {{ item.score }}</b></div></div>
            <div v-if="salesResult" class="sales-result"><small>AI analysis · salesperson review required</small><p><b>{{ salesResult.intent }}</b> · {{ salesResult.risk_level }} risk · {{ salesResult.confidence }} confidence · {{ selected.conversation_stage?.replaceAll('_', ' ') || 'NEW' }}</p><p>Missing: {{ salesResult.missing_information.join(' · ') || 'None identified' }}</p></div>
            <article v-if="salesDraft" class="sales-draft"><div><b>Sales draft</b><span class="pill">{{ salesDraft.status }} · {{ salesDraft.risk_level }} risk</span></div><p>{{ salesDraft.body }}</p><small>{{ salesDraft.intent }} · {{ Math.round(salesDraft.confidence * 100) }}% confidence</small><p v-if="salesDraft.missing_information.length">Still to learn: {{ salesDraft.missing_information.join(' · ') }}</p><div class="conversation-actions"><button class="quiet" :disabled="busy" @click="regenerateSalesDraft">Regenerate</button><button v-if="salesDraft.status === 'pending'" class="quiet" :disabled="busy" @click="reviewSalesDraft('reject')">Reject draft</button><button v-if="salesDraft.status === 'pending'" class="primary" :disabled="busy" @click="reviewSalesDraft('approve')">Approve for human use</button></div></article>
            <div v-if="selected.ownership_state === 'HUMAN_ACTIVE'" class="conversation-actions"><button class="quiet" :disabled="busy" @click="returnToAi">Return to AI assistance</button></div>
            <div v-if="selected.sales_opportunity_id" class="conversation-actions"><label>Opportunity stage<select :value="selected.conversation_stage || 'NEW'" :disabled="busy" @change="changeOpportunityStage(($event.target as HTMLSelectElement).value)"><option v-for="stage in ['NEW','ENGAGED','DISCOVERY','QUALIFIED','MEETING_READY','PROPOSAL_READY','CLOSED','NOT_QUALIFIED']" :key="stage" :value="stage">{{ stage.replaceAll('_', ' ') }}</option></select></label><button class="quiet" :disabled="busy" @click="markQualification(true)">Mark qualified</button><button class="quiet" :disabled="busy" @click="markQualification(false)">Mark not qualified</button></div>
            <div class="sales-pipeline"><b>Opportunity pipeline</b><span v-for="stage in salesPipeline" :key="stage.stage">{{ stage.stage.replaceAll('_', ' ') }} <strong>{{ stage.count }}</strong></span></div>
          </section>
          <div v-if="selected.ai_summary" class="conversation-recommendation"><small>AI SUMMARY</small><p>{{ selected.ai_summary }}</p><b>Next action: {{ selected.recommended_next_action?.replaceAll('_', ' ') }}</b><p>{{ selected.recommendation_reason }}</p><div v-if="selectedEvidence.length" class="citation-list"><small>SUPPORTING EVIDENCE</small><a v-for="item in selectedEvidence" :key="item.id" :href="item.source_url || undefined" target="_blank" rel="noopener noreferrer">{{ item.statement }} · {{ item.excerpt }}</a></div></div>
          <div v-if="decision" class="decision-card"><div><small>AUTONOMOUS ACTION RECOMMENDATION</small><span class="pill">{{ decision.status.replaceAll('_', ' ') }}</span></div><b>{{ decision.action.replaceAll('_', ' ') }}</b><p>{{ decision.reason }}</p><small>{{ Math.round(decision.confidence * 100) }}% confidence{{ decision.requires_human_approval ? ' · human review required' : '' }}</small><div v-if="decision.status === 'pending_approval'" class="conversation-actions"><button class="primary" :disabled="busy" @click="decideDecision('approve')">Approve recommendation</button><button class="quiet" :disabled="busy" @click="decideDecision('reject')">Reject recommendation</button></div></div>
          <div v-if="selected.handoff_reason" class="notice">Human review required: {{ selected.handoff_reason.replaceAll('_', ' ') }}</div>
          <div class="conversation-actions"><button v-if="['ai_active','human_review'].includes(selected.status)" class="primary" :disabled="busy" @click="handoffAction('takeover')">Take over</button><button v-if="selected.status === 'human_active'" class="quiet" :disabled="busy" @click="handoffAction('resolve')">Resolve</button><button class="quiet" :disabled="busy || selected.status === 'resolved'" @click="classify">{{ busy ? 'Working…' : 'Analyze latest conversation' }}</button></div>
          <section v-if="selected.status === 'human_active'" class="reply-draft-panel"><div class="panel-heading"><div><h3>AI reply draft</h3><p>Review every draft. Approval queues delivery through the fake provider.</p></div><button class="quiet" :disabled="busy" @click="createReplyDraft">Create draft</button></div><template v-if="replyDraft"><label>Subject<input v-model="replyDraft.subject" maxlength="180" :disabled="replyDraft.status !== 'draft'" /></label><label>Message<textarea v-model="replyDraft.body" rows="6" maxlength="5000" :disabled="replyDraft.status !== 'draft'"></textarea></label><div class="conversation-actions"><span class="pill">{{ replyDraft.status.replaceAll('_', ' ') }}</span><button v-if="replyDraft.status === 'draft'" class="quiet" :disabled="busy" @click="saveReplyDraft">Save edits</button><button v-if="replyDraft.status === 'draft'" class="primary" :disabled="busy" @click="approveReplyDraft">Approve and send</button></div></template></section>
          <details class="scheduling-settings"><summary>Scheduling configuration</summary><div class="scheduling-config-form"><label><input v-model="schedulingConfiguration.enabled" type="checkbox"/> Enable fake scheduling provider</label><label>Tenant timezone<select v-model="schedulingConfiguration.default_timezone"><option value="UTC">UTC</option><option value="Asia/Kolkata">Asia/Kolkata</option><option value="America/New_York">America/New_York</option><option value="Europe/London">Europe/London</option></select></label><label>Calendar owner timezone<select v-model="schedulingConfiguration.owner_timezone"><option value="UTC">UTC</option><option value="Asia/Kolkata">Asia/Kolkata</option><option value="America/New_York">America/New_York</option><option value="Europe/London">Europe/London</option></select></label><label>Duration<select v-model.number="schedulingConfiguration.meeting_duration_minutes"><option :value="30">30 minutes</option><option :value="45">45 minutes</option><option :value="60">60 minutes</option></select></label><label>Minimum notice (minutes)<input v-model.number="schedulingConfiguration.minimum_notice_minutes" type="number" min="0" max="10080"/></label><label>Booking horizon (days)<input v-model.number="schedulingConfiguration.maximum_horizon_days" type="number" min="1" max="90"/></label><label>Buffer before (minutes)<input v-model.number="schedulingConfiguration.buffer_before_minutes" type="number" min="0" max="120"/></label><label>Buffer after (minutes)<input v-model.number="schedulingConfiguration.buffer_after_minutes" type="number" min="0" max="120"/></label><label>Workday start<input v-model="schedulingConfiguration.working_hours_start" type="time"/></label><label>Workday end<input v-model="schedulingConfiguration.working_hours_end" type="time"/></label><label v-for="day in weekdays" :key="day.value"><input v-model="schedulingConfiguration.allowed_weekdays" type="checkbox" :value="day.value"/> {{ day.label }}</label><label>Meeting title template<input v-model="schedulingConfiguration.meeting_title_template" maxlength="180"/></label><label>Description template<textarea v-model="schedulingConfiguration.meeting_description_template" maxlength="2000" rows="2"></textarea></label><label>Default owner user ID<input v-model.number="schedulingConfiguration.default_owner_user_id" type="number" min="1"/></label><button class="quiet" :disabled="busy" @click="saveSchedulingConfiguration">Save scheduling settings</button></div></details>
          <section v-if="selected.intent?.toLowerCase() === 'meeting_request' || hasActionableSchedulingRequest" class="meeting-planner"><div class="panel-heading"><div><h3>Meeting request <span v-if="schedulingRequestStatus" class="status-badge">{{ schedulingRequestStatus.replaceAll('_', ' ') }}</span></h3><p v-if="hasActionableSchedulingRequest">Approved scheduling request · {{ schedulingRequestDuration ?? schedulingConfiguration.meeting_duration_minutes }} minutes · {{ schedulingRequestTimezone || schedulingConfiguration.default_timezone }}. Availability comes from the configured provider; booking requires an explicit human action.</p><p v-else>Availability comes from the configured provider. Booking requires an explicit human action.</p></div><div class="conversation-actions"><button v-if="schedulingRequestId" class="quiet" :disabled="busy" @click="cancelSchedulingRequest">Cancel request</button><button class="quiet" :disabled="busy || !schedulingConfiguration.enabled" @click="loadAvailability">{{ schedulingRequestId ? 'Refresh availability' : 'Generate availability' }}</button></div></div><div v-if="slots.length" class="meeting-slot-picker"><label>Available time<select v-model="selectedSlot"><option value="" disabled>Select a time</option><option v-for="slot in slots" :key="slot.id" :value="slot.id">{{ new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short', timeZone: slot.timezone }).format(new Date(slot.starts_at)) }} · {{ slot.timezone }}</option></select></label><button class="primary" :disabled="busy || !selectedSlot" @click="bookMeeting">Select and book</button></div><div v-if="meetings.length" class="meeting-list"><article v-for="meeting in meetings" :key="meeting.id"><b>{{ meeting.status }}</b><span>{{ new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short', timeZone: meeting.timezone }).format(new Date(meeting.starts_at)) }} · {{ meeting.timezone }}</span><div v-if="meeting.status === 'SCHEDULED'" class="conversation-actions"><button class="quiet" :disabled="busy" @click="updateMeetingStatus(meeting.id, 'COMPLETED')">Completed</button><button class="quiet" :disabled="busy" @click="updateMeetingStatus(meeting.id, 'NO_SHOW')">No show</button><button class="quiet" :disabled="busy" @click="updateMeetingStatus(meeting.id, 'CANCELLED')">Cancel</button></div><a v-if="meeting.meeting_url" :href="meeting.meeting_url" target="_blank" rel="noopener noreferrer">Join meeting</a></article></div></section>
        </section>
        <section class="panel conversation-evidence-panel">
          <div class="panel-heading"><div><h2>Lead context</h2><p>Website and lead evidence available to the classifier.</p></div></div>
          <p v-if="selected.company?.description" class="company-context">{{ selected.company.description }}</p>
          <div v-if="evidence.length" class="conversation-evidence-list"><article v-for="item in evidence" :key="item.id"><b>{{ item.statement }}</b><p>{{ item.excerpt || 'No excerpt stored.' }}</p><a v-if="item.source_url" :href="item.source_url" target="_blank" rel="noopener noreferrer">View source ↗</a><small>{{ item.evidence_type }} · {{ Math.round(Number(item.confidence) * 100) }}% confidence</small></article></div>
          <div v-else class="empty-table">No evidence is available for this company yet.</div>
        </section>
      </template>
      <section v-else class="panel conversation-detail empty-table">Select a conversation to inspect its messages and lead context.</section>
    </div>
  </div>
</template>
