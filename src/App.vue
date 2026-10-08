<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import CampaignWorkspace from './components/CampaignWorkspace.vue'
import ConversationWorkspace from './components/ConversationWorkspace.vue'
import ProposalWorkspace from './components/ProposalWorkspace.vue'
import MarketingWorkspace from './components/MarketingWorkspace.vue'
import OrchestrationWorkspace from './components/OrchestrationWorkspace.vue'
import HomeWorkspace from './components/HomeWorkspace.vue'
import ProspectsWorkspace from './components/ProspectsWorkspace.vue'
import Prospect360 from './components/Prospect360.vue'
import OutreachWorkspace from './components/OutreachWorkspace.vue'
import PipelineWorkspace from './components/PipelineWorkspace.vue'
import MeetingsWorkspace from './components/MeetingsWorkspace.vue'
import SetupWorkspace from './components/SetupWorkspace.vue'
import LocalAcceptancePanel from './components/LocalAcceptancePanel.vue'
import CorpusOperationsWorkspace from './components/CorpusOperationsWorkspace.vue'
import PilotOperationsDashboard from './components/PilotOperationsDashboard.vue'

type Page = 'Home' | 'Prospects' | 'Prospect 360' | 'Outreach' | 'Inbox' | 'Pipeline' | 'Meetings' | 'Dashboard' | 'Companies' | 'Campaigns' | 'Marketing Intelligence' | 'Conversation Inbox' | 'Proposals' | 'Workflows' | 'Approval Center' | 'Automation' | 'Company Details' | 'Website Intelligence' | 'Contacts' | 'Lead Scores' | 'ICP Configuration' | 'Agent Runs' | 'Corpus Operations' | 'Pilot Operations' | 'AI Configuration' | 'Setup' | 'Services & Pricing'
const pages: { name: Page; icon: string; group: string }[] = [
  { name: 'Home', icon: '⌂', group: 'SALES' }, { name: 'Prospects', icon: '▦', group: 'SALES' }, { name: 'Outreach', icon: '✉', group: 'SALES' }, { name: 'Inbox', icon: '◌', group: 'SALES' }, { name: 'Pipeline', icon: '↗', group: 'SALES' }, { name: 'Meetings', icon: '◷', group: 'SALES' }, { name: 'Proposals', icon: '▤', group: 'SALES' },
  { name: 'Approval Center', icon: '✓', group: 'MANAGEMENT' }, { name: 'Automation', icon: '⚙', group: 'MANAGEMENT' },
  { name: 'Workflows', icon: '⇢', group: 'AUTOMATION' }, { name: 'Approval Center', icon: '✓', group: 'AUTOMATION' }, { name: 'Automation', icon: '⚙', group: 'AUTOMATION' },
  { name: 'Company Details', icon: '⌂', group: 'WORKSPACE' }, { name: 'Website Intelligence', icon: '◎', group: 'INTELLIGENCE' },
  { name: 'Contacts', icon: '♙', group: 'INTELLIGENCE' }, { name: 'Lead Scores', icon: '↗', group: 'INTELLIGENCE' },
  { name: 'Setup', icon: '⚙', group: 'SETUP' }, { name: 'Services & Pricing', icon: '▤', group: 'SETUP' }, { name: 'ICP Configuration', icon: '☷', group: 'SETUP' }, { name: 'AI Configuration', icon: '✳', group: 'SETUP' }, { name: 'Agent Runs', icon: '◷', group: 'OPERATIONS' }, { name: 'Pilot Operations', icon: '▤', group: 'OPERATIONS' }, { name: 'Corpus Operations', icon: '▤', group: 'OPERATIONS' },
]
const salesPages: { name: Page; icon: string }[] = [
  { name: 'Home', icon: '⌂' }, { name: 'Prospects', icon: '▦' }, { name: 'Outreach', icon: '✉' }, { name: 'Inbox', icon: '◌' }, { name: 'Pipeline', icon: '↗' }, { name: 'Meetings', icon: '◷' }, { name: 'Proposals', icon: '▤' },
]
const active = ref<Page>('Home')
const tenant = ref(localStorage.getItem('tenant_id') ?? '')
const tenantOptions = ref<{ id: string; name: string; role: string }[]>([])
const currentUser = ref<{ id: number; name: string; email: string } | null>(null)
const email = ref('')
const password = ref('')
const signedIn = ref(false)
const loginError = ref('')
const showAdd = ref(false)
const showDiscovery = ref(false)
const discoveryBusy = ref(false)
const discoveryNotice = ref('')
const companyDraft = ref({ name: '', website: '', industry: '', location: '' })
const discoveryDraft = ref({ name: '', website: '', industry: '', location: '' })
const companies = ref<any[]>([])
const runs = ref<any[]>([])
const dashboardSummary = ref({ companies: 0, completed_scans: 0, qualified_leads: 0, active_runs: 0, meetings_requested: 0, meetings_booked: 0, upcoming_meetings: 0 })
const contacts = ref<any[]>([])
const scores = ref<any[]>([])
const scoringConfiguration = ref<any>({ version: 1, rules: {}, icp: { industries: [], locations: [], keywords: [] } })
const icpDraft = ref({ industries: '', locations: '', keywords: '' })
const aiConfigurations = ref<any[]>([])
const savingConfiguration = ref(false)
const selectedCompany = ref<any | null>(null)
const focusedOpportunityId = ref('')
const focusedProposalId = ref('')
const outreachKnowledgeOnly = ref(false)
const focusedConversationId = ref('')
const intelligence = ref<any | null>(null)
const screenshotUrls = ref<{ id: string; url: string }[]>([])
const error = ref('')
const loading = ref(false)
const currentPage = computed(() => pages.find((page) => page.name === active.value) ?? { name: active.value, icon: '◫', group: 'SALES' })
const selectedTenant = computed(() => tenantOptions.value.find((item) => item.id === tenant.value))
const isManager = computed(() => ['owner', 'admin'].includes(selectedTenant.value?.role ?? ''))

