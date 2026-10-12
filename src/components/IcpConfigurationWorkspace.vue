<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

type IcpVersion = { id: string; version: number; status: string; configuration: string | Record<string, any>; activated_at?: string | null }
type ConfigurationResponse = { active: IcpVersion | null; versions: IcpVersion[]; available_service_keys: string[]; available_service_capabilities: Array<{ key: string; label: string }> }
const tenantId = localStorage.getItem('tenant_id') ?? ''
const state = ref<ConfigurationResponse>({ active: null, versions: [], available_service_keys: [], available_service_capabilities: [] })
const busy = ref(false)
const error = ref('')
const notice = ref('')
const latestDraftId = computed(() => state.value.versions.find((item) => item.status === 'draft')?.id ?? '')
const form = ref({ countries: '', locations: '', industries: '', businessTypes: '', scaleBands: '', viability: '', digitalEvidence: '', serviceKeys: [] as string[], contactEvidence: '' })

function xsrfToken() { return decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '') }
function values(input: string) { return input.split(',').map((part) => part.trim()).filter(Boolean) }
function configuration() {
  return {
    geography: { target_countries: values(form.value.countries), target_locations: values(form.value.locations) },
    organization_suitability: { target_industries: values(form.value.industries), business_types: values(form.value.businessTypes), scale_bands: values(form.value.scaleBands), commercial_viability_criteria: values(form.value.viability) },
    digital_opportunity: { evidence_types: values(form.value.digitalEvidence) },
    service_fit: { service_keys: form.value.serviceKeys },
    commercial_contact_readiness: { evidence_requirements: values(form.value.contactEvidence) },
  }
}
async function request(path: string, method = 'GET', body?: unknown) {
  const response = await fetch(`/api/v1${path}`, { method, credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Tenant-ID': tenantId, 'X-XSRF-TOKEN': xsrfToken() }, body: body ? JSON.stringify(body) : undefined })
  const result = await response.json()
  if (!response.ok) throw new Error(result.message ?? `Request failed (${response.status}).`)
  return result
}
async function load() {
  try {
    state.value = await request('/icp-configurations')
    const editableVersion = state.value.versions.find((item) => item.status === 'draft') ?? state.value.active
    if (editableVersion) {
      const config = typeof editableVersion.configuration === 'string' ? JSON.parse(editableVersion.configuration) : editableVersion.configuration
      form.value = { countries: (config.geography?.target_countries ?? []).join(', '), locations: (config.geography?.target_locations ?? []).join(', '),
        industries: (config.organization_suitability?.target_industries ?? []).join(', '), businessTypes: (config.organization_suitability?.business_types ?? []).join(', '),
        scaleBands: (config.organization_suitability?.scale_bands ?? []).join(', '), viability: (config.organization_suitability?.commercial_viability_criteria ?? []).join(', '),
        digitalEvidence: (config.digital_opportunity?.evidence_types ?? []).join(', '), serviceKeys: config.service_fit?.service_keys ?? [],
        contactEvidence: (config.commercial_contact_readiness?.evidence_requirements ?? []).join(', ') }
    }
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load ICP configurations.' }
}
async function saveDraft() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    if (latestDraftId.value) await request(`/icp-configurations/${latestDraftId.value}`, 'PUT', configuration())
    else await request('/icp-configurations', 'POST', configuration())
    notice.value = 'Versioned ICP draft saved. It is not active until explicitly activated.'
    await load()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save ICP draft.' }
  finally { busy.value = false }
}
async function activateDraft() {
  if (!latestDraftId.value) return
  busy.value = true; error.value = ''; notice.value = ''
  try { await request(`/icp-configurations/${latestDraftId.value}/activate`, 'POST'); notice.value = 'ICP version activated.'; await load() }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to activate ICP.' }
  finally { busy.value = false }
}
async function deactivateActive() {
  if (!state.value.active) return
  busy.value = true; error.value = ''; notice.value = ''
  try { await request(`/icp-configurations/${state.value.active.id}/deactivate`, 'POST'); notice.value = 'Active ICP version deactivated.'; await load() }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to deactivate ICP.' }
  finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <section class="panel companies-panel config-panel icp-versioned">
    <div class="panel-heading"><div><h2>Versioned pilot ICP</h2><p>Configure explicit targeting and evidence policies. Empty fields remain unconfigured; cohort membership is never used as a default.</p></div><span class="pill">{{ state.active ? `Active v${state.active.version}` : 'No active ICP' }}</span></div>
    <p v-if="error" class="icp-message error" role="alert">{{ error }}</p><p v-if="notice" class="icp-message" role="status">{{ notice }}</p>
    <p class="icp-warning">Only active, owner-approved tenant services can be selected for service fit. Current eligible services: {{ state.available_service_keys.length }}.</p>
    <form class="config-form" @submit.prevent="saveDraft">
      <h3>Geography</h3><label>Target countries<input v-model="form.countries" placeholder="Explicit countries"/><small>Required before activation. No inferred markets.</small></label><label>Target regions or locations<input v-model="form.locations" placeholder="Explicit regions or cities"/></label>
      <h3>Organization suitability</h3><label>Target industries<input v-model="form.industries" placeholder="Explicit industries"/><small>Required before activation. Industry alone does not imply opportunity.</small></label><label>Business types<input v-model="form.businessTypes" placeholder="B2B, manufacturer, service provider"/></label><label>Scale bands<input v-model="form.scaleBands" placeholder="Only if explicitly chosen"/></label><label>Commercial viability criteria<input v-model="form.viability" placeholder="Owner-defined criteria"/></label>
      <h3>Evidence dimensions</h3><label>Digital opportunity evidence types<input v-model="form.digitalEvidence" placeholder="website_modernization_need, lead_capture_cro"/><small>Evidence categories include website modernization, e-commerce, lead capture/CRO, WhatsApp engagement, ERP/workflow, AI/process automation, SEO/content, mobile/custom software, and cloud/DevOps. Findings still require cited crawl evidence.</small></label><fieldset class="service-fit-options"><legend>Service-fit capabilities</legend><p v-if="!state.available_service_capabilities.length">No active approved service is mapped to a Website Intelligence capability.</p><label v-for="capability in state.available_service_capabilities" :key="capability.key" class="service-fit-option"><input v-model="form.serviceKeys" type="checkbox" :value="capability.key"/><span>{{ capability.label }} <small>{{ capability.key }}</small></span></label><small>Only capabilities with at least one active, approved mapped commercial service are eligible. ICP stores canonical capability keys, not SKUs.</small></fieldset><label>Commercial/contact evidence requirements<input v-model="form.contactEvidence" placeholder="public_company_contact_channel, contact_enquiry_form"/><small>Public company channel, enquiry form, public business email/phone, named public business contact, explicit sales mechanism, and recorded source/time are supported. Missing evidence remains unknown.</small></label>
      <div class="icp-actions"><button class="quiet" :disabled="busy">{{ busy ? 'Saving…' : latestDraftId ? 'Save draft' : 'Create versioned draft' }}</button><button class="primary" type="button" :disabled="busy || !latestDraftId" @click="activateDraft">Activate draft</button><button v-if="state.active" class="quiet" type="button" :disabled="busy" @click="deactivateActive">Deactivate current ICP</button></div>
    </form>
    <p v-if="state.versions.length" class="icp-versions">Versions: <span v-for="item in state.versions" :key="item.id">v{{ item.version }} · {{ item.status }} &nbsp;</span></p>
  </section>
</template>

<style scoped>
.icp-versioned { margin-bottom: 18px; }
.icp-versioned h3 { margin: 10px 0 0; color: #34445f; }
.icp-warning { padding: 10px 12px; border-radius: 10px; background: #fff7e6; color: #7a5715; }
.icp-message { color: #146c43; }.icp-message.error { color: #a32626; }
.icp-actions { display: flex; gap: 10px; flex-wrap: wrap; }.icp-versions { color: #667085; font-size: 13px; }
</style>
