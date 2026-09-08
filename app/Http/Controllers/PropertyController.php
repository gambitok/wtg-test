<?php

namespace App\Http\Controllers;

use App\Http\Requests\PropertySearchRequest;
use App\Http\Resources\PropertySearchResource;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function index(PropertySearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $now = now();

        $rankedOffers = Offer::query()
            ->select([
                'offers.id',
                'offers.property_id',
                'offers.supplier_id',
                'offers.price',
                'offers.currency',
                'offers.available_units',
                'offers.expires_at',
                DB::raw(
                    'ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price ASC, offers.id ASC) AS offer_rank',
                ),
            ])
            ->where('offers.check_in', $data['check_in'])
            ->where('offers.check_out', $data['check_out'])
            ->where('offers.max_guests', '>=', $data['guests'])
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', $now);

        $properties = Property::query()
            ->select([
                'properties.id',
                'properties.code',
                'properties.name',
                'properties.city',
                'ranked_offers.id as best_offer_id',
                'suppliers.code as best_offer_supplier',
                'ranked_offers.price as best_offer_price',
                'ranked_offers.currency as best_offer_currency',
                'ranked_offers.available_units as best_offer_available_units',
                'ranked_offers.expires_at as best_offer_expires_at',
            ])
            ->joinSub($rankedOffers, 'ranked_offers', function (JoinClause $join): void {
                $join->on('ranked_offers.property_id', '=', 'properties.id')
                    ->where('ranked_offers.offer_rank', '=', 1);
            })
            ->join('suppliers', 'suppliers.id', '=', 'ranked_offers.supplier_id')
            ->when(
                filled($data['city'] ?? null),
                fn ($query) => $query->where('properties.city', $data['city']),
            )
            ->orderBy('properties.id');

        $paginator = $properties
            ->paginate((int) ($data['per_page'] ?? 15))
            ->withQueryString();

        return response()->json([
            'data' => PropertySearchResource::collection($paginator->getCollection())->resolve($request),
            'next' => $paginator->nextPageUrl(),
            'prev' => $paginator->previousPageUrl(),
            'per_page' => $paginator->perPage(),
        ]);
    }
}
