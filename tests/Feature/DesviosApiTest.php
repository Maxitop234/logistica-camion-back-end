<?php

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\DesvioRuta;
use App\Models\Ruta;
use App\Models\TelemetriaGps;
use App\Models\User;
use App\Services\Desvios\DetectorDeDesvios;
use App\Services\OsmRouteOptimizerService;
use Illuminate\Support\Str;

/**
 * Ruta planificada: línea recta de ~1,7 km hacia el este por la latitud -38.74.
 *
 * @return array{ruta: Ruta, chofer: Chofer, camion: Camion}
 */
function rutaConPolilinea(?Chofer $chofer = null, string $estado = Ruta::ESTADO_COMPLETADA): array
{
    $chofer ??= Chofer::factory()->create();
    $camion = Camion::factory()->create(['chofer_actual_id' => $chofer->id]);
    $ruta = Ruta::create([
        'camion_id' => $camion->id,
        'chofer_id' => $chofer->id,
        'fecha_programada' => today()->format('Y-m-d'),
        'estado' => $estado,
        'total_puntos_evaluados' => 1,
        'puntos_incluidos_count' => 1,
        'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 1.7,
        'duracion_estimada_min' => 10,
        'encoded_polyline' => OsmRouteOptimizerService::codificarPolilinea([[-38.74, -72.60], [-38.74, -72.58]]),
        'hora_inicio_real' => now()->subHour(),
    ]);

    return ['ruta' => $ruta, 'chofer' => $chofer, 'camion' => $camion];
}

/**
 * @param  array<int, array{0: float, 1: float}>  $coords
 */
function pingsEn(Ruta $ruta, array $coords): void
{
    $t = now()->subMinutes(50);
    foreach ($coords as [$lat, $lng]) {
        TelemetriaGps::create([
            'ruta_id' => $ruta->id,
            'camion_id' => $ruta->camion_id,
            'chofer_id' => $ruta->chofer_id,
            'ping_uuid' => (string) Str::uuid(),
            'latitud' => $lat,
            'longitud' => $lng,
            'velocidad_kmh' => 30,
            'precision_m' => 5,
            'capturado_en' => $t,
        ]);
        $t = $t->addSeconds(10);
    }
}

/** Traza que sigue la ruta, se aleja ~170 m al norte durante ~300 m y vuelve. */
function trazaConDesvio(): array
{
    return [
        [-38.7400, -72.6000], [-38.7400, -72.5990], [-38.7400, -72.5980],
        [-38.7385, -72.5975], [-38.7385, -72.5965], [-38.7385, -72.5955], [-38.7385, -72.5945],
        [-38.7400, -72.5940], [-38.7400, -72.5930], [-38.7400, -72.5920],
    ];
}

test('detecta un tramo de desvio con geometria y es idempotente', function () {
    ['ruta' => $ruta] = rutaConPolilinea();
    pingsEn($ruta, trazaConDesvio());

    $detector = app(DetectorDeDesvios::class);
    $detector->detectar($ruta);
    $detector->detectar($ruta);

    expect(DesvioRuta::count())->toBe(1);
    $d = DesvioRuta::first();
    expect($d->ruta_id)->toBe($ruta->id)
        ->and($d->chofer_id)->toBe($ruta->chofer_id)
        ->and($d->max_separacion_m)->toBeGreaterThan(150)
        ->and($d->distancia_m)->toBeGreaterThan(300)
        ->and($d->geometria_real)->not->toBe('')
        ->and($d->geometria_planificada)->not->toBe('')
        ->and($d->clave_tramo)->toContain('|');
});

test('no marca desvio si la traza sigue la ruta o el alejamiento es breve', function () {
    ['ruta' => $ruta] = rutaConPolilinea();
    // Sigue la ruta con ruido de ~10 m y un solo ping a 60 m (poco recorrido).
    pingsEn($ruta, [
        [-38.7400, -72.6000], [-38.74008, -72.5990], [-38.7400, -72.5980],
        [-38.73955, -72.5975], [-38.7400, -72.5970], [-38.7400, -72.5960],
    ]);

    expect(app(DetectorDeDesvios::class)->detectar($ruta))->toBe([]);
    expect(DesvioRuta::count())->toBe(0);
});

test('conserva el motivo al volver a detectar', function () {
    ['ruta' => $ruta] = rutaConPolilinea();
    pingsEn($ruta, trazaConDesvio());
    app(DetectorDeDesvios::class)->detectar($ruta);
    DesvioRuta::first()->update(['motivo_codigo' => 'calle_cerrada_obras', 'revisado_en' => now()]);

    app(DetectorDeDesvios::class)->detectar($ruta);

    expect(DesvioRuta::count())->toBe(1)
        ->and(DesvioRuta::first()->motivo_codigo)->toBe('calle_cerrada_obras');
});

test('finalizar la ruta guarda los desvios detectados', function () {
    $datos = rutaConPolilinea(null, Ruta::ESTADO_EN_CURSO);
    pingsEn($datos['ruta'], trazaConDesvio());

    $this->actingAs($datos['chofer']->user, 'sanctum')
        ->postJson('/api/v1/rutas/'.$datos['ruta']->id.'/finalizar')
        ->assertOk();

    expect(DesvioRuta::where('ruta_id', $datos['ruta']->id)->count())->toBe(1);
});

