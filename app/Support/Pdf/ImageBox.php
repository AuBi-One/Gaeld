<?php

namespace App\Support\Pdf;

/**
 * Fits an image (e.g. the organisation logo) into a box, keeping its aspect
 * ratio, so wide, square and tall images all print whole.
 */
final class ImageBox
{
    /**
     * @return array{width: float, height: float} in the unit of the box (mm)
     */
    public static function fit(string $path, float $maxWidth, float $maxHeight): array
    {
        $size = @getimagesize($path);
        // An unreadable file (corrupt, or a format without dimensions) gets a square box.
        $ratio = $size !== false && $size[0] > 0 && $size[1] > 0 ? $size[0] / $size[1] : 1.0;
        $width = min($maxWidth, $maxHeight * $ratio);

        return ['width' => round($width, 2), 'height' => round($width / $ratio, 2)];
    }
}
