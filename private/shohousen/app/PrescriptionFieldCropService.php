<?php
declare(strict_types=1);

/**
 * 承認済みテンプレート領域がある項目だけ、PHP GDで該当枠を切り出してOCR補助画像を作る。
 * 座標未登録の初見テンプレートでは何もせず、全体画像の項目指定再解析にフォールバックする。
 */
final class PrescriptionFieldCropService
{
    public function __construct(
        private readonly PrescriptionLayoutRegionRepository $regions = new PrescriptionLayoutRegionRepository(),
    ) {}

    /** @return array<string,mixed>|null */
    public function cropForField(string $sourcePath, string $sourceMime, string $fieldKey, array $context = []): ?array
    {
        if (!(bool)app_config('prescription_field_crop.enabled', true)) {
            return null;
        }
        if (!extension_loaded('gd') || !is_file($sourcePath)) {
            return null;
        }
        $region = $this->regions->findApprovedRegion($fieldKey, $context);
        if (!$region) {
            return null;
        }
        return $this->cropByRegion($sourcePath, $sourceMime, $region, $fieldKey);
    }

    /** @param array<string,mixed> $region @return array<string,mixed>|null */
    private function cropByRegion(string $sourcePath, string $sourceMime, array $region, string $fieldKey): ?array
    {
        $src = $this->loadImage($sourcePath, $sourceMime);
        if (!$src) {
            return null;
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            return null;
        }

        $margin = (float)($region['margin_ratio'] ?? 0.01);
        $x = (int)floor(((float)$region['x_ratio'] - $margin) * $srcW);
        $y = (int)floor(((float)$region['y_ratio'] - $margin) * $srcH);
        $w = (int)ceil(((float)$region['w_ratio'] + $margin * 2) * $srcW);
        $h = (int)ceil(((float)$region['h_ratio'] + $margin * 2) * $srcH);
        $x = max(0, min($srcW - 1, $x));
        $y = max(0, min($srcH - 1, $y));
        $w = max(1, min($srcW - $x, $w));
        $h = max(1, min($srcH - $y, $h));

        $scale = max(2.0, min(4.0, 900.0 / max(1, max($w, $h))));
        $dstW = max(1, (int)round($w * $scale));
        $dstH = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($dstW, $dstH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $dstW, $dstH, $w, $h);
        imagedestroy($src);

        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        imagefilter($dst, IMG_FILTER_CONTRAST, -18);
        imagefilter($dst, IMG_FILTER_BRIGHTNESS, 4);
        if (function_exists('imageconvolution')) {
            @imageconvolution($dst, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
        }

        $dir = rtrim((string)app_config('storage.prescription_field_crop_dir', dirname(__DIR__) . '/storage/prescription_field_crops'), '/')
            . '/' . date('Y/m');
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $safeKey = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $fieldKey) ?: 'field';
        $path = $dir . '/crop_' . $safeKey . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (!imagejpeg($dst, $path, 94)) {
            imagedestroy($dst);
            return null;
        }
        imagedestroy($dst);

        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'width' => $dstW,
            'height' => $dstH,
            'source_field_key' => $fieldKey,
            'region' => $region,
            'preprocess_profile' => 'field_crop_gd_v1',
            'sha256' => hash_file('sha256', $path) ?: '',
            'size' => filesize($path) ?: 0,
        ];
    }

    private function loadImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        } ?: false;
    }
}
