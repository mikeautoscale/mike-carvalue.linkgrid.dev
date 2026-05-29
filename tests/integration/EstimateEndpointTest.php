<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for GET /api/estimate.php against the imported database.
 * Assertions use robust properties (sign, rounding, counts, shape) rather than
 * exact dollar values so they remain stable as the data set changes.
 */
final class EstimateEndpointTest extends TestCase
{
    /** T1: a known year/make/model returns a rounded estimate with comps. */
    public function testKnownYmmReturnsEstimate(): void
    {
        $r = call_api('estimate.php', ['ymm' => '2015 Toyota Camry']);

        $this->assertSame(200, $r['status']);
        $this->assertIsInt($r['json']['estimate']);
        $this->assertGreaterThan(0, $r['json']['estimate']);
        $this->assertSame(0, $r['json']['estimate'] % 100, 'estimate must be a multiple of $100');
        $this->assertGreaterThan(0, $r['json']['sampleCount']);
        $this->assertIsArray($r['json']['listings']);
        $this->assertSame(2015, $r['json']['query']['year']);
    }

    /** T6: the supporting listings list is capped at 100. */
    public function testListingsCappedAt100(): void
    {
        $r = call_api('estimate.php', ['ymm' => '2015 Toyota Camry']);
        $this->assertGreaterThan(100, $r['json']['sampleCount'], 'precondition: a large comp set');
        $this->assertLessThanOrEqual(100, count($r['json']['listings']));

        foreach ($r['json']['listings'] as $listing) {
            $this->assertSame(['vehicle', 'price', 'mileage', 'location'], array_keys($listing));
            $this->assertGreaterThan(0, $listing['price']);
        }
    }

    /** T2: a high-mileage query estimates lower than a low-mileage one. */
    public function testMileageLowersEstimate(): void
    {
        $low  = call_api('estimate.php', ['ymm' => '2015 Toyota Camry', 'mileage' => '20000']);
        $high = call_api('estimate.php', ['ymm' => '2015 Toyota Camry', 'mileage' => '200000']);

        $this->assertSame(200, $low['status']);
        $this->assertSame(200, $high['status']);
        $this->assertLessThanOrEqual($low['json']['estimate'], $high['json']['estimate']);
    }

    /** T7: matching is case-insensitive. */
    public function testCaseInsensitive(): void
    {
        $a = call_api('estimate.php', ['ymm' => '2015 Toyota Camry']);
        $b = call_api('estimate.php', ['ymm' => '2015 toyota camry']);

        $this->assertSame($a['json']['estimate'], $b['json']['estimate']);
        $this->assertSame($a['json']['sampleCount'], $b['json']['sampleCount']);
    }

    /** T3: an unknown model returns 200 with a null estimate and no comps. */
    public function testUnknownModelReturnsNullEstimate(): void
    {
        $r = call_api('estimate.php', ['ymm' => '2015 Toyota Zzgibberishmodel']);

        $this->assertSame(200, $r['status']);
        $this->assertNull($r['json']['estimate']);
        $this->assertSame(0, $r['json']['sampleCount']);
        $this->assertSame([], $r['json']['listings']);
    }

    /** T4: the required ymm parameter is enforced. */
    public function testMissingYmmReturns400(): void
    {
        $r = call_api('estimate.php', []);
        $this->assertSame(400, $r['status']);
        $this->assertArrayHasKey('error', $r['json']);
    }

    public function testUnparseableYmmReturns400(): void
    {
        $r = call_api('estimate.php', ['ymm' => 'just some words no year']);
        $this->assertSame(400, $r['status']);
        $this->assertArrayHasKey('error', $r['json']);
    }

    public function testInvalidMileageReturns400(): void
    {
        $r = call_api('estimate.php', ['ymm' => '2015 Toyota Camry', 'mileage' => 'lots']);
        $this->assertSame(400, $r['status']);
        $this->assertArrayHasKey('error', $r['json']);
    }

    /** Mileage may be supplied with commas / units. */
    public function testMileageAcceptsFormatting(): void
    {
        $r = call_api('estimate.php', ['ymm' => '2015 Toyota Camry', 'mileage' => '150,000 miles']);
        $this->assertSame(200, $r['status']);
        $this->assertSame(150000, $r['json']['query']['mileage']);
    }
}
