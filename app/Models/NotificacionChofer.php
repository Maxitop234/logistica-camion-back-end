<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chofer_id
 * @property string $tipo
 * @property string $titulo
 * @property string $mensaje
 * @property Carbon|null $leida_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('notificaciones_chofer')]
#[Fillable([
    'chofer_id',
    'tipo',
    'titulo',
    'mensaje',
    'leida_en',
])]
class NotificacionChofer extends Model
{
    public const TIPO_LICENCIA_VERIFICADA = 'licencia_verificada';

    public const TIPO_LICENCIA_DESVERIFICADA = 'licencia_desverificada';

    public const TIPO_LICENCIA_RECHAZADA = 'licencia_rechazada';

    public const TIPO_LICENCIA_DESTRABADA = 'licencia_destrabada';

    public const TIPO_BORRADO_APROBADO = 'borrado_aprobado';

    public const TIPO_BORRADO_RECHAZADO = 'borrado_rechazado';

    public const TIPO_CUENTA_REACTIVADA = 'cuenta_reactivada';

    public const TIPO_REACTIVACION_RECHAZADA = 'reactivacion_rechazada';

    public const TIPO_PUNTO_APROBADO = 'punto_aprobado';

    public const TIPO_PUNTO_RECHAZADO = 'punto_rechazado';

    /**
     * Registra una notificación persistida para el chofer indicado.
     */
    public static function crear(
        Chofer|int $chofer,
        string $tipo,
        string $titulo,
        string $mensaje,
    ): self {
        $choferId = $chofer instanceof Chofer ? $chofer->id : $chofer;

        return self::create([
            'chofer_id' => $choferId,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leida_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Chofer, $this>
     */
    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Chofer::class);
    }
}
