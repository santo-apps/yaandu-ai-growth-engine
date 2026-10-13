<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { salesRequest } from '../salesApi'

const props = defineProps<{ tenantId: string; companyId: string }>()
const workspace = ref<any>(null)
const selected = ref<string[]>([])
const tone = ref<'professional'|'consultative'|'concise'>('consultative')
const loading = ref(false)
const generating = ref(false)
const error = ref('')
const notice = ref('')
const current = computed(() => workspace.value?.drafts?.[0] ?? null)
const eligible = computed(() => workspace.value?.eligible === true)

async function load() {
  loading.value = true; error.value = ''
  try {
    workspace.value = await salesRequest(`/companies/${encodeURIComponent(props.companyId)}/outreach-drafts`, props.tenantId)
    if (selected.value.length === 0 && workspace.value?.drafts?.[0]?.validated_findings?.length) selected.value = workspace.value.drafts[0].validated_findings.map((finding: any) => finding.id)
  }
  catch (cause) { error.value = cause instanceof Error ? cause.message : 'Outreach drafts could not be loaded.' }
  finally { loading.value = false }
}

async function generate(previousId?: string) {
  if (!eligible.value || selected.value.length === 0) return
  generating.value = true; error.value = ''; notice.value = ''
  try {
    const path = previousId ? `/outreach-drafts/${encodeURIComponent(previousId)}/regenerate` : `/companies/${encodeURIComponent(props.companyId)}/outreach-drafts`
    const result = await salesRequest(path, props.tenantId, 'POST', { finding_ids: selected.value, tone: tone.value }, crypto.randomUUID())
    workspace.value.drafts = [result, ...(workspace.value.drafts ?? []).filter((draft: any) => draft.id !== result.id)]
    notice.value = 'Draft saved for your review. Nothing was sent.'
  } catch (cause) { error.value = cause instanceof Error ? cause.message : 'The draft could not be generated.' }
  finally { generating.value = false }
}

async function copy(value: string) {
  try { await navigator.clipboard.writeText(value); notice.value = 'Copied to clipboard.' }
  catch { error.value = 'Clipboard access is unavailable. Select and copy the draft text instead.' }
}

watch(() => [props.tenantId, props.companyId], () => { selected.value = []; void load() })
onMounted(load)
</script>

<template>
  <section class="panel outreach-draft-panel" aria-labelledby="draft-title">
    <header class="draft-header"><div><span class="eyebrow">HUMAN-APPROVED OUTREACH</span><h3 id="draft-title">Outreach draft</h3><p>Choose only the evidence you have reviewed. Drafts are saved for human editing and are never sent here.</p></div><span class="draft-only-badge">Draft only · No sending</span></header>
    <p v-if="loading" class="draft-empty" role="status">Loading reviewed findings and saved drafts…</p>
    <template v-else>
      <div v-if="!eligible" class="draft-gate" role="status"><b>Outreach drafting is not available yet.</b><span>Outreach drafting becomes available after the prospect is marked Pursue and an active service is selected in Human Sales Review.</span></div>
      <template v-else>
        <div class="draft-context"><span><small>SALES DECISION</small><b>Pursue</b></span><span><small>SELECTED SERVICE</small><b>{{ workspace?.review?.service_name || 'Not selected' }}</b></span></div>
        <fieldset class="finding-picker" :disabled="generating"><legend>Validated findings for outreach</legend><p>Nothing is selected automatically. Select the statements you have checked against their evidence.</p>
          <label v-for="finding in workspace?.findings ?? []" :key="finding.id" class="finding-option"><input v-model="selected" type="checkbox" :value="finding.id"><span><b>{{ finding.label }}</b><small>{{ finding.summary }}</small><small class="finding-source">{{ finding.source }} · {{ Math.round(Number(finding.confidence) * 100) }}% confidence</small></span></label>
          <div v-if="!workspace?.findings?.length" class="draft-empty">No validated opportunity findings are available to reference.</div>
        </fieldset>
        <div class="draft-controls"><label>Writing style<select v-model="tone"><option value="professional">Professional</option><option value="consultative">Consultative</option><option value="concise">Concise</option></select></label><button class="primary" type="button" :disabled="generating || selected.length === 0" @click="generate()">{{ generating ? 'Generating draft…' : 'Generate outreach draft' }}</button></div>
      </template>
      <p v-if="error" class="draft-message error" role="alert">{{ error }}</p><p v-if="notice" class="draft-message success" role="status">{{ notice }}</p>
      <div v-if="current" class="draft-results">
        <div class="draft-summary"><div><small>PITCH SUMMARY</small><p>{{ current.draft_payload.pitch_summary }}</p></div><div><small>RECOMMENDED CHANNEL</small><b>{{ current.draft_payload.recommended_channel }}</b><p>{{ current.draft_payload.recommended_channel_reason }}</p></div></div>
        <article class="draft-output"><header><h4>Email</h4><button class="quiet" type="button" @click="copy(`${current.draft_payload.email.subject}\n\n${current.draft_payload.email.body}`)">Copy email</button></header><b>{{ current.draft_payload.email.subject }}</b><p>{{ current.draft_payload.email.body }}</p></article>
        <article v-for="item in [{key:'whatsapp',label:'WhatsApp'},{key:'linkedin',label:'LinkedIn / social'},{key:'follow_up_email',label:'Follow-up email'},{key:'follow_up_short',label:'Follow-up short message'}]" :key="item.key" class="draft-output"><header><h4>{{ item.label }}</h4><button class="quiet" type="button" @click="copy(current.draft_payload[item.key])">Copy</button></header><p>{{ current.draft_payload[item.key] || 'No draft provided.' }}</p></article>
        <div class="draft-evidence"><h4>Evidence used</h4><ul><li v-for="item in current.draft_payload.evidence_used" :key="item.finding_id">{{ item.summary }}</li></ul><p v-if="!current.draft_payload.evidence_used?.length">No evidence references were included in the copy.</p></div>
        <footer class="draft-footer"><small>Draft {{ current.version }} · {{ current.provider }} / {{ current.model }} · Prompt v{{ current.prompt_version }}</small><button class="secondary" type="button" :disabled="generating || !eligible || selected.length === 0" @click="generate(current.id)">{{ generating ? 'Regenerating…' : 'Regenerate' }}</button></footer>
      </div>
      <div v-else-if="eligible && !loading" class="draft-empty">No outreach draft yet. Select reviewed findings above to create a grounded draft.</div>
    </template>
  </section>
