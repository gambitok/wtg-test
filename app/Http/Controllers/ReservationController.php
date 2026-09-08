<?php

namespace App\Http\Controllers;

use App\Exceptions\OfferUnavailableException;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function store(
        StoreReservationRequest $request,
        Offer $offer,
        ReservationService $reservationService,
    ): JsonResponse {
        try {
            $reservation = $reservationService->reserve($offer, $request->validated());
        } catch (OfferUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(201);
    }
}
