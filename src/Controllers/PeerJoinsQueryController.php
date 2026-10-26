<?php

namespace SITMC\YudreamUnion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use SITMC\YudreamUnion\Services\AssertionService;
use SITMC\YudreamUnion\Services\AuditService;
use SITMC\YudreamUnion\Services\JoinEvidenceService;
use SITMC\YudreamUnion\Services\UnionBanService;
use SITMC\YudreamUnion\Services\UnionKeyService;

/**
 * 联合 join 断言查询(协议 §3.2,匿名 + 限流)。
 *
 * 未命中、封禁、参数非法、密钥未初始化、上游插件不可用一律 204——
 * 不泄露封禁状态,联邦层降级不影响联盟对本站的其它信任判断。
 */
class PeerJoinsQueryController extends Controller
{
    public function query(Request $request): Response|JsonResponse
    {
        if (UnionKeyService::privateKeyPem() === null) {
            return response()->noContent();
        }

        $username = trim((string) $request->query('username', ''));
        $serverId = trim((string) $request->query('serverId', ''));
        $aud = trim((string) $request->query('aud', ''));
        if ($username === '' || $serverId === '' || $aud === '') {
            return response()->noContent();
        }
        // 断言内容约束(§3.2):角色名 3-16 位 [A-Za-z0-9_]
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $username)) {
            return response()->noContent();
        }
        // 断言签发前自查联合封禁(§3.2:命中一律 204,不泄露封禁状态)
        if (UnionBanService::isNameBanned($username)) {
            return response()->noContent();
        }

        $evidence = (new JoinEvidenceService())->find($username, $serverId);
        if ($evidence === null) {
            return response()->noContent();
        }

        try {
            $result = (new AssertionService())->issue(
                $evidence['profileId'],
                $evidence['profileName'],
                $serverId,
                $aud,
                $evidence['textures']
            );
            AuditService::log('join_issue', [
                'aud' => $aud,
                'username' => $evidence['profileName'],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->noContent();
        }

        return response()->json($result);
    }
}
