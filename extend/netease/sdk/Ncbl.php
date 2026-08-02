<?php

declare(strict_types=1);

namespace netease\sdk;

use RuntimeException;

/**
 * NetEase desktop client-log (NCBL v3) encoder.
 *
 * This is a PHP port of api-enhanced/util/ncbl.js. The binary format is used
 * by the current desktop client for _plv (play start) and _pld (play end)
 * records. A normal HTTP 200 is not enough: callers must also verify that the
 * response data.successfiles array contains the uploaded file name.
 */
final class Ncbl
{
    public const MAGIC = 'NCBL';
    public const VERSION = 3;
    public const HEADER_FIXED_LENGTH = 70;
    public const META_BLOCK_TYPE = 0x4343;
    public const DEFAULT_MAX_FRAME = 0x8000;
    public const FIELD_SEPARATOR = "\x01";
    public const UPLOAD_URL = 'https://clientlog3.music.163.com/api/clientlog/encrypt/upload?multiupload=true';

    private const RSA_MODULUS_HEX = 'fd90bd466ff9bc8a3fec2fbcf263b90d5c564879fa5d7aab89b31c1d5cb4139d';
    private const RSA_EXPONENT = '65537';
    private const SIGMA = [0x61707865, 0x3320646e, 0x79622d32, 0x6b206574];

    private static ?string $rsaModulusDecimal = null;

