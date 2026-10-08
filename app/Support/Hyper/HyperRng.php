<?php

namespace App\Support\Hyper;

use InvalidArgumentException;

/**
 * The game's dice and shuffles: xoshiro128++ with a 32-bit state, seeded by splitmix32, draw for draw
 * identical to tools/hbsim (Rng). PHP integers are signed 64-bit and an overflow silently turns into a
 * float, so every step stays in masked 32-bit arithmetic; a 32 x 32 bit product is split into 16-bit
 * halves so no intermediate reaches 2^63. Sources: Blackman/Vigna, xoshiro128++ 1.0
 * (https://prng.di.unimi.it/xoshiro128plusplus.c); splitmix32 constants from MurmurHash3's fmix32.
 */
final class HyperRng
{
    private const int MASK = 0xFFFFFFFF;

    /**
     * @param  array{int, int, int, int}  $state
     */
    private function __construct(private array $state) {}

    public static function seeded(int $seed): self
    {
        if ($seed < 0 || $seed > self::MASK) {
            throw new InvalidArgumentException('The seed is a 32-bit unsigned integer.');
        }

        $z = $seed;
        $state = [0, 0, 0, 0];

        for ($k = 0; $k < 4; $k++) {
            $z = ($z + 0x9E3779B9) & self::MASK;
            $v = $z;
            $v = self::mul($v ^ ($v >> 16), 0x85EBCA6B);
            $v = self::mul($v ^ ($v >> 13), 0xC2B2AE35);
            $state[$k] = $v ^ ($v >> 16);
        }

        return new self($state);
    }

    /**
     * @param  list<mixed>  $state  from state(), possibly through JSON
     */
    public static function fromState(array $state): self
    {
        if (count($state) !== 4) {
            throw new InvalidArgumentException('An RNG state has four words.');
        }

        $words = [];

        foreach ($state as $word) {
            if (! is_int($word) || $word < 0 || $word > self::MASK) {
                throw new InvalidArgumentException('An RNG state word is a 32-bit unsigned integer.');
            }

            $words[] = $word;
        }

        return new self([$words[0], $words[1], $words[2], $words[3]]);
    }

    /**
     * @return array{int, int, int, int}
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * The next 32-bit output, 0 .. 2^32-1.
     */
    public function next(): int
    {
        [$s0, $s1, $s2, $s3] = $this->state;
        $result = (self::rotl(($s0 + $s3) & self::MASK, 7) + $s0) & self::MASK;
        $t = ($s1 << 9) & self::MASK;
        $s2 ^= $s0;
        $s3 ^= $s1;
        $s1 ^= $s2;
        $s0 ^= $s3;
        $s2 ^= $t;
        $s3 = self::rotl($s3, 11);
        $this->state = [$s0, $s1, $s2, $s3];

        return $result;
    }

    /**
     * 0 .. n-1 by multiply-shift; the product stays below 2^38 for every n the game uses.
     */
    public function below(int $n): int
    {
        return ($this->next() * $n) >> 32;
    }

    public function die(): int
    {
        return 1 + $this->below(6);
    }

    /**
     * A float in [0, 1) from the top 24 bits, exact in double arithmetic.
     */
    public function float(): float
    {
        return ($this->next() >> 8) / 16777216.0;
    }

    /**
     * Fisher-Yates from the back, as the game page's shuffle().
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->below($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    private static function rotl(int $x, int $k): int
    {
        return (($x << $k) | ($x >> (32 - $k))) & self::MASK;
    }

    /**
     * (a * b) mod 2^32 without leaving the 64-bit integer range.
     */
    private static function mul(int $a, int $b): int
    {
        $low = $a * ($b & 0xFFFF);
        $high = (($a * ($b >> 16)) & 0xFFFF) << 16;

        return ($low + $high) & self::MASK;
    }
}
