<?php

use App\Models\Administrador;
use App\Models\Chofer;
use App\Models\Punto;
use App\Models\SolicitudPunto;
use App\Models\User;
use App\Services\NominatimGeocoderService;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();

    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $this->admin->id]);

    $this->chofer = Chofer::factory()->create(['nombre_cifrado' => 'Pedro Chofer']);
    $this->choferUser = $this->chofer->user;
});

it('resuelve un lugar con nombre (POI) enviando User-Agent propio y parámetros obligatorios', function (): void {
    config(['services.nominatim.user_agent' => 'EcoRutaTemuco/1.0 (contacto@ecoruta.cl)']);

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'name' => 'Plaza Aníbal Pinto',
            'address' => [
                'road' => 'Claro Solar',
                'house_number' => '831',
                'city' => 'Temuco',
            ],
        ], 200),
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.739712&lon=-72.590144')
        ->assertOk()
        ->assertJsonPath('data.encontrado', true)
        ->assertJsonPath('data.nombre', 'Plaza Aníbal Pinto')
        ->assertJsonPath('data.direccion', 'Claro Solar 831, Temuco')
        ->assertJsonPath('data.desde_cache', false);

    Http::assertSent(function (HttpClientRequest $req): bool {
        return str_starts_with($req->url(), 'https://nominatim.openstreetmap.org/reverse')
            && $req->hasHeader('User-Agent', 'EcoRutaTemuco/1.0 (contacto@ecoruta.cl)')
            && $req['format'] === 'jsonv2'
            && $req['accept-language'] === 'es'
            && (string) $req['addressdetails'] === '1'
            && $req['lat'] === '-38.73971'
            && $req['lon'] === '-72.59014';
    });
});

it('resuelve calle con número y solo calle cuando no hay POI', function (): void {
    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::sequence()
            ->push([
                'name' => '',
                'address' => [
                    'road' => 'Av. Alemania',
                    'house_number' => '0671',
                    'city' => 'Temuco',
                ],
            ], 200)
            ->push([
                'name' => '',
                'address' => [
                    'road' => 'Av. Pablo Neruda',
                    'municipality' => 'Temuco',
                ],
            ], 200),
    ]);

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.73300&lon=-72.61000')
        ->assertOk()
        ->assertJsonPath('data.encontrado', true)
        ->assertJsonPath('data.nombre', 'Av. Alemania 0671')
        ->assertJsonPath('data.direccion', 'Av. Alemania 0671, Temuco');

    // Libera la ventana de 1 s en caché para probar la segunda coordenada de inmediato.
    Cache::forget(NominatimGeocoderService::RATE_LIMIT_KEY);

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.71800&lon=-72.60500')
        ->assertOk()
        ->assertJsonPath('data.encontrado', true)
        ->assertJsonPath('data.nombre', 'Av. Pablo Neruda')
        ->assertJsonPath('data.direccion', 'Av. Pablo Neruda, Temuco');
});

it('cachea resultados útiles a 5 decimales sin consumir cuota externa en aciertos', function (): void {
    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'name' => 'Parque Estadio Germán Becker',
            'address' => [
                'road' => 'Av. Pablo Neruda',
                'city' => 'Temuco',
            ],
        ], 200),
    ]);

    // Primera consulta: llama a Nominatim y guarda en caché.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.742101&lon=-72.615004')
        ->assertOk()
        ->assertJsonPath('data.desde_cache', false)
        ->assertJsonPath('data.nombre', 'Parque Estadio Germán Becker');

    // Incluso con la ventana externa ocupada, un hit de caché responde al instante y sin HTTP.
    $ahoraMs = (int) floor(microtime(true) * 1000);
    Cache::put(NominatimGeocoderService::RATE_LIMIT_KEY, $ahoraMs + 5000, 10);

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.742104&lon=-72.615001')
        ->assertOk()
        ->assertJsonPath('data.desde_cache', true)
        ->assertJsonPath('data.espero_turno_ms', 0)
        ->assertJsonPath('data.nombre', 'Parque Estadio Germán Becker');

    Http::assertSentCount(1);
});

