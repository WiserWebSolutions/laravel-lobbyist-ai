<?php

namespace WiserWebSolutions\Lobbyist\Ai\Support;

/**
 * Packing, normalization, and distance math for embedding vectors.
 *
 * Vectors are stored as binary float32 (via {@see self::pack()}) rather than
 * JSON, and carry a binary sign-bit "signature" (via {@see self::signature()})
 * alongside them so a store can prefilter a large corpus by cheap Hamming
 * distance before reranking the survivors by exact cosine similarity.
 * Exhaustive exact scoring is not viable at state scale: 40,000 bills squared
 * at 256 dimensions is on the order of 400 billion floating-point operations
 * in PHP. The signature is a candidate generator, not the final answer --
 * {@see self::hamming()} orders candidates, {@see self::dot()} on the
 * un-quantized vectors decides the final ranking.
 */
class Vector
{
    /**
     * L2-normalize a vector so cosine similarity becomes a plain dot product.
     *
     * Not optional for Gemini embeddings: the provider returns un-normalized
     * vectors whenever `outputDimensionality` is set below the model's native
     * dimensionality, which is every truncated configuration a caller is
     * likely to choose for storage economy.
     *
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    public static function normalize(array $vector): array
    {
        $normSq = 0.0;

        foreach ($vector as $value) {
            $normSq += $value * $value;
        }

        if ($normSq <= 0.0) {
            return $vector;
        }

        $norm = sqrt($normSq);

        return array_map(fn (float $value): float => $value / $norm, $vector);
    }

    /**
     * Pack a vector of floats into a binary string (float32 little-endian).
     *
     * A 256-dimension vector packs to 1 KB, versus roughly 15 KB as a JSON
     * array of PHP floats -- the difference between a corpus that fits
     * comfortably in memory for a prefilter pass and one that does not.
     *
     * @param  array<int, float>  $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * Unpack a binary float32 string back into a vector of floats.
     *
     * @return array<int, float>
     */
    public static function unpack(string $packed): array
    {
        if ($packed === '') {
            return [];
        }

        return array_values(unpack('g*', $packed));
    }

    /**
     * Derive a binary sign-bit signature from a vector: one bit per
     * dimension, set when that dimension is positive. Every 8 dimensions
     * become one byte, so a 256-dimension vector signs to 32 bytes.
     *
     * This is a coarse summary -- it discards magnitude entirely -- but it is
     * cheap to compare (native string XOR plus a popcount) and, empirically,
     * preserves enough of a normalized vector's structure to rank real
     * candidates ahead of unrelated ones before the exact rerank corrects the
     * final order.
     */
    public static function signature(array $vector): string
    {
        $bytes = '';
        $byte = 0;
        $bitsInByte = 0;

        foreach ($vector as $value) {
            $byte = ($byte << 1) | ($value > 0.0 ? 1 : 0);
            $bitsInByte++;

            if ($bitsInByte === 8) {
                $bytes .= chr($byte);
                $byte = 0;
                $bitsInByte = 0;
            }
        }

        if ($bitsInByte > 0) {
            $bytes .= chr($byte << (8 - $bitsInByte));
        }

        return $bytes;
    }

    /**
     * Dot product of two vectors of equal length. On L2-normalized inputs,
     * this is their cosine similarity.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * Hamming distance between two equal-length binary signatures: the
     * number of differing bits, via a native string XOR and an 8-bit
     * popcount lookup table (built once, memoized on first use).
     */
    public static function hamming(string $a, string $b): int
    {
        static $popcount = null;

        if ($popcount === null) {
            $popcount = [];
            for ($i = 0; $i < 256; $i++) {
                $popcount[$i] = substr_count(decbin($i), '1');
            }
        }

        $xor = $a ^ $b;
        $distance = 0;

        foreach (unpack('C*', $xor) as $byte) {
            $distance += $popcount[$byte];
        }

        return $distance;
    }
}
