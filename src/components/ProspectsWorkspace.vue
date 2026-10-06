<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { humanize, salesRequest, unwrap } from '../salesApi'

const props = defineProps<{ tenantId: string }>()
const emit = defineEmits<{ openProspect: [company: any] }>()
const companies = ref<any[]>([])
const scores = ref<any[]>([])
const contacts = ref<any[]>([])
const query = ref('')
const industry = ref('')
const location = ref('')
const contactFilter = ref('any')
const minScore = ref('')
const statusFilter = ref('')
const currentPage = ref(1)
const lastPage = ref(1)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const addOpen = ref(false)
const discoveryOpen = ref(false)
const saving = ref(false)
const draft = ref({ name: '', website: '', industry: '', location: '' })
const seed = ref({ name: '', website: '', industry: '', location: '' })

const scoreByCompany = computed(() => {
  const latest = new Map<string, any>()
  scores.value.forEach((row) => { if (!latest.has(row.company_id)) latest.set(row.company_id, row) })
  return latest
})
const contactsByCompany = computed(() => {
  const map = new Map<string, any[]>()
  contacts.value.forEach((contact) => map.set(contact.company_id, [...(map.get(contact.company_id) ?? []), contact]))
  return map
})
const industries = computed(() => [...new Set(companies.value.map((company) => company.industry).filter(Boolean))].sort())
const locations = computed(() => [...new Set(companies.value.map((company) => company.location).filter(Boolean))].sort())
const statuses = computed(() => [...new Set(companies.value.map((company) => company.status).filter(Boolean))].sort())
const filtered = computed(() => companies.value.filter((company) => {
  const score = scoreByCompany.value.get(company.id)?.score
  const hasContact = (contactsByCompany.value.get(company.id) ?? []).length > 0
  return (!industry.value || company.industry === industry.value)
    && (!location.value || company.location === location.value)
    && (!statusFilter.value || company.status === statusFilter.value)
    && (contactFilter.value === 'any' || (contactFilter.value === 'yes' ? hasContact : !hasContact))
    && (minScore.value === '' || (Number(score ?? -1) >= Number(minScore.value)))
}))

