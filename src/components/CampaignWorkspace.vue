<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

type Campaign = {
  id: string
  name: string
  description: string | null
  objective: string | null
  status: string
  updated_at?: string
  enrollments_count?: number
  steps_count?: number
  timezone: string
  target_audience: Record<string, unknown>
  sending_windows: { weekdays: number[]; start: string; end: string } | null
  rate_limit_per_hour: number
  daily_limit: number
  audience?: { name: string; criteria: Record<string, unknown> } | null
  steps?: CampaignStep[]
  templates?: CampaignTemplate[]
  enrollments?: { data: CampaignEnrollment[]; total: number }
  metrics?: { enrolled: number; active: number; sent: number; delivered: number; replied: number; bounced: number; unsubscribed: number }
}
type CampaignTemplate = { id: string; name: string; subject: string | null; body: string; status: string; version: number }
type CampaignStep = { id: string; ordinal: number; delay_seconds: number; active: boolean; template?: CampaignTemplate | null }
type CampaignEnrollment = { id: string; status: string; enrolled_at: string; contact?: { name: string | null; title: string | null }; company?: { id: string; name: string } }
type Contact = { id: string; company_name: string; name: string | null; title: string | null; methods: { id: string; type: string; value: string }[] }
type MessagingConfiguration = { provider: string; enabled: boolean; from_name: string | null; from_email: string | null; reply_to_email: string | null; hourly_limit: number; daily_limit: number }
type OutboundMessage = { id: string; campaign_id: string; status: string; provider: string; safe_error: string | null; sent_at: string | null; created_at: string }
type Suppression = { id: string; identifier_type: string; identifier_hash: string; reason: string; suppressed_at: string | null }

const props = withDefaults(defineProps<{ tenantId: string; manager?: boolean }>(), { manager: false })
const emit = defineEmits<{ openProspect: [company: { id: string; name: string }] }>()
const campaigns = ref<Campaign[]>([])
const selectedId = ref('')
const selected = ref<Campaign | null>(null)
const contacts = ref<Contact[]>([])
const messagingConfiguration = ref<MessagingConfiguration>({ provider: 'fake', enabled: false, from_name: '', from_email: '', reply_to_email: '', hourly_limit: 60, daily_limit: 500 })
const outboundMessages = ref<OutboundMessage[]>([])
const suppressions = ref<Suppression[]>([])
const suppressionEmail = ref('')
const selectedMessage = ref<{ id: string; subject: string; content: string; status: string } | null>(null)
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const campaignDraft = ref({ name: '', description: '', objective: '', timezone: 'UTC', industries: '', locations: '', weekdays: '1,2,3,4,5', start: '09:00', end: '17:00', rate_limit_per_hour: 60, daily_limit: 500 })
const templateDraft = ref({ name: '', subject: '', body: '' })
const stepDraft = ref({ template_id: '', delay_seconds: 0 })
const enrollmentDraft = ref({ contact_id: '', contact_method_id: '' })
const selectedContact = computed(() => contacts.value.find((contact) => contact.id === enrollmentDraft.value.contact_id) ?? null)
const emailMethods = computed(() => selectedContact.value?.methods.filter((method) => method.type === 'email') ?? [])
function statusLabel(status: string) { return ({ draft: 'Draft', active: 'Active', paused: 'Paused', completed: 'Completed', cancelled: 'Cancelled' } as Record<string, string>)[status] ?? status }
function audienceLabel(criteria: any) { const value = criteria ?? {}; const parts = [...(value.industries ?? []).map((x: string) => `Industry: ${x}`), ...(value.locations ?? []).map((x: string) => `Location: ${x}`)]; if (value.min_score !== undefined || value.max_score !== undefined) parts.push(`Lead score ${value.min_score ?? 0}–${value.max_score ?? 100}`); return parts.length ? parts.join(' · ') : 'No audience filters configured' }

function xsrfToken(): string {
  return decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
}

async function request(path: string, method = 'GET', body?: unknown) {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Tenant-ID': props.tenantId }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) headers['X-XSRF-TOKEN'] = xsrfToken()
  const response = await fetch(`/api/v1${path}`, { method, credentials: 'include', headers, ...(body === undefined ? {} : { body: JSON.stringify(body) }) })
  const result = response.status === 204 ? null : await response.json()
  if (!response.ok) {
    const firstValidation = result?.errors ? Object.values(result.errors as Record<string, string[]>)[0]?.[0] : null
    throw new Error(firstValidation ?? result?.message ?? `Request failed (${response.status}).`)
  }
  return result
}

