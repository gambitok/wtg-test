<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_cheapest_current_offer_for_each_property(): void
    {
        $supplierA = Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);
        $supplierB = Supplier::create([
            'code' => 'supplier-b',
            'name' => 'Supplier B',
        ]);
        $property = Property::create([
            'code' => 'BCN-0001',
            'name' => 'Apartment near Sagrada Familia',
            'city' => 'Barcelona',
        ]);

        $this->createOffer($supplierA, $property, [
            'external_id' => 'offer-a-10001',
            'price' => 80000,
            'available_units' => 2,
        ]);
        $cheapestOffer = $this->createOffer($supplierB, $property, [
            'external_id' => 'offer-b-10001',
            'price' => 70000,
            'available_units' => 1,
        ]);

        $response = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.best_offer.id', $cheapestOffer->id)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b')
            ->assertJsonPath('data.0.best_offer.price', 70000)
            ->assertJsonPath('data.0.best_offer.currency', 'EUR')
            ->assertJsonPath('data.0.best_offer.available_units', 1)
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('next', null)
            ->assertJsonPath('prev', null);
    }

    public function test_it_filters_city_and_offer_availability_at_database_level(): void
    {
        $supplier = Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);
        $barcelona = Property::create([
            'code' => 'BCN-0001',
            'name' => 'Barcelona Apartment',
            'city' => 'Barcelona',
        ]);
        $madrid = Property::create([
            'code' => 'MAD-0001',
            'name' => 'Madrid Apartment',
            'city' => 'Madrid',
        ]);

        $this->createOffer($supplier, $barcelona, [
            'external_id' => 'offer-unavailable',
            'price' => 1000,
            'available_units' => 0,
        ]);
        $this->createOffer($supplier, $barcelona, [
            'external_id' => 'offer-too-many-guests',
            'price' => 2000,
            'max_guests' => 1,
        ]);
        $this->createOffer($supplier, $barcelona, [
            'external_id' => 'offer-expired',
            'price' => 3000,
            'expires_at' => now()->subMinute(),
        ]);
        $this->createOffer($supplier, $barcelona, [
            'external_id' => 'offer-wrong-dates',
            'price' => 4000,
            'check_in' => '2026-10-11',
            'check_out' => '2026-10-16',
        ]);
        $this->createOffer($supplier, $barcelona, [
            'external_id' => 'offer-valid',
            'price' => 5000,
        ]);
        $this->createOffer($supplier, $madrid, [
            'external_id' => 'offer-madrid-valid',
            'price' => 1000,
        ]);

        $response = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.best_offer.price', 5000);
    }

    public function test_it_returns_pagination_links_and_honours_per_page(): void
    {
        $supplier = Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);

        foreach (['BCN-0001', 'BCN-0002'] as $index => $code) {
            $property = Property::create([
                'code' => $code,
                'name' => "Barcelona Apartment $index",
                'city' => 'Barcelona',
            ]);

            $this->createOffer($supplier, $property, [
                'external_id' => "offer-$index",
                'price' => 50000 + $index,
            ]);
        }

        $firstPage = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&per_page=1&page=1');

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('prev', null);
        $this->assertNotNull($firstPage->json('next'));

        $secondPage = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&per_page=1&page=2');

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('per_page', 1);
        $this->assertNotNull($secondPage->json('prev'));
    }

    public function test_invalid_search_dates_are_rejected(): void
    {
        $response = $this->getJson('/api/properties?check_in=2026-10-15&check_out=2026-10-10&guests=2');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_out');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOffer(Supplier $supplier, Property $property, array $overrides = []): Offer
    {
        $import = Import::create([
            'supplier_id' => $supplier->id,
            'external_import_id' => 'import-'.$supplier->code.'-'.uniqid(),
            'sent_at' => now(),
            'status' => ImportStatus::Completed,
            'total_offers' => 1,
            'processed_offers' => 1,
            'payload' => ['offers' => []],
            'completed_at' => now(),
        ]);

        return Offer::create(array_merge([
            'supplier_id' => $supplier->id,
            'import_id' => $import->id,
            'property_id' => $property->id,
            'external_id' => 'offer-'.uniqid(),
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => now()->addDay(),
        ], $overrides));
    }
}
