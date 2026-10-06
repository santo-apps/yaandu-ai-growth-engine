<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { humanize, salesRequest, unwrap } from '../salesApi'

const props = defineProps<{ tenantId: string; focusId?: string }>()
const emit = defineEmits<{ openProspect: [company: any]; openInbox: [conversationId?: string]; openMeetings: []; openProposals: [] }>()
const opportunities = ref<any[]>([])
const selected = ref<any | null>(null)
const detail = ref<any | null>(null)
const loading = ref(false)
const error = ref('')
const stage = ref('')
const stages = ['NEW','ENGAGED','DISCOVERY','QUALIFIED','MEETING_READY','PROPOSAL_READY','CLOSED','NOT_QUALIFIED']
const filtered = computed(() => opportunities.value.filter((item) => !stage.value || item.stage === stage.value))

function formatCurrency(value: unknown, currency: unknown) {
  const amount = Number(value)
  if (!Number.isFinite(amount)) return 'Not estimated'
  const code = typeof currency === 'string' && /^[A-Z]{3}$/.test(currency) ? currency : 'INR'
  try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency: code, maximumFractionDigits: 0 }).format(amount) }
  catch { return `${code} ${amount.toLocaleString('en-IN')}` }
}

async function load() {
  loading.value = true; error.value = ''
  try {
    const result = await salesRequest('/opportunities', props.tenantId)
    opportunities.value = unwrap(result)
    const target = opportunities.value.find((row) => row.id === props.focusId) ?? (selected.value ? opportunities.value.find((row) => row.id === selected.value?.id) : undefined) ?? opportunities.value[0]
    if (target) await selectOpportunity(target)
    else { selected.value = null; detail.value = null }
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load pipeline.' }
  finally { loading.value = false }
}

async function selectOpportunity(opportunity: any) {
  selected.value = opportunity; error.value = ''
  try { detail.value = await salesRequest(`/opportunities/${encodeURIComponent(opportunity.id)}`, props.tenantId) }
  catch (exception) { detail.value = null; error.value = exception instanceof Error ? exception.message : 'Unable to load opportunity details.' }
}

watch(() => [props.tenantId, props.focusId], () => { void load() })
onMounted(() => { void load() })
</script>

<template>
  <section class="pipeline-workspace">
    <div class="pipeline-intro"><div><h2>Your opportunities</h2><p>Review qualification, ownership, and the next step for each open sales opportunity.</p></div><label>Stage<select v-model="stage"><option value="">All stages</option><option v-for="item in stages" :key="item" :value="item">{{ humanize(item) }}</option></select></label></div>
    <div v-if="error" class="notice">{{ error }} <button @click="load">Retry</button></div>
    <div class="pipeline-layout"><section class="panel pipeline-list"><div v-if="loading && !opportunities.length" class="sales-state"><span class="spinner"></span><b>Loading pipeline</b></div><div v-else-if="!filtered.length" class="sales-state"><span class="state-icon">↗</span><b>No opportunities yet</b><small>Opportunities appear as conversations are qualified.</small><button class="quiet" @click="emit('openInbox')">Review Inbox</button></div><button v-for="item in filtered" :key="item.id" class="opportunity-row" :class="{ selected: selected?.id === item.id }" @click="selectOpportunity(item)"><div class="opportunity-company"><span class="company-mark">{{ item.company?.name?.slice(0,1) || 'C' }}</span><span><b>{{ item.company?.name || 'Company' }}</b><small>{{ item.company?.industry || 'Industry not recorded' }} · {{ item.company?.location || 'Location not recorded' }}</small></span></div><span class="pill">{{ humanize(item.stage) }}</span><div class="opportunity-meta"><span><small>QUALIFICATION</small><b>{{ humanize(item.qualification_level || item.qualification?.score?.level) }}</b></span><span><small>VALUE</small><b>{{ formatCurrency(item.value, item.currency) }}</b></span><span><small>UPDATED</small><b>{{ item.updated_at ? new Date(item.updated_at).toLocaleDateString() : '—' }}</b></span><span><small>PROPOSALS</small><b>{{ item.proposals_count ?? 0 }}</b></span></div></button><div v-if="filtered.length" class="pipeline-footnote">List view · stage movement remains available through authorized existing workflows.</div></section>
    <aside class="panel opportunity-detail"><template v-if="selected"><div class="detail-head"><div><span class="eyebrow">OPPORTUNITY</span><h3>{{ selected.company?.name || 'Sales opportunity' }}</h3></div><span class="pill">{{ humanize(selected.stage) }}</span></div><button v-if="selected.company" class="company-link" @click="emit('openProspect', selected.company)">Open Prospect 360 →</button><div class="detail-kpis"><div><small>QUALIFICATION</small><b>{{ humanize(selected.qualification_level || selected.qualification?.score?.level) }}</b></div><div><small>ESTIMATED VALUE</small><b>{{ selected.value ? formatCurrency(selected.value, selected.currency) : 'Not estimated' }}</b></div><div><small>STATUS</small><b>{{ humanize(selected.status) }}</b></div></div><h4>Qualification</h4><template v-if="selected.qualification?.score?.components"><div v-for="(item, key) in selected.qualification.score.components" :key="key" class="qualification-row"><span>{{ humanize(key) }}</span><b>{{ humanize(item.level) }}</b><small>{{ item.evidence || 'No supporting evidence recorded.' }}</small></div></template><div v-else class="inline-empty">Detailed qualification information is not available for this opportunity.</div><h4>Recent activity</h4><article v-for="activity in detail?.activities?.slice(0,5) ?? []" :key="activity.id" class="activity-row"><div><b>{{ humanize(activity.activity_type) }}</b><small>{{ activity.occurred_at }}</small></div></article><p v-if="!detail?.activities?.length" class="inline-empty">No activity is recorded yet.</p><div v-for="meeting in detail?.meetings ?? []" :key="meeting.id" class="activity-row"><div><b>{{ meeting.title || 'Meeting' }}</b><small>{{ humanize(meeting.status) }} · {{ meeting.starts_at }}</small></div><button class="text-button" @click="emit('openMeetings')">Meetings</button></div><div class="detail-actions"><button v-if="selected.conversation_id" class="quiet" @click="emit('openInbox', selected.conversation_id)">Open conversation</button><button class="quiet" @click="emit('openMeetings')">Meetings</button><button class="quiet" @click="emit('openProposals')">Proposals ({{ selected.proposals_count ?? 0 }})</button></div></template><div v-else class="sales-state"><b>Select an opportunity</b><small>Its details will appear here.</small></div></aside></div>
  </section>
</template>

<style scoped>
.pipeline-workspace{display:grid;gap:14px}.pipeline-intro{display:flex;justify-content:space-between;align-items:end}.pipeline-intro h2{font-size:16px;margin:0 0 4px}.pipeline-intro p{font-size:11px;color:#7d8693;margin:0}.pipeline-intro label{display:grid;gap:5px;font-size:10px;color:#858d99}.pipeline-intro select{min-width:150px;height:36px;border:1px solid #e2e6ed;border-radius:8px;padding:0 9px;background:white;color:#303a48}.pipeline-layout{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.8fr);align-items:start;gap:14px}.pipeline-list{overflow:hidden}.opportunity-row{width:100%;display:grid;grid-template-columns:minmax(190px,1.2fr) auto;gap:12px;align-items:center;text-align:left;background:white;border:0;border-bottom:1px solid #edf0f4;padding:15px;cursor:pointer}.opportunity-row:hover,.opportunity-row.selected{background:#f8faff}.opportunity-row.selected{box-shadow:inset 3px 0 #5269b0}.opportunity-company{display:flex;gap:10px;align-items:center}.company-mark{width:34px;height:34px;border-radius:10px;background:#eef2fa;color:#495fa0;display:grid;place-items:center;font-weight:700}.opportunity-company>span:last-child{display:grid;gap:4px}.opportunity-company b{font-size:12px;color:#303a48}.opportunity-company small{font-size:10px;color:#858e9b}.opportunity-meta{grid-column:1/-1;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding-left:44px}.opportunity-meta span{display:grid;gap:4px}.opportunity-meta small,.detail-kpis small{font-size:8px;color:#969eaa;letter-spacing:.04em}.opportunity-meta b,.detail-kpis b{font-size:10px;color:#4d5969}.pipeline-footnote{padding:10px 14px;font-size:9px;color:#939ba6}.opportunity-detail{padding:16px;position:sticky;top:15px}.detail-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.detail-head h3{margin:4px 0;font-size:15px}.company-link,.text-button{border:0;padding:8px 0;background:none;color:#425da9;font-size:10px;font-weight:650;cursor:pointer}.detail-kpis{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:13px 0;border-bottom:1px solid #eef0f3}.detail-kpis div{display:grid;gap:5px}.opportunity-detail h4{font-size:11px;margin:16px 0 7px}.qualification-row{display:grid;grid-template-columns:1fr auto;gap:6px;padding:9px 0;border-bottom:1px solid #eff1f4;font-size:10px}.qualification-row small{grid-column:1/-1;color:#87909d}.activity-row{padding:8px 0;border-bottom:1px solid #eff1f4}.activity-row div{display:grid;gap:4px}.activity-row b{font-size:10px}.activity-row small{color:#87909d;font-size:9px}.inline-empty{font-size:10px;color:#858e9b}.detail-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.detail-actions button{font-size:9px}.sales-state{min-height:165px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;text-align:center;color:#4a5667}.sales-state small{color:#838c99}
@media(max-width:950px){.pipeline-layout{grid-template-columns:1fr}.opportunity-detail{position:static}}
@media(max-width:600px){.pipeline-intro{align-items:flex-start;flex-direction:column;gap:10px}.opportunity-row{grid-template-columns:minmax(0,1fr) auto}.opportunity-meta{padding-left:0;grid-template-columns:repeat(2,1fr)}}
</style>
