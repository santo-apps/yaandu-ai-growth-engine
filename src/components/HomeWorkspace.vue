<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { humanize, salesRequest, unwrap } from '../salesApi'
import PilotDashboard from './PilotDashboard.vue'

const props = defineProps<{ tenantId: string; manager: boolean }>()
const emit = defineEmits<{ openProspects: []; openProspect: [company: any]; openInbox: [conversationId?: string]; openPipeline: [opportunityId?: string]; openMeetings: []; openProposals: []; openApprovals: [] }>()
const summary = ref<any>({})
const companies = ref<any[]>([])
const conversations = ref<any[]>([])
const opportunities = ref<any[]>([])
const meetings = ref<any[]>([])
const proposals = ref<any[]>([])
const approvals = ref<any[]>([])
const loading = ref(true)
const error = ref('')
const openConversations = computed(() => conversations.value.filter((item) => ['human_review','human_active'].includes(item.status)))
const attention = computed(() => [
  ...approvals.value.slice(0, 4).map((item) => ({ kind: 'Approval', label: item.title || item.action || 'Approval needs review', context: item.company?.name || item.target_type || 'Review a pending decision', action: 'Review approvals', click: () => emit('openApprovals') })),
  ...openConversations.value.slice(0, 4).map((item) => ({ kind: 'Reply', label: item.contact?.name || item.company?.name || 'Conversation needs attention', context: `${item.company?.name || 'Prospect'} · ${item.ai_summary || item.recommended_next_action || humanize(item.status)}`, action: 'Open inbox', click: () => emit('openInbox', item.id) })),
  ...opportunities.value.filter((item) => ['QUALIFIED','MEETING_READY','PROPOSAL_READY'].includes(item.stage)).slice(0, 3).map((item) => ({ kind: 'Opportunity', label: item.company?.name || 'Qualified opportunity', context: `${humanize(item.stage)} · next action not recorded`, action: 'Open opportunity', click: () => emit('openPipeline', item.id) })),
  ...proposals.value.filter((item) => ['review_required','commercial_input_required'].includes(item.status)).slice(0, 3).map((item) => ({ kind: 'Proposal', label: item.opportunity?.company?.name || 'Proposal review', context: `${humanize(item.status)} · review required`, action: 'Review proposals', click: () => emit('openProposals') })),
].slice(0, 8))
const upcomingMeetings = computed(() => meetings.value.filter((item) => item.status === 'SCHEDULED' && new Date(item.starts_at).getTime() >= Date.now()).slice(0, 4))

async function load() {
  loading.value = true; error.value = ''
  const [summaryResult, companyResult, conversationResult, opportunityResult, meetingResult, proposalResult, approvalResult] = await Promise.allSettled([
    salesRequest('/dashboard/summary', props.tenantId), salesRequest('/companies', props.tenantId), salesRequest('/conversations', props.tenantId),
    salesRequest('/opportunities', props.tenantId), salesRequest('/meetings', props.tenantId), salesRequest('/proposals', props.tenantId),
    props.manager ? salesRequest('/automation/approvals', props.tenantId) : Promise.resolve([]),
  ])
  if (summaryResult.status === 'fulfilled') summary.value = summaryResult.value
  if (companyResult.status === 'fulfilled') companies.value = unwrap(companyResult.value)
  if (conversationResult.status === 'fulfilled') conversations.value = unwrap(conversationResult.value)
  if (opportunityResult.status === 'fulfilled') opportunities.value = unwrap(opportunityResult.value)
  if (meetingResult.status === 'fulfilled') meetings.value = unwrap(meetingResult.value)
  if (proposalResult.status === 'fulfilled') proposals.value = unwrap(proposalResult.value)
  if (approvalResult.status === 'fulfilled') approvals.value = unwrap(approvalResult.value)
  if ([summaryResult, companyResult, conversationResult, opportunityResult].every((result) => result.status === 'rejected')) error.value = 'Sales workspace data is temporarily unavailable.'
  loading.value = false
}

watch(() => [props.tenantId, props.manager], () => { void load() })
onMounted(() => { void load() })
</script>

