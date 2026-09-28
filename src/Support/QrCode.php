<?php

declare(strict_types=1);

namespace XArrPay\Support;

use InvalidArgumentException;

/**
 * Self-contained QR encoder for short byte-mode payloads.
 *
 * MFA otpauth URIs fit QR version 5-L. Keeping the encoder here avoids
 * runtime Composer, native qrencode, or third-party network dependencies.
 */
final class QrCode
{
    private const VERSION = 5;
    private const SIZE = 37;
    private const DATA_CODEWORDS = 108;
    private const ECC_CODEWORDS = 26;

    /** @return array{modules: list<list<bool>>, size: int} */
    public static function encode(string $content): array
    {
        $bytes = array_values(unpack('C*', $content) ?: []);
        $bits = [0, 1, 0, 0];
        self::appendBits($bits, count($bytes), 8);
        foreach ($bytes as $byte) {
            self::appendBits($bits, $byte, 8);
        }
        if (count($bits) > self::DATA_CODEWORDS * 8) {
            throw new InvalidArgumentException('二维码内容过长');
        }
        for ($index = 0; $index < min(4, self::DATA_CODEWORDS * 8 - count($bits)); $index++) {
            $bits[] = 0;
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $data = [];
        for ($index = 0; $index < count($bits); $index += 8) {
            $value = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $value = ($value << 1) | $bits[$index + $bit];
            }
            $data[] = $value;
        }
        $pad = [0xec, 0x11];
        $padIndex = 0;
        while (count($data) < self::DATA_CODEWORDS) {
            $data[] = $pad[$padIndex++ % 2];
        }

        $ecc = self::reedSolomonRemainder($data, self::ECC_CODEWORDS);
        $codewords = array_merge($data, $ecc);
        // Mask 1 avoids false finder-pattern matches in the bundled pure-PHP
        // decoder while remaining a standards-compliant QR mask.
        return ['modules' => self::buildMatrix($codewords, 1), 'size' => self::SIZE];
    }

    public static function svgDataUri(string $content, int $scale = 8, int $border = 4): string
    {
        $qr = self::encode($content);
        $size = $qr['size'];
        $viewSize = $size + $border * 2;
        $path = [];
        foreach ($qr['modules'] as $row => $modules) {
            foreach ($modules as $column => $dark) {
                if ($dark) {
                    $path[] = 'M' . ($column + $border) . ',' . ($row + $border) . 'h1v1h-1z';
                }
            }
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $viewSize . ' ' . $viewSize
            . '" width="' . ($viewSize * $scale) . '" height="' . ($viewSize * $scale)
            . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/>'
            . '<path d="' . implode('', $path) . '" fill="#000"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** @param list<int> $bits */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($index = $length - 1; $index >= 0; $index--) {
            $bits[] = ($value >> $index) & 1;
        }
    }

