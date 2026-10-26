<?php

namespace SITMC\YudreamUnion\Services;

/**
 * 联合名前缀(协议 §3.1.1,对齐 YDA 0.2.0 语义)。
 *
 * 线上断言始终携带原名;改写只发生在**验收方出口**(A 侧 hasJoined 响应的 name):
 * 命中受信站断言后以 `{peer.namePrefix}_{name}` 为服务端玩家名,确定性分配、
 * 终身稳定(option 持久化映射),冲突追加 _1/_2…,超 16 字符(MC 上限)拒绝本次联合登录。
 * 改写名与封禁判定无关:封禁仍按原名与归属站进行。
 */
final class NamePrefixService
{
    public const OPT_LOCAL_PREFIX = 'yu_name_prefix';   // 本站前缀 [a-z0-9_]{1,4},空=未启用
    public const OPT_NAME_MAP = 'yu_name_map';

    /** A 侧出口改写;返回 null = 超 16 字符,应拒绝本次联合登录并审计。 */
    public static function rewrite(array $peer, string $name): ?string
    {
        $prefix = (string) ($peer['namePrefix'] ?? '');
        if ($prefix === '') {
            return $name;
        }
        $mapKey = ($peer['code'] ?? '') . '|' . UnionKeyService::normalizeName($name);
        $map = self::map();
        if (isset($map[$mapKey]) && is_string($map[$mapKey])) {
            return $map[$mapKey];
        }
        $base = $prefix . '_' . strtolower($name);
        $taken = array_flip(array_map('strtolower', $map));
        $candidate = $base;
        for ($i = 1; ; $i++) {
            if (strlen($candidate) > 16) {
                return null; // 前缀+名(+序号)超 MC 16 字符上限
            }
            if (!isset($taken[strtolower($candidate)])) {
                break;
            }
            $candidate = $base . '_' . $i;
        }
        $map[$mapKey] = $candidate;
        option([self::OPT_NAME_MAP => json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        return $candidate;
    }

    /** @return array<string, string> */
    private static function map(): array
    {
        $raw = json_decode((string) option(self::OPT_NAME_MAP, '{}'), true);
        return is_array($raw) ? $raw : [];
    }
}
