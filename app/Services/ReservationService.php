<?php

namespace App\Services;

use App\Exceptions\OfferUnavailableException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    /**
     * @param  array{client_reference: string, customer_name: string, customer_email: string}  $data
     */
    public function reserve(Offer $offer, array $data): Reservation
    {
        return DB::transaction(function () use ($offer, $data): Reservation {
            $lockedOffer = Offer::query()
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOffer->available_units < 1 || $lockedOffer->expires_at->isPast()) {
                throw new OfferUnavailableException;
            }

            $reservation = Reservation::create([
                'offer_id' => $lockedOffer->id,
                'client_reference' => $data['client_reference'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'price' => $lockedOffer->price,
                'currency' => $lockedOffer->currency,
            ]);

            $lockedOffer->available_units--;
            $lockedOffer->save();

            return $reservation;
        });
    }
}
