<?php

namespace SITMC\YudreamUnion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SITMC\YudreamUnion\Services\EnrollService;

/**
 * 信任注册(协议 §3.5,机器面,匿名+限流)。
 * 成功 200 {"status":"trusted","peerSecret":"…(仅本次返回)"};
 * 失败:401 auth.badToken / 409 auth.tokenConsumed / 400 invalid-request / 502 peer-unreachable。
 */
class PeerEnrollController extends Controller
{
    public function enroll(Request $request): JsonResponse
    {
        // consume 失败:[false, httpStatus, error, message];成功:[true, peerSecret, peer]
        [$ok, $a, $b, $c] = EnrollService::consume(
            (string) $request->input('enrollToken', ''),
            (string) $request->input('origin', ''),
            (string) $request->input('note', '')
        );
        if (!$ok) {
            return response()->json(['error' => $b, 'errorMessage' => $c], $a);
        }
        return response()->json(['status' => 'trusted', 'peerSecret' => $a]);
    }
}
