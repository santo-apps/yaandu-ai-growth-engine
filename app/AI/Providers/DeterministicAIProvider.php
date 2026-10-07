<?php

namespace App\AI\Providers;

use App\AI\AIProviderInterface;
use App\AI\AIRequest;
use App\AI\AIResponse;
use Illuminate\Support\Arr;
use LogicException;
use RuntimeException;

/** Deterministic local-acceptance responses. Never a production AI integration. */
final class DeterministicAIProvider implements AIProviderInterface
{
    public function __construct()
    {
        if (! app()->environment(['local', 'testing']) || ! config('ai.local_acceptance.enabled')) {
            throw new LogicException('Deterministic AI is available only when explicitly enabled in local/testing.');
        }
    }

    public function providerKey(): string { return 'deterministic'; }
    public function capabilities(): array { return ['structured_json']; }

    public function generate(AIRequest $request, string $model): AIResponse
    {
        $data = match ($request->task) {
            'website_reasoning' => $this->website($request),
            'content_generation' => $this->marketing($request),
            'sales_reasoning' => $this->sales($request),
            'proposal_generation' => $this->proposal($request),
            default => throw new RuntimeException('Deterministic AI does not support task ['.$request->task.'].') ,
        };

        return new AIResponse($data, $this->providerKey(), $model);
    }

    private function website(AIRequest $request): array
    {
        $page = $request->evidence[0] ?? [];
        $url = (string) ($page['url'] ?? 'https://northstar-retail.fixture.test');
        $text = (string) ($page['text'] ?? '');
        $issues = [];
        if (stripos($text, 'older storefront layout') !== false) {
            $issues[] = ['type' => 'outdated_website', 'summary' => 'The site describes an older storefront layout.', 'source_url' => $url, 'evidence' => 'older storefront layout', 'severity' => 'high', 'confidence' => 0.94];
        }
        if (stripos($text, 'mobile navigation is difficult') !== false) {
            $issues[] = ['type' => 'poor_mobile_ux', 'summary' => 'The page describes difficult mobile navigation.', 'source_url' => $url, 'evidence' => 'Mobile navigation is difficult', 'severity' => 'medium', 'confidence' => 0.92];
        }
        $insights = stripos($text, 'retail ecommerce business') !== false
            ? [['statement' => 'The page identifies a retail ecommerce business.', 'kind' => 'business_fit', 'source_url' => $url, 'evidence' => 'Fictional retail ecommerce business', 'confidence' => 0.93]] : [];

        return ['summary' => 'Local deterministic analysis based only on the supplied fictional page evidence.',
            'issues' => $issues, 'technologies' => [], 'insights' => $insights, 'contacts' => []];
    }

    private function marketing(AIRequest $request): array
    {
        $evidence = Arr::get($request->evidence, 'prospect.evidence', []);
        $references = array_values(array_filter(array_map(static fn ($item) => $item['id'] ?? null, $evidence)));
        $knowledge = Arr::get($request->evidence, 'approved_yaandu_knowledge', []);
        $references = array_values(array_unique([...$references, ...array_filter(array_map(static fn ($item) => $item['id'] ?? null, $knowledge))]));
        $name = (string) (Arr::get($request->evidence, 'prospect.company.name') ?? 'Northstar Retail Systems Pvt Ltd');

        return ['subject' => 'A mobile-first modernization idea for Northstar',
            'message' => "Hi Ananya,\n\nI reviewed the public Northstar storefront and noticed its older layout and difficult mobile navigation. Those details can make it harder for shoppers to move from browsing to a confident enquiry.\n\nYaandu helps retail teams modernize ecommerce experiences, improve performance, and explore AI-enabled customer engagement. Would a short discussion about your modernization priorities be useful?\n\nRegards,\nYaandu Growth Team",
            'reasoning_summary' => 'Local acceptance copy grounded in the fictional prospect website evidence and approved Yaandu service knowledge for '.$name.'.',
            'personalization_points' => ['Older storefront layout observed on the public site', 'Mobile navigation is difficult'],
            'evidence_references' => $references, 'confidence' => 0.94,
            'recommended_call_to_action' => 'Invite the prospect to a short, human-reviewed discussion.'];
    }

