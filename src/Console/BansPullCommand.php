<?php

namespace SITMC\YudreamUnion\Console;

use Illuminate\Console\Command;
use SITMC\YudreamUnion\Services\UnionBanService;

/**
 * 受信站封禁账本定时同步(建议 cron ≥10 分钟,配合 GUI 手动同步互补):
 *
 *   * * * * * php /www/wwwroot/blessingskin6.0.3/artisan yudream-union:bans-pull
 */
class BansPullCommand extends Command
{
    protected $signature = 'yudream-union:bans-pull';

    protected $description = 'Pull ban ledgers from all trusted peers (for cron).';

    public function handle(): int
    {
        $summary = UnionBanService::pullAll();
        $ok = 0;
        foreach ($summary as $code => $result) {
            if (($result['ok'] ?? false) === true) {
                $ok++;
                $this->info("[{$code}] cursor={$result['cursor']} entries={$result['entries']}");
            } else {
                $this->error("[{$code}] {$result['error']} (cursor={$result['cursor']})");
            }
        }
        $this->info("Done: {$ok}/" . count($summary) . " peers synced.");
        return 0;
    }
}
