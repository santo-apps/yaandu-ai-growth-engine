<?php

namespace Tests\Fakes;

use App\AI\AIProviderInterface;
use App\AI\AIRequest;
use App\AI\AIResponse;

/**
 * Local visual-acceptance fixture only. It is not registered by the application
 * and never makes network requests.
 */
final class VisualAcceptanceAIProvider implements AIProviderInterface
{
    public function __construct(private readonly string $serviceId, private readonly string $evidenceId, private readonly string $knowledgeId) {}

    public function providerKey(): string { return 'conversation-test'; }
    public function capabilities(): array { return ['structured_json']; }

    public function generate(AIRequest $request, string $model): AIResponse
    {
        $data = match ($request->task) {
            'content_generation' => [
                'subject' => 'A clearer enquiry journey for Acme Test Outfitters',
                'message' => "Hi Riley,\n\nI noticed the sample contact page asks visitors to submit a form but does not explain what happens next. A short confirmation and clearer next step could make the enquiry journey easier to follow.\n\nYaandu helps teams improve website enquiry experiences. Would a short, no-obligation overview be useful?\n\nRegards,\nYaandu Growth Team",
                'reasoning_summary' => 'Fictional copy grounded in a local fixture observation and approved fixture service knowledge. This text was authored deterministically for UI acceptance; no model generated it.',
                'personalization_points' => ['Public contact page has no visible post-submit guidance', 'Fictional outdoor retailer'],
                'evidence_references' => [$this->evidenceId, $this->knowledgeId],
                'confidence' => 0.91,
                'recommended_call_to_action' => 'Offer a short overview for human review.',
            ],
            'proposal_generation' => [
                'executive_summary' => 'A focused engagement to help Acme Test Outfitters make its online enquiry journey clearer.',
                'client_understanding' => 'The fictional company wants prospective customers to understand what happens after they submit an enquiry.',
                'objectives' => ['Clarify the enquiry journey', 'Make follow-up expectations easier to understand'],
                'recommended_solution' => [$this->serviceId],
                'scope' => [['service_id' => $this->serviceId, 'description' => 'Review the enquiry journey and recommend practical improvements.', 'deliverables' => ['Enquiry journey review', 'Prioritized improvement brief']]],
                'deliverables' => ['Enquiry journey review', 'Prioritized improvement brief'],
                'assumptions' => ['The client will provide access to existing public-facing materials.'],
                'dependencies' => ['Client review of the findings.'],
                'exclusions' => ['Website implementation and third-party software fees.'],
                'implementation_approach' => ['Review the existing journey.', 'Present a prioritized improvement brief.'],
                'timeline_narrative' => 'Timing will be agreed after the scope is reviewed.',
                'commercial_narrative' => 'Commercials below use the approved tenant service catalogue and recorded human approval.',
                'case_study_references' => [],
                'evidence_references' => [$this->evidenceId],
                'knowledge_references' => [$this->knowledgeId],
                'risks' => ['Recommendations depend on the materials supplied for review.'],
                'next_steps' => ['Review scope and commercials with the client.'],
            ],
            default => throw new \RuntimeException('Unexpected visual fixture task: '.$request->task),
        };

        return new AIResponse($data, $this->providerKey(), $model);
    }
}
