<?php

namespace SITMC\YudreamUnion\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SITMC\YudreamUnion\Services\UnionBanService;

/** 联合封禁账本导出(协议 §3.3,匿名+限流)。只导本站裁决,不转发他站条目。 */
class PeerBansController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $since = (int) ($request->query('since', '0'));
        if ($since < 0) {
            $since = 0;
        }
        return response()->json(UnionBanService::export($since));
    }
}
