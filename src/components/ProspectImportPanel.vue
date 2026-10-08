<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { csrfToken, salesRequest } from '../salesApi'

const props = defineProps<{ tenantId: string; manager: boolean }>()
const cohorts = ref<any[]>([])
const cohortId = ref('')
const file = ref<File | null>(null)
const preview = ref<any>(null)
const batch = ref<any>(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const creatingCohort = ref(false)
const cohortName = ref('Sprint 7 internal simulated pilot')
let pollTimer: ReturnType<typeof setInterval> | undefined
const rows = computed(() => preview.value?.rows ?? batch.value?.rows ?? [])

async function loadCohorts() {
  cohorts.value = (await salesRequest('/pilot/cohorts', props.tenantId)).data ?? []
  if (!cohortId.value && cohorts.value.filter((cohort) => cohort.status === 'active').length === 1) cohortId.value = cohorts.value.find((cohort) => cohort.status === 'active')?.id ?? ''
}
async function chooseFile(event: Event) { file.value = (event.target as HTMLInputElement).files?.[0] ?? null; preview.value = null; batch.value = null; error.value = ''; notice.value = '' }
async function previewFile() {
  if (!file.value) { error.value = 'Choose a CSV file first.'; return }
  busy.value = true; error.value = ''; notice.value = ''; batch.value = null
  try {
    const form = new FormData(); form.append('csv', file.value); if (cohortId.value) form.append('cohort_id', cohortId.value)
    const response = await fetch('/api/v1/pilot/import-batches/preview', { method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-Tenant-ID': props.tenantId, 'X-XSRF-TOKEN': csrfToken() }, body: form })
    const result = await response.json(); if (!response.ok) throw new Error(result.message ?? 'Unable to read this CSV.')
    preview.value = result; notice.value = `${result.counts.valid} valid row(s), ${result.counts.invalid} skipped row(s). Review the row results before importing.`
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to preview CSV.' }
  finally { busy.value = false }
}
async function confirmImport() {
  if (!preview.value?.id) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest(`/pilot/import-batches/${encodeURIComponent(preview.value.id)}/confirm`, props.tenantId, 'POST', { idempotency_key: crypto.randomUUID() })
    notice.value = result.message; preview.value = null; await loadBatch(result.batch_id); poll()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to queue this import.' }
  finally { busy.value = false }
}
async function loadBatch(id: string) { batch.value = await salesRequest(`/pilot/import-batches/${encodeURIComponent(id)}`, props.tenantId) }
function poll() {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = setInterval(async () => { if (!batch.value?.batch?.id) return; await loadBatch(batch.value.batch.id); if (!['importing', 'validating'].includes(batch.value.batch.status)) { clearInterval(pollTimer); pollTimer = undefined } }, 1800)
}
async function retryFailed() {
  if (!batch.value?.batch?.id) return
  try { const result = await salesRequest(`/pilot/import-batches/${encodeURIComponent(batch.value.batch.id)}/retry`, props.tenantId, 'POST'); notice.value = `${result.queued_rows} failed row(s) queued for retry.`; await loadBatch(batch.value.batch.id); poll() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to retry failed rows.' }
}
async function downloadErrors() {
  if (!batch.value?.batch?.id) return
  const response = await fetch(`/api/v1/pilot/import-batches/${encodeURIComponent(batch.value.batch.id)}/errors.csv`, { credentials: 'include', headers: { 'X-Tenant-ID': props.tenantId } })
  if (!response.ok) { error.value = `Error report download failed (${response.status}).`; return }
  const url = URL.createObjectURL(await response.blob()); const link = document.createElement('a'); link.href = url; link.download = `prospect-import-errors-${batch.value.batch.id}.csv`; link.click(); URL.revokeObjectURL(url)
}
async function createCohort() {
  creatingCohort.value = true; error.value = ''
  try { const cohort = await salesRequest('/pilot/cohorts', props.tenantId, 'POST', { name: cohortName.value, starts_on: new Date().toISOString().slice(0, 10), status: 'active' }); await loadCohorts(); cohortId.value = cohort.id; notice.value = 'Pilot cohort created.' }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to create cohort.' }
  finally { creatingCohort.value = false }
}
onMounted(() => { void loadCohorts() })
onBeforeUnmount(() => { if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
  <section class="import-panel">
    <div class="import-head"><div><span class="eyebrow">KNOWN-DOMAIN INTAKE</span><h2>Import a prospect list</h2><p>Business name, website, country code and source are required. Website identity stays unverified until independently checked.</p></div><span class="safe-label">No outreach on import</span></div>
    <div class="import-controls"><label class="file-field">CSV file<input type="file" accept=".csv,text/csv" @change="chooseFile" /></label><label>Pilot cohort<select v-model="cohortId"><option value="">No cohort</option><option v-for="cohort in cohorts" :key="cohort.id" :value="cohort.id">{{ cohort.name }}</option></select></label><button v-if="manager" class="quiet" :disabled="creatingCohort" @click="createCohort">{{ creatingCohort ? 'Creating…' : '＋ New cohort' }}</button><button class="primary" :disabled="busy || !file" @click="previewFile">{{ busy ? 'Checking…' : 'Preview CSV' }}</button></div>
    <details class="csv-help"><summary>CSV format and privacy</summary><p>Required columns: <code>business_name, website, country, source</code>. Optional: <code>city, industry, business_email, business_phone, contact_name, notes</code>. Country uses ISO two-letter codes. Maximum 100 data rows and 2 MB. Contact values are encrypted while staged and on the contact record. The source is recorded as user-supplied and is not independent proof.</p></details>
    <p v-if="error" class="import-error" role="alert">{{ error }}</p><p v-if="notice" class="import-notice" role="status">{{ notice }}</p>
    <div v-if="preview" class="import-state"><div class="import-summary"><b>{{ preview.counts.valid }} ready</b><b>{{ preview.counts.invalid }} invalid or duplicate</b><span>Batch {{ preview.id }}</span></div><div class="import-table-wrap"><table><thead><tr><th>Row</th><th>Business</th><th>Website</th><th>Result</th><th>Row notes</th></tr></thead><tbody><tr v-for="row in rows" :key="row.row_number"><td>{{ row.row_number }}</td><td>{{ row.business_name || '—' }}</td><td>{{ row.normalized_domain || row.website || '—' }}</td><td>{{ row.validation_status }} · {{ row.deduplication_status }}</td><td>{{ row.errors?.join(' ') || 'Ready to import' }}</td></tr></tbody></table></div><button class="primary" :disabled="busy || preview.counts.valid === 0" @click="confirmImport">Confirm import of {{ preview.counts.valid }} rows</button></div>
    <div v-if="batch" class="import-state"><div class="import-summary"><b>{{ batch.batch.status.replaceAll('_', ' ') }}</b><span>{{ batch.batch.counts.imported || 0 }} imported</span><span>{{ batch.batch.counts.pending || 0 }} pending</span><span>{{ batch.batch.counts.failed || 0 }} failed</span></div><div class="import-table-wrap"><table><thead><tr><th>Row</th><th>Business</th><th>Domain</th><th>Validation</th><th>Import status</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td>{{ row.row_number }}</td><td>{{ row.original_name }}</td><td>{{ row.normalized_domain || row.original_website }}</td><td>{{ row.validation_status }} · {{ row.deduplication_status }}</td><td>{{ row.status }}<small v-if="row.errors?.length">{{ row.errors.join(' ') }}</small></td></tr></tbody></table></div><div class="import-footer"><button class="quiet" @click="downloadErrors">Download row issues</button><button v-if="batch.batch.counts?.failed" class="quiet" @click="retryFailed">Retry failed rows</button><span>Imported domains remain unverified. No message was sent.</span></div></div>
  </section>
</template>

<style scoped>
.import-panel{display:grid;gap:13px;padding:18px;border:1px solid #e3e8f0;border-radius:13px;background:#fff;min-width:0}.import-head,.import-controls,.import-summary,.import-footer{display:flex;align-items:center;justify-content:space-between;gap:11px;flex-wrap:wrap}.import-head h2{font-size:16px;margin:4px 0;color:#24364f}.import-head p,.csv-help p{font-size:11px;line-height:1.5;color:#718096;max-width:750px;margin:0}.safe-label{padding:7px 9px;border-radius:20px;background:#fff7e5;color:#84671f;font-size:10px;font-weight:700;white-space:nowrap}.import-controls label{display:grid;gap:5px;font-size:10px;color:#68778c}.import-controls select,.import-controls input{max-width:100%;min-height:36px;border:1px solid #dce3ec;border-radius:7px;padding:7px;background:#fff;color:#273a54}.file-field input{min-width:210px}.csv-help{font-size:10px;color:#68778c}.csv-help p{margin-top:8px}.import-error,.import-notice{padding:10px;border-radius:8px;font-size:11px;overflow-wrap:anywhere}.import-error{background:#fff0ee;color:#9c4639}.import-notice{background:#eef5ff;color:#405986}.import-state{display:grid;gap:10px;min-width:0}.import-summary{justify-content:flex-start;font-size:10px;color:#65748a}.import-summary b{color:#2e7856}.import-table-wrap{max-width:100%;overflow:auto;border:1px solid #e6ebf1;border-radius:8px}.import-table-wrap table{width:100%;min-width:760px;border-collapse:collapse;text-align:left;font-size:10px}.import-table-wrap th,.import-table-wrap td{padding:9px;border-bottom:1px solid #edf0f4;vertical-align:top}.import-table-wrap th{background:#f6f8fb;color:#68778b;font-size:9px}.import-table-wrap td{overflow-wrap:anywhere}.import-table-wrap small{display:block;color:#a44d40;margin-top:4px}.import-footer{justify-content:flex-start}.import-footer span{font-size:10px;color:#718096}
</style>
