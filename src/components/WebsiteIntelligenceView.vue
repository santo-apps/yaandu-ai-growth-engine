<script setup lang="ts">
import { computed } from 'vue'

type RecordValue = Record<string, any>

const props = defineProps<{
  company: RecordValue | null
  intelligence: RecordValue | null
  screenshots: Array<{ id: string; url: string }>
  loading: boolean
}>()
const emit = defineEmits<{ refresh: []; scan: []; score: [] }>()

const report = computed<RecordValue | null>(() => {
  const raw = props.intelligence?.intelligence_results?.[0]?.structured_output
  if (raw && typeof raw === 'object') return raw as RecordValue
  if (typeof raw === 'string') {
    try { return JSON.parse(raw) as RecordValue } catch { return null }
  }
  return null
})

function sourceUrl(value: unknown): string | null {
  if (typeof value !== 'string') return null
  try {
    const url = new URL(value)
    return ['http:', 'https:'].includes(url.protocol) ? url.toString() : null
  } catch { return null }
}

function evidenceFor(id: unknown): RecordValue | undefined {
  if (typeof id !== 'string') return undefined
  return (report.value?.evidence ?? []).find((item: RecordValue) => item.evidence_id === id)
}

function issueEvidence(value: unknown): RecordValue {
  if (value && typeof value === 'object') return value as RecordValue
  if (typeof value === 'string') {
    try { return JSON.parse(value) as RecordValue } catch { return { excerpt: value } }
  }
  return {}
}

const deterministicVersion = 'deterministic-v1'
const inspectedPageCount = computed(() => new Set((props.intelligence?.pages ?? []).map((page: RecordValue) => page.id).filter(Boolean)).size)
const scanFailureMessage = computed(() => {
  const failure = props.intelligence?.latest_scan?.failure_category
  if (failure === 'ROBOTS') return 'Crawl permission could not be verified from robots.txt.'
  if (failure === 'HTTP_4XX') return 'The website returned a client error during the scan.'
  if (failure === 'SSRF_POLICY' || failure === 'SSRF_REJECTED') return 'The destination did not pass the website safety checks.'
  if (failure === 'TIMEOUT') return 'The website did not respond within the scan time limit.'
  if (failure) return 'The latest scan could not be completed. Review the safe failure status with an administrator.'
  return null
})

type EvidenceSource = { url: string; excerpts: string[]; confidences: number[]; pageIds: string[] }
type EvidenceGroup = { key: string; title: string; category: string; count: number; pageIds: Set<string>; confidence: number[]; sources: Map<string, EvidenceSource>; severity?: string }

function normalized(value: unknown): string {
  return typeof value === 'string' ? value.normalize('NFKC').toLocaleLowerCase().replace(/\s+/g, ' ').trim() : ''
}

function evidenceUrl(value: unknown): string {
  if (typeof value !== 'string') return ''
  try {
    const url = new URL(value)
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return ''
    url.username = ''; url.password = ''; url.search = ''; url.hash = ''
    return url.toString()
  } catch { return '' }
}

function addSource(group: EvidenceGroup, row: RecordValue, evidence: RecordValue, pagesById: Map<string, RecordValue>) {
  const pageId = typeof row.website_page_id === 'string' ? row.website_page_id : ''
  const page = pagesById.get(pageId)
  const url = evidenceUrl(evidence.source_url || page?.final_url || page?.requested_url || page?.canonical_url)
  const key = url || (pageId ? `page:${pageId}` : `record:${String(row.id ?? group.count)}`)
  const excerptValue = typeof evidence.excerpt === 'string' ? evidence.excerpt : typeof evidence.marker === 'string' ? evidence.marker : ''
  const excerpt = excerptValue.replace(/\s+/g, ' ').trim().slice(0, 240)
  const confidence = row.confidence !== null && row.confidence !== undefined && row.confidence !== '' && Number.isFinite(Number(row.confidence)) ? Number(row.confidence) : null
  const prior = group.sources.get(key)
  if (prior) {
    if (pageId && !prior.pageIds.includes(pageId)) prior.pageIds.push(pageId)
    if (excerpt && !prior.excerpts.includes(excerpt)) prior.excerpts.push(excerpt)
    if (confidence !== null) prior.confidences.push(confidence)
    return
  }
  group.sources.set(key, { url, excerpts: excerpt ? [excerpt] : [], confidences: confidence === null ? [] : [confidence], pageIds: pageId ? [pageId] : [] })
}