function xsrfToken() {
  return decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
}

async function api(path: string) {
  const response = await fetch(`/api/v1${path}`, { credentials: 'include', headers: tenant.value ? { 'X-Tenant-ID': tenant.value } : {} })
  if (!response.ok) throw new Error(response.status === 401 ? 'Sign in to your Yaandu workspace to load live data.' : `Request failed (${response.status}).`)
  return response.json()
}

async function load() {
  if (!signedIn.value) return
  if (!tenant.value) { error.value = 'Choose a tenant linked to your account.'; return }
  localStorage.setItem('tenant_id', tenant.value)
  loading.value = true; error.value = ''
  try {
    const [companyData, runData, summaryData, automationData] = await Promise.all([api('/companies'), api('/agent-runs'), api('/dashboard/summary'), api('/automation/summary')])
    companies.value = companyData.data ?? []; runs.value = runData.data ?? []; dashboardSummary.value = { ...summaryData, ...automationData }
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load workspace data.' }
  finally { loading.value = false }
}

async function signIn() {
  loginError.value = ''
  try {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    const response = await fetch('/api/v1/login', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() }, body: JSON.stringify({ email: email.value, password: password.value }) })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? 'Unable to sign in.')
    signedIn.value = true
    currentUser.value = result.user ?? null
    tenantOptions.value = result.tenants ?? []
    if (!tenantOptions.value.some((item) => item.id === tenant.value)) tenant.value = tenantOptions.value.length === 1 ? tenantOptions.value[0].id : ''
    await load()
  } catch (exception) { loginError.value = exception instanceof Error ? exception.message : 'Unable to sign in.' }
}

async function restoreSession() {
  if (!tenant.value) return
  try {
    const [user, tenants] = await Promise.all([api('/me'), api('/tenants')])
    currentUser.value = user
    tenantOptions.value = tenants
    if (!tenantOptions.value.some((item) => item.id === tenant.value)) {
      tenant.value = tenantOptions.value.length === 1 ? tenantOptions.value[0].id : ''
    }
    signedIn.value = Boolean(tenant.value)
    if (signedIn.value) await load()
  } catch {
    signedIn.value = false
  }
}

async function loadContacts() {
  try { contacts.value = (await api('/contacts')).data ?? [] }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load contacts.' }
}

async function loadScores() {
  try { scores.value = (await api('/lead-scores')).data ?? [] }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load lead scores.' }
}

async function loadScoringConfiguration() {
  try {
    scoringConfiguration.value = await api('/scoring-rules')
    icpDraft.value = {
      industries: (scoringConfiguration.value.icp?.industries ?? []).join(', '),
      locations: (scoringConfiguration.value.icp?.locations ?? []).join(', '),
      keywords: (scoringConfiguration.value.icp?.keywords ?? []).join(', '),
    }
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load ICP configuration.' }
}

async function saveScoringConfiguration() {
  savingConfiguration.value = true
  error.value = ''
  try {
    const response = await fetch('/api/v1/scoring-rules', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Tenant-ID': tenant.value },
      body: JSON.stringify({ rules: scoringConfiguration.value.rules, icp: {
        industries: icpDraft.value.industries.split(',').map((item) => item.trim()).filter(Boolean),
        locations: icpDraft.value.locations.split(',').map((item) => item.trim()).filter(Boolean),
        keywords: icpDraft.value.keywords.split(',').map((item) => item.trim()).filter(Boolean),
      } }),
    })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? 'Unable to save ICP configuration.')
    scoringConfiguration.value = result
    discoveryNotice.value = 'ICP and lead scoring settings saved.'
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save ICP configuration.' }
  finally { savingConfiguration.value = false }
}

async function loadAIConfigurations() {
  try { aiConfigurations.value = await api('/ai-configurations') }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load AI configuration.' }
}

async function saveAIConfiguration(configuration: any) {
  savingConfiguration.value = true
  error.value = ''
  try {
    const parameters: Record<string, number> = {}
    const temperature = configuration.parameters?.temperature
    const maxOutputTokens = configuration.parameters?.max_output_tokens
    if (temperature !== '' && temperature !== null && temperature !== undefined && Number.isFinite(Number(temperature))) parameters.temperature = Number(temperature)
    if (maxOutputTokens !== '' && maxOutputTokens !== null && maxOutputTokens !== undefined && Number.isFinite(Number(maxOutputTokens))) parameters.max_output_tokens = Number(maxOutputTokens)
    const response = await fetch(configuration.id ? `/api/v1/ai-configurations/${encodeURIComponent(configuration.id)}` : '/api/v1/ai-configurations', {
      method: configuration.id ? 'PUT' : 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Tenant-ID': tenant.value },
      body: JSON.stringify({ task_key: configuration.task_key, provider: configuration.provider, model: configuration.model,
        enabled: configuration.enabled, parameters }),
    })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? `Unable to save ${configuration.task_key} configuration.`)
    await loadAIConfigurations()
    discoveryNotice.value = `AI settings saved for ${configuration.task_key}.`
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to save AI configuration.' }
  finally { savingConfiguration.value = false }
}

