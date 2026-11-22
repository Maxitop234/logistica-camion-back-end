<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Código secreto impreso en la etiqueta de un sensor. Se guarda solo su HMAC.
 *
 * @property int $id
 * @property string $codigo_hardware
 * @property string $codigo_hash
 * @property string|null $lote
 * @property Carbon|null $usado_en
 */
#[Table('codigos_vinculacion')]
#[Fillable(['codigo_hardware', 'codigo_hash', 'lote', 'usado_en'])]
class CodigoVinculacion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['usado_en' => 'datetime'];
    }

    /**
     * Quita guiones y espacios y pasa a mayúsculas, para tolerar cómo se tipea o escanea.
     */
    public static function normalizar(string $codigo): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? '');
    }

    public static function hashDe(string $codigo): string
    {
        return hash_hmac('sha256', self::normalizar($codigo), (string) config('app.key'));
    }

    public function coincide(string $codigo): bool
    {
        return hash_equals($this->codigo_hash, self::hashDe($codigo));
    }
}
