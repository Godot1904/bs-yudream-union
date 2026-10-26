<?php

namespace SITMC\YudreamUnion\Services;

use Illuminate\Support\Facades\Http;

/**
 * 邀请码信任注册(协议 §3.5,对齐 YDA 20156b02 语义)。
 *
 * A 侧(接纳方):issue() 签发一次性 enrollToken(5 分钟、SHA-256 落库、明文仅显示一次);
 * POST /peer/enroll 校验链:限流→token 有效(无效/过期不区分,防枚举)→拉取注册方 origin
 * 的发现文档(3s)→协议校验→TOFU 固定指纹与公钥→CAS 消费→落库 TRUSTED,
 * 响应一次性 peerSecret(§3.4 推送备用,仅存 SHA-256)。
 *
 * B 侧(注册方):selfEnroll() 代理调用对端 POST /peer/enroll,透传对端错误语义。
 */
final class EnrollService
{
    public const OPT_TOKENS = 'yu_enroll_tokens';        // A 侧:已签发 token(SHA-256 形态)
    public const OPT_PEER_SECRETS = 'yu_peer_secrets';   // A 侧:对端 peerSecret 哈希(推送校验备用)
    public const TTL_MS = 300_000;

    /** 签发:返回 [明文 token(仅此一次), 落库记录]。备注留空时自动生成。 */
    public static function issue(string $note = ''): array
    {
        $token = bin2hex(random_bytes(32));
        $note = mb_substr(trim($note), 0, 255);
        if ($note === '') {
            $note = 'auto ' . now()->format('md-Hi');
        }
        $entry = [
            'sha256' => hash('sha256', $token),
            'note' => mb_substr(trim($note), 0, 255),
            'createdAt' => (int) now()->getPreciseTimestamp(3),
            'expiresAt' => (int) now()->getPreciseTimestamp(3) + self::TTL_MS,
            'consumed' => false,
            'consumedBy' => null,
        ];
        $tokens = self::tokens();
        $tokens[] = $entry;
        // 只保留最近 20 条(过期未用的自然失效)
        $tokens = array_slice($tokens, -20);
        option([self::OPT_TOKENS => json_encode($tokens, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        AuditService::log('enroll_issue', ['note' => $entry['note']]);
        return [$token, $entry];
    }

    /**
     * A 侧消费(POST /peer/enroll 处理)。
     * 失败返回 [false, httpStatus, error, message];成功返回 [true, peerSecret 明文, peer]。
     */
    public static function consume(string $token, string $origin, string $note = ''): array
    {
        $origin = rtrim(trim($origin), '/');
        if (mb_strlen($token) < 16 || mb_strlen($token) > 512
            || !str_starts_with($origin, 'https://') || mb_strlen($origin) > 255
            || mb_strlen($note) > 255) {
            return [false, 400, 'invalid-request', 'payload 超限或 origin 非 https'];
        }
        if (UnionKeyService::siteCode() === '') {
            return [false, 400, 'invalid-request', '本站尚未初始化'];
        }

        $hash = hash('sha256', $token);
        $tokens = self::tokens();
        $index = null;
        foreach ($tokens as $i => $t) {
            if (($t['sha256'] ?? '') === $hash) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return [false, 401, 'auth.badToken', '注册令牌无效'];
        }
        $now = (int) now()->getPreciseTimestamp(3);
        if ($tokens[$index]['consumed']) {
            return [false, 409, 'auth.tokenConsumed', '注册令牌已被使用'];
        }
        if ((int) $tokens[$index]['expiresAt'] < $now) {
            return [false, 401, 'auth.badToken', '注册令牌无效']; // 过期与无效同码,防枚举
        }

        // 拉取注册方发现文档(3s 超时)
        try {
            $response = Http::timeout(3)->connectTimeout(3)
                ->get($origin . '/api/plugins/yudream-union/peer/meta');
        } catch (\Throwable $e) {
            return [false, 502, 'peer-unreachable', '拉取对端发现文档失败'];
        }
        if (!$response->successful()) {
            return [false, 502, 'peer-unreachable', '发现文档 HTTP ' . $response->status()];
        }
        [$peer, $reason] = TrustedPeers::fromMeta($origin, $response->json() ?? []);
        if ($peer === null) {
            return [false, 400, 'invalid-request', "对端不满足协议约束:{$reason}"];
        }

        // CAS 消费:重读确认未被并发使用后再写回
        $tokens2 = self::tokens();
        foreach ($tokens2 as $i => $t) {
            if (($t['sha256'] ?? '') === $hash) {
                if ($t['consumed']) {
                    return [false, 409, 'auth.tokenConsumed', '注册令牌已被使用'];
                }
                $tokens2[$i]['consumed'] = true;
                $tokens2[$i]['consumedBy'] = $peer['code'];
                break;
            }
        }
        option([self::OPT_TOKENS => json_encode($tokens2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        TrustedPeers::upsert($peer);
        $peerSecret = bin2hex(random_bytes(32));
        $secrets = json_decode((string) option(self::OPT_PEER_SECRETS, '{}'), true) ?: [];
        $secrets[$peer['code']] = ['sha256' => hash('sha256', $peerSecret), 'at' => now()->toIso8601String()];
        option([self::OPT_PEER_SECRETS => json_encode($secrets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        AuditService::log('peer_enrolled', ['code' => $peer['code'], 'origin' => $origin, 'note' => $note]);
        return [true, $peerSecret, $peer, null]; // 统一 4 元组:[$ok, peerSecret, peer, null]
    }

    /**
     * B 侧:凭对端邀请码把本站注册到对端(代理调用对端 POST /peer/enroll,透传语义)。
     * 成功返回 [true, 对端响应 json];失败 [false, httpStatus, error, message]。
     */
    public static function selfEnroll(string $peerOrigin, string $token, string $note = ''): array
    {
        $peerOrigin = rtrim(trim($peerOrigin), '/');
        if (!str_starts_with($peerOrigin, 'https://')) {
            return [false, 400, 'invalid-request', '对端 origin 必须是 https URL'];
        }
        $selfOrigin = rtrim((string) option('site_url', ''), '/');
        if ($selfOrigin === '') {
            return [false, 400, 'invalid-request', '本站 site_url 未配置'];
        }
        try {
            $response = Http::timeout(8)->connectTimeout(4)->post(
                $peerOrigin . '/api/plugins/yudream-union/peer/enroll',
                ['enrollToken' => $token, 'origin' => $selfOrigin, 'note' => mb_substr($note, 0, 255)]
            );
        } catch (\Throwable $e) {
            return [false, 502, 'peer-unreachable', '请求对端失败:'.$e->getMessage()];
        }
        $body = $response->json();
        if ($response->status() === 200 && is_array($body) && ($body['status'] ?? '') === 'trusted') {
            AuditService::log('peer_self_enroll', ['peer' => $peerOrigin]);
            return [true, $body, null, null]; // 统一 4 元组:[$ok, 对端响应, null, null]
        }
        return [false, $response->status(),
            (string) ($body['error'] ?? 'peer-rejected'),
            (string) ($body['errorMessage'] ?? 'HTTP ' . $response->status())];
    }

    /** @return array<int, array> */
    public static function tokens(): array
    {
        $raw = json_decode((string) option(self::OPT_TOKENS, '[]'), true);
        return is_array($raw) ? array_values($raw) : [];
    }
}
