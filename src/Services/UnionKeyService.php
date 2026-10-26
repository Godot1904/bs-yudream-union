<?php

namespace SITMC\YudreamUnion\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * 联合断言签名密钥(RSA-4096)。
 *
 * 与 Yggdrasil 材质签名密钥(option('ygg_private_key'))是**两把不同的钥匙**(协议 §3.1)。
 * 私钥经 Laravel Crypt 加密后存 option('yu_union_private_key'),不明文落库。
 */
final class UnionKeyService
{
    public const OPT_PRIVATE = 'yu_union_private_key';
    public const OPT_SITE_CODE = 'yu_site_code';
    public const OPT_SITE_NAME = 'yu_site_name';
    /** L1:经管理员批准的合作站材质签名公钥(Base64 DER,分号分隔),可选 */
    public const OPT_PEER_AUTHLIB_KEYS = 'yu_peer_authlib_keys';

    public static function generate(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $pem);
        option([self::OPT_PRIVATE => Crypt::encryptString($pem)]);
    }

    // —— 主/备双钥轮换(协议 §3.1:新钥先上 backup 观察期,验收方主钥失败试备钥)——

    public const OPT_BACKUP = 'yu_union_backup_private_key';

    /** 生成新的备钥(不影响现有备钥则覆盖)。 */
    public static function generateBackup(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $pem);
        option([self::OPT_BACKUP => Crypt::encryptString($pem)]);
    }

    /** 备钥晋升为主钥(旧主钥弃用;消费方由 7 天宽限语义过渡)。 */
    public static function promoteBackup(): bool
    {
        $encrypted = option(self::OPT_BACKUP);
        if (!is_string($encrypted) || $encrypted === '') {
            return false;
        }
        option([
            self::OPT_PRIVATE => $encrypted,
            self::OPT_BACKUP => '',
        ]);
        return true;
    }

    public static function backupPublicKeyPem(): ?string
    {
        $encrypted = option(self::OPT_BACKUP);
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }
        try {
            $pem = Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            return null;
        }
        $key = openssl_pkey_get_private($pem);
        return $key ? openssl_pkey_get_details($key)['key'] : null;
    }

    public static function privateKeyPem(): ?string
    {
        $encrypted = option(self::OPT_PRIVATE);
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }
        try {
            $pem = Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            return null;
        }
        return openssl_pkey_get_private($pem) ? $pem : null;
    }

    public static function publicKeyPem(): ?string
    {
        $pem = self::privateKeyPem();
        if ($pem === null) {
            return null;
        }
        $key = openssl_pkey_get_private($pem);
        return openssl_pkey_get_details($key)['key'];
    }

    /** issuerKeyId = 联合公钥 SHA-256 前 16 **字节** hex(=32 个十六进制字符,协议 §1)。 */
    public static function issuerKeyId(): ?string
    {
        $pub = self::publicKeyPem();
        if ($pub === null) {
            return null;
        }
        return substr(hash('sha256', self::pemToDer($pub)), 0, 32);
    }

    public static function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pem);
        return base64_decode($body, true) ?: '';
    }

    public static function siteCode(): string
    {
        return trim((string) option(self::OPT_SITE_CODE, ''));
    }

    public static function siteName(): string
    {
        return trim((string) option(self::OPT_SITE_NAME, option('site_name', 'Blessing Skin')));
    }

    /** 本地联合封禁名单(option 逗号分隔,规范化小写比较)。 */
    public static function normalizeName(string $name): string
    {
        return strtolower(trim(self::stripColorCodes($name)));
    }

    private static function stripColorCodes(string $name): string
    {
        return preg_replace('/§./u', '', $name) ?? $name;
    }
}