async function signOut() {
  try {
    await fetch('/api/v1/logout', { method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Tenant-ID': tenant.value } })
  } finally {
    signedIn.value = false
    currentUser.value = null
    tenant.value = ''
    localStorage.removeItem('tenant_id')
    companies.value = []; runs.value = []; contacts.value = []; scores.value = []
  }
}

async function createCompany() {
  error.value = ''
  try {
    const response = await fetch('/api/v1/companies', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Tenant-ID': tenant.value }, body: JSON.stringify(companyDraft.value) })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? 'Unable to add company.')
    showAdd.value = false; companyDraft.value = { name: '', website: '', industry: '', location: '' }
    await load()
  } catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to add company.' }
}

async function startDiscovery() {
  discoveryBusy.value = true
  discoveryNotice.value = ''
  error.value = ''
  try {
    const response = await fetch('/api/v1/discovery-runs', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Tenant-ID': tenant.value },
      body: JSON.stringify({ candidates: [{ ...discoveryDraft.value, source: 'user_seed' }] }),
    })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? 'Unable to start discovery.')
    discoveryNotice.value = `Discovery queued (${result.id}). Check Agent Runs for progress.`
    discoveryDraft.value = { name: '', website: '', industry: '', location: '' }
    showDiscovery.value = false
    await load()
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Unable to start discovery.'
  } finally {
    discoveryBusy.value = false
  }
}

async function viewIntelligence(company: any) {
  selectedCompany.value = company
  active.value = 'Website Intelligence'
  loading.value = true
  error.value = ''
  screenshotUrls.value.forEach((screenshot) => URL.revokeObjectURL(screenshot.url))
  screenshotUrls.value = []
  try {
    intelligence.value = await api(`/companies/${encodeURIComponent(company.id)}/intelligence`)
    await Promise.all((intelligence.value.screenshots ?? []).map(async (screenshot: any) => {
      try {
        const response = await fetch(`/api/v1/website-screenshots/${encodeURIComponent(screenshot.id)}/content`, {
          credentials: 'include', headers: { Accept: 'image/png', 'X-Tenant-ID': tenant.value },
        })
        if (response.ok) screenshotUrls.value.push({ id: screenshot.id, url: URL.createObjectURL(await response.blob()) })
      } catch { /* Optional screenshot failures do not block the intelligence view. */ }
    }))
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Unable to load website intelligence.'
  } finally {
    loading.value = false
  }
}

async function scanCompany(company: any) {
  const website = company.websites?.[0]
  if (!website) {
    error.value = 'Add a public website before starting a scan.'
    return
  }
  error.value = ''
  try {
    const response = await fetch(`/api/v1/websites/${encodeURIComponent(website.id)}/scan`, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Tenant-ID': tenant.value },
      body: JSON.stringify({}),
    })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? 'Unable to queue the website scan.')
    discoveryNotice.value = `Website scan queued for ${company.name} (${result.scan_id}).`
    await viewIntelligence(company)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Unable to queue the website scan.'
  }
}

async function scoreCompany(company: any) {
  error.value = ''
  try {
    const response = await fetch(`/api/v1/companies/${encodeURIComponent(company.id)}/score`, {
      method: 'POST', credentials: 'include',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Tenant-ID': tenant.value },
    })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message ?? 'Unable to queue lead scoring.')
    discoveryNotice.value = `Lead scoring queued for ${company.name} (${result.id}).`
    await load()
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Unable to queue lead scoring.'
  }
}

function select(page: Page) {
  if (page === 'Outreach') outreachKnowledgeOnly.value = false
  active.value = page
  if (page === 'Agent Runs') void load()
  if (page === 'Contacts') void loadContacts()
  if (page === 'Lead Scores') void loadScores()
  if (page === 'Setup') {}
  if (page === 'ICP Configuration') void loadScoringConfiguration()
  if (page === 'AI Configuration') void loadAIConfigurations()
}

function navigateSetup(page: string) { if (page === 'knowledge') { outreachKnowledgeOnly.value = true; active.value = 'Outreach'; return } select(page as Page) }

function openProspect(company: any) {
  if (!company?.id) return
  selectedCompany.value = company
  active.value = 'Prospect 360'
}

function openPipeline(opportunityId?: string) {
  focusedOpportunityId.value = opportunityId ?? ''
  active.value = 'Pipeline'
}

function openProposal(proposalId?: string) { focusedProposalId.value = proposalId ?? ''; active.value = 'Proposals' }

function openInbox(conversationId?: string) {
  focusedConversationId.value = conversationId ?? ''
  active.value = 'Inbox'
}

async function showCompanyDetails(company: any) {
  selectedCompany.value = company
  active.value = 'Company Details'
  if (company.websites && company.created_at) return
  try { selectedCompany.value = await api(`/companies/${encodeURIComponent(company.id)}`) }
  catch (exception) { error.value = exception instanceof Error ? exception.message : 'Unable to load company details.' }
}

onMounted(() => { void restoreSession() })
onBeforeUnmount(() => screenshotUrls.value.forEach((screenshot) => URL.revokeObjectURL(screenshot.url)))
</script>

