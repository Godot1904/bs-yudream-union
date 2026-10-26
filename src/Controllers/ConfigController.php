<?php

namespace SITMC\YudreamUnion\Controllers;

use App\Services\Facades\Option;
use App\Services\OptionForm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use SITMC\YudreamUnion\Services\AuditService;
use SITMC\YudreamUnion\Services\EnrollService;
use SITMC\YudreamUnion\Services\TrustedPeers;
use SITMC\YudreamUnion\Services\UnionBanService;
use SITMC\YudreamUnion\Services\UnionKeyService;

/**
 * 插件配置页(package.json enchants.config)。
 *
 * 声明式 OptionForm 管静态配置;受信站管理与状态展示走 twig 内联 JS,
 * 动作端点(admin/plugins/config/yudream-union/*)由 bootstrap 注册,
 * 响应遵循 blessing.fetch 的 {code, message} 信封(code===0 成功)。
 */
class ConfigController extends Controller
{
    public function render(): View
    {
        $basicForm = Option::form('basic', '基础设置', function (OptionForm $form) {
            $form->text(UnionKeyService::OPT_SITE_NAME, '站点展示名')
                ->description('显示在受信站目录与断言审计中;初始化后不可变更。');
        })->handle();

        $publicKey = UnionKeyService::publicKeyPem();
        $keyId = UnionKeyService::issuerKeyId();
        $backupKey = UnionKeyService::backupPublicKeyPem();

        return view('SITMC\YudreamUnion::config-v3', [
            'forms' => ['basic' => $basicForm],
            'initialized' => $publicKey !== null,
            'siteCode' => UnionKeyService::siteCode(),
            'siteName' => UnionKeyService::siteName(),
            'issuerKeyId' => $keyId,
            'publicKeyPem' => $publicKey ?? '',
            'backupKeyId' => $backupKey !== null
                ? substr(hash('sha256', UnionKeyService::pemToDer($backupKey)), 0, 32) : null,
            'peers' => array_map(static function ($p) {
                return [
                    'code' => $p['code'],
                    'name' => $p['name'],
                    'origin' => $p['origin'],
                    'keyId' => substr(hash('sha256', UnionKeyService::pemToDer($p['publicKeyPem'])), 0, 32),
                    'backup' => !empty($p['backupPem']),
                    'namePrefix' => $p['namePrefix'] ?? '',
                    'authlib' => !empty($p['authlibPublicKeyPem']),
                    'tls' => isset($p['tlsFingerprint']) && $p['tlsFingerprint'] !== null
                        ? substr((string) $p['tlsFingerprint'], 0, 12) : '—',
                    'addedAt' => $p['addedAt'] ?? '-',
                ];
            }, TrustedPeers::all()),
            'localBans' => array_map(static function ($b) {
                $b['createdAtText'] = date('m-d H:i', (int) ((int) ($b['createdAt'] ?? 0) / 1000));
                return $b;
            }, array_slice(array_reverse(UnionBanService::localBans()), 0, 20)),
            'ledgers' => array_map(static fn ($code, $l) => [
                'code' => $code,
                'cursor' => $l['cursor'] ?? '0',
                'entries' => count($l['bans'] ?? []),
                'fetchedAt' => $l['fetchedAt'] ?? '—',
            ], array_keys(UnionBanService::ledgers()), array_values(UnionBanService::ledgers())),
            'audit' => AuditService::recent(20),
            'enrollTokens' => array_map(static function ($t) {
                $t['statusText'] = $t['consumed']
                    ? ('已用于 ' . ($t['consumedBy'] ?? '?'))
                    : ((int) $t['expiresAt'] > (int) now()->getPreciseTimestamp(3) ? '待使用' : '已过期');
                $t['createdText'] = date('m-d H:i', (int) ((int) $t['createdAt'] / 1000));
                return $t;
            }, EnrollService::tokens()),
        ]);
    }