    public static function chacha20(string $key, int $counter, string $nonce, string $data): string
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('NCBL ChaCha20 key must be 32 bytes');
        }
        if (strlen($nonce) !== 12) {
            throw new RuntimeException('NCBL ChaCha20 nonce must be 12 bytes');
        }

        $output = '';
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset += 64) {
            $blockCounter = (int)(($counter + intdiv($offset, 64)) & 0xffffffff);
            $stream = self::chachaBlock($key, $blockCounter, $nonce);
            $chunk = substr($data, $offset, 64);
            $output .= $chunk ^ substr($stream, 0, strlen($chunk));
        }
        return $output;
    }

    public static function rsaWrap(string $key): string
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('NCBL RSA-wrapped key must be 32 bytes');
        }
        if (!function_exists('bcpowmod')) {
            throw new RuntimeException('NCBL RSA wrapping requires the PHP bcmath extension');
        }

        $wrapped = bcpowmod(
            self::bytesToDecimal($key),
            self::RSA_EXPONENT,
            self::rsaModulusDecimal(),
            0
        );
        if (!is_string($wrapped)) {
            throw new RuntimeException('NCBL RSA key wrapping failed');
        }
        return self::decimalToBytes($wrapped, 32);
    }

    /**
     * @param array{keyA?:string,uuid?:string,baseSeq?:int,maxFrame?:int} $options
     */
    public static function encrypt(string $meta, string $body, array $options = []): string
    {
        $maxFrame = (int)($options['maxFrame'] ?? self::DEFAULT_MAX_FRAME);
        if ($maxFrame < 1 || $maxFrame > 0xffff) {
            throw new RuntimeException('NCBL max frame must be between 1 and 65535 bytes');
        }

        $keyA = (string)($options['keyA'] ?? random_bytes(32));
        if (strlen($keyA) !== 32) {
            throw new RuntimeException('NCBL content key must be 32 bytes');
        }
        if (ord($keyA[0]) >= 0xa3) {
            $keyA[0] = chr(0xa2);
        }
        $keyB = self::rsaWrap($keyA);

        $uuid = (string)($options['uuid'] ?? random_bytes(16));
        if (strlen($uuid) !== 16) {
            throw new RuntimeException('NCBL UUID must be 16 bytes');
        }
        if (!array_key_exists('uuid', $options)) {
            $uuid[6] = chr((ord($uuid[6]) & 0x0f) | 0x40);
            $uuid[8] = chr((ord($uuid[8]) & 0x3f) | 0x80);
        }

        $nonce = substr($uuid, 0, 12);
        $counter = ((int)unpack('Vvalue', substr($uuid, 12, 4))['value']) >> 2;
        $baseSeq = array_key_exists('baseSeq', $options)
            ? (int)$options['baseSeq']
            : random_int(0, 0xffff);
        $baseSeq &= 0xffffffff;

        $metaCipher = self::chacha20($keyB, $counter, $nonce, $meta);
        if (strlen($metaCipher) > 0xffff) {
            throw new RuntimeException('NCBL metadata block is too large');
        }
        $metaBlock = pack('vv', self::META_BLOCK_TYPE, strlen($metaCipher)) . $metaCipher;
        $headerLength = self::HEADER_FIXED_LENGTH + strlen($metaBlock);
        if ($headerLength > 0xffff) {
            throw new RuntimeException('NCBL header is too large');
        }

        $compressed = gzencode($body, -1, ZLIB_ENCODING_GZIP);
        if (!is_string($compressed)) {
            throw new RuntimeException('NCBL gzip compression failed');
        }
        // zlib writes a host-specific gzip OS marker (Windows=10, Unix=3).
        // The production desktop-log implementation runs with the Unix marker;
        // normalize it so deterministic vectors and deployed payloads agree.
        if (strlen($compressed) >= 10) {
            $compressed[9] = chr(3);
        }

        $trailing = '';
        $sequence = $baseSeq;
        $compressedLength = strlen($compressed);
        for ($offset = 0; $offset < $compressedLength || $offset === 0; $offset += $maxFrame) {
            $slice = substr($compressed, $offset, $maxFrame);
            $cipher = self::chacha20($keyA, $counter, $nonce, $slice);
            $trailing .= pack('vV', strlen($cipher), $sequence) . $cipher;
            $sequence = (int)(($sequence + 1) & 0xffffffff);
            if ($compressedLength === 0) {
                break;
            }
        }

        $endSequence = (int)(($sequence - 1) & 0xffffffff);
        $header = self::MAGIC
            . pack('V', self::VERSION)
            . pack('v', $headerLength)
            . $uuid
            . $keyB
            . pack('V', $baseSeq)
            . pack('V', $endSequence)
            . pack('V', strlen($trailing));
        if (strlen($header) !== self::HEADER_FIXED_LENGTH) {
            throw new RuntimeException('NCBL fixed header length mismatch');
        }

        return $header . $metaBlock . $trailing;
    }

    /** @param array<string,mixed>|string $data */
    public static function buildRecord(int $time, string $action, $data): string
    {
        $json = is_string($data) ? $data : self::jsonEncode($data);
        return (string)$time . self::FIELD_SEPARATOR . $action . self::FIELD_SEPARATOR . $json;
    }

    /** @param array<int,array{time:int,action:string,data:array<string,mixed>|string}> $records */
    public static function buildRecords(array $records): string
    {
        $body = '';
        foreach ($records as $record) {
            $body .= self::buildRecord(
                (int)($record['time'] ?? 0),
                (string)($record['action'] ?? ''),
                $record['data'] ?? []
            );
        }
        return $body;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $song
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    public static function buildPlv(array $context, array $song, array $source, ?int $nowMs = null): array
    {
        $nowMs ??= (int)round(microtime(true) * 1000);
        $version = (string)($context['app']['version'] ?? '3.1.35');
        $versionCode = (string)($context['app']['versionCode'] ?? '205293');
        $songId = (string)($song['id'] ?? '');
        $sourceId = (string)($source['id'] ?? $songId);
        $addRefer = '[F:63][' . $nowMs . '#933#' . $version . '#' . $versionCode
            . '#c9156c3][e][2][23][cell_pc_songlist_song:2|page_pc_songlist_songflow|page_mine_like_music]['
            . $songId . ':song:x:x|:::|' . $sourceId . ':list::]';

        return [
            'mode' => 'circulation',
            'download' => 0,
            'alg' => '',
            'status' => 'front',
            'id' => $songId,
            'bitrate' => (int)($song['bitrate'] ?? 320),
            'type' => 'song',
            'is_listentogether' => 0,
            'source' => (string)($source['name'] ?? 'list'),
            'is_heart' => 0,
            'resource_ratio' => '',
            'resource_time' => (int)($song['time'] ?? 0),
            'musiceffect_id' => '',
            'app_mode' => 2,
            'bitrate_level' => (string)($song['level'] ?? 'exhigh'),
            '_addrefer' => $addRefer,
            '_multirefers' => [
                '[F:26][s][18][_ai]',
                '[F:26][s][12][_ai]',
                '[F:63][' . $nowMs . '#933#' . $version . '#' . $versionCode
                    . '#c9156c3][e][2][8][cell_pc_main_tab_entrance:6|page_pc_main_tab][我喜欢的音乐:spm::|:::]',
                '[F:26][s][5][_ai]',
                '[F:26][s][0][_ai]',
            ],
            'vipType' => $context['auth']['vipType'] ?? '',
            'fee' => 1,
            'file' => 4,
            'rightSource' => 0,
            'sourceId' => $sourceId,
            'sourcetype' => (string)($source['type'] ?? 'track'),
            'libra_abt' => '',
            'channel' => (string)($context['app']['channel'] ?? 'netease'),
            'curStartChannel' => '',
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $song
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    public static function buildPld(
        array $context,
        array $song,
        array $source,
        int $played,
        ?int $nowMs = null
    ): array {
        $nowMs ??= (int)round(microtime(true) * 1000);
        $version = (string)($context['app']['version'] ?? '3.1.35');
        $versionCode = (string)($context['app']['versionCode'] ?? '205293');
        $songId = (string)($song['id'] ?? '');
        $sourceId = (string)($source['id'] ?? $songId);
        $addRefer = '[F:63][' . $nowMs . '#616#' . $version . '#' . $versionCode
            . '#c9156c3][e][2][92][btn_pc_cover_play|cell_pc_songlist_song:6|page_pc_songlist_songflow|page_mine_like_music]'
            . '[:::|' . $songId . ':song:x:x|:::|' . $sourceId . ':list::]';

        return [
            'mode' => 'circulation',
            'download' => 0,
            'alg' => '',
            'status' => 'front',
            'id' => $songId,
            'time' => $played,
            'type' => 'song',
            'is_listentogether' => 0,
            'source' => (string)($source['name'] ?? 'list'),
            'is_heart' => 0,
            'realtime' => $played,
            'resource_ratio' => '',
            'resource_time' => (int)($song['time'] ?? 0),
            'musiceffect_id' => '1001',
            'app_mode' => 1,
            'lyriceffect' => 'default',
            'displayMode' => 'classic',
            'bitrate' => (int)($song['bitrate'] ?? 320),
            'bitrate_level' => (string)($song['level'] ?? 'exhigh'),
            '_addrefer' => $addRefer,
            '_multirefers' => [
                '[F:26][s][87][_ai]',
                '[F:26][s][81][_ai]',
                '[F:26][s][75][_ai]',
                '[F:26][s][69][_ai]',
                '[F:26][s][63][_ai]',
            ],
            'vipType' => $context['auth']['vipType'] ?? '',
            'fee' => 8,
            'file' => 4,
            'rightSource' => 0,
            'sourceId' => $sourceId,
            'sourcetype' => (string)($source['type'] ?? 'track'),
            'end' => 'interrupt',
            'libra_abt' => '',
            'channel' => (string)($context['app']['channel'] ?? 'netease'),
            'curStartChannel' => '',
        ];
    }

    /** @param array<string,mixed> $context */
    public static function buildMetaJson(array $context): string
    {
        return self::jsonEncode([
            'JSESSIONID-WYYY' => (string)($context['auth']['sessionId'] ?? ''),
            'MUSIC_U' => (string)($context['auth']['token'] ?? ''),
            'NMTID' => (string)($context['device']['ti'] ?? ''),
            'WEVNSM' => (string)($context['app']['nsm'] ?? '1.0.0'),
            'WNMCID' => (string)($context['app']['cid'] ?? ''),
            '__csrf' => (string)($context['device']['csrf'] ?? ''),
            '_iuqxldmzr_' => '33',
            '_ntes_nnid' => (string)($context['device']['nnid'] ?? ','),
            '_ntes_nuid' => (string)($context['device']['nuid'] ?? ''),
            'appver' => self::desktopAppVersion($context),
            'channel' => (string)($context['app']['channel'] ?? 'netease'),
            'clientSign' => (string)($context['device']['sign'] ?? ''),
            'deviceId' => (string)($context['device']['id'] ?? ''),
            'mode' => (string)($context['device']['model'] ?? ''),
            'ntes_kaola_ad' => '1',
            'os' => (string)($context['device']['systemType'] ?? 'pc'),
            'osver' => (string)($context['device']['systemVersion'] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $context */
    public static function buildCookie(array $context): string
    {
        $parts = [
            'JSESSIONID-WYYY=' . (string)($context['auth']['sessionId'] ?? ''),
            'MUSIC_U=' . (string)($context['auth']['token'] ?? ''),
            'NMTID=' . (string)($context['device']['ti'] ?? ''),
            'WEVNSM=' . (string)($context['app']['nsm'] ?? '1.0.0'),
            'WNMCID=' . (string)($context['app']['cid'] ?? ''),
            '__csrf=' . (string)($context['device']['csrf'] ?? ''),
            '__remember_me=true',
            '_iuqxldmzr_=33',
            '_ntes_nnid=' . (string)($context['device']['nnid'] ?? ','),
            '_ntes_nuid=' . (string)($context['device']['nuid'] ?? ''),
            'appver=' . self::desktopAppVersion($context),
            'channel=' . (string)($context['app']['channel'] ?? 'netease'),
            'clientSign=' . (string)($context['device']['sign'] ?? ''),
            'deviceId=' . (string)($context['device']['id'] ?? ''),
            'mode=' . (string)($context['device']['model'] ?? ''),
            'ntes_kaola_ad=1',
            'os=' . (string)($context['device']['systemType'] ?? 'pc'),
            'osver=' . (string)($context['device']['systemVersion'] ?? ''),
        ];
        return implode('; ', $parts);
    }

    /**
     * @return array{boundary:string,fileName:string,body:string}
     */
    public static function buildMultipart(
        string $payload,
        ?string $boundary = null,
        ?string $fileName = null
    ): array {
        $boundary ??= bin2hex(random_bytes(16));
        $fileName ??= 'op_' . random_int(10000, 99999) . '_0_' . random_int(1, 4294967295);
        if ($boundary === '' || preg_match('/[\r\n]/', $boundary)) {
            throw new RuntimeException('NCBL multipart boundary is invalid');
        }
        if ($fileName === '' || preg_match('/["\r\n]/', $fileName)) {
            throw new RuntimeException('NCBL multipart file name is invalid');
        }

        $crlf = "\r\n";
        $header = implode($crlf, [
            '--' . $boundary,
            'Content-Disposition: form-data; name="file"; filename="' . $fileName . '"',
            'Content-Type: multipart/form-data',
            '',
            '',
        ]);
        $footer = $crlf . '--' . $boundary . '--' . $crlf;
        return [
            'boundary' => $boundary,
            'fileName' => $fileName,
            'body' => $header . $payload . $footer,
        ];
    }

    /** @param array<string,mixed> $context */
    public static function uploadHeaders(array $context, string $boundary): array
    {
        $version = (string)($context['app']['version'] ?? '3.1.35');
        return [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Referer' => 'https://music.163.com/di',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Safari/537.36 Chrome/91.0.4472.164 NeteaseMusicDesktop/' . $version,
            'Accept-Encoding' => 'gzip,deflate',
            'Accept-Language' => 'zh-CN,zh;q=0.8',
            'Cookie' => self::buildCookie($context),
        ];
    }

    public static function uploadAccepted(array $response, string $fileName): bool
    {
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded) || (int)($decoded['code'] ?? 0) !== 200) {
            return false;
        }
        $successFiles = $decoded['data']['successfiles'] ?? null;
        return is_array($successFiles) && in_array($fileName, $successFiles, true);
    }

    private static function chachaBlock(string $key, int $counter, string $nonce): string
    {
        $state = self::SIGMA;
        $keyWords = array_values(unpack('V8', $key));
        foreach ($keyWords as $word) {
            $state[] = (int)$word;
        }
        $state[] = $counter & 0xffffffff;
        foreach (array_values(unpack('V3', $nonce)) as $word) {
            $state[] = (int)$word;
        }

        $work = $state;
        for ($round = 0; $round < 10; $round++) {
            self::quarterRound($work, 0, 4, 8, 12);
            self::quarterRound($work, 1, 5, 9, 13);
            self::quarterRound($work, 2, 6, 10, 14);
            self::quarterRound($work, 3, 7, 11, 15);
            self::quarterRound($work, 0, 5, 10, 15);
            self::quarterRound($work, 1, 6, 11, 12);
            self::quarterRound($work, 2, 7, 8, 13);
            self::quarterRound($work, 3, 4, 9, 14);
        }

        $output = '';
        for ($index = 0; $index < 16; $index++) {
            $output .= pack('V', ($work[$index] + $state[$index]) & 0xffffffff);
        }
        return $output;
    }

    /** @param array<int,int> $state */
    private static function quarterRound(array &$state, int $a, int $b, int $c, int $d): void
    {
        $state[$a] = ($state[$a] + $state[$b]) & 0xffffffff;
        $state[$d] = self::rotateLeft(($state[$d] ^ $state[$a]) & 0xffffffff, 16);
        $state[$c] = ($state[$c] + $state[$d]) & 0xffffffff;
        $state[$b] = self::rotateLeft(($state[$b] ^ $state[$c]) & 0xffffffff, 12);
        $state[$a] = ($state[$a] + $state[$b]) & 0xffffffff;
        $state[$d] = self::rotateLeft(($state[$d] ^ $state[$a]) & 0xffffffff, 8);
        $state[$c] = ($state[$c] + $state[$d]) & 0xffffffff;
        $state[$b] = self::rotateLeft(($state[$b] ^ $state[$c]) & 0xffffffff, 7);
    }

    private static function rotateLeft(int $value, int $bits): int
    {
        $value &= 0xffffffff;
        return (int)((($value << $bits) | ($value >> (32 - $bits))) & 0xffffffff);
    }

    private static function bytesToDecimal(string $bytes): string
    {
        $decimal = '0';
        foreach (unpack('C*', $bytes) as $byte) {
            $decimal = bcadd(bcmul($decimal, '256', 0), (string)$byte, 0);
        }
        return $decimal;
    }

    private static function decimalToBytes(string $decimal, int $length): string
    {
        $bytes = str_repeat("\0", $length);
        for ($index = $length - 1; $index >= 0; $index--) {
            $bytes[$index] = chr((int)bcmod($decimal, '256'));
            $decimal = bcdiv($decimal, '256', 0);
        }
        if (bccomp($decimal, '0', 0) !== 0) {
            throw new RuntimeException('NCBL RSA result exceeds the requested width');
        }
        return $bytes;
    }

    private static function rsaModulusDecimal(): string
    {
        if (self::$rsaModulusDecimal === null) {
            $modulus = hex2bin(self::RSA_MODULUS_HEX);
            if (!is_string($modulus)) {
                throw new RuntimeException('NCBL RSA modulus is invalid');
            }
            self::$rsaModulusDecimal = self::bytesToDecimal($modulus);
        }
        return self::$rsaModulusDecimal;
    }

    /** @param array<string,mixed> $context */
    private static function desktopAppVersion(array $context): string
    {
        return (string)($context['app']['version'] ?? '3.1.35') . '.'
            . (string)($context['app']['versionCode'] ?? '205293');
    }

    /** @param mixed $value */
    private static function jsonEncode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('NCBL JSON encoding failed');
        }
        return $json;
    }
}