test('el chofer ve sus desvios pendientes y registra el motivo de forma opcional', function () {
    ['ruta' => $ruta, 'chofer' => $chofer] = rutaConPolilinea();
    pingsEn($ruta, trazaConDesvio());
    app(DetectorDeDesvios::class)->detectar($ruta);
    $desvio = DesvioRuta::first();

    $otro = rutaConPolilinea();
    pingsEn($otro['ruta'], trazaConDesvio());
    app(DetectorDeDesvios::class)->detectar($otro['ruta']);

    $this->actingAs($chofer->user, 'sanctum')
        ->getJson('/api/v1/chofer/desvios')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $desvio->id)
        ->assertJsonPath('data.0.motivo_codigo', null);

    // Sin motivo ni "omitir" no se acepta; con motivo inválido tampoco.
    $this->actingAs($chofer->user, 'sanctum')
        ->putJson("/api/v1/chofer/desvios/{$desvio->id}/motivo", [])
        ->assertUnprocessable();
    $this->actingAs($chofer->user, 'sanctum')
        ->putJson("/api/v1/chofer/desvios/{$desvio->id}/motivo", ['motivo_codigo' => 'inventado'])
        ->assertUnprocessable();

    $this->actingAs($chofer->user, 'sanctum')
        ->putJson("/api/v1/chofer/desvios/{$desvio->id}/motivo", [
            'motivo_codigo' => 'otro',
            'motivo_texto' => 'Había un camión de mudanza',
        ])
        ->assertOk()
        ->assertJsonPath('data.motivo_codigo', 'otro');

    expect($desvio->fresh()->revisado_en)->not->toBeNull();
    $this->actingAs($chofer->user, 'sanctum')
        ->getJson('/api/v1/chofer/desvios')
        ->assertJsonCount(0, 'data');

    // Omitir también lo deja revisado, y nadie puede tocar desvíos ajenos.
    $ajeno = DesvioRuta::where('chofer_id', $otro['chofer']->id)->first();
    $this->actingAs($chofer->user, 'sanctum')
        ->putJson("/api/v1/chofer/desvios/{$ajeno->id}/motivo", ['omitir' => true])
        ->assertForbidden();
    $this->actingAs($otro['chofer']->user, 'sanctum')
        ->putJson("/api/v1/chofer/desvios/{$ajeno->id}/motivo", ['omitir' => true])
        ->assertOk();
});

test('el reporte de calles problematicas agrupa desvios repetidos segun el umbral', function () {
    $admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $admin->id]);

    $motivos = ['calle_otro_sentido', 'calle_cerrada_obras', null];
    foreach ($motivos as $motivo) {
        ['ruta' => $ruta] = rutaConPolilinea();
        pingsEn($ruta, trazaConDesvio());
        app(DetectorDeDesvios::class)->detectar($ruta);
        DesvioRuta::where('ruta_id', $ruta->id)->update(['motivo_codigo' => $motivo]);
    }
    // Un desvío aislado en otro tramo no alcanza el umbral.
    ['ruta' => $otra] = rutaConPolilinea();
    $otra->update(['encoded_polyline' => OsmRouteOptimizerService::codificarPolilinea([[-38.70, -72.60], [-38.70, -72.58]])]);
    pingsEn($otra, array_map(fn (array $p): array => [$p[0] + 0.04, $p[1]], trazaConDesvio()));
    app(DetectorDeDesvios::class)->detectar($otra);
    expect(DesvioRuta::count())->toBe(4);

    $r = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/desvios/calles-problematicas')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.cantidad', 3)
        ->assertJsonPath('data.0.rutas_distintas', 3)
        ->assertJsonPath('data.0.motivos.calle_otro_sentido', 1)
        ->assertJsonPath('data.0.motivos.calle_cerrada_obras', 1)
        ->assertJsonPath('data.0.sin_motivo', 1)
        ->assertJsonPath('meta.umbral', 3);

    // Umbral configurable.
    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/desvios/calles-problematicas?umbral=4')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    expect($r->json('data.0.geometria_planificada'))->not->toBeNull();
});

test('solo el administrador consulta desvios por ruta y el reporte', function () {
    ['ruta' => $ruta, 'chofer' => $chofer] = rutaConPolilinea();

    $this->actingAs($chofer->user, 'sanctum')
        ->getJson("/api/v1/rutas/{$ruta->id}/desvios")
        ->assertForbidden();
    $this->actingAs($chofer->user, 'sanctum')
        ->getJson('/api/v1/desvios/calles-problematicas')
        ->assertForbidden();

    $admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $admin->id]);
    pingsEn($ruta, trazaConDesvio());
    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/rutas/{$ruta->id}/desvios")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('descodifica polilineas igual que las codifica', function () {
    $coords = [[-38.74, -72.6], [-38.7385, -72.5975], [-38.7, -72.55]];
    $r = OsmRouteOptimizerService::decodificarPolilinea(OsmRouteOptimizerService::codificarPolilinea($coords));
    expect($r)->toEqualWithDelta($coords, 1e-5);
});
