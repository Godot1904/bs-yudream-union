<?php

/*
 * YD 联合认证适配插件(Blessing Skin 6 / yggdrasil-connect 共存版)
 *
 * 实现 YD 联合认证协议 v1 的 B 侧(断言回供)、A 侧(联邦 hasJoined)与
 * L1(联合公钥端点),详见 README.md。
 *
 * 与 yggdrasil-connect 的共存约定:
 *   - 路由零冲突:仅注册 yggdrasil-connect 未占用的路径;
 *   - join 证据三级读取(带时间戳补丁 key → ygg_log 表 → 原始 cache key),
 *     不修改 yggdrasil-connect 也能工作(证据窗口 120s,见 JoinEvidenceService);
 *   - 对 LittleSkin\YggdrasilConnect 类的引用全部经 class_exists 守卫,
 *     该插件被禁用时 /peer/joins/query 返回 204(协议降级语义);
 *   - A 侧联邦 hasJoined 需在其 SessionController 未命中分支应用
 *     patch/ready/ 下的成品文件(约 8 行 class_exists 守卫委托)。
 */

use App\Services\Hook;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use SITMC\YudreamUnion\Console\BansPullCommand;
use SITMC\YudreamUnion\Console\InitCommand;
use SITMC\YudreamUnion\Console\PeerCommand;
use SITMC\YudreamUnion\Console\PlantJoinCommand;

return function (Dispatcher $events) {
    $events->listen(ArtisanStarting::class, function (ArtisanStarting $event) {
        $event->artisan->resolveCommands([
            InitCommand::class,
            PeerCommand::class,
            BansPullCommand::class,
        ]);
    });

    Hook::addRoute(function () {
        Route::namespace('SITMC\YudreamUnion\Controllers')
            ->group(function () {
                // —— 协议端点(§2 约定:裸 JSON、X-Union-Version、限流、强制 HTTPS)——
                Route::prefix('api/plugins/yudream-union')
                    ->middleware(\SITMC\YudreamUnion\Http\UnionProtocolMiddleware::class)
                    ->group(function ($route) {
                        $route->get('peer/meta', 'PeerMetaController@meta');
                        $route->get('peer/joins/query', 'PeerJoinsQueryController@query');
                        $route->get('peer/bans', 'PeerBansController@export');
                        $route->post('peer/enroll', 'PeerEnrollController@enroll');
                        // A 侧材质代理:hasJoined 联邦命中后材质 URL 指向这里
                        $route->get('texture/union/{hash}', 'TextureProxyController@fetch')
                            ->where('hash', '[A-Za-z0-9._-]{1,128}');
                    });

                // —— L1 客户端信任集(挂在与 yggdrasil-connect 相同的 API 根下,
                //    路径未被其占用;无需冲突)——
                Route::prefix('api/yggdrasil')
                    ->middleware(\SITMC\YudreamUnion\Http\UnionProtocolMiddleware::class)
                    ->group(function ($route) {
                        $route->get('minecraftservices/publickeys', 'PublicKeysController@keys');
                    });

                // —— authlib 根别名(YDA 路径约定兼容):YDA 注册/刷新受信站时按
                //    {origin}/api/plugins/authlib-injector/ 探对端 authlib 元数据并
                //    钉扎其材质签名钥;BS 的根在 /api/yggdrasil,此处内部转发使
                //    YDA 能为 BS 站完成钉扎(否则 BS 材质钥进不了 YDA 信任集)——
                Route::prefix('api/plugins/authlib-injector')->group(function ($route) {
                    $route->get('/', 'AuthlibAliasController@meta');
                    $route->get('', 'AuthlibAliasController@meta');
                });

                // —— 配置页动作端点(blessing.fetch {code,message} 信封)——
                Route::prefix('admin/plugins/config/yudream-union')
                    ->middleware(['web', 'auth', 'role:admin'])
                    ->group(function ($route) {
                        $route->post('init', 'ConfigController@init');
                        $route->post('enroll-issue', 'ConfigController@enrollIssue');
                        $route->post('enroll-register', 'ConfigController@enrollRegister');
                        $route->post('peer-add', 'ConfigController@addPeer');
                        $route->post('peer-remove', 'ConfigController@removePeer');
                        $route->post('ban-add', 'ConfigController@banAdd');
                        $route->post('ban-lift', 'ConfigController@banLift');
                        $route->post('bans-pull', 'ConfigController@bansPull');
                        $route->post('key-backup', 'ConfigController@keyBackup');
                        $route->post('key-promote', 'ConfigController@keyPromote');
                    });
            });
    });
};
