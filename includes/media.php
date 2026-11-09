<?php
// Media helpers shared by the upload API and the File Manager.

/** "SSC Result 2026!.JPG" → "ssc-result-2026" */
function edu_media_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim(substr(trim($s, '-'), 0, 80), '-');
    return $s !== '' ? $s : 'file';
}

/** First free name in the folder: base, base-2, base-3 … (checks the file and its size variants). */
function edu_media_unique_base(string $dir, string $sub, string $base, string $ext): string
{
    for ($i = 1; $i < 10000; $i++) {
        $try = $i === 1 ? $base : $base . '-' . $i;
        if (!file_exists("$dir/$try.$ext") && !file_exists("$dir/$try-480.$ext") && !glob("$dir/$try.*")) return $try;
    }
    return $base . '-' . substr(uniqid(), -6);
}

/** Who uploaded a file (added column — older rows have none). */
function edu_media_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM media LIKE 'uploaded_by'")->fetch()) $pdo->exec("ALTER TABLE media ADD COLUMN uploaded_by INT NULL");
    } catch (Throwable $e) {
    }
}

/** Everything that belongs to one media row on disk (main file + responsive sizes). */
function edu_media_files(array $m): array
{
    $files = [ltrim((string)$m['file_path'], '/')];
    foreach (json_decode((string)($m['responsive_set'] ?? ''), true) ?: [] as $p) $files[] = ltrim((string)$p, '/');
    return array_values(array_unique(array_filter($files)));
}

function edu_media_kind(array $m): string
{
    $t = (string)$m['file_type'];
    if (in_array($t, ['image', 'banner'], true) || preg_match('/\.(webp|jpe?g|png|gif|avif|svg|ico)$/i', (string)$m['file_path'])) return 'image';
    if ($t === 'pdf' || preg_match('/\.pdf$/i', (string)$m['file_path'])) return 'pdf';
    if (in_array($t, ['video', 'audio'], true)) return $t;
    return 'document';
}

/**
 * Saves a GD image as WebP files under $dir (URL folder $sub, file name $base):
 * original shape, largest side 1280px (+ 480/768/1024 wide), or — $featured — a
 * 16:9 centre crop at exactly 1200×675 (+ 480/768/1024 wide) under 100 KB.
 * Returns [ main path, responsive set, width, height ].
 */
function edu_media_save_webp($src, string $dir, string $sub, string $base, bool $featured, int $maxMain = 1280): array // $maxMain: slider banners keep up to 1920px
{
    $set = [];
    $w = imagesx($src); $h = imagesy($src);
    $save = function (int $maxW, string $suffix, int $q) use ($src, $w, $h, $dir, $base, $sub) {
        $nw = min($w, $maxW); $nh = (int)round($h * $nw / $w);
        $im = imagecreatetruecolor($nw, $nh);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagecopyresampled($im, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $name = $base . $suffix . '.webp';
        imagewebp($im, $dir . '/' . $name, $q);
        return $sub . '/' . $name;
    };
    if ($featured) {
        // centre crop to 16:9, then exactly 1200×675 (Google Discover wants ≥1200px wide)
        $cw = $w; $ch = (int)round($w * 9 / 16);
        if ($ch > $h) { $ch = $h; $cw = (int)round($h * 16 / 9); }
        $cx = (int)(($w - $cw) / 2); $cy = (int)(($h - $ch) / 2);
        $crop = function (int $nw, string $suffix, int $maxBytes) use ($src, $cx, $cy, $cw, $ch, $dir, $base, $sub) {
            $nh = (int)round($nw * 9 / 16);
            $im = imagecreatetruecolor($nw, $nh);
            imagealphablending($im, false); imagesavealpha($im, true);
            imagecopyresampled($im, $src, 0, 0, $cx, $cy, $nw, $nh, $cw, $ch);
            $file = $dir . '/' . $base . $suffix . '.webp';
            foreach ([82, 76, 70, 64, 58, 52, 46] as $q) { // best quality that stays under the size limit
                imagewebp($im, $file, $q);
                clearstatcache(true, $file);
                if (filesize($file) <= $maxBytes) break;
            }
            imagedestroy($im);
            return $sub . '/' . $base . $suffix . '.webp';
        };
        $path = $crop(1200, '', 100 * 1024);
        foreach (['sm' => 480, 'md' => 768, 'lg' => 1024] as $k => $mw) $set[$k] = $crop($mw, '-' . $mw, 60 * 1024);
        $set['xl'] = $path;
        [$w, $h] = [1200, 675];
    } else {
        $path = $save($maxMain, '', $maxMain > 1280 ? 80 : 72);
        if ($maxMain <= 1280 && filesize($dir . '/' . basename($path)) > 90000) $path = $save(1280, '', 60); // aim for a light main image
        foreach (['sm' => 480, 'md' => 768, 'lg' => 1024] as $k => $mw) {
            if ($w > $mw) $set[$k] = $save($mw, '-' . $mw, 68);
        }
        $set['xl'] = $path;
        $mw0 = $maxMain > 1280 ? $maxMain : 1280;
        [$w, $h] = [min($w, $mw0), (int)round($h * min($w, $mw0) / max(1, $w))];
    }
    return [$path, $set, $w, $h];
}
