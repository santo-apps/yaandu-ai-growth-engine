import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import ts from 'typescript'

const source = await readFile(new URL('../src/leadPriority.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2022, target: ts.ScriptTarget.ES2022 } }).outputText
const { deriveLeadPriority } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
const scored = (score, evidence_coverage, components = {}) => ({ score, evidence_coverage, components })
const positive = (status = 'POSITIVE') => ({ status, raw_points: status === 'POSITIVE' ? 10 : 0 })

assert.equal(deriveLeadPriority(scored(67, 60, { outdated_website: positive() })).label, 'High Priority')
assert.equal(deriveLeadPriority(scored(67, 60, { strong_business_fit: positive() })).label, 'High Priority')
assert.equal(deriveLeadPriority(scored(60, 50, { poor_mobile_ux: positive() })).label, 'Medium Priority')
assert.equal(deriveLeadPriority(scored(67, 30, { outdated_website: positive() })).label, 'Low Confidence')

for (const failure_category of ['ROBOTS', 'HTTP_4XX']) {
  const blocked = deriveLeadPriority(null, { status: 'failed', failure_category, safe_error_summary: 'raw private exception must not render' })
  assert.equal(blocked.label, 'Blocked / Unscored')
  assert.match(blocked.failureReason, /robots\.txt|client error/)
  assert.ok(!JSON.stringify(blocked).includes('raw private exception'))
}

assert.equal(deriveLeadPriority(scored(67, 60, { outdated_website: positive('UNKNOWN') })).opportunitySignalCount, 0)
assert.equal(deriveLeadPriority(scored(67, 60, { no_whatsapp: positive('NEGATIVE') })).opportunitySignalCount, 0)
assert.equal(deriveLeadPriority(scored(67, 60, { poor_lead_capture: positive('NEGATIVE') })).opportunitySignalCount, 0)
assert.equal(deriveLeadPriority(scored(67, 60, { icp_fit: positive(), relevant_industry: positive() })).label, 'Medium Priority')
assert.notEqual(deriveLeadPriority(scored(67, 60, { icp_fit: positive() })).label, 'High Priority')
assert.notEqual(deriveLeadPriority(scored(67, 60, { relevant_industry: positive() })).label, 'High Priority')
assert.equal(deriveLeadPriority(scored(55, 40, { relevant_industry: positive() })).label, 'Medium Priority')

const original = scored(67, 60, { outdated_website: positive(), icp_fit: positive() })
const snapshot = structuredClone(original)
const derived = deriveLeadPriority(original)
assert.deepEqual(original, snapshot, 'Priority derivation does not mutate scoring data.')
assert.equal(derived.score, 67)
assert.equal(derived.coverage, 60)
assert.equal(derived.confidenceLabel, 'High evidence')
assert.equal(deriveLeadPriority(scored(67, 50)).opportunitySignalCount, 0)

const examples = {
  'The Home': deriveLeadPriority(scored(67, 60, { technology_opportunity: positive() })),
  Ebarza: deriveLeadPriority(scored(67, 60, { strong_business_fit: positive() })),
  'Full Flat': deriveLeadPriority(scored(60, 50, { poor_mobile_ux: positive() })),
  'OC HOME': deriveLeadPriority(scored(60, 50, { poor_lead_capture: positive() })),
  'Bo Concept': deriveLeadPriority(scored(67, 30, { outdated_website: positive() })),
  Danube: deriveLeadPriority(null, { status: 'failed', failure_category: 'ROBOTS' }),
  Homecentre: deriveLeadPriority(null, { status: 'failed', failure_category: 'HTTP_4XX' }),
}
assert.deepEqual(Object.fromEntries(Object.entries(examples).map(([name, result]) => [name, result.label])), {
  'The Home': 'High Priority', Ebarza: 'High Priority', 'Full Flat': 'Medium Priority', 'OC HOME': 'Medium Priority',
  'Bo Concept': 'Low Confidence', Danube: 'Blocked / Unscored', Homecentre: 'Blocked / Unscored',
})

console.log('Lead priority derivation tests passed; pure read-only derivation verified.')
