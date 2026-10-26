<?php

namespace App\Domains\Ride\Services;

use InvalidArgumentException;

/**
 * Code 128 of the 49-digit access key printed on the RIDE. Digit pairs use set C; the odd last
 * digit switches to set B. Rendered as SVG so dompdf draws exact bars without image extensions.
 */
final class Code128Barcode
{
    /** Bar/space widths of every symbol (0-105) and the stop pattern (106). */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_C = 105;

    private const CODE_B = 100;

    private const STOP = 106;

    /** @return list<int> symbol values, checksum and stop included */
    public function symbols(string $digits): array
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new InvalidArgumentException('Code 128 set C only encodes digits here.');
        }
        $values = [self::START_C];
        $pairs = intdiv(strlen($digits), 2) * 2;
        for ($index = 0; $index < $pairs; $index += 2) {
            $values[] = (int) substr($digits, $index, 2);
        }
        if ($pairs < strlen($digits)) {
            $values[] = self::CODE_B;
            $values[] = ord($digits[$pairs]) - 32;
        }
        $checksum = $values[0];
        foreach (array_slice($values, 1) as $position => $value) {
            $checksum += $value * ($position + 1);
        }
        $values[] = $checksum % 103;
        $values[] = self::STOP;

        return $values;
    }

    public function svg(string $digits, float $moduleWidth = 1.0, float $height = 40.0): string
    {
        $x = 10 * $moduleWidth; // quiet zone
        $rects = '';
        foreach ($this->symbols($digits) as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $element => $width) {
                $width = (int) $width * $moduleWidth;
                if ($element % 2 === 0) {
                    $rects .= sprintf('<rect x="%.2F" y="0" width="%.2F" height="%.2F" fill="#000"/>', $x, $width, $height);
                }
                $x += $width;
            }
        }
        $total = $x + 10 * $moduleWidth;

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%.2F" height="%.2F" viewBox="0 0 %.2F %.2F">%s</svg>', $total, $height, $total, $height, $rects);
    }
}
