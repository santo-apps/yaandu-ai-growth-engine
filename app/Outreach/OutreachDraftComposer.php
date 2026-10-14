<?php

namespace App\Outreach;

use InvalidArgumentException;

/** Renders prospect-facing copy from reviewer-selected evidence atoms and fixed templates. */
final class OutreachDraftComposer
{
    private const STYLES = ['consultative', 'professional', 'concise'];

    public function __construct(private readonly OutreachChannelResolver $channels) {}

    /** @param list<OutreachEvidenceAtom> $atoms @param array<string, mixed> $plan @param list<array<string, mixed>> $contactContext */
    public function compose(array $atoms, string $serviceId, string $serviceName, array $plan, array $contactContext, string $requestedWritingStyle = 'consultative'): OutreachDraftComposition
    {
        $channel = $this->channels->resolve($contactContext);
        if (! in_array($requestedWritingStyle, self::STYLES, true)) $requestedWritingStyle = 'consultative';

        $byId = [];
        foreach ($atoms as $atom) $byId[$atom->findingId] = $atom;
        $orderedIds = $plan['finding_order'] ?? null;
        $atomIds = array_values(array_map(fn (OutreachEvidenceAtom $atom): string => $atom->findingId, $atoms));
        if (! is_array($orderedIds) || count(array_filter($orderedIds, 'is_string')) !== count($orderedIds)
            || count($orderedIds) !== count($atomIds)
            || count(array_unique($orderedIds)) !== count($atomIds)
            || array_diff($orderedIds, $atomIds) !== [] || array_diff($atomIds, $orderedIds) !== []) {
            throw new InvalidArgumentException('The outreach style plan must order every selected finding exactly once.');
        }
        $orderedAtoms = array_map(fn (string $id): OutreachEvidenceAtom => $byId[$id], $orderedIds);
        $profile = $this->profile($requestedWritingStyle, $serviceName);

        $segments = [];
        $append = function (string $field, string $type, string $text, string $separator = '', ?string $findingId = null) use (&$segments): void {
            $segment = ['field' => $field, 'type' => $type, 'text' => $text, 'separator_before' => $separator];
            if ($findingId !== null) $segment['finding_id'] = $findingId;
            $segments[] = $segment;
        };
        if ($requestedWritingStyle === 'consultative') $append('pitch_summary', 'template', 'A point worth exploring: ');
        if ($requestedWritingStyle === 'professional') $append('pitch_summary', 'template', 'Website observation: ');
        foreach (array_slice($orderedAtoms, 0, 3) as $index => $atom) {
            $append('pitch_summary', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), $index === 0 ? '' : ' ', $atom->findingId);
        }
        $append('email.subject', 'finding_label', mb_substr($profile['subject_prefix'].$orderedAtoms[0]->label, 0, 180), '', $orderedAtoms[0]->findingId);
        $append('email.body', 'template', $profile['opening']);
        foreach ($orderedAtoms as $atom) {
            $id = $atom->findingId;
            $observation = mb_substr(trim($atom->observation), 0, 120);
            $opportunity = mb_substr(trim($atom->opportunity), 0, 180);
            $append('email.body', 'observation', 'We noticed: '.$observation, "\n\n", $id);
            $append('email.body', 'opportunity', $opportunity, "\n\n", $id);
        }
        $append('email.body', 'service_capability', $profile['service_sentence'], "\n\n");
        $append('email.body', 'cta', $profile['cta'], "\n\n");
        $append('email.body', 'template', $profile['closing'], "\n\n");

