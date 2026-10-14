<?php

namespace Tests\Unit;

use App\Outreach\OutreachChannelResolver;
use App\Outreach\OutreachDraftComposition;
use App\Outreach\OutreachDraftComposer;
use App\Outreach\OutreachDraftGroundingValidator;
use App\Outreach\OutreachEvidenceAtomBuilder;
use App\Outreach\OutreachStylePlanFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class OutreachDraftCompositionTest extends TestCase
{
    public function test_evidence_atoms_are_limited_to_selected_findings_and_require_provenance(): void
    {
        $id = (string) Str::uuid();
        $builder = new OutreachEvidenceAtomBuilder();
        $atoms = $builder->build([$this->finding($id, 'An observed H1 opportunity could improve clarity.', 'No non-empty H1 heading was found.')]);

        self::assertSame([$id], array_map(fn ($atom): string => $atom->findingId, $atoms));
        self::assertSame('No non-empty H1 heading was found.', $atoms[0]->observation);
        self::assertSame('An observed H1 opportunity could improve clarity.', $atoms[0]->opportunity);
        self::assertSame(['source' => 'business_insight', 'id' => $id], $atoms[0]->evidenceReference);
        $this->expectException(InvalidArgumentException::class);
        $builder->build([['id' => $id, 'label' => 'Missing evidence', 'summary' => 'A claim without evidence.', 'evidence_reference' => []]]);
    }

    public function test_style_plan_accepts_only_closed_values_and_a_permutation_of_selected_ids(): void
    {
        $factory = new OutreachStylePlanFactory();
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        self::assertSame(['tone', 'opening_variant', 'cta_variant', 'service_mention', 'finding_order'], array_keys($factory->schema($ids)['properties']));
        self::assertSame($ids, $factory->normalize($this->plan($ids), $ids)['finding_order']);
        self::assertNull($factory->normalize($this->plan([(string) Str::uuid(), $ids[1]]), $ids));
        self::assertNull($factory->normalize($this->plan($ids, ['tone' => 'inventive']), $ids));
        self::assertSame($ids, $factory->fallback($ids, 'consultative')['finding_order']);
    }

    public function test_composer_preserves_ai_style_order_and_provenance_for_one_two_and_several_findings(): void
    {
        $channels = new OutreachChannelResolver();
        $composer = new OutreachDraftComposer($channels);
        $validator = new OutreachDraftGroundingValidator($channels);
        $serviceId = (string) Str::uuid();
        $service = (object) ['id' => $serviceId, 'name' => 'Website Performance Optimization'];
        $contact = [['channel' => 'contact_page', 'status' => 'publicly_listed', 'confidence' => .95]];

        foreach ([1, 2, 5] as $count) {
            $findings = [];
            for ($index = 1; $index <= $count; $index++) {
                $findings[] = $this->finding((string) Str::uuid(), "Selected opportunity {$index} could support a review.", "Observed fact {$index} was recorded.");
            }
            $atoms = (new OutreachEvidenceAtomBuilder())->build($findings);
            $ids = array_map(fn ($atom): string => $atom->findingId, $atoms);
            $order = array_reverse($ids);
            $composition = $composer->compose($atoms, $serviceId, $service->name, $this->plan($order), $contact);
            $validator->assertComposition($composition, $findings, $service, $contact);

            self::assertSame($order, $composition->findingIds);
            self::assertSame($order, array_column($composition->draft['evidence_used'], 'finding_id'));
            self::assertSame('contact_page', $composition->draft['recommended_channel']);
            foreach ($order as $id) {
                self::assertContains($id, array_column(array_filter($composition->segments, fn (array $segment): bool => in_array($segment['type'], ['observation', 'opportunity'], true)), 'finding_id'));
            }
            self::assertStringNotContainsString('opportunity 1 therefore', strtolower($composition->draft['email']['body']));
        }
    }

    public function test_validator_rejects_any_copy_inserted_outside_provenance_segments(): void
    {
        $channels = new OutreachChannelResolver();
        $composer = new OutreachDraftComposer($channels);
        $validator = new OutreachDraftGroundingValidator($channels);
        $finding = $this->finding((string) Str::uuid(), 'The selected H1 opportunity could improve clarity.', 'No non-empty H1 heading was found.');
        $service = (object) ['id' => (string) Str::uuid(), 'name' => 'Website Performance Optimization'];
        $contact = [['channel' => 'contact_page', 'status' => 'publicly_listed', 'confidence' => .95]];
        $composition = $composer->compose((new OutreachEvidenceAtomBuilder())->build([$finding]), $service->id, $service->name,
            $this->plan([$finding['id']]), $contact);
        $tampered = $composition->draft;
        $tampered['email']['body'] .= "\n\nEbarza has broken mobile performance.";

        $this->expectException(\RuntimeException::class);
        $validator->assertComposition(new OutreachDraftComposition($tampered, $composition->segments, $composition->findingIds,
            $composition->serviceId, $composition->recommendedChannel), [$finding], $service, $contact);
    }

    private function finding(string $id, string $summary, string $evidence): array
    {
        return ['id' => $id, 'key' => 'strong_business_fit', 'label' => 'Strong business fit', 'summary' => $summary,
            'evidence' => $evidence, 'evidence_reference' => ['source' => 'business_insight', 'id' => $id]];
    }

    private function plan(array $ids, array $overrides = []): array
    {
        return array_replace(['tone' => 'consultative', 'opening_variant' => 'brief_observation', 'cta_variant' => 'soft_discussion',
            'service_mention' => 'light', 'finding_order' => array_values($ids)], $overrides);
    }
}
