<?php

namespace App\Outreach;

use InvalidArgumentException;

/** Renders prospect-facing copy from reviewer-selected evidence atoms and fixed templates. */
final class OutreachDraftComposer
{
    private const TONES = ['consultative', 'concise', 'warm_professional'];
    private const OPENINGS = ['direct', 'brief_observation'];
    private const CTAS = ['soft_discussion', 'offer_review', 'ask_interest'];
    private const SERVICE_MENTIONS = ['none', 'light'];

    public function __construct(private readonly OutreachChannelResolver $channels) {}

    /** @param list<OutreachEvidenceAtom> $atoms @param array<string, mixed> $plan @param list<array<string, mixed>> $contactContext */
    public function compose(array $atoms, string $serviceId, string $serviceName, array $plan, array $contactContext): OutreachDraftComposition
    {
        $channel = $this->channels->resolve($contactContext);
        if (! in_array($plan['tone'] ?? null, self::TONES, true)
            || ! in_array($plan['opening_variant'] ?? null, self::OPENINGS, true)
            || ! in_array($plan['cta_variant'] ?? null, self::CTAS, true)
            || ! in_array($plan['service_mention'] ?? null, self::SERVICE_MENTIONS, true)) {
            throw new InvalidArgumentException('The outreach style plan contains unsupported values.');
        }

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
        $cta = match ($plan['cta_variant']) {
            'offer_review' => 'Would a brief review of this finding be useful?',
            'ask_interest' => 'Would you be interested in discussing this?',
            default => 'Would you be open to a brief conversation about this?',
        };
        $opening = $plan['opening_variant'] === 'direct'
            ? 'Hello, I am reaching out from Yaandu with a note about a selected finding.'
            : 'Hello, we noticed a detail in the reviewed website evidence.';
        $serviceSentence = $plan['service_mention'] === 'light'
            ? 'Our '.$serviceName.' work can support this type of improvement where relevant.'
            : null;

        $segments = [];
        $append = function (string $field, string $type, string $text, string $separator = '', ?string $findingId = null) use (&$segments): void {
            $segment = ['field' => $field, 'type' => $type, 'text' => $text, 'separator_before' => $separator];
            if ($findingId !== null) $segment['finding_id'] = $findingId;
            $segments[] = $segment;
        };
        foreach (array_slice($orderedAtoms, 0, 3) as $index => $atom) {
            $append('pitch_summary', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), $index === 0 ? '' : ' ', $atom->findingId);
        }
        $append('email.subject', 'finding_label', mb_substr('A note about '.$orderedAtoms[0]->label, 0, 180), '', $orderedAtoms[0]->findingId);
        $append('email.body', 'template', $opening);
        foreach ($orderedAtoms as $atom) {
            $id = $atom->findingId;
            $observation = mb_substr(trim($atom->observation), 0, 120);
            $opportunity = mb_substr(trim($atom->opportunity), 0, 180);
            $append('email.body', 'observation', 'We noticed: '.$observation, "\n\n", $id);
            $append('email.body', 'opportunity', $opportunity, "\n\n", $id);
        }
        if ($serviceSentence !== null) $append('email.body', 'service_capability', $serviceSentence, "\n\n");
        $append('email.body', 'cta', $cta, "\n\n");
        $append('email.body', 'template', $this->closing($plan['tone']), "\n\n");

        if ($this->channels->isAvailable('whatsapp', $contactContext)) {
            $append('whatsapp', 'template', 'Hello,');
            foreach (array_slice($orderedAtoms, 0, 5) as $atom) $append('whatsapp', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), "\n\n", $atom->findingId);
            $append('whatsapp', 'cta', $cta, "\n\n");
        }
        if ($this->channels->isAvailable('linkedin', $contactContext)) {
            $append('linkedin', 'template', 'Hello,');
            foreach (array_slice($orderedAtoms, 0, 5) as $atom) $append('linkedin', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), "\n\n", $atom->findingId);
            $append('linkedin', 'cta', $cta, "\n\n");
        }

        $append('follow_up_email', 'template', 'Hello,');
        foreach ($orderedAtoms as $atom) $append('follow_up_email', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), ' ', $atom->findingId);
        $append('follow_up_email', 'cta', $cta, "\n\n");
        $append('follow_up_email', 'template', $this->closing($plan['tone']), "\n\n");
        foreach (array_slice($orderedAtoms, 0, 3) as $index => $atom) {
            $append('follow_up_short', 'opportunity', mb_substr(trim($atom->opportunity), 0, 180), $index === 0 ? '' : ' ', $atom->findingId);
        }
        $append('follow_up_short', 'cta', $cta, ' ');

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
        );
    }

    private function closing(string $tone): string
    {
        return match ($tone) {
            'concise' => 'Regards, Yaandu',
            'warm_professional' => 'Warm regards, Yaandu',
            default => 'Best regards, Yaandu',
        };
    }
}
