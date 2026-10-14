<?php

namespace App\Outreach;

use RuntimeException;

/** Validates composer provenance and deterministic channel invariants. */
final class OutreachDraftGroundingValidator
{
    public function __construct(private readonly OutreachChannelResolver $channels) {}

    /** @param list<array<string, mixed>> $selectedFindings @param list<array<string, mixed>> $publicContactContext */
    public function assertComposition(OutreachDraftComposition $composition, array $selectedFindings, object $service, array $publicContactContext): void
    {
        if (! in_array($composition->requestedWritingStyle, ['consultative', 'professional', 'concise'], true)) {
            throw new RuntimeException('The composed draft uses an unsupported writing style.');
        }
        $selected = array_values(array_unique(array_map('strval', array_column($selectedFindings, 'id'))));
        if ($selected === [] || count($selected) !== count($selectedFindings)
            || count($composition->findingIds) !== count($selected)
            || count(array_unique($composition->findingIds)) !== count($selected)
            || array_diff($composition->findingIds, $selected) !== []
            || array_diff($selected, $composition->findingIds) !== []) {
            throw new RuntimeException('The composed draft did not preserve the reviewer-selected finding set.');
        }

        if ((string) ($service->id ?? '') !== $composition->serviceId) {
            throw new RuntimeException('The composed draft service does not match the approved selected service.');
        }

        $contact = $this->channels->resolve($publicContactContext);
        if (($composition->draft['recommended_channel'] ?? null) !== $contact['recommended_channel']
            || ($composition->draft['recommended_channel_reason'] ?? null) !== $contact['recommended_channel_reason']) {
            throw new RuntimeException('The composed draft channel does not match verified public contact context.');
        }
        if (! $this->channels->isAvailable('whatsapp', $publicContactContext) && ($composition->draft['whatsapp'] ?? null) !== '') {
            throw new RuntimeException('A WhatsApp draft was composed without a verified or publicly listed channel.');
        }
        if (! $this->channels->isAvailable('linkedin', $publicContactContext) && ($composition->draft['linkedin'] ?? null) !== '') {
            throw new RuntimeException('A LinkedIn draft was composed without a verified or publicly listed channel.');
        }

        $draft = $composition->draft;
        $expectedKeys = ['pitch_summary', 'recommended_channel', 'recommended_channel_reason', 'email', 'whatsapp', 'linkedin',
            'follow_up_email', 'follow_up_short', 'evidence_used'];
        if (array_diff($expectedKeys, array_keys($draft)) !== [] || array_diff(array_keys($draft), $expectedKeys) !== []
            || ! is_array($draft['email'] ?? null) || array_keys($draft['email']) !== ['subject', 'body']) {
            throw new RuntimeException('The deterministic outreach composer produced an unexpected draft shape.');
        }
        foreach (['pitch_summary', 'recommended_channel_reason', 'email.subject', 'email.body', 'whatsapp', 'linkedin', 'follow_up_email', 'follow_up_short'] as $field) {
            $value = match ($field) {
                'email.subject' => $draft['email']['subject'] ?? null,
                'email.body' => $draft['email']['body'] ?? null,
                default => $draft[$field] ?? null,
            };
            if (is_string($value) && in_array($field, ['whatsapp', 'linkedin'], true) && $value === '') continue;
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException('The deterministic outreach composer produced an incomplete draft.');
            }
        }

