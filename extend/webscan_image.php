<?php

declare(strict_types=1);

function webscan_contains_php(string $text): bool
{
    return preg_match('~<\?(?:php(?:\s|/\*)|=)~i', $text) === 1;
}

/** Inspect text and trailing data, never opaque pixels or compressed metadata. */
function webscan_image_contains_php(string $content, string $mime): ?bool
{
    return match ($mime) {
        'image/png' => webscan_png_contains_php($content),
        'image/jpeg' => webscan_jpeg_contains_php($content),
        'image/webp' => webscan_webp_contains_php($content),
        default => null,
    };
}

/** null means malformed framing: the caller must fall back to normal scanning. */
function webscan_png_contains_php(string $content): ?bool
{
    if (!str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
        return null;
    }
    $length = strlen($content);
    $offset = 8;
    while ($offset + 12 <= $length) {
        $size = unpack('N', substr($content, $offset, 4))[1];
        if ($size > $length - $offset - 12) {
            return null;
        }
        $type = substr($content, $offset + 4, 4);
        $start = $offset + 8;
        $offset += $size + 12;
        if ($type === 'tEXt' && webscan_contains_php(substr($content, $start, $size))) {
            return true;
        }
        if ($type === 'iTXt') {
            $data = substr($content, $start, $size);
            $keywordEnd = strpos($data, "\0");
            if ($keywordEnd === false || $keywordEnd + 2 >= $size) {
                return null;
            }
            $languageEnd = strpos($data, "\0", $keywordEnd + 3);
            $translatedEnd = $languageEnd === false ? false : strpos($data, "\0", $languageEnd + 1);
            if ($translatedEnd === false) {
                return null;
            }
            $textEnd = ord($data[$keywordEnd + 1]) === 0 ? $size : $translatedEnd + 1;
            if (webscan_contains_php(substr($data, 0, $textEnd))) {
                return true;
            }
        }
        if ($type === 'IEND') {
            return $size === 0 ? webscan_contains_php(substr($content, $offset)) : null;
        }
    }
    return null;
}

function webscan_jpeg_contains_php(string $content): ?bool
{
    if (!str_starts_with($content, "\xFF\xD8")) {
        return null;
    }
    $length = strlen($content);
    $offset = 2;
    while ($offset < $length) {
        if ($content[$offset] !== "\xFF") {
            return null;
        }
        $offset += strspn($content, "\xFF", $offset);
        if ($offset >= $length) {
            return null;
        }
        $marker = ord($content[$offset++]);
        if ($marker === 0xD9) {
            return webscan_contains_php(substr($content, $offset));
        }
        if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
            continue;
        }
        if ($marker === 0x00 || $marker === 0xD8 || $offset + 2 > $length) {
            return null;
        }
        $size = unpack('n', substr($content, $offset, 2))[1];
        if ($size < 2 || $size > $length - $offset) {
            return null;
        }
        $dataStart = $offset + 2;
        $offset += $size;
        if ($marker === 0xFE && webscan_contains_php(substr($content, $dataStart, $size - 2))) {
            return true;
        }
        if ($marker === 0xE1) {
            $data = substr($content, $dataStart, $size - 2);
            $xmpPrefix = "http://ns.adobe.com/xap/1.0/\0";
            if (str_starts_with($data, $xmpPrefix)
                && webscan_contains_php(substr($data, strlen($xmpPrefix)))) {
                return true;
            }
        }
        if ($marker === 0xDA) {
            // JPEG scan bytes escape FF as FF00; restart markers are also data.
            // The next other marker resumes segment parsing (also progressive JPEG).
            while (true) {
                $next = strpos($content, "\xFF", $offset);
                if ($next === false) {
                    return null;
                }
                $after = $next + strspn($content, "\xFF", $next);
                if ($after >= $length) {
                    return null;
                }
                $scanMarker = ord($content[$after]);
                if ($scanMarker !== 0 && ($scanMarker < 0xD0 || $scanMarker > 0xD7)) {
                    $offset = $next;
                    break;
                }
                $offset = $after + 1;
            }
        }
    }
    return null;
}

function webscan_webp_contains_php(string $content): ?bool
{
    $length = strlen($content);
    if ($length < 12 || substr($content, 0, 4) !== 'RIFF' || substr($content, 8, 4) !== 'WEBP') {
        return null;
    }
    $end = unpack('V', substr($content, 4, 4))[1] + 8;
    if ($end < 12 || $end > $length) {
        return null;
    }
    $offset = 12;
    while ($offset + 8 <= $end) {
        $type = substr($content, $offset, 4);
        $size = unpack('V', substr($content, $offset + 4, 4))[1];
        $paddedSize = $size + ($size & 1);
        if ($paddedSize > $end - $offset - 8) {
            return null;
        }
        if ($type === 'XMP ' && webscan_contains_php(substr($content, $offset + 8, $size))) {
            return true;
        }
        $offset += 8 + $paddedSize;
    }
    return $offset === $end ? webscan_contains_php(substr($content, $end)) : null;
}
