<?php

namespace SITMC\YudreamUnion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SITMC\YudreamUnion\Services\NamePrefixService;
use SITMC\YudreamUnion\Services\UnionBanService;
use SITMC\YudreamUnion\Services\UnionKeyService;

/** 发现文档(协议 §3.1,匿名 + 限流)。 */
class PeerMetaController extends Controller
{
    public function meta(): JsonResponse
    {
        $publicKey = UnionKeyService::publicKeyPem();
        if ($publicKey === null) {
            return response()->json(
                ['error' => 'not-initialized', 'errorMessage' => 'run `php artisan yudream-union:init` first'],
                503
            );
        }

        $origin = rtrim((string) option('site_url', ''), '/');
        if ($origin === '') {
            $origin = request()->getSchemeAndHttpHost();
        }

        $features = ['join-assertions', 'ban-ledger'];
        $site = [
            'code' => UnionKeyService::siteCode(),
            'name' => UnionKeyService::siteName(),
            'origin' => $origin,
        ];
        // §3.1.1 联合名前缀(启用时声明,验收方出口据此改写)
        $namePrefix = strtolower(trim((string) option(NamePrefixService::OPT_LOCAL_PREFIX, '')));
        if (preg_match('/^[a-z0-9_]{1,4}$/', $namePrefix)) {
            $site['namePrefix'] = $namePrefix;
            $features[] = 'name-namespaces';
        }

        return response()->json([
            'unionVersion' => '1',
            'site' => $site,
            'endpoints' => [
                'apiRoot' => $origin . '/api/plugins/yudream-union',
            ],
            'authlib' => [
                'apiRoot' => $origin . '/api/yggdrasil',
            ],
            'textureBaseUrl' => $origin . '/textures',
            'signaturePublickey' => $publicKey,
            'backupSignaturePublickey' => UnionKeyService::backupPublicKeyPem(),
            'features' => array_values(array_unique($features)),
            'bans' => ['cursor' => UnionBanService::cursor()],
            'enrollEnabled' => true,
        ]);
    }
}
