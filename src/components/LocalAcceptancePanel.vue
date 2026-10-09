<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'

const props = defineProps<{ tenantId: string }>()
type AcceptanceState = {
  mode: string
  providers: { ai: string; outbound: string; scheduling: string }
  queue: string
  company_id: string
  campaign_id: string
  campaign_status: string
  message: { id: string; status: string; provider: string } | null
  inbound_count: number
  conversation_id: string | null
  opportunity_id: string | null
  meeting_id: string | null
  proposal_id: string | null
  proposal_delivery_count: number
}
const state = ref<AcceptanceState | null>(null)
const busy = ref(false)
const error = ref('')

function headers(json = false) {
  const xsrf = decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
  return { Accept: 'application/json', ...(json ? { 'Content-Type': 'application/json' } : {}), 'X-XSRF-TOKEN': xsrf, 'X-Tenant-ID': props.tenantId }
}

async function refresh() {
  try {
    const response = await fetch('/api/v1/local-acceptance/status', { credentials: 'include', headers: headers() })
    if (!response.ok) { state.value = null; error.value = `Local acceptance controls unavailable (${response.status}).`; return }
    state.value = await response.json()
    error.value = ''
  } catch { state.value = null; error.value = 'Local acceptance controls are unreachable.' }
}

async function post(path: string, body?: object) {
  busy.value = true; error.value = ''
  try {
    const response = await fetch(`/api/v1/local-acceptance/${path}`, { method: 'POST', credentials: 'include', headers: headers(true), body: JSON.stringify(body ?? {}) })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message ?? `Acceptance helper failed (${response.status}).`)
    state.value = data
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Local acceptance helper failed.' }
  finally { busy.value = false }
}

watch(() => props.tenantId, refresh)
onMounted(refresh)
</script>

<template>
  <aside v-if="state" class="local-acceptance" aria-label="Local acceptance controls">
    <div class="acceptance-head"><strong>LOCAL ACCEPTANCE · TEST MODE</strong><span>AI, outbound email, and calendar are deterministic fakes. No external messages or meetings.</span></div>
    <div class="acceptance-state"><span>{{ state.providers.ai }}</span><span>{{ state.providers.outbound }}</span><span>{{ state.providers.scheduling }}</span><span>Queue: {{ state.queue }}</span></div>
    <div class="acceptance-actions">
      <button v-if="state.message?.status === 'accepted' && state.message.provider === 'fake'" class="quiet" :disabled="busy" @click="post(`outbound/${state.message.id}/sent`)">Confirm fake delivery</button>
      <button v-if="state.message?.status === 'sent' && state.inbound_count === 0" class="quiet" :disabled="busy" @click="post('reply', { intent: 'interested' })">Simulate interested prospect reply</button>
      <button v-if="state.message?.status === 'sent' && state.inbound_count === 0" class="quiet" :disabled="busy" @click="post('reply', { intent: 'unsubscribe' })">Simulate unsubscribe</button>
      <button class="quiet" :disabled="busy" @click="refresh">Refresh acceptance status</button>
    </div>
    <small v-if="state.inbound_count > 0">Inbound reply recorded. Follow-up and sales analysis use the configured local queue.</small>
    <small v-else-if="state.conversation_id">Outbound conversation thread created. No inbound reply has been simulated yet.</small>
    <small v-if="state.proposal_delivery_count > 0" class="acceptance-error">Unexpected proposal delivery count: {{ state.proposal_delivery_count }}</small>
    <small v-if="error" class="acceptance-error">{{ error }}</small>
  </aside>
</template>

<style scoped>
.local-acceptance{display:flex;flex-direction:column;gap:6px;margin:0 0 14px;padding:9px 12px;border:1px solid #f0c36a;border-radius:8px;background:#fff9e9;color:#604500;font-size:10px}.acceptance-head,.acceptance-state,.acceptance-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.acceptance-head strong{font-size:10px}.acceptance-head span,.local-acceptance small{color:#79633b}.acceptance-state span{padding:3px 6px;border:1px solid #ecd9a8;border-radius:12px;background:#fffdf7;font-size:9px}.acceptance-actions{margin-top:2px}.acceptance-error{color:#a22b23!important}
</style>
