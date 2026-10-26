<?php

namespace SITMC\YudreamUnion\Services;

/**
 * 轻量审计(option 环形存储,上限 1000 条;惰性清理:联合登录类保留 90 天,
 * 治理类保留 30 天,对齐 YDA 默认保留策略)。类型:
 * join_issue(B侧签发)/ join_accept(A侧命中)/ join_reject(A侧拒绝)
 * / conflict(多站冲突)/ peer_add / peer_remove / key_rotate / ban_add / ban_lift / bans_pull
 */
final class AuditService
{
    public const OPT = 'yu_audit';
    public const OPT_LAST_CLEAN = 'yu_audit_last_clean';
    private const CAP = 1000;
    private const LOGIN_TYPES = ['join_issue', 'join_accept', 'join_reject', 'conflict'];

    public static function log(string $type, array $detail = []): void
    {
        $now = now();
        $entries = self::all();
        $entries[] = ['type' => $type, 'detail' => $detail, 'at' => $now->toIso8601String()];
        if (count($entries) > self::CAP) {
            $entries = array_slice($entries, -self::CAP);
        }
        option([self::OPT => json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        $lastClean = (int) strtotime((string) option(self::OPT_LAST_CLEAN, ''));
        if ($lastClean < 1 || $now->getTimestamp() - $lastClean > 86400) {
            option([self::OPT_LAST_CLEAN => $now->toIso8601String()]);
            self::clean($now->getTimestamp());
        }
    }

    /** @return array<int, array> 倒序 */
    public static function recent(int $limit = 50): array
    {
        return array_slice(array_reverse(self::all()), 0, $limit);
    }

    private static function clean(int $nowTs): void
    {
        $entries = array_values(array_filter(self::all(), static function ($e) use ($nowTs) {
            $ts = strtotime((string) ($e['at'] ?? '')) ?: 0;
            $retention = in_array($e['type'] ?? '', self::LOGIN_TYPES, true) ? 90 : 30;
            return $nowTs - $ts <= $retention * 86400;
        }));
        option([self::OPT => json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    /** @return array<int, array> */
    private static function all(): array
    {
        $raw = json_decode((string) option(self::OPT, '[]'), true);
        return is_array($raw) ? array_values($raw) : [];
    }
}