        $findingsById = collect($selectedFindings)->keyBy('id');
        $fieldText = [
            'pitch_summary' => $draft['pitch_summary'], 'email.subject' => $draft['email']['subject'],
            'email.body' => $draft['email']['body'], 'whatsapp' => $draft['whatsapp'], 'linkedin' => $draft['linkedin'],
            'follow_up_email' => $draft['follow_up_email'], 'follow_up_short' => $draft['follow_up_short'],
        ];
        $seenAtoms = [];
        $rendered = [];
        foreach ($composition->segments as $segment) {
            $field = $segment['field'] ?? null;
            $type = $segment['type'] ?? null;
            $text = $segment['text'] ?? null;
            if (! is_string($field) || ! isset($fieldText[$field]) || ! is_string($text) || trim($text) === '') {
                throw new RuntimeException('The deterministic outreach composer produced an invalid provenance segment.');
            }
            $rendered[$field] = ($rendered[$field] ?? '').(string) ($segment['separator_before'] ?? '').$text;
            if (in_array($type, ['observation', 'opportunity', 'finding_label'], true)) {
                $id = $segment['finding_id'] ?? null;
                $finding = is_string($id) ? $findingsById->get($id) : null;
                if (! $finding || ! in_array($id, $selected, true)) {
                    throw new RuntimeException('A provenance segment references a finding not selected by the reviewer.');
                }
                $source = match ($type) {
                    'observation' => 'We noticed: '.mb_substr(trim((string) ($finding['evidence'] ?? '')), 0, 120),
                    'opportunity' => mb_substr(trim((string) ($finding['summary'] ?? '')), 0, 180),
                    'finding_label' => mb_substr(match ($composition->requestedWritingStyle) {
                        'professional' => 'Website observation: ',
                        'concise' => 'Quick note: ',
                        default => 'A quick observation about ',
                    }.(string) ($finding['label'] ?? ''), 0, 180),
                };
                if ($text !== $source) {
                    throw new RuntimeException('A provenance segment does not match its selected evidence atom.');
                }
                if (in_array($type, ['observation', 'opportunity'], true)) $seenAtoms[$id][$type] = true;
            } elseif ($type === 'service_capability') {
                $expected = match ($composition->requestedWritingStyle) {
                    'professional' => "Yaandu's ".(string) ($service->name ?? '').' service can support this area where appropriate.',
                    'concise' => 'Our '.(string) ($service->name ?? '').' service may help with this area.',
                    default => 'Our '.(string) ($service->name ?? '').' work can support this type of improvement where relevant.',
                };
                if ($text !== $expected) throw new RuntimeException('The service capability segment does not match the approved service template.');
            } elseif ($type === 'template') {
                $templates = match ($composition->requestedWritingStyle) {
                    'professional' => [
                        'Website observation: ',
                        'Hello, we are reaching out from Yaandu regarding an observation identified during our review.',
                        "Kind regards,\nYaandu", 'I am following up regarding the website observation below.',
                    ],
                    'concise' => ['Hello,', 'Regards, Yaandu', 'Following up:'],
                    default => [
                        'A point worth exploring: ',
                        'Hello, while reviewing the available website evidence, we noticed one point that may be worth exploring.',
                        "Best regards,\nYaandu", 'Just following up on the website observation we shared.',
                    ],
                };
                if (! in_array($text, $templates, true)) {
                    throw new RuntimeException('A deterministic template segment is not approved.');
                }
            } elseif ($type === 'cta') {
                $ctas = match ($composition->requestedWritingStyle) {
                    'professional' => [
                        'Please let us know if you would like to discuss this observation further.',
                        'Please let us know if you would like to discuss it further.',
                        'Please let us know if you would like to discuss.',
                    ],
                    'concise' => ['Interested in a quick discussion?', 'Interested?'],
                    default => [
                        'Would you be open to a brief conversation to explore whether this is relevant for your priorities?',
                        'Would it be useful to explore whether this is relevant to your priorities?',
                        'Would it be useful to explore this?',
                    ],
                };
                if (! in_array($text, $ctas, true)) {
                    throw new RuntimeException('A deterministic call-to-action segment is not approved.');
                }
            } elseif ($type !== 'cta') {
                throw new RuntimeException('The deterministic outreach composer produced an unsupported segment type.');
            }
        }

        foreach ($fieldText as $field => $value) {
            if (($rendered[$field] ?? '') !== $value) {
                throw new RuntimeException('A rendered draft field contains text outside its deterministic provenance segments.');
            }
        }

        foreach ($selected as $id) {
            if (empty($seenAtoms[$id]['observation']) || empty($seenAtoms[$id]['opportunity'])) {
                throw new RuntimeException('The composed draft is missing provenance for a selected finding.');
            }
        }

        $evidenceUsed = array_values(array_map('strval', array_column((array) ($draft['evidence_used'] ?? []), 'finding_id')));
        if ($evidenceUsed !== $composition->findingIds) {
            throw new RuntimeException('The composed evidence references do not match the selected finding order.');
        }
        foreach ((array) ($draft['evidence_used'] ?? []) as $index => $item) {
            $finding = $findingsById->get($item['finding_id'] ?? null);
            if (! $finding || ($item['summary'] ?? null) !== mb_substr((string) $finding['summary'], 0, 300)) {
                throw new RuntimeException('A public evidence summary does not match its selected finding.');
            }
        }
    }
}
