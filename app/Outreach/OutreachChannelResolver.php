<?php

namespace App\Outreach;

/** Resolves an outreach channel only from verified or publicly listed contact context. */
final class OutreachChannelResolver
{
    /** @param list<array<string, mixed>> $context @return array<string, string> channel => strongest accepted status */
    public function availableChannels(array $context): array
    {
        $available = [];
        foreach ($context as $entry) {
            if (! is_array($entry)) continue;
            $status = $entry['status'] ?? null;
            if (! in_array($status, ['verified', 'publicly_listed'], true)) continue;
            $channel = match (strtolower((string) ($entry['channel'] ?? ''))) {
                'email' => 'email',
                'whatsapp' => 'whatsapp',
                'linkedin' => 'linkedin',
                'phone', 'telephone' => 'phone',
                'contact_page' => 'contact_page',
                default => null,
            };
            if ($channel === null) continue;
            if (($available[$channel] ?? null) !== 'verified' || $status === 'verified') {
                $available[$channel] = $status;
            }
        }

        return $available;
    }

    /** @param list<array<string, mixed>> $context @return array{recommended_channel: string, recommended_channel_reason: string} */
    public function resolve(array $context): array
    {
        $available = $this->availableChannels($context);

        // Preserve the existing supported channel kinds and manual phone behavior.
        // Direct channels take precedence over the public contact page.
        foreach (['email', 'whatsapp', 'linkedin'] as $channel) {
            if (isset($available[$channel])) {
                return [
                    'recommended_channel' => $channel,
                    'recommended_channel_reason' => $this->availabilityReason($channel, $available[$channel]),
                ];
            }
        }
        if (isset($available['phone'])) {
            return [
                'recommended_channel' => 'manual_contact',
                'recommended_channel_reason' => $this->availabilityReason('phone', $available['phone']).' Use manual contact.',
            ];
        }
        if (isset($available['contact_page'])) {
            return [
                'recommended_channel' => 'contact_page',
                'recommended_channel_reason' => 'No verified direct email, WhatsApp, or LinkedIn channel is available. A public contact page is available.',
            ];
        }

        return [
            'recommended_channel' => 'none',
            'recommended_channel_reason' => 'No verified direct contact channel or public contact page is available.',
        ];
    }

    /** @param list<array<string, mixed>> $context */
    public function isAvailable(string $channel, array $context): bool
    {
        return isset($this->availableChannels($context)[$channel]);
    }

    private function availabilityReason(string $channel, string $status): string
    {
        $label = match ($channel) {
            'email' => 'email channel',
            'whatsapp' => 'WhatsApp channel',
            'linkedin' => 'LinkedIn channel',
            'phone' => 'phone contact',
            default => 'contact channel',
        };
        $verb = $status === 'verified' ? 'verified' : 'publicly listed';

        $article = $channel === 'email' ? 'An ' : 'A ';

        return $article.$label.' is '.$verb.'.';
    }
}
