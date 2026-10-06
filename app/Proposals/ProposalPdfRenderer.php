<?php

namespace App\Proposals;

use RuntimeException;

/** Small deterministic text PDF renderer. It does not parse HTML, fetch assets, or execute templates. */
final class ProposalPdfRenderer implements ProposalDocumentRendererInterface
{
    public function render(array $proposal): string
    {
        foreach (['title','currency','total','sections'] as $required) if (! array_key_exists($required, $proposal)) throw new RuntimeException('Approved proposal data is incomplete.');
        $lines = [$proposal['tenant_name'] ?? 'Proposal', $proposal['title'], 'Prepared for: '.($proposal['company_name'] ?? 'Client'), 'Version '.(int) ($proposal['version'] ?? 1), ''];
        $labels = ['executive_summary' => 'Executive Summary', 'client_understanding' => 'Understanding of Requirements', 'objectives' => 'Objectives',
            'recommended_solution' => 'Recommended Solution', 'scope' => 'Scope of Work', 'deliverables' => 'Deliverables', 'implementation_approach' => 'Implementation Approach',
            'timeline_narrative' => 'Timeline', 'commercial_narrative' => 'Commercial Narrative', 'assumptions' => 'Assumptions', 'dependencies' => 'Dependencies',
            'exclusions' => 'Exclusions', 'risks' => 'Risks', 'next_steps' => 'Next Steps'];
        foreach ($proposal['sections'] as $heading => $content) {
            $lines[] = strtoupper($labels[$heading] ?? (string) $heading);
            if (is_array($content)) foreach ($content as $item) $lines[] = '- '.(is_scalar($item) ? (string) $item : json_encode($item));
            else $lines[] = (string) $content;
            $lines[] = '';
        }
        $lines[] = 'COMMERCIALS';
        foreach (($proposal['items'] ?? []) as $item) $lines[] = $item['name'].' | '.$item['quantity'].' '.$item['unit'].' | '.$proposal['currency'].' '.$item['line_total'];
        $lines[] = 'Total: '.$proposal['currency'].' '.$proposal['total'];
        if (! empty($proposal['valid_until'])) $lines[] = 'Validity: '.$proposal['valid_until'];
        $wrapped = [];
        foreach ($lines as $line) foreach (explode("\n", wordwrap($this->plain((string) $line), 92, "\n", true)) as $piece) $wrapped[] = $piece;
        $pages = array_chunk($wrapped, 48);
        if ($pages === []) $pages = [['Proposal']];
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pageRefs = [];
        foreach ($pages as $pageIndex => $pageLines) {
            $pageId = 4 + $pageIndex * 2; $contentId = $pageId + 1; $pageRefs[] = "$pageId 0 R";
            $stream = "BT\n/F1 10 Tf\n50 790 Td\n14 TL\n";
            foreach ($pageLines as $line) $stream .= '('.$this->escape($line).") Tj\nT*\n";
            $stream .= 'ET';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R >> >> /Contents $contentId 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream).">>\nstream\n$stream\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageRefs).'] /Count '.count($pages).' >>'; ksort($objects);
        $pdf = "%PDF-1.4\n%YAANDU\n"; $offsets = [0];
        foreach ($objects as $id => $body) { $offsets[$id] = strlen($pdf); $pdf .= "$id 0 obj\n$body\nendobj\n"; }
        $xref = strlen($pdf); $max = max(array_keys($objects)); $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0)."\n";
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }

    private function plain(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return preg_replace('/[^\x20-\x7E]/', '', $ascii === false ? $text : $ascii) ?? '';
    }

    private function escape(string $text): string { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $text); }
}
