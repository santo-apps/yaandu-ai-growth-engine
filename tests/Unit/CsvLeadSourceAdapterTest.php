<?php

namespace Tests\Unit;

use App\LeadDiscovery\CsvLeadSourceAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CsvLeadSourceAdapterTest extends TestCase
{
    public function test_it_accepts_a_utf8_csv_with_bom_and_rejects_invalid_utf8(): void
    {
        $adapter = new CsvLeadSourceAdapter();
        $path = tempnam(sys_get_temp_dir(), 'lead-csv-');
        self::assertIsString($path);

        try {
            file_put_contents($path, "\xEF\xBB\xBFcompany_name,website\nNorthstar Retail,https://northstar.example\n");
            self::assertSame('Northstar Retail', $adapter->readCsv($path, 10)[0]['company_name']);

            file_put_contents($path, "company_name,website\nNorthstar\xFF,https://northstar.example\n");
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('valid UTF-8');
            $adapter->readCsv($path, 10);
        } finally {
            @unlink($path);
        }
    }
}
