<?php

namespace Psf\Monitor;

/**
 * Histograma de latência com buckets fixos.
 *
 * Bucket i cobre [EDGES[i-1], EDGES[i]); o bucket 0 começa em 0 e o último
 * (índice 9) é aberto: [5000, ∞). Helper puro, sem banco; nunca lança.
 */
final class Histogram
{
    /** Bordas dos buckets em ms (parte do contrato dos rollups). */
    public const EDGES = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    /** Número de buckets (bordas + 1 = 10). */
    public static function bucketCount(): int
    {
        return count(self::EDGES) + 1;
    }

    /** Índice do bucket de uma duração em ms (NaN/negativo → 0; >= 5000 → 9). */
    public static function bucketIndex(float $ms): int
    {
        if (is_nan($ms) || $ms < 0) {
            return 0;
        }
        foreach (self::EDGES as $i => $edge) {
            if ($ms < $edge) {
                return $i;
            }
        }
        return count(self::EDGES);
    }

    /**
     * Histograma zerado.
     *
     * @return float[]
     */
    public static function empty(): array
    {
        return array_fill(0, self::bucketCount(), 0.0);
    }

    /**
     * Soma posição a posição; tamanhos errados são completados com 0.
     *
     * @return float[]
     */
    public static function merge(array $a, array $b): array
    {
        $a = array_values($a);
        $b = array_values($b);
        $out = [];
        for ($i = 0, $n = self::bucketCount(); $i < $n; $i++) {
            $out[] = self::num($a[$i] ?? 0) + self::num($b[$i] ?? 0);
        }
        return $out;
    }

    /** JSON (lista de 10 floats) para a coluna `histogram`; valores não finitos viram 0. */
    public static function encode(array $counts): string
    {
        $vals = [];
        foreach (self::merge($counts, []) as $v) {
            $vals[] = is_finite($v) ? $v : 0.0;
        }
        return json_encode($vals, JSON_PRESERVE_ZERO_FRACTION) ?: json_encode(self::empty(), JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Lê o JSON da coluna `histogram`.
     *
     * @return float[] Estrito: qualquer irregularidade devolve empty().
     */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return self::empty();
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data) || count($data) !== self::bucketCount()) {
            return self::empty();
        }
        $out = [];
        foreach ($data as $v) {
            if (!(is_int($v) || is_float($v)) || !is_finite($v) || $v < 0) {
                return self::empty();
            }
            $out[] = (float) $v;
        }
        return $out;
    }

    /**
     * Percentil estimado por interpolação linear dentro do bucket.
     *
     * No bucket aberto, $maxMs < 5000 (dados inconsistentes) é ignorado e
     * devolve 5000.0; nos demais o resultado é limitado a $maxMs.
     *
     * @param float      $p     percentil de 0 a 100
     * @param float|null $maxMs máximo observado no período (teto do bucket aberto)
     * @return float ms estimados; 0.0 para histograma vazio
     */
    public static function percentile(array $counts, float $p, ?float $maxMs = null): float
    {
        $counts = self::merge($counts, []);
        $total = 0.0;
        foreach ($counts as $i => $c) {
            $counts[$i] = (is_finite($c) && $c > 0) ? $c : 0.0;
            $total += $counts[$i];
        }
        if ($total <= 0.0) {
            return 0.0;
        }
        $p = is_nan($p) ? 0.0 : max(0.0, min(100.0, $p));
        $rank = $p / 100.0 * $total;
        $last = self::bucketCount() - 1;
        $maxOk = $maxMs !== null && is_finite($maxMs);

        $cum = 0.0;
        $result = 0.0;
        $lastHit = 0;
        foreach ($counts as $i => $c) {
            if ($c <= 0.0) {
                continue;
            }
            $lo = $i === 0 ? 0.0 : (float) self::EDGES[$i - 1];
            if ($i === $last) {
                $hi = ($maxOk && $maxMs >= $lo) ? $maxMs : $lo;
            } else {
                $hi = (float) self::EDGES[$i];
            }
            $lastHit = $i;
            $result = $lo;
            if ($cum + $c >= $rank) {
                $frac = max(0.0, min(1.0, ($rank - $cum) / $c));
                $result = $lo + $frac * ($hi - $lo);
                break;
            }
            $cum += $c;
            $result = $hi;
        }

        // Limite por $maxMs só vale fora do bucket aberto (que já o usa como teto).
        if ($maxOk && $lastHit < $last) {
            $result = min($result, $maxMs);
        }
        return $result;
    }

    private static function num(mixed $v): float
    {
        return (is_int($v) || is_float($v)) && is_finite($v) ? (float) $v : 0.0;
    }
}