    /** @param list<int> $data @return list<int> */
    private static function reedSolomonRemainder(array $data, int $degree): array
    {
        $generator = [1];
        for ($index = 0; $index < $degree; $index++) {
            $next = array_fill(0, count($generator) + 1, 0);
            $factor = self::gfPow(2, $index);
            foreach ($generator as $position => $coefficient) {
                $next[$position] ^= $coefficient;
                $next[$position + 1] ^= self::gfMultiply($coefficient, $factor);
            }
            $generator = $next;
        }

        $remainder = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            for ($position = 0; $position < $degree; $position++) {
                $coefficient = $generator[$position + 1];
                if ($coefficient !== 0) {
                    $remainder[$position] ^= self::gfMultiply($coefficient, $factor);
                }
            }
        }
        return $remainder;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $result = 0;
        while ($y > 0) {
            if (($y & 1) !== 0) {
                $result ^= $x;
            }
            $y >>= 1;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11d;
            }
        }
        return $result & 0xff;
    }

    private static function gfPow(int $base, int $exponent): int
    {
        $result = 1;
        for ($index = 0; $index < $exponent; $index++) {
            $result = self::gfMultiply($result, $base);
        }
        return $result;
    }

    /** @param list<int> $codewords @return list<list<bool>> */
    private static function buildMatrix(array $codewords, int $mask): array
    {
        $matrix = array_fill(0, self::SIZE, array_fill(0, self::SIZE, null));
        $function = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));

        self::drawFinder($matrix, $function, 0, 0);
        self::drawFinder($matrix, $function, self::SIZE - 7, 0);
        self::drawFinder($matrix, $function, 0, self::SIZE - 7);
        self::drawAlignment($matrix, $function, 30, 30);
        for ($index = 8; $index < self::SIZE - 8; $index++) {
            if (!$function[6][$index]) {
                $matrix[6][$index] = $index % 2 === 0;
                $function[6][$index] = true;
            }
            if (!$function[$index][6]) {
                $matrix[$index][6] = $index % 2 === 0;
                $function[$index][6] = true;
            }
        }
        self::reserveFormat($function);
        $matrix[self::SIZE - 8][8] = true;
        $function[self::SIZE - 8][8] = true;
        self::placeData($matrix, $function, $codewords, $mask);
        self::drawFormat($matrix, $function, $mask);

        $result = [];
        for ($row = 0; $row < self::SIZE; $row++) {
            $result[$row] = [];
            for ($column = 0; $column < self::SIZE; $column++) {
                $result[$row][$column] = (bool) $matrix[$row][$column];
            }
        }
        return $result;
    }

    /** @param list<list<bool|null>> $matrix @param list<list<bool>> $function */
    private static function drawFinder(array &$matrix, array &$function, int $left, int $top): void
    {
        for ($row = -1; $row <= 7; $row++) {
            for ($column = -1; $column <= 7; $column++) {
                $x = $left + $column;
                $y = $top + $row;
                if ($x < 0 || $x >= self::SIZE || $y < 0 || $y >= self::SIZE) {
                    continue;
                }
                $dark = $column >= 0 && $column <= 6 && $row >= 0 && $row <= 6
                    && ($column === 0 || $column === 6 || $row === 0 || $row === 6
                        || ($column >= 2 && $column <= 4 && $row >= 2 && $row <= 4));
                $matrix[$y][$x] = $dark;
                $function[$y][$x] = true;
            }
        }
    }

    /** @param list<list<bool|null>> $matrix @param list<list<bool>> $function */
    private static function drawAlignment(array &$matrix, array &$function, int $centerX, int $centerY): void
    {
        for ($row = -2; $row <= 2; $row++) {
            for ($column = -2; $column <= 2; $column++) {
                $x = $centerX + $column;
                $y = $centerY + $row;
                $matrix[$y][$x] = max(abs($column), abs($row)) !== 1;
                $function[$y][$x] = true;
            }
        }
    }

    /** @param list<list<bool>> $function */
    private static function reserveFormat(array &$function): void
    {
        for ($index = 0; $index < 15; $index++) {
            if ($index < 6) {
                $function[$index][8] = true;
            } elseif ($index < 8) {
                $function[$index + 1][8] = true;
            } else {
                $function[self::SIZE - 15 + $index][8] = true;
            }
            if ($index < 8) {
                $function[8][self::SIZE - $index - 1] = true;
            } elseif ($index < 9) {
                $function[8][15 - $index] = true;
            } else {
                $function[8][15 - $index - 1] = true;
            }
        }
    }

    /** @param list<list<bool|null>> $matrix @param list<list<bool>> $function */
    private static function drawFormat(array &$matrix, array &$function, int $mask): void
    {
        $data = (1 << 3) | $mask; // Error correction level L.
        $bits = ($data << 10) | self::bchRemainder($data, 0x537);
        $bits ^= 0x5412;
        for ($index = 0; $index < 15; $index++) {
            $bit = (($bits >> $index) & 1) !== 0;
            if ($index < 6) {
                $matrix[$index][8] = $bit;
            } elseif ($index < 8) {
                $matrix[$index + 1][8] = $bit;
            } else {
                $matrix[self::SIZE - 15 + $index][8] = $bit;
            }
            if ($index < 8) {
                $matrix[8][self::SIZE - $index - 1] = $bit;
            } elseif ($index < 9) {
                $matrix[8][15 - $index] = $bit;
            } else {
                $matrix[8][15 - $index - 1] = $bit;
            }
        }
        $matrix[self::SIZE - 8][8] = true;
        $function[self::SIZE - 8][8] = true;
    }

    private static function bchRemainder(int $value, int $polynomial): int
    {
        $degree = self::bchDegree($polynomial);
        $value <<= $degree - 1;
        while (self::bchDegree($value) >= $degree) {
            $value ^= $polynomial << (self::bchDegree($value) - $degree);
        }
        return $value;
    }

    private static function bchDegree(int $value): int
    {
        $degree = 0;
        while ($value !== 0) {
            $degree++;
            $value >>= 1;
        }
        return $degree;
    }

    /** @param list<list<bool|null>> $matrix @param list<list<bool>> $function @param list<int> $codewords */
    private static function placeData(array &$matrix, array $function, array $codewords, int $mask): void
    {
        $bits = [];
        foreach ($codewords as $codeword) {
            self::appendBits($bits, $codeword, 8);
        }
        $bitIndex = 0;
        $direction = -1;
        for ($right = self::SIZE - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }
            for ($offset = 0; $offset < self::SIZE; $offset++) {
                $row = $direction === 1 ? $offset : self::SIZE - 1 - $offset;
                for ($column = 0; $column < 2; $column++) {
                    $x = $right - $column;
                    if ($function[$row][$x]) {
                        continue;
                    }
                    $dark = $bitIndex < count($bits) ? $bits[$bitIndex++] === 1 : false;
                    if (self::maskApplies($mask, $row, $x)) {
                        $dark = !$dark;
                    }
                    $matrix[$row][$x] = $dark;
                }
            }
            $direction = -$direction;
        }
        if ($bitIndex !== count($bits)) {
            throw new InvalidArgumentException('二维码数据填充失败');
        }
    }

    private static function maskApplies(int $mask, int $row, int $column): bool
    {
        return match ($mask) {
            0 => ($row + $column) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $column % 3 === 0,
            3 => ($row + $column) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0,
            5 => (($row * $column) % 2) + (($row * $column) % 3) === 0,
            6 => ((($row * $column) % 2) + (($row * $column) % 3)) % 2 === 0,
            7 => ((($row * $column) % 3) + (($row + $column) % 2)) % 2 === 0,
            default => throw new InvalidArgumentException('二维码掩码无效'),
        };
    }
}
