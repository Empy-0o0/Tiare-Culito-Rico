<?php

namespace App\Support;

/**
 * Calce simple por palabras (≥ 4 letras, sin tildes ni mayúsculas). Determinista y
 * explicable: sirve para un matching preliminar, no para afirmar equivalencia de productos.
 */
final class WordMatcher
{
    /** Palabras genéricas que no aportan al calce. */
    private const STOPWORDS = ['para', 'con', 'sin', 'unidad', 'unidades', 'tipo', 'marca', 'otros', 'otro', 'segun', 'como', 'desde', 'hasta', 'entre', 'sobre', 'cada'];

    /** @return list<string> */
    public static function words(?string $text): array
    {
        $t = mb_strtolower((string) $text);
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $parts = preg_split('/[^a-z0-9]+/', $t) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($w) => strlen($w) >= 4 && ! in_array($w, self::STOPWORDS, true))));
    }

    /**
     * Para cada texto solicitado, los nombres del catálogo que comparten al menos una palabra.
     *
     * @param  list<string>  $requested
     * @param  list<array{name: string, text: string}>  $catalog
     * @return list<array{requested: string, catalog: list<string>, words: list<string>}>
     */
    public static function match(array $requested, array $catalog, int $maxPerItem = 5): array
    {
        $index = array_map(fn ($p) => ['name' => $p['name'], 'words' => self::words($p['text'])], $catalog);
        $out = [];
        foreach ($requested as $text) {
            $w = self::words($text);
            $hits = [];
            $shared = [];
            foreach ($index as $p) {
                $common = array_intersect($w, $p['words']);
                if ($common) {
                    $hits[] = $p['name'];
                    $shared = array_merge($shared, $common);
                }
            }
            if ($hits) {
                $out[] = ['requested' => $text, 'catalog' => array_slice(array_values(array_unique($hits)), 0, $maxPerItem), 'words' => array_values(array_unique($shared))];
            }
        }

        return $out;
    }
}
