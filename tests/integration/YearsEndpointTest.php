<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Integration tests for GET /api/years.php. */
final class YearsEndpointTest extends TestCase
{
    public function testReturnsYearsNewestFirst(): void
    {
        $r = call_api('years.php', []);

        $this->assertSame(200, $r['status']);
        $this->assertIsArray($r['json']['years']);
        $this->assertNotEmpty($r['json']['years']);

        $years = $r['json']['years'];
        foreach ($years as $y) {
            $this->assertIsInt($y);
            $this->assertGreaterThanOrEqual(1900, $y);
            $this->assertLessThanOrEqual(2100, $y);
        }

        $sorted = $years;
        rsort($sorted);
        $this->assertSame($sorted, $years, 'years should be sorted newest first');
        $this->assertContains(2015, $years);
    }
}
