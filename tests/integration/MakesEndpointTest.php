<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Integration tests for GET /api/makes.php. */
final class MakesEndpointTest extends TestCase
{
    public function testReturnsMakeList(): void
    {
        $r = call_api('makes.php', []);

        $this->assertSame(200, $r['status']);
        $this->assertIsArray($r['json']['makes']);
        $this->assertNotEmpty($r['json']['makes']);
    }

    public function testContainsKnownBrands(): void
    {
        $r = call_api('makes.php', []);
        $lower = array_map('strtolower', $r['json']['makes']);

        foreach (['toyota', 'ford', 'honda'] as $brand) {
            $this->assertContains($brand, $lower, "expected makes to include {$brand}");
        }
    }

    /** Junk values are filtered: every make contains a letter. */
    public function testExcludesNonAlphaJunk(): void
    {
        $r = call_api('makes.php', []);
        foreach ($r['json']['makes'] as $make) {
            $this->assertMatchesRegularExpression('/[A-Za-z]/', $make);
        }
        // Sanity: the unfiltered list had >1000 entries; filtering trims it hard.
        $this->assertLessThan(400, count($r['json']['makes']));
    }
}