function emptyGroup(key: string, title: string, category: string, severity?: string): EvidenceGroup {
  return { key, title, category, count: 0, pageIds: new Set(), confidence: [], sources: new Map(), severity }
}

function parseEvidence(value: unknown): RecordValue {
  return issueEvidence(value)
}

const deterministicIssues = computed<EvidenceGroup[]>(() => {
  const rows = (props.intelligence?.issues ?? []).filter((row: RecordValue) => row.detector_version === deterministicVersion && row.type !== 'lead_capture_mechanism_present')
  const pagesById = new Map<string, RecordValue>((props.intelligence?.pages ?? []).map((page: RecordValue) => [String(page.id), page]))
  const groups = new Map<string, EvidenceGroup>()
  for (const row of rows) {
    const type = normalized(row.type)
    const severity = normalized(row.severity) || 'unknown'
    const summary = normalized(row.summary)
    const key = JSON.stringify([row.detector_version, type, severity, summary])
    const title = issueTitle(row)
    const group = groups.get(key) ?? emptyGroup(key, title, 'Issues', severity)
    group.count++
    if (row.website_page_id) group.pageIds.add(String(row.website_page_id))
    if (row.confidence !== null && row.confidence !== undefined && row.confidence !== '' && Number.isFinite(Number(row.confidence))) group.confidence.push(Number(row.confidence))
    addSource(group, row, parseEvidence(row.evidence), pagesById)
    groups.set(key, group)
  }
  const rank: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3, unknown: 4 }
  return [...groups.values()].sort((a, b) => (rank[a.severity || 'unknown'] ?? 4) - (rank[b.severity || 'unknown'] ?? 4) || a.title.localeCompare(b.title))
})

function issueTitle(row: RecordValue): string {
  const known: Record<string, string> = {
    poor_mobile_ux: 'Poor mobile UX', lead_capture_mechanism_present: 'Lead capture mechanism present',
    seo_missing_title: 'Document title missing', seo_missing_meta_description: 'Meta description missing',
    seo_missing_canonical: 'Canonical link missing', seo_missing_h1: 'H1 missing', seo_missing_language: 'HTML language missing',
    outdated_website: 'Outdated website technology observed',
  }
  return known[String(row.type)] || (typeof row.summary === 'string' ? row.summary : 'Website issue')
}

const deterministicTechnologyGroups = computed(() => {
  const technologyRows = (props.intelligence?.technologies ?? []).filter((row: RecordValue) => row.detection_method === deterministicVersion)
  const positiveContactRows = (props.intelligence?.issues ?? []).filter((row: RecordValue) => row.detector_version === deterministicVersion && row.type === 'lead_capture_mechanism_present')
    .map((row: RecordValue) => ({ ...row, name: 'Lead capture mechanism present', category: 'contact_navigation', detection_method: deterministicVersion }))
  const rows = [...technologyRows, ...positiveContactRows]
  const pagesById = new Map<string, RecordValue>((props.intelligence?.pages ?? []).map((page: RecordValue) => [String(page.id), page]))
  const groups = new Map<string, EvidenceGroup>()
  for (const row of rows) {
    const name = typeof row.name === 'string' ? row.name.trim() : 'Observed website signal'
    const category = typeof row.category === 'string' ? row.category : 'other'
    const key = JSON.stringify([row.detection_method, normalized(category), normalized(name)])
    const group = groups.get(key) ?? emptyGroup(key, name, category)
    group.count++
    if (row.website_page_id) group.pageIds.add(String(row.website_page_id))
    if (row.confidence !== null && row.confidence !== undefined && row.confidence !== '' && Number.isFinite(Number(row.confidence))) group.confidence.push(Number(row.confidence))
    addSource(group, row, parseEvidence(row.evidence), pagesById)
    groups.set(key, group)
  }
  const sectionOrder: Record<string, number> = { contact_channel: 0, contact_navigation: 0, ecommerce: 1, cms: 1, site_builder: 1, framework: 1, responsive: 2, seo_metadata: 3 }
  return [...groups.values()].sort((a, b) => (sectionOrder[a.category] ?? 4) - (sectionOrder[b.category] ?? 4) || a.title.localeCompare(b.title) || a.key.localeCompare(b.key))
})

