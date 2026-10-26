<?php

namespace SITMC\YudreamUnion\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A 侧联邦验证(协议 §3.2 消费方):本地 hasJoined 未命中时,
 * 并发向受信站(≤8 站、2s 超时)查询签名断言并验收;命中则把材质 URL
 * 改写为本站代理、用**本站 Yggdrasil 材质钥重签**后返回 hasJoined 形状。
 *
 * 由 yggdrasil-connect 的 hasJoined 未命中分支(patch 第 3 处)委托调用;
 * 本类返回 null = 保持 204(联邦层降级,不阻塞本站语义)。
 */
final class FederationService
{
    public static function handleMiss(string $username, string $serverId): ?array
    {
        try {
            $result = (new self())->federatedHasJoined($username, $serverId);
            Log::info('[yudream-union] federated result', [
                'username' => $username, 'serverId' => $serverId,
                'outcome' => $result !== null ? 'accepted' : 'no-assertion',
            ]);
            return $result;
        } catch (\Throwable $e) {
            Log::error('[yudream-union] handleMiss exception', ['err' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            return null;
        }
    }

    public function federatedHasJoined(string $username, string $serverId): ?array
    {
        $siteCode = UnionKeyService::siteCode();
        if ($siteCode === '') {
            Log::info('[yudream-union] skip: siteCode empty');
            return null; // 未初始化:联邦层关闭
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $username) || $serverId === '') {
            Log::info('[yudream-union] skip: bad username/serverId');
            return null;
        }
        // 联合封禁判定(本站 ∪ 各直接受信站 ACTIVE 并集,subjectSite 归属过滤)
        if (UnionBanService::isNameBanned($username)) {
            AuditService::log('join_reject', ['reason' => 'name-banned', 'username' => $username]);
            return null;
        }

        $peers = TrustedPeers::queryable();
        if ($peers === []) {
            Log::info('[yudream-union] skip: no trusted peers');
            return null;
        }

        // —— 并发查询(协议 §3.2:≤8 站、2s 超时)——
        $responses = Http::pool(function ($pool) use ($peers, $username, $serverId, $siteCode) {
            foreach ($peers as $i => $peer) {
                $pool->as($i)->timeout(2)->connectTimeout(2)
                    ->get(rtrim($peer['apiRoot'], '/') . '/peer/joins/query', [
                        'username' => $username,
                        'serverId' => $serverId,
                        'aud' => $siteCode,
                    ]);
            }
        });

        $assertionService = new AssertionService();
        $hits = [];
        foreach ($peers as $i => $peer) {
            $response = $responses[$i] ?? null;
            if ($response === null || $response->status() !== 200) {
                Log::info('[yudream-union] peer no-assertion', [
                    'peer' => $peer['code'],
                    'status' => $response !== null ? $response->status() : 'exception/timeout',
                ]);
                continue; // 204/网络失败/限流:该站不参与
            }
            $body = $response->json();
            $assertion = is_array($body) ? ($body['assertion'] ?? null) : null;
            $signature = is_array($body) ? (string) ($body['signature'] ?? '') : '';
            if (!is_array($assertion) || $signature === '') {
                continue;
            }
            // 查询维度一致性:serverId 与角色名(不区分大小写)必须与本次查询匹配
            if ((string) ($assertion['serverId'] ?? '') !== $serverId) {
                continue;
            }
            if (UnionKeyService::normalizeName((string) ($assertion['profileName'] ?? ''))
                !== UnionKeyService::normalizeName($username)) {
                continue;
            }
            [$ok, $reason] = $assertionService->verify($assertion, $signature, $peer, $siteCode);
            if ($ok) {
                $hits[] = [$peer, $assertion];
            } elseif ($reason !== null) {
                Log::info('[yudream-union] assertion rejected', ['peer' => $peer['code'], 'reason' => $reason]);
            }
        }

        // 多站同时命中 = 全拒并告警(协议 §3.2 防跨站撞名身份混淆)
        if (count($hits) > 1) {
            AuditService::log('conflict', [
                'username' => $username,
                'peers' => array_map(static fn ($h) => $h[0]['code'], $hits),
            ]);
            Log::warning('[yudream-union] multi-peer conflict, dropping all', ['username' => $username]);
            return null;
        }
        if ($hits === []) {
            Log::info('[yudream-union] no peer produced a valid assertion');
            return null;
        }

        [$peer, $assertion] = $hits[0];
        $result = $this->buildHasJoinedResponse($peer, $assertion);
        AuditService::log($result !== null ? 'join_accept' : 'join_reject', [
            'peer' => $peer['code'],
            'username' => (string) $assertion['profileName'],
            'reason' => $result === null ? 'build-failed' : null,
        ]);
        return $result;
    }

    /**
     * 材质 URL 改写为本站代理 + 本站钥重签;不携带 uploadableTextures。
     */
    private function buildHasJoinedResponse(array $peer, array $assertion): ?array
    {
        $value = (string) $assertion['textures'];
        $decoded = json_decode((string) base64_decode($value, true), true);
        if (!is_array($decoded)) {
            Log::info('[yudream-union] build fail: textures not base64(JSON)', ['raw_head' => substr($value, 0, 60)]);
            return null;
        }
        $proxyBase = rtrim((string) option('site_url'), '/')
            . '/api/plugins/yudream-union/texture/union';
        foreach (['SKIN', 'CAPE'] as $type) {
            if (isset($decoded['textures'][$type]['url'])) {
                $path = (string) parse_url((string) $decoded['textures'][$type]['url'], PHP_URL_PATH);
                $hash = basename($path);
                if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $hash)) {
                    $decoded['textures'][$type]['url'] = "{$proxyBase}/{$hash}";
                } else {
                    unset($decoded['textures'][$type]);
                }
            }
        }
        // 与 yggdrasil-connect 的材质 value 编码风格保持一致
        $newValue = base64_encode(json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT));

        $privateKey = openssl_pkey_get_private((string) option('ygg_private_key', ''));
        if (!$privateKey) {
            Log::info('[yudream-union] build fail: ygg_private_key unusable');
            return null; // 本站材质钥不可用:不降级下发未签名内容
        }
        // 材质属性签名 = authlib 规范的 SHA1withRSA(yggdrasil-connect 本地签名同为
        // openssl_sign 默认算法,Profile.php:23);用 SHA256 会被客户端验签拒绝
        $ok = openssl_sign($newValue, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        if (!$ok) {
            Log::info('[yudream-union] build fail: openssl_sign SHA1 failed');
            return null;
        }

        // §3.1.1 联合名前缀:改写只发生在验收方出口;超 16 字符拒绝本次联合登录
        $displayName = NamePrefixService::rewrite($peer, (string) $assertion['profileName']);
        if ($displayName === null) {
            AuditService::log('join_reject', [
                'peer' => $peer['code'],
                'username' => (string) $assertion['profileName'],
                'reason' => 'prefixed-name-exceeds-16',
            ]);
            return null;
        }

        return [
            'id' => strtolower((string) $assertion['profileId']),
            'name' => $displayName,
            'properties' => [[
                'name' => 'textures',
                'value' => $newValue,
                'signature' => base64_encode($signature),
            ]],
        ];
    }
}
