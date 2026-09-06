<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use WiserWebSolutions\Lobbyist\Ai\Support\Vector;

/**
 * Pure math, no framework needed -- this does not extend the package's own
 * TestCase (no database, no service container).
 */
class VectorTest extends PHPUnitTestCase
{
    public function test_pack_and_unpack_round_trip(): void
    {
        $vector = [0.5, -0.25, 1.0, -1.0, 0.0, 3.14159];

        $packed = Vector::pack($vector);
        $unpacked = Vector::unpack($packed);

        $this->assertCount(count($vector), $unpacked);
        foreach ($vector as $i => $value) {
            $this->assertEqualsWithDelta($value, $unpacked[$i], 1e-6);
        }
    }

    public function test_unpack_of_empty_string_is_empty_array(): void
    {
        $this->assertSame([], Vector::unpack(''));
    }

    public function test_normalize_yields_unit_length(): void
    {
        $normalized = Vector::normalize([3.0, 4.0]);

        $normSq = array_sum(array_map(fn ($v) => $v * $v, $normalized));

        $this->assertEqualsWithDelta(1.0, $normSq, 1e-9);
    }

    public function test_normalize_of_zero_vector_is_unchanged(): void
    {
        $this->assertSame([0.0, 0.0], Vector::normalize([0.0, 0.0]));
    }

    public function test_dot_of_a_normalized_vector_with_itself_is_one(): void
    {
        $normalized = Vector::normalize([1.0, 2.0, -3.0, 0.5]);

        $this->assertEqualsWithDelta(1.0, Vector::dot($normalized, $normalized), 1e-9);
    }

    public function test_dot_of_orthogonal_vectors_is_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, Vector::dot([1.0, 0.0], [0.0, 1.0]), 1e-9);
    }

    public function test_signature_bit_count_matches_dimension_count(): void
    {
        // 10 dimensions round up to 2 bytes (16 bits), the trailing 6 padded.
        $signature = Vector::signature(array_fill(0, 10, 1.0));

        $this->assertSame(2, strlen($signature));
    }

    public function test_signature_sign_bits_reflect_the_source_vector(): void
    {
        // All-positive vector -> every bit set -> a byte of 0xFF.
        $allPositive = Vector::signature(array_fill(0, 8, 1.0));
        $this->assertSame(chr(0b11111111), $allPositive);

        // All-negative vector -> every bit clear -> a byte of 0x00.
        $allNegative = Vector::signature(array_fill(0, 8, -1.0));
        $this->assertSame(chr(0b00000000), $allNegative);
    }

    public function test_hamming_distance_of_identical_signatures_is_zero(): void
    {
        $signature = Vector::signature([1.0, -1.0, 1.0, -1.0]);

        $this->assertSame(0, Vector::hamming($signature, $signature));
    }

    public function test_hamming_distance_counts_differing_bits(): void
    {
        $a = Vector::signature([1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0]);
        $b = Vector::signature([1.0, 1.0, 1.0, -1.0, 1.0, 1.0, 1.0, -1.0]);

        $this->assertSame(2, Vector::hamming($a, $b));
    }

    /**
     * Hamming ordering over signatures should agree with cosine ordering over
     * the full vectors, on a set clearly separated enough that quantization
     * noise cannot flip the order -- this is the property the whole
     * two-pass search design depends on.
     */
    public function test_hamming_ordering_agrees_with_cosine_ordering(): void
    {
        $query = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0];
        $close = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, -0.9]; // one dimension flipped, barely
        $far = [-1.0, -1.0, -1.0, -1.0, -1.0, -1.0, -1.0, -1.0]; // fully opposite

        $querySig = Vector::signature($query);
        $closeSig = Vector::signature($close);
        $farSig = Vector::signature($far);

        $queryNorm = Vector::normalize($query);
        $closeNorm = Vector::normalize($close);
        $farNorm = Vector::normalize($far);

        $this->assertLessThan(
            Vector::hamming($querySig, $farSig),
            Vector::hamming($querySig, $closeSig),
        );

        $this->assertGreaterThan(
            Vector::dot($queryNorm, $farNorm),
            Vector::dot($queryNorm, $closeNorm),
        );
    }
}
