<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { salesRequest } from '../salesApi'

const props = defineProps<{ tenantId: string }>()
type Run = { id: string; source: string; status: string; processed: number; inserted: number; updated: number; unchanged: number; failed: number; duplicates: number; unique_domains_added: number; bytes_processed: number; stored_bytes_delta: number; started_at: string; finished_at: string | null; checkpointed_at: string | null; failure_summary: string | null; metrics: Record<string, unknown> }
const overview = ref<any>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const source = ref('osm_public_websites')
const location = ref('')
const category = ref('business')
const limit = ref(50)
const maxBytes = ref(10000000)

async function load() {
  loading.value = true; error.value = ''
  try { overview.value = await salesRequest('/operations/web-index', props.tenantId) }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unable to load corpus operations.' }
  finally { loading.value = false }
}

async function startIngestion() {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const body: Record<string, unknown> = { source: source.value, limit: limit.value, max_bytes: maxBytes.value }
    if (source.value === 'osm_public_websites') { body.location = location.value; body.categories = [category.value] }
    const result = await salesRequest('/operations/web-index/runs', props.tenantId, 'POST', body)
    notice.value = `Ingestion queued. Run ${result.run_id}`
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Unable to start corpus ingestion.' }
  finally { saving.value = false }
}

function formatBytes(value: unknown): string {
  const bytes = Number(value ?? 0)
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function label(value: string): string { return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) }
onMounted(load)
</script>

