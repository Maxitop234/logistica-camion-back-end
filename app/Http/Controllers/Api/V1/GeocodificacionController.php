<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Geocodificacion\ReverseGeocodeRequest;
use App\Services\NominatimGeocoderService;
use Illuminate\Http\JsonResponse;

class GeocodificacionController extends Controller
{
    public function __construct(
        private readonly NominatimGeocoderService $geocoder,
    ) {}

    /**
     * Resuelve el nombre y dirección sugeridos para una coordenada usando
     * Nominatim exclusivamente desde el backend (con caché y límite 1 req/s).
     */
    public function inversa(ReverseGeocodeRequest $request): JsonResponse
    {
        $lat = (float) $request->validated('lat');
        $lon = (float) $request->validated('lon');

        $resultado = $this->geocoder->reverse($lat, $lon);

        return response()->json([
            'data' => $resultado,
        ]);
    }
}