</template>

<style scoped>
.outreach-draft-panel{display:grid;gap:18px;padding:22px}.draft-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.draft-header h3{margin:5px 0;font-size:20px}.draft-header p,.draft-empty,.draft-gate span{color:var(--muted);line-height:1.55}.draft-header p{margin:0;max-width:680px}.draft-only-badge{white-space:nowrap;padding:7px 10px;border-radius:999px;background:#eef4ff;color:#315b9c;font-size:12px;font-weight:650}.draft-gate{display:grid;gap:5px;padding:14px 16px;border:1px solid #eadbb9;border-radius:12px;background:#fffaf0}.draft-context{display:flex;flex-wrap:wrap;gap:24px;padding:12px 15px;border-radius:12px;background:#f7f9fc}.draft-context span{display:grid;gap:4px}.draft-context small,.draft-summary small{font-size:10px;letter-spacing:.06em;color:var(--muted)}.finding-picker{border:0;padding:0;margin:0;display:grid;gap:10px}.finding-picker legend{font-weight:700;font-size:15px}.finding-picker>p{margin:0;color:var(--muted);font-size:13px}.finding-option{display:flex;gap:12px;padding:12px;border:1px solid var(--line);border-radius:10px;cursor:pointer}.finding-option input{margin-top:3px;accent-color:var(--primary)}.finding-option span{display:grid;gap:4px}.finding-option small{color:var(--muted);line-height:1.4}.finding-source{font-size:11px}.draft-controls{display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap}.draft-controls label{display:grid;gap:5px;color:var(--muted);font-size:12px}.draft-controls select{min-width:190px}.draft-message{margin:0}.draft-message.error{color:#9f3636}.draft-message.success{color:#28724f}.draft-results{display:grid;gap:12px;padding-top:16px;border-top:1px solid var(--line)}.draft-summary{display:grid;grid-template-columns:2fr 1fr;gap:16px;padding:15px;border-radius:12px;background:#f6f8fc}.draft-summary p{margin:6px 0 0;white-space:pre-wrap;line-height:1.55}.draft-output{padding:15px;border:1px solid var(--line);border-radius:12px}.draft-output header,.draft-footer{display:flex;justify-content:space-between;align-items:center;gap:12px}.draft-output h4,.draft-evidence h4{margin:0 0 9px}.draft-output p{white-space:pre-wrap;line-height:1.6;margin:8px 0 0}.draft-evidence{padding:15px;background:#f7f9fc;border-radius:12px}.draft-evidence ul{margin:0;padding-left:20px}.draft-evidence p{color:var(--muted)}.draft-footer{flex-wrap:wrap}.draft-footer small{color:var(--muted)}
@media(max-width:640px){.outreach-draft-panel{padding:16px}.draft-header,.draft-summary{grid-template-columns:1fr;display:grid}.draft-only-badge{justify-self:start}.draft-controls .primary{width:100%}}
</style>
