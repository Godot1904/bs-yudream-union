<?php

namespace SITMC\YudreamUnion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SITMC\YudreamUnion\Services\TrustedPeers;
use SITMC\YudreamUnion\Services\UnionKeyService;

/**
 * L1 客户端信任集(authlib-injector-union ≥1.4,docs/union-public-keys.md)。
 *
 * 集合 = 本站材质签名钥 ∪ 全部受信站钉扎的材质签名钥(注册/刷新时自动抓取)
 *       ∪ 手工追加的合作站钥(option yu_peer_authlib_keys,分号分隔 DER)。
 * 与 YDA 0.5.0 同语义:列表达"自己 + 受信站"的完整列表;两数组必须存在、
 * 去重、每用途 ≤64、Base64 X.509 DER(无 PEM 头尾)。
 */
class PublicKeysController extends Controller
{
    public function keys(): JsonResponse
    {
        $ders = [];

        // 1. 本站 Yggdrasil 材质签名公钥
        $yggPrivate = option('ygg_private_key');
        if (is_string($yggPrivate) && $yggPrivate !== '') {
            $key = openssl_pkey_get_private($yggPrivate);
            if ($key) {
                $der = UnionKeyService::pemToDer(openssl_pkey_get_details($key)['key']);
                if ($der !== '') {
                    $ders[] = base64_encode($der);
                }
            }
        }

        // 2. 受信站钉扎材质钥(注册/刷新时自动抓取)
        foreach (TrustedPeers::all() as $peer) {
            if (!empty($peer['authlibPublicKeyPem'])) {
                $der = UnionKeyService::pemToDer((string) $peer['authlibPublicKeyPem']);
                if ($der !== '') {
                    $ders[] = base64_encode($der);
                }
            }
        }

        // 3. 手工追加的合作站钥
        foreach (preg_split('/[\r\n;]+/', (string) option(UnionKeyService::OPT_PEER_AUTHLIB_KEYS, '')) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (base64_decode($line, true) !== false) {
                $ders[] = $line;
            }
        }

        $distinct = array_values(array_slice(array_unique($ders), 0, 64));
        $entries = array_map(static fn (string $der): array => ['publicKey' => $der], $distinct);

        return response()->json([
            'profilePropertyKeys' => $entries,
            'playerCertificateKeys' => $entries,
        ]);
    }
}
