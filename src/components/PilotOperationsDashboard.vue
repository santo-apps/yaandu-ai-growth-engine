<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { salesRequest } from '../salesApi'

const props = defineProps<{ tenantId: string }>()
const emit = defineEmits<{ openProspects: [] }>()
const status = ref<any>(null)
const busy = ref(false)
const error = ref('')
let refreshTimer: ReturnType<typeof setInterval> | undefined

async function refresh() {
  busy.value = true; error.value = ''
  try { status.value = await salesRequest('/pilot/operations', props.tenantId) }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Operational status is unavailable.' }
  finally { busy.value = false }
}

onMounted(() => { void refresh(); refreshTimer = setInterval(() => { void refresh() }, 15000) })
onBeforeUnmount(() => { if (refreshTimer) clearInterval(refreshTimer) })
</script>

<template>
  <section class="operations-dashboard">
    <div class="operations-heading"><div><span class="eyebrow">PILOT OPERATIONS</span><h2>Runtime and workflow health</h2><p>Infrastructure counts are installation-wide. Workflow failure summaries and events are scoped to this tenant.</p></div><button class="quiet" :disabled="busy" @click="refresh">{{ busy ? 'Refreshing…' : 'Refresh status' }}</button></div>
    <p v-if="error" class="ops-error" role="alert">{{ error }}</p>
    <template v-if="status">
      <div class="ops-health-grid">
        <article><small>Redis</small><b :class="status.redis === 'available' ? 'healthy' : 'unhealthy'">{{ status.redis }}</b></article>
        <article><small>Horizon</small><b :class="status.horizon.status === 'running' ? 'healthy' : 'unhealthy'">{{ status.horizon.status }}</b><span>{{ status.horizon.supervisors.length }} active supervisor(s)</span></article>
        <article><small>Failed queue jobs</small><b>{{ status.failed_queue_jobs }}</b><span>Installation-wide count; payloads are not exposed</span></article>
      </div>
      <section class="ops-panel"><div class="ops-panel-heading"><div><h3>Queue backlog</h3><p>Waiting, delayed and reserved work reported by Laravel's Redis queue connection.</p></div><span class="scope-label">Installation-wide</span></div><div class="queue-grid"><article v-for="(count, queue) in status.queue_backlog" :key="queue"><span>{{ queue }}</span><b>{{ count }}</b></article><p v-if="status.redis !== 'available'">Queue counts could not be read from Redis.</p></div><div v-if="status.horizon.supervisors.length" class="worker-list"><b>Workers</b><div v-for="(supervisor, index) in status.horizon.supervisors" :key="index"><span>{{ supervisor.status }}</span><small>{{ supervisor.queues.map((item: any) => `${item.queue} (${item.workers})`).join(' · ') }}</small></div></div></section>
      <div class="ops-columns"><section class="ops-panel"><h3>Tenant workflow health</h3><div class="ops-metrics"><article><span>Failed imports</span><b>{{ status.tenant_metrics.failed_import_rows }}</b></article><article><span>Website crawl failures</span><b>{{ status.tenant_metrics.website_crawl_failures }}</b></article><article><span>AI provider failures</span><b>{{ status.tenant_metrics.ai_provider_failures }}</b></article><article><span>Budget exhaustion</span><b>{{ status.tenant_metrics.budget_exhaustion_events }}</b></article><article><span>Failed or paused workflows</span><b>{{ status.tenant_metrics.workflow_errors }}</b></article><article><span>Retryable workflow errors</span><b>{{ status.tenant_metrics.retryable_workflow_errors }}</b></article></div><button class="text-action" @click="emit('openProspects')">Review imports and retry failed rows →</button></section>
        <section class="ops-panel"><h3>Recent workflow events</h3><div v-if="status.recent_events.length" class="ops-event-list"><article v-for="(event, index) in status.recent_events" :key="`${event.event}-${event.occurred_at}-${index}`"><b>{{ event.event.replaceAll('_', ' ') }}</b><span>{{ event.stage.replaceAll('_', ' ') }}</span><small>{{ event.occurred_at }}</small></article></div><p v-else class="ops-empty">No workflow events recorded for this tenant.</p></section></div>
      <p class="ops-safety">Operational summaries omit credentials, raw provider payloads, exception text, and message content. No send or retry is initiated from this dashboard.</p>
    </template>
    <div v-else-if="!error" class="ops-loading">Loading runtime health…</div>
  </section>
</template>

<style scoped>
.operations-dashboard{display:grid;gap:13px;min-width:0}.operations-heading,.ops-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.operations-heading h2{margin:4px 0;color:#253752;font-size:16px}.operations-heading p,.ops-panel-heading p{margin:0;color:#78869a;font-size:10px;line-height:1.5}.ops-health-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.ops-health-grid article,.ops-panel{background:#fff;border:1px solid #e4e9f0;border-radius:11px;padding:14px;min-width:0}.ops-health-grid article{display:grid;gap:7px}.ops-health-grid small,.ops-health-grid span{font-size:10px;color:#79869a}.ops-health-grid b{font-size:18px;text-transform:capitalize;color:#35465f}.healthy{color:#1e8058!important}.unhealthy{color:#ab5947!important}.ops-panel h3{margin:0 0 10px;color:#3b4e69;font-size:12px}.scope-label{font-size:9px;color:#6b7890;background:#f2f5f9;border-radius:12px;padding:5px 7px}.queue-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin-top:12px}.queue-grid article{display:flex;justify-content:space-between;gap:6px;padding:8px;border:1px solid #edf0f4;border-radius:7px;font-size:10px;color:#6f7c90;min-width:0}.queue-grid article span{overflow-wrap:anywhere}.queue-grid b{color:#33455e}.queue-grid p,.ops-empty,.ops-loading{color:#77849a;font-size:11px}.worker-list{display:grid;gap:7px;margin-top:12px;padding-top:10px;border-top:1px solid #edf0f4}.worker-list>b{font-size:10px;color:#64738a}.worker-list div{display:grid;grid-template-columns:80px 1fr;gap:8px;font-size:10px}.worker-list div span{text-transform:capitalize;color:#26815d}.worker-list small{color:#77849a;overflow-wrap:anywhere}.ops-columns{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ops-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.ops-metrics article{display:flex;justify-content:space-between;gap:8px;padding:8px;border-bottom:1px solid #eff1f5;font-size:10px;color:#718097}.ops-metrics b{color:#3b4d67}.text-action{margin-top:10px;border:0;background:none;padding:3px 0;color:#435ba0;font-weight:650;font-size:10px;cursor:pointer}.ops-event-list{display:grid;max-height:330px;overflow:auto}.ops-event-list article{display:grid;grid-template-columns:1.2fr .8fr;gap:5px;padding:8px 0;border-bottom:1px solid #eff1f5}.ops-event-list b,.ops-event-list span{font-size:10px;text-transform:capitalize;color:#44546b}.ops-event-list small{grid-column:1/-1;color:#8590a0;font-size:9px}.ops-safety{margin:0;color:#77849a;font-size:9px}.ops-error{padding:10px;background:#fff0ee;color:#9f493e;border-radius:8px;font-size:11px}@media(max-width:760px){.ops-health-grid{grid-template-columns:1fr}.ops-columns{grid-template-columns:1fr}.queue-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:420px){.ops-metrics{grid-template-columns:1fr}.operations-heading{align-items:flex-start}}
</style>
