<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use netease\sdk\Client;
use netease\sdk\Ncbl;
use netease\sdk\TransportInterface;

function ncblCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$key = implode('', array_map('chr', range(0, 31)));
$nonce = hex2bin('000000090000004a00000000');
ncblCheck(is_string($nonce), 'NCBL nonce fixture is invalid');
$chacha = Ncbl::chacha20($key, 1, $nonce, str_repeat("\0", 64));
ncblCheck(
    bin2hex($chacha) === '10f1e7e4d13b5915500fdd1fa32071c4c7d1f4c733c068030422aa9ac3d46c4e'
        . 'd2826446079faa0914c2d705d98b02a2b5129cd1de164eb9cbd083e8a2503c4e',
    'NCBL ChaCha20 output does not match the RFC/upstream vector'
);

ncblCheck(
    bin2hex(Ncbl::rsaWrap($key)) === 'dda20437ce173c34273cb03bffb85db8f3ce53bc2f1334a752303d26890094af',
    'NCBL RSA key wrapping does not match the upstream vector'
);

$uuid = hex2bin('00112233445566778899aabbccddeeff');
ncblCheck(is_string($uuid), 'NCBL UUID fixture is invalid');
$meta = '{"MUSIC_U":"token","os":"pc"}';
$recordBody = "1700000000\x01_plv\x01{\"id\":\"347230\",\"time\":180}";
$payload = Ncbl::encrypt($meta, $recordBody, [
    'keyA' => $key,
    'uuid' => $uuid,
    'baseSeq' => 0x1234,
    'maxFrame' => 0x8000,
]);
ncblCheck(strlen($payload) === 165, 'NCBL deterministic payload has the wrong length');
ncblCheck(
    hash('sha256', $payload) === '96ee9207e0e75b395bbb60a41eadc329b54f6999feb2ac4dcd65eff99cd39aed',
    'NCBL binary output does not match api-enhanced/util/ncbl.js'
);
ncblCheck(substr($payload, 0, 4) === Ncbl::MAGIC, 'NCBL magic header is missing');
ncblCheck(unpack('Vvalue', substr($payload, 4, 4))['value'] === Ncbl::VERSION, 'NCBL version is wrong');
ncblCheck(unpack('vvalue', substr($payload, 8, 2))['value'] === 103, 'NCBL header length is wrong');
ncblCheck(substr($payload, 10, 16) === $uuid, 'NCBL UUID was not preserved');
ncblCheck(unpack('Vvalue', substr($payload, 58, 4))['value'] === 0x1234, 'NCBL first sequence is wrong');
ncblCheck(unpack('Vvalue', substr($payload, 62, 4))['value'] === 0x1234, 'NCBL last sequence is wrong');

