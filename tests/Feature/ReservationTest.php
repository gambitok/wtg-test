<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_reservation_and_decrements_available_units(): void
    {
        $offer = $this->createOffer();

        $response = $this->postJson("/api/offers/$offer->id/reservations", [
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ]);

        $reservationId = $response->json('data.id');

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $reservationId)
            ->assertJsonPath('data.offer_id', $offer->id)
            ->assertJsonPath('data.client_reference', 'web-order-9f782b1c')
            ->assertJsonPath('data.customer_name', 'John Smith')
            ->assertJsonPath('data.customer_email', 'john@example.com')
            ->assertJsonPath('data.price', 72500)
            ->assertJsonPath('data.currency', 'EUR');

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'offer_id' => $offer->id,
            'price' => 72500,
        ], 'mysql');
        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'available_units' => 1,
        ], 'mysql');
    }

    public function test_only_one_reservation_can_take_the_last_available_unit(): void
    {
        $offer = $this->createOffer(['available_units' => 1]);

        $firstResponse = $this->postJson("/api/offers/$offer->id/reservations", [
            'client_reference' => 'web-order-first',
            'customer_name' => 'First Customer',
            'customer_email' => 'first@example.com',
        ]);
        $secondResponse = $this->postJson("/api/offers/$offer->id/reservations", [
            'client_reference' => 'web-order-second',
            'customer_name' => 'Second Customer',
            'customer_email' => 'second@example.com',
        ]);

        $firstResponse->assertCreated();
        $secondResponse
            ->assertStatus(409)
            ->assertJsonPath('message', 'The offer is no longer available.');

        $this->assertDatabaseCount('reservations', 1, 'mysql');
        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'available_units' => 0,
        ], 'mysql');
    }

    public function test_expired_offer_cannot_be_reserved(): void
    {
        $offer = $this->createOffer([
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->postJson("/api/offers/$offer->id/reservations", [
            'client_reference' => 'web-order-expired',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('message', 'The offer is no longer available.');

        $this->assertDatabaseCount('reservations', 0, 'mysql');
    }

    public function test_reservation_data_is_validated(): void
    {
        $offer = $this->createOffer();

        $response = $this->postJson("/api/offers/$offer->id/reservations", [
            'client_reference' => 'invalid reference',
            'customer_name' => '',
            'customer_email' => 'invalid-email',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'customer_name',
                'customer_email',
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOffer(array $overrides = []): Offer
    {
        $supplier = Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);
        $property = Property::create([
            'code' => 'BCN-0001',
            'name' => 'Barcelona Apartment',
            'city' => 'Barcelona',
        ]);
        $import = Import::create([
            'supplier_id' => $supplier->id,
            'external_import_id' => 'import-'.uniqid(),
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
