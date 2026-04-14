<?php

namespace Tests\Unit\Models;

use App\Models\ShippingCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Set up default shipping charges for India and USA
        ShippingCharge::factory()->forCountry('India')->create();
        ShippingCharge::factory()->forCountry('USA')->create(['0_500g' => 80]);
    }

    /**
     * Data Provider for L4 boundary value testing
     * Covers: weight > 0 && weight <= X for each bracket ✓
     * Format: [weight, country, expectedRate, testDescription]
     */
    public static function shippingChargeDataProvider(): array
    {
        return [
            // Test 5.1: weight = 0 → outer condition false → rate = 0
            [ 0, 'India', 0, 'weight=0 outer false' ],
            
            // Test 5.2: weight = 250 → bracket 1 (0 < w <= 500)
            [ 250, 'India', 50, 'weight=250 bracket 0-500' ],
            
            // Test 5.3: boundary upper of bracket 1, w <= 500 = TRUE
            [ 500, 'India', 50, 'weight=500 boundary bracket 0-500' ],
            
            // Test 5.4: weight = 501 → bracket 2 (501 <= 1000)
            [ 501, 'India', 100, 'weight=501 bracket 501-1000' ],
            
            // Test 5.5: boundary upper of bracket 2, w <= 1000 = TRUE
            [ 1000, 'India', 100, 'weight=1000 boundary bracket 501-1000' ],
            
            // Test 5.6: weight = 1001 → bracket 3 (1001 <= 2000)
            [ 1001, 'India', 150, 'weight=1001 bracket 1001-2000' ],
            
            // Test 5.7: boundary upper of bracket 3, w <= 2000 = TRUE
            [ 2000, 'India', 150, 'weight=2000 boundary bracket 1001-2000' ],
            
            // Test 5.8: weight = 2001 → bracket 4 (2001 <= 5000)
            [ 2001, 'India', 200, 'weight=2001 bracket 2001-5000' ],
            
            // Test 5.9: boundary upper of bracket 4, w <= 5000 = TRUE
            [ 5000, 'India', 200, 'weight=5000 boundary bracket 2001-5000' ],
            
            // Test 5.10: weight = 5001 → bracket 5 (> 5000)
            [ 5001, 'India', 300, 'weight=5001 above 5000' ],
            
            // Country-specific test: USA has different rate for 0-500g bracket
            [ 250, 'USA', 80, 'weight=250 USA country rate 80' ],
            [ 250, 'India', 50, 'weight=250 India country rate 50' ],
        ];
    }

    /**
     * @dataProvider shippingChargeDataProvider
     * Parametrized L4 test covering all weight brackets with boundary values
     */
    public function test_shipping_charge_by_weight_and_country(
        int $weight,
        string $country,
        int $expectedRate,
        string $description
    ): void {
        $actual = ShippingCharge::getShippingCharges($weight, $country);
        $this->assertEquals(
            $expectedRate,
            $actual,
            "Failed: $description (weight=$weight, country=$country)"
        );
    }
}
