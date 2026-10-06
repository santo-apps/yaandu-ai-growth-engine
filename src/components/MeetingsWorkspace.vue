<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { humanize, salesRequest, unwrap } from '../salesApi'

const props = defineProps<{ tenantId: string }>()
const emit = defineEmits<{ openProspect: [company: any]; openInbox: [conversationId?: string]; openPipeline: [opportunityId?: string] }>()
const meetings = ref<any[]>([])
const conversations = ref<any[]>([])
const selectedFilter = ref('upcoming')
const loading = ref(false)
const error = ref('')
const selectedMeeting = ref<any | null>(null)
const conversationById = computed(() => new Map(conversations.value.map((item) => [item.id, item])))
const filtered = computed(() => meetings.value.filter((meeting) => {
  const date = new Date(meeting.starts_at).getTime()
  if (selectedFilter.value === 'upcoming') return date >= Date.now() && meeting.status === 'SCHEDULED'
  if (selectedFilter.value === 'past') return date < Date.now() || ['COMPLETED','NO_SHOW','CANCELLED'].includes(meeting.status)
  return true
}))
const isTestMode = computed(() => true)

async function load() {
  loading.value = true; error.value = ''
  try {
    const [meetingResult, conversationResult] = await Promise.all([salesRequest('/meetings', props.tenantId), salesRequest('/conversations', props.tenantId)])
    meetings.value = unwrap(meetingResult); conversations.value = unwrap(conversationResult)
    selectedMeeting.value = filtered.value[0] ?? meetings.value[0] ?? null
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load meetings.' }
  finally { loading.value = false }
}

function prospect(meeting: any) { return conversationById.value.get(meeting.conversation_id)?.company }
function openProspect(meeting: any) { const company = prospect(meeting); if (company) emit('openProspect', company) }
watch(() => props.tenantId, () => { void load() })
watch(selectedFilter, () => { selectedMeeting.value = filtered.value[0] ?? null })
onMounted(() => { void load() })
</script>

<template>
  <section class="meetings-workspace">
    <div class="meeting-banner"><span class="meeting-banner-icon">◷</span><div><b>Scheduling test mode</b><p>Meetings use the configured fake calendar provider. No external calendar invite is sent.</p></div><span v-if="isTestMode" class="test-mode-badge">TEST MODE</span></div>
    <div class="meeting-toolbar"><div><h2>Meetings</h2><p>Review scheduled conversations and their prospect context.</p></div><div class="filter-pills"><button v-for="filter in [{key:'upcoming',label:'Upcoming'},{key:'all',label:'All meetings'},{key:'past',label:'Past'}]" :key="filter.key" :class="{active:selectedFilter===filter.key}" @click="selectedFilter=filter.key">{{ filter.label }}</button></div></div>
    <div v-if="error" class="notice">{{ error }} <button @click="load">Retry</button></div>
    <div class="meetings-layout"><section class="panel meeting-list"><div v-if="loading && !meetings.length" class="sales-state"><span class="spinner"></span><b>Loading meetings</b></div><div v-else-if="!filtered.length" class="sales-state"><span class="state-icon">◷</span><b>{{ selectedFilter === 'upcoming' ? 'No upcoming meetings' : 'No meetings found' }}</b><small>Meetings appear here when a scheduling request is completed.</small></div><button v-for="meeting in filtered" :key="meeting.id" class="meeting-row" :class="{active:selectedMeeting?.id===meeting.id}" @click="selectedMeeting=meeting"><span class="date-tile"><b>{{ new Date(meeting.starts_at).toLocaleDateString(undefined,{day:'2-digit'}) }}</b><small>{{ new Date(meeting.starts_at).toLocaleDateString(undefined,{month:'short'}) }}</small></span><span class="meeting-row-main"><b>{{ meeting.title || 'Sales meeting' }}</b><small>{{ prospect(meeting)?.name || 'Prospect details unavailable' }}</small><small>{{ new Date(meeting.starts_at).toLocaleString() }} · {{ meeting.timezone }}</small></span><span class="pill">{{ humanize(meeting.status) }}</span></button></section>
    <aside class="panel meeting-detail"><template v-if="selectedMeeting"><span class="eyebrow">MEETING DETAILS</span><h3>{{ selectedMeeting.title || 'Sales meeting' }}</h3><p class="meeting-time">{{ new Date(selectedMeeting.starts_at).toLocaleString() }} – {{ new Date(selectedMeeting.ends_at).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}) }} <span>{{ selectedMeeting.timezone }}</span></p><span class="pill">{{ humanize(selectedMeeting.status) }}</span><div class="meeting-context"><small>PROSPECT</small><b>{{ prospect(selectedMeeting)?.name || 'Prospect unavailable' }}</b><span>{{ prospect(selectedMeeting)?.industry || 'Industry not recorded' }} · {{ prospect(selectedMeeting)?.location || 'Location not recorded' }}</span><button v-if="prospect(selectedMeeting)" class="text-button" @click="openProspect(selectedMeeting)">Open Prospect 360 →</button></div><div class="meeting-context"><small>CALENDAR</small><b class="test-label">Fake provider · no invite sent</b><span>Provider state is configured for test use.</span></div><div class="meeting-detail-actions"><button class="quiet" @click="emit('openInbox', selectedMeeting.conversation_id)">Open conversation</button><button v-if="selectedMeeting.sales_opportunity_id" class="quiet" @click="emit('openPipeline', selectedMeeting.sales_opportunity_id)">Open opportunity</button></div></template><div v-else class="sales-state"><b>Select a meeting</b><small>Meeting details and prospect context will appear here.</small></div></aside></div>
  </section>