<template>
  <div class="shell">
    <aside class="sidebar">
      <div class="brand"><span class="brand-mark">Y</span><span>yaandu<span class="brand-light">growth</span></span></div>
      <div class="workspace"><div class="workspace-icon">Y</div><div><b>{{ selectedTenant?.name || 'Yaandu' }}</b><small>{{ selectedTenant ? (isManager ? 'Manager workspace' : 'Sales workspace') : 'Growth workspace' }}</small></div><span class="chevron">⌄</span></div>
      <p class="nav-label">SALES</p>
      <button v-for="page in salesPages" :key="page.name" class="nav-item" :class="{ selected: active === page.name || (active === 'Prospect 360' && page.name === 'Prospects') }" @click="select(page.name)"><span class="nav-icon">{{ page.icon }}</span>{{ page.name }}</button>
      <template v-if="isManager">
        <p class="nav-label secondary-nav-label">MANAGEMENT</p>
        <button class="nav-item" :class="{selected:active==='Approval Center'}" @click="select('Approval Center')"><span class="nav-icon">✓</span>Approvals</button>
        <button class="nav-item" :class="{selected:active==='Automation'}" @click="select('Automation')"><span class="nav-icon">⚙</span>Automation</button>
        <p class="nav-label secondary-nav-label">SETUP</p>
        <button class="nav-item" :class="{selected:active==='Setup'}" @click="select('Setup')"><span class="nav-icon">⚙</span>Setup &amp; readiness</button>
        <button class="nav-item" :class="{selected:active==='ICP Configuration'}" @click="select('ICP Configuration')"><span class="nav-icon">☷</span>ICP &amp; Scoring</button>
        <button class="nav-item" :class="{selected:active==='Services & Pricing'}" @click="select('Services & Pricing')"><span class="nav-icon">▤</span>Services &amp; Pricing</button>
        <button class="nav-item" :class="{selected:active==='AI Configuration'}" @click="select('AI Configuration')"><span class="nav-icon">✳</span>AI Configuration</button>
        <p class="nav-label secondary-nav-label">OPERATIONS</p>
        <button class="nav-item" :class="{selected:active==='Agent Runs'}" @click="select('Agent Runs')"><span class="nav-icon">◷</span>Agent Runs</button>
        <button class="nav-item" :class="{selected:active==='Pilot Operations'}" @click="select('Pilot Operations')"><span class="nav-icon">▤</span>Pilot operations</button>
        <button class="nav-item" :class="{selected:active==='Corpus Operations'}" @click="select('Corpus Operations')"><span class="nav-icon">▤</span>Corpus operations</button>
        <button class="nav-item" :class="{selected:active==='Workflows'}" @click="select('Workflows')"><span class="nav-icon">⇢</span>Workflow diagnostics</button>
      </template>
      <div class="sidebar-bottom"><div class="avatar">{{ currentUser?.name?.slice(0, 2).toUpperCase() || 'YA' }}</div><div><b>{{ currentUser?.name || 'Workspace user' }}</b><small>{{ currentUser?.email || 'Yaandu account' }}</small></div><button class="more" v-if="signedIn" aria-label="Sign out" @click="signOut">⇥</button></div>
    </aside>
    <main class="main">
      <header class="topbar"><div class="breadcrumbs">Workspace <span>/</span> <b>{{ active }}</b></div><div class="top-actions"><select v-if="signedIn && tenantOptions.length > 1" v-model="tenant" class="tenant-switch" aria-label="Select workspace" @change="load"><option v-for="option in tenantOptions" :key="option.id" :value="option.id">{{ option.name }}</option></select><button class="help-button">? <span>Help center</span></button><div class="avatar small">{{ currentUser?.name?.slice(0, 2).toUpperCase() || 'YA' }}</div></div></header>
      <div class="content">
        <div class="page-heading"><div><div class="eyebrow">{{ active === 'Prospect 360' ? 'PROSPECTS' : currentPage.group }}</div><h1>{{ active === 'Prospect 360' ? selectedCompany?.name || 'Prospect 360' : active }}</h1><p>{{ active === 'Home' || active === 'Dashboard' ? 'Your sales work, priorities, and next steps.' : active === 'Prospect 360' ? 'Company context, evidence, relationships, and sales activity.' : `Review and manage your ${active.toLowerCase()} workspace.` }}</p></div></div>
        <LocalAcceptancePanel v-if="signedIn && tenant" :tenant-id="tenant" />
        <div v-if="!signedIn" class="tenant-card"><div><b>Sign in to your workspace</b><p>Use your Yaandu account to securely load tenant data.</p></div><input v-model="email" type="email" placeholder="Work email"/><input v-model="password" type="password" placeholder="Password" @keyup.enter="signIn"/><button class="primary" @click="signIn">Sign in</button></div>
        <div v-else-if="!tenant" class="tenant-card"><div><b>Choose a tenant</b><p>Select a workspace linked to your account.</p></div><select v-if="tenantOptions.length" v-model="tenant"><option value="" disabled>Select workspace</option><option v-for="option in tenantOptions" :key="option.id" :value="option.id">{{ option.name }}</option></select><input v-else v-model="tenant" placeholder="Tenant UUID"/><button class="primary" @click="load">Connect</button></div>
        <div v-if="loginError" class="notice">{{ loginError }}</div>
        <div v-if="error && tenant" class="notice">{{ error }} <button @click="load">Retry</button></div>
        <div v-if="discoveryNotice" class="notice success-notice">{{ discoveryNotice }}</div>
        <form v-if="showDiscovery && active === 'Companies'" class="tenant-card add-company" @submit.prevent="startDiscovery"><div><b>Discover a company</b><p>Register a company from a public business website. Discovery runs asynchronously.</p></div><input v-model="discoveryDraft.name" required maxlength="255" placeholder="Company name"/><input v-model="discoveryDraft.website" required type="url" maxlength="2048" placeholder="https://company.com"/><input v-model="discoveryDraft.industry" maxlength="150" placeholder="Industry"/><input v-model="discoveryDraft.location" maxlength="150" placeholder="Location"/><button class="primary" :disabled="discoveryBusy">{{ discoveryBusy ? 'Queueing…' : 'Start discovery' }}</button></form>
        <form v-if="showAdd && active === 'Companies'" class="tenant-card add-company" @submit.prevent="createCompany"><input v-model="companyDraft.name" required placeholder="Company name"/><input v-model="companyDraft.website" type="url" placeholder="https://company.com"/><input v-model="companyDraft.industry" placeholder="Industry"/><input v-model="companyDraft.location" placeholder="Location"/><button class="primary">Save company</button></form>
        <HomeWorkspace v-if="active === 'Home' && signedIn && tenant" :tenant-id="tenant" :manager="isManager" @open-prospects="select('Prospects')" @open-prospect="openProspect" @open-inbox="openInbox" @open-pipeline="openPipeline" @open-meetings="select('Meetings')" @open-proposals="select('Proposals')" @open-approvals="select('Approval Center')" />
        <ProspectsWorkspace v-else-if="active === 'Prospects' && signedIn && tenant" :tenant-id="tenant" :manager="isManager" @open-prospect="openProspect" />
        <Prospect360 v-else-if="active === 'Prospect 360' && signedIn && tenant && selectedCompany" :tenant-id="tenant" :company-id="selectedCompany.id" @back="select('Prospects')" @open-inbox="openInbox" @open-pipeline="openPipeline" @open-meetings="select('Meetings')" @open-outreach="select('Outreach')" @open-proposals="select('Proposals')" />
        <OutreachWorkspace v-else-if="active === 'Outreach' && signedIn && tenant" :tenant-id="tenant" :manager="isManager" :knowledge-only="outreachKnowledgeOnly" @open-prospect="openProspect" />
        <SetupWorkspace v-else-if="active === 'Setup' && signedIn && tenant && isManager" :tenant-id="tenant" @navigate="navigateSetup" />
        <ConversationWorkspace v-else-if="active === 'Inbox' && signedIn && tenant" :tenant-id="tenant" :focus-conversation-id="focusedConversationId" />
        <PipelineWorkspace v-else-if="active === 'Pipeline' && signedIn && tenant" :tenant-id="tenant" :focus-id="focusedOpportunityId" @open-prospect="openProspect" @open-inbox="openInbox" @open-meetings="select('Meetings')" @open-proposals="select('Proposals')" />
        <MeetingsWorkspace v-else-if="active === 'Meetings' && signedIn && tenant" :tenant-id="tenant" @open-prospect="openProspect" @open-inbox="openInbox" @open-pipeline="openPipeline" />
        <CorpusOperationsWorkspace v-else-if="active === 'Corpus Operations' && signedIn && tenant && isManager" :tenant-id="tenant" />
        <PilotOperationsDashboard v-else-if="active === 'Pilot Operations' && signedIn && tenant && isManager" :tenant-id="tenant" @open-prospects="select('Prospects')" />
        <template v-else-if="active === 'Dashboard'">
          <div class="stats-grid">
            <article class="stat-card"><div class="stat-top"><span>Total companies</span><span class="stat-icon blue">▦</span></div><strong>{{ dashboardSummary.companies }}</strong><small>In your prospecting database</small></article>
            <article class="stat-card"><div class="stat-top"><span>Scans completed</span><span class="stat-icon violet">◎</span></div><strong>{{ dashboardSummary.completed_scans }}</strong><small>Website intelligence scans</small></article>
            <article class="stat-card"><div class="stat-top"><span>Qualified leads</span><span class="stat-icon green">↗</span></div><strong>{{ dashboardSummary.qualified_leads }}</strong><small>Latest score at least 70</small></article>
            <article class="stat-card"><div class="stat-top"><span>Active runs</span><span class="stat-icon amber">✳</span></div><strong>{{ dashboardSummary.active_runs }}</strong><small><span class="status-dot"></span> Queued or running agents</small></article>
            <article class="stat-card"><div class="stat-top"><span>Meetings requested</span><span class="stat-icon violet">◷</span></div><strong>{{ dashboardSummary.meetings_requested }}</strong><small>Scheduling requests</small></article>
            <article class="stat-card"><div class="stat-top"><span>Meetings booked</span><span class="stat-icon green">✓</span></div><strong>{{ dashboardSummary.meetings_booked }}</strong><small>{{ dashboardSummary.upcoming_meetings }} upcoming</small></article>
          </div>
          <div class="dashboard-grid">
            <section class="panel pipeline"><div class="panel-heading"><div><h2>Prospecting pipeline</h2><p>Company coverage across your acquisition workflow</p></div><button class="quiet" @click="select('Companies')">View companies <span>→</span></button></div><div class="pipeline-body"><div class="pipeline-stage"><div class="stage-count">{{ companies.length }}</div><div class="stage-label"><span class="stage-dot slate"></span>Discovered</div><div class="stage-line"></div></div><div class="pipeline-stage"><div class="stage-count">—</div><div class="stage-label"><span class="stage-dot blue-dot"></span>Scanned</div><div class="stage-line"></div></div><div class="pipeline-stage"><div class="stage-count">—</div><div class="stage-label"><span class="stage-dot green-dot"></span>Scored</div></div></div><div class="pipeline-footer"><span class="pulse"></span> Pipeline updates as agents finish their runs</div></section>
            <section class="panel agent-panel"><div class="panel-heading"><div><h2>Agent activity</h2><p>Latest platform runs</p></div><button class="more-button" @click="select('Agent Runs')">···</button></div><div v-if="runs.length" class="run-list"><div v-for="run in runs.slice(0,4)" :key="run.id" class="run-row"><span class="run-symbol">✳</span><div><b>{{ run.agent_key }}</b><small>{{ run.status }} · {{ run.created_at }}</small></div><span class="run-status" :class="run.status">{{ run.status }}</span></div></div><div v-else class="empty-small"><span class="empty-icon">◷</span><b>No agent activity yet</b><small>Runs will appear here when discovery begins.</small></div></section>
          </div>
          <OrchestrationWorkspace v-if="signedIn && tenant" :tenant-id="tenant" view="summary" />
          <section class="panel companies-panel"><div class="panel-heading"><div><h2>Recently added companies</h2><p>Companies most recently added to your workspace</p></div><button class="quiet" @click="select('Companies')">All companies <span>→</span></button></div><div v-if="companies.length" class="table-wrap"><table><thead><tr><th>COMPANY</th><th>INDUSTRY</th><th>LOCATION</th><th>STATUS</th></tr></thead><tbody><tr v-for="company in companies.slice(0,5)" :key="company.id"><td><b>{{ company.name }}</b><small>{{ company.normalized_domain || 'No website' }}</small></td><td>{{ company.industry || '—' }}</td><td>{{ company.location || '—' }}</td><td><span class="pill">{{ company.status }}</span></td></tr></tbody></table></div><div v-else class="empty-table">Your company list will appear here once connected and populated.</div></section>
        </template>
        <section v-else-if="active === 'Companies'" class="panel companies-panel"><div class="panel-heading"><div><h2>Company directory</h2><p>Tenant scoped prospects and their public business information.</p></div><button class="quiet" @click="load">↻ Refresh</button></div><div v-if="companies.length" class="table-wrap"><table><thead><tr><th>COMPANY</th><th>INDUSTRY</th><th>LOCATION</th><th>STATUS</th><th>ADDED</th><th>ACTIONS</th></tr></thead><tbody><tr v-for="company in companies" :key="company.id"><td><b>{{ company.name }}</b><small>{{ company.normalized_domain || 'No website' }}</small></td><td>{{ company.industry || '—' }}</td><td>{{ company.location || '—' }}</td><td><span class="pill">{{ company.status }}</span></td><td>{{ new Date(company.created_at).toLocaleDateString() }}</td><td class="row-actions"><button class="quiet" @click="showCompanyDetails(company)">Details</button><button class="quiet" @click="viewIntelligence(company)">Intelligence</button><button class="quiet" :disabled="!company.websites?.length" @click="scanCompany(company)">Scan</button><button class="quiet" @click="scoreCompany(company)">Score</button></td></tr></tbody></table></div><div v-else class="empty-table">{{ loading ? 'Loading companies…' : 'No companies yet. Add or discover a company to begin.' }}</div></section>
        <section v-else-if="active === 'Website Intelligence'" class="panel companies-panel"><div class="panel-heading"><div><h2>{{ selectedCompany?.name || 'Website intelligence' }}</h2><p>Scan evidence, detected issues, technology signals, and lead scores.</p></div><div class="heading-actions"><button v-if="selectedCompany" class="quiet" @click="viewIntelligence(selectedCompany)">↻ Refresh</button><button v-if="selectedCompany?.websites?.length" class="primary" @click="scanCompany(selectedCompany)">Scan website</button></div></div><div v-if="loading" class="empty-table">Loading website intelligence…</div><template v-else-if="intelligence"><div class="intel-summary"><span>Latest scan</span><b>{{ intelligence.latest_scan?.status || 'Not scanned' }}</b><small>{{ intelligence.latest_scan?.created_at || 'No scan has been started.' }}</small></div><div v-if="intelligence.latest_scan?.error_summary" class="notice">{{ intelligence.latest_scan.error_summary }}</div><div v-if="screenshotUrls.length" class="screenshot-grid"><figure v-for="screenshot in screenshotUrls" :key="screenshot.id"><img :src="screenshot.url" alt="Captured public website screenshot"/><figcaption>Desktop website screenshot</figcaption></figure></div><h3 class="section-title">Website issues</h3><div v-if="intelligence.issues.length" class="intel-list"><article v-for="issue in intelligence.issues" :key="issue.id"><span class="pill">{{ issue.severity }}</span><div><b>{{ issue.summary }}</b><small>{{ issue.type }} · {{ Math.round(issue.confidence * 100) }}% confidence</small><small v-if="issue.evidence">Evidence: {{ JSON.stringify(issue.evidence) }}</small></div></article></div><div v-else class="empty-table">{{ intelligence.latest_scan?.status === 'completed' ? 'No website issues were recorded.' : 'Issues appear when scan analysis completes.' }}</div><h3 class="section-title">Scanned pages</h3><div v-if="intelligence.pages.length" class="intel-list"><article v-for="page in intelligence.pages" :key="page.id"><span class="pill">{{ page.http_status || '—' }}</span><div><b>{{ page.title || page.final_url || page.requested_url }}</b><small><a :href="page.final_url || page.requested_url" target="_blank" rel="noopener noreferrer">{{ page.final_url || page.requested_url }}</a></small><small>{{ page.extracted_text?.slice(0, 240) || 'No extracted text' }}</small></div></article></div><div v-else class="empty-table">No pages are stored for this scan yet.</div><h3 class="section-title">Lead score history</h3><div v-if="intelligence.scores.length" class="intel-list"><article v-for="score in intelligence.scores" :key="score.id"><b class="score-value">{{ score.score }} / 100</b><div><small>Rule version {{ score.rule_version }} · {{ score.scored_at }}</small><small>{{ JSON.stringify(score.components) }}</small></div></article></div><div v-else class="empty-table">No scores recorded yet. Use Score from the company directory.</div></template><div v-else class="empty-table">Select a company from the directory to inspect its website intelligence.</div></section>
        <section v-else-if="active === 'Company Details'" class="panel companies-panel"><div v-if="selectedCompany" class="detail-layout"><div><div class="eyebrow">COMPANY PROFILE</div><h2>{{ selectedCompany.name }}</h2><p class="detail-domain">{{ selectedCompany.normalized_domain || 'Website not provided' }}</p><dl class="detail-grid"><dt>Industry</dt><dd>{{ selectedCompany.industry || 'Not provided' }}</dd><dt>Location</dt><dd>{{ selectedCompany.location || 'Not provided' }}</dd><dt>Description</dt><dd>{{ selectedCompany.description || 'No description recorded' }}</dd><dt>Source</dt><dd>{{ selectedCompany.source || 'Manual entry' }}</dd><dt>Status</dt><dd>{{ selectedCompany.status }}</dd><dt>Created</dt><dd>{{ selectedCompany.created_at }}</dd></dl><h3 class="section-title">Websites</h3><div v-for="website in selectedCompany.websites" :key="website.id" class="website-row"><a :href="website.url" target="_blank" rel="noopener noreferrer">{{ website.url }}</a><span class="pill">{{ website.verification_status }}</span><button class="quiet" @click="scanCompany(selectedCompany)">Scan</button></div><p v-if="!selectedCompany.websites?.length" class="empty-table">No website has been added for this company.</p></div><div class="detail-actions"><button class="primary" @click="viewIntelligence(selectedCompany)">View intelligence</button><button class="quiet" @click="scoreCompany(selectedCompany)">Queue lead score</button></div></div><div v-else class="empty-table">Select a company from the directory to view its details.</div></section>
        <section v-else-if="active === 'Contacts'" class="panel companies-panel"><div class="panel-heading"><div><h2>Public business contacts</h2><p>Contact methods extracted from scanned public company pages, with provenance.</p></div><button class="quiet" @click="loadContacts">↻ Refresh</button></div><div v-if="contacts.length" class="contact-grid"><article v-for="contact in contacts" :key="contact.id" class="contact-card"><div class="contact-avatar">{{ (contact.name || contact.company_name || 'C').slice(0, 1).toUpperCase() }}</div><div class="contact-content"><b>{{ contact.name || 'Public contact method' }}</b><small>{{ contact.title || contact.company_name }}</small><div v-for="method in contact.methods" :key="method.id" class="contact-method"><span class="pill">{{ method.type }}</span><a v-if="method.type === 'email'" :href="`mailto:${method.value}`">{{ method.value }}</a><a v-else-if="method.type === 'phone'" :href="`tel:${method.value}`">{{ method.value }}</a><span v-else>{{ method.value }}</span></div><small>Source: <a :href="contact.source_url" target="_blank" rel="noopener noreferrer">{{ contact.source_url }}</a></small><small>Observed {{ contact.observed_at }} · {{ contact.extraction_method }} · {{ Math.round(contact.confidence * 100) }}% confidence</small></div></article></div><div v-else class="empty-table">No public contact methods have been extracted yet. Scan a company website to check its public pages.</div></section>
        <section v-else-if="active === 'Lead Scores'" class="panel companies-panel"><div class="panel-heading"><div><h2>Lead score history</h2><p>Rule-based scores with the evidence behind each component.</p></div><button class="quiet" @click="loadScores">↻ Refresh</button></div><div v-if="scores.length" class="score-list"><article v-for="score in scores" :key="score.id" class="score-card"><div class="score-circle">{{ score.score }}</div><div class="score-body"><b>{{ score.company_name }}</b><small>{{ score.normalized_domain }} · Rule version {{ score.rule_version }} · {{ score.scored_at }}</small><details><summary>Review scoring evidence</summary><pre>{{ JSON.stringify(score.components, null, 2) }}</pre></details></div><button class="quiet" @click="showCompanyDetails({ id: score.company_id, name: score.company_name, normalized_domain: score.normalized_domain })">Company</button></article></div><div v-else class="empty-table">No lead scores yet. Queue scoring from the Companies directory.</div></section>
        <section v-else-if="active === 'ICP Configuration' && isManager" class="panel companies-panel config-panel"><div class="panel-heading"><div><h2>Ideal customer profile</h2><p>Configure target industries, locations, and keywords used by scoring.</p></div><span class="pill">Scoring rules v{{ scoringConfiguration.version }}</span></div><form class="config-form" @submit.prevent="saveScoringConfiguration"><label>Target industries<input v-model="icpDraft.industries" placeholder="Technology, Healthcare"/><small>Separate values with commas.</small></label><label>Target locations<input v-model="icpDraft.locations" placeholder="Toronto, Ontario"/><small>Use exact location labels from company records.</small></label><label>Business keywords<input v-model="icpDraft.keywords" placeholder="cloud, ecommerce, manufacturing"/><small>Matches company names and descriptions.</small></label><h3 class="section-title">Scoring weights</h3><div class="rules-grid"><label v-for="(points, key) in scoringConfiguration.rules" :key="key">{{ String(key).replaceAll('_', ' ') }}<input v-model.number="scoringConfiguration.rules[key]" type="number" min="0" max="100"/></label></div><button class="primary" :disabled="savingConfiguration">{{ savingConfiguration ? 'Saving…' : 'Save ICP and scoring rules' }}</button></form></section>
        <section v-else-if="active === 'AI Configuration' && isManager" class="config-grid"><article v-for="configuration in aiConfigurations" :key="configuration.task_key" class="panel ai-config-card"><div class="panel-heading"><div><h2>{{ ({website_visual_analysis:'Website visual analysis',website_reasoning:'Website intelligence',lead_classification:'Lead classification',sales_reasoning:'Sales reasoning',content_generation:'Marketing content generation',structured_extraction:'Structured extraction',proposal_generation:'Proposal generation'} as Record<string,string>)[configuration.task_key] || configuration.task_key }}</h2><p>{{ configuration.is_tenant_override ? 'Tenant override' : 'Application default' }} · Version {{ configuration.version }}</p></div><label class="toggle-label"><input v-model="configuration.enabled" type="checkbox"/>Enabled</label></div><label>Provider<select v-model="configuration.provider"><option v-for="provider in configuration.available_providers" :key="provider" :value="provider">{{ provider === 'deterministic' ? 'Deterministic · local acceptance only' : provider === 'gemini' ? 'Google Gemini' : provider === 'openai' ? 'OpenAI' : 'Anthropic Claude' }}</option></select></label><label>Model<input v-model="configuration.model" maxlength="120"/></label><div class="parameter-row"><label>Temperature<input v-model.number="configuration.parameters.temperature" type="number" min="0" max="2" step="0.1" placeholder="Task default"/></label><label>Max output tokens<input v-model.number="configuration.parameters.max_output_tokens" type="number" min="1" max="8192" placeholder="Task default"/></label></div><button class="primary" :disabled="savingConfiguration" @click="saveAIConfiguration(configuration)">{{ savingConfiguration ? 'Saving…' : 'Save task configuration' }}</button></article><div v-if="!aiConfigurations.length" class="empty-table">No AI task configurations returned.</div></section>
        <section v-else-if="active === 'Agent Runs'" class="panel companies-panel"><div class="panel-heading"><div><h2>Agent run history</h2><p>Execution records, progress events, and safe error summaries.</p></div><button class="quiet" @click="load">↻ Refresh</button></div><div v-if="runs.length" class="table-wrap"><table><thead><tr><th>AGENT</th><th>STATUS</th><th>SUMMARY</th><th>STARTED</th></tr></thead><tbody><tr v-for="run in runs" :key="run.id"><td><b>{{ run.agent_key }}</b><small>{{ run.id }}</small></td><td><span class="pill">{{ run.status }}</span></td><td>{{ run.summary || run.error_summary || '—' }}<details v-if="run.events?.length" class="run-events"><summary>{{ run.events.length }} events</summary><small v-for="event in run.events" :key="event.id">{{ event.sequence }} · {{ event.event_key }} · {{ JSON.stringify(event.payload) }}</small></details></td><td>{{ run.started_at || run.created_at }}</td></tr></tbody></table></div><div v-else class="empty-table">{{ loading ? 'Loading agent runs…' : 'No agent runs recorded yet.' }}</div></section>
        <CampaignWorkspace v-else-if="active === 'Campaigns' && signedIn && tenant" :tenant-id="tenant" :manager="isManager" />
        <MarketingWorkspace v-else-if="active === 'Marketing Intelligence' && signedIn && tenant" :tenant-id="tenant" :manager="isManager" />
        <ConversationWorkspace v-else-if="active === 'Conversation Inbox' && signedIn && tenant" :tenant-id="tenant" />
        <ProposalWorkspace v-else-if="active === 'Proposals' && signedIn && tenant" :tenant-id="tenant" :focus-id="focusedProposalId" @open-prospect="openProspect" @open-opportunity="openPipeline" />
        <ProposalWorkspace v-else-if="active === 'Services & Pricing' && signedIn && tenant && isManager" :tenant-id="tenant" setup-only />
        <OrchestrationWorkspace v-else-if="active === 'Workflows' && signedIn && tenant" :tenant-id="tenant" view="workflows" />
        <OrchestrationWorkspace v-else-if="active === 'Approval Center' && signedIn && tenant && isManager" :tenant-id="tenant" view="approvals" @open-prospect="openProspect" @open-proposal="openProposal" />
        <OrchestrationWorkspace v-else-if="active === 'Automation' && signedIn && tenant && isManager" :tenant-id="tenant" view="policies" />
        <section v-else-if="signedIn" class="coming-panel"><div class="coming-symbol">{{ currentPage.icon }}</div><div class="eyebrow">WORKSPACE</div><h2>{{ active }}</h2><p>This workflow is not active yet.</p><button class="quiet" @click="select('Home')">← Back to Home</button></section>
        <footer>© 2026 Yaandu <span>Growth Engine · Phase 2 foundation</span><span class="footer-right">Built for thoughtful growth</span></footer>
      </div>
    </main>
  </div>
</template>