it('nunca cachea errores ni resultados vacíos de Nominatim', function (): void {
    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::sequence()
            ->push(['error' => 'Unable to geocode'], 503)
            ->push(['name' => '', 'address' => []], 200)
            ->push([
                'name' => 'Colegio Bautista',
                'address' => [
                    'road' => 'Av. Caupolicán',
                    'house_number' => '150',
                    'city' => 'Temuco',
                ],
            ], 200),
    ]);

    // 1. Error HTTP 503: devuelve encontrado=false y NO se cachea.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.73100&lon=-72.59200')
        ->assertOk()
        ->assertJsonPath('data.encontrado', false)
        ->assertJsonPath('data.nombre', null);

    Cache::forget(NominatimGeocoderService::RATE_LIMIT_KEY);

    // 2. Resultado vacío: devuelve encontrado=false y NO se cachea.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.73100&lon=-72.59200')
        ->assertOk()
        ->assertJsonPath('data.encontrado', false);

    Cache::forget(NominatimGeocoderService::RATE_LIMIT_KEY);

    // 3. Al recuperarse Nominatim, la misma coordenada devuelve el nombre útil.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.73100&lon=-72.59200')
        ->assertOk()
        ->assertJsonPath('data.encontrado', true)
        ->assertJsonPath('data.nombre', 'Colegio Bautista')
        ->assertJsonPath('data.desde_cache', false);

    Http::assertSentCount(3);
});

it('espera brevemente ante concurrencia en el límite global de 1 req/s y responde sin fallar', function (): void {
    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'name' => 'Supermercado Líder Vecino',
            'address' => [
                'road' => 'Av. Alemania',
                'house_number' => '850',
                'city' => 'Temuco',
            ],
        ], 200),
    ]);

    // Simula que otra petición ocupó el turno hace poco y quedan ~150 ms de ventana.
    $ahoraMs = (int) floor(microtime(true) * 1000);
    Cache::put(NominatimGeocoderService::RATE_LIMIT_KEY, $ahoraMs + 150, 10);

    $resp = $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.73450&lon=-72.60320')
        ->assertOk()
        ->assertJsonPath('data.encontrado', true)
        ->assertJsonPath('data.nombre', 'Supermercado Líder Vecino');

    expect($resp->json('data.espero_turno_ms'))->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(NominatimGeocoderService::MAX_WAIT_MS);
    Http::assertSentCount(1);

    // Si la cola supera el tope de espera de 2 s, no bloquea ni llama a Nominatim.
    $ahoraMs2 = (int) floor(microtime(true) * 1000);
    Cache::put(NominatimGeocoderService::RATE_LIMIT_KEY, $ahoraMs2 + 3500, 10);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/geocodificacion/inversa?lat=-38.75550&lon=-72.62320')
        ->assertOk()
        ->assertJsonPath('data.encontrado', false);

    Http::assertSentCount(1);
});

it('persiste nombre_automatico en puntos y lo propaga al aprobar propuestas del chofer', function (): void {
    // Nuevo punto sin enviar flag queda automático=true; al actualizar a manual queda false.
    $creado = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', [
            'nombre' => 'Plaza Recabarren',
            'direccion' => 'Av. Alemania 100, Temuco',
            'tipo_punto' => Punto::TIPO_CALLE,
            'latitud' => -38.7359,
            'longitud' => -72.5904,
        ])
        ->assertCreated()
        ->assertJsonPath('data.nombre_automatico', true);

    $puntoId = $creado->json('data.id');

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/puntos/{$puntoId}", [
            'nombre' => 'Mi Punto Personalizado',
            'nombre_automatico' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Mi Punto Personalizado')
        ->assertJsonPath('data.nombre_automatico', false);

    // Propuesta del chofer con nombre manual (`false`) se preserva al aprobar.
    $propuesta = $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/solicitudes-punto', [
            'nombre' => 'Condominio Vista Sur',
            'nombre_automatico' => false,
            'direccion' => 'Calle Maquehue 900, Padre Las Casas',
            'tipo_punto' => Punto::TIPO_CONDOMINIO,
            'latitud' => -38.77,
            'longitud' => -72.595,
        ])
        ->assertCreated()
        ->assertJsonPath('data.nombre_automatico', false);

    $solicitudId = $propuesta->json('data.id');

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/puntos/{$solicitudId}/aprobar")
        ->assertOk();

    $solicitud = SolicitudPunto::findOrFail($solicitudId);
    $puntoAprobado = Punto::findOrFail($solicitud->punto_creado_id);
    expect($puntoAprobado->nombre_automatico)->toBeFalse();
});
