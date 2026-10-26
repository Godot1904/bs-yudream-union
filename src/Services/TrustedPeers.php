<?php

namespace SITMC\YudreamUnion\Services;

use Illuminate\Support\Facades\Http;

/**
 * 受信站目录(A 侧消费):option('yu_trusted_peers') JSON 数组。
 *
 * M2 阶段由 `php artisan yudream-union:peer-add {origin}` 从对端发现文档
 * TOFU 读取并落库;M3 演进为 enrollToken 门禁(协议 §3.5)。
 *
 * 每项:{code,name,origin,apiRoot,textureBaseUrl,publicKeyPem,backupPem,addedAt}
 * 协议 §3.2:A 侧查询受信站上限 8 站、2s 超时(查询侧截断,存储不设限)。
 */
final class TrustedPeers
{
    public const OPT = 'yu_trusted_peers';
    private const MAX_QUERY = 8;

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $raw = json_decode((string) option(self::OPT, '[]'), true);
        return is_array($raw) ? array_values($raw) : [];
    }

    /** 断言查询用的前 8 站(协议 §3.2)。 */
    public static function queryable(): array
    {
        return array_slice(self::all(), 0, self::MAX_QUERY);
    }

    public static function find(string $code): ?array
    {
        foreach (self::all() as $peer) {
            if (($peer['code'] ?? '') === $code) {
                return $peer;
            }
        }
        return null;
    }

    public static function upsert(array $peer): void
    {
        // 钉扎对端材质签名钥(L1 publickeys 信任集来源,对齐 YDA 0.5.0;失败置空可重试)
        $peer['authlibPublicKeyPem'] = self::fetchAuthlibKeyPem($peer);

        $peers = self::all();
        $found = false;
        foreach ($peers as $i => $existing) {
            if (($existing['code'] ?? '') === $peer['code']) {
                $peers[$i] = $peer;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $peers[] = $peer;
        }
        option([self::OPT => json_encode($peers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    public static function remove(string $code): bool
    {
        $peers = self::all();
        $kept = array_values(array_filter($peers, static fn ($p) => ($p['code'] ?? '') !== $code));
        if (count($kept) === count($peers)) {
            return false;
        }
        option([self::OPT => json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        return true;
    }

    /**
     * 钉扎对端材质签名钥(对齐 YDA 0.5.0 语义):经与 enroll 相同的通道抓取对端
     * authlib 元数据 signaturePublickey。探测顺序:YD 别名路径(对端为 YDA 栈或
     * 装有本适配插件)→ 对端声明的 authlib.apiRoot → 本站同款 Yggdrasil 路径。
     * 失败返回 null(不阻塞注册,可稍后重试/刷新)。
     */
    public static function fetchAuthlibKeyPem(array $peer): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            rtrim((string) $peer['origin'], '/') . '/api/plugins/authlib-injector/',
            rtrim((string) ($peer['apiRoot'] ?? ''), '/'),
            rtrim((string) $peer['origin'], '/') . '/api/yggdrasil',
        ])));
        foreach ($candidates as $url) {
            try {
                $response = Http::timeout(4)->connectTimeout(3)->get($url);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$response->successful()) {
                continue;
            }
            $pem = (string) ($response->json()['signaturePublickey'] ?? '');
            if ($pem !== '' && openssl_pkey_get_public($pem)) {
                return $pem;
            }
        }
        return null;
    }

    /**
     * 从对端发现文档(协议 §3.1)构造受信站条目;不满足协议约束返回 [null, 原因]。
     *
     * 约束:unionVersion=1;siteCode 非空;origin 为 https;
     * textureBaseUrl 的 host 必须与 origin 同源(协议 §3.1);
     * signaturePublickey 为可用 RSA PEM。
     *
     * @return array{0: null|array, 1: ?string}
     */
    public static function fromMeta(string $origin, array $meta): array
    {
        if (($meta['unionVersion'] ?? '') !== '1') {
            return [null, 'unionVersion != 1'];
        }
        $site = $meta['site'] ?? [];
        $code = strtolower(trim((string) ($site['code'] ?? '')));
        if (!preg_match('/^[a-z0-9_-]{2,32}$/', $code)) {
            return [null, 'site.code missing or invalid'];
        }
        $metaOrigin = trim((string) ($site['origin'] ?? $origin));
        foreach ([$origin, $metaOrigin] as $url) {
            if (!str_starts_with($url, 'https://')) {
                return [null, "origin must be https: {$url}"];
            }
        }
        $apiRoot = (string) ($meta['endpoints']['apiRoot'] ?? '');
        if (!str_starts_with($apiRoot, 'https://')) {
            return [null, 'endpoints.apiRoot missing or not https'];
        }
        $textureBaseUrl = rtrim((string) ($meta['textureBaseUrl'] ?? ''), '/');
        if ($textureBaseUrl === '') {
            return [null, 'textureBaseUrl missing'];
        }
        if (parse_url($textureBaseUrl, PHP_URL_HOST) !== parse_url($metaOrigin, PHP_URL_HOST)) {
            return [null, 'textureBaseUrl host must be same-origin with site.origin (protocol §3.1)'];
        }
        $pem = (string) ($meta['signaturePublickey'] ?? '');
        if (!openssl_pkey_get_public($pem)) {
            return [null, 'signaturePublickey is not a valid PEM public key'];
        }
        $backup = trim((string) ($meta['backupSignaturePublickey'] ?? ''));
        if ($backup !== '' && !openssl_pkey_get_public($backup)) {
            $backup = '';
        }
        // §3.1.1 联合名前缀:非法格式忽略(按未启用处理)
        $namePrefix = strtolower(trim((string) ($site['namePrefix'] ?? '')));
        if (!preg_match('/^[a-z0-9_]{1,4}$/', $namePrefix)) {
            $namePrefix = '';
        }

        return [[
            'code' => $code,
            'name' => (string) ($site['name'] ?? $code),
            'origin' => $metaOrigin,
            'apiRoot' => $apiRoot,
            'textureBaseUrl' => $textureBaseUrl,
            'publicKeyPem' => $pem,
            'backupPem' => $backup !== '' ? $backup : null,
            'namePrefix' => $namePrefix,
            'tlsFingerprint' => TlsFingerprint::of($metaOrigin),
            'addedAt' => now()->toIso8601String(),
        ], null];
    }
}
