<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

type View = 'workflows' | 'approvals' | 'policies' | 'summary'
const props = defineProps<{ tenantId: string; view: View }>()
const emit = defineEmits<{ openProspect: [company: { id: string; name: string }]; openProposal: [id: string] }>()
const rows = ref<any[]>([])
const proposalRows = ref<any[]>([])
const selected = ref<any | null>(null)
const approvalWorkflow = ref<any | null>(null)
const policy = ref<any>({ autonomy_mode: 'ASSISTED', daily_ai_call_limit: null, daily_token_limit: null, monthly_estimated_spend_limit: null, actions: [] })
const summary = ref<Record<string, number>>({})
const companies = ref<any[]>([])
const companyId = ref('')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const workflow = computed(() => selected.value?.workflow ?? selected.value)
const approvalGroups = computed(() => { const names = ['Needs attention', 'Outreach', 'Meetings', 'Proposals', 'Other']; const groups: Record<string, any[]> = Object.fromEntries(names.map((n) => [n, []])); for (const row of rows.value) { const key = row.expires_at && new Date(row.expires_at).getTime() < Date.now() + 24 * 60 * 60 * 1000 ? 'Needs attention' : /proposal/i.test(row.action) ? 'Proposals' : /meeting|schedule|calendar/i.test(row.action) ? 'Meetings' : /message|email|outreach/i.test(row.action) ? 'Outreach' : 'Other'; groups[key].push(row) } return names.filter((name) => groups[name].length).map((name) => ({ name, rows: groups[name] })) })
function actionTitle(row: any) { const action = String(row.action ?? '').toLowerCase(); const who = row.contact_name ? ` to ${row.contact_name}` : ''; const company = row.company_name || 'this prospect'; if (/proposal/i.test(action)) return `Review proposal for ${company}`; if (/meeting|schedule/i.test(action)) return `Create meeting request for ${company}`; if (/message|email|outreach/i.test(action)) return `Send outreach email${who} at ${company}`; return action.replaceAll('_', ' ') }
function afterApproval(row: any) { if (/proposal/i.test(row.action)) return 'The proposal workflow advances to its next approved state; this does not send it.'; if (/meeting|schedule/i.test(row.action)) return 'The controlled meeting request handler will run after revalidation.'; if (/message|email|outreach/i.test(row.action)) return 'The fake/test outbound handler may queue this message after revalidation.'; return 'The approved workflow action will run through its controlled handler after revalidation.' }
function formatMoney(value:string|null|undefined,currency:string|null|undefined){if(!value||!currency)return value||'—';try{return new Intl.NumberFormat('en-IN',{style:'currency',currency}).format(Number(value))}catch{return `${currency} ${value}`}}
function autonomyLabel(value: string) { return ({MANUAL:'Manual review',ASSISTED:'AI assisted · human controlled',CONTROLLED:'Controlled automation'} as Record<string,string>)[value] ?? value }
function policyLabel(value: string) { return ({AUTO_ALLOWED:'Allowed by policy',APPROVAL_REQUIRED:'Human approval required',HUMAN_ONLY:'Human action only',DENIED:'Blocked by policy'} as Record<string,string>)[value] ?? value }
function riskLabel(value:string){return ({LOW:'Low',MEDIUM:'Moderate',HIGH:'High',CRITICAL:'Critical'} as Record<string,string>)[value]??value}
function sideEffectLabel(value:string){return value==='EXTERNAL_CONSEQUENTIAL'?'External or consequential action':'Internal workspace update'}
function permissionLabel(value:string){const labels:Record<string,string>={'workflow.execute':'System workflow (no separate approver)','campaign.enroll':'Campaign owner/admin','outreach.approve':'Tenant owner/admin approval','meeting.approve':'Tenant owner/admin approval','meeting.book':'Human meeting owner','proposal.approve':'Tenant owner/admin','proposal.send':'Human owner; sending disabled in current workflow','pricing.manage':'Tenant owner/admin','opportunity.manage':'Sales role with opportunity access','opportunity.close':'Tenant owner/admin','handoff.manage':'Sales owner'};return labels[value]??'Authorized tenant user'}

