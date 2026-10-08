<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Clave para comparar nombres escritos a mano: sin mayúsculas, acentos ni espacios repetidos.
 * "Gas  Licuado", "gas licuado" y "Gás licuado" dan la misma clave.
 */
class NameKey
{
    public static function make(?string $name): string
    {
        return Str::of((string) $name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }

    /** Recorta y deja un solo espacio entre palabras (conserva mayúsculas y acentos). */
    public static function tidy(?string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $name));
    }
}
