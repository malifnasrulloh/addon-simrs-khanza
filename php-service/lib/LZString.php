<?php

/**
 * LZString - PHP port of ApiBPJSLZString.java
 * 
 * Implements LZ-based string compression/decompression used by BPJS API.
 * Ported from: src/bridging/ApiBPJSLZString.java
 *
 * @author  malifnasrulloh (ported from Java by Antigravity)
 */

declare(strict_types=1);

class LZString
{
    private static string $keyStrUriSafe = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-$';
    private static array $baseReverseDic = [];

    private static function getBaseValue(string $alphabet, string $character): int
    {
        if (!isset(self::$baseReverseDic[$alphabet])) {
            self::$baseReverseDic[$alphabet] = [];
            for ($i = 0; $i < strlen($alphabet); $i++) {
                self::$baseReverseDic[$alphabet][$alphabet[$i]] = $i;
            }
        }
        return self::$baseReverseDic[$alphabet][$character];
    }

    /**
     * Decompress a string that was compressed with compressToEncodedURIComponent.
     * This is the primary method used by BPJS API response decryption.
     */
    public static function decompressFromEncodedURIComponent(?string $inputStr): ?string
    {
        if ($inputStr === null) return '';
        if ($inputStr === '') return null;

        $inputStr = str_replace(' ', '+', $inputStr);
        $alphabet = self::$keyStrUriSafe;

        return self::_decompress(strlen($inputStr), 32, function (int $index) use ($inputStr, $alphabet): int {
            return self::getBaseValue($alphabet, $inputStr[$index]);
        });
    }

    private static function _decompress(int $length, int $resetValue, callable $getNextValue): ?string
    {
        $dictionary = [];
        $enlargeIn = 4;
        $dictSize = 4;
        $numBits = 3;
        $entry = '';
        $result = '';
        $w = '';
        $c = null;

        $dataVal = $getNextValue(0);
        $dataPosition = $resetValue;
        $dataIndex = 1;

        for ($i = 0; $i < 3; $i++) {
            $dictionary[$i] = chr($i);
        }

        // Read first character type
        $bits = 0;
        $maxpower = 4; // 2^2
        $power = 1;
        while ($power !== $maxpower) {
            $resb = $dataVal & $dataPosition;
            $dataPosition >>= 1;
            if ($dataPosition === 0) {
                $dataPosition = $resetValue;
                $dataVal = $getNextValue($dataIndex++);
            }
            $bits |= ($resb > 0 ? 1 : 0) * $power;
            $power <<= 1;
        }

        $next = $bits;
        switch ($next) {
            case 0:
                $bits = 0;
                $maxpower = 256; // 2^8
                $power = 1;
                while ($power !== $maxpower) {
                    $resb = $dataVal & $dataPosition;
                    $dataPosition >>= 1;
                    if ($dataPosition === 0) {
                        $dataPosition = $resetValue;
                        $dataVal = $getNextValue($dataIndex++);
                    }
                    $bits |= ($resb > 0 ? 1 : 0) * $power;
                    $power <<= 1;
                }
                $c = chr($bits);
                break;
            case 1:
                $bits = 0;
                $maxpower = 65536; // 2^16
                $power = 1;
                while ($power !== $maxpower) {
                    $resb = $dataVal & $dataPosition;
                    $dataPosition >>= 1;
                    if ($dataPosition === 0) {
                        $dataPosition = $resetValue;
                        $dataVal = $getNextValue($dataIndex++);
                    }
                    $bits |= ($resb > 0 ? 1 : 0) * $power;
                    $power <<= 1;
                }
                $c = mb_chr($bits, 'UTF-8');
                break;
            case 2:
                return '';
        }

        $dictionary[3] = $c;
        $w = $c;
        $result .= $w;

        while (true) {
            if ($dataIndex > $length) {
                return '';
            }

            $bits = 0;
            $maxpower = 1 << $numBits;
            $power = 1;
            while ($power !== $maxpower) {
                $resb = $dataVal & $dataPosition;
                $dataPosition >>= 1;
                if ($dataPosition === 0) {
                    $dataPosition = $resetValue;
                    $dataVal = $getNextValue($dataIndex++);
                }
                $bits |= ($resb > 0 ? 1 : 0) * $power;
                $power <<= 1;
            }

            $cc = $bits;
            switch ($cc) {
                case 0:
                    $bits = 0;
                    $maxpower = 256; // 2^8
                    $power = 1;
                    while ($power !== $maxpower) {
                        $resb = $dataVal & $dataPosition;
                        $dataPosition >>= 1;
                        if ($dataPosition === 0) {
                            $dataPosition = $resetValue;
                            $dataVal = $getNextValue($dataIndex++);
                        }
                        $bits |= ($resb > 0 ? 1 : 0) * $power;
                        $power <<= 1;
                    }
                    $dictionary[$dictSize++] = chr($bits);
                    $cc = $dictSize - 1;
                    $enlargeIn--;
                    break;
                case 1:
                    $bits = 0;
                    $maxpower = 65536; // 2^16
                    $power = 1;
                    while ($power !== $maxpower) {
                        $resb = $dataVal & $dataPosition;
                        $dataPosition >>= 1;
                        if ($dataPosition === 0) {
                            $dataPosition = $resetValue;
                            $dataVal = $getNextValue($dataIndex++);
                        }
                        $bits |= ($resb > 0 ? 1 : 0) * $power;
                        $power <<= 1;
                    }
                    $dictionary[$dictSize++] = mb_chr($bits, 'UTF-8');
                    $cc = $dictSize - 1;
                    $enlargeIn--;
                    break;
                case 2:
                    return $result;
            }

            if ($enlargeIn === 0) {
                $enlargeIn = 1 << $numBits;
                $numBits++;
            }

            if ($cc < count($dictionary) && isset($dictionary[$cc])) {
                $entry = $dictionary[$cc];
            } else {
                if ($cc === $dictSize) {
                    $entry = $w . $w[0];
                } else {
                    return null;
                }
            }
            $result .= $entry;

            // Add w+entry[0] to dictionary
            $dictionary[$dictSize++] = $w . $entry[0];
            $enlargeIn--;

            $w = $entry;

            if ($enlargeIn === 0) {
                $enlargeIn = 1 << $numBits;
                $numBits++;
            }
        }
    }
}
