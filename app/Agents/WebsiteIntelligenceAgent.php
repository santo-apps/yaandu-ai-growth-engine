<?php

namespace App\Agents;

use App\AI\AIModelRouter;
use App\AI\AIRequest;
use App\AI\ApprovedPromptRepository;
use App\WebsiteIntelligence\PublicContactExtractor;
use App\WebsiteIntelligence\YaanduServiceTaxonomy;
use App\WebsiteIntelligence\TenantServiceCatalog;
use App\WebsiteIntelligence\WebsiteIntelligencePilotPromptV2;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class WebsiteIntelligenceAgent implements AgentInterface
{
    public function __construct(private readonly AIModelRouter $router, private readonly PublicContactExtractor $contacts, private readonly ApprovedPromptRepository $prompts,
        private readonly ?TenantServiceCatalog $serviceCatalog = null) {}
    public function name(): string { return 'WebsiteIntelligenceAgent'; }
    public function description(): string { return 'Summarize a completed public website scan with evidence-backed issues and business facts.'; }
    public function inputSchema(): array { return ['required' => ['website_scan_id']]; }
    public function outputSchema(): array
    {
        $schema = self::modelSchema();
        $schema['required'] = [...$schema['required'], 'summary', 'issues', 'technologies', 'insights', 'contacts', 'contacts_extracted', 'prompt_template_id', 'prompt_version'];
        $schema['properties'] += ['summary' => ['type' => 'string'], 'issues' => ['type' => 'array'], 'technologies' => ['type' => 'array'],
            'insights' => ['type' => 'array'], 'contacts' => ['type' => 'array'], 'contacts_extracted' => ['type' => 'integer'],
            'prompt_template_id' => ['type' => 'string'], 'prompt_version' => ['type' => 'integer'], 'next_actions' => ['type' => 'array'],
            'service_recommendations' => ['type' => 'array'], 'structured_output_metadata' => ['type' => 'object']];
        // Provider-specific version schemas are validated before normalization; the final agent envelope supports both versions.
        $schema['properties']['service_recommendations'] = ['type' => 'array'];
        return $schema;
    }
    public function tools(): array { return []; }

    public function execute(AgentContext $context, array $input): AgentResult
    {
        // Use a monotonic clock: database timestamps may be returned without a timezone
        // while the app timezone is local, which can inflate duration by the UTC offset.
        $executionStartedAtNs = hrtime(true);
        $scan = DB::table('website_scans')->where('id', $input['website_scan_id'])->where('tenant_id', $context->tenantId)->first();
        if (! $scan || $scan->status !== 'completed') throw new RuntimeException('A completed scan in this tenant is required.');
        $route = DB::table('ai_model_configurations')->where('tenant_id', $context->tenantId)->where('task_key', 'website_reasoning')->first();
        if (! $route || ! $route->enabled || ! filled($route->provider) || ! filled($route->model)) {
            throw new RuntimeException('No AI model configured for task [website_reasoning].');
        }
        $pages = DB::table('website_pages')->where('website_scan_id', $scan->id)->where('tenant_id', $context->tenantId)->limit(20)->get(['id', 'final_url', 'title', 'extracted_text', 'http_status']);
        $evidence = $pages->map(fn ($page) => ['id' => $page->id, 'url' => $page->final_url, 'title' => $page->title, 'status' => $page->http_status, 'text' => mb_substr(strip_tags((string) $page->extracted_text), 0, 6000)])->all();
        $pagesByUrl = [];
        foreach ($pages as $index => $page) $pagesByUrl[(string) $page->final_url] = ['id' => $page->id, 'text' => trim((string) $page->title.' '.$evidence[$index]['text'])];
        $prompt = $this->prompts->get($context->tenantId, $this->name(), '');
        if (trim($prompt->systemInstruction) === '' || trim($prompt->template) === '') {
            throw new RuntimeException('The approved WebsiteIntelligenceAgent prompt is incomplete.');
        }
        $approvedServices = ($this->serviceCatalog ?? app(TenantServiceCatalog::class))->recommendationServices($context->tenantId);
        $schema = $prompt->version >= 2 ? self::modelSchemaV2($approvedServices->keys()->all()) : self::modelSchema();
        $systemInstruction = $prompt->systemInstruction;
        if ($prompt->version >= 2) {
            $offerings = $approvedServices->map(fn (object $service): array => [
                'service_key' => $service->sku,
                'name' => $service->name,
                'description' => mb_substr((string) ($service->description ?? ''), 0, 500),
            ])->values()->all();
            $systemInstruction .= "\n\nTenant-approved active service offerings (the only offerings that may be recommended; map every recommendation to an exact service_key; if empty, return no service recommendations):\n".json_encode($offerings, JSON_THROW_ON_ERROR);
        }
        $response = $this->router->generate(
    new AIRequest(
        'website_reasoning',
        $systemInstruction."\n\nApproved analysis template (version {$prompt->version}):\n".$prompt->template,
        $evidence,
        $schema,
        maxOutputTokens: 3500,
        correlationId: $context->correlationId,
        tenantId: $context->tenantId,
    )
        );
        $structured = $response->data;
        if (! is_numeric($structured['confidence'] ?? null) || $structured['confidence'] < 0 || $structured['confidence'] > 1) {
            throw new RuntimeException('Website intelligence confidence is outside the required schema range.');
        }
        $issues = [];
        $technologies = [];
        foreach ($structured['technical_findings'] ?? [] as $finding) {
            $citation = $this->verifiedPageCitation($finding, $pagesByUrl);
            if (! $citation) continue;
            if (($finding['type'] ?? null) === 'technology') {
                $technologies[] = ['name' => $finding['summary'], 'category' => 'observed', 'confidence' => $finding['confidence'], ...$citation];
            } else {
                $issues[] = ['type' => $finding['type'], 'summary' => $finding['summary'], 'severity' => $finding['severity'], 'confidence' => $finding['confidence'], 'source_url' => $citation['url'], 'evidence' => $citation['excerpt']];
            }
        }
        $insights = [];
        $validatedEvidence = [];
        foreach ([['items' => $structured['observations'] ?? [], 'kind' => 'observation'], ['items' => $structured['opportunities'] ?? [], 'kind' => 'opportunity']] as $group) {
            foreach ($group['items'] as $item) {
                $citation = $this->verifiedPageCitation($item, $pagesByUrl);
                if (! $citation) continue;
                // Only the dedicated opportunities collection may create a scoring opportunity.
                // A model-supplied kind on an observation cannot promote it into a positive signal.
                $kind = $group['kind'] === 'opportunity' ? 'opportunity' : (in_array($item['kind'] ?? '', ['fact', 'inference'], true) ? $item['kind'] : 'inference');
                $insights[] = ['statement' => $item['statement'] ?? '', 'kind' => $kind, 'confidence' => $item['confidence'] ?? 0.5, 'source_url' => $citation['url'], 'evidence' => $citation['excerpt']];
                $validatedEvidence[$citation['page_id']] = ['evidence_id' => $citation['page_id'], 'source_url' => $citation['url'], 'excerpt' => $citation['excerpt']];
            }
        }
        foreach ($structured['service_recommendations'] ?? [] as $recommendation) {
            if ($prompt->version >= 2) continue;
            if (! is_numeric($recommendation['confidence'] ?? null) || $recommendation['confidence'] < 0 || $recommendation['confidence'] > 1) continue;
            foreach (array_slice($recommendation['evidence_ids'] ?? [], 0, 10) as $evidenceId) {
                $candidate = collect($structured['evidence'] ?? [])->first(fn ($item) => ($item['evidence_id'] ?? null) === $evidenceId);
                $citation = $candidate ? $this->verifiedPageCitation($candidate, $pagesByUrl) : null;
                if (! $citation) continue;
                $insights[] = ['statement' => mb_substr(trim($recommendation['recommendation'].' '.$recommendation['rationale']), 0, 2000), 'kind' => 'service_recommendation',
                    'confidence' => $recommendation['confidence'] ?? 0.5, 'source_url' => $citation['url'], 'evidence' => $citation['excerpt']];
                $validatedEvidence[$citation['page_id']] = ['evidence_id' => $citation['page_id'], 'source_url' => $citation['url'], 'excerpt' => $citation['excerpt']];
            }
        }
        $identityCitation = $this->verifiedPageCitation($structured['business_identity'] ?? [], $pagesByUrl);
        if (! $identityCitation) $structured['business_identity'] = ['name' => '', 'description' => '', 'evidence_id' => '', 'excerpt' => ''];
        else $validatedEvidence[$identityCitation['page_id']] = ['evidence_id' => $identityCitation['page_id'], 'source_url' => $identityCitation['url'], 'excerpt' => $identityCitation['excerpt']];
        $structured['technical_findings'] = array_values(array_filter($structured['technical_findings'] ?? [], fn ($item) => $this->verifiedPageCitation($item, $pagesByUrl) !== null));
        $structured['evidence'] = array_values($validatedEvidence);
        $structured['observations'] = array_values(array_filter($structured['observations'] ?? [], fn ($item) => $this->verifiedPageCitation($item, $pagesByUrl) !== null));
        $structured['opportunities'] = array_values(array_filter($structured['opportunities'] ?? [], fn ($item) => $this->verifiedPageCitation($item, $pagesByUrl) !== null));
        $opportunityEvidenceIds = [];
        foreach ($structured['opportunities'] as $opportunity) {
            $citation = $this->verifiedPageCitation($opportunity, $pagesByUrl);
            if ($citation) $opportunityEvidenceIds[] = $citation['page_id'];
        }
        $structured['evidence'] = array_values(array_filter($structured['evidence'] ?? [], function ($item) use (&$validatedEvidence, $pagesByUrl): bool {
            $citation = $this->verifiedPageCitation($item, $pagesByUrl);
            if (! $citation) return false;
            $validatedEvidence[$citation['page_id']] = ['evidence_id' => $citation['page_id'], 'source_url' => $citation['url'], 'excerpt' => $citation['excerpt']];
            return true;
        }));
        $recommendations = [];
        foreach ($structured['service_recommendations'] ?? [] as $recommendation) {
            // V1 has no service-key mapping contract, so it cannot safely create
            // a tenant service recommendation. V2 is constrained to the current catalog.
            if ($prompt->version < 2) continue;
            $serviceKey = (string) ($recommendation['service_key'] ?? '');
            $catalogService = $approvedServices->get($serviceKey);
            if (! $catalogService) continue;
            $evidenceIds = array_values(array_intersect($recommendation['evidence_ids'] ?? [], array_keys($validatedEvidence)));
            $linkedOpportunityEvidence = array_values(array_intersect($evidenceIds, $opportunityEvidenceIds));
            $linkedNextActionEvidence = [];
            foreach ($structured['next_actions'] ?? [] as $action) {
                if (trim((string) ($action['action'] ?? '')) === '') continue;
                $linkedNextActionEvidence = [...$linkedNextActionEvidence, ...array_intersect($evidenceIds, $action['evidence_ids'] ?? [])];
            }
            $requiredText = ['rationale', 'recommendation_strength', 'discovery_question', 'recommended_next_action'];
            $hasRequiredText = collect($requiredText)->every(fn (string $field): bool => filled($recommendation[$field] ?? null));
            $confidence = $recommendation['confidence'] ?? null;
            if (($recommendation['speculative'] ?? true) !== false || $evidenceIds === [] || $linkedOpportunityEvidence === [] || $linkedNextActionEvidence === [] || ! $hasRequiredText
                || ! is_numeric($confidence) || $confidence < 0 || $confidence > 1 || ! $catalogService->id) continue;
            $recommendations[] = [...$recommendation, 'service_key' => $serviceKey, 'service_name' => $catalogService->name,
                'tenant_service_id' => $catalogService->id, 'evidence_ids' => $evidenceIds];
            foreach ($evidenceIds as $evidenceId) {
                $citation = $validatedEvidence[$evidenceId] ?? null;
                if (! $citation) continue;
                $insights[] = ['statement' => mb_substr(trim($catalogService->name.': '.($recommendation['rationale'] ?? $recommendation['recommendation'] ?? '')), 0, 2000),
                    'kind' => 'service_recommendation', 'confidence' => $recommendation['confidence'] ?? 0.5,
                    'source_url' => $citation['source_url'], 'evidence' => $citation['excerpt']];
            }
        }
        $structured['service_recommendations'] = $recommendations;
        $structured['next_actions'] = $prompt->version >= 2
            ? array_values(array_filter($structured['next_actions'] ?? [], fn ($action) => array_intersect($action['evidence_ids'] ?? [], array_keys($validatedEvidence)) !== []))
            : [];
        $contactsExtracted = $this->contacts->extract($context->tenantId, $scan->id);
        $companyId = DB::table('company_websites')->where('id', $scan->company_website_id)->where('tenant_id', $context->tenantId)->value('company_id');
        $executionDurationMs = max(0, intdiv(hrtime(true) - $executionStartedAtNs, 1_000_000));
        $structured['next_actions'] ??= [];
        $structured['structured_output_metadata'] = ['provider' => $response->provider, 'model' => $response->model,
            'prompt_template_id' => $prompt->id, 'prompt_version' => $prompt->version, 'agent_run_id' => $context->runId,
            'correlation_id' => $context->correlationId];
        DB::transaction(function () use ($issues, $technologies, $insights, $scan, $context, $pagesByUrl, $companyId, $structured, $response, $prompt, $executionDurationMs): void {
        DB::table('lead_insights')->where('tenant_id', $context->tenantId)->where('agent_run_id', $context->runId)->delete();

        foreach (array_slice($issues, 0, 30) as $issue) {
            if (! is_array($issue) || empty($issue['type']) || empty($issue['summary'])) continue;
            $citation = $this->verifiedCitation($issue, $pagesByUrl);
            if (! $citation) continue;
            DB::table('website_issues')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $context->tenantId,
                'website_scan_id' => $scan->id, 'agent_run_id' => $context->runId, 'website_page_id' => $citation['page_id'], 'type' => mb_substr($issue['type'], 0, 100),
                'severity' => in_array($issue['severity'] ?? '', ['low','medium','high','critical'], true) ? $issue['severity'] : 'low',
                'summary' => mb_substr($issue['summary'], 0, 2000), 'evidence' => json_encode(['source_url' => $citation['url'], 'excerpt' => $citation['excerpt']]),
                'confidence' => max(0, min(1, (float) ($issue['confidence'] ?? 0.5))), 'detector_version' => 'ai-v1', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (array_slice($technologies, 0, 50) as $technology) {
            DB::table('website_technologies')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $context->tenantId, 'website_scan_id' => $scan->id,
                'agent_run_id' => $context->runId, 'name' => mb_substr($technology['name'], 0, 150), 'category' => $technology['category'], 'detection_method' => 'ai_inference',
                'confidence' => max(0, min(1, (float) $technology['confidence'])), 'website_page_id' => $technology['page_id'], 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (array_slice($insights, 0, 20) as $insight) {
            if (! is_array($insight) || empty($insight['statement'])) continue;
            $citation = $this->verifiedCitation($insight, $pagesByUrl);
            if (! $citation) continue;
            $insightId = (string) Str::uuid();
            DB::table('lead_insights')->insert(['id' => $insightId, 'tenant_id' => $context->tenantId, 'company_id' => $companyId,
                'kind' => mb_substr($insight['kind'] ?? 'business_fit', 0, 100), 'statement' => mb_substr($insight['statement'], 0, 2000),
                'confidence' => max(0, min(1, (float) ($insight['confidence'] ?? 0.5))), 'agent_run_id' => $context->runId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lead_evidence')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $context->tenantId,
                'lead_insight_id' => $insightId, 'evidence_type' => 'website_page', 'source_url' => mb_substr($citation['url'], 0, 2048),
                'excerpt' => mb_substr($citation['excerpt'], 0, 1000), 'content_hash' => hash('sha256', $citation['excerpt']), 'observed_at' => now(),
                'confidence' => max(0, min(1, (float) ($insight['confidence'] ?? 0.5))), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('website_intelligence_results')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $context->tenantId,
            'company_id' => $companyId, 'website_scan_id' => $scan->id, 'agent_run_id' => $context->runId,
            'prompt_template_id' => $prompt->id, 'prompt_version' => $prompt->version,
            'schema_version' => $prompt->version >= 2 ? WebsiteIntelligencePilotPromptV2::SCHEMA_VERSION : \App\WebsiteIntelligence\WebsiteIntelligencePilotPrompt::SCHEMA_VERSION,
            'provider' => $response->provider, 'model' => $response->model, 'correlation_id' => $context->correlationId,
            'confidence' => max(0, min(1, (float) $structured['confidence'])),
            'structured_output' => json_encode($structured, JSON_THROW_ON_ERROR), 'provider_latency_ms' => $response->latencyMs,
            'execution_duration_ms' => $executionDurationMs, 'created_at' => now(), 'updated_at' => now()]);
        });
        return new AgentResult($structured + ['summary' => $structured['business_identity']['description'] ?? '', 'issues' => $issues, 'technologies' => $technologies,
            'insights' => $insights, 'contacts' => [], 'contacts_extracted' => $contactsExtracted, 'prompt_template_id' => $prompt->id, 'prompt_version' => $prompt->version],
            'Website intelligence extracted from '.count($pages).' pages and '.$contactsExtracted.' public contact methods.', $evidence);
    }

    public static function modelSchema(): array
    {
        $claim = ['type' => 'object', 'required' => ['statement', 'kind', 'evidence_id', 'excerpt', 'confidence'], 'properties' => [
            'statement' => ['type' => 'string', 'maxLength' => 1200], 'kind' => ['type' => 'string', 'enum' => ['fact', 'inference', 'opportunity', 'service_recommendation']],
            'evidence_id' => ['type' => 'string'], 'excerpt' => ['type' => 'string', 'maxLength' => 1000], 'confidence' => ['type' => 'number'],
        ]];
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['business_identity', 'observations', 'technical_findings', 'opportunities', 'service_recommendations', 'unknowns', 'evidence', 'confidence'], 'properties' => [
            'business_identity' => ['type' => 'object', 'required' => ['name', 'description', 'evidence_id', 'excerpt'], 'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'evidence_id' => ['type' => 'string'], 'excerpt' => ['type' => 'string']]],
            'observations' => ['type' => 'array', 'items' => $claim], 'technical_findings' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['type', 'summary', 'severity', 'evidence_id', 'excerpt', 'confidence'], 'properties' => ['type' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']], 'evidence_id' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'confidence' => ['type' => 'number']]]],
            'opportunities' => ['type' => 'array', 'items' => $claim], 'service_recommendations' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['recommendation', 'rationale', 'evidence_ids', 'confidence'], 'properties' => ['recommendation' => ['type' => 'string'], 'rationale' => ['type' => 'string'], 'evidence_ids' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']], 'confidence' => ['type' => 'number']]]],
            'unknowns' => ['type' => 'array', 'items' => ['type' => 'string']],
            'evidence' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['evidence_id', 'source_url', 'excerpt'], 'properties' => [
                'evidence_id' => ['type' => 'string'], 'source_url' => ['type' => 'string'], 'excerpt' => ['type' => 'string']]]],
            'confidence' => ['type' => 'number'],
        ]];
    }

    public static function modelSchemaV2(?array $allowedServiceKeys = null): array
    {
        $schema = self::modelSchema();
        $schema['required'][] = 'next_actions';
        $recommendation = ['type' => 'object', 'required' => ['service_key', 'recommendation_strength', 'evidence_ids', 'rationale', 'confidence', 'missing_information', 'discovery_question', 'recommended_next_action', 'speculative'],
            'properties' => ['service_key' => ['type' => 'string', 'enum' => ($allowedServiceKeys === null || $allowedServiceKeys === []) ? YaanduServiceTaxonomy::keys() : $allowedServiceKeys],
                'recommendation_strength' => ['type' => 'string', 'enum' => ['strong', 'moderate', 'tentative']],
                'evidence_ids' => ['type' => 'array', 'items' => ['type' => 'string']], 'rationale' => ['type' => 'string'],
                'confidence' => ['type' => 'number'], 'missing_information' => ['type' => 'array', 'items' => ['type' => 'string']],
                'discovery_question' => ['type' => 'string'], 'recommended_next_action' => ['type' => 'string'], 'speculative' => ['type' => 'boolean']]];
        $schema['properties']['service_recommendations'] = ['type' => 'array', 'items' => $recommendation];
        $schema['properties']['next_actions'] = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['action', 'evidence_ids', 'confidence'],
            'properties' => ['action' => ['type' => 'string'], 'evidence_ids' => ['type' => 'array', 'items' => ['type' => 'string']], 'confidence' => ['type' => 'number']]]];
        return $schema;
    }

    private function verifiedPageCitation(array $claim, array $pagesByUrl): ?array
    {
        if (array_key_exists('confidence', $claim) && (! is_numeric($claim['confidence']) || $claim['confidence'] < 0 || $claim['confidence'] > 1)) return null;
        $pageId = $claim['evidence_id'] ?? null;
        foreach ($pagesByUrl as $url => $page) {
            if ($pageId !== $page['id']) continue;
            $excerpt = trim((string) ($claim['excerpt'] ?? ''));
            $normalize = static fn (string $text): string => mb_strtolower(trim(preg_replace('/\\s+/', ' ', $text) ?? $text));
            if ($excerpt !== '' && str_contains($normalize($page['text']), $normalize($excerpt))) return ['page_id' => $page['id'], 'url' => $url, 'excerpt' => mb_substr($excerpt, 0, 1000)];
        }
        return null;
    }

    /** Rechecks the normalized server-side citation before persistence. */
    private function verifiedCitation(array $claim, array $pagesByUrl): ?array
    {
        $url = isset($claim['source_url']) && is_string($claim['source_url']) ? $claim['source_url'] : '';
        $excerpt = isset($claim['evidence']) && is_string($claim['evidence']) ? trim($claim['evidence']) : '';
        if ($url === '' || $excerpt === '' || ! isset($pagesByUrl[$url])) return null;
        $normalize = static fn (string $text): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
        if (! str_contains($normalize($pagesByUrl[$url]['text']), $normalize($excerpt))) return null;
        return ['page_id' => $pagesByUrl[$url]['id'], 'url' => $url, 'excerpt' => mb_substr($excerpt, 0, 1000)];
    }

}
