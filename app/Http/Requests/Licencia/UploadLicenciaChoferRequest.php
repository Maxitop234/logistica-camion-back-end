<?php

namespace App\Http\Requests\Licencia;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadLicenciaChoferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'archivo' => [
                'required',
                'file',
                'extensions:pdf',
                'mimetypes:application/pdf',
                'max:5120',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $ruta = $value->getRealPath();
                    if ($ruta === false) {
                        $fail('No se pudo leer el archivo PDF.');

                        return;
                    }

                    $handle = fopen($ruta, 'rb');
                    $cabecera = $handle !== false ? fread($handle, 5) : false;
                    if ($handle !== false) {
                        fclose($handle);
                    }

                    if ($cabecera !== '%PDF-') {
                        $fail('El archivo no corresponde a un documento PDF válido.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Debes seleccionar el PDF de tu Hoja de Vida del Conductor.',
            'archivo.extensions' => 'El archivo debe tener extensión .pdf.',
            'archivo.mimetypes' => 'El archivo debe ser un documento PDF válido.',
            'archivo.max' => 'El PDF no puede superar los 5 MB.',
        ];
    }
}
