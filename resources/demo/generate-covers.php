<?php

// Original geometric interface illustrations. No third-party images or remote assets.
$catalogue = require __DIR__.'/projects.php';
if (! is_dir(__DIR__.'/covers')) {
    mkdir(__DIR__.'/covers', 0755, true);
}
foreach ($catalogue as $index => $project) {
    $image = imagecreatetruecolor(1200, 760);
    imageantialias($image, true);
    $colour = fn (string $hex) => imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    $navy = $colour('142b40');
    $teal = $colour('218b88');
    $white = $colour('ffffff');
    $light = $colour('e8eff0');
    $muted = $colour('9bb3b9');
    $accents = ['bfa98a', 'd57d59', '9aafa1', '7598b1', '728a61', 'a19bbc', 'b7a38e', '6ca4a5'];
    $accent = $colour($accents[$index]);
    imagefill($image, 0, 0, $light);
    imagefilledellipse($image, 1120, 70, 650, 650, $accent);
    imagefilledellipse($image, 60, 730, 650, 650, $muted);
    imagefilledrectangle($image, 135, 100, 1065, 650, $white);
    imagefilledrectangle($image, 135, 100, 1065, 145, $navy);
    foreach ([160, 182, 204] as $x) {
        imagefilledellipse($image, $x, 122, 9, 9, $light);
    }
    imagefilledrectangle($image, 185, 175, 340, 188, $navy);
    imagefilledrectangle($image, 835, 179, 1008, 186, $muted);
    $line = function (int $x, int $y, int $width = 180) use ($image, $muted): void {
        imagefilledrectangle($image, $x, $y, $x + $width, $y + 7, $muted);
    };
    switch ($project['key']) {
        case 'hotel':
            imagefilledrectangle($image, 185, 225, 1010, 490, $accent);
            imagefilledrectangle($image, 590, 280, 900, 490, $white);
            imagefilledpolygon($image, [550, 280, 745, 205, 940, 280], $navy);
            foreach ([620, 710, 800] as $x) {
                imagefilledrectangle($image, $x, 315, $x + 55, 395, $teal);
            }
            imagefilledellipse($image, 300, 330, 125, 125, $light);
            $line(195, 535, 330);
            $line(195, 562, 250);
            imagefilledrectangle($image, 800, 530, 1005, 580, $teal);
            break;
        case 'restaurant':
        case 'retailer':
            for ($i = 0; $i < 3; $i++) {
                $x = 185 + $i * 280;
                imagefilledrectangle($image, $x, 230, $x + 245, 480, $light);
                if ($project['key'] === 'restaurant') {
                    imagefilledellipse($image, $x + 122, 345, 180, 180, $white);
                    imagefilledellipse($image, $x + 122, 345, 145, 145, $accent);
                    foreach ([0, 1, 2, 3] as $p) {
                        imagefilledellipse($image, $x + 85 + ($p % 2) * 70, 315 + intdiv($p, 2) * 60, 25, 25, $teal);
                    }
                } else {
                    imagefilledrectangle($image, $x + 65, 315, $x + 180, 415, $accent);
                    imagefilledarc($image, $x + 122, 314, 70, 85, 180, 360, $navy, IMG_ARC_PIE);
                }
                $line($x, 510, 185);
                $line($x, 535, 115);
                imagefilledrectangle($image, $x, 565, $x + 245, 600, $teal);
            }
            break;
        case 'appointments':
            for ($i = 0; $i < 3; $i++) {
                $x = 230 + $i * 265;
                imagefilledrectangle($image, $x, 220, $x + 205, 610, $navy);
                imagefilledrectangle($image, $x + 10, 240, $x + 195, 590, $light);
                imagefilledellipse($image, $x + 102, 300, 64, 64, $accent);
                for ($j = 0; $j < 6; $j++) {
                    imagefilledrectangle($image, $x + 25 + ($j % 2) * 85, 360 + intdiv($j, 2) * 48, $x + 95 + ($j % 2) * 85, 390 + intdiv($j, 2) * 48, $j === $i ? $teal : $white);
                }
            }
            break;
        case 'tea':
            imagefilledrectangle($image, 185, 230, 1010, 600, $light);
            foreach ([300, 510, 720] as $i => $x) {
                imagefilledrectangle($image, $x, 290 - $i * 10, $x + 155, 560, $i === 1 ? $navy : $accent);
                imagefilledrectangle($image, $x + 20, 360, $x + 135, 475, $white);
                imagefilledellipse($image, $x + 78, 410, 58, 75, $teal);
                imageline($image, $x + 78, 380, $x + 78, 445, $white);
            }
            break;
        case 'student':
            for ($i = 0; $i < 3; $i++) {
                $x = 185 + $i * 280;
                imagefilledrectangle($image, $x, 230, $x + 245, 605, $light);
                imagefilledrectangle($image, $x + 15, 250, $x + 180, 262, $i === 2 ? $teal : $navy);
                for ($j = 0; $j < 3 - $i; $j++) {
                    imagefilledrectangle($image, $x + 15, 290 + $j * 95, $x + 230, 365 + $j * 95, $white);
                    $line($x + 30, 310 + $j * 95, 150);
                    $line($x + 30, 335 + $j * 95, 90);
                }
            }
            break;
        case 'portfolio':
            imagefilledrectangle($image, 185, 230, 1010, 500, $light);
            imagefilledpolygon($image, [440, 500, 440, 315, 625, 240, 625, 500], $accent);
            imagefilledpolygon($image, [625, 240, 850, 315, 850, 500, 625, 500], $navy);
            imagefilledrectangle($image, 655, 360, 800, 500, $teal);
            $line(185, 540, 450);
            $line(185, 570, 300);
            break;
        case 'reporting':
            for ($i = 0; $i < 3; $i++) {
                imagefilledrectangle($image, 185 + $i * 280, 230, 430 + $i * 280, 315, $light);
                $line(205 + $i * 280, 250, 100);
            }
            imagefilledrectangle($image, 185, 345, 1010, 600, $light);
            for ($i = 0; $i < 7; $i++) {
                imagefilledrectangle($image, 240 + $i * 105, 540 - (($i * 41 + 35) % 160), 290 + $i * 105, 560, $i === 5 ? $navy : $teal);
            }
            break;
    }
    imagewebp($image, __DIR__.'/covers/'.$project['key'].'.webp', 90);
    imagedestroy($image);
}
echo "Eight original landscape cover illustrations generated.\n";
