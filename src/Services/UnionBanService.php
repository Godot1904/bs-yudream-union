<?php

namespace SITMC\YudreamUnion\Services;

use Illuminate\Support\Facades\Http;

/**
 * 联合封禁账本(协议 §3.3,对齐 YDA UnionBanService 语义)。
 *
 * 本站裁决:append-only 事件流(含 LIFTED),seq 单调递增=游标;
 * 受信站账本:按站拉取缓存(option),合并口径=「各直接受信站 ACTIVE 并集」,
 * 声明 subjectSite 的条目只作用于归属站(§3.3 v1.1:空=全域生效);
 * 本站只导出自己签发的裁决,不转发他站条目(防信任传递)。
 */
final class UnionBanService
{
    public const OPT_BANS = 'yu_bans';
    public const OPT_BANS_SEQ = 'yu_bans_seq';
    public const OPT_PEER_LEDGERS = 'yu_peer_ledgers';
    private const PAGE = 500;

    /** 本站追加一条裁决(封禁或解除)。 */
    public static function append(string $name, string $reason, ?int $expiresAt = null, ?string $subjectSite = null): array
    {
        $seq = (int) option(self::OPT_BANS_SEQ, 0) + 1;
        option([self::OPT_BANS_SEQ => $seq]);
        $entry = [
            'seq' => (string) $seq,
            'name' => UnionKeyService::normalizeName($name),
            'reason' => mb_substr($reason, 0, 200),
            'status' => 'active',
            'createdAt' => (int) now()->getPreciseTimestamp(3),
            'expiresAt' => $expiresAt,
            'subjectSite' => $subjectSite ?? UnionKeyService::siteCode(),
        ];
        $bans = self::localBans();
        $bans[] = $entry;
        option([self::OPT_BANS => self::encode($bans)]);
        return $entry;
    }

    /** 解除封禁=追加 LIFTED 事件(append-only,不删历史)。 */
    public static function lift(string $name): bool
    {
        $name = UnionKeyService::normalizeName($name);
        if (!self::latestLocalByName($name) || self::latestLocalByName($name)['status'] !== 'active') {
            return false;
        }
        $seq = (int) option(self::OPT_BANS_SEQ, 0) + 1;
        option([self::OPT_BANS_SEQ => $seq]);
        $bans = self::localBans();
        $bans[] = [
            'seq' => (string) $seq,
            'name' => $name,
            'reason' => '',
            'status' => 'lifted',
            'createdAt' => (int) now()->getPreciseTimestamp(3),
            'expiresAt' => null,
            'subjectSite' => UnionKeyService::siteCode(),
        ];
        option([self::OPT_BANS => self::encode($bans)]);
        return true;
    }

    /** 导出本站裁决(§3.3):seq > since 的 append-only 事件,≤500 条,cursor 只推进到实际交付。 */
    public static function export(int $since): array
    {
        $entries = array_values(array_filter(self::localBans(), static fn ($e) => (int) $e['seq'] > $since));
        sort($entries);
        $delivered = array_slice($entries, 0, self::PAGE);
        $cursor = $delivered === [] ? (string) $since : (string) end($delivered)['seq'];
        $code = UnionKeyService::siteCode();
        return [
            'cursor' => $cursor,
            'bans' => array_map(static fn ($e) => [
                'name' => $e['name'],
                'reason' => $e['reason'],
                'sourceSite' => $code,
                'subjectSite' => $e['subjectSite'] ?? null,
                'createdAt' => $e['createdAt'],
                'expiresAt' => $e['expiresAt'],
                'status' => $e['status'],
                'seq' => $e['seq'],
            ], $delivered),
        ];
    }

    public static function cursor(): string
    {
        return (string) option(self::OPT_BANS_SEQ, 0);
    }

    /**
     * 拉取全部受信站账本(游标增量,防环:cursor 不推进即停)。
     * 返回各站摘要;由 GUI 手动同步或 artisan 命令(建议 cron ≥10min)驱动。
     */
    public static function pullAll(): array
    {
        $summary = [];
        foreach (TrustedPeers::queryable() as $peer) {
            $summary[$peer['code']] = self::pullPeer($peer);
        }
        AuditService::log('bans_pull', ['summary' => $summary]);
        return $summary;
    }

