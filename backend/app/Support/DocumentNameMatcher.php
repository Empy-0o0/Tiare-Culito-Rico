<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Asocia un archivo subido con el adjunto oficial de Mercado Público que le corresponde,
 * comparando nombres (TD-030). Tolera mayúsculas, tildes, extensión, separadores y nombres
 * truncados por el portal (p. ej. "...COMPRA Á.pdf").
 */
final class DocumentNameMatcher
{
    public const THRESHOLD = 80;

    public static function normalize(string $name): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', trim($name)) ?? $name;
        $ascii = Str::lower(Str::ascii($base));
        $clean = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }

    public static function score(string $a, string $b): int
    {
        $x = self::normalize($a);
        $y = self::normalize($b);
        if ($x === '' || $y === '') {
            return 0;
        }
        if ($x === $y) {
            return 100;
        }
        // Uno contiene al otro (nombres truncados o con prefijos/sufijos del portal o del navegador, p. ej. "(1)").
        $shorter = strlen($x) <= strlen($y) ? $x : $y;
        $longer = $shorter === $x ? $y : $x;
        if (strlen($shorter) >= 8 && str_contains($longer, $shorter)) {
            return 92;
        }
        similar_text($x, $y, $percent);

        return (int) round($percent);
    }

    /**
     * Mejor adjunto oficial para un nombre de archivo, o null si ninguno es suficientemente parecido.
     * En empate se prefiere un adjunto que aún no tenga copia.
     *
     * @param  array<string, ?string>  $official  id => nombre oficial
     * @param  list<string>  $alreadyCopied  ids que ya tienen copia
     * @return array{id: string, score: int}|null
     */
    public static function bestMatch(string $filename, array $official, array $alreadyCopied = []): ?array
    {
        $best = null;
        foreach ($official as $id => $name) {
            if ($name === null || $name === '') {
                continue;
            }
            $score = self::score($filename, $name);
            $isBetter = $best === null
                || $score > $best['score']
                || ($score === $best['score'] && in_array($best['id'], $alreadyCopied, true) && ! in_array((string) $id, $alreadyCopied, true));
            if ($isBetter) {
                $best = ['id' => (string) $id, 'score' => $score];
            }
        }

        return $best !== null && $best['score'] >= self::THRESHOLD ? $best : null;
    }
}