        if ($this->channels->isAvailable('whatsapp', $contactContext)) {
            $append('whatsapp', 'template', $profile['opening']);
            foreach (array_slice($orderedAtoms, 0, 5) as $atom) $append('whatsapp', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), "\n\n", $atom->findingId);
            $append('whatsapp', 'cta', $profile['cta'], "\n\n");
        }
        if ($this->channels->isAvailable('linkedin', $contactContext)) {
            $append('linkedin', 'template', $profile['opening']);
            foreach (array_slice($orderedAtoms, 0, 5) as $atom) $append('linkedin', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), "\n\n", $atom->findingId);
            $append('linkedin', 'cta', $profile['cta'], "\n\n");
        }

        $append('follow_up_email', 'template', $profile['follow_up_opening']);
        foreach ($orderedAtoms as $index => $atom) $append('follow_up_email', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), $index === 0 ? "\n\n" : ' ', $atom->findingId);
        $append('follow_up_email', 'cta', $profile['follow_up_cta'], "\n\n");
        if ($profile['follow_up_closing'] !== '') $append('follow_up_email', 'template', $profile['follow_up_closing'], "\n\n");
        foreach (array_slice($orderedAtoms, 0, 3) as $index => $atom) {
            $append('follow_up_short', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), $index === 0 ? '' : ' ', $atom->findingId);
        }
        $append('follow_up_short', 'cta', $profile['short_cta'], ' ');

        $fieldText = [];
        foreach ($segments as $segment) $fieldText[$segment['field']] = ($fieldText[$segment['field']] ?? '').$segment['separator_before'].$segment['text'];
        foreach (['whatsapp', 'linkedin'] as $optionalChannel) $fieldText[$optionalChannel] ??= '';
        foreach ($fieldText as $field => $text) {
            $limit = match ($field) {
                'pitch_summary' => 600, 'email.subject' => 180, 'email.body' => 3000,
                'whatsapp', 'linkedin' => 1000, 'follow_up_email' => 2000, default => 800,
            };
            if (mb_strlen($text) > $limit) throw new InvalidArgumentException('The selected findings exceed the deterministic draft length limits.');
        }

        return new OutreachDraftComposition(
            draft: [
                'pitch_summary' => $fieldText['pitch_summary'],
                'recommended_channel' => $channel['recommended_channel'],
                'recommended_channel_reason' => $channel['recommended_channel_reason'],
                'email' => ['subject' => $fieldText['email.subject'], 'body' => $fieldText['email.body']],
                'whatsapp' => $fieldText['whatsapp'], 'linkedin' => $fieldText['linkedin'],
                'follow_up_email' => $fieldText['follow_up_email'], 'follow_up_short' => $fieldText['follow_up_short'],
                'evidence_used' => array_map(fn (OutreachEvidenceAtom $atom): array => ['finding_id' => $atom->findingId, 'summary' => mb_substr($atom->opportunity, 0, 300)], $orderedAtoms),
            ],
            segments: $segments,
            findingIds: array_values($orderedIds),
            serviceId: $serviceId,
            recommendedChannel: (string) $channel['recommended_channel'],
            requestedWritingStyle: $requestedWritingStyle,
        );
    }

    /** @return array{subject_prefix: string, opening: string, cta: string, closing: string, service_sentence: string, follow_up_opening: string, follow_up_cta: string, follow_up_closing: string, short_cta: string} */
    private function profile(string $style, string $serviceName): array
    {
        return match ($style) {
            'professional' => [
                'subject_prefix' => 'Website observation: ',
                'opening' => 'Hello, we are reaching out from Yaandu regarding an observation identified during our review.',
                'cta' => 'Please let us know if you would like to discuss this observation further.',
                'closing' => "Kind regards,\nYaandu",
                'service_sentence' => "Yaandu's {$serviceName} service can support this area where appropriate.",
                'follow_up_opening' => 'I am following up regarding the website observation below.',
                'follow_up_cta' => 'Please let us know if you would like to discuss it further.',
                'follow_up_closing' => "Kind regards,\nYaandu",
                'short_cta' => 'Please let us know if you would like to discuss.',
            ],
            'concise' => [
                'subject_prefix' => 'Quick note: ',
                'opening' => 'Hello,',
                'cta' => 'Interested in a quick discussion?',
                'closing' => 'Regards, Yaandu',
                'service_sentence' => "Our {$serviceName} service may help with this area.",
                'follow_up_opening' => 'Following up:',
                'follow_up_cta' => 'Interested?',
                'follow_up_closing' => '',
                'short_cta' => 'Interested?',
            ],
            default => [
                'subject_prefix' => 'A quick observation about ',
                'opening' => 'Hello, while reviewing the available website evidence, we noticed one point that may be worth exploring.',
                'cta' => 'Would you be open to a brief conversation to explore whether this is relevant for your priorities?',
                'closing' => "Best regards,\nYaandu",
                'service_sentence' => "Our {$serviceName} work can support this type of improvement where relevant.",
                'follow_up_opening' => 'Just following up on the website observation we shared.',
                'follow_up_cta' => 'Would it be useful to explore whether this is relevant to your priorities?',
                'follow_up_closing' => "Best regards,\nYaandu",
                'short_cta' => 'Would it be useful to explore this?',
            ],
        };
    }
}
