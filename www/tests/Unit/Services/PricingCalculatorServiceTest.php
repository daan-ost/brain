<?php

namespace Tests\Unit\Services;

use App\Models\License;
use App\Services\PricingCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PricingCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PricingCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PricingCalculatorService;
    }

    // ===========================================
    // VAT RATE TESTS
    // ===========================================

    #[Test]
    public function dutch_company_with_vat_id_pays_21_percent_vat()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'NL', 'NL123456789B01', true);

        $this->assertEquals(21.0, $pricing['vat_rate']);
        $this->assertEquals(21.0, $pricing['tax_amount']);
        $this->assertTrue($pricing['vat_applicable']);
        $this->assertFalse($pricing['vat_reverse_charge']);
    }

    #[Test]
    public function dutch_individual_pays_21_percent_vat()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'NL', null, false);

        $this->assertEquals(21.0, $pricing['vat_rate']);
        $this->assertEquals(21.0, $pricing['tax_amount']);
        $this->assertTrue($pricing['vat_applicable']);
        $this->assertFalse($pricing['vat_reverse_charge']);
    }

    #[Test]
    public function german_company_with_vat_id_gets_reverse_charge()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'DE', 'DE123456789', true);

        $this->assertEquals(0.0, $pricing['vat_rate']);
        $this->assertEquals(0.0, $pricing['tax_amount']);
        $this->assertFalse($pricing['vat_applicable']);
        $this->assertTrue($pricing['vat_reverse_charge']);
    }

    #[Test]
    public function belgian_company_with_vat_id_gets_reverse_charge()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'BE', 'BE0123456789', true);

        $this->assertEquals(0.0, $pricing['vat_rate']);
        $this->assertEquals(0.0, $pricing['tax_amount']);
        $this->assertFalse($pricing['vat_applicable']);
        $this->assertTrue($pricing['vat_reverse_charge']);
    }

    #[Test]
    public function german_individual_pays_21_percent_vat()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'DE', null, false);

        $this->assertEquals(21.0, $pricing['vat_rate']);
        $this->assertEquals(21.0, $pricing['tax_amount']);
        $this->assertTrue($pricing['vat_applicable']);
        $this->assertFalse($pricing['vat_reverse_charge']);
    }

    #[Test]
    public function us_customer_pays_no_vat()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'USD']);

        $pricing = $this->service->calculatePricing($license, 'US', null, false);

        $this->assertEquals(0.0, $pricing['vat_rate']);
        $this->assertEquals(0.0, $pricing['tax_amount']);
        $this->assertFalse($pricing['vat_applicable']);
        $this->assertFalse($pricing['vat_reverse_charge']);
    }

    #[Test]
    public function uk_post_brexit_pays_no_vat()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'GB', 'GB123456789', true);

        $this->assertEquals(0.0, $pricing['vat_rate']);
        $this->assertEquals(0.0, $pricing['tax_amount']);
        $this->assertFalse($pricing['vat_applicable']);
        $this->assertFalse($pricing['vat_reverse_charge']);
    }

    #[Test]
    public function eu_company_without_vat_id_pays_21_percent()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'FR', null, true);

        $this->assertEquals(21.0, $pricing['vat_rate']);
        $this->assertEquals(21.0, $pricing['tax_amount']);
        $this->assertTrue($pricing['vat_applicable']);
        $this->assertFalse($pricing['vat_reverse_charge']);
    }

    // ===========================================
    // EU COUNTRY DETECTION TESTS
    // ===========================================

    #[Test]
    public function is_eu_country_returns_true_for_eu_members()
    {
        $euCountries = ['NL', 'DE', 'BE', 'FR', 'IT', 'ES', 'PT', 'AT', 'PL', 'SE'];

        foreach ($euCountries as $country) {
            $this->assertTrue(
                $this->service->isEuCountry($country),
                "Expected {$country} to be identified as EU country"
            );
        }
    }

    #[Test]
    public function is_eu_country_returns_false_for_non_eu()
    {
        $nonEuCountries = ['US', 'GB', 'UK', 'CH', 'NO', 'AU', 'CA', 'JP'];

        foreach ($nonEuCountries as $country) {
            $this->assertFalse(
                $this->service->isEuCountry($country),
                "Expected {$country} to NOT be identified as EU country"
            );
        }
    }

    #[Test]
    public function country_codes_are_case_insensitive()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        // Lowercase NL should still be treated as Netherlands
        $pricing = $this->service->calculatePricing($license, 'nl', 'NL123456789B01', true);

        $this->assertEquals(21.0, $pricing['vat_rate']);
        $this->assertFalse($pricing['vat_reverse_charge']);
    }

    // ===========================================
    // CURRENCY TESTS
    // ===========================================

    #[Test]
    public function eur_currency_for_eu_and_european_countries()
    {
        $this->assertEquals('EUR', $this->service->determineCurrency('NL'));
        $this->assertEquals('EUR', $this->service->determineCurrency('DE'));
        $this->assertEquals('EUR', $this->service->determineCurrency('GB'));
        $this->assertEquals('EUR', $this->service->determineCurrency('CH'));
        $this->assertEquals('EUR', $this->service->determineCurrency('NO'));
    }

    #[Test]
    public function usd_currency_for_non_european_countries()
    {
        $this->assertEquals('USD', $this->service->determineCurrency('US'));
        $this->assertEquals('USD', $this->service->determineCurrency('AU'));
        $this->assertEquals('USD', $this->service->determineCurrency('JP'));
        $this->assertEquals('USD', $this->service->determineCurrency('CA'));
    }

    // ===========================================
    // AMOUNT CALCULATION TESTS
    // ===========================================

    #[Test]
    public function gross_amount_includes_vat_when_applicable()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'NL', null, false);

        $this->assertEquals(100, $pricing['net_amount']);
        $this->assertEquals(21.0, $pricing['tax_amount']);
        $this->assertEquals(121.0, $pricing['gross_amount']);
    }

    #[Test]
    public function gross_equals_net_when_no_vat()
    {
        $license = License::factory()->create(['amount' => 100, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'DE', 'DE123456789', true);

        $this->assertEquals(100, $pricing['net_amount']);
        $this->assertEquals(0.0, $pricing['tax_amount']);
        $this->assertEquals(100, $pricing['gross_amount']);
    }

    // ===========================================
    // ROUNDING FIX TESTS
    // ===========================================

    #[Test]
    public function gross_rounds_to_nearest_whole_before_deriving_tax()
    {
        // net=28.93, naive: tax=round(28.93*0.21,2)=6.08, gross=28.93+6.08=35.01  ← wrong
        // correct:  gross=round(28.93*1.21)=35.00, tax=35.00-28.93=6.07
        $license = License::factory()->create(['amount' => 28.93, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'NL', null, false);

        $this->assertEquals(28.93, $pricing['net_amount']);
        $this->assertEquals(35.0, $pricing['gross_amount']);
        $this->assertEquals(6.07, $pricing['tax_amount']);
    }

    #[Test]
    public function net_plus_tax_always_equals_gross()
    {
        // Ensures no rounding gap: net + tax must exactly equal gross
        $license = License::factory()->create(['amount' => 28.93, 'currency' => 'EUR']);

        $pricing = $this->service->calculatePricing($license, 'NL', null, false);

        $this->assertEquals(
            $pricing['gross_amount'],
            round($pricing['net_amount'] + $pricing['tax_amount'], 2)
        );
    }

    #[Test]
    public function rounding_holds_for_various_non_round_amounts()
    {
        // Each case: gross = round(net * 1.21), tax = gross - net
        $cases = [
            ['net' => 14.46, 'expected_gross' => 17.0],  // 14.46*1.21=17.4966→17
            ['net' => 41.32, 'expected_gross' => 50.0],  // 41.32*1.21=50.0,  exact
            ['net' => 99.17, 'expected_gross' => 120.0], // 99.17*1.21=119.9957→120
        ];

        foreach ($cases as $case) {
            $license = License::factory()->create(['amount' => $case['net'], 'currency' => 'EUR']);
            $pricing = $this->service->calculatePricing($license, 'NL', null, false);

            $this->assertEquals(
                $case['expected_gross'],
                $pricing['gross_amount'],
                "Failed for net={$case['net']}: expected gross={$case['expected_gross']}"
            );
            $this->assertEquals(
                $pricing['gross_amount'],
                round($pricing['net_amount'] + $pricing['tax_amount'], 2),
                "net+tax≠gross for net={$case['net']}"
            );
        }
    }
}
