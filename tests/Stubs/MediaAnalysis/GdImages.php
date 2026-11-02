<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\MediaAnalysis;

use RuntimeException;

/**
 * Builds small test images with GD, so no binary is committed. The picture is a mosaic of
 * gray cells whose levels come from the seed alone: the same seed at another size is the same
 * picture scaled, another seed is another picture.
 */
final class GdImages
{
    private const int CELLS_X = 12;

    private const int CELLS_Y = 10;

    public static function mosaicPng(string $seed, int $width = 240, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD cannot create an image.');
        }

        for ($cell_y = 0; $cell_y < self::CELLS_Y; $cell_y++) {
            for ($cell_x = 0; $cell_x < self::CELLS_X; $cell_x++) {
                $level = hexdec(mb_substr(md5("{$seed}:{$cell_x}:{$cell_y}"), 0, 2));
                $color = imagecolorallocate($image, $level, $level, $level);

                imagefilledrectangle(
                    $image,
                    (int) floor($cell_x * $width / self::CELLS_X),
                    (int) floor($cell_y * $height / self::CELLS_Y),
                    (int) floor(($cell_x + 1) * $width / self::CELLS_X) - 1,
                    (int) floor(($cell_y + 1) * $height / self::CELLS_Y) - 1,
                    (int) $color,
                );
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