    private static function pullPeer(array $peer): array
    {
        $ledgers = self::ledgers();
        $state = $ledgers[$peer['code']] ?? ['cursor' => '0', 'bans' => []];
        $cursor = (string) $state['cursor'];
        $merged = $state['bans'];

        for ($i = 0; $i < 20; $i++) {
            try {
                $response = Http::timeout(4)->connectTimeout(3)
                    ->get(rtrim($peer['apiRoot'], '/') . '/peer/bans', ['since' => $cursor]);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'cursor' => $cursor];
            }
            if (!$response->successful()) {
                return ['ok' => false, 'error' => 'HTTP ' . $response->status(), 'cursor' => $cursor];
            }
            $body = $response->json();
            $bans = is_array($body) ? ($body['bans'] ?? []) : [];
            $newCursor = (string) ($body['cursor'] ?? $cursor);
            foreach ($bans as $ban) {
                if (is_array($ban) && isset($ban['name'], $ban['seq'], $ban['status'])) {
                    $merged[(string) $ban['seq']] = [
                        'name' => UnionKeyService::normalizeName((string) $ban['name']),
                        'reason' => (string) ($ban['reason'] ?? ''),
                        'status' => $ban['status'] === 'active' ? 'active' : 'lifted',
                        'createdAt' => (int) ($ban['createdAt'] ?? 0),
                        'expiresAt' => isset($ban['expiresAt']) ? (int) $ban['expiresAt'] : null,
                        'subjectSite' => isset($ban['subjectSite']) && $ban['subjectSite'] !== ''
                            ? strtolower((string) $ban['subjectSite']) : null,
                    ];
                }
            }
            if ($bans === [] || $newCursor === $cursor) {
                $cursor = $newCursor;
                break;
            }
            $cursor = $newCursor;
        }

        $ledgers[$peer['code']] = [
            'cursor' => $cursor,
            'bans' => $merged,
            'fetchedAt' => now()->toIso8601String(),
        ];
        option([self::OPT_PEER_LEDGERS => self::encode($ledgers)]);
        return ['ok' => true, 'cursor' => $cursor, 'entries' => count($merged)];
    }

    /** 联合判定:规范化名是否命中【本站 ∪ 各直接受信站】ACTIVE 名单(subjectSite 归属过滤)。 */
    public static function isNameBanned(string $name): bool
    {
        $name = UnionKeyService::normalizeName($name);
        if ($name === '') {
            return false;
        }
        $now = (int) (microtime(true) * 1000);
        // 本站:按名取最大 seq 事件
        if (($latest = self::latestLocalByName($name)) !== null) {
            if (self::activeAt($latest, $now)) {
                return true;
            }
        }
        // 受信站:各站名单并集,归属过滤(空=全域;=本站 siteCode 才作用于本站)
        $self = UnionKeyService::siteCode();
        foreach (self::ledgers() as $ledger) {
            $byName = [];
            foreach ($ledger['bans'] ?? [] as $entry) {
                if (($entry['name'] ?? '') !== $name) {
                    continue;
                }
                if (isset($entry['subjectSite']) && $entry['subjectSite'] !== null && $entry['subjectSite'] !== '' && $entry['subjectSite'] !== $self) {
                    continue; // 归属他站的裁决不连坐(§3.3)
                }
                $byName[] = $entry;
            }
            if ($byName !== []) {
                usort($byName, static fn ($a, $b) => (int) ($a['createdAt'] ?? 0) <=> (int) ($b['createdAt'] ?? 0));
                if (self::activeAt(end($byName), $now)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function activeAt(array $entry, int $now): bool
    {
        if (($entry['status'] ?? '') !== 'active') {
            return false;
        }
        $expiresAt = $entry['expiresAt'] ?? null;
        return $expiresAt === null || (is_int($expiresAt) && $expiresAt > $now);
    }

    private static function latestLocalByName(string $name): ?array
    {
        $latest = null;
        foreach (self::localBans() as $entry) {
            if (($entry['name'] ?? '') === $name
                && ($latest === null || (int) $entry['seq'] > (int) $latest['seq'])) {
                $latest = $entry;
            }
        }
        return $latest;
    }

    /** @return array<int, array> */
    public static function localBans(): array
    {
        $raw = json_decode((string) option(self::OPT_BANS, '[]'), true);
        return is_array($raw) ? array_values($raw) : [];
    }

    /** @return array<string, array> */
    public static function ledgers(): array
    {
        $raw = json_decode((string) option(self::OPT_PEER_LEDGERS, '{}'), true);
        return is_array($raw) ? $raw : [];
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
