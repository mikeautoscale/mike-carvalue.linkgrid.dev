<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Integration tests for GET /api/models.php. */
final class ModelsEndpointTest extends TestCase
{
    public function testModelsForMake(): void
    {
        $r = call_api('models.php', ['make' => 'Toyota']);

        $this->assertSame(200, $r['status']);
        $this->assertNotEmpty($r['json']['models']);
        $lower = array_map('strtolower', $r['json']['models']);
        $this->assertContains('camry', $lower);

        foreach ($r['json']['models'] as $model) {
            $this->assertMatchesRegularExpression('/[A-Za-z]/', $model);
        }
    }

    public function testModelsNarrowedByYear(): void
    {
        $all  = call_api('models.php', ['make' => 'Toyota']);
        $year = call_api('models.php', ['make' => 'Toyota', 'year' => '2015']);

        $this->assertSame(200, $year['status']);
        $this->assertNotEmpty($year['json']['models']);
        $this->assertContains('camry', array_map('strtolower', $year['json']['models']));
        $this->assertLessThanOrEqual(count($all['json']['models']), count($year['json']['models']));
    }

    public function testMissingMakeReturns400(): void
    {
        $r = call_api('models.php', []);
        $this->assertSame(400, $r['status']);
        $this->assertArrayHasKey('error', $r['json']);
    }

    public function testInvalidYearReturns400(): void
    {
        $r = call_api('models.php', ['make' => 'Toyota', 'year' => 'soon']);
        $this->assertSame(400, $r['status']);
    }

    public function testUnknownMakeReturnsEmpty(): void
    {
        $r = call_api('models.php', ['make' => 'Zzgibberishmake']);
        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['json']['models']);
    }
}
