<?php

namespace Tests\Unit;

use App\Campaigns\CampaignTemplateRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CampaignTemplateRendererTest extends TestCase
{
    public function test_it_renders_only_allowlisted_fields_and_strips_header_controls_from_values(): void
    {
        $result = (new CampaignTemplateRenderer)->render(
            'Hello {{ contact_first_name }}', 'From {{ sender_name }} at {{ company_name }} ({{ website }})',
            ['contact_first_name' => "Ada\r\nBcc: bad@example.test", 'sender_name' => 'Yaandu',
                'company_name' => 'Example Co', 'website' => 'https://example.test'],
        );

        self::assertSame('Hello Ada Bcc: bad@example.test', $result['subject']);
        self::assertSame('From Yaandu at Example Co (https://example.test)', $result['body']);
    }

    public function test_php_blade_markup_and_unknown_template_expressions_are_rejected_without_execution(): void
    {
        $renderer = new CampaignTemplateRenderer;
        foreach (['{{ arbitrary_expression }}', '{{ $secret }}', '{{ system("id") }}', '<?php echo "literal"; ?>'] as $expression) {
            try {
                $renderer->render('Subject', 'Body '.$expression, []);
                self::fail('Unsupported template expression should be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertContains($exception->getMessage(), [
                    'The campaign template contains an unsupported variable.',
                    'The campaign template contains malformed variables.',
                    'Campaign templates must contain plain text only.',
                ]);
            }
        }
    }
}
