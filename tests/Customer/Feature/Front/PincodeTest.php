<?php

namespace Tests\Feature\Front;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PincodeTest extends TestCase
{
    use RefreshDatabase;

    private array $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();

        // Chèn dữ liệu test vào các bảng pincode
        DB::table('cod_pincodes')->insert(['pincode' => '110001', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prepaid_pincodes')->insert(['pincode' => '110002', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Test 21.1 — cod=0 && prepaid=0 => "not available"
     * Cả 2 subcondition đều TRUE → decision TRUE
     */
    public function test_checkPincode_returns_not_available_when_pincode_not_in_either_table(): void
    {
        $this->expectOutputString('This pincode is not available for delivery');

        $response = $this->withHeaders($this->ajax)
            ->post('/check-pincode', ['pincode' => '999999']);

        $response->assertOk();
    }

    /**
     * Test 21.2 — cod != 0 (cod available) => "available"
     * Subcondition cod=0 = FALSE → decision FALSE (short-circuit)
     */
    public function test_checkPincode_returns_available_when_cod_pincode_exists(): void
    {
        $this->expectOutputString('This pincode is available for delivery');

        $response = $this->withHeaders($this->ajax)
            ->post('/check-pincode', ['pincode' => '110001']);

        $response->assertOk();
    }

    /**
     * Test 21.3 — cod=0 && prepaid != 0 (prepaid available) => "available"
     * Subcondition cod=0 = TRUE, prepaid=0 = FALSE → decision FALSE
     */
    public function test_checkPincode_returns_available_when_only_prepaid_pincode_exists(): void
    {
        $this->expectOutputString('This pincode is available for delivery');

        $response = $this->withHeaders($this->ajax)
            ->post('/check-pincode', ['pincode' => '110002']);

        $response->assertOk();
    }
}