<template>
  <section class="home-workspace">
    <PilotDashboard v-if="manager" :tenant-id="tenantId" />
    <div v-if="error" class="notice">{{ error }} <button @click="load">Retry</button></div>
    <div class="home-stats"><button class="home-stat" @click="emit('openProspects')"><small>PROSPECTS</small><b>{{ loading ? '—' : summary.companies ?? companies.length }}</b><span>In your workspace →</span></button><button class="home-stat" @click="emit('openProspects')"><small>QUALIFIED</small><b>{{ loading ? '—' : summary.qualified_leads ?? '—' }}</b><span>Score of 70 or above</span></button><button class="home-stat" @click="emit('openPipeline')"><small>OPEN OPPORTUNITIES</small><b>{{ loading ? '—' : opportunities.filter((item) => !['CLOSED','NOT_QUALIFIED'].includes(item.stage)).length }}</b><span>Across your pipeline →</span></button><button class="home-stat" @click="emit('openMeetings')"><small>UPCOMING MEETINGS</small><b>{{ loading ? '—' : summary.upcoming_meetings ?? upcomingMeetings.length }}</b><span>Scheduled conversations →</span></button></div>
    <div class="home-action-layout"><section class="panel action-center"><div class="panel-heading"><div><h2>Action center</h2><p>Sales work with an available next step.</p></div><span class="action-count">{{ attention.length }}</span></div><div v-if="loading && !attention.length" class="sales-state"><span class="spinner"></span><b>Loading your work</b></div><div v-else-if="!attention.length" class="sales-state"><span class="state-icon">✓</span><b>You’re all caught up</b><small>New replies and review work will appear here when available.</small><button class="primary" @click="emit('openProspects')">Review prospects</button></div><button v-for="item in attention" :key="`${item.kind}-${item.label}`" class="action-row" @click="item.click()"><span class="action-type">{{ item.kind }}</span><span class="action-copy"><b>{{ item.label }}</b><small>{{ item.context }}</small></span><span class="action-link">{{ item.action }} →</span></button></section>
    <aside class="panel home-side"><div class="panel-heading"><div><h2>Upcoming meetings</h2><p>Scheduled in the workspace</p></div><button class="text-button" @click="emit('openMeetings')">All →</button></div><div v-if="upcomingMeetings.length" class="upcoming-list"><article v-for="meeting in upcomingMeetings" :key="meeting.id"><span class="calendar-mark">◷</span><div><b>{{ meeting.title || 'Sales meeting' }}</b><small>{{ new Date(meeting.starts_at).toLocaleString() }} · {{ meeting.timezone }}</small></div></article></div><div v-else class="inline-empty">No upcoming meetings recorded.</div><div class="test-indicator"><span>TEST MODE</span> Calendar invites are not sent.</div></aside></div>
    <section class="panel recent-prospects"><div class="panel-heading"><div><h2>Recent prospects</h2><p>Continue work on a company in your tenant.</p></div><button class="text-button" @click="emit('openProspects')">All prospects →</button></div><div v-if="companies.length" class="recent-list"><button v-for="company in companies.slice(0,5)" :key="company.id" @click="emit('openProspect', company)"><span class="company-mark">{{ company.name?.slice(0,1) || 'C' }}</span><span><b>{{ company.name }}</b><small>{{ company.industry || 'Industry not recorded' }} · {{ company.location || 'Location not recorded' }}</small></span><span class="pill">{{ humanize(company.status) }}</span><span class="arrow">→</span></button></div><div v-else class="inline-empty">{{ loading ? 'Loading prospects…' : 'No prospects have been added yet.' }}</div></section>
  </section>
</template>

<style scoped>
.home-workspace{display:grid;gap:15px}.home-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.home-stat{display:grid;gap:7px;min-height:105px;text-align:left;background:white;border:1px solid #e5e8ef;border-radius:11px;padding:15px;cursor:pointer}.home-stat:hover{border-color:#cbd5ef}.home-stat small{font-size:8px;color:#8992a0;letter-spacing:.06em;font-weight:700}.home-stat b{font-size:23px;color:#29364a}.home-stat span{font-size:10px;color:#7c8796}.home-action-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(270px,.65fr);gap:13px;align-items:start}.action-center,.home-side,.recent-prospects{padding:17px}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.panel-heading h2{font-size:14px;margin:0}.panel-heading p{font-size:10px;color:#818b99;margin:5px 0 0}.action-count{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:#edf1fb;color:#475f9f;font-size:10px;font-weight:700}.action-row{display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:13px 0;border:0;border-bottom:1px solid #edf0f4;background:white;cursor:pointer}.action-row:hover .action-link{color:#293f85}.action-type{min-width:75px;padding:5px 7px;text-align:center;border-radius:14px;background:#f2f4f8;color:#687487;font-size:9px}.action-copy{display:grid;gap:4px;flex:1;min-width:0}.action-copy b{font-size:11px;color:#303a48}.action-copy small{font-size:9px;color:#87909d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.action-link{font-size:9px;color:#4960a5;white-space:nowrap}.sales-state{min-height:180px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;text-align:center}.sales-state b{font-size:12px}.sales-state small,.inline-empty{font-size:10px;color:#858e9b}.upcoming-list{display:grid}.upcoming-list article{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid #eff1f4}.calendar-mark{width:30px;height:30px;border-radius:8px;background:#eef2fa;color:#5268a5;display:grid;place-items:center}.upcoming-list article div{display:grid;gap:4px}.upcoming-list b{font-size:10px}.upcoming-list small{font-size:9px;color:#818b99}.test-indicator{margin-top:12px;padding:8px;background:#fff8e6;border-radius:7px;color:#80682f;font-size:9px}.test-indicator span{font-weight:800;margin-right:5px}.recent-prospects .panel-heading{margin-bottom:8px}.recent-list button{width:100%;display:flex;gap:10px;align-items:center;text-align:left;padding:10px 0;border:0;border-bottom:1px solid #eef0f4;background:white;cursor:pointer}.company-mark{width:31px;height:31px;flex:0 0 31px;border-radius:9px;background:#edf1fa;color:#4960a1;display:grid;place-items:center;font-weight:700}.recent-list button>span:nth-child(2){display:grid;gap:4px;flex:1}.recent-list b{font-size:11px}.recent-list small{font-size:9px;color:#868f9c}.recent-list .pill{font-size:9px}.arrow{color:#6b7891}
@media(max-width:900px){.home-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.home-action-layout{grid-template-columns:1fr}}
@media(max-width:600px){.home-stats{gap:7px}.home-stat{padding:11px;min-height:90px}.action-row{align-items:flex-start;flex-wrap:wrap}.action-link{margin-left:87px}.recent-list .pill{display:none}}
</style>
