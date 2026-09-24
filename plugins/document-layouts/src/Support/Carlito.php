<?php

namespace Plugins\DocumentLayouts\Support;

use Illuminate\Support\Facades\File;
use Plugins\Offers\Services\OfferPdf;
use TCPDF;
use TCPDF_FONTS;

/**
 * Carlito (SIL OFL 1.1, metric-compatible with Calibri, the font of the Word
 * models), shipped with the offers plugin. TCPDF needs its own font files,
 * converted once from the TTF into the (git-ignored) cache folder.
 */
final class Carlito
{
    public const FAMILY = 'carlito';

    private const STYLES = ['' => 'Regular', 'B' => 'Bold', 'I' => 'Italic', 'BI' => 'BoldItalic'];

    /** Folder of the TTF files, for dompdf's @font-face (and its chroot). */
    public static function ttfPath(): string
    {
        return OfferPdf::fontsPath();
    }

    /** Adds the four styles to a TCPDF document. */
    public static function register(TCPDF $pdf): void
    {
        $dir = self::tcpdfFonts();
        foreach (array_keys(self::STYLES) as $style) {
            $pdf->AddFont(self::FAMILY, $style, $dir.self::FAMILY.strtolower($style).'.php');
        }
    }

    private static function tcpdfFonts(): string
    {
        $dir = storage_path('framework/cache/tcpdf-fonts/');
        // The marker is written after the last file: its presence means every file is complete.
        if (is_file($dir.'carlito.done')) {
            return $dir;
        }
        File::ensureDirectoryExists($dir);
        // One process converts; the others wait (a half-written font file would break their PDF).
        $lock = fopen($dir.'.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock the TCPDF font cache: '.$dir);
        }
        try {
            flock($lock, LOCK_EX);
            if (is_file($dir.'carlito.done')) {
                return $dir;
            }
            // Files left by an interrupted conversion would be taken as complete.
            File::delete(File::glob($dir.self::FAMILY.'*'));
            foreach (self::STYLES as $style => $file) {
                // Returns at once when the font was converted already.
                $name = TCPDF_FONTS::addTTFfont(self::ttfPath()."/Carlito-{$file}.ttf", 'TrueTypeUnicode', '', 32, $dir);
                if ($name !== self::FAMILY.strtolower($style)) {
                    throw new \RuntimeException("Carlito {$file} could not be converted for TCPDF");
                }
            }
            touch($dir.'carlito.done');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $dir;
    }
}
