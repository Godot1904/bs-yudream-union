<?php

namespace SITMC\YudreamUnion\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * authlib 根别名(兼容 YDA 路径约定)。
 *
 * YDA 的 yudream-union 在注册/刷新受信站时,按自身路径约定探对端 authlib 根
 * {origin}/api/plugins/authlib-injector/ 以钉扎对端材质签名钥;而 BS 站的
 * authlib 根是 /api/yggdrasil(yggdrasil-connect)。本别名把 YDA 的探针路径
 * 内部转发到本站元数据,使其能正常完成 TOFU 钉扎——否则 skin 的材质钥进不了
 * YDA 的联合公钥信任集,访客(union agent)验签原住民皮肤必败。
 */
class AuthlibAliasController extends Controller
{
    public function meta(Request $request): Response
    {
        $response = app()->handle(\Illuminate\Http\Request::create('/api/yggdrasil', 'GET', $request->query()));
        return response($response->getContent(), 200, ['Content-Type' => 'application/json']);
    }
}