    /**
     * POST admin/plugins/config/yudream-union/init {siteCode, name}
     * 仅在未初始化时工作(幂等保护:已有密钥或 siteCode 一律拒绝,保证 siteCode 不可变更)。
     * RSA-4096 生成约需数秒。
     */
    public function init(Request $request): JsonResponse
    {
        if (UnionKeyService::privateKeyPem() !== null || UnionKeyService::siteCode() !== '') {
            return response()->json(['code' => 1, 'message' => '联合密钥已初始化;siteCode 启用后不可变更(如确需重置,须清除 option 并重新走全部信任注册)。']);
        }
        $siteCode = strtolower(trim((string) $request->input('siteCode')));
        if (!preg_match('/^[a-z0-9_-]{2,32}$/', $siteCode)) {
            return response()->json(['code' => 1, 'message' => 'siteCode 格式非法:2-32 位小写字母/数字/-/_']);
        }
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            $name = (string) option('site_name', 'Blessing Skin');
        }

        set_time_limit(60);
        UnionKeyService::generate();
        option([
            UnionKeyService::OPT_SITE_CODE => $siteCode,
            UnionKeyService::OPT_SITE_NAME => $name,
        ]);

        return response()->json([
            'code' => 0,
            'message' => "初始化完成:siteCode={$siteCode}",
            'data' => ['issuerKeyId' => UnionKeyService::issuerKeyId()],
        ]);
    }

    /** POST admin/plugins/config/yudream-union/peer-add {origin, force} */
    public function addPeer(Request $request): JsonResponse
    {
        $origin = rtrim(trim((string) $request->input('origin')), '/');
        if (!str_starts_with($origin, 'https://')) {
            return response()->json(['code' => 1, 'message' => 'origin 必须是 https URL']);
        }
        try {
            $response = Http::timeout(3)->connectTimeout(3)
                ->get($origin . '/api/plugins/yudream-union/peer/meta');
        } catch (\Throwable $e) {
            return response()->json(['code' => 1, 'message' => '拉取发现文档失败:'.$e->getMessage()]);
        }
        if (!$response->successful()) {
            return response()->json(['code' => 1, 'message' => '发现文档返回 HTTP '.$response->status()]);
        }

        [$peer, $reason] = TrustedPeers::fromMeta($origin, $response->json() ?? []);
        if ($peer === null) {
            return response()->json(['code' => 1, 'message' => "不满足协议约束:{$reason}"]);
        }
        $existing = TrustedPeers::find($peer['code']);
        if ($existing !== null
            && ($existing['publicKeyPem'] ?? '') !== $peer['publicKeyPem']
            && !$request->boolean('force')) {
            return response()->json([
                'code' => 2,
                'message' => "受信站 [{$peer['code']}] 联合公钥发生变化(TOFU 信任重建)。请经可信渠道核对新指纹后重试并勾选确认。",
            ]);
        }
        TrustedPeers::upsert($peer);

        return response()->json([
            'code' => 0,
            'message' => "已加入受信站 [{$peer['code']}]",
            'data' => ['code' => $peer['code'], 'keyId' => substr(
                hash('sha256', UnionKeyService::pemToDer($peer['publicKeyPem'])), 0, 32
            )],
        ]);
    }

    /**
     * POST admin/plugins/config/yudream-union/enroll-issue {note}
     * 签发一次性邀请码(5 分钟有效);明文仅本次返回,落库只存 SHA-256。
     */
    public function enrollIssue(Request $request): JsonResponse
    {
        if (UnionKeyService::siteCode() === '') {
            return response()->json(['code' => 1, 'message' => '尚未初始化']);
        }
        [$token] = EnrollService::issue((string) $request->input('note', ''));
        return response()->json([
            'code' => 0,
            'message' => '已签发(5 分钟内有效,仅显示这一次)',
            'data' => ['token' => $token],
        ]);
    }

    /**
     * POST admin/plugins/config/yudream-union/enroll-register {origin, token, note}
     * B 侧:凭对端签发的邀请码,把本站注册到对端(代理调用对端 /peer/enroll)。
     */
    public function enrollRegister(Request $request): JsonResponse
    {
        [$ok, $status, $error, $message] = EnrollService::selfEnroll(
            (string) $request->input('origin', ''),
            (string) $request->input('token', ''),
            (string) $request->input('note', '')
        );
        if (!$ok) {
            return response()->json(['code' => 1, 'message' => "注册失败 [{$error}] {$message}"]);
        }
        $secret = (string) ($status['peerSecret'] ?? '');
        return response()->json([
            'code' => 0,
            'message' => '注册成功,对端已信任本站;peerSecret 仅显示这一次(用于封禁推送,当前版本可忽略)',
            'data' => ['peerSecret' => $secret],
        ]);
    }

    /** POST admin/plugins/config/yudream-union/peer-remove {code} */
    public function removePeer(Request $request): JsonResponse
    {
        $code = strtolower(trim((string) $request->input('code')));
        if (!TrustedPeers::remove($code)) {
            return response()->json(['code' => 1, 'message' => "受信站 [{$code}] 不存在"]);
        }
        AuditService::log('peer_remove', ['code' => $code]);
        return response()->json(['code' => 0, 'message' => "已移除受信站 [{$code}]"]);
    }

    /** POST admin/plugins/config/yudream-union/ban-add {name, reason} */
    public function banAdd(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        $reason = trim((string) $request->input('reason'));
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $name)) {
            return response()->json(['code' => 1, 'message' => '角色名须为 3-16 位 [A-Za-z0-9_]']);
        }
        UnionBanService::append($name, $reason);
        AuditService::log('ban_add', ['name' => strtolower($name), 'reason' => $reason]);
        return response()->json(['code' => 0, 'message' => "已封禁 {$name}(active,全域或归属本站)"]);
    }

    /** POST admin/plugins/config/yudream-union/ban-lift {name} */
    public function banLift(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        if (!UnionBanService::lift($name)) {
            return response()->json(['code' => 1, 'message' => "没有名为 {$name} 的 active 封禁"]);
        }
        AuditService::log('ban_lift', ['name' => UnionKeyService::normalizeName($name)]);
        return response()->json(['code' => 0, 'message' => "已解除 {$name}"]);
    }

    /** POST admin/plugins/config/yudream-union/bans-pull(手动同步受信站账本;cron 用 artisan 命令) */
    public function bansPull(): JsonResponse
    {
        $summary = UnionBanService::pullAll();
        $ok = count(array_filter($summary, static fn ($s) => ($s['ok'] ?? false) === true));
        return response()->json([
            'code' => 0,
            'message' => "账本同步完成:{$ok}/" . count($summary) . " 个受信站成功",
            'data' => $summary,
        ]);
    }

    /** POST admin/plugins/config/yudream-union/key-backup(生成备钥) */
    public function keyBackup(): JsonResponse
    {
        if (UnionKeyService::privateKeyPem() === null) {
            return response()->json(['code' => 1, 'message' => '尚未初始化']);
        }
        set_time_limit(60);
        UnionKeyService::generateBackup();
        AuditService::log('key_rotate', ['action' => 'backup-generated']);
        return response()->json(['code' => 0, 'message' => '已生成备钥并随 peer/meta 下发(观察期后可晋升)']);
    }

    /** POST admin/plugins/config/yudream-union/key-promote(备钥升主,旧主钥弃用) */
    public function keyPromote(): JsonResponse
    {
        if (!UnionKeyService::promoteBackup()) {
            return response()->json(['code' => 1, 'message' => '没有可用备钥,先生成备钥']);
        }
        AuditService::log('key_rotate', ['action' => 'promoted']);
        return response()->json(['code' => 0, 'message' => '备钥已晋升为主钥;issuerKeyId 已变化,联盟站验签有备钥宽限']);
    }
}
