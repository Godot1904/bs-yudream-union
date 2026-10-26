<?php

namespace SITMC\YudreamUnion\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SITMC\YudreamUnion\Services\TrustedPeers;

/**
 * 联合材质代理(A 侧配套):{site}/api/plugins/yudream-union/texture/union/{hash}。
 *
 * hasJoined 联邦命中后,材质 URL 已改写为该代理——把客户端的材质域名白名单
 * 与签名信任根收敛到本站(对齐 YDA AuthlibAppService#unionTexture 语义):
 * 本站静态材质未命中 → 按受信站顺序回源,200+非空+≤512KB 即采信(不校验
 * Content-Type——yudream-skin 端点可能返回 octet-stream 等非 image 类型);
 * 每站先试其对端声明的 textureBaseUrl,再兜底 YD 约定路径
 * {origin}/api/plugins/yudream-skin/textures/。材质不可变,响应可长缓存。
 */
class TextureProxyController extends Controller
{
    private const MAX_BYTES = 512 * 1024;

    public function fetch(Request $request, string $hash): \Illuminate\Http\Response
    {
        if (!preg_match('/^[0-9a-fA-F]{32,128}$/', $hash)) {
            abort(404);
        }

        // 本站静态材质(与 yggdrasil-connect 共用的 /textures/{hash})
        $local = public_path("textures/{$hash}");
        if (is_file($local)) {
            $bytes = (string) file_get_contents($local);
            if ($bytes !== '' && strlen($bytes) <= self::MAX_BYTES) {
                return $this->image($bytes, $this->guessMime($bytes));
            }
        }

        foreach (TrustedPeers::queryable() as $peer) {
            $bases = array_values(array_unique(array_filter([
                rtrim((string) $peer['textureBaseUrl'], '/'),
                rtrim((string) $peer['origin'], '/') . '/api/plugins/yudream-skin/textures',
            ])));
            foreach ($bases as $base) {
                $bytes = $this->pull($base . '/' . strtolower($hash));
                if ($bytes !== null) {
                    Log::info('[yudream-union] texture proxy hit', ['base' => $base, 'hash' => $hash]);
                    return $this->image($bytes, $this->guessMime($bytes));
                }
            }
        }

        Log::warning('[yudream-union] texture proxy miss', ['hash' => $hash]);
        abort(404);
    }

    /** 200 + 非空 + ≤512KB 即采信(对齐 YDA,不校验 Content-Type)。 */
    private function pull(string $url): ?string
    {
        try {
            $response = Http::timeout(8)->connectTimeout(3)->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$response->successful()) {
            return null;
        }
        $body = (string) $response->body();
        return ($body !== '' && strlen($body) <= self::MAX_BYTES) ? $body : null;
    }

    private function image(string $bytes, string $mime): \Illuminate\Http\Response
    {
        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }

    /** PNG 魔数优先,兜底 octet-stream(客户端按内容解码,不依赖该头)。 */
    private function guessMime(string $bytes): string
    {
        return str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'application/octet-stream';
    }
}
