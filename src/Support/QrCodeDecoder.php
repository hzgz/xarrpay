<?php

declare(strict_types=1);

namespace XArrPay\Support;

use RuntimeException;
use Zxing\QrReader;

final class QrCodeDecoder
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public static function decodeFile(string $path): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('服务器未启用 GD 图像扩展，无法解析二维码');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('二维码图片不存在或不可读取');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('二维码图片为空');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('二维码图片不能超过 10 MB');
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('二维码图片读取失败');
        }
        return self::decodeBlob($contents);
    }

    public static function decodeBlob(string $contents): string
    {
        $imageInfo = @getimagesizefromstring($contents);
        if (!is_array($imageInfo)) {
            throw new RuntimeException('上传文件不是有效图片');
        }

        $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'], true)) {
            throw new RuntimeException('二维码图片格式不支持');
        }

        try {
            $reader = new QrReader($contents, QrReader::SOURCE_TYPE_BLOB, false);
            $text = $reader->text();
        } catch (\Throwable $exception) {
            throw new RuntimeException('二维码图片解析失败', 0, $exception);
        }

        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('图片中未识别到二维码');
        }

        return $text;
    }
}
