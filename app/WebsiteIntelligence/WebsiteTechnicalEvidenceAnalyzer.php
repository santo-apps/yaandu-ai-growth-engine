<?php

namespace App\WebsiteIntelligence;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Deterministic observations from already-crawled page snapshots. Never makes network requests. */
final class WebsiteTechnicalEvidenceAnalyzer
{
    private const VERSION = 'deterministic-v1';
    private const MAX_HTML_BYTES = 1_500_000;

    public function analyze(string $tenantId, string $scanId): array
    {
        $scan = DB::table('website_scans')->where('tenant_id', $tenantId)->where('id', $scanId)->first();
        if (! $scan || $scan->status !== 'completed') {
            throw new RuntimeException('A completed website scan in this tenant is required.');
        }

        $pages = DB::table('website_pages')->where('tenant_id', $tenantId)->where('website_scan_id', $scanId)
            ->orderBy('depth')->orderBy('requested_url')->limit(100)->get();
        $technologies = [];
        $issues = [];
        foreach ($pages as $page) {
            $html = $this->pageHtml($page);
            if ($html === '') continue;
            $document = $this->parse($html);
            if (! $document) continue;
            $url = $this->safeSourceUrl((string) ($page->final_url ?: $page->requested_url));
            if (! $url) continue;
            $this->inspectPage($document, $page, $url, $technologies, $issues);
        }

        DB::transaction(function () use ($tenantId, $scanId, $technologies, $issues): void {
            $existingIssues = [];
            foreach (DB::table('website_issues')->where('tenant_id', $tenantId)->where('website_scan_id', $scanId)->where('detector_version', self::VERSION)->get(['id', 'website_page_id', 'type', 'evidence', 'created_at']) as $row) {
                $metadata = $this->decodeEvidence($row->evidence);
                $existingIssues[json_encode([$row->website_page_id, $row->type, $metadata['marker'] ?? ''], JSON_THROW_ON_ERROR)] = (string) $row->id;
            }
            $existingTechnologies = [];
            foreach (DB::table('website_technologies')->where('tenant_id', $tenantId)->where('website_scan_id', $scanId)->where('detection_method', self::VERSION)->get(['id', 'website_page_id', 'name']) as $row) {
                $existingTechnologies[json_encode([$row->website_page_id, $row->name], JSON_THROW_ON_ERROR)] = (string) $row->id;
            }
            $now = now();
            $keptIssueIds = [];
            foreach (array_slice($issues, 0, 500, true) as $issue) {
                $key = json_encode([$issue['page_id'], $issue['type'], $issue['marker']], JSON_THROW_ON_ERROR);
                $id = $existingIssues[$key] ?? (string) Str::uuid();
                $values = ['tenant_id' => $tenantId,
                    'website_scan_id' => $scanId, 'website_page_id' => $issue['page_id'], 'type' => $issue['type'],
                    'severity' => $issue['severity'], 'summary' => $issue['summary'],
                    'evidence' => json_encode(['source_url' => $issue['url'], 'excerpt' => $issue['excerpt'], 'marker' => $issue['marker'], 'detector' => self::VERSION], JSON_THROW_ON_ERROR),
                    'confidence' => $issue['confidence'], 'detector_version' => self::VERSION, 'updated_at' => $now];
                if (isset($existingIssues[$key])) DB::table('website_issues')->where('tenant_id', $tenantId)->where('id', $id)->update($values);
                else DB::table('website_issues')->insert(['id' => $id, ...$values, 'created_at' => $now]);
                $keptIssueIds[] = $id;
            }
            $keptTechnologyIds = [];
            foreach (array_slice($technologies, 0, 500, true) as $technology) {
                $key = json_encode([$technology['page_id'], $technology['name']], JSON_THROW_ON_ERROR);
                $id = $existingTechnologies[$key] ?? (string) Str::uuid();
                $values = ['tenant_id' => $tenantId,
                    'website_scan_id' => $scanId, 'website_page_id' => $technology['page_id'], 'agent_run_id' => null,
                    'name' => $technology['name'], 'category' => $technology['category'], 'detection_method' => self::VERSION,
                    'confidence' => $technology['confidence'], 'evidence' => json_encode(['source_url' => $technology['url'], 'excerpt' => $technology['excerpt'], 'marker' => $technology['marker'], 'detector' => self::VERSION], JSON_THROW_ON_ERROR),
                    'updated_at' => $now];
                if (isset($existingTechnologies[$key])) DB::table('website_technologies')->where('tenant_id', $tenantId)->where('id', $id)->update($values);
                else DB::table('website_technologies')->insert(['id' => $id, ...$values, 'created_at' => $now]);
                $keptTechnologyIds[] = $id;
            }
            $staleIssues = DB::table('website_issues')->where('tenant_id', $tenantId)->where('website_scan_id', $scanId)->where('detector_version', self::VERSION);
            $keptIssueIds === [] ? $staleIssues->delete() : $staleIssues->whereNotIn('id', $keptIssueIds)->delete();
            $staleTechnologies = DB::table('website_technologies')->where('tenant_id', $tenantId)->where('website_scan_id', $scanId)->where('detection_method', self::VERSION);
            $keptTechnologyIds === [] ? $staleTechnologies->delete() : $staleTechnologies->whereNotIn('id', $keptTechnologyIds)->delete();
        });

        return ['pages_inspected' => $pages->count(), 'issues' => count($issues), 'technologies' => count($technologies), 'detector_version' => self::VERSION];
    }

