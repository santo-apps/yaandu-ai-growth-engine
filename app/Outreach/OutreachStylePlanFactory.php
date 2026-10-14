<?php

namespace App\Outreach;

final class OutreachStylePlanFactory
{
    private const TONES = ['consultative', 'concise', 'warm_professional'];
    private const OPENINGS = ['direct', 'brief_observation'];
    private const CTAS = ['soft_discussion', 'offer_review', 'ask_interest'];
    private const SERVICE_MENTIONS = ['none', 'light'];

    /** @param list<string> $findingIds */
    public function schema(array $findingIds): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['tone', 'opening_variant', 'cta_variant', 'service_mention', 'finding_order'],
            'properties' => [
                'tone' => ['type' => 'string', 'enum' => self::TONES],
                'opening_variant' => ['type' => 'string', 'enum' => self::OPENINGS],
                'cta_variant' => ['type' => 'string', 'enum' => self::CTAS],
                'service_mention' => ['type' => 'string', 'enum' => self::SERVICE_MENTIONS],
                'finding_order' => ['type' => 'array', 'minItems' => count($findingIds), 'maxItems' => count($findingIds), 'uniqueItems' => true,
                    'items' => ['type' => 'string', 'enum' => $findingIds]],
            ],
        ];
    }

    /** @param list<string> $findingIds @return array{tone: string, opening_variant: string, cta_variant: string, service_mention: string, finding_order: list<string>}|null */
    public function normalize(mixed $data, array $findingIds): ?array
    {
        $keys = ['tone', 'opening_variant', 'cta_variant', 'service_mention', 'finding_order'];
        if (! is_array($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) return null;
        if (! in_array($data['tone'], self::TONES, true)
            || ! in_array($data['opening_variant'], self::OPENINGS, true)
            || ! in_array($data['cta_variant'], self::CTAS, true)
            || ! in_array($data['service_mention'], self::SERVICE_MENTIONS, true)
            || ! is_array($data['finding_order'])) return null;
        $order = array_values($data['finding_order']);
        if (count($order) !== count($findingIds) || count(array_filter($order, 'is_string')) !== count($order)
            || count(array_unique($order)) !== count($findingIds)
            || array_diff($order, $findingIds) !== [] || array_diff($findingIds, $order) !== []) return null;

        return [
            'tone' => $data['tone'], 'opening_variant' => $data['opening_variant'], 'cta_variant' => $data['cta_variant'],
            'service_mention' => $data['service_mention'], 'finding_order' => $order,
        ];
    }

    /** @param list<string> $findingIds @return array{tone: string, opening_variant: string, cta_variant: string, service_mention: string, finding_order: list<string>} */
    public function fallback(array $findingIds, string $requestedTone): array
    {
        $tone = match ($requestedTone) {
            'concise' => 'concise', 'professional' => 'warm_professional', 'consultative' => 'consultative', default => 'consultative',
        };

        return ['tone' => $tone, 'opening_variant' => 'brief_observation', 'cta_variant' => 'soft_discussion',
            'service_mention' => 'light', 'finding_order' => array_values($findingIds)];
    }
}