const aiIssues = computed(() => (props.intelligence?.issues ?? []).filter((row: RecordValue) => row.detector_version !== deterministicVersion))
const aiTechnologies = computed(() => (props.intelligence?.technologies ?? []).filter((row: RecordValue) => row.detection_method !== deterministicVersion))

const technologySections = computed(() => {
  const titles: Record<string, string> = {
    contact_channel: 'Contact & conversion', contact_navigation: 'Contact & conversion', ecommerce: 'Commerce & platform',
    cms: 'Commerce & platform', site_builder: 'Commerce & platform', framework: 'Commerce & platform',
    responsive: 'Responsive', seo_metadata: 'SEO metadata',
  }
  const grouped = new Map<string, EvidenceGroup[]>()
  for (const item of deterministicTechnologyGroups.value) {
    const title = titles[item.category] || 'Other technologies'
    grouped.set(title, [...(grouped.get(title) ?? []), item])
  }
  return ['Contact & conversion', 'Commerce & platform', 'Responsive', 'SEO metadata', 'Other technologies']
    .filter((title) => grouped.has(title)).map((title) => ({ title, items: grouped.get(title)! }))
})

function groupPageCount(group: EvidenceGroup): number {
  return group.pageIds.size
}
function groupConfidence(group: EvidenceGroup): number | null {
  return group.confidence.length ? Math.round(group.confidence.reduce((sum, value) => sum + value, 0) / group.confidence.length * 100) : null
}
function categoryLabel(category: string): string {
  const labels: Record<string, string> = { contact_channel: 'Contact channel', contact_navigation: 'Contact & conversion', ecommerce: 'E-commerce', responsive: 'Responsive', seo_metadata: 'SEO metadata', cms: 'Platform', site_builder: 'Platform', framework: 'Technology', crm: 'CRM', legacy: 'Legacy technology' }
  return labels[category] || category.replaceAll('_', ' ')
}
function compactExcerpt(value: string): string { return value.length > 180 ? `${value.slice(0, 177)}…` : value }

