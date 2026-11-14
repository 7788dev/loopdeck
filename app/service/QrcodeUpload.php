<?php

declare(strict_types=1);

namespace app\service;

final class QrcodeUpload
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_DIMENSION = 4096;
    private const DECODE_DIMENSION = 1024;

    public static function decode(string $path): string|false
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('图片不能超过 2MB');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new \InvalidArgumentException('仅支持 PNG、JPEG 或 WebP 图片');
        }
        $size = @getimagesize($path);
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
            throw new \InvalidArgumentException('图片内容无效，请重新选择图片');
        }
        // Bound the raster allocation before invoking GD. A tiny compressed
        // file can otherwise expand to many millions of pixels.
        if ($size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException('图片宽高不能超过 4096 像素，请裁剪或缩小后上传');
        }
        $source = @imagecreatefromstring((string)file_get_contents($path));
        if (!$source instanceof \GdImage) {
            return false;
        }
        $image = null;
        try {
            $scale = min(1, self::DECODE_DIMENSION / max($size[0], $size[1]));
            $width = max(1, (int)floor($size[0] * $scale));
            $height = max(1, (int)floor($size[1] * $scale));
            $image = imagecreatetruecolor($width, $height);
            if (!$image instanceof \GdImage) {
                return false;
            }
            // Composite transparent/paletted uploads onto white and release the
            // original raster before the decoder builds its PHP pixel arrays.
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            if (!imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1])) {
                return false;
            }
            imagedestroy($source);
            $source = null;
            $text = (new \Zxing\QrReader($image, \Zxing\QrReader::SOURCE_TYPE_RESOURCE, false))->text();
            return is_string($text) ? $text : false;
        } finally {
            if ($source instanceof \GdImage) {
                imagedestroy($source);
            }
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }
}
