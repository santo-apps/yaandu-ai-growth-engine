<?php

namespace App\Outreach;

final class OutreachStylePlanFactory
{
    /** @param list<string> $findingIds */
    public function schema(array $findingIds): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['finding_order'],
            'properties' => [
                'finding_order' => ['type' => 'array', 'minItems' => count($findingIds), 'maxItems' => count($findingIds), 'uniqueItems' => true,
                    'items' => ['type' => 'string', 'enum' => $findingIds]],
            ],
        ];
    }

    /** @param list<string> $findingIds @return array{finding_order: list<string>}|null */
    public function normalize(mixed $data, array $findingIds): ?array
    {
        if (! is_array($data) || array_keys($data) !== ['finding_order'] || ! is_array($data['finding_order'])) return null;
        $order = array_values($data['finding_order']);
        if (count($order) !== count($findingIds) || count(array_filter($order, 'is_string')) !== count($order)
            || count(array_unique($order)) !== count($findingIds)
            || array_diff($order, $findingIds) !== [] || array_diff($findingIds, $order) !== []) return null;

        return ['finding_order' => $order];
    }

    /** @param list<string> $findingIds @return array{finding_order: list<string>} */
    public function fallback(array $findingIds): array
    {
        return ['finding_order' => array_values($findingIds)];
    }
}