async function load(page = currentPage.value) {
  busy.value = true; error.value = ''
  try {
    const [companyResult, scoreResult, contactResult] = await Promise.all([
      salesRequest(`/companies?search=${encodeURIComponent(query.value)}&page=${page}`, props.tenantId),
      salesRequest('/lead-scores', props.tenantId), salesRequest('/contacts', props.tenantId),
    ])
    companies.value = unwrap(companyResult)
    currentPage.value = companyResult.current_page ?? 1; lastPage.value = companyResult.last_page ?? 1
    scores.value = unwrap(scoreResult)
    contacts.value = unwrap(contactResult)
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load prospects.' }
  finally { busy.value = false }
}

async function saveProspect() {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    await salesRequest('/companies', props.tenantId, 'POST', draft.value)
    draft.value = { name: '', website: '', industry: '', location: '' }; addOpen.value = false
    notice.value = 'Prospect added to your workspace.'; await load()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to add prospect.' }
  finally { saving.value = false }
}

async function addSeed() {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const result = await salesRequest('/discovery-runs', props.tenantId, 'POST', { candidates: [{ ...seed.value, source: 'user_seed' }] })
    notice.value = `Prospect registration queued. External company discovery is not available.`
    seed.value = { name: '', website: '', industry: '', location: '' }; discoveryOpen.value = false
    void result; await load()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to register prospect.' }
  finally { saving.value = false }
}

watch(query, () => { currentPage.value = 1; void load(1) })
watch(() => props.tenantId, () => { void load() })
onMounted(() => { void load() })
</script>

<template>
  <section class="sales-workspace">
    <div class="sales-toolbar"><label class="sales-search"><span>⌕</span><input v-model="query" placeholder="Search prospects" aria-label="Search prospects" /></label><button class="quiet" @click="discoveryOpen = !discoveryOpen">Import / Discover <span class="coming-soon">Limited</span></button><button class="primary" @click="addOpen = !addOpen">＋ Add prospect</button></div>
    <div v-if="discoveryOpen" class="sales-callout"><div><b>Register a prospect from a known company</b><p>Discovery accepts a company you provide. External prospect sourcing is not available yet.</p></div><form class="sales-inline-form" @submit.prevent="addSeed"><input v-model="seed.name" required maxlength="255" placeholder="Company name"/><input v-model="seed.website" required type="url" placeholder="https://company.com"/><input v-model="seed.industry" placeholder="Industry"/><input v-model="seed.location" placeholder="Location"/><button class="primary" :disabled="saving">{{ saving ? 'Queueing…' : 'Register prospect' }}</button></form></div>
    <form v-if="addOpen" class="sales-callout sales-inline-form" @submit.prevent="saveProspect"><input v-model="draft.name" required maxlength="255" placeholder="Company name"/><input v-model="draft.website" type="url" placeholder="https://company.com"/><input v-model="draft.industry" placeholder="Industry"/><input v-model="draft.location" placeholder="Location"/><button class="primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save prospect' }}</button></form>
    <div v-if="notice" class="notice success-notice">{{ notice }}</div><div v-if="error" class="notice">{{ error }} <button @click="() => load()">Retry</button></div>
    <div class="prospect-filterbar"><label>Industry<select v-model="industry"><option value="">All industries</option><option v-for="value in industries" :key="value">{{ value }}</option></select></label><label>Location<select v-model="location"><option value="">All locations</option><option v-for="value in locations" :key="value">{{ value }}</option></select></label><label>Status<select v-model="statusFilter"><option value="">All statuses</option><option v-for="value in statuses" :key="value" :value="value">{{ humanize(value) }}</option></select></label><label>Minimum score<select v-model="minScore"><option value="">Any score</option><option value="40">40+</option><option value="60">60+</option><option value="70">70+</option><option value="85">85+</option></select></label><label>Public contact<select v-model="contactFilter"><option value="any">Any</option><option value="yes">Available</option><option value="no">Unavailable</option></select></label><span class="filter-count">{{ filtered.length }} prospects · page {{ currentPage }} of {{ lastPage }}</span></div>
    <div class="panel sales-table-panel"><div v-if="busy && !companies.length" class="sales-state"><span class="spinner"></span><b>Loading prospects</b><small>Preparing your tenant workspace…</small></div><div v-else-if="!filtered.length" class="sales-state"><span class="state-icon">⌕</span><b>{{ query ? 'No matching prospects' : 'No prospects yet' }}</b><small>{{ query ? 'Try a different search or filter.' : 'Add a prospect or register a known company to begin.' }}</small><button v-if="!query" class="primary" @click="addOpen = true">Add first prospect</button></div><div v-else class="table-wrap"><table class="sales-table"><thead><tr><th>PROSPECT</th><th>STATUS</th><th>LEAD SCORE</th><th>PUBLIC CONTACT</th><th>WEBSITE</th><th>NEXT ACTION</th></tr></thead><tbody><tr v-for="company in filtered" :key="company.id" class="clickable-row" @click="emit('openProspect', company)"><td><b>{{ company.name }}</b><small>{{ company.industry || 'Industry not recorded' }} · {{ company.location || 'Location not recorded' }}</small></td><td><span class="pill">{{ humanize(company.status) }}</span></td><td><span v-if="scoreByCompany.get(company.id)" class="sales-score">{{ scoreByCompany.get(company.id).score }}<small>/100</small></span><span v-else class="muted">Not scored</span></td><td><template v-if="contactsByCompany.get(company.id)?.length">{{ contactsByCompany.get(company.id)?.[0]?.name || contactsByCompany.get(company.id)?.[0]?.title || 'Public contact' }}<small>{{ contactsByCompany.get(company.id)?.[0]?.title || 'Contact details available' }}</small></template><span v-else class="muted">Not identified</span></td><td><span class="health-dot" :class="company.websites?.length ? 'is-known' : ''"></span>{{ company.websites?.length ? 'Added' : 'Not added' }}</td><td><span class="next-action">{{ scoreByCompany.get(company.id) ? 'Review prospect' : 'Assess fit' }} <span>→</span></span></td></tr></tbody></table></div></div>
    <div v-if="lastPage > 1" class="pagination-controls"><button class="quiet" :disabled="currentPage <= 1 || busy" @click="load(currentPage - 1)">← Previous</button><span>Page {{ currentPage }} of {{ lastPage }}</span><button class="quiet" :disabled="currentPage >= lastPage || busy" @click="load(currentPage + 1)">Next →</button></div>
    <p class="data-limit-note">Search is server-side. Industry, location, status, score, and contact filters apply to loaded company and lead/contact pages.</p>
  </section>
</template>

<style scoped>
.sales-workspace{display:grid;gap:16px}.sales-toolbar{display:flex;gap:10px;align-items:center}.sales-search{display:flex;align-items:center;gap:9px;max-width:420px;flex:1;border:1px solid #e2e6ed;background:white;border-radius:10px;padding:0 12px;color:#6a7381}.sales-search input{border:0;outline:0;width:100%;min-height:42px}.sales-callout{display:grid;gap:13px;border:1px solid #dce7fc;background:#f8faff;padding:17px;border-radius:12px}.sales-callout p{color:#697386;margin:5px 0 0}.sales-inline-form{display:flex;flex-wrap:wrap;gap:8px}.sales-inline-form input{min-height:40px;border:1px solid #dfe4eb;border-radius:8px;padding:0 10px;flex:1;min-width:150px}.coming-soon{margin-left:5px;font-size:10px;text-transform:uppercase;color:#697386}.prospect-filterbar{display:flex;gap:12px;align-items:end;flex-wrap:wrap;padding:0 2px}.prospect-filterbar label{display:grid;gap:5px;color:#77808d;font-size:11px;font-weight:650}.prospect-filterbar select{height:36px;min-width:135px;border:1px solid #e1e5ec;border-radius:8px;background:white;padding:0 9px;color:#202938}.filter-count{margin-left:auto;color:#707989;font-size:12px;padding-bottom:9px}.sales-table-panel{min-height:220px;overflow:hidden}.sales-table{min-width:780px}.sales-table td:first-child b{display:block}.sales-table td small{display:block;margin-top:4px}.sales-score{font-size:16px;font-weight:700;color:#263d72}.sales-score small{display:inline!important;color:#8a93a1;font-size:10px}.muted{color:#8b94a1}.clickable-row{cursor:pointer}.clickable-row:hover{background:#f8faff}.health-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;background:#bcc2cc}.health-dot.is-known{background:#35a77b}.next-action{color:#3757a6;font-weight:600;white-space:nowrap}.next-action span{margin-left:8px}.sales-state{min-height:220px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#536070;text-align:center}.sales-state b{color:#202938}.sales-state small{color:#88919e}.state-icon{font-size:25px}.spinner{width:24px;height:24px;border:2px solid #dfe5f0;border-top-color:#425eb6;border-radius:50%;animation:spin .8s linear infinite}.data-limit-note{margin:0;color:#8a93a1;font-size:11px}@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:760px){.sales-toolbar{flex-wrap:wrap}.sales-search{min-width:100%;max-width:none}.prospect-filterbar{align-items:stretch}.prospect-filterbar label{flex:1;min-width:145px}.prospect-filterbar select{width:100%}.filter-count{margin-left:0}.sales-inline-form>*{width:100%}}
</style>

<style scoped>
.pagination-controls{display:flex;align-items:center;justify-content:center;gap:15px;color:#7c8592;font-size:11px}.pagination-controls button:disabled{opacity:.4;cursor:not-allowed}
</style>