    private function decodeEvidence(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value)) return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function pageHtml(object $page): string
    {
        $html = '';
        if (filled($page->object_key) && Storage::disk(config('filesystems.default'))->exists($page->object_key)) {
            $html = (string) Storage::disk(config('filesystems.default'))->get($page->object_key);
        }
        if ($html === '') $html = (string) ($page->extracted_text ?? '');
        return substr($html, 0, self::MAX_HTML_BYTES);
    }

    private function parse(string $html): ?DOMXPath
    {
        if (! class_exists(DOMDocument::class)) return null;
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (! $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) return null;
            return new DOMXPath($document);
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function inspectPage(DOMXPath $xpath, object $page, string $url, array &$technologies, array &$issues): void
    {
        $html = $xpath->document->saveHTML() ?: '';
        $lower = mb_strtolower($html);
        $addTech = function (string $name, string $category, string $marker, float $confidence = 0.95) use (&$technologies, $page, $url): void {
            $key = $page->id.'|'.$name;
            $technologies[$key] ??= ['page_id' => $page->id, 'url' => $url, 'name' => $name, 'category' => $category,
                'marker' => mb_substr($marker, 0, 240), 'excerpt' => mb_substr($marker, 0, 300), 'confidence' => $confidence];
        };
        $addIssue = function (string $type, string $summary, string $marker, float $confidence = 0.95, string $severity = 'low') use (&$issues, $page, $url): void {
            $key = $page->id.'|'.$type.'|'.$marker;
            $issues[$key] ??= ['page_id' => $page->id, 'url' => $url, 'type' => $type, 'summary' => mb_substr($summary, 0, 500),
                'marker' => mb_substr($marker, 0, 240), 'excerpt' => mb_substr($marker, 0, 300), 'confidence' => $confidence, 'severity' => $severity];
        };

        // Responsive metadata is an observation, not proof of good or poor UX.
        $viewports = $xpath->query('//meta[translate(@name,"VIEWPORT","viewport")="viewport"]');
        if ($viewports && $viewports->length > 0) {
            $content = mb_substr((string) $viewports->item(0)?->attributes?->getNamedItem('content')?->nodeValue, 0, 180);
            $addTech('Responsive viewport metadata', 'responsive', 'meta name="viewport" content="'.$content.'"', 0.99);
            if (preg_match('/(?:user-scalable\s*=\s*no|maximum-scale\s*=\s*1(?:\.0+)?)(?:\s|,|$)/i', $content)) {
                $addIssue('poor_mobile_ux', 'Viewport metadata disables user zoom on the inspected page.', 'viewport content="'.$content.'"', 0.99, 'medium');
            }
        }
        if (str_contains($lower, '@media')) $addTech('Responsive CSS media query', 'responsive', 'Embedded stylesheet contains @media.', 0.9);

        $visibleText = mb_strtolower($xpath->document->textContent ?? '');
        $strongCaptureSignals = [];
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (! $form instanceof DOMElement) continue;
            $formText = mb_strtolower(trim($form->textContent.' '.$form->getAttribute('action')));
            $fields = $xpath->query('.//input[@type="email" or @type="tel"] | .//textarea', $form)?->length ?? 0;
            if ($fields > 0 || preg_match('/contact|enquir|inquir|quote|request|book|consult|message|sales/', $formText)) {
                $strongCaptureSignals[] = 'contact/enquiry form observed';
                break;
            }
        }
        foreach ($xpath->query('//a[@href] | //button | //input[@type="submit"]') ?: [] as $control) {
            if (! $control instanceof DOMElement) continue;
            $text = trim($control->textContent.' '.$control->getAttribute('value'));
            $intent = $this->ctaIntent($text);
            if ($intent !== null) {
                $addTech('Contact / enquiry CTA', 'contact_navigation', 'Visible CTA label: '.mb_substr(trim($text), 0, 80), 0.95);
            }
            $href = trim($control->getAttribute('href'));
            if (str_starts_with(mb_strtolower($href), 'tel:')) {
                $strongCaptureSignals[] = 'telephone link observed';
                $addTech('Telephone contact link', 'contact_channel', 'tel: contact link observed.', 0.99);
            }
            if (str_starts_with(mb_strtolower($href), 'mailto:')) {
                $strongCaptureSignals[] = 'email link observed';
                $addTech('Email contact link', 'contact_channel', 'mailto: contact link observed.', 0.99);
            }
            if ($href !== '' && preg_match('/(?:^|[\/#?_-])(?:contact|enquiry|inquiry|quote)(?:[\/#?_.-]|$)/i', $href)) {
                $addTech('Contact page link', 'contact_navigation', 'Link to a contact/enquiry/quote path observed.', 0.95);
            }
            if ($this->isWhatsAppHref($href)) {
                $host = parse_url($href, PHP_URL_HOST);
                $addTech('WhatsApp business contact', 'contact_channel', 'WhatsApp contact target at '.(is_string($host) ? strtolower($host) : 'whatsapp://').' observed.', 0.99);
            }
        }
        $explicitWhatsAppAbsence = preg_match('/\b(?:no|not|never|without|unavailable|unsupported)\s+(?:use|offer|support|provide)?\s*(?:a\s+)?whatsapp\b|\bwhatsapp\s+(?:is\s+)?(?:not|unavailable|unsupported)\b/i', $visibleText) === 1;
        $whatsAppContactText = preg_match('/\b(?:contact|message|chat|call|reach|text)\b.{0,50}\bwhatsapp\b|\bwhatsapp\b.{0,50}\b(?:contact|message|chat|us|business)\b/i', $visibleText) === 1;
        if (! $explicitWhatsAppAbsence && $whatsAppContactText) {
            $addTech('WhatsApp business contact', 'contact_channel', 'Visible page text explicitly offers WhatsApp as a contact channel.', 0.95);
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $visibleText)) {
            $strongCaptureSignals[] = 'public email address observed';
        }
        if (preg_match('/\b(?:phone|telephone|tel|call us|mobile)\b.{0,80}(?<!\d)(?:\+\d{1,3}[\s().-]?)?(?:\(?\d{2,5}\)?[\s().-]){1,3}\d{3,4}(?!\d)/i', $xpath->document->textContent ?? '')) {
            $strongCaptureSignals[] = 'public business phone number observed';
        }
        if ($strongCaptureSignals !== []) {
            $signals = array_values(array_unique($strongCaptureSignals));
            $marker = implode('; ', array_slice($signals, 0, 5));
            $addIssue('lead_capture_mechanism_present', 'A public contact or enquiry mechanism was observed: '.$marker.'.', $marker, 0.96);
        }

        $this->detectTechnologies($lower, $addTech);
        $this->detectCommerce($xpath, $lower, $addTech);
        $this->detectLegacy($xpath, $addTech, $addIssue);
        $this->detectSeo($xpath, $addIssue, $addTech);
    }

    private function detectTechnologies(string $html, callable $addTech): void
    {
        $signatures = [
            ['WordPress', 'cms', ['/wp-content/', '/wp-includes/', 'name="generator" content="wordpress']],
            ['Laravel', 'framework', ['name="generator" content="laravel', 'content="laravel" name="generator']],
            ['WooCommerce', 'ecommerce', ['woocommerce', 'wc-block-cart']],
            ['Shopify', 'ecommerce', ['cdn.shopify.com', 'shopify.theme', 'shopify-buy']],
            ['Magento / Adobe Commerce', 'ecommerce', ['mage/cookies', 'magento_', 'data-mage-init']],
            ['Drupal', 'cms', ['drupal-settings-json', 'drupal.js']],
            ['Joomla', 'cms', ['option=com_joomla', '/media/system/js/']],
            ['Wix', 'site_builder', ['wixstatic.com', 'wixsite.com']],
            ['Squarespace', 'site_builder', ['static1.squarespace.com', 'squarespace.com/universal/scripts-compressed/']],
            ['Webflow', 'site_builder', ['data-wf-page=', 'webflow.js']],
            ['Next.js', 'framework', ['/_next/', '__next_data__']],
            ['Nuxt', 'framework', ['__nuxt__', '/_nuxt/']],
            ['React', 'framework', ['data-reactroot', 'react-dom']],
            ['Vue', 'framework', ['data-v-app=', 'vue.runtime.', '__vue__']],
            ['HubSpot web-form integration', 'crm', ['js.hsforms.net', 'hbspt.forms.create', 'hsforms.com']],
            ['Salesforce web-form integration', 'crm', ['salesforce.com/servlet/servlet.webtolead', 'pardot.com']],
            ['Zoho CRM web-form integration', 'crm', ['zoho.com/crm/webform', 'crm.zoho.']],
        ];
        foreach ($signatures as [$name, $category, $markers]) {
            foreach ($markers as $marker) {
                if (str_contains($html, $marker)) { $addTech($name, $category, 'HTML signature observed: '.$marker, 0.94); break; }
            }
        }
    }

    private function detectCommerce(DOMXPath $xpath, string $html, callable $addTech): void
    {
        foreach ($xpath->query('//a[@href] | //button | //input[@type="submit"]') ?: [] as $element) {
            if (! $element instanceof DOMElement) continue;
            $text = mb_strtolower($element->textContent.' '.$element->getAttribute('value').' '.$element->getAttribute('href'));
            if (preg_match('/add\s+to\s+(?:cart|basket)|\b(?:cart|basket|checkout)\b/', $text)) {
                $addTech('E-commerce cart / checkout capability', 'ecommerce', 'Cart, basket, checkout, or add-to-cart control observed.', 0.98);
                return;
            }
        }
        if (str_contains($html, 'schema.org/product') || preg_match('/["\']@type["\']\s*:\s*["\']product["\']/i', $html)
            || preg_match('/href=["\'][^"\']*\/products?\/[^"\']+/i', $html)) {
            $addTech('Product listing / detail capability', 'ecommerce', 'Product structured-data marker observed.', 0.94);
        }
    }

    private function detectLegacy(DOMXPath $xpath, callable $addTech, callable $addIssue): void
    {
        $legacyTags = ['marquee', 'blink', 'font', 'frameset', 'frame', 'applet', 'big', 'strike'];
        foreach ($legacyTags as $tag) {
            if (($xpath->query('//'.$tag)?->length ?? 0) > 0) {
                $marker = 'Deprecated HTML element <'.$tag.'> observed.';
                $addTech('Legacy deprecated HTML: '.$tag, 'legacy', $marker, 0.99);
                $addIssue('outdated_website', 'A deprecated HTML element was observed in the page source.', $marker, 0.99, 'medium');
            }
        }
        foreach ($xpath->query('//object[@data] | //embed[@src]') ?: [] as $element) {
            if (! $element instanceof DOMElement) continue;
            $asset = $element->getAttribute('data') ?: $element->getAttribute('src');
            if (preg_match('/\.swf(?:$|[?#])/i', $asset) || str_contains(mb_strtolower($element->getAttribute('type')), 'shockwave-flash')) {
                $addTech('Legacy Flash content', 'legacy', 'Flash/SWF object or embed marker observed.', 0.99);
                $addIssue('outdated_website', 'Legacy Flash content is embedded in the page.', 'Flash/SWF object or embed marker observed.', 0.99, 'high');
            }
        }
    }

    private function detectSeo(DOMXPath $xpath, callable $addIssue, callable $addTech): void
    {
        $observations = [
            ['//title[normalize-space(.)!=""]', 'Document title present', 'text'],
            ['//meta[translate(@name,"DESCRIPTION","description")="description" and normalize-space(@content)!=""]', 'Meta description present', 'content'],
            ['//link[translate(@rel,"CANONICAL","canonical")="canonical" and normalize-space(@href)!=""]', 'Canonical link present', 'href'],
            ['//meta[translate(@name,"ROBOTS","robots")="robots" and normalize-space(@content)!=""]', 'Robots meta directive present', 'content'],
            ['//script[translate(@type,"APPLICATION/LD+JSON","application/ld+json")="application/ld+json"]', 'JSON-LD structured data present', 'structured_data'],
            ['//html[@lang and normalize-space(@lang)!=""]', 'HTML language present', 'lang'],
            ['//h1[normalize-space(.)!=""]', 'H1 heading present', 'text'],
        ];
        foreach ($observations as [$query, $name, $field]) {
            $node = $xpath->query($query)?->item(0);
            if (! $node) continue;
            $value = match ($field) {
                'text' => trim($node->textContent), 'structured_data' => 'application/ld+json block present',
                'href' => $this->safeSourceUrl((string) $node->attributes?->getNamedItem('href')?->nodeValue) ?? 'canonical link present',
                default => trim((string) $node->attributes?->getNamedItem($field)?->nodeValue),
            };
            $addTech($name, 'seo_metadata', mb_substr($name.': '.$value, 0, 240), 0.99);
        }
        $checks = [
            ['//title[normalize-space(.)!=""]', 'seo_missing_title', 'No non-empty document title was found.'],
            ['//meta[translate(@name,"DESCRIPTION","description")="description" and normalize-space(@content)!=""]', 'seo_missing_meta_description', 'No non-empty meta description was found.'],
            ['//link[translate(@rel,"CANONICAL","canonical")="canonical" and normalize-space(@href)!=""]', 'seo_missing_canonical', 'No canonical link tag was found.'],
            ['//h1[normalize-space(.)!=""]', 'seo_missing_h1', 'No non-empty H1 heading was found.'],
            ['//html[@lang and normalize-space(@lang)!=""]', 'seo_missing_language', 'No HTML language declaration was found.'],
        ];
        foreach ($checks as [$query, $type, $message]) {
            if (($xpath->query($query)?->length ?? 0) === 0) $addIssue($type, $message, $message, 0.98);
        }
    }

    private function ctaIntent(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') return null;
        foreach (['contact', 'enquire', 'enquiry', 'inquire', 'inquiry', 'request quote', 'get a quote', 'quote', 'book', 'get started', 'talk to us', 'talk with us', 'call us', 'message us'] as $intent) {
            if (str_contains($text, $intent)) return 'CTA';
        }
        return null;
    }

    private function isWhatsAppHref(string $href): bool
    {
        $lower = mb_strtolower($href);
        if (str_starts_with($lower, 'whatsapp://')) return true;
        $host = parse_url($href, PHP_URL_HOST);
        return is_string($host) && in_array(mb_strtolower($host), ['wa.me', 'api.whatsapp.com', 'whatsapp.com', 'www.whatsapp.com'], true);
    }

    private function safeSourceUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) return null;
        $host = strtolower($parts['host']);
        if (isset($parts['user']) || isset($parts['pass'])) return null;
        return strtolower($parts['scheme']).'://'.$host.($parts['port'] ?? null ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
    }
}
