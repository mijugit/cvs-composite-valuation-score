<?php
declare(strict_types=1);

/**
 * One-time favicon generator.
 * Run once from CLI: /usr/local/bin/php84 bin/gen_favicon.php
 * Generates public/images/favicon.png (64x64) from public/images/BG_CVS.png.
 */

if (PHP_SAPI !== 'cli') { exit(1); }
if (!extension_loaded('gd')) { echo "GD not available\n"; exit(1); }

define('ROOT_PATH', dirname(__DIR__));

$src  = ROOT_PATH . '/public/images/BG_CVS.png';
$dest = ROOT_PATH . '/public/images/favicon.png';
$size = 64;

$orig = imagecreatefrompng($src);
if (!$orig) { echo "Cannot open $src\n"; exit(1); }

$w = imagesx($orig);
$h = imagesy($orig);

// Crop a square centered on the bull-bear clash (roughly center of image).
// The clash point is near 50% width, 55% height.
$squareSide = (int) min($w, $h);
$cx = (int) ($w * 0.50);  // center-x: 50% (clash point)
$cy = (int) ($h * 0.55);  // center-y: slightly below center for visual balance

$cropX = max(0, $cx - (int)($squareSide / 2));
$cropY = max(0, $cy - (int)($squareSide / 2));
$cropX = min($cropX, $w - $squareSide);
$cropY = min($cropY, $h - $squareSide);

$favicon = imagecreatetruecolor($size, $size);
imagealphablending($favicon, false);
imagesavealpha($favicon, true);

imagecopyresampled(
    $favicon, $orig,
    0, 0,
    $cropX, $cropY,
    $size, $size,
    $squareSide, $squareSide
);

imagepng($favicon, $dest, 9);
imagedestroy($orig);
imagedestroy($favicon);

echo "favicon.png generated: $dest ({$size}x{$size})\n";
