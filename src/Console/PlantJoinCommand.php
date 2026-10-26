<?php

namespace SITMC\YudreamUnion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 联调诊断:为指定角色伪造一条本地 join 记录,使 hasJoined 可离线复现。
 *
 *   php artisan yudream-union:plant-join <uuid> [serverId]
 *
 * BS 6 无 tinker,此命令是种 join 缓存的唯一手段;记录 10 分钟后自动过期。
 */
class PlantJoinCommand extends Command
{
    protected $signature = 'yudream-union:plant-join
        {uuid : 角色 UUID(32 位无连字符)}
        {serverId=manualskin : 目标 serverId}';

    protected $description = 'Plant a local join record for hasJoined diagnostics (10 min TTL).';

    public function handle(): int
    {
        $uuid = strtolower(str_replace('-', '', trim((string) $this->argument('uuid'))));
        if (!preg_match('/^[0-9a-f]{32}$/', $uuid)) {
            $this->error('uuid 须为 32 位无连字符 hex');
            return 1;
        }

        $name = null;
        foreach (['uuid', 'players'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)
                && DB::getSchemaBuilder()->hasColumn($table, 'uuid')
                && ($row = DB::table($table)->where('uuid', $uuid)->first())) {
                $name = $row->name ?? null;
                break;
            }
        }
        if ($name === null) {
            $this->error('该 uuid 不在本站 uuid/players 表(仅支持本站真实角色)');
            return 1;
        }

        $serverId = (string) $this->argument('serverId');
        Cache::put("yggdrasil-server-{$serverId}", ['profile' => $uuid, 'ip' => '127.0.0.1'], 600);
        Cache::put("yggdrasil-union-join-{$serverId}", [
            'profile' => $uuid,
            'joinedAt' => (int) (microtime(true) * 1000),
        ], 600);

        $this->info("已为 [{$name}] 种下 join 记录:serverId={$serverId}(600s)");
        $this->line("hasJoined 复现:GET /api/yggdrasil/sessionserver/session/minecraft/hasJoined?username={$name}&serverId={$serverId}");
        return 0;
    }
}
