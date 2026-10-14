<?php

namespace App\Outreach;

/** Rejects ungrounded prospect claims before an outreach draft is persisted. */
final class OutreachDraftGroundingValidator
{
    /** @var array<string, string> */
    private const UNSUPPORTED_STRENGTH = [
        'significant' => '/\bsignificant(?:ly)?\b/i',
        'major' => '/\bmajor\b/i',
        'substantial' => '/\bsubstantial(?:ly)?\b/i',
        'poor' => '/\bpoor\b/i',
        'slow' => '/\bslow(?:ly)?\b/i',
        'weak' => '/\bweak(?:ly)?\b/i',
        'broken' => '/\bbroken\b/i',
        'lacking' => '/\black(?:s|ing)?\b/i',
    ];

    /** @var array<string, string> */
    private const TECHNICAL_TOPICS = [
        'mobile usability' => '/\b(?:mobile|smartphone|phone|viewport)\b/i',
        'responsive behavior' => '/\bresponsiv(?:e|ity|eness)\b/i',
        'website speed' => '/\b(?:speed|slow|fast|load(?:ing)? time|page speed|latency)\b/i',
        'website performance' => '/\bperformance\b/i',
        'technical weaknesses' => '/\btechnical (?:performance|weakness(?:es)?|problem(?:s)?|issue(?:s)?)\b/i',
        'lead capture' => '/\b(?:lead capture|contact form|conversion rate|cro)\b/i',
        'search optimization' => '/\b(?:seo|search engine optimization|meta description|h1|headings?)\b/i',
        'messaging capability' => '/\b(?:whatsapp|crm)\b/i',
        'commerce capability' => '/\b(?:e-?commerce|online store|checkout)\b/i',
        'software capability' => '/\b(?:erp|custom software|mobile app|ai agent|cloud devops)\b/i',
    ];

    /** @param array<string, mixed> $draft @param list<array<string, mixed>> $findings @param list<array<string, mixed>> $publicContactContext */
    public function assertGrounded(array $draft, array $findings, object $service, string $companyName = '', array $publicContactContext = []): void
    {
        $evidence = mb_strtolower(implode(' ', array_map(fn (array $finding): string => implode(' ', [
            (string) ($finding['key'] ?? ''), (string) ($finding['label'] ?? ''),
            (string) ($finding['summary'] ?? ''), (string) ($finding['evidence'] ?? ''),
        ]), $findings)));
        $serviceName = mb_strtolower((string) ($service->name ?? ''));
        $companyName = mb_strtolower($companyName);
        $this->assertChannelGrounded($draft, $publicContactContext, $companyName);
        $assertedFields = [
            'pitch_summary' => $draft['pitch_summary'] ?? '',
            'email.subject' => $draft['email']['subject'] ?? '',
            'email.body' => $draft['email']['body'] ?? '',
            'whatsapp' => $draft['whatsapp'] ?? '',
            'linkedin' => $draft['linkedin'] ?? '',
            'follow_up_email' => $draft['follow_up_email'] ?? '',
            'follow_up_short' => $draft['follow_up_short'] ?? '',
        ];

        foreach ($assertedFields as $field => $value) {
            if (! is_string($value) || trim($value) === '') continue;
            foreach ($this->sentences($value) as $sentence) {
                $sentenceLower = mb_strtolower($sentence);
                foreach (self::UNSUPPORTED_STRENGTH as $pattern) {
                    if (preg_match($pattern, $sentence) && ! preg_match($pattern, $evidence)) {
                        throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_STRENGTH', $field);
                    }
                }

                $capabilityOnly = $this->isGeneralCapabilityStatement($sentenceLower, $serviceName);
                $prospectClaim = $this->isProspectClaim($sentenceLower, $companyName);
                // The approved service name is context, not evidence. Exempt only that
                // exact phrase, and only when the sentence is not asserting a fact
                // about the prospect. Keep topic checks intact for all other wording.
                $topicSentence = $prospectClaim || $serviceName === ''
                    ? $sentence
                    : str_ireplace($serviceName, '', $sentence);
                foreach (self::TECHNICAL_TOPICS as $topicPattern) {
                    if (preg_match($topicPattern, $topicSentence) && ! preg_match($topicPattern, $evidence) && ! $capabilityOnly) {
                        throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_TOPIC', $field);
                    }
                }

                if ($prospectClaim && ! $this->hasEvidenceAnchor($sentenceLower, $evidence)
                    && ! $capabilityOnly && ! $this->isGenericCallToAction($sentenceLower)) {
                    throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_PROSPECT_CLAIM', $field);
                }
            }
        }
    }

