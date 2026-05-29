<?php
/**
 * image_utils.php — Utilitaires de traitement d'images côté serveur.
 *
 * Fonctions partagées par upload.php et bdd/optimize_images.php.
 *
 * Pré-requis : extension GD compilée avec le support WebP (imagewebp()).
 * Vérifiable via : phpinfo() → GD → WebP Support = enabled
 *
 * MIME supportés par l'optimiseur : image/jpeg, image/png, image/webp
 * SVG, GIF, ICO sont ignorés (non supportés par GD pour cette opération).
 */

/**
 * Redimensionne et optimise une image.
 *
 * - Redimensionne si la largeur ou la hauteur dépasse $maxDim.
 * - Convertit en WebP si l'extension GD le supporte, sinon garde le format
 *   d'origine (JPEG q=$quality, PNG compressé).
 * - Écrase le fichier en place si $outputPath est null, sinon écrit dans $outputPath.
 * - Retourne le chemin de sortie effectif (peut différer de $inputPath si le
 *   format a changé).
 *
 * @param string   $inputPath   Chemin absolu vers l'image source.
 * @param string   $mime        Type MIME détecté (ex: 'image/jpeg').
 * @param int      $maxDim      Dimension maximale (largeur ou hauteur) en pixels.
 * @param int      $quality     Qualité de compression (0-100).
 * @param string|null $outputPath Chemin de sortie (null = écrasement en place).
 * @return string|false         Chemin de sortie effectif, ou false en cas d'erreur.
 */
function optimizeImage(
    string $inputPath,
    string $mime,
    int $maxDim = 1200,
    int $quality = 85,
    ?string $outputPath = null
): string|false {

    // Normaliser les alias MIME non-standard (ex: Windows/XAMPP)
    if ($mime === 'image/x-png') $mime = 'image/png';

    // Types supportés par GD pour lecture + écriture
    $supported = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if (!in_array($mime, $supported, true)) {
        return false; // SVG, GIF, ICO : ignorer silencieusement
    }

    if (!extension_loaded('gd')) {
        return false;
    }

    // Chargement selon le MIME
    $img = match ($mime) {
        'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($inputPath),
        'image/png'               => @imagecreatefrompng($inputPath),
        'image/webp'              => @imagecreatefromwebp($inputPath),
        default                   => false,
    };

    if (!$img) {
        return false; // Fichier corrompu ou non lisible par GD
    }

    $origW = imagesx($img);
    $origH = imagesy($img);

    // Calcul du redimensionnement (préserver le ratio)
    if ($origW > $maxDim || $origH > $maxDim) {
        if ($origW >= $origH) {
            $newW = $maxDim;
            $newH = (int) round($origH * $maxDim / $origW);
        } else {
            $newH = $maxDim;
            $newW = (int) round($origW * $maxDim / $origH);
        }
        $resized = imagecreatetruecolor($newW, $newH);

        // Préserver la transparence pour PNG/WebP
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($img);
        $img = $resized;
    }

    // Décision du format de sortie : WebP si supporté, sinon format d'origine
    $webpSupported = function_exists('imagewebp');
    $useWebp = $webpSupported && ($mime !== 'image/png' || !hasTransparency($img));

    // Détermination du chemin de sortie
    if ($outputPath === null) {
        $outputPath = $inputPath;
    }

    if ($useWebp) {
        // Remplacer l'extension par .webp
        $outputPath = preg_replace('/\.(jpe?g|png|webp)$/i', '.webp', $outputPath);
        $ok = imagewebp($img, $outputPath, $quality);
    } else {
        $ok = match ($mime) {
            'image/jpeg', 'image/jpg' => imagejpeg($img, $outputPath, $quality),
            'image/png'               => imagepng($img, $outputPath, (int) round((100 - $quality) / 10)),
            'image/webp'              => imagewebp($img, $outputPath, $quality),
            default                   => false,
        };
    }

    imagedestroy($img);

    return $ok ? $outputPath : false;
}

/**
 * Vérifie si une ressource GD contient des pixels transparents.
 * Utilisé pour décider si un PNG doit rester PNG ou peut passer en WebP.
 */
function hasTransparency(\GdImage $img): bool
{
    $w = imagesx($img);
    $h = imagesy($img);
    // Échantillonnage rapide sur une grille 20×20 max
    $stepX = max(1, (int) ($w / 20));
    $stepY = max(1, (int) ($h / 20));
    for ($x = 0; $x < $w; $x += $stepX) {
        for ($y = 0; $y < $h; $y += $stepY) {
            $color = imagecolorat($img, $x, $y);
            $alpha = ($color >> 24) & 0x7F;
            if ($alpha > 0) {
                return true;
            }
        }
    }
    return false;
}