function xsrf() { return decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '') }
async function api(path: string, method = 'GET', body?: unknown) {
  const response = await fetch(`/api/v1${path}`, { method, credentials: 'include', headers: { Accept: 'application/json', 'X-Tenant-ID': props.tenantId, ...(body ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.message ?? `Request failed (${response.status}).`)
  return data
}
async function load() {
  loading.value = true; error.value = ''
  try {
    if (props.view === 'workflows') { const result = await api('/automation/workflows'); rows.value = result.data?.data ?? []; if (selected.value?.id) await openWorkflow(selected.value.id) }
    if (props.view === 'approvals') { const [result, proposals] = await Promise.all([api('/automation/approvals'), api('/proposals')]); rows.value = result.data?.data ?? []; proposalRows.value = (proposals.data ?? []).filter((item: any) => ['review_required', 'commercial_input_required'].includes(item.status)) }
    if (props.view === 'policies') { policy.value = await api('/automation/policies') }
    if (props.view === 'summary') { summary.value = await api('/automation/summary') }
    const result = await api('/companies'); companies.value = result.data ?? []
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load orchestration data.' }
  finally { loading.value = false }
}
async function openWorkflow(id: string) { try { selected.value = await api(`/automation/workflows/${encodeURIComponent(id)}`) } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load workflow.' } }
async function openApprovalContext(id: string) { try { approvalWorkflow.value = await api(`/automation/workflows/${encodeURIComponent(id)}`) } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load workflow context.' } }
async function control(operation: string) {
  if (!workflow.value?.id) return
  try { await api(`/automation/workflows/${workflow.value.id}/${operation}`, 'POST', {}); notice.value = `Workflow action completed: ${operation}.`; await load() }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Workflow action failed.' }
}
async function createWorkflow() {
  if (!companyId.value) return
  try { const result = await api('/automation/workflows', 'POST', { company_id: companyId.value }); companyId.value = ''; notice.value = 'B2B acquisition workflow created.'; await load(); await openWorkflow(result.data.id) }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Workflow creation failed.' }
}
async function decide(row: any, decision: 'approve' | 'reject') {
  if (decision === 'approve' && ['HIGH', 'CRITICAL'].includes(row.risk) && !window.confirm(`Confirm ${row.action} for ${row.company_name || 'this target'}? The action will be revalidated and dispatched through its controlled handler.`)) return
  try { await api(`/automation/approvals/${row.id}/${decision}`, 'POST', {}); notice.value = `Approval ${decision}d.`; await load() }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Approval review failed.' }
}
async function savePolicy() {
  try { policy.value = await api('/automation/policies', 'PUT', { autonomy_mode: policy.value.autonomy_mode, daily_ai_call_limit: policy.value.daily_ai_call_limit || null,
    daily_token_limit: policy.value.daily_token_limit || null, monthly_estimated_spend_limit: policy.value.monthly_estimated_spend_limit || null,
    actions: policy.value.actions.map((item: any) => ({ action: item.action, policy: item.current_policy })) }); notice.value = 'Automation policies saved.' }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save automation policies.' }
}
onMounted(() => void load())
</script>

<template>
  <div class="orchestration-workspace">
    <div v-if="error" class="notice">{{ error }} <button class="quiet" @click="load">Retry</button></div>
    <div v-if="notice" class="notice success-notice">{{ notice }}</div>
    <template v-if="view === 'summary'">
      <section class="orchestration-stats">
        <article v-for="item in [['Active Workflows','active_workflows'],['Waiting Approval','waiting_approval'],['Human Handoffs','human_handoffs'],['Failed / Paused','failed_or_paused'],['AI Runs Today','ai_runs_today'],['Approvals Pending','approvals_pending']]" :key="item[1]" class="stat-card"><div class="stat-top"><span>{{ item[0] }}</span><span class="stat-icon violet">◈</span></div><strong>{{ summary[item[1]] ?? 0 }}</strong><small>Current tenant telemetry</small></article>
      </section>
    </template>
    <template v-else-if="view === 'workflows'">
      <section class="panel">
        <div class="panel-heading"><div><h2>B2B acquisition workflows</h2><p>Tenant-scoped execution timelines linked to existing agents and domain services.</p></div><button class="quiet" @click="load">↻ Refresh</button></div>
        <form class="orchestration-create" @submit.prevent="createWorkflow"><select v-model="companyId" required><option value="" disabled>Select a company</option><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select><button class="primary">＋ Start workflow</button></form>
        <div class="orchestration-layout">
          <div class="orchestration-list"><button v-for="row in rows" :key="row.id" class="orchestration-row" :class="{ selected: workflow?.id === row.id }" @click="openWorkflow(row.id)"><b>{{ row.company_id || 'Unlinked company' }}</b><span class="pill">{{ row.status }}</span><small>{{ row.current_stage }} · {{ new Date(row.updated_at).toLocaleString() }}</small></button><div v-if="!rows.length" class="empty-table">{{ loading ? 'Loading workflows…' : 'No workflows have been started.' }}</div></div>
          <div v-if="selected?.workflow" class="orchestration-detail"><div class="panel-heading"><div><h3>{{ selected.workflow.workflow_type }}</h3><p>{{ selected.workflow.current_stage }} · {{ selected.workflow.status }} · {{ selected.workflow.autonomy_policy }}</p></div></div><p class="workflow-id">{{ selected.workflow.id }} · Correlation {{ selected.workflow.correlation_id }}</p>
            <div class="workflow-context"><b>Next permitted action</b><p>{{ selected.context?.next_permitted_action }}</p><p v-if="selected.context?.waiting_reason"><b>Waiting reason:</b> {{ selected.context.waiting_reason }}</p><div class="context-links"><span v-if="selected.context?.campaign">Campaign: {{ selected.context.campaign.name }} ({{ selected.context.campaign.status }})</span><span v-if="selected.context?.conversation">Conversation: {{ selected.context.conversation.status }} · Ownership {{ selected.context.conversation.ownership_state }}</span><span v-if="selected.context?.opportunity">Opportunity: {{ selected.context.opportunity.stage }} · Score {{ selected.context.opportunity.qualification_score }}</span><span v-if="selected.context?.meeting">Meeting: {{ selected.context.meeting.status }} · {{ selected.context.meeting.starts_at }}</span><span v-if="selected.context?.proposal">Proposal: {{ selected.context.proposal.status }} · v{{ selected.context.proposal.version }}</span></div></div>
            <div class="orchestration-actions"><button v-if="['RUNNING','WAITING_APPROVAL','WAITING_EXTERNAL'].includes(selected.workflow.status)" class="quiet" @click="control('pause')">Pause</button><button v-if="selected.workflow.status === 'PAUSED'" class="quiet" @click="control('resume')">Resume</button><button v-if="selected.workflow.status === 'FAILED'" class="quiet" @click="control('retry')">Retry</button><button v-if="!['COMPLETED','CANCELLED'].includes(selected.workflow.status)" class="quiet danger" @click="control('cancel')">Cancel</button></div>
            <h4>Execution timeline</h4><ol class="workflow-timeline"><li v-for="event in selected.events" :key="event.id"><span class="timeline-dot"></span><div><b>{{ event.event.replaceAll('_',' ') }}</b><small>{{ event.stage }} · {{ event.source }} · {{ new Date(event.created_at).toLocaleString() }}</small><small v-if="event.safe_metadata && Object.keys(JSON.parse(event.safe_metadata || '{}')).length">{{ JSON.stringify(JSON.parse(event.safe_metadata || '{}')) }}</small></div></li></ol>
            <h4>Agent runs</h4><div v-for="run in selected.agent_runs" :key="run.id" class="workflow-run"><b>{{ run.agent_key }}</b><span class="pill">{{ run.status }}</span><small>{{ run.summary || run.error_code || 'No summary' }}</small></div>
          </div><div v-else class="empty-table">Select a workflow to inspect its timeline.</div>
        </div>
      </section>
    </template>
    <template v-else-if="view === 'approvals'">
      <section class="panel"><div class="panel-heading"><div><h2>Approval Center</h2><p>Review what will happen and who is affected before authorizing a controlled workflow action.</p></div><button class="quiet" @click="load">↻ Refresh</button></div>
        <section v-if="proposalRows.length" class="proposal-approval-list"><h3>Proposal reviews</h3><p>Proposal approvals remain a human review step and do not send the proposal.</p><article v-for="proposal in proposalRows" :key="proposal.id"><div><b>Review proposal for {{ proposal.opportunity?.company?.name || 'prospect' }}</b><small>{{ proposal.title }} · {{ proposal.status === 'review_required' ? 'Needs human review' : 'Needs commercial input' }} · {{ formatMoney(proposal.total,proposal.currency) }}</small></div><button class="primary" @click="emit('openProposal',proposal.id)">Open proposal review →</button></article></section>
        <div v-for="group in approvalGroups" :key="group.name" class="approval-group"><h3>{{ group.name }}</h3>
          <article v-for="row in group.rows" :key="row.id" class="approval-row"><div class="approval-risk" :class="row.risk.toLowerCase()">{{ row.risk }}</div><div class="approval-content"><b>{{ actionTitle(row) }}</b><small>{{ row.company_name || 'Prospect context unavailable' }}<span v-if="row.contact_name"> · {{ row.contact_name }}</span> · {{ row.current_stage || 'Sales workflow' }}</small><p>{{ row.reason }}</p><p v-if="row.content_summary" class="approval-summary">{{ row.content_summary }}</p><small v-if="row.qualification">Qualification: {{ row.qualification.level || 'Unclassified' }} · score {{ row.qualification.score ?? '—' }}</small><small>After approval: {{ afterApproval(row) }}</small><small>{{ /message|email|outreach/i.test(row.action) ? 'External action · Not reversible after queueing' : 'Controlled workflow action · Review before approval' }}</small><small>Requested {{ new Date(row.requested_at).toLocaleString() }} · Expires {{ row.expires_at ? new Date(row.expires_at).toLocaleString() : 'No expiry recorded' }}</small><button v-if="row.company_id && row.company_name" class="quiet" @click="emit('openProspect', {id:row.company_id,name:row.company_name})">Open Prospect 360 →</button><button class="quiet" @click="openApprovalContext(row.workflow_id)">Open workflow timeline →</button><details class="technical-details"><summary>Technical details</summary><small>Target {{ row.target_type }} · {{ row.target_id }}</small><small>Workflow {{ row.workflow_id }} · {{ row.workflow_status }}<span v-if="row.agent_key"> · {{ row.agent_key }}</span></small><small v-if="row.evidence_references?.length">Evidence references: {{ row.evidence_references.join(', ') }}</small><small v-if="row.confidence !== null">Classification confidence {{ Math.round(row.confidence * 100) }}%</small></details></div><div class="approval-buttons"><button class="primary" :disabled="!row.content_summary && ['outbound_message','conversation'].includes(row.target_type)" @click="decide(row,'approve')">Approve</button><button class="quiet" @click="decide(row,'reject')">Reject</button></div></article>
        </div>
        <div v-if="approvalWorkflow" class="approval-context"><h3>Workflow timeline: {{ approvalWorkflow.workflow.current_stage }} · {{ approvalWorkflow.workflow.status }}</h3><p v-for="event in approvalWorkflow.events.slice(-8)" :key="event.id">{{ event.event.replaceAll('_',' ') }} · {{ new Date(event.created_at).toLocaleString() }}</p></div>
        <div v-if="!rows.length" class="empty-table">{{ loading ? 'Loading approvals…' : 'No pending approvals. New requests will appear here.' }}</div>
      </section>
    </template>
    <template v-else>
      <section class="panel"><div class="panel-heading"><div><h2>Automation management</h2><p>System boundaries remain authoritative. Tenant policy can only add restrictions.</p></div></div>
        <div class="policy-settings"><label>Autonomy mode<select v-model="policy.autonomy_mode"><option value="MANUAL">Manual review · every action requires a person</option><option value="ASSISTED">AI assisted · humans control consequential actions</option><option value="CONTROLLED">Controlled automation · restricted policy actions only</option></select></label><label>Daily AI calls<input v-model="policy.daily_ai_call_limit" type="number" min="1" placeholder="No limit"/></label><label>Daily token limit<input v-model="policy.daily_token_limit" type="number" min="1" placeholder="No limit"/></label><label>Monthly estimated spend<input v-model="policy.monthly_estimated_spend_limit" type="number" min="0.0001" step="0.01" placeholder="No limit"/></label><button class="primary" @click="savePolicy">Save policy</button></div>
        <div class="table-wrap"><table><thead><tr><th>ACTION</th><th>RISK</th><th>APPROVAL OWNER</th><th>IMPACT</th><th>SYSTEM DEFAULT</th><th>EFFECTIVE POLICY</th></tr></thead><tbody><tr v-for="action in policy.actions" :key="action.action"><td><b>{{ action.action.replaceAll('_',' ').toLowerCase().replace(/\b\w/g,(letter:string)=>letter.toUpperCase()) }}</b><small>{{ riskLabel(action.risk) }} impact</small></td><td>{{ riskLabel(action.risk) }}</td><td>{{ permissionLabel(action.permission) }}</td><td>{{ sideEffectLabel(action.side_effect) }}</td><td>{{ policyLabel(action.default_policy) }}</td><td><select v-model="action.current_policy"><option v-for="value in ['AUTO_ALLOWED','APPROVAL_REQUIRED','HUMAN_ONLY','DENIED']" :key="value" :value="value" :disabled="['AUTO_ALLOWED','APPROVAL_REQUIRED','HUMAN_ONLY','DENIED'].indexOf(value) < ['AUTO_ALLOWED','APPROVAL_REQUIRED','HUMAN_ONLY','DENIED'].indexOf(action.default_policy)">{{ policyLabel(value) }}</option></select></td></tr></tbody></table></div>
        <p class="policy-note">Full autonomy, autonomous pricing/discounts, proposal sending, meeting booking, and arbitrary tool execution are not available. Monthly spend limits require verified per-model pricing metadata.</p>
      </section>
    </template>
  </div>
</template>