    private function sales(AIRequest $request): array
    {
        $messages = Arr::get($request->evidence, 'conversation', []);
        // ConversationIntentClassifier uses a distinct, strict schema from SalesAgent.
        // Keep the local fixture faithful to that public contract.
        if (array_key_exists('summary', $request->outputSchema['properties'] ?? [])) {
            $messages = Arr::get($request->evidence, 'messages', []);
            $lastInbound = null;
            foreach ($messages as $message) {
                if (($message['direction'] ?? null) === 'inbound') $lastInbound = $message;
            }
            $body = mb_strtolower((string) ($lastInbound['body'] ?? ''));
            $unsubscribed = str_contains($body, 'unsubscribe') || str_contains($body, 'stop emailing') || str_contains($body, 'stop contacting');
            $intent = $unsubscribed ? 'unsubscribe' : 'interested';

            return [
                'intent' => $intent,
                'confidence' => 0.97,
                'summary' => $unsubscribed
                    ? 'The prospect asked to stop receiving outreach.'
                    : 'The prospect expressed interest in discussing the modernization opportunity.',
                'reason' => $unsubscribed
                    ? 'The newest inbound message explicitly asks to stop further outreach.'
                    : 'The newest inbound message expresses interest; persisted scheduling state remains authoritative for meeting actions.',
                'risk' => 'none',
                'evidence_references' => [],
            ];
        }
        if (array_key_exists('qualification_evidence', $request->outputSchema['properties'] ?? [])) {
            $conversation = Arr::get($request->evidence, 'prospect_context_untrusted.conversation.messages', []);
            $inboundIds = [];
            foreach ($conversation as $message) if (($message['direction'] ?? null) === 'inbound' && isset($message['id'])) $inboundIds[] = $message['id'];
            $website = Arr::get($request->evidence, 'prospect_context_untrusted.verified_company_evidence.0.id');
            $reference = static fn (?string $id): array => $id ? [$id] : [];
            return ['intent' => 'INTERESTED', 'confidence' => 0.96,
                'qualification' => ['NEED' => 'STRONG', 'FIT' => 'STRONG', 'AUTHORITY' => 'UNKNOWN', 'TIMELINE' => 'STRONG', 'BUDGET' => 'UNKNOWN'],
                'qualification_evidence' => ['NEED' => $reference($inboundIds[array_key_last($inboundIds)] ?? null), 'FIT' => $reference($website),
                    'AUTHORITY' => [], 'TIMELINE' => $reference($inboundIds[array_key_last($inboundIds)] ?? null), 'BUDGET' => []],
                'missing_information' => ['Who else is involved in the decision?', 'Is a budget already allocated?'],
                'recommended_action' => 'DRAFT_RESPONSE', 'requires_human_review' => false,
                'draft_response' => 'Thanks for sharing your modernization priorities. We can discuss the storefront and mobile experience, then outline options for your team to review.',
                'evidence_references' => $reference($inboundIds[array_key_last($inboundIds)] ?? null), 'knowledge_references' => [],
                'reasoning_summary' => 'The fictional prospect expressed interest in modernization and requested a meeting and proposal.'];
        }

        $last = is_array($messages) && $messages !== [] ? end($messages) : [];
        $body = mb_strtolower((string) ($last['body'] ?? ''));
        $intent = str_contains($body, 'unsubscribe') || str_contains($body, 'stop emailing') ? 'unsubscribe' : 'interested';
        return ['intent' => $intent, 'confidence' => 0.97,
            'reasoning_summary' => $intent === 'interested' ? 'The prospect asked to discuss modernization and requested a meeting and proposal.' : 'The prospect asked to stop receiving outreach.',
            'draft_message' => $intent === 'interested' ? 'Thanks, Ananya. We can discuss the modernization goals you outlined and prepare a grounded scope for review.' : '',
            'evidence_references' => isset($last['id']) ? [$last['id']] : []];
    }

    private function proposal(AIRequest $request): array
    {
        $services = Arr::get($request->evidence, 'approved_tenant_services', []);
        $requestedServices = array_map(static fn ($name) => mb_strtolower(trim((string) $name), 'UTF-8'),
            Arr::get($request->evidence, 'prospect_data_untrusted.requirements.requested_services', []));
        $service = null;
        foreach ($services as $candidate) {
            if (is_array($candidate) && in_array(mb_strtolower(trim((string) ($candidate['name'] ?? '')), 'UTF-8'), $requestedServices, true)) {
                $service = $candidate;
                break;
            }
        }
        $service ??= $services[0] ?? null;
        if (! is_array($service) || empty($service['id'])) throw new RuntimeException('Proposal fixture requires an approved tenant service.');
        $knowledge = Arr::get($request->evidence, 'approved_tenant_knowledge', []);
        $knowledgeId = $knowledge[0]['id'] ?? null;
        $evidence = Arr::get($request->evidence, 'prospect_data_untrusted.evidence', []);
        $evidenceId = $evidence[0]['id'] ?? null;
        $deliverables = array_values(array_slice($service['standard_deliverables'] ?? [], 0, 3));

        return ['executive_summary' => 'A focused engagement to modernize Northstar Retail Systems’ ecommerce experience and improve its mobile buying journey.',
            'client_understanding' => 'Northstar is exploring storefront modernization and wants a smoother mobile experience, with room to assess performance and customer engagement opportunities.',
            'objectives' => ['Modernize the ecommerce storefront', 'Improve mobile usability', 'Identify performance and customer engagement opportunities'],
            'recommended_solution' => [$service['id']],
            'scope' => [['service_id' => $service['id'], 'description' => $service['description'] ?? $service['name'], 'deliverables' => $deliverables]],
            'deliverables' => $deliverables, 'assumptions' => ['Final priorities will be confirmed with Northstar.'],
            'dependencies' => ['Northstar will provide relevant storefront and analytics context for discovery.'],
            'exclusions' => ['Third-party platform and media fees are excluded.'],
            'implementation_approach' => ['Review storefront and mobile journeys.', 'Prioritize modernization and performance opportunities.', 'Present customer-engagement options for human review.'],
            'timeline_narrative' => 'Schedule and milestones will be agreed after discovery.',
            'commercial_narrative' => 'Commercials are calculated from the approved service catalogue and require human review.',
            'case_study_references' => [], 'evidence_references' => $evidenceId ? [$evidenceId] : [],
            'knowledge_references' => $knowledgeId ? [$knowledgeId] : [], 'risks' => ['Recommendations depend on platform and analytics context confirmed during discovery.'],
            'next_steps' => ['Review the recommended scope and catalogue-based commercials.', 'Confirm discovery participants and priorities.']];
    }
}
