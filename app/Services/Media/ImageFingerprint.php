<?php

declare(strict_types=1);

namespace Modules\AI\Services\Media;

use GdImage;
use InvalidArgumentException;
use Throwable;

/**
 * A perceptual fingerprint of an image: the 64-bit difference hash. The picture is scaled to 9 by 8
 * gray pixels and each pixel is compared with its right neighbour, giving 8 by 8 bits written as 16
 * hexadecimal characters. The same picture at another size, or re-encoded, lands a few bits apart;
 * another picture lands about half the bits apart.
 *
 * It needs the GD extension. Without it, or for bytes GD cannot decode, {@see self::of()} gives null and
 * the caller goes on without a fingerprint, so a missing extension never fails an analysis.
 */
final class ImageFingerprint
{
    public const int LENGTH = 16;

    /**
     * Pixels above which an image is not decoded: GD holds four bytes per pixel in memory, so a huge
     * picture could exhaust the worker. The limit is far above any photograph a site serves.
     */
    private const int MAX_PIXELS = 40_000_000;

    private const int COLUMNS = 9;

    private const int ROWS = 8;

    /**
     * @return string|null 16 hexadecimal characters, or null when the bytes are not a decodable image
     */
    public static function of(string $imageBytes): ?string
    {
        if ($imageBytes === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            return self::hash($imageBytes);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Hamming distance of two fingerprints: how many of the 64 bits differ.
     *
     * @throws InvalidArgumentException when either value is not a fingerprint
     */
    public static function distance(string $a, string $b): int
    {
        if (! self::isValid($a) || ! self::isValid($b)) {
            throw new InvalidArgumentException('An image fingerprint is ' . self::LENGTH . ' hexadecimal characters.');
        }

        $bits = 0;

        foreach ([0, 8] as $offset) {
            $difference = hexdec(mb_substr($a, $offset, 8)) ^ hexdec(mb_substr($b, $offset, 8));
            $bits += mb_substr_count(decbin((int) $difference), '1');
        }

        return $bits;
    }

    public static function isValid(string $fingerprint): bool
    {
        return mb_strlen($fingerprint) === self::LENGTH && ctype_xdigit($fingerprint);
    }

    private static function hash(string $imageBytes): ?string
    {
        $size = getimagesizefromstring($imageBytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $source = imagecreatefromstring($imageBytes);

        if ($source === false) {
            return null;
        }

        $small = imagecreatetruecolor(self::COLUMNS, self::ROWS);

        if ($small === false) {
            return null;
        }

        // Transparent areas count as white, so a PNG and its flattened copy agree.
        imagefill($small, 0, 0, (int) imagecolorallocate($small, 255, 255, 255));
        imagecopyresampled($small, $source, 0, 0, 0, 0, self::COLUMNS, self::ROWS, $size[0], $size[1]);

        $bits = '';

        for ($y = 0; $y < self::ROWS; $y++) {
            for ($x = 0; $x < self::COLUMNS - 1; $x++) {
                $bits .= self::gray($small, $x, $y) > self::gray($small, $x + 1, $y) ? '1' : '0';
            }
        }

        return mb_str_pad(dechex((int) bindec(mb_substr($bits, 0, 32))), 8, '0', STR_PAD_LEFT)
            . mb_str_pad(dechex((int) bindec(mb_substr($bits, 32, 32))), 8, '0', STR_PAD_LEFT);
    }

    private static function gray(GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }
}
