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

function componentRows(value: unknown): Array<{ label: string; value: string; evidence: string }> {
  let parsed = value
  if (typeof parsed === 'string') {
    try { parsed = JSON.parse(parsed) } catch { return [] }
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return []
  return Object.entries(parsed as RecordValue).map(([key, raw]) => {
    const detail = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw as RecordValue : {}
    const result = typeof raw === 'number' || typeof raw === 'string'
      ? raw
      : detail.points ?? detail.score ?? detail.value ?? detail.status ?? 'Not recorded'
    return {
      label: key.replaceAll('_', ' '),
      value: typeof result === 'boolean' ? (result ? 'Yes' : 'No') : String(result),
      evidence: String(detail.evidence ?? detail.reason ?? ''),
    }
  })
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
      <div class="scan-status"><span>Latest scan</span><b>{{ intelligence.latest_scan?.status || 'Not scanned' }}</b><small>{{ intelligence.latest_scan?.created_at || 'No scan has been started.' }}</small></div>
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

      <section v-if="report?.service_recommendations?.length" class="intel-section">
        <div class="section-heading"><h3>AI service suggestions</h3><span class="advisory-chip">Human selection required</span></div>
        <article v-for="(item, index) in report.service_recommendations" :key="`recommendation-${index}`" class="finding">
          <b>{{ item.service_key?.replaceAll('_', ' ') || 'Service suggestion' }}</b><small>{{ item.speculative ? 'Speculative · discovery needed' : (item.recommendation_strength || 'Evidence linked') }} · AI suggestion · not approved</small>
          <p>{{ item.rationale || item.recommendation }}</p><small v-if="item.discovery_question">Discovery question: {{ item.discovery_question }}</small><small v-if="item.recommended_next_action">Next action: {{ item.recommended_next_action }}</small>
        </article>
      </section>

      <section v-if="report?.opportunities?.length" class="intel-section">
        <h3>Potential opportunities</h3>
        <article v-for="(item, index) in report.opportunities" :key="`opportunity-${index}`" class="finding">
          <b>{{ item.statement }}</b><small>Potential opportunity · advisory · {{ Math.round(Number(item.confidence ?? 0) * 100) }}% confidence</small>
          <blockquote v-if="evidenceFor(item.evidence_id)?.excerpt">“{{ evidenceFor(item.evidence_id)?.excerpt }}”</blockquote>
          <a v-if="sourceUrl(evidenceFor(item.evidence_id)?.source_url)" :href="sourceUrl(evidenceFor(item.evidence_id)?.source_url) || undefined" target="_blank" rel="noopener noreferrer">Open evidence source ↗</a>
        </article>
      </section>

      <section class="intel-section">
        <div class="section-heading"><h3>Technical observations</h3><span class="advisory-chip">AI advisory</span></div>
        <div v-if="intelligence.issues?.length" class="finding-list">
          <article v-for="issue in intelligence.issues" :key="issue.id" class="finding">
            <div class="finding-title"><b>{{ issue.summary }}</b><span class="status-chip">{{ issue.severity }}</span></div>
            <small>{{ issue.type?.replaceAll('_', ' ') || 'Website finding' }} · {{ Math.round(Number(issue.confidence ?? 0) * 100) }}% confidence</small>
            <details v-if="issue.evidence"><summary>Inspect evidence</summary>
              <blockquote>{{ issueEvidence(issue.evidence).excerpt || 'A source excerpt was not recorded.' }}</blockquote>
              <a v-if="sourceUrl(issueEvidence(issue.evidence).source_url)" :href="sourceUrl(issueEvidence(issue.evidence).source_url) || undefined" target="_blank" rel="noopener noreferrer">Open evidence source ↗</a>
            </details>
          </article>
        </div>
        <p v-else class="empty-state">No technical observations were recorded for this scan.</p>
        <div v-if="(intelligence.technologies?.length || report?.technical_findings?.length)" class="technology-list">
          <h4>Detected technologies</h4><span v-for="item in (intelligence.technologies ?? report?.technical_findings ?? [])" :key="item.id || item.summary">{{ item.name || item.summary }}</span>
        </div>
      </section>

      <section v-if="report?.unknowns?.length" class="intel-section unknown-section">
        <h3>Missing evidence and unknowns</h3><ul><li v-for="(unknown, index) in report.unknowns" :key="index">{{ unknown }}</li></ul>
      </section>

      <section class="intel-section">
        <h3>Evidence sources</h3>
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
        <div class="section-heading"><h3>AI advisory score</h3><span class="advisory-chip">Human decision required</span></div>
        <article v-for="score in intelligence.scores ?? []" :key="score.id" class="score-row">
          <b>{{ score.score === null ? 'Insufficient evidence' : `${score.score} / 100` }}</b>
          <small>Coverage {{ score.evidence_coverage ?? '—' }}% · rule version {{ score.rule_version }}</small>
          <details><summary>Review score evidence</summary><dl><template v-for="row in componentRows(score.components)" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value }}<small v-if="row.evidence">{{ row.evidence }}</small></dd></template></dl></details>
        </article>
        <p v-if="!intelligence.scores?.length" class="empty-state">No advisory score is available yet.</p>
      </section>
    </template>
    <div v-else class="intel-state"><b>No intelligence report available</b><span>Select a prospect with a completed scan or run a scan to continue.</span></div>
  </section>
