<?php

namespace App\Support;

/**
 * Contraseña de inicio legible y fuerte (TD-026): 4 bloques de 4 caracteres + 2 dígitos,
 * p. ej. "Kp7m-Qx3r-Vb9t-Hw2n-58". ~90 bits de entropía, sin caracteres ambiguos (0/O, 1/l/I).
 * Cumple la política (≥ 10, letras y números). Se usa una sola vez: la persona debe cambiarla al ingresar.
 */
final class InitialPasswordGenerator
{
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $blocks = [];
        for ($b = 0; $b < 4; $b++) {
            $block = '';
            for ($i = 0; $i < 4; $i++) {
                $block .= self::ALPHABET[random_int(0, $max)];
            }
            $blocks[] = $block;
        }
        $blocks[] = (string) random_int(10, 99);
        $password = implode('-', $blocks);

        // Garantiza letras mayúsculas, minúsculas y dígitos.
        if (! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password)) {
            return self::generate();
        }

        return $password;
    }
}
