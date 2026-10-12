export type LeadPriority = {
  key: 'high' | 'medium' | 'low_confidence' | 'blocked' | 'review'
  label: 'High Priority' | 'Medium Priority' | 'Low Confidence' | 'Blocked / Unscored' | 'Needs Review'
  confidence: 'high' | 'moderate' | 'low' | 'insufficient'
  confidenceLabel: 'High evidence' | 'Moderate evidence' | 'Low evidence' | 'Insufficient evidence'
  score: number | null
  coverage: number | null
  opportunitySignalCount: number
  reasons: string[]
  failureReason: string | null
}

const OPPORTUNITY_DIMENSIONS = new Set([
  'outdated_website', 'poor_mobile_ux', 'poor_lead_capture', 'no_whatsapp', 'no_crm',
  'technology_opportunity', 'strong_business_fit',
])
const FIT_DIMENSIONS = new Set(['icp_fit', 'relevant_industry'])
const SAFE_FAILURE_REASONS: Record<string, string> = {
  ROBOTS: 'Crawl permission could not be verified from robots.txt.',
  HTTP_4XX: 'The website returned a client error response.',
  HTTP_5XX: 'The website returned a server error response.',
  DNS: 'The website host could not be resolved.',
  TLS: 'A secure connection to the website could not be established.',
  REDIRECT_POLICY: 'The website redirect did not satisfy crawl safety policy.',
  SSRF_POLICY: 'The website destination did not satisfy public-network safety policy.',
  TIMEOUT: 'The website did not respond within the crawl time budget.',
  UNSUPPORTED_CONTENT: 'The website did not provide supported page content.',
  RENDER_FAILURE: 'The optional browser rendering step could not be completed.',
  PARSER_FAILURE: 'The page content could not be parsed safely.',
}

function objectValue(value: unknown): Record<string, any> | null {
  if (typeof value === 'string') {
    try { value = JSON.parse(value) } catch { return null }
  }
  return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, any> : null
}

function positiveDimension(value: unknown): boolean {
  const component = objectValue(value)
  if (component) {
    const status = String(component.status ?? '').toUpperCase()
    if (status) return status === 'POSITIVE'
    return Number(component.raw_points ?? component.points ?? 0) > 0
  }
  return typeof value === 'number' && value > 0
}

export function deriveLeadPriority(scoreRecord: any, latestScan: any = null): LeadPriority {
  const scoreValue = scoreRecord?.score
  const score = scoreValue === null || scoreValue === undefined || scoreValue === '' || !Number.isFinite(Number(scoreValue))
    ? null : Number(scoreValue)
  const rawCoverage = scoreRecord?.evidence_coverage
  const coverage = rawCoverage === null || rawCoverage === undefined || rawCoverage === '' || !Number.isFinite(Number(rawCoverage))
    ? null : Number(rawCoverage)
  const components = objectValue(scoreRecord?.components) ?? {}
  const positiveOpportunityDimensions = [...OPPORTUNITY_DIMENSIONS].filter((key) => positiveDimension(components[key]))
  const positiveFit = [...FIT_DIMENSIONS].some((key) => positiveDimension(components[key]))
  const opportunitySignalCount = positiveOpportunityDimensions.length

  if (score === null) {
    const category = String(latestScan?.failure_category ?? latestScan?.error_code ?? '').toUpperCase()
    const failureReason = SAFE_FAILURE_REASONS[category] ?? null
    return {
      key: 'blocked', label: 'Blocked / Unscored', confidence: 'insufficient', confidenceLabel: 'Insufficient evidence',
      score: null, coverage, opportunitySignalCount, reasons: [failureReason ?? 'There is not enough verified evidence to produce a score.'], failureReason,
    }
  }

  const confidence = coverage === null ? 'insufficient' : coverage >= 60 ? 'high' : coverage >= 40 ? 'moderate' : coverage >= 30 ? 'low' : 'insufficient'
  const confidenceLabel = confidence === 'high' ? 'High evidence' : confidence === 'moderate' ? 'Moderate evidence'
    : confidence === 'low' ? 'Low evidence' : 'Insufficient evidence'
  const reasons: string[] = []
  if (coverage !== null) reasons.push(`Evidence coverage is ${coverage}%.`)

  if (coverage !== null && coverage < 40) {
    reasons.push('The score has low evidence coverage and should be reviewed cautiously.')
    return { key: 'low_confidence', label: 'Low Confidence', confidence, confidenceLabel, score, coverage, opportunitySignalCount, reasons, failureReason: null }
  }

  if (score >= 65 && coverage !== null && coverage >= 50 && opportunitySignalCount > 0) {
    reasons.push(`${opportunitySignalCount} positive opportunity signal${opportunitySignalCount === 1 ? ' was' : 's were'} observed.`)
    return { key: 'high', label: 'High Priority', confidence, confidenceLabel, score, coverage, opportunitySignalCount, reasons, failureReason: null }
  }

  if (score >= 50 && coverage !== null && coverage >= 40 && (opportunitySignalCount > 0 || positiveFit)) {
    reasons.push(opportunitySignalCount > 0 ? `${opportunitySignalCount} positive opportunity signal${opportunitySignalCount === 1 ? ' was' : 's were'} observed.` : 'Positive ICP or industry fit is supported by the available evidence.')
    return { key: 'medium', label: 'Medium Priority', confidence, confidenceLabel, score, coverage, opportunitySignalCount, reasons, failureReason: null }
  }

  reasons.push('The current score and evidence do not meet a priority rule.')
  return { key: 'review', label: 'Needs Review', confidence, confidenceLabel, score, coverage, opportunitySignalCount, reasons, failureReason: null }
}