</template>

<style scoped>
.intelligence-workspace{display:grid;gap:16px;min-width:0}.intel-heading,.section-heading,.finding-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.intel-heading h2{margin:2px 0;font-size:21px}.intel-heading p,.section-heading p{margin:4px 0 0;color:#68778c;font-size:12px}.intel-actions{display:flex;gap:8px}.scan-status{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;background:#f6f8fb;border:1px solid #e3e8ef;border-radius:10px}.scan-status span,.scan-status small{color:#68778c;font-size:12px}.scan-status b{font-size:13px;text-transform:capitalize}.intel-section{display:grid;gap:10px;padding:16px;border:1px solid #e3e8ef;border-radius:12px;background:#fff;min-width:0}.intel-section h3{margin:0;font-size:15px}.intel-section h4{margin:8px 0 0;font-size:13px}.intel-section p{margin:0;color:#43536b;font-size:13px;line-height:1.6}.intel-section>small{color:#68778c;font-size:11px}.summary-section{border-left:3px solid #6554a4}.advisory-chip,.status-chip{display:inline-flex;align-items:center;border-radius:20px;padding:4px 8px;background:#f3f0fb;color:#5b4b96;font-size:10px;font-weight:700;white-space:nowrap}.status-chip{background:#f5f6f8;color:#59677a}.finding-list,.evidence-list{display:grid;gap:8px}.finding,.evidence-list article,.score-row{display:grid;gap:6px;padding:12px;border:1px solid #edf0f4;border-radius:9px;min-width:0}.finding b,.evidence-list b{font-size:13px;color:#26364c}.finding small,.evidence-list small,.score-row>small{font-size:11px;color:#68778c}.finding blockquote,.evidence-list blockquote{margin:2px 0;padding:8px 10px;border-left:2px solid #c9d4e6;background:#f8f9fb;color:#4e5d72;font-size:12px;line-height:1.55}.finding a,.evidence-list a{font-size:11px;color:#245bd6;overflow-wrap:anywhere}.technology-list{display:flex;flex-wrap:wrap;gap:7px;align-items:center}.technology-list h4{width:100%}.technology-list span{padding:5px 8px;border:1px solid #e3e8ef;border-radius:18px;background:#f8f9fb;font-size:11px}.unknown-section{background:#fffaf0;border-color:#f0e2c5}.unknown-section ul{margin:0;padding-left:20px;color:#526176;font-size:12px}.score-row>b{font-size:16px;color:#26364c}.score-row details summary,.finding details summary{cursor:pointer;color:#53657d;font-size:11px;font-weight:650}.score-row dl{display:grid;grid-template-columns:minmax(120px,1fr) 2fr;gap:5px 12px;margin:8px 0}.score-row dt{color:#68778c;font-size:11px;text-transform:capitalize}.score-row dd{margin:0;font-size:11px;color:#2f4057}.score-row dd small{display:block;margin-top:3px;color:#718097}.empty-state,.intel-state{color:#718097;font-size:12px}.intel-state{display:grid;gap:4px;padding:22px;background:#f8f9fb;border-radius:10px}.screenshot-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.screenshot-grid figure{margin:0}.screenshot-grid img{width:100%;border:1px solid #e3e8ef;border-radius:9px}.screenshot-grid figcaption{color:#68778c;font-size:10px}@media(max-width:560px){.intel-heading h2{font-size:19px}.intel-actions{width:100%}.intel-actions button{flex:1}.intel-section{padding:13px}.score-row dl{grid-template-columns:1fr}.score-row dd{margin-bottom:6px}}
</style>
