<?php

namespace App\Services\Puntos;

/**
 * Convierte las coordenadas escritas en las planillas de importación (BE-04)
 * a grados decimales WGS84 con 7 decimales.
 *
 * Acepta el par decimal `-38.7623611, -72.7618889` y grados, minutos y
 * segundos como los copia Google Maps (`38°45'44.5"S 72°45'42.8"W`). El
 * hemisferio oeste también se acepta como `O`.
 */
class ExtractorDeCoordenadas
{
    private const DECIMALES = 7;

    private const PATRON_DECIMAL = '/^\s*([+-]?\d{1,3}(?:\.\d+)?)\s*[,;\s]\s*([+-]?\d{1,3}(?:\.\d+)?)\s*$/';

    /**
     * Un componente en grados, minutos y segundos seguido de su hemisferio.
     */
    private const PATRON_GMS = '/(\d{1,3}(?:[.,]\d+)?)\s*[°º˚]\s*(?:(\d{1,2}(?:[.,]\d+)?)\s*[\'′’]\s*)?(?:(\d{1,2}(?:[.,]\d+)?)\s*(?:"|″|”|\'\'|′′)\s*)?([NSEWO])/iu';

    /**
     * Extrae latitud y longitud de un texto con ambas coordenadas.
     *
     * @return array{latitud: float, longitud: float}|null `null` si el texto no es una coordenada válida
     */
    public function extraer(string $texto): ?array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        if (preg_match(self::PATRON_DECIMAL, $texto, $partes) === 1) {
            return $this->par((float) $partes[1], (float) $partes[2]);
        }

        return $this->desdeGradosMinutosSegundos($texto);
    }

    /**
     * Extrae las coordenadas cuando la planilla las trae en dos columnas.
     * Cada una puede venir en decimal o en grados, minutos y segundos.
     *
     * @return array{latitud: float, longitud: float}|null
     */
    public function extraerDeColumnas(string $latitud, string $longitud): ?array
    {
        $latitud = trim($latitud);
        $longitud = trim($longitud);
        if ($latitud === '' || $longitud === '') {
            return null;
        }

        if (is_numeric($latitud) && is_numeric($longitud)) {
            return $this->par((float) $latitud, (float) $longitud);
        }

        return $this->desdeGradosMinutosSegundos("{$latitud} {$longitud}");
    }

    /**
     * @return array{latitud: float, longitud: float}|null
     */
    private function desdeGradosMinutosSegundos(string $texto): ?array
    {
        if (! preg_match_all(self::PATRON_GMS, $texto, $coincidencias, PREG_SET_ORDER)) {
            return null;
        }

        $latitud = null;
        $longitud = null;
        foreach ($coincidencias as $coincidencia) {
            $grados = $this->numero($coincidencia[1]);
            $minutos = $this->numero($coincidencia[2]);
            $segundos = $this->numero($coincidencia[3]);
            $hemisferio = strtoupper($coincidencia[4]);
            if ($minutos >= 60 || $segundos >= 60) {
                return null;
            }

            $valor = $grados + ($minutos / 60) + ($segundos / 3600);
            if (in_array($hemisferio, ['S', 'W', 'O'], true)) {
                $valor = -$valor;
            }

            if (in_array($hemisferio, ['N', 'S'], true)) {
                if ($latitud !== null) {
                    return null;
                }
                $latitud = $valor;
            } else {
                if ($longitud !== null) {
                    return null;
                }
                $longitud = $valor;
            }
        }

        if ($latitud === null || $longitud === null) {
            return null;
        }

        return $this->par($latitud, $longitud);
    }

    /**
     * @return array{latitud: float, longitud: float}|null
     */
    private function par(float $latitud, float $longitud): ?array
    {
        if (abs($latitud) > 90 || abs($longitud) > 180) {
            return null;
        }

        return [
            'latitud' => round($latitud, self::DECIMALES),
            'longitud' => round($longitud, self::DECIMALES),
        ];
    }

    private function numero(string $texto): float
    {
        return $texto === '' ? 0.0 : (float) str_replace(',', '.', $texto);
    }
}
