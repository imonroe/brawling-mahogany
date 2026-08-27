<?php

declare(strict_types=1);

/**
 * Generate the PWA icons from the product mark (#102).
 *
 * Run: `php scripts/generate-pwa-icons.php`
 *
 * ## Why a script rather than three committed mystery PNGs
 *
 * The icons are derived, not drawn. `resources/img/goldieflow.png` is the
 * source of truth — it is what `AppLogoIcon` puts in the sidebar — and a
 * home-screen icon that drifts from the one in the app is the kind of thing
 * nobody notices until somebody has thirty of them on a phone. Regenerating
 * is then a command rather than an archaeology exercise.
 *
 * ## The plate, and why it is not transparent
 *
 * Design System §2.6: *"a raster asset cannot participate in the token
 * layer."* The mark is a fixed two-tone PNG whose darker tone all but
 * disappears on a dark ground, which is why `AppLogoIcon` gives it
 * `dark:bg-logo-plate` in the app. A launcher is a dark ground we do not
 * control and cannot query, so the plate is baked in — the same decision the
 * email layout makes for the same reason, one surface along.
 *
 * ## `any` and `maskable` are different pictures, not different sizes
 *
 * A `maskable` icon is cropped by the launcher to whatever shape it likes —
 * circle, squircle, rounded square — and only the inner 80% is guaranteed to
 * survive. So the mark is drawn smaller inside a plate that bleeds to the
 * edge. Shipping one image for both purposes is the common mistake: declared
 * `maskable`, an `any`-shaped icon gets its edges shaved off.
 */
const SOURCE = __DIR__.'/../resources/img/goldieflow.png';
const OUT = __DIR__.'/../public/icons';

/** `--logo-plate`, oklch(0.97 0.005 250), as the sRGB a PNG can hold. */
const PLATE = [0xF2, 0xF5, 0xF8];

/**
 * @param  float  $scale  how much of the canvas the mark may occupy
 */
function render(string $path, int $size, float $scale, bool $rounded): void
{
    $source = imagecreatefrompng(SOURCE);

    if ($source === false) {
        throw new RuntimeException('Cannot read '.SOURCE);
    }

    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);

    $plate = imagecolorallocate($canvas, ...PLATE);
    $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

    imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, $rounded ? $clear : $plate);

    if ($rounded) {
        /*
         * A rounded plate for the `any` icon, because iOS is the one platform
         * that does **not** mask: it renders `apple-touch-icon` as given, and
         * a square white tile beside rounded neighbours looks like a mistake
         * rather than a choice. Android masks this one too, and a radius
         * inside a mask is invisible — so the corner costs nothing where it
         * is not wanted.
         */
        imagealphablending($canvas, true);
        $radius = (int) round($size * 0.22);
        imagefilledrectangle($canvas, $radius, 0, $size - $radius - 1, $size - 1, $plate);
        imagefilledrectangle($canvas, 0, $radius, $size - 1, $size - $radius - 1, $plate);

        foreach ([[$radius, $radius], [$size - $radius - 1, $radius], [$radius, $size - $radius - 1], [$size - $radius - 1, $size - $radius - 1]] as [$cx, $cy]) {
            imagefilledellipse($canvas, $cx, $cy, $radius * 2, $radius * 2, $plate);
        }
    }

    imagealphablending($canvas, true);

    /*
     * Contained, never stretched. The mark is 734x779, so fitting it to a
     * square by scaling both axes would squash it — the reason `AppLogoIcon`
     * carries `object-contain` at the component rather than at its call sites.
     */
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $box = $size * $scale;
    $ratio = min($box / $sourceWidth, $box / $sourceHeight);

    $width = (int) round($sourceWidth * $ratio);
    $height = (int) round($sourceHeight * $ratio);

    imagecopyresampled(
        $canvas,
        $source,
        (int) round(($size - $width) / 2),
        (int) round(($size - $height) / 2),
        0,
        0,
        $width,
        $height,
        $sourceWidth,
        $sourceHeight,
    );

    imagepng($canvas, $path, 9);
    imagedestroy($canvas);
    imagedestroy($source);

    printf("%s  %dx%d\n", basename($path), $size, $size);
}

if (! is_dir(OUT)) {
    mkdir(OUT, 0o755, true);
}

// `any`: the mark with a comfortable inset, on a rounded plate.
render(OUT.'/icon-192.png', 192, 0.68, true);
render(OUT.'/icon-512.png', 512, 0.68, true);

/*
 * `maskable`: plate to the edge, mark inside the guaranteed-safe inner 80%
 * with room to spare. 0.5 rather than 0.8, because "safe" means *not cropped*
 * and a mark touching the safe circle still reads as crowded.
 */
render(OUT.'/icon-maskable-512.png', 512, 0.5, false);

// iOS renders this one as given, at whatever size it likes. 180 is the
// largest it asks for, and it must not be transparent — a transparent
// apple-touch-icon is composited onto black.
render(__DIR__.'/../public/apple-touch-icon.png', 180, 0.68, true);
