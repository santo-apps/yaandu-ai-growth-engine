<?php

namespace App\Campaigns;

use InvalidArgumentException;

final class CampaignTemplateRenderer
{
    /** @param array<string, string> $variables */
    public function render(string $subject, string $body, array $variables): array
    {
        $allowed = ['company_name', 'contact_name', 'contact_first_name', 'website', 'sender_name'];
        if (preg_match('/<\s*\/?\s*[a-z][^>]*>|<\?|\?>/i', $subject.' '.$body)) {
            throw new InvalidArgumentException('Campaign templates must contain plain text only.');
        }
        $replace = static function (string $text) use ($variables, $allowed): string {
            $result = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $match) use ($variables, $allowed): string {
                $key = $match[1];
                if (! in_array($key, $allowed, true)) throw new InvalidArgumentException('The campaign template contains an unsupported variable.');
                $value = preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $variables[$key] ?? '') ?? '';
                if (preg_match('/<\s*\/?\s*[a-z][^>]*>|<\?|\?>/i', $value)) {
                    throw new InvalidArgumentException('Campaign placeholders must resolve to plain text.');
                }

                return $value;
            }, $text);
            if ($result === null || str_contains($result, '{{') || str_contains($result, '}}')) {
                throw new InvalidArgumentException('The campaign template contains malformed variables.');
            }

            return $result;
        };

        return ['subject' => $replace($subject), 'body' => $replace($body)];
    }
}