async function loadCampaigns() {
  loading.value = true
  error.value = ''
  try {
    const result = await request('/campaigns')
    campaigns.value = result.data ?? []
    if (selectedId.value) await loadCampaign(selectedId.value)
    else if (campaigns.value.length) await loadCampaign(campaigns.value[0].id)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Unable to load campaigns.'
  } finally {
    loading.value = false
  }
}

async function loadContacts() {
  try { contacts.value = (await request('/contacts')).data ?? [] }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load public contacts.' }
}

async function loadMessagingConfiguration() {
  try { messagingConfiguration.value = await request('/messaging-configuration') }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load messaging configuration.' }
}

async function loadCampaign(id: string) {
  selectedId.value = id
  try {
    selected.value = await request(`/campaigns/${encodeURIComponent(id)}`)
    await loadMessages()
  }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load campaign details.' }
}

async function loadMessages() {
  try {
    const result = await request(`/outbound-messages${selectedId.value ? `?campaign_id=${encodeURIComponent(selectedId.value)}` : ''}`)
    outboundMessages.value = result.data ?? []
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load outbound messages.' }
}

async function loadSuppressions() {
  try {
    const result = await request('/suppressions')
    suppressions.value = result.data ?? []
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load suppression entries.' }
}

async function addSuppression() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await request('/suppressions', 'POST', { email: suppressionEmail.value })
    suppressionEmail.value = ''
    notice.value = 'Address suppressed for this tenant; active enrollments were stopped.'
    await loadSuppressions()
    if (selectedId.value) await loadCampaign(selectedId.value)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to suppress address.' }
  finally { busy.value = false }
}

async function showMessage(id: string) {
  try { selectedMessage.value = await request(`/outbound-messages/${encodeURIComponent(id)}`) }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load message.' }
}

async function stopEnrollment(enrollment: CampaignEnrollment) {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/enrollments/${enrollment.id}/stop`, 'POST', {})
    notice.value = 'Enrollment stopped.'
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to stop enrollment.' }
  finally { busy.value = false }
}

async function reorderStep(step: CampaignStep, direction: -1 | 1) {
  if (!selected.value?.steps) return
  const ordered = [...selected.value.steps].sort((a, b) => a.ordinal - b.ordinal)
  const index = ordered.findIndex((item) => item.id === step.id)
  const target = index + direction
  if (target < 0 || target >= ordered.length) return
  ;[ordered[index], ordered[target]] = [ordered[target], ordered[index]]
  busy.value = true; error.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/steps/reorder`, 'PUT', { step_ids: ordered.map((item) => item.id) })
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to reorder steps.' }
  finally { busy.value = false }
}

async function toggleStep(step: CampaignStep) {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/steps/${step.id}`, 'PATCH', { active: !step.active })
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to update step.' }
  finally { busy.value = false }
}

async function removeStep(step: CampaignStep) {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/steps/${step.id}`, 'DELETE')
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to delete step.' }
  finally { busy.value = false }
}

async function createCampaign() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request('/campaigns', 'POST', {
      name: campaignDraft.value.name, description: campaignDraft.value.description || null,
      objective: campaignDraft.value.objective || null, timezone: campaignDraft.value.timezone,
      target_audience: {
        industries: campaignDraft.value.industries.split(',').map((item) => item.trim()).filter(Boolean),
        locations: campaignDraft.value.locations.split(',').map((item) => item.trim()).filter(Boolean),
      },
      sending_windows: { weekdays: campaignDraft.value.weekdays.split(',').map(Number).filter(Number.isFinite), start: campaignDraft.value.start, end: campaignDraft.value.end },
      rate_limit_per_hour: Number(campaignDraft.value.rate_limit_per_hour), daily_limit: Number(campaignDraft.value.daily_limit),
    })
    campaignDraft.value = { name: '', description: '', objective: '', timezone: 'UTC', industries: '', locations: '', weekdays: '1,2,3,4,5', start: '09:00', end: '17:00', rate_limit_per_hour: 60, daily_limit: 500 }
    notice.value = 'Draft campaign created. Add an approved template and sequence steps to prepare it.'
    campaigns.value.unshift(result)
    await loadCampaign(result.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to create campaign.' }
  finally { busy.value = false }
}

