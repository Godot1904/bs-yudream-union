<?php

namespace SITMC\YudreamUnion\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * B 侧 join 证据(协议 §3.2:最近 60 秒、username 不区分大小写 + serverId 精确匹配)。
 *
 * 三级证据源(与 yggdrasil-connect 零绑定,逐级降级):
 *
 *  1. 补丁 key `yggdrasil-union-join-{serverId}`(TTL 60s,带 joinedAt)——
 *     应用了 patch/yggdrasil-connect-optional.patch 后完全符合协议 60s 窗;
 *  2. `ygg_log` 表(action='join' 且 parameters 含 serverId,精确 time 列)——
 *     仅当站点开启 YGG_VERBOSE_LOG 时有数据;
 *  3. 原始 key `yggdrasil-server-{serverId}`(TTL 120s,无时间戳)——
 *     兜底可用,但证据窗口放宽到 120s(偏差见 README §合规说明)。
 *
 * 返回 textures 时经 yggdrasil-connect 的 Profile::serialize(false) 取
 * 与本站 hasJoined 完全一致的材质属性 value 原文;该插件被禁用时返回 null。
 */
final class JoinEvidenceService
{
    public function find(string $username, string $serverId): ?array
    {
        $username = UnionKeyService::normalizeName($username);
        if ($username === '' || $serverId === '') {
            return null;
        }

        // —— 级别 1:带时间戳的补丁 key ——
        $union = Cache::get("yggdrasil-union-join-{$serverId}");
        if (is_array($union) && isset($union['profile'], $union['joinedAt'])) {
            $age = (int) (microtime(true) * 1000) - (int) $union['joinedAt'];
            if ($age >= 0 && $age <= 60_000) {
                return $this->matchProfile($union['profile'], $username);
            }
            return null; // 补丁 key 存在但过窗:不再放宽到原始 key
        }

        // —— 级别 2:ygg_log(开启 YGG_VERBOSE_LOG 时;yggdrasil-connect 写入列为 time)——
        if (DB::getSchemaBuilder()->hasTable('ygg_log')) {
            try {
                $since = now()->subSeconds(60);
                $hit = DB::table('ygg_log')
                    ->where('action', 'join')
                    ->where('time', '>=', $since)
                    ->orderByDesc('id')
                    ->get(['parameters', 'time'])
                    ->first(static function ($row) use ($serverId) {
                        $params = json_decode((string) $row->parameters, true);
                        return is_array($params) && ($params['serverId'] ?? '') === $serverId;
                    });
                if ($hit !== null) {
                    $params = json_decode((string) $hit->parameters, true);
                    return $this->matchProfile((string) ($params['selectedProfile'] ?? ''), $username);
                }
                // ygg_log 可用但没有命中:信任它(说明 60s 内确实没有 join)
                if (self::yggLogActive()) {
                    return null;
                }
            } catch (\Throwable $e) {
                // 表结构差异等异常:降级到级别 3,不让证据查询打挂断言端点
                report($e);
            }
        }

        // —— 级别 3:原始 cache key(120s 宽窗兜底) ——
        $session = Cache::get("yggdrasil-server-{$serverId}");
        if (is_array($session) && isset($session['profile'])) {
            return $this->matchProfile((string) $session['profile'], $username);
        }

        return null;
    }

    private static function yggLogActive(): bool
    {
        // 与 yggdrasil-connect 的 ygg_log() 写入条件一致
        return (bool) env('YGG_VERBOSE_LOG');
    }

    /**
     * @return array{profileId:string, profileName:string, textures:string}|null
     */
    private function matchProfile(string $profileUuid, string $normalizedUsername): ?array
    {
        $profileUuid = str_replace('-', '', trim($profileUuid));
        if (!preg_match('/^[0-9a-f]{32}$/i', $profileUuid)) {
            return null;
        }
        if (!class_exists(\LittleSkin\YggdrasilConnect\Models\Profile::class)) {
            return null; // yggdrasil-connect 未启用:协议降级,视为未命中
        }

        $profile = \LittleSkin\YggdrasilConnect\Models\Profile::createFromUuid($profileUuid);
        if ($profile === null || UnionKeyService::normalizeName($profile->name) !== $normalizedUsername) {
            return null;
        }

        // serialize(false) = 带材质数据签名的完整 profile JSON(与本站 hasJoined 一致);
        // 取其中 textures 属性的 value 原文作为断言 textures。
        $serialized = json_decode($profile->serialize(false), true);
        foreach ($serialized['properties'] ?? [] as $property) {
            if (($property['name'] ?? '') === 'textures' && is_string($property['value'] ?? null)) {
                return [
                    'profileId' => strtolower($profileUuid),
                    'profileName' => $profile->name,
                    'textures' => $property['value'],
                ];
            }
        }

        return null;
    }
}