    /** @param array<string, mixed> $draft @param list<array<string, mixed>> $context */
    private function assertChannelGrounded(array $draft, array $context, string $companyName): void
    {
        $available = [];
        foreach ($context as $entry) {
            if (! is_array($entry) || ! in_array($entry['status'] ?? null, ['verified', 'publicly_listed'], true)) continue;
            $channel = match (strtolower((string) ($entry['channel'] ?? ''))) {
                'email' => 'email',
                'whatsapp' => 'whatsapp',
                'linkedin' => 'linkedin',
                'phone', 'telephone' => 'phone',
                'contact_page' => 'contact_page',
                default => null,
            };
            if ($channel !== null) $available[$channel] = true;
        }

        $recommended = (string) ($draft['recommended_channel'] ?? '');
        $recommendedContext = match ($recommended) {
            'email', 'whatsapp', 'linkedin', 'contact_page' => $recommended,
            'manual_contact' => ! empty($available['email']) || ! empty($available['whatsapp']) || ! empty($available['linkedin']) || ! empty($available['phone']) ? 'manual_contact' : null,
            'none' => null,
            default => null,
        };
        $hasDirect = ! empty($available['email']) || ! empty($available['whatsapp']) || ! empty($available['linkedin']) || ! empty($available['phone']);
        $validRecommendation = match ($recommended) {
            'email', 'whatsapp', 'linkedin', 'contact_page' => ! empty($available[$recommended]),
            'manual_contact' => $recommendedContext === 'manual_contact',
            'none' => ! $hasDirect && empty($available['contact_page']),
            default => false,
        };
        if (! $validRecommendation) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel');

        $reason = (string) ($draft['recommended_channel_reason'] ?? '');
        if (trim($reason) === '') throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
        $reasonLower = mb_strtolower($reason);
        $genericDirectUnavailable = preg_match('/\bno\s+(?:verified\s+)?direct\s+(?:contact\s+)?channels?\b.*\b(?:available|verified|identified)\b/i', $reason) === 1
            || preg_match('/\bno\s+verified\s+direct\s+channel\s+is\s+available\b/i', $reason) === 1;
        if ($genericDirectUnavailable && $hasDirect) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');

        $terms = [
            'email' => '/\bemail(?: address)?\b/i',
            'whatsapp' => '/\bwhatsapp\b/i',
            'linkedin' => '/\blinkedin\b/i',
            'phone' => '/\b(?:phone|telephone)\b/i',
            'contact_page' => '/\b(?:public\s+)?contact(?:_|\s+)page\b/i',
        ];
        $hasContextBoundReason = $genericDirectUnavailable;
        foreach ($terms as $channel => $pattern) {
            if (! preg_match_all($pattern, $reason, $matches, PREG_OFFSET_CAPTURE)) continue;
            $hasContextBoundReason = true;
            foreach ($matches[0] as [$mention, $offset]) {
                if ($genericDirectUnavailable && in_array($channel, ['email', 'whatsapp', 'linkedin', 'phone'], true)) {
                    if (! empty($available[$channel])) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
                    continue;
                }
                $before = substr($reason, max(0, $offset - 80), min(80, $offset));
                $after = substr($reason, $offset + strlen($mention), 80);
                $negativeBefore = preg_match('/\b(?:no|not|without|unavailable|cannot|can not|could not|isn.t|aren.t)\b(?:[\s\w-]{0,40})$/i', $before) === 1;
                $negativeAfter = preg_match('/^\s*(?:(?:address|contact|channel)\s+)?(?:is|are)?\s*(?:not\s+(?:verified|available|present)|unavailable|missing)\b/i', $after) === 1;
                $negative = $negativeBefore || $negativeAfter;
                $positive = ($channel === 'contact_page' && preg_match('/\bpublic\s+contact\s+page\b/i', $mention) === 1)
                    || preg_match('/\b(?:verified|available|publicly listed|present|public|most appropriate)\b(?:[\s\w-]{0,32})$/i', $before) === 1
                    || preg_match('/^\s*(?:(?:address|contact|page|channel)\s+)?(?:is|are)?\s*(?:verified|available|publicly listed|present|recommended|the most appropriate|most appropriate)\b/i', $after) === 1;
                if ($negative) $positive = false;
                if ($negative === $positive) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
                if ($negative && ! empty($available[$channel])) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
                if ($positive && empty($available[$channel])) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
            }
        }
        if (! $hasContextBoundReason) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');

        // Channel explanations are not a place to smuggle business or technical claims.
        $claimText = preg_replace($terms, ' ', $reason) ?? $reason;
        foreach (self::UNSUPPORTED_STRENGTH as $pattern) {
            if (preg_match($pattern, $claimText)) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
        }
        foreach (self::TECHNICAL_TOPICS as $pattern) {
            if (preg_match($pattern, $claimText)) throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
        }
        if ($companyName !== '' && str_contains($reasonLower, $companyName)
            && preg_match('/\b(?:has|have|is|are|needs?|lacks?|shows?|contains?|could|may|might)\b/i', $reason)) {
            throw new OutreachDraftGroundingException('OUTREACH_GROUNDING_CHANNEL', 'recommended_channel_reason');
        }
    }

