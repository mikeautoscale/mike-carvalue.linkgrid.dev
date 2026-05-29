<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * T10: the importer loads a fixed sample file into the expected row/dealer
 * counts. Runs against a throwaway database that is dropped afterwards.
 */
final class ImporterTest extends TestCase
{
    private const SCRATCH_DB = 'carvalue_test_import';

    protected function tearDown(): void
    {
        carvalue_pdo()->exec('DROP DATABASE IF EXISTS `' . self::SCRATCH_DB . '`');
    }

    public function testSampleFileImportsExpectedCounts(): void
    {
        $source = CARVALUE_ROOT . '/docs/sample-data-1000.txt';
        [$code, $output] = run_importer(self::SCRATCH_DB, $source);

        $this->assertSame(0, $code, "importer failed:\n{$output}");

        $pdo = carvalue_pdo(self::SCRATCH_DB);
        $listings = (int) $pdo->query('SELECT COUNT(*) FROM listings')->fetchColumn();
        $priced   = (int) $pdo->query('SELECT COUNT(*) FROM listings WHERE listing_price IS NOT NULL')->fetchColumn();
        $dealers  = (int) $pdo->query('SELECT COUNT(*) FROM dealers')->fetchColumn();

        $this->assertSame(999, $listings, 'all sample data rows imported');
        $this->assertSame(789, $priced, 'rows with a price');
        $this->assertSame(491, $dealers, 'deduplicated dealers');
    }

    public function testImportIsIdempotentOnRerun(): void
    {
        $source = CARVALUE_ROOT . '/docs/sample-data-1000.txt';
        run_importer(self::SCRATCH_DB, $source);
        [$code] = run_importer(self::SCRATCH_DB, $source); // re-run truncates + reloads

        $this->assertSame(0, $code);
        $pdo = carvalue_pdo(self::SCRATCH_DB);
        $this->assertSame(999, (int) $pdo->query('SELECT COUNT(*) FROM listings')->fetchColumn());
    }
}
