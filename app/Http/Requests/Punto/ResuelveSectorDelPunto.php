<?php

namespace App\Http\Requests\Punto;

use App\Models\Sector;
use Illuminate\Validation\Validator;

/**
 * Permite indicar el sector de un punto por `sector_id` o por su nombre
 * (`sector`, sin distinguir mayúsculas). El nombre debe ser uno de los
 * sectores registrados y activos.
 */
trait ResuelveSectorDelPunto
{
    private ?int $sectorIdPorNombre = null;

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $nombre = $this->input('sector');
                if ($this->filled('sector_id') || ! is_string($nombre) || trim($nombre) === '') {
                    return;
                }

                $normalizado = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nombre)));
                $sector = Sector::query()
                    ->where('activo', true)
                    ->whereRaw('lower(nombre) = ?', [$normalizado])
                    ->first();

                if (! $sector instanceof Sector) {
                    $validator->errors()->add(
                        'sector',
                        'El sector «'.trim($nombre).'» no existe. Usa uno de los sectores registrados (GET /api/v1/sectores).',
                    );

                    return;
                }

                $this->sectorIdPorNombre = $sector->id;
            },
        ];
    }

    /**
     * Sector indicado en la solicitud: `false` si no se indicó, `null` si se
     * quitó explícitamente o el id del sector.
     */
    public function sectorIndicado(): int|false|null
    {
        if ($this->filled('sector_id')) {
            return (int) $this->input('sector_id');
        }

        if ($this->sectorIdPorNombre !== null) {
            return $this->sectorIdPorNombre;
        }

        $quitado = ($this->exists('sector_id') && $this->input('sector_id') === null)
            || ($this->exists('sector') && blank($this->input('sector')));

        return $quitado ? null : false;
    }
}
