<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

type ScopeLine = { service_id: string; description: string; deliverables: string[] }
type Version = { id: string; version: number; status: string; provider: string | null; model: string | null; draft_content: Record<string, any>; recommended_scope: ScopeLine[]; approved_scope: ScopeLine[]; document_key: string | null; document_sha256: string | null; document_generated_at?: string | null; commercial_snapshot?: { discount_percent?: number } }
type Proposal = { id: string; title: string; version: number; status: string; currency: string | null; subtotal: string | null; discount_value?: string | null; total: string | null; terms: string | null; valid_until: string | null; safe_generation_error: string | null; opportunity?: { id?: string; stage?: string; qualification_score?: number; contact?: { name: string; title?: string }; company?: { id?: string; name: string } }; items?: Array<{ service_id: string; service_name: string; quantity: number; unit: string; unit_price: string; line_total: string; net_total: string; discount_amount: string; discount_reason?: string | null; price_override_reason?: string | null }>; versions?: Version[]; requirements?: Record<string, any>; internal_notes?: Array<{ id: string; note: string }> }
type Service = { id: string; sku: string; name: string; description: string | null; unit_price: string; currency: string; unit: string; commercial_model: string; active: boolean; capabilities?: string[]; standard_deliverables?: string[] }
type Opportunity = { id: string; stage: string; status: string; company?: { name: string } }
type PricingPolicy = { currency: string; max_discount_percent: number; default_validity_days: number }
const props = withDefaults(defineProps<{ tenantId: string; setupOnly?: boolean; focusId?: string }>(), { setupOnly: false, focusId: '' })
const emit = defineEmits<{ openProspect: [company: { id: string; name: string }]; openOpportunity: [id: string] }>()
const proposals = ref<Proposal[]>([]); const opportunities = ref<Opportunity[]>([]); const services = ref<Service[]>([])
const selected = ref<Proposal | null>(null); const viewVersionId = ref(''); const opportunityId = ref(''); const busy = ref(false); const error = ref(''); const notice = ref('')
const policy = ref<PricingPolicy>({ currency: 'INR', max_discount_percent: 0, default_validity_days: 30 })
const requirement = ref({ services: '', needs: '', objectives: '', pain: '', timeline: '', notes: '', override: false, reason: '' })
const commercial = ref({ currency: 'INR', discount: 0, discountReason: '', lines: [{ serviceId: '', quantity: 1 }] })
const edits = ref<Record<string, any>>({}); const scope = ref<string[]>([]); const internalNote = ref('')
const termsDraft = ref('')
const serviceDraft = ref({ sku: '', name: '', description: '', unit_price: '0.00', currency: 'INR', commercial_model: 'FIXED_PRICE', unit: 'project', category: '', capabilities: '', standard_deliverables: '' })
const version = computed(() => selected.value?.versions?.find((item) => item.id === viewVersionId.value) ?? selected.value?.versions?.[0] ?? null)
const isLatestVersion = computed(() => version.value?.id === selected.value?.versions?.[0]?.id)
const reviewing = computed(() => ['review_required', 'commercial_input_required'].includes(selected.value?.status ?? ''))
function textField(key: string) { return computed({ get: () => Array.isArray(edits.value[key]) ? edits.value[key].join('\n') : String(edits.value[key] ?? ''), set: (value: string) => { edits.value[key] = Array.isArray(edits.value[key]) || ['objectives','deliverables','assumptions','exclusions','implementation_approach'].includes(key) ? split(value) : value } }) }
const objectivesText = textField('objectives'); const deliverablesText = textField('deliverables'); const assumptionsText = textField('assumptions'); const exclusionsText = textField('exclusions'); const approachText = textField('implementation_approach')
function csrf() { return decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '') }
function split(value: string) { return value.split('\n').map((x) => x.trim()).filter(Boolean) }
function formatMoney(amount: string | null | undefined, currency: string | null | undefined) { if (!amount || !currency) return amount || '—'; try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency }).format(Number(amount)) } catch { return `${currency} ${amount}` } }
function statusLabel(status: string) { return ({draft:'Draft',commercial_input_required:'Needs commercial input',review_required:'Needs human review',approved:'Approved',ready_to_send:'Ready to send',rejected:'Rejected',superseded:'Superseded'} as Record<string,string>)[status] ?? status.replaceAll('_',' ') }
function isFixtureVersion(item:Version|null){return Boolean(item?.model?.startsWith('visual-fixture-'))}
function copy<T>(value: T): T { return JSON.parse(JSON.stringify(value)) as T }
function listValue(value: unknown): string[] { if (Array.isArray(value)) return value.map(String); try { const parsed = JSON.parse(String(value ?? '[]')); return Array.isArray(parsed) ? parsed.map(String) : [] } catch { return [] } }
async function api(path: string, method = 'GET', body?: unknown, key?: string) {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Tenant-ID': props.tenantId }
  if (body !== undefined) { headers['Content-Type'] = 'application/json'; headers['X-XSRF-TOKEN'] = csrf() }
  if (key) headers['Idempotency-Key'] = key
  const response = await fetch('/api/v1' + path, { method, credentials: 'include', headers, ...(body === undefined ? {} : { body: JSON.stringify(body) }) })
  const value = response.status === 204 ? null : await response.json()
  if (!response.ok) throw new Error(value?.message ?? 'Request failed (' + response.status + ').')
  return value
}
async function load() {
  try {
    const [ps, os, ss, pricing] = await Promise.all([api('/proposals'), api('/opportunities'), api('/tenant-services'), api('/pricing-policy')])
    proposals.value = ps.data ?? []; opportunities.value = os.data ?? []; services.value = ss ?? []; policy.value = pricing
    if (props.focusId && proposals.value.some((item) => item.id === props.focusId)) await select(props.focusId)
    else if (selected.value) await select(selected.value.id); else if (proposals.value.length) await select(proposals.value[0].id)
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to load proposal workspace.' }
}
async function select(id: string) {
  try {
    selected.value = await api('/proposals/' + encodeURIComponent(id))
    viewVersionId.value = selected.value?.versions?.[0]?.id ?? ''
    const v = selected.value?.versions?.[0]
    if (v) { edits.value = copy(v.draft_content); scope.value = copy(v.approved_scope?.length ? v.approved_scope : v.recommended_scope).map((line) => line.service_id) }
    commercial.value = { currency: selected.value?.currency || policy.value.currency, discount: Number(v?.commercial_snapshot?.discount_percent ?? 0), discountReason: '', lines: selected.value?.items?.length ? selected.value.items.map((item) => ({ serviceId: item.service_id, quantity: item.quantity })) : [{ serviceId: '', quantity: 1 }] }
    termsDraft.value = String(v?.draft_content?.client_visible_terms ?? selected.value?.terms ?? '')
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to load proposal.' }
}
async function createRequest() {
  if (!opportunityId.value) return
  busy.value = true; error.value = ''
  try {
    const p = await api('/opportunities/' + opportunityId.value + '/proposals', 'POST', { qualification_override: requirement.value.override, override_reason: requirement.value.reason || undefined,
      requirements: { requested_services: split(requirement.value.services), business_requirements: split(requirement.value.needs), business_objectives: split(requirement.value.objectives), known_pain_points: split(requirement.value.pain), requested_timeline: requirement.value.timeline || null, special_notes: requirement.value.notes || null } })
    proposals.value.unshift(p); notice.value = 'Requirements snapshot created.'; await select(p.id)
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to create proposal request.' }
  finally { busy.value = false }
}
async function generate(regenerate = false) {
  if (!selected.value) return
  busy.value = true; error.value = ''
  try { await api('/proposals/' + selected.value.id + (regenerate ? '/regenerate' : '/generate'), 'POST', {}, globalThis.crypto?.randomUUID?.() ?? String(Date.now())); notice.value = 'Proposal draft is ready for human review.'; await load() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Proposal generation failed.' }
  finally { busy.value = false }
}
async function saveDraft() {
  if (!selected.value || !version.value || !isLatestVersion.value) return
  busy.value = true
  try { await api('/proposals/' + selected.value.id + '/versions/' + version.value.id, 'PATCH', { content: edits.value, approved_scope: version.value.recommended_scope.filter((line) => scope.value.includes(line.service_id)), timeline_type: edits.value.timeline_type ?? null, committed_delivery_date: edits.value.committed_delivery_date ?? null, terms: termsDraft.value }); error.value = ''; notice.value = 'Draft edits and approved scope saved.'; await load() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to save draft.' }
  finally { busy.value = false }
}
async function saveCommercial() {
  if (!selected.value || !commercial.value.lines.every((line) => line.serviceId)) return
  busy.value = true
  try { await api('/proposals/' + selected.value.id + '/commercials', 'PUT', { currency: commercial.value.currency, discount_percent: commercial.value.discount, discount_reason: commercial.value.discountReason || undefined, items: commercial.value.lines.map((line) => ({ service_id: line.serviceId, quantity: line.quantity })) }); notice.value = 'Catalog pricing snapshot saved.'; await load() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to save commercial inputs.' }
  finally { busy.value = false }
}
async function action(action: 'approve' | 'reject' | 'document' | 'ready-to-send' | 'supersede') {
  if (!selected.value) return
  busy.value = true
  try { await api('/proposals/' + selected.value.id + '/' + action, 'POST', action === 'reject' ? { reason: 'Rejected in human review.' } : {}); error.value = ''; notice.value = 'Proposal ' + action.replaceAll('-', ' ') + '.'; await load() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to ' + action + ' proposal.' }
  finally { busy.value = false }
}
async function download() {
  if (!selected.value || !version.value) return
  const response = await fetch('/api/v1/proposals/' + selected.value.id + '/versions/' + version.value.id + '/document', { credentials: 'include', headers: { Accept: 'application/pdf', 'X-Tenant-ID': props.tenantId } })
  if (!response.ok) { error.value = 'Authorized PDF download failed.'; return }
  const link = document.createElement('a'); const url = URL.createObjectURL(await response.blob()); link.href = url; link.download = 'proposal-v' + version.value.version + '.pdf'; link.click(); URL.revokeObjectURL(url)
}
async function addNote() {
  if (!selected.value || !internalNote.value.trim()) return
  try { await api('/proposals/' + selected.value.id + '/internal-notes', 'POST', { note: internalNote.value }); internalNote.value = ''; await select(selected.value.id) }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to save note.' }
}
async function updatePolicy() {
  try { policy.value = await api('/pricing-policy', 'PUT', policy.value); notice.value = 'Tenant pricing policy saved.' }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to save pricing policy.' }
}
async function addService() {
  try { const item = await api('/tenant-services', 'POST', { ...serviceDraft.value, capabilities: split(serviceDraft.value.capabilities), standard_deliverables: split(serviceDraft.value.standard_deliverables) }); services.value.push(item); serviceDraft.value = { sku: '', name: '', description: '', unit_price: '0.00', currency: policy.value.currency, commercial_model: 'FIXED_PRICE', unit: 'project', category: '', capabilities: '', standard_deliverables: '' }; notice.value = 'Approved service added.' }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to add service.' }
}
watch(() => props.tenantId, () => { proposals.value = []; selected.value = null; void load() })
watch(() => props.focusId, (id) => { if (id) void select(id) })
onMounted(() => { void load() })
</script>

<template>
  <div class="proposal-workspace">
    <div v-if="error" class="notice">{{ error }} <button class="quiet" @click="load">Retry</button></div>
    <div v-if="notice" class="notice success-notice">{{ notice }}</div>
    <div class="proposal-columns" :class="{'setup-only-layout':setupOnly}">
      <section v-if="!setupOnly" class="panel companies-panel">
        <div class="panel-heading"><div><h2>Proposals</h2><p>Grounded drafts and human-controlled approvals.</p></div><button class="quiet" @click="load">↻ Refresh</button></div>
        <button v-for="item in proposals" :key="item.id" class="proposal-choice" :class="{ selected: selected?.id === item.id }" @click="select(item.id)"><b>{{ item.title }}</b><small>{{ item.opportunity?.company?.name }} · {{ formatMoney(item.total, item.currency) }}</small><span class="pill">{{ statusLabel(item.status) }} · v{{ item.version }}</span></button>
        <p v-if="!proposals.length" class="empty-table">No proposal requests yet.</p>
        <form class="config-form" @submit.prevent="createRequest"><h3>Proposal requirements</h3>
          <label>Qualified opportunity<select v-model="opportunityId" required><option value="" disabled>Select opportunity</option><option v-for="op in opportunities.filter((o) => o.status === 'open')" :key="op.id" :value="op.id">{{ op.company?.name }} · {{ op.stage }}</option></select></label>
          <label>Requested services<textarea v-model="requirement.services" required placeholder="One per line"/></label><label>Business requirements<textarea v-model="requirement.needs" required placeholder="One per line"/></label>
          <label>Objectives<textarea v-model="requirement.objectives"/></label><label>Known pain points<textarea v-model="requirement.pain"/></label><label>Requested timeline<input v-model="requirement.timeline" placeholder="Unknown is okay"/></label><label>Special notes<textarea v-model="requirement.notes"/></label>
          <label class="check"><input v-model="requirement.override" type="checkbox"/>Authorized qualification override</label><label v-if="requirement.override">Override reason<input v-model="requirement.reason" required/></label>
          <button class="primary" :disabled="busy || !opportunityId">Create request snapshot</button>
        </form>
      </section>
      <section v-if="selected && !setupOnly" class="panel companies-panel proposal-detail-panel">
        <div class="panel-heading"><div><div class="eyebrow">PROPOSAL REVIEW · VERSION {{ version?.version ?? 0 }}</div><h2>{{ selected.title }}</h2><p>{{ selected.opportunity?.company?.name }} · {{ statusLabel(selected.status) }}</p><p v-if="selected.opportunity">Opportunity {{ selected.opportunity.stage?.replaceAll('_',' ') }}<span v-if="selected.opportunity.qualification_score"> · Qualification {{ selected.opportunity.qualification_score }}/100</span><span v-if="selected.opportunity.contact"> · {{ selected.opportunity.contact.name }}{{ selected.opportunity.contact.title ? `, ${selected.opportunity.contact.title}` : '' }}</span></p><div class="proposal-context-links"><button v-if="selected.opportunity?.company?.id" class="quiet" @click="emit('openProspect',{id:selected.opportunity.company.id,name:selected.opportunity.company.name})">Open Prospect 360 →</button><button v-if="selected.opportunity?.id" class="quiet" @click="emit('openOpportunity',selected.opportunity.id!)">Open opportunity in Pipeline →</button></div></div></div>
        <div v-if="!version && !selected.safe_generation_error" class="empty-table proposal-empty-state"><b>Proposal content is not available yet.</b><span>Next step: generate a draft after proposal generation is configured and verified. Approval will remain unavailable until scope and commercials can be reviewed.</span></div>
        <div v-if="isFixtureVersion(version)" class="notice fixture-notice">TEST FIXTURE · Fictional client and deterministic sample copy. The text was authored for UI review and was not generated by an AI model.</div>
        <p class="proposal-authority-note"><b>Approval does not send or share this proposal.</b> “Ready to send” means it is approved and prepared for a later controlled delivery step. It does not mean sent.</p>
        <div v-if="selected.requirements" class="service-catalog proposal-requirements-context"><h3>Client requirements snapshot</h3><div class="proposal-context-grid"><section><h4>Requested services</h4><p v-for="item in listValue(selected.requirements.requested_services)" :key="item">{{ item }}</p></section><section><h4>Business requirements</h4><p v-for="item in listValue(selected.requirements.business_requirements)" :key="item">{{ item }}</p></section><section><h4>Objectives</h4><p v-for="item in listValue(selected.requirements.business_objectives)" :key="item">{{ item }}</p></section><section><h4>Known pain points</h4><p v-for="item in listValue(selected.requirements.known_pain_points)" :key="item">{{ item }}</p></section><section><h4>Requested timeline</h4><p>{{ selected.requirements.requested_timeline || 'To be agreed' }}</p></section></div><details v-if="selected.requirements.special_notes"><summary>Internal request notes</summary><p>{{ selected.requirements.special_notes }}</p></details></div>
        <div v-if="selected.safe_generation_error" class="notice">{{ selected.safe_generation_error }}</div>
        <div class="proposal-actions"><button v-if="!version && ['draft','commercial_input_required','review_required','rejected'].includes(selected.status)" class="primary" :disabled="busy" @click="generate()">Generate draft</button><button v-if="version && reviewing" class="quiet" :disabled="busy" @click="generate(true)">Regenerate new version</button><small v-if="version">Draft version {{ version.version }} is saved for human review.</small></div>
        <div v-if="version" class="proposal-builder-grid">
          <section v-if="isLatestVersion" class="service-catalog"><h3>{{ isFixtureVersion(version) ? 'Deterministic fixture draft · not AI-generated' : 'AI draft · human editable' }}</h3><label>Executive summary<textarea v-model="edits.executive_summary" rows="4"/></label><label>Client understanding<textarea v-model="edits.client_understanding" rows="4"/></label><label>Objectives (one per line)<textarea v-model="objectivesText" rows="3"/></label><label>Deliverables (one per line)<textarea v-model="deliverablesText" rows="3"/></label><label>Assumptions<textarea v-model="assumptionsText" rows="2"/></label><label>Exclusions<textarea v-model="exclusionsText" rows="2"/></label><label>Approach<textarea v-model="approachText" rows="3"/></label><label>Timeline narrative<textarea v-model="edits.timeline_narrative" rows="2"/></label><label>Timeline type<select v-model="edits.timeline_type"><option :value="null">Uncommitted</option><option value="ESTIMATED">Estimated</option><option value="TARGET">Target</option><option value="COMMITTED">Committed (owner/admin)</option></select></label><label v-if="edits.timeline_type === 'COMMITTED'">Committed delivery date<input v-model="edits.committed_delivery_date" type="date"/></label><label>Tenant-approved terms<textarea v-model="termsDraft" rows="4" placeholder="Owner/admin controlled client-visible terms"/></label>
            <h4>Approved scope</h4><label v-for="line in version.recommended_scope" :key="line.service_id" class="scope-choice check"><input v-model="scope" type="checkbox" :value="line.service_id"/>{{ services.find((s) => s.id === line.service_id)?.name }} · {{ line.description }}</label>
            <button class="quiet" :disabled="busy" @click="saveDraft">Save edits and scope</button>
          </section>
          <section class="service-catalog"><h3>Commercial editor</h3><p>Catalog price is the default. Currency is an explicit tenant/user selection.</p><label>Currency<input v-model="commercial.currency" maxlength="3" required/></label><div v-for="(line, index) in commercial.lines" :key="index" class="proposal-item-editor"><label>Service<select v-model="line.serviceId"><option value="" disabled>Select approved service</option><option v-for="s in services.filter((x) => x.active)" :key="s.id" :value="s.id">{{ s.name }} · {{ s.currency }} {{ s.unit_price }}/{{ s.unit }}</option></select></label><label>Quantity<input v-model.number="line.quantity" type="number" min="1" max="1000"/></label><button v-if="commercial.lines.length > 1" class="quiet" type="button" @click="commercial.lines.splice(index, 1)">Remove</button></div><button class="quiet" @click="commercial.lines.push({ serviceId: '', quantity: 1 })">Add line item</button><label>Authorized discount %<input v-model.number="commercial.discount" type="number" min="0" :max="policy.max_discount_percent" step="0.01"/></label><label v-if="commercial.discount">Discount reason<input v-model="commercial.discountReason" required/></label><button class="quiet" :disabled="busy || !commercial.lines.every((line) => line.serviceId)" @click="saveCommercial">Save commercial inputs</button>
            <div v-if="selected.items?.length" class="proposal-commercial-ledger"><h4>Recorded commercial lines</h4><article v-for="item in selected.items" :key="item.service_id + item.unit_price"><div><b>{{ item.service_name }}</b><small>{{ item.quantity }} {{ item.unit }} × {{ formatMoney(item.unit_price, selected.currency) }}</small></div><span class="commercial-provenance" :class="{override:!!item.price_override_reason}">{{ item.price_override_reason ? 'HUMAN COMMERCIAL OVERRIDE' : 'APPROVED CATALOG PRICE' }}</span><small v-if="item.price_override_reason">{{ item.price_override_reason }}</small><small v-else>Price follows the approved service catalogue.</small><small v-if="item.discount_amount !== '0.00'">Discount {{ formatMoney(item.discount_amount, selected.currency) }} · {{ item.discount_reason || 'Human-authorized' }}</small><b>Line total: {{ formatMoney(item.line_total, selected.currency) }}</b></article></div>
            <div class="proposal-totals"><span>Subtotal: {{ formatMoney(selected.subtotal, selected.currency) }}</span><span v-if="Number(selected.discount_value)>0">Human-authorized discount: {{ selected.discount_value }}%</span><b>Final total: {{ formatMoney(selected.total, selected.currency) }} · {{ selected.currency }}</b></div>
          </section>
        </div>
        <section v-if="version" class="service-catalog proposal-preview"><h3>Client preview</h3><h4>Executive summary</h4><p>{{ version.draft_content.executive_summary }}</p><h4>Client understanding</h4><p>{{ version.draft_content.client_understanding }}</p><h4>Objectives</h4><p v-for="item in version.draft_content.objectives" :key="item">{{ item }}</p><h4>Solution</h4><p v-for="item in version.draft_content.recommended_solution" :key="item">{{ item }}</p><h4>Scope and deliverables</h4><article v-for="line in (version.approved_scope.length ? version.approved_scope : version.recommended_scope)" :key="line.service_id"><b>{{ services.find((s) => s.id === line.service_id)?.name }}</b><p>{{ line.description }}</p><small>{{ line.deliverables.join(' · ') }}</small></article><h4>Implementation approach</h4><p v-for="item in version.draft_content.implementation_approach" :key="item">{{ item }}</p><h4>Timeline</h4><p>{{ version.draft_content.timeline_narrative }}</p><h4>Exclusions</h4><p v-for="item in version.draft_content.exclusions" :key="item">{{ item }}</p><h4>Commercials</h4><p v-for="item in selected.items" :key="item.service_id + item.unit_price">{{ item.service_name }} · {{ item.quantity }} {{ item.unit }} × {{ formatMoney(item.unit_price, selected.currency) }} · {{ formatMoney(item.net_total, selected.currency) }}<small v-if="item.discount_amount !== '0.00'"> (includes {{ formatMoney(item.discount_amount, selected.currency) }} human-authorized discount)</small><small>{{ item.price_override_reason ? 'Human-approved price override.' : 'Approved catalog price.' }}</small></p><b>Final total {{ formatMoney(selected.total, selected.currency) }}</b><h4>Terms</h4><p>{{ termsDraft }}</p><small>Valid until {{ selected.valid_until }}</small></section>
        <div class="proposal-actions"><button v-if="reviewing && isLatestVersion" class="primary" :disabled="busy" @click="action('approve')">Approve</button><button v-if="reviewing && isLatestVersion" class="quiet" :disabled="busy" @click="action('reject')">Reject</button><button v-if="selected.status === 'approved' && isLatestVersion" class="primary" :disabled="busy" @click="action('document')">Generate PDF</button><button v-if="version?.document_key" class="quiet" @click="download">Download PDF</button><button v-if="selected.status === 'approved' && version?.document_key && isLatestVersion" class="primary" :disabled="busy" @click="action('ready-to-send')">Mark ready to send</button><button v-if="['approved','ready_to_send'].includes(selected.status) && isLatestVersion" class="quiet" @click="action('supersede')">Supersede</button></div>
        <section class="service-catalog"><h3>Internal notes · excluded from client documents</h3><p v-for="note in selected.internal_notes" :key="note.id">{{ note.note }}</p><textarea v-model="internalNote" rows="2"/><button class="quiet" @click="addNote">Add internal note</button></section>
        <section class="service-catalog"><h3>Versions</h3><article v-for="v in selected.versions" :key="v.id"><button class="quiet" @click="viewVersionId = v.id; edits = copy(v.draft_content); scope = copy(v.approved_scope?.length ? v.approved_scope : v.recommended_scope).map((line) => line.service_id); termsDraft = String(v.draft_content.client_visible_terms ?? '')"><b>View v{{ v.version }} · {{ statusLabel(v.status) }}</b></button><small v-if="v.document_sha256">PDF available · generated {{ v.document_generated_at ? new Date(v.document_generated_at).toLocaleString() : '' }}</small></article></section>
      </section>
      <section class="panel companies-panel"><div class="eyebrow">ADMIN SETUP</div><h2>{{ setupOnly ? 'Services & pricing' : 'Approved commercial configuration' }}</h2><p>Proposal commercials use approved catalog values and human-authorized discounts. AI generated prices are never authoritative.</p><form class="config-form" @submit.prevent="updatePolicy"><label>Tenant currency<input v-model="policy.currency" maxlength="3" required/></label><label>Maximum human discount %<input v-model.number="policy.max_discount_percent" type="number" min="0" max="100" step="0.01" required/></label><label>Default validity days<input v-model.number="policy.default_validity_days" type="number" min="1" max="365" required/></label><button class="quiet">Save policy</button></form><h3>Approved service catalogue</h3><article v-for="s in services" :key="s.id"><b>{{ s.name }}</b><span>{{ s.sku }} · {{ s.commercial_model.replaceAll('_',' ') }} · {{ s.currency }} {{ s.unit_price }}/{{ s.unit }} · {{ s.active ? 'active' : 'inactive' }}</span><small>Approved catalog price · {{ s.description }}</small></article><form class="config-form" @submit.prevent="addService"><h3>Add approved service</h3><label>SKU<input v-model="serviceDraft.sku" required/></label><label>Name<input v-model="serviceDraft.name" required/></label><label>Category<input v-model="serviceDraft.category"/></label><label>Description<textarea v-model="serviceDraft.description"/></label><label>Approved capabilities<textarea v-model="serviceDraft.capabilities" placeholder="One per line"/></label><label>Standard deliverables<textarea v-model="serviceDraft.standard_deliverables" placeholder="One per line"/></label><label>Unit price<input v-model="serviceDraft.unit_price" inputmode="decimal" required/></label><label>Currency<input v-model="serviceDraft.currency" maxlength="3" required/></label><label>Commercial model<select v-model="serviceDraft.commercial_model"><option>FIXED_PRICE</option><option>TIME_AND_MATERIAL</option><option>MONTHLY_RETAINER</option><option>MILESTONE_BASED</option><option>CUSTOM</option></select></label><label>Unit<input v-model="serviceDraft.unit" required/></label><button class="quiet">Add service</button></form></section>
    </div>
  </div>
</template>