$context = [
    'app' => [
        'nsm' => '1.0.0',
        'cid' => 'abcdef.1700000000000.01.0',
        'channel' => 'netease',
        'version' => '3.1.35',
        'versionCode' => '205293',
    ],
    'device' => [
        'id' => 'DEVICE',
        'ti' => 'nmtid',
        'sign' => '',
        'model' => '',
        'nnid' => 'nnid,1700000000000',
        'nuid' => 'nuid',
        'csrf' => 'csrf',
        'systemType' => 'pc',
        'systemVersion' => 'Microsoft-Windows-10-Professional-build-19045-64bit',
    ],
    'auth' => [
        'id' => '1',
        'token' => 'music-u',
        'sessionId' => 'session',
        'vipType' => '',
    ],
];
$song = ['id' => 347230, 'bitrate' => 320, 'level' => 'exhigh', 'time' => 180];
$source = ['id' => '3778678', 'type' => 'track', 'name' => 'list'];
$plv = Ncbl::buildPlv($context, $song, $source, 1700000000123);
$pld = Ncbl::buildPld($context, $song, $source, 180, 1700000000123);
ncblCheck($plv['id'] === '347230' && $plv['app_mode'] === 2, 'NCBL PLV schema is incomplete');
ncblCheck($plv['sourceId'] === '3778678' && $plv['fee'] === 1, 'NCBL PLV source fields are wrong');
ncblCheck(str_contains($plv['_addrefer'], '#933#3.1.35#205293#'), 'NCBL PLV refer chain is wrong');
ncblCheck($pld['time'] === 180 && $pld['realtime'] === 180, 'NCBL PLD duration fields are wrong');
ncblCheck($pld['app_mode'] === 1 && $pld['end'] === 'interrupt', 'NCBL PLD completion fields are wrong');
ncblCheck(str_contains($pld['_addrefer'], '#616#3.1.35#205293#'), 'NCBL PLD refer chain is wrong');
$schemaJson = json_encode(['plv' => $plv, 'pld' => $pld], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ncblCheck(
    is_string($schemaJson)
        && hash('sha256', $schemaJson) === '1ad968e604876b86ca6ea11197dc65626e36be67679ed077a2f6d981fd78079b',
    'NCBL PLV/PLD JSON does not match the upstream Node schema'
);
$records = Ncbl::buildRecords([
    ['time' => 1700000000, 'action' => '_plv', 'data' => ['id' => '1']],
    ['time' => 1700000001, 'action' => '_pld', 'data' => ['id' => '1']],
]);
ncblCheck(substr_count($records, Ncbl::FIELD_SEPARATOR) === 4, 'NCBL records use the wrong field separator');

$metaDecoded = json_decode(Ncbl::buildMetaJson($context), true);
ncblCheck(($metaDecoded['MUSIC_U'] ?? '') === 'music-u', 'NCBL metadata lost MUSIC_U');
ncblCheck(($metaDecoded['appver'] ?? '') === '3.1.35.205293', 'NCBL metadata has the wrong desktop version');
$cookie = Ncbl::buildCookie($context);
ncblCheck(str_contains($cookie, 'MUSIC_U=music-u'), 'NCBL cookie lost MUSIC_U');
ncblCheck(str_contains($cookie, 'os=pc'), 'NCBL cookie is not using the PC profile');

$multipart = Ncbl::buildMultipart('PAYLOAD', '0123456789abcdef', 'op_12345_0_123');
ncblCheck($multipart['boundary'] === '0123456789abcdef', 'NCBL multipart boundary changed');
ncblCheck($multipart['fileName'] === 'op_12345_0_123', 'NCBL multipart file name changed');
ncblCheck(
    str_starts_with($multipart['body'], "--0123456789abcdef\r\nContent-Disposition: form-data; name=\"file\"; filename=\"op_12345_0_123\""),
    'NCBL multipart header is malformed'
);
ncblCheck(str_contains($multipart['body'], "\r\n\r\nPAYLOAD\r\n--0123456789abcdef--\r\n"), 'NCBL multipart body is malformed');

$acceptedResponse = ['body' => '{"code":200,"data":{"successfiles":["op_12345_0_123"]}}'];
$silentDropResponse = ['body' => '{"code":200,"data":{"successfiles":[]}}'];
ncblCheck(Ncbl::uploadAccepted($acceptedResponse, 'op_12345_0_123'), 'NCBL accepted upload was rejected');
ncblCheck(!Ncbl::uploadAccepted($silentDropResponse, 'op_12345_0_123'), 'HTTP 200 without successfiles was treated as success');

final class NcblContextTransport implements TransportInterface
{
    public function request(string $method, string $url, array $options = []): array
    {
        return ['status' => 200, 'headers' => [], 'body' => '{}', 'header' => '', 'set_cookie' => []];
    }
}

$client = new Client([
    'user_id' => '7',
    'cookie' => 'MUSIC_U=token+with+plus; __csrf=csrf; NMTID=fixed-nmtid; WNMCID=fixed.1.01.0',
], [
    'auto_anonymous_token' => false,
    'cache_dir' => '',
    'device_id' => 'FIXEDDEVICE',
    'nuid' => 'fixed-nuid',
    'nnid' => 'fixed-nnid,1',
], new NcblContextTransport());
$firstContext = $client->desktopLogContext();
$secondContext = $client->desktopLogContext();
ncblCheck($firstContext['auth']['token'] === 'token+with+plus', 'Desktop context URL-decoded MUSIC_U');
ncblCheck($firstContext['device']['ti'] === 'fixed-nmtid', 'Desktop context did not preserve NMTID');
ncblCheck($firstContext['app']['cid'] === 'fixed.1.01.0', 'Desktop context did not preserve WNMCID');
ncblCheck($firstContext['device']['id'] === 'FIXEDDEVICE', 'Desktop context did not preserve deviceId');
ncblCheck($firstContext['device']['ti'] === $secondContext['device']['ti'], 'Desktop context NMTID is not stable');

echo "Netease NCBL protocol tests passed\n";