</template>

<style scoped>
.meetings-workspace{display:grid;gap:14px}.meeting-banner{display:flex;align-items:center;gap:12px;background:#fff9e9;border:1px solid #f1dfa7;border-radius:11px;padding:13px 15px}.meeting-banner-icon{font-size:20px;color:#9c771c}.meeting-banner b{font-size:11px;color:#55451d}.meeting-banner p{font-size:10px;color:#806b36;margin:4px 0 0}.test-mode-badge{margin-left:auto;border-radius:20px;padding:5px 8px;background:#fff0c1;color:#795d15;font-size:9px;font-weight:750;letter-spacing:.06em}.meeting-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:end}.meeting-toolbar h2{margin:0 0 4px;font-size:16px}.meeting-toolbar p{margin:0;color:#838c99;font-size:11px}.filter-pills{display:flex;gap:5px}.filter-pills button{border:1px solid #e4e7ed;border-radius:18px;background:white;padding:7px 11px;color:#687383;font-size:10px;cursor:pointer}.filter-pills button.active{background:#edf1ff;color:#3f57a0;border-color:#dae2ff}.meetings-layout{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr);align-items:start;gap:14px}.meeting-list{overflow:hidden}.meeting-row{width:100%;display:flex;align-items:center;gap:12px;text-align:left;padding:14px;border:0;border-bottom:1px solid #edf0f3;background:white;cursor:pointer}.meeting-row:hover,.meeting-row.active{background:#f8faff}.date-tile{width:42px;flex:0 0 42px;height:45px;display:grid;align-content:center;justify-items:center;gap:2px;background:#f0f3fb;border-radius:9px;color:#415793}.date-tile b{font-size:15px}.date-tile small{text-transform:uppercase;font-size:8px}.meeting-row-main{flex:1;display:grid;gap:4px;min-width:0}.meeting-row-main b{font-size:11px}.meeting-row-main small{font-size:9px;color:#818b99}.meeting-row>.pill{white-space:nowrap}.meeting-detail{padding:17px;position:sticky;top:15px}.meeting-detail h3{margin:7px 0;font-size:15px}.meeting-time{color:#677384;font-size:11px}.meeting-time span{color:#929aa5}.meeting-context{display:grid;gap:6px;padding:14px 0;border-bottom:1px solid #eef0f4}.meeting-context small{font-size:8px;color:#919aa7;letter-spacing:.05em}.meeting-context b{font-size:11px}.meeting-context>span{font-size:10px;color:#828c99}.test-label{color:#9a771c}.meeting-detail-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.meeting-detail-actions button{font-size:9px}.text-button{border:0;background:none;text-align:left;color:#405ba8;font-size:10px;cursor:pointer;padding:0}.sales-state{min-height:180px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#4c5867}.sales-state small{color:#858e9b}
@media(max-width:900px){.meetings-layout{grid-template-columns:1fr}.meeting-detail{position:static}}
@media(max-width:620px){.meeting-toolbar{align-items:flex-start;flex-direction:column}.meeting-row{align-items:flex-start;flex-wrap:wrap}.meeting-row-main{min-width:calc(100% - 60px)}.meeting-row>.pill{margin-left:54px}}
</style>
