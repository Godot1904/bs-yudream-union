<?php

namespace SITMC\YudreamUnion\Services;

/**
 * 联合 join 断言(协议 §3.2)。
 *
 * canonical JSON 铁律:
 *   只含九个字段,固定顺序 profileId, profileName, serverId, aud, issuerKeyId,
 *   jti, issuedAt, expiresAt, textures;紧凑 UTF-8;issuedAt/expiresAt 必须为数字。
 *
 * 字符串值逐个 json_encode(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
 * 与 YDA 侧 Java 实现(Jackson 默认不转义 / 与非 ASCII)对齐;
 * **联调时必须与 YDA 侧 canonicalAssertion 逐字节比对**(见 README §联调)。
 */
final class AssertionService
{
    private const FIELDS = [
        'profileId', 'profileName', 'serverId', 'aud',
        'issuerKeyId', 'jti', 'issuedAt', 'expiresAt', 'textures',
    ];

    public function canonical(array $assertion): string
    {
        $parts = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $assertion)) {
                throw new \InvalidArgumentException("assertion field missing: {$field}");
            }
            $value = $assertion[$field];
            $encoded = is_int($value)
                ? (string) $value
                : json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $parts[] = json_encode($field) . ':' . $encoded;
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * @return array{assertion: array, signature: string}
     */
    public function issue(
        string $profileId,
        string $profileName,
        string $serverId,
        string $aud,
        string $textures,
        int $ttlMs = 60_000
    ): array {
        $issuedAt = (int) (microtime(true) * 1000);
        $assertion = [
            'profileId' => strtolower(str_replace('-', '', $profileId)),
            'profileName' => $profileName,
            'serverId' => $serverId,
            'aud' => $aud,
            'issuerKeyId' => UnionKeyService::issuerKeyId(),
            'jti' => bin2hex(random_bytes(16)),
            'issuedAt' => $issuedAt,
            'expiresAt' => $issuedAt + $ttlMs,
            'textures' => $textures,
        ];

        $privatePem = UnionKeyService::privateKeyPem();
        if ($privatePem === null) {
            throw new \RuntimeException('union key not initialized');
        }
        $ok = openssl_sign($this->canonical($assertion), $signature, $privatePem, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('openssl_sign failed');
        }

        return ['assertion' => $assertion, 'signature' => base64_encode($signature)];
    }

    /**
     * A 侧验收(协议 §3.2 验签规则):canonical 重建 + SHA256withRSA,
     * issuerKeyId 绑定实际验签成功的公钥,aud/时间窗/形状/jti 全套校验。
     *
     * @param array $assertion 对端响应的 assertion 对象(json_decode 产物)
     * @param string $signatureB64 对端响应的 signature
     * @param array $peer TrustedPeers 条目(publicKeyPem/backupPem/textureBaseUrl)
     * @param string $selfSiteCode 本站 siteCode(aud 受众)
     * @return array{0: bool, 1: ?string} [是否通过, 失败原因]
     */
    public function verify(array $assertion, string $signatureB64, array $peer, string $selfSiteCode): array
    {
        $fail = static fn (string $r): array => [false, $r];

        $fields = ['profileId', 'profileName', 'serverId', 'aud', 'issuerKeyId',
            'jti', 'issuedAt', 'expiresAt', 'textures'];
        foreach ($fields as $f) {
            if (!isset($assertion[$f])) {
                return $fail("missing field: {$f}");
            }
        }

        // 时间戳强制数字形态(规范串与语义都要求)
        $issuedAt = (int) $assertion['issuedAt'];
        $expiresAt = (int) $assertion['expiresAt'];
        $now = (int) (microtime(true) * 1000);
        if ($issuedAt > $now + 5_000) {
            return $fail('issuedAt is in the future beyond 5s clock skew');
        }
        if ($expiresAt <= $now) {
            return $fail('assertion expired');
        }
        if ($expiresAt - $issuedAt > 65_000) {
            return $fail('assertion ttl exceeds 65s');
        }
        if (!preg_match('/^[0-9a-f]{32}$/', strtolower((string) $assertion['profileId']))) {
            return $fail('profileId must be 32 hex');
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', (string) $assertion['profileName'])) {
            return $fail('profileName must be 3-16 [A-Za-z0-9_]');
        }
        if (!preg_match('/^[0-9a-f]{32}$/', strtolower((string) $assertion['jti']))) {
            return $fail('jti must be 32 hex');
        }
        if ((string) $assertion['aud'] !== $selfSiteCode) {
            return $fail('aud mismatch');
        }

        // jti 去重(60 秒内重复即丢弃;60 秒前的旧键自然失效)
        $jtiKey = 'yu-jti-' . strtolower((string) $assertion['jti']);
        if (\Illuminate\Support\Facades\Cache::get($jtiKey)) {
            return $fail('jti replayed');
        }

        // 验签:主钥优先,备钥宽限(协议 §3.1 轮换语义);失败即拒
        $canonical = $this->canonical(array_merge($assertion, [
            'issuedAt' => $issuedAt,
            'expiresAt' => $expiresAt,
        ]));
        $sig = base64_decode((string) $signatureB64, true);
        if ($sig === false || $sig === '') {
            return $fail('signature is not valid base64');
        }
        $verifiedPem = null;
        foreach (array_filter([$peer['publicKeyPem'] ?? null, $peer['backupPem'] ?? null]) as $pem) {
            if (openssl_verify($canonical, $sig, $pem, OPENSSL_ALGO_SHA256) === 1) {
                $verifiedPem = $pem;
                break;
            }
        }
        if ($verifiedPem === null) {
            return $fail('signature verification failed');
        }
        // issuerKeyId 必须等于实际验签成功那把公钥的 SHA-256 前 16 hex
        $expectedKeyId = substr(hash('sha256', UnionKeyService::pemToDer($verifiedPem)), 0, 32);
        if (strtolower((string) $assertion['issuerKeyId']) !== $expectedKeyId) {
            return $fail('issuerKeyId does not match the verifying key');
        }

        // textures 形状:base64(JSON) 且 SKIN/CAPE URL 只能用对端 textureBaseUrl 的 https host
        $decoded = json_decode((string) base64_decode((string) $assertion['textures'], true), true);
        if (!is_array($decoded)) {
            return $fail('textures is not base64(JSON)');
        }
        $allowedHost = parse_url((string) ($peer['textureBaseUrl'] ?? ''), PHP_URL_HOST);
        foreach (['SKIN', 'CAPE'] as $type) {
            $url = $decoded['textures'][$type]['url'] ?? null;
            if ($url === null) {
                continue;
            }
            $parts = parse_url((string) $url);
            if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $allowedHost) {
                return $fail("textures {$type} url host not allowed: {$url}");
            }
        }

        \Illuminate\Support\Facades\Cache::put($jtiKey, true, 60);
        return [true, null];
    }
}