function componentRows(value: unknown): Array<{ label: string; status: string; statusLabel: string; value: string; evidence: string }> {
  let parsed = value
  if (typeof parsed === 'string') {
    try { parsed = JSON.parse(parsed) } catch { return [] }
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return []
  return Object.entries(parsed as RecordValue).map(([key, raw]) => {
    const detail = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw as RecordValue : {}
    const status = typeof detail.status === 'string' ? detail.status : 'UNKNOWN'
    const result = typeof raw === 'number' || typeof raw === 'string'
      ? raw
      : detail.points ?? detail.score ?? detail.value ?? detail.status ?? 'Not recorded'
    return {
      label: key.replaceAll('_', ' '),
      status,
      statusLabel: ({ POSITIVE: 'Opportunity found', NEGATIVE: 'Capability present', UNKNOWN: 'Unknown / insufficient evidence', NOT_CONFIGURED: 'Not configured' } as Record<string, string>)[status] || 'Unknown / insufficient evidence',
      value: ['UNKNOWN', 'NOT_CONFIGURED'].includes(status) ? '—' : `${Number(result) > 0 ? '+' : ''}${String(result)}`,
      evidence: formatEvidence(detail.evidence_reference ?? detail.evidence ?? detail.reference ?? detail.reason),
    }
  })
}

function formatEvidence(value: unknown): string {
  if (value === null || value === undefined || value === '') return ''
  if (typeof value !== 'object') return String(value)
  if (Array.isArray(value)) return value.map(formatEvidence).filter(Boolean).join(' · ')
  const detail = value as RecordValue
  const fields = ['source', 'field', 'observed', 'value', 'criteria', 'name', 'url']
  return fields.filter((key) => detail[key] !== null && detail[key] !== undefined && detail[key] !== '')
    .map((key) => `${key.replaceAll('_', ' ')}: ${key === 'url' ? evidenceUrl(detail[key]) : Array.isArray(detail[key]) ? detail[key].join(', ') : String(detail[key])}`)
    .filter((part) => !part.endsWith(': ')).join(' · ')
}
</script>

<template>
  <section class="intelligence-workspace panel">
    <header class="intel-heading">
      <div>
        <span class="eyebrow">WEBSITE INTELLIGENCE · AI ADVISORY</span>
        <h2>{{ company?.name || 'Website intelligence' }}</h2>
        <p>Evidence from public pages. Findings are advisory and require human review.</p>
      </div>
      <div class="intel-actions">
        <button v-if="company" class="quiet" :disabled="loading" @click="emit('refresh')">Refresh</button>
        <button v-if="company" class="quiet" :disabled="loading" @click="emit('score')">Run lead score</button>
        <button v-if="company?.websites?.length" class="primary" :disabled="loading" @click="emit('scan')">{{ loading ? 'Working…' : 'Scan website' }}</button>
      </div>
    </header>

    <div v-if="loading" class="intel-state" role="status">Loading website intelligence…</div>
    <template v-else-if="intelligence">
      <div class="scan-status"><span>Latest scan</span><b>{{ intelligence.latest_scan?.status || 'Not scanned' }}</b><small>{{ scanFailureMessage || intelligence.latest_scan?.created_at || 'No completed website scan is available yet.' }}</small></div>
      <div v-if="!report" class="intel-empty-report"><b>No Website Intelligence yet</b><span>{{ intelligence.latest_scan?.status === 'completed' ? 'Generate a report from the latest completed scan to review interpreted findings.' : 'A completed website scan is needed before an intelligence report can be generated.' }}</span></div>
      <section v-if="report" class="intel-section summary-section">
        <div class="section-heading"><div><h3>Executive summary</h3><p>Generated from the latest evidence-backed intelligence report.</p></div><span class="advisory-chip">AI advisory</span></div>
        <p>{{ report.business_identity?.description || 'The report does not include a supported business summary.' }}</p>
        <small v-if="report.confidence !== undefined">Report confidence: {{ Math.round(Number(report.confidence) * 100) }}%</small>
      </section>

      <div v-if="screenshots.length" class="screenshot-grid">
        <figure v-for="screenshot in screenshots" :key="screenshot.id"><img :src="screenshot.url" alt="Captured public website"/><figcaption>Public website capture</figcaption></figure>
      </div>

      <section v-if="report?.observations?.length" class="intel-section">
        <h3>Key observations and website facts</h3>
        <article v-for="(item, index) in report.observations" :key="`observation-${index}`" class="finding">
          <b>{{ item.statement }}</b><small>Evidence-backed observation · {{ Math.round(Number(item.confidence ?? 0) * 100) }}% confidence</small>
          <blockquote v-if="evidenceFor(item.evidence_id)?.excerpt">“{{ evidenceFor(item.evidence_id)?.excerpt }}”</blockquote>
          <a v-if="sourceUrl(evidenceFor(item.evidence_id)?.source_url)" :href="sourceUrl(evidenceFor(item.evidence_id)?.source_url) || undefined" target="_blank" rel="noopener noreferrer">Open evidence source ↗</a>
        </article>
      </section>

      <section v-if="report?.opportunities?.length" class="intel-section">
        <div class="section-heading"><h3>Opportunity signals</h3><span class="status-chip">AI advisory</span></div>
        <article v-for="(item, index) in report.opportunities" :key="`opportunity-${index}`" class="finding">
          <b>{{ item.statement }}</b><small>Potential opportunity · advisory · {{ Math.round(Number(item.confidence ?? 0) * 100) }}% confidence</small>
          <blockquote v-if="evidenceFor(item.evidence_id)?.excerpt">“{{ evidenceFor(item.evidence_id)?.excerpt }}”</blockquote>
          <a v-if="sourceUrl(evidenceFor(item.evidence_id)?.source_url)" :href="sourceUrl(evidenceFor(item.evidence_id)?.source_url) || undefined" target="_blank" rel="noopener noreferrer">Open evidence source ↗</a>
        </article>
      </section>

      <section v-if="report?.service_recommendations?.length" class="intel-section">
        <div class="section-heading"><h3>Service suggestions</h3><span class="advisory-chip">Human selection required</span></div>
        <article v-for="(item, index) in report.service_recommendations" :key="`recommendation-${index}`" class="finding">
          <b>{{ item.service_key?.replaceAll('_', ' ') || 'Service suggestion' }}</b><small>{{ item.speculative ? 'Speculative · discovery needed' : (item.recommendation_strength || 'Evidence linked') }} · AI suggestion · not approved</small>
          <p>{{ item.rationale || item.recommendation }}</p><small v-if="item.discovery_question">Discovery question: {{ item.discovery_question }}</small><small v-if="item.recommended_next_action">Next action: {{ item.recommended_next_action }}</small>
          <div v-if="item.tenant_services?.length" class="matched-commercial-services"><small>Matching approved commercial services for human review:</small><span v-for="service in item.tenant_services" :key="service.id">{{ service.name }} · {{ service.sku }}</span></div>
        </article>
      </section>

      <section v-if="deterministicIssues.length || deterministicTechnologyGroups.length" class="intel-section deterministic-evidence" aria-label="Deterministic technical evidence">
        <div class="section-heading"><div><h3>Observed website evidence</h3><p>Repeated page findings are grouped; original page-level evidence remains available below each item.</p></div><span class="status-chip">Observed evidence</span></div>
        <section v-if="deterministicIssues.length" class="evidence-group-section">
          <h4>Technical and content findings</h4>
          <article v-for="group in deterministicIssues" :key="group.key" class="evidence-group issue-group">
            <div class="finding-title"><b>{{ group.title }}</b><span class="status-chip severity-chip">{{ group.severity }}</span></div>
            <small>{{ groupPageCount(group) }} / {{ inspectedPageCount }} inspected pages · {{ group.count }} matching {{ group.count === 1 ? 'record' : 'records' }}<template v-if="groupConfidence(group) !== null"> · {{ groupConfidence(group) }}% confidence</template></small>
            <details v-if="group.sources.size" class="source-details"><summary>View evidence sources ({{ group.sources.size }})</summary>
              <ul class="source-list"><li v-for="[key, source] in group.sources" :key="key"><a v-if="source.url" :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.url }}</a><span v-else>Page evidence</span><small v-if="source.pageIds.length">Page IDs: {{ source.pageIds.join(', ') }}</small><small v-for="excerpt in source.excerpts" :key="excerpt">{{ compactExcerpt(excerpt) }}</small><small v-if="source.confidences.length">Confidence {{ source.confidences.map(value => `${Math.round(value * 100)}%`).join(', ') }}</small></li></ul>
            </details>
          </article>
        </section>
        <section v-for="section in technologySections" :key="section.title" class="evidence-group-section">
          <h4>{{ section.title }} · Existing capabilities</h4>
          <article v-for="group in section.items" :key="group.key" class="evidence-group">
            <div class="finding-title"><b>{{ group.title }}</b><span class="category-chip">{{ categoryLabel(group.category) }}</span></div>
            <small>Detected on {{ groupPageCount(group) }} / {{ inspectedPageCount }} inspected pages · {{ group.count }} {{ group.count === 1 ? 'record' : 'records' }}<template v-if="groupConfidence(group) !== null"> · {{ groupConfidence(group) }}% confidence</template></small>
            <details v-if="group.sources.size" class="source-details"><summary>View evidence sources ({{ group.sources.size }})</summary>
              <ul class="source-list"><li v-for="[key, source] in group.sources" :key="key"><a v-if="source.url" :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.url }}</a><span v-else>Page evidence</span><small v-if="source.pageIds.length">Page IDs: {{ source.pageIds.join(', ') }}</small><small v-for="excerpt in source.excerpts" :key="excerpt">{{ compactExcerpt(excerpt) }}</small><small v-if="source.confidences.length">Confidence {{ source.confidences.map(value => `${Math.round(value * 100)}%`).join(', ') }}</small></li></ul>
            </details>
          </article>
        </section>
      </section>

      <section v-if="aiIssues.length || aiTechnologies.length || report?.technical_findings?.length" class="intel-section ai-technical-evidence">
        <div class="section-heading"><h3>AI-generated technical observations</h3><span class="advisory-chip">AI advisory · human review</span></div>
        <div v-if="aiIssues.length" class="finding-list">
          <article v-for="issue in aiIssues" :key="issue.id" class="finding">
            <div class="finding-title"><b>{{ issue.summary }}</b><span class="status-chip">{{ issue.severity }}</span></div>
            <small>{{ issue.type?.replaceAll('_', ' ') || 'Website finding' }} · {{ Math.round(Number(issue.confidence ?? 0) * 100) }}% confidence</small>
            <details v-if="issue.evidence"><summary>Inspect evidence</summary>
              <blockquote>{{ compactExcerpt(String(issueEvidence(issue.evidence).excerpt || 'A source excerpt was not recorded.')) }}</blockquote>
              <a v-if="sourceUrl(issueEvidence(issue.evidence).source_url)" :href="sourceUrl(issueEvidence(issue.evidence).source_url) || undefined" target="_blank" rel="noopener noreferrer">Open evidence source ↗</a>
            </details>
          </article>
        </div>
        <div v-if="aiTechnologies.length || report?.technical_findings?.length" class="technology-list">
          <h4>AI-detected technologies and signals</h4><span v-for="item in (aiTechnologies.length ? aiTechnologies : report?.technical_findings ?? [])" :key="item.id || item.summary">{{ item.name || item.summary }}</span>
        </div>
        <p v-if="!aiIssues.length && !aiTechnologies.length && !report?.technical_findings?.length" class="empty-state">No AI-generated technical observations were recorded.</p>
      </section>

      <section v-if="!deterministicIssues.length && !deterministicTechnologyGroups.length && !aiIssues.length && !aiTechnologies.length && !report?.technical_findings?.length" class="intel-section">
        <h3>Website evidence</h3><p class="empty-state">No technical observations or existing capabilities were recorded for this scan.</p>
      </section>

      <section v-if="report?.unknowns?.length" class="intel-section unknown-section">
        <h3>Missing evidence and unknowns</h3><ul><li v-for="(unknown, index) in report.unknowns" :key="index">{{ unknown }}</li></ul>
      </section>

      <section class="intel-section">
        <h3>Supporting evidence</h3>
        <div v-if="report?.evidence?.length" class="evidence-list">
          <article v-for="item in report.evidence" :key="item.evidence_id"><div><b>{{ item.source_url }}</b><small>{{ item.evidence_id }}</small></div>
            <blockquote>{{ item.excerpt }}</blockquote><a v-if="sourceUrl(item.source_url)" :href="sourceUrl(item.source_url) || undefined" target="_blank" rel="noopener noreferrer">Open source ↗</a></article>
        </div>
        <div v-else-if="intelligence.pages?.length" class="evidence-list">
          <article v-for="page in intelligence.pages" :key="page.id"><div><b>{{ page.title || page.final_url || page.requested_url }}</b><small>{{ page.http_status || 'Status unavailable' }}</small></div><blockquote>{{ page.extracted_text || 'No page excerpt was retained.' }}</blockquote><a v-if="sourceUrl(page.final_url || page.requested_url)" :href="sourceUrl(page.final_url || page.requested_url) || undefined" target="_blank" rel="noopener noreferrer">Open page ↗</a></article>
        </div>
        <p v-else class="empty-state">No evidence sources are available yet.</p>
      </section>

      <section class="intel-section">
        <div class="section-heading"><h3>Lead score breakdown</h3><span class="advisory-chip">Human decision required</span></div>
        <article v-for="score in intelligence.scores ?? []" :key="score.id" class="score-row">
          <b>{{ score.score === null ? 'Insufficient evidence' : `${score.score} / 100` }}</b>
          <small>Coverage {{ score.evidence_coverage ?? '—' }}% · rule version {{ score.rule_version }}</small>
          <details><summary>Review score evidence</summary><dl><template v-for="row in componentRows(score.components)" :key="row.label"><dt>{{ row.label }}</dt><dd><span class="score-status" :class="row.status.toLowerCase()">{{ row.statusLabel }}</span><b>{{ row.value }}</b><small v-if="row.evidence">{{ row.evidence }}</small></dd></template></dl></details>
        </article>
        <p v-if="!intelligence.scores?.length" class="empty-state">No advisory score is available yet.</p>
      </section>
    </template>
    <div v-else class="intel-state"><b>No Website Intelligence yet</b><span>Run Website Intelligence after a completed website scan to review evidence-backed findings.</span></div>
  </section>
