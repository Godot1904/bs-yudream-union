<?php

namespace SITMC\YudreamUnion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use SITMC\YudreamUnion\Services\TrustedPeers;

/**
 * 受信站管理(M2:TOFU 直加;M3 演进为 enrollToken 门禁,协议 §3.5)。
 *
 *   php artisan yudream-union:peer add https://skin.example.edu
 *   php artisan yudream-union:peer list
 *   php artisan yudream-union:peer remove example
 *
 * add 会拉取 {origin}/api/plugins/yudream-union/peer/meta 并按协议 §3.1 校验
 * (unionVersion/https/textureBaseUrl 同源/PEM 可用),TOFU 固定其联合公钥;
 * 同 siteCode 已存在且公钥指纹变化时要求确认(信任重建)。
 */
class PeerCommand extends Command
{
    protected $signature = 'yudream-union:peer
        {action : add|list|remove}
        {target? : add 时为对端 origin;remove 时为 siteCode}
        {--force : 跳过公钥变化确认}';

    protected $description = 'Manage YD union trusted peers (TOFU).';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'add' => $this->add(),
            'list' => $this->listing(),
            'remove' => $this->remove(),
            default => $this->failUsage(),
        };
    }

    private function failUsage(): int
    {
        $this->error('用法:yudream-union:peer {add|list|remove} {origin|siteCode}');
        return 1;
    }

    private function add(): int
    {
        $origin = rtrim(trim((string) $this->argument('target')), '/');
        if (!str_starts_with($origin, 'https://')) {
            $this->error('origin 必须是 https URL(协议 §2 强制)。');
            return 1;
        }

        try {
            $response = Http::timeout(3)->connectTimeout(3)
                ->get($origin . '/api/plugins/yudream-union/peer/meta');
        } catch (\Throwable $e) {
            $this->error('拉取发现文档失败:' . $e->getMessage());
            return 1;
        }
        if (!$response->successful()) {
            $this->error('发现文档返回 ' . $response->status());
            return 1;
        }

        [$peer, $reason] = TrustedPeers::fromMeta($origin, $response->json() ?? []);
        if ($peer === null) {
            $this->error("对端发现文档不满足协议约束:{$reason}");
            return 1;
        }

        $existing = TrustedPeers::find($peer['code']);
        if ($existing !== null) {
            $sameKey = ($existing['publicKeyPem'] ?? '') === $peer['publicKeyPem'];
            if (!$sameKey && !$this->option('force')) {
                if (!$this->confirm(
                    "受信站 [{$peer['code']}] 已存在且联合公钥发生变化(TOFU 信任重建,需经可信渠道核对指纹),继续?",
                    false
                )) {
                    return 1;
                }
            }
        }

        TrustedPeers::upsert($peer);
        $this->info("已加入受信站 [{$peer['code']}] {$peer['name']} ({$peer['origin']})");
        $this->line('apiRoot: ' . $peer['apiRoot']);
        $this->line('textureBaseUrl: ' . $peer['textureBaseUrl']);
        $this->line('联合公钥指纹(SHA-256 前 16 hex): '
            . substr(hash('sha256', \SITMC\YudreamUnion\Services\UnionKeyService::pemToDer($peer['publicKeyPem'])), 0, 32));
        return 0;
    }

    private function listing(): int
    {
        $peers = TrustedPeers::all();
        if ($peers === []) {
            $this->info('(无受信站)');
            return 0;
        }
        $this->table(
            ['code', 'name', 'origin', 'keyId', 'addedAt'],
            array_map(static function ($p) {
                return [
                    $p['code'],
                    $p['name'],
                    $p['origin'],
                    substr(hash('sha256', \SITMC\YudreamUnion\Services\UnionKeyService::pemToDer($p['publicKeyPem'])), 0, 32),
                    $p['addedAt'] ?? '-',
                ];
            }, $peers)
        );
        return 0;
    }

    private function remove(): int
    {
        $code = strtolower(trim((string) $this->argument('target')));
        if ($code === '') {
            return $this->failUsage();
        }
        if (!TrustedPeers::remove($code)) {
            $this->error("受信站 [{$code}] 不存在。");
            return 1;
        }
        $this->info("已移除受信站 [{$code}]。");
        return 0;
    }
}