<template>
  <section class="corpus-ops">
    <div v-if="error" class="notice">{{ error }} <button class="quiet" @click="load">Retry</button></div>
    <div v-if="notice" class="notice success-notice">{{ notice }}</div>
    <div v-if="loading && !overview" class="panel empty-table">Loading corpus status…</div>
    <template v-else-if="overview">
      <section class="corpus-metrics">
        <article><small>INDEXED DOMAINS</small><b>{{ overview.corpus.domains.toLocaleString() }}</b><span>{{ overview.quality.quality_domains.toLocaleString() }} quality business domains · {{ overview.corpus.documents.toLocaleString() }} docs</span></article>
        <article><small>FRESH DOCUMENTS</small><b>{{ overview.corpus.fresh.toLocaleString() }}</b><span>Stale or unavailable: {{ overview.corpus.stale.toLocaleString() }}</span></article>
        <article><small>ACTIVE RUNS</small><b>{{ overview.corpus.active_runs }}</b><span>{{ overview.corpus.last_ingestion ? `Last ingestion ${new Date(overview.corpus.last_ingestion).toLocaleString()}` : 'No ingestion run yet' }}</span></article>
        <article><small>STORED METADATA</small><b>{{ formatBytes(overview.corpus.stored_bytes) }}</b><span>Bounded identity fields and excerpts</span></article>
      </section>

      <section class="panel corpus-ingest-panel">
        <div class="panel-heading"><div><h2>Grow public business coverage</h2><p>Choose a bounded source and region. Public web evidence is shared across workspaces; prospect and sales decisions remain tenant-scoped.</p></div><button class="quiet" @click="load">↻ Refresh</button></div>
        <form class="corpus-run-form" @submit.prevent="startIngestion">
          <label>Source<select v-model="source"><option value="osm_public_websites">OpenStreetMap websites</option><option value="verified_discovery">Verified public discovery</option><option value="wikidata_linked_websites">Linked Wikidata websites</option><option value="common_crawl_known_domains">Common Crawl known domains</option></select></label>
          <label v-if="source === 'osm_public_websites'">Region<select v-model="location" required><option value="" disabled>Choose region</option><option v-for="item in overview.plans.locations" :key="item.name" :value="item.name">{{ item.name }} · {{ item.country }}</option></select></label>
          <label v-if="source === 'osm_public_websites'">Category<select v-model="category"><option v-for="item in overview.plans.categories" :key="item" :value="item">{{ label(item) }}</option></select></label>
          <label>Maximum documents/domains<input v-model.number="limit" type="number" min="1" max="500" required /></label>
          <label>Maximum fetched/capture bytes<input v-model.number="maxBytes" type="number" min="1024" max="250000000" step="1024" required /></label>
          <button class="primary" :disabled="saving || (source === 'osm_public_websites' && !location)">{{ saving ? 'Queueing…' : 'Queue bounded ingestion' }}</button>
        </form>
        <p class="corpus-policy-note">No broad crawls or paid lead/search APIs. Ingestion follows robots, host politeness, public URL safety, and explicit run budgets. It never creates outreach or sales records.</p>
      </section>

      <section class="panel corpus-source-panel">
        <div class="panel-heading"><div><h2>Source yield</h2><p>Observations are provenance links; a document or domain can have multiple sources.</p></div></div>
        <div v-if="overview.sources.length" class="corpus-source-grid"><article v-for="item in overview.sources" :key="item.source"><b>{{ label(item.source) }}</b><span>{{ item.domains }} domains · {{ item.documents }} docs · {{ item.observations }} observations</span><small>Quality {{ item.quality_score }}/100 · index success {{ item.successful_index_percent }}% · unique-domain yield {{ item.unique_domain_yield_percent }}%</small><small>{{ item.contact_documents }} docs with contacts · {{ item.structured_documents }} with structured data · {{ item.failures || 0 }} failures · {{ item.duplicates || 0 }} duplicates</small></article></div>
        <div v-else class="empty-table">No source observations recorded yet.</div>
      </section>

      <section class="corpus-distribution-grid">
        <article class="panel"><h2>Evidence quality</h2><dl><dt>High-quality business domains</dt><dd>{{ overview.quality.quality_domains }}</dd><dt>Partial business evidence</dt><dd>{{ overview.quality.classification.PARTIAL_BUSINESS_EVIDENCE }}</dd><dt>Brand-only</dt><dd>{{ overview.quality.classification.BRAND_ONLY }}</dd><dt>Directory / academic / unavailable</dt><dd>{{ overview.quality.classification.DIRECTORY + overview.quality.classification.ACADEMIC_SUBUNIT + overview.quality.classification.SUSPENDED + overview.quality.classification.ERROR_PAGE + overview.quality.classification.OTHER_LOW_VALUE }}</dd><dt>Domains with business name</dt><dd>{{ overview.quality.domains_with_name }}</dd><dt>Domains with location</dt><dd>{{ overview.quality.domains_with_location }}</dd><dt>Domains with public contact evidence</dt><dd>{{ overview.quality.domains_with_public_contact }}</dd><dt>Domains with structured data</dt><dd>{{ overview.quality.domains_with_structured_data }}</dd><dt>Average identity completeness</dt><dd>{{ overview.quality.average_identity_completeness }}%</dd></dl></article>
        <article class="panel"><h2>Coverage distribution</h2><div class="distribution-columns"><div><b>Countries</b><span v-for="item in overview.distribution.countries" :key="item.label">{{ item.label }} <small>{{ item.domains }}</small></span></div><div><b>Cities</b><span v-for="item in overview.distribution.cities" :key="item.label">{{ item.label }} <small>{{ item.domains }}</small></span></div><div><b>Categories</b><span v-for="item in overview.distribution.categories" :key="item.label">{{ label(item.label) }} <small>{{ item.domains }}</small></span></div></div></article>
      </section>

      <section class="panel corpus-runs-panel">
        <div class="panel-heading"><div><h2>Recent ingestion runs</h2><p>Progress and safe failure summaries for corpus operations.</p></div></div>
        <div v-if="overview.runs.length" class="corpus-runs-table"><table><thead><tr><th>SOURCE / STARTED</th><th>STATUS</th><th>PROGRESS</th><th>NEW DOMAINS</th><th>STORAGE</th><th>CHECKPOINT / ISSUE</th></tr></thead><tbody><tr v-for="run in overview.runs as Run[]" :key="run.id"><td><b>{{ label(run.source) }}</b><small>{{ new Date(run.started_at).toLocaleString() }}</small></td><td><span class="pill" :class="run.status">{{ label(run.status) }}</span></td><td>{{ run.processed }} records · {{ run.inserted }} new docs · {{ run.duplicates }} duplicates · {{ run.failed }} failed</td><td>{{ run.unique_domains_added }}</td><td>{{ formatBytes(run.stored_bytes_delta) }}</td><td><small>{{ run.checkpointed_at ? `Checkpoint ${new Date(run.checkpointed_at).toLocaleString()}` : 'No checkpoint yet' }}</small><small v-if="run.failure_summary" class="corpus-run-error">{{ run.failure_summary }}</small></td></tr></tbody></table></div>
        <div v-else class="empty-table">No ingestion runs yet. Choose a bounded source above to start corpus growth.</div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.corpus-ops{display:grid;gap:14px;min-width:0}.corpus-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.corpus-metrics article,.corpus-source-grid article{min-width:0;padding:15px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;display:grid;gap:6px}.corpus-metrics small{font-size:9px;letter-spacing:.08em;color:#78869a;font-weight:750}.corpus-metrics b{font-size:25px;color:#263b5b}.corpus-metrics span,.corpus-source-grid span,.corpus-source-grid small{font-size:10px;line-height:1.4;color:#728096;overflow-wrap:anywhere}.corpus-ops .panel{min-width:0;padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}.panel-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.panel-heading h2{margin:0 0 5px;font-size:15px;color:#263b5b}.panel-heading p{margin:0;color:#768398;font-size:11px;line-height:1.5;max-width:740px}.corpus-run-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:16px}.corpus-run-form label{display:grid;gap:5px;font-size:10px;color:#56647a;font-weight:650}.corpus-run-form input,.corpus-run-form select{min-width:0;width:100%;height:38px;padding:0 9px;border:1px solid #dfe5ed;border-radius:7px;background:#fff;color:#26364e}.corpus-run-form .primary{align-self:end;height:38px}.corpus-policy-note{margin:12px 0 0;color:#758196;font-size:10px;line-height:1.5}.corpus-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:12px}.corpus-source-grid b{font-size:11px;color:#33445d}.corpus-runs-table{max-width:100%;overflow:auto;margin-top:12px;border:1px solid #e7ebf0;border-radius:8px}.corpus-runs-table table{width:100%;min-width:850px;border-collapse:collapse;text-align:left;font-size:10px}.corpus-runs-table th,.corpus-runs-table td{padding:10px;border-bottom:1px solid #edf0f4;vertical-align:top}.corpus-runs-table th{background:#f7f9fb;color:#78869a;font-size:9px;white-space:nowrap}.corpus-runs-table td small{display:block;margin-top:4px;color:#79879a}.corpus-run-error{color:#aa4b42!important;max-width:280px}.pill.running,.pill.pending{background:#edf3ff;color:#3159a5}.pill.failed{background:#fff0ed;color:#a84d3f}.pill.partially_completed{background:#fff7e8;color:#8a641f}.corpus-ops .empty-table{padding:26px 12px;text-align:center;color:#778497;font-size:11px}
.corpus-distribution-grid{display:grid;grid-template-columns:minmax(250px,.75fr) minmax(0,1.25fr);gap:14px}.corpus-distribution-grid .panel{min-width:0;padding:15px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}.corpus-distribution-grid h2{margin:0 0 12px;font-size:14px;color:#263b5b}.corpus-distribution-grid dl{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;margin:0;font-size:10px}.corpus-distribution-grid dt{color:#718096}.corpus-distribution-grid dd{margin:0;color:#33445d;font-weight:700}.distribution-columns{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.distribution-columns>div{display:grid;align-content:start;gap:7px;min-width:0}.distribution-columns b{font-size:10px;color:#56647a}.distribution-columns span{display:flex;justify-content:space-between;gap:6px;color:#66758b;font-size:9px;overflow-wrap:anywhere}.distribution-columns small{flex:none;color:#33445d;font-weight:700}
@media(max-width:900px){.corpus-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:800px){.corpus-distribution-grid{grid-template-columns:1fr}}
@media(max-width:600px){.corpus-metrics{grid-template-columns:1fr 1fr;gap:7px}.corpus-metrics article{padding:11px}.corpus-metrics b{font-size:20px}.corpus-run-form{grid-template-columns:1fr}.corpus-run-form .primary{width:100%}.corpus-ops .panel{padding:12px}.panel-heading{align-items:flex-start}.panel-heading p{font-size:10px}.distribution-columns{grid-template-columns:1fr 1fr}.distribution-columns>div:last-child{grid-column:1/-1}}
</style>