    /** @return list<string> */
    private function sentences(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|[\r\n]+/u', $text) ?: []),
            fn (string $sentence): bool => $sentence !== ''));
    }

    private function isGeneralCapabilityStatement(string $sentence, string $serviceName): bool
    {
        if ($serviceName === '' || ! str_contains($sentence, $serviceName)) return false;
        if (preg_match('/\b(?:your|you|we noticed|we found|we observed|currently|needs?|lacks?|suffers?)\b/i', $sentence)) return false;

        return preg_match('/\b(?:our|the selected)\b.*\b(?:service|work|offering)\b.*\b(?:can|could|may)\s+(?:include|help|support|address|cover|provide|improve|optimi[sz]e)\b.*\b(?:where relevant|when relevant|if needed|as appropriate)\b/i', $sentence) === 1;
    }

    private function isProspectClaim(string $sentence, string $companyName): bool
    {
        if (preg_match('/\b(?:we noticed|we found|we observed|we reviewed|we saw|your (?:company|business|website|site|team|current)|you (?:have|are|need|lack)|your\s+\w+\s+(?:is|are|has|have|needs?|lacks?|shows?|contains?))\b/i', $sentence)) return true;
        if ($companyName !== '' && str_contains($sentence, $companyName)
            && preg_match('/\b(?:has|have|is|are|needs?|lacks?|shows?|contains?|could|may|might)\b/i', $sentence)) return true;

        return false;
    }

    private function hasEvidenceAnchor(string $sentence, string $evidence): bool
    {
        preg_match_all('/\b(?:[a-z][a-z0-9-]{4,}|h1|seo|cro|crm)\b/i', $evidence, $matches);
        $stop = ['could', 'would', 'should', 'might', 'their', 'there', 'these', 'those', 'which', 'where', 'while', 'about', 'after',
            'before', 'pages', 'page', 'presence', 'content', 'improving', 'enhance', 'improve', 'opportunity', 'finding', 'business'];
        foreach (array_unique(array_map('mb_strtolower', $matches[0] ?? [])) as $anchor) {
            if (in_array($anchor, $stop, true)) continue;
            if (preg_match('/\b'.preg_quote($anchor, '/').'\b/i', $sentence)) return true;
        }

        return false;
    }

    private function isGenericCallToAction(string $sentence): bool
    {
        return preg_match('/\b(?:would you be open|would it be useful|shall we|could we|let me know|happy to discuss|available for a call)\b/i', $sentence) === 1;
    }
}