</template>

<style scoped>
.intelligence-workspace{display:grid;gap:16px;min-width:0}.intel-heading,.section-heading,.finding-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.intel-heading h2{margin:2px 0;font-size:21px}.intel-heading p,.section-heading p{margin:4px 0 0;color:#68778c;font-size:12px}.intel-actions{display:flex;gap:8px}.scan-status{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;background:#f6f8fb;border:1px solid #e3e8ef;border-radius:10px}.scan-status span,.scan-status small{color:#68778c;font-size:12px}.scan-status b{font-size:13px;text-transform:capitalize}.intel-empty-report{display:grid;gap:4px;padding:14px 16px;border:1px solid #e8eaf0;border-radius:10px;background:#fbfcfe}.intel-empty-report b{font-size:13px;color:#34445c}.intel-empty-report span{font-size:12px;color:#718096;line-height:1.5}.intel-section{display:grid;gap:10px;padding:16px;border:1px solid #e3e8ef;border-radius:12px;background:#fff;min-width:0}.intel-section h3{margin:0;font-size:15px}.intel-section h4{margin:8px 0 0;font-size:13px}.intel-section p{margin:0;color:#43536b;font-size:13px;line-height:1.6}.intel-section>small{color:#68778c;font-size:11px}.summary-section{border-left:3px solid #6554a4}.advisory-chip,.status-chip{display:inline-flex;align-items:center;border-radius:20px;padding:4px 8px;background:#f3f0fb;color:#5b4b96;font-size:10px;font-weight:700;white-space:nowrap}.status-chip{background:#f5f6f8;color:#59677a}.finding-list,.evidence-list{display:grid;gap:8px}.finding,.evidence-list article,.score-row{display:grid;gap:6px;padding:12px;border:1px solid #edf0f4;border-radius:9px;min-width:0}.finding b,.evidence-list b{font-size:13px;color:#26364c}.finding small,.evidence-list small,.score-row>small{font-size:11px;color:#68778c}.finding blockquote,.evidence-list blockquote{margin:2px 0;padding:8px 10px;border-left:2px solid #c9d4e6;background:#f8f9fb;color:#4e5d72;font-size:12px;line-height:1.55}.finding a,.evidence-list a{font-size:11px;color:#245bd6;overflow-wrap:anywhere}.technology-list{display:flex;flex-wrap:wrap;gap:7px;align-items:center}.technology-list h4{width:100%}.technology-list span{padding:5px 8px;border:1px solid #e3e8ef;border-radius:18px;background:#f8f9fb;font-size:11px}.unknown-section{background:#fffaf0;border-color:#f0e2c5}.unknown-section ul{margin:0;padding-left:20px;color:#526176;font-size:12px}.score-row>b{font-size:16px;color:#26364c}.score-row details summary,.finding details summary{cursor:pointer;color:#53657d;font-size:11px;font-weight:650}.score-row dl{display:grid;grid-template-columns:minmax(120px,1fr) 2fr;gap:5px 12px;margin:8px 0}.score-row dt{color:#68778c;font-size:11px;text-transform:capitalize}.score-row dd{margin:0;font-size:11px;color:#2f4057;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.score-row dd small{display:block;flex-basis:100%;margin-top:3px;color:#718097}.score-status{padding:3px 7px;border-radius:12px;background:#eef2f7;color:#586a82;font-size:10px}.score-status.positive{background:#e8f4ed;color:#24744f}.score-status.negative{background:#edf2f7;color:#51647d}.score-status.unknown,.score-status.not_configured{background:#f4f5f7;color:#6e7888}.empty-state,.intel-state{color:#718097;font-size:12px}.intel-state{display:grid;gap:4px;padding:22px;background:#f8f9fb;border-radius:10px}.screenshot-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.screenshot-grid figure{margin:0}.screenshot-grid img{width:100%;border:1px solid #e3e8ef;border-radius:9px}.screenshot-grid figcaption{color:#68778c;font-size:10px}@media(max-width:560px){.intel-heading h2{font-size:19px}.intel-actions{width:100%}.intel-actions button{flex:1}.intel-section{padding:13px}.score-row dl{grid-template-columns:1fr}.score-row dd{margin-bottom:6px}}
.matched-commercial-services{display:grid;gap:4px;padding:9px 10px;border-radius:8px;background:#f7f8fb}.matched-commercial-services span{color:#34445f;font-size:11px}
.evidence-group-section{display:grid;gap:8px;min-width:0}.evidence-group-section h4{margin:4px 0 0;font-size:13px;color:#33445e}.evidence-group{display:grid;gap:7px;padding:12px;border:1px solid #e8edf3;border-radius:9px;min-width:0}.evidence-group>small{color:#68778c;font-size:11px}.category-chip,.severity-chip{display:inline-flex;align-items:center;border-radius:16px;padding:4px 8px;background:#f1f4f8;color:#586981;font-size:10px;font-weight:700;text-transform:capitalize}.issue-group{border-left:3px solid #d69a32}.issue-group .severity-chip{background:#fff4df;color:#895d17}.source-details summary{cursor:pointer;color:#3159a6;font-size:11px;font-weight:650}.source-list{display:grid;gap:8px;margin:8px 0 0;padding:0;list-style:none}.source-list li{display:grid;gap:4px;padding:8px 10px;border-radius:7px;background:#f8f9fb;min-width:0}.source-list a,.source-list span{font-size:11px;color:#3159a6;overflow-wrap:anywhere}.source-list small{font-size:10px;color:#68778c;line-height:1.5;overflow-wrap:anywhere}.ai-technical-evidence{border-left:3px solid #6554a4}
@media(max-width:560px){.evidence-group{padding:10px}.source-list li{padding:8px}.finding-title{align-items:flex-start}}
</style>
