<?php

declare(strict_types=1);

namespace XArrPay\Support;

use RuntimeException;

final class WebAuthn
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException('WebAuthn 数据编码无效');
        }
        return $decoded;
    }

    /** @return array{type:string,challenge:string,origin:string} */
    public static function verifyClientData(string $clientDataJson, string $expectedChallenge, string $expectedOrigin, string $type): array
    {
        $decoded = json_decode($clientDataJson, true);
        if (!is_array($decoded)
            || (string) ($decoded['type'] ?? '') !== $type
            || (string) ($decoded['challenge'] ?? '') !== $expectedChallenge
            || (string) ($decoded['origin'] ?? '') !== $expectedOrigin
        ) {
            throw new RuntimeException('WebAuthn 客户端数据校验失败');
        }
        return [
            'type' => (string) $decoded['type'],
            'challenge' => (string) $decoded['challenge'],
            'origin' => (string) $decoded['origin'],
        ];
    }

    /** @return array{credential_id:string,public_key:string,sign_count:int,aaguid:string} */
    public static function registration(
        string $attestationObject,
        string $clientDataJson,
        string $challenge,
        string $origin,
    ): array {
        self::verifyClientData($clientDataJson, $challenge, $origin, 'webauthn.create');
        $attestation = self::decodeCbor($attestationObject);
        if (!is_array($attestation) || !is_string($attestation['authData'] ?? null)) {
            throw new RuntimeException('WebAuthn 注册数据格式无效');
        }
        $authData = $attestation['authData'];
        if (strlen($authData) < 55) {
            throw new RuntimeException('WebAuthn 注册认证器数据过短');
        }
        $flags = ord($authData[32]);
        if (($flags & 0x40) === 0) {
            throw new RuntimeException('认证器未返回凭据数据');
        }
        $signCount = self::uint32(substr($authData, 33, 4));
        $aaguid = substr($authData, 37, 16);
        $credentialLength = unpack('n', substr($authData, 53, 2))[1] ?? 0;
        $offset = 55;
        $credentialId = substr($authData, $offset, $credentialLength);
        $offset += $credentialLength;
        if ($credentialLength < 16 || strlen($credentialId) !== $credentialLength) {
            throw new RuntimeException('WebAuthn 凭据 ID 无效');
        }
        $cose = self::decodeCbor(substr($authData, $offset));
        $publicKey = self::coseToPem($cose);

        $fmt = (string) ($attestation['fmt'] ?? '');
        $attStmt = is_array($attestation['attStmt'] ?? null) ? $attestation['attStmt'] : [];
        if ($fmt === 'packed' && isset($attStmt['sig']) && is_string($attStmt['sig'])) {
            $alg = (int) ($attStmt['alg'] ?? -7);
            $algorithm = $alg === -7 ? OPENSSL_ALGO_SHA256 : null;
            if ($algorithm === null || openssl_verify($authData . hash('sha256', $clientDataJson, true), $attStmt['sig'], $publicKey, $algorithm) !== 1) {
                throw new RuntimeException('WebAuthn 注册签名校验失败');
            }
        } elseif ($fmt !== 'none' && $fmt !== 'packed') {
            throw new RuntimeException('暂不支持该 WebAuthn 证明格式');
        }

        return [
            'credential_id' => self::encode($credentialId),
            'public_key' => $publicKey,
            'sign_count' => $signCount,
            'aaguid' => self::encode($aaguid),
        ];
    }

    /** @return array{sign_count:int,flags:int} */
    public static function assertion(
        string $credentialId,
        string $authenticatorData,
        string $clientDataJson,
        string $signature,
        string $challenge,
        string $origin,
        string $publicKey,
        int $storedSignCount,
        string $rpId,
    ): array {
        self::verifyClientData($clientDataJson, $challenge, $origin, 'webauthn.get');
        if (strlen($authenticatorData) < 37) {
            throw new RuntimeException('WebAuthn 认证器数据过短');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($authenticatorData, 0, 32))) {
            throw new RuntimeException('WebAuthn 域名校验失败');
        }
        $flags = ord($authenticatorData[32]);
        if (($flags & 0x01) === 0) {
            throw new RuntimeException('WebAuthn 未完成用户存在性校验');
        }
        $signCount = self::uint32(substr($authenticatorData, 33, 4));
        if ($storedSignCount > 0 && $signCount > 0 && $signCount <= $storedSignCount) {
            throw new RuntimeException('WebAuthn 签名计数器异常');
        }
        $payload = $authenticatorData . hash('sha256', $clientDataJson, true);
        if (openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('WebAuthn 签名校验失败');
        }
        return ['sign_count' => $signCount, 'flags' => $flags];
    }

    /** @return mixed */
    public static function decodeCbor(string $data): mixed
    {
        $offset = 0;
        $value = self::readCbor($data, $offset);
        if ($offset !== strlen($data)) {
            throw new RuntimeException('WebAuthn CBOR 数据存在尾部内容');
        }
        return $value;
    }

    private static function uint32(string $value): int
    {
        $bytes = array_values(unpack('C*', $value) ?: []);
        if (count($bytes) !== 4) {
            throw new RuntimeException('WebAuthn 计数器无效');
        }
        return ($bytes[0] << 24) | ($bytes[1] << 16) | ($bytes[2] << 8) | $bytes[3];
    }

    private static function readCbor(string $data, int &$offset): mixed
    {
        if (!isset($data[$offset])) {
            throw new RuntimeException('WebAuthn CBOR 数据不完整');
        }
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $additional = $initial & 0x1f;
        if ($additional === 31) {
            throw new RuntimeException('WebAuthn 不支持不定长 CBOR');
        }
        $length = self::cborLength($data, $offset, $additional);
        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2 => self::readBytes($data, $offset, $length),
            3 => self::readBytes($data, $offset, $length),
            4 => self::readArray($data, $offset, $length),
            5 => self::readMap($data, $offset, $length),
            7 => self::readSimple($additional),
            default => throw new RuntimeException('WebAuthn CBOR 类型不支持'),
        };
    }

    private static function cborLength(string $data, int &$offset, int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }
        $bytes = $additional === 24 ? 1 : ($additional === 25 ? 2 : ($additional === 26 ? 4 : ($additional === 27 ? 8 : 0)));
        if ($bytes === 0 || $offset + $bytes > strlen($data)) {
            throw new RuntimeException('WebAuthn CBOR 长度无效');
        }
        $raw = substr($data, $offset, $bytes);
        $offset += $bytes;
        $number = 0;
        foreach (array_values(unpack('C*', $raw) ?: []) as $byte) {
            if ($number > PHP_INT_MAX >> 8) {
                throw new RuntimeException('WebAuthn CBOR 长度过大');
            }
            $number = ($number << 8) | $byte;
        }
        return $number;
    }

    private static function readBytes(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new RuntimeException('WebAuthn CBOR 字符串不完整');
        }
        $value = substr($data, $offset, $length);
        $offset += $length;
        return $value;
    }

    /** @return list<mixed> */
    private static function readArray(string $data, int &$offset, int $length): array
    {
        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = self::readCbor($data, $offset);
        }
        return $result;
    }

    /** @return array<int|string,mixed> */
    private static function readMap(string $data, int &$offset, int $length): array
    {
        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $key = self::readCbor($data, $offset);
            $result[is_int($key) || is_string($key) ? $key : (string) $key] = self::readCbor($data, $offset);
        }
        return $result;
    }

    private static function readSimple(int $additional): mixed
    {
        return match ($additional) {
            20 => false,
            21 => true,
            22, 23 => null,
            default => throw new RuntimeException('WebAuthn CBOR 简单值不支持'),
        };
    }

    private static function coseToPem(mixed $cose): string
    {
        if (!is_array($cose) || (int) ($cose[1] ?? 0) !== 2 || (int) ($cose[3] ?? 0) !== -7 || (int) ($cose[-1] ?? 0) !== 1) {
            throw new RuntimeException('仅支持 P-256 ES256 WebAuthn 凭据');
        }
        $x = $cose[-2] ?? null;
        $y = $cose[-3] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('WebAuthn 公钥坐标无效');
        }
        $point = "\x04" . $x . $y;
        $algorithm = self::derSequence(
            self::derSequence(
                self::derOid("\x2a\x86\x48\xce\x3d\x02\x01")
                . self::derOid("\x2a\x86\x48\xce\x3d\x03\x01\x07")
            )
            . self::derBitString($point)
        );
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($algorithm), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $value): string
    {
        return "\x30" . self::derLength(strlen($value)) . $value;
    }

    private static function derOid(string $value): string
    {
        return "\x06" . self::derLength(strlen($value)) . $value;
    }

    private static function derBitString(string $value): string
    {
        $value = "\x00" . $value;
        return "\x03" . self::derLength(strlen($value)) . $value;
    }
}
