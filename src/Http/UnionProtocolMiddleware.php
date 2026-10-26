<?php

namespace SITMC\YudreamUnion\Http;

use Closure;
use Illuminate\Http\Request;

/**
 * 协议 §2 wire 约定:X-Union-Version: 1 头、强制 HTTPS(本地除外)、
 * 按来源限流(60 秒 30 次,Cache 计数)。
 */
class UnionProtocolMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->secure()
            && !in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && app()->environment('production')) {
            return response()->json(
                ['error' => 'https-required', 'errorMessage' => 'union endpoints require HTTPS'],
                400
            );
        }

        if (!$this->allowRate($request)) {
            return response()->json(
                ['error' => 'auth.rateLimited', 'errorMessage' => 'too many requests'],
                429,
                ['X-Union-Version' => '1']
            );
        }

        /** @var \Illuminate\Http\Response $response */
        $response = $next($request);
        $response->headers->set('X-Union-Version', '1');
        return $response;
    }

    private function allowRate(Request $request): bool
    {
        $key = 'yu-rl-' . sha1($request->ip() ?: 'unknown');
        $count = (int) (\Illuminate\Support\Facades\Cache::get($key, 0)) + 1;
        \Illuminate\Support\Facades\Cache::put($key, $count, 60);
        return $count <= 30;
    }
}
