<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Contraseña de al menos 10 caracteres que no sea una de las más comunes,
 * una repetición o secuencia simple, ni contenga el correo de la cuenta.
 * No consulta servicios externos.
 */
class ContrasenaSegura implements ValidationRule
{
    /**
     * @var array<int, string>
     */
    private const COMUNES = [
        'password', 'password1', 'password12', 'password123', 'password1234', 'passw0rd', 'p@ssw0rd', 'p@ssword',
        'contrasena', 'contrasena1', 'contrasena12', 'contrasena123', 'clave', 'clave123', 'clave1234', 'clavesecreta',
        'qwerty', 'qwerty123', 'qwertyuiop', 'qwerty1234', 'qwertyui', 'asdfghjkl', 'asdfghjkl1', 'zxcvbnm', 'zxcvbnm123',
        '1q2w3e4r5t', '1qaz2wsx', 'q1w2e3r4t5', 'abc123', 'abcd1234', 'abcdefgh', 'abcdefghij', 'abc12345', 'abcd123456',
        'iloveyou', 'iloveyou1', 'letmein', 'welcome', 'welcome1', 'welcome123', 'admin', 'admin123', 'admin1234',
        'administrador', 'administrator', 'administrador1', 'root1234', 'changeme', 'changeme123', 'trustno1',
        'monkey', 'dragon', 'football', 'baseball', 'superman', 'batman', 'master', 'shadow', 'sunshine', 'princess',
        'chile', 'chile123', 'chile2024', 'chile2025', 'chile2026', 'temuco', 'temuco123', 'temuco2026', 'ecoruta',
        'ecoruta123', 'ecoruta2026', 'terranova', 'terranova1', 'terranova123', 'reciclaje', 'reciclaje1', 'reciclaje123',
        'camion', 'camion123', 'chofer', 'chofer123', 'chofer1234', 'conductor', 'holamundo', 'hola1234', 'hola12345',
        'bienvenido', 'bienvenido1', 'santiago', 'santiago1', 'colocolo', 'colocolo1', 'universidad', 'universidad1',
        '0123456789', '1234567890', '12345678', '123456789', '1234567', '987654321', '9876543210', '1111111111',
        '0000000000', '1234512345', '12341234', '1q2w3e4r', 'qazwsxedc', 'qweasdzxc', 'asdf1234', 'zaq12wsx',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $minimo = (int) config('ecoruta.password.largo_minimo', 10);

        if (! is_string($value) || mb_strlen($value) < $minimo) {
            $fail("La contraseña debe tener al menos {$minimo} caracteres.");

            return;
        }

        if (mb_strlen($value) > 128) {
            $fail('La contraseña no puede superar los 128 caracteres.');

            return;
        }

        $normal = Str::lower($value);
        $sinDigitosFinales = rtrim($normal, '0123456789!._-');

        if (
            in_array($normal, self::COMUNES, true)
            || ($sinDigitosFinales !== '' && in_array($sinDigitosFinales, self::COMUNES, true))
            || self::esRepeticionOSecuencia($normal)
        ) {
            $fail('La contraseña es demasiado común. Elige otra.');

            return;
        }

        $email = Str::lower((string) request()->input('email', ''));
        $local = Str::before($email, '@');
        if (mb_strlen($local) >= 4 && str_contains($normal, $local)) {
            $fail('La contraseña no puede contener el correo de la cuenta.');
        }
    }

    private static function esRepeticionOSecuencia(string $texto): bool
    {
        if (count(array_unique(mb_str_split($texto))) <= 3) {
            return true;
        }

        $secuencias = ['0123456789', '9876543210', 'abcdefghijklmnopqrstuvwxyz', 'zyxwvutsrqponmlkjihgfedcba', 'qwertyuiopasdfghjklzxcvbnm'];
        foreach ($secuencias as $secuencia) {
            if (str_contains($secuencia, $texto)) {
                return true;
            }
        }

        return false;
    }
}