async function createTemplate() {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/templates`, 'POST', templateDraft.value)
    templateDraft.value = { name: '', subject: '', body: '' }
    notice.value = 'Draft email template saved.'
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save template.' }
  finally { busy.value = false }
}

async function approveTemplate(template: CampaignTemplate) {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/templates/${template.id}/approve`, 'POST', {})
    notice.value = 'Template approved for use in this draft sequence.'
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to approve template.' }
  finally { busy.value = false }
}

async function createStep() {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/steps`, 'POST', {
      ordinal: (selected.value.steps?.length ?? 0) + 1, template_id: stepDraft.value.template_id,
      delay_seconds: Number(stepDraft.value.delay_seconds),
    })
    notice.value = 'Sequence step added.'
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to add sequence step.' }
  finally { busy.value = false }
}

async function enrollContact() {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try {
    const result = await request(`/campaigns/${selected.value.id}/enrollments`, 'POST', {
      contact_id: enrollmentDraft.value.contact_id, contact_method_id: enrollmentDraft.value.contact_method_id || null,
    })
    notice.value = result.status === 'suppressed' ? 'Contact was not enrolled because the address is suppressed.' : 'Contact enrolled in this draft campaign.'
    enrollmentDraft.value = { contact_id: '', contact_method_id: '' }
    await loadCampaign(selected.value.id)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to enroll contact.' }
  finally { busy.value = false }
}

async function saveMessagingConfiguration() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    messagingConfiguration.value = await request('/messaging-configuration', 'PUT', messagingConfiguration.value)
    notice.value = messagingConfiguration.value.enabled ? 'Fake outbound messaging is enabled for this tenant.' : 'Outbound messaging is disabled and active campaigns have been paused.'
    await loadCampaigns()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save messaging configuration.' }
  finally { busy.value = false }
}

async function campaignAction(action: 'activate' | 'pause' | 'resume' | 'complete' | 'cancel') {
  if (!selected.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await request(`/campaigns/${selected.value.id}/${action}`, 'POST', {})
    notice.value = `Campaign ${action} request completed.`
    await loadCampaigns()
    await loadCampaign(selected.value?.id ?? selectedId.value)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : `Unable to ${action} campaign.` }
  finally { busy.value = false }
}

watch(() => enrollmentDraft.value.contact_id, () => { enrollmentDraft.value.contact_method_id = emailMethods.value[0]?.id ?? '' })
watch(() => [props.tenantId, props.manager], () => { selected.value = null; selectedId.value = ''; outboundMessages.value = []; suppressions.value = []; void loadCampaigns(); void loadContacts(); if (props.manager) { void loadMessagingConfiguration(); void loadSuppressions() } })
onMounted(() => { void loadCampaigns(); void loadContacts(); if (props.manager) { void loadMessagingConfiguration(); void loadSuppressions() } })
</script>

<template>
  <div class="campaign-workspace">
    <div v-if="error" class="notice">{{ error }} <button class="quiet" @click="loadCampaigns">Retry</button></div>
    <div v-if="notice" class="notice success-notice">{{ notice }}</div>
    <div class="test-mode-banner"><b>TEST MODE</b><span>Outbound messages use the fake provider. No external email is delivered.</span></div>
    <section class="panel companies-panel">
      <div class="panel-heading"><div><h2>Campaigns</h2><p>Create audience-scoped drafts and prepare an approved email sequence.</p></div><button class="quiet" @click="loadCampaigns">↻ Refresh</button></div>
      <div v-if="campaigns.length" class="campaign-selector">
        <button v-for="campaign in campaigns" :key="campaign.id" class="campaign-choice" :class="{ selected: selectedId === campaign.id }" @click="loadCampaign(campaign.id)">
          <span><b>{{ campaign.name }}</b><small>{{ campaign.objective || 'No objective yet' }}</small><small>{{ campaign.enrollments_count ?? 0 }} prospects · {{ campaign.steps_count ?? 0 }} sequence steps · Updated {{ new Date(campaign.updated_at ?? Date.now()).toLocaleDateString() }}</small></span><span class="pill">{{ statusLabel(campaign.status) }}</span>
        </button>
      </div>
      <div v-else class="empty-table">{{ loading ? 'Loading campaigns…' : 'No campaigns yet. Create a draft to define an audience and sequence.' }}</div>
    </section>

    <details v-if="manager" class="panel companies-panel campaign-create">
      <summary class="campaign-create-summary">＋ Create campaign draft</summary>
      <form class="config-form" @submit.prevent="createCampaign">
        <div class="campaign-form-grid"><label>Campaign name<input v-model="campaignDraft.name" required maxlength="255" placeholder="Website refresh outreach"/></label><label>Description<input v-model="campaignDraft.description" maxlength="4000" placeholder="Audience and message context"/></label><label>Objective<input v-model="campaignDraft.objective" maxlength="4000" placeholder="Book qualified introduction calls"/></label><label>Timezone<input v-model="campaignDraft.timezone" required placeholder="Asia/Kolkata"/></label><label>Industries<input v-model="campaignDraft.industries" placeholder="Consulting, healthcare"/></label><label>Locations<input v-model="campaignDraft.locations" placeholder="Mumbai, Pune"/></label><label>Weekdays (1–7)<input v-model="campaignDraft.weekdays" required placeholder="1,2,3,4,5"/></label><label>Window start<input v-model="campaignDraft.start" type="time" required/></label><label>Window end<input v-model="campaignDraft.end" type="time" required/></label><label>Hourly campaign limit<input v-model.number="campaignDraft.rate_limit_per_hour" type="number" min="1" max="1000" required/></label><label>Daily campaign limit<input v-model.number="campaignDraft.daily_limit" type="number" min="1" max="10000" required/></label></div>
        <button class="primary" :disabled="busy">{{ busy ? 'Saving…' : 'Create draft' }}</button>
      </form>
    </details>

    <details v-if="manager" class="panel companies-panel campaign-create">
      <summary class="campaign-create-summary">✉ Tenant messaging configuration</summary>
      <form class="config-form" @submit.prevent="saveMessagingConfiguration">
        <p class="campaign-config-note">Phase 2 uses a local fake provider only. Enabling it exercises the complete queue and message ledger without sending real email.</p>
        <div class="campaign-form-grid"><label>Provider<select v-model="messagingConfiguration.provider"><option value="fake">Fake (local)</option></select></label><label>From name<input v-model="messagingConfiguration.from_name" maxlength="255"/></label><label>From email<input v-model="messagingConfiguration.from_email" type="email" maxlength="255" :required="messagingConfiguration.enabled"/></label><label>Reply-to email<input v-model="messagingConfiguration.reply_to_email" type="email" maxlength="255"/></label><label>Tenant hourly limit<input v-model.number="messagingConfiguration.hourly_limit" type="number" min="1" max="1000" required/></label><label>Tenant daily limit<input v-model.number="messagingConfiguration.daily_limit" type="number" min="1" max="10000" required/></label></div>
        <label class="campaign-enable"><input v-model="messagingConfiguration.enabled" type="checkbox"/> Enable fake outbound execution</label>
        <button class="primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save messaging configuration' }}</button>
      </form>
    </details>

    <template v-if="selected">
      <section class="panel companies-panel">
        <div class="panel-heading"><div><div class="eyebrow">CAMPAIGN DETAILS</div><h2>{{ selected.name }}</h2><p>{{ selected.description || selected.objective || 'No campaign description.' }}</p></div><div class="campaign-actions"><span class="pill">{{ statusLabel(selected.status) }}</span><button v-if="manager && selected.status === 'draft'" class="primary" :disabled="busy" @click="campaignAction('activate')">Start</button><button v-if="manager && selected.status === 'active'" class="quiet" :disabled="busy" @click="campaignAction('pause')">Pause</button><button v-if="manager && selected.status === 'paused'" class="primary" :disabled="busy" @click="campaignAction('resume')">Resume</button><button v-if="manager && ['active','paused'].includes(selected.status)" class="quiet" :disabled="busy" @click="campaignAction('complete')">Complete</button><button v-if="manager && ['draft','active','paused'].includes(selected.status)" class="quiet" :disabled="busy" @click="campaignAction('cancel')">Cancel</button></div></div>
        <div class="campaign-metric-grid"><article><small>ENROLLED</small><b>{{ selected.metrics?.enrolled ?? 0 }}</b></article><article><small>ACTIVE</small><b>{{ selected.metrics?.active ?? 0 }}</b></article><article><small>SENT</small><b>{{ selected.metrics?.sent ?? 0 }}</b></article><article><small>DELIVERED</small><b>{{ selected.metrics?.delivered ?? 0 }}</b></article><article><small>REPLIED</small><b>{{ selected.metrics?.replied ?? 0 }}</b></article><article><small>BOUNCED</small><b>{{ selected.metrics?.bounced ?? 0 }}</b></article><article><small>UNSUBSCRIBED</small><b>{{ selected.metrics?.unsubscribed ?? 0 }}</b></article></div>
        <dl class="campaign-facts"><dt>Audience</dt><dd>{{ audienceLabel(selected.audience?.criteria ?? selected.target_audience) }}</dd><dt>Send window</dt><dd>{{ selected.sending_windows ? `${selected.sending_windows.weekdays.map((day:number) => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][day]).join(', ')} · ${selected.sending_windows.start}–${selected.sending_windows.end} · ${selected.timezone}` : `Any time · ${selected.timezone}` }}</dd><dt>Limits</dt><dd>{{ selected.rate_limit_per_hour }}/hour · {{ selected.daily_limit }}/day</dd><dt>Enrollment</dt><dd>{{ selected.metrics?.enrolled ?? 0 }} prospects · first page shows up to 25</dd></dl>
      </section>

      <section class="panel companies-panel">
        <div class="panel-heading"><div><h2>Email templates</h2><p>Templates are plain text and require explicit owner/admin approval before a step can use them.</p></div></div>
        <div v-if="selected.templates?.length" class="campaign-template-list"><article v-for="template in selected.templates" :key="template.id" class="campaign-template"><div><b>{{ template.name }}</b><small>{{ template.subject || 'No subject' }} · v{{ template.version }} · {{ template.status }}</small><p>{{ template.body }}</p></div><button v-if="manager && template.status !== 'approved'" class="quiet" :disabled="busy" @click="approveTemplate(template)">Approve</button></article></div>
        <div v-else class="empty-table">No email templates added yet.</div>
        <form v-if="manager" class="config-form campaign-template-form" @submit.prevent="createTemplate"><div class="campaign-form-grid"><label>Template name<input v-model="templateDraft.name" required maxlength="255"/></label><label>Subject<input v-model="templateDraft.subject" maxlength="500" placeholder="No line breaks allowed"/></label></div><label>Email body<textarea v-model="templateDraft.body" required maxlength="20000" rows="5"/></label><button class="primary" :disabled="busy">Save draft template</button></form>
      </section>

      <section class="panel companies-panel">
        <div class="panel-heading"><div><h2>Sequence editor</h2><p>Ordered email steps run through the queued outbound ledger when the campaign is active.</p></div></div>
        <div v-if="selected.steps?.length" class="campaign-step-list"><article v-for="(step, index) in selected.steps" :key="step.id"><span class="step-number">{{ step.ordinal }}</span><div><b>{{ step.template?.name || 'Email step' }}</b><small>{{ step.template?.subject }} · wait {{ Math.floor(step.delay_seconds / 86400) }} days · {{ step.active ? 'active' : 'inactive' }}</small></div><span class="pill">{{ step.template?.status }}</span><div v-if="manager" class="step-tools"><button class="quiet" :disabled="busy || index === 0" aria-label="Move step up" @click="reorderStep(step, -1)">↑</button><button class="quiet" :disabled="busy || index === selected.steps!.length - 1" aria-label="Move step down" @click="reorderStep(step, 1)">↓</button><button class="quiet" :disabled="busy" @click="toggleStep(step)">{{ step.active ? 'Disable' : 'Enable' }}</button><button class="quiet" :disabled="busy" @click="removeStep(step)">Delete</button></div></article></div>
        <div v-else class="empty-table">Add an approved template before creating the first step.</div>
        <form v-if="manager && selected.templates?.some((template) => template.status === 'approved')" class="campaign-inline-form" @submit.prevent="createStep"><label>Approved template<select v-model="stepDraft.template_id" required><option value="" disabled>Select template</option><option v-for="template in selected.templates.filter((item) => item.status === 'approved')" :key="template.id" :value="template.id">{{ template.name }}</option></select></label><label>Delay in seconds<input v-model.number="stepDraft.delay_seconds" type="number" min="0" max="7776000" required/></label><button class="primary" :disabled="busy">Add step {{ (selected.steps?.length ?? 0) + 1 }}</button></form>
      </section>

      <section class="panel companies-panel">
        <div class="panel-heading"><div><h2>Enrollments</h2><p>Public email contacts can be added to this draft. Suppression is checked before enrollment.</p></div></div>
        <form v-if="manager" class="campaign-inline-form" @submit.prevent="enrollContact"><label>Contact<select v-model="enrollmentDraft.contact_id" required><option value="" disabled>Select a public contact</option><option v-for="contact in contacts.filter((item) => item.methods?.some((method) => method.type === 'email'))" :key="contact.id" :value="contact.id">{{ contact.name || contact.company_name }} · {{ contact.company_name }}</option></select></label><label>Email method<select v-model="enrollmentDraft.contact_method_id" required><option value="" disabled>Select email</option><option v-for="method in emailMethods" :key="method.id" :value="method.id">{{ method.value }}</option></select></label><button class="primary" :disabled="busy || !contacts.length">Enroll contact</button></form>
        <div v-if="selected.enrollments?.data?.length" class="table-wrap"><table><thead><tr><th>CONTACT</th><th>COMPANY</th><th>STATUS</th><th>ENROLLED</th><th></th></tr></thead><tbody><tr v-for="enrollment in selected.enrollments.data" :key="enrollment.id"><td>{{ enrollment.contact?.name || 'Public contact' }}<small>{{ enrollment.contact?.title }}</small></td><td><button v-if="enrollment.company?.id" class="quiet" @click="emit('openProspect', {id:enrollment.company.id,name:enrollment.company.name})">{{ enrollment.company?.name }} →</button><span v-else>{{ enrollment.company?.name || '—' }}</span></td><td><span class="pill">{{ enrollment.status }}</span></td><td>{{ enrollment.enrolled_at }}</td><td><button v-if="manager && ['active','pending'].includes(enrollment.status)" class="quiet" :disabled="busy" @click="stopEnrollment(enrollment)">Stop</button></td></tr></tbody></table></div>
        <div v-else class="empty-table">No contacts enrolled in this campaign.</div>
      </section>

      <section class="panel companies-panel">
        <div class="panel-heading"><div><h2>Message activity</h2><p>Test provider ledger status. These records do not indicate live email delivery.</p></div><button class="quiet" :disabled="busy" @click="loadMessages">Refresh</button></div>
        <div v-if="outboundMessages.length" class="table-wrap"><table><thead><tr><th>STATUS</th><th>PROVIDER</th><th>CREATED</th><th>SENT</th><th>ERROR</th><th></th></tr></thead><tbody><tr v-for="message in outboundMessages" :key="message.id"><td><span class="pill">{{ message.status }}</span></td><td>{{ message.provider }}</td><td>{{ message.created_at }}</td><td>{{ message.sent_at || '—' }}</td><td>{{ message.safe_error || '—' }}</td><td><button class="quiet" @click="showMessage(message.id)">View</button></td></tr></tbody></table></div>
        <div v-else class="empty-table">No outbound messages for this campaign yet.</div>
        <article v-if="selectedMessage" class="campaign-template"><div><b>{{ selectedMessage.subject }}</b><small>{{ selectedMessage.status }}</small><pre>{{ selectedMessage.content }}</pre></div><button class="quiet" @click="selectedMessage = null">Close</button></article>
      </section>
    </template>

    <section v-if="manager" class="panel companies-panel">
      <div class="panel-heading"><div><h2>Suppression list</h2><p>Tenant-scoped blocks are permanent in Phase 2A; no reactivation path is available.</p></div><button class="quiet" :disabled="busy" @click="loadSuppressions">Refresh</button></div>
      <form v-if="manager" class="campaign-inline-form" @submit.prevent="addSuppression"><label>Email address<input v-model="suppressionEmail" type="email" maxlength="254" required placeholder="name@example.com"/></label><button class="primary" :disabled="busy">Suppress and stop active enrollments</button></form>
      <div v-if="suppressions.length" class="table-wrap"><table><thead><tr><th>IDENTIFIER HASH</th><th>TYPE</th><th>REASON</th><th>SUPPRESSED</th></tr></thead><tbody><tr v-for="item in suppressions" :key="item.id"><td><code>{{ item.identifier_hash }}</code></td><td>{{ item.identifier_type }}</td><td>{{ item.reason }}</td><td>{{ item.suppressed_at || '—' }}</td></tr></tbody></table></div>
      <div v-else class="empty-table">{{ loading ? 'Loading suppression entries…' : 'No suppression entries.' }}</div>
    </section>
  </div>
</template>
