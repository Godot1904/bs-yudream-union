<?php

namespace SITMC\YudreamUnion\Console;

use Illuminate\Console\Command;
use SITMC\YudreamUnion\Services\UnionKeyService;

/**
 * 一键初始化:生成联合密钥并配置站点身份。
 *
 *
 * 已有密钥时跳过生成(幂等)。
 */
class InitCommand extends Command
{
    protected $signature = 'yudream-union:init
        {siteCode : 跨站断言受众标识(启用后不可变更,如 sitmc)}
        {--name= : 站点展示名(默认取站点设置)}

    protected $description = 'Initialize YD union federation key and site identity.';

    public function handle(): int
    {
        $siteCode = strtolower(trim((string) $this->argument('siteCode')));
        if (!preg_match('/^[a-z0-9_-]{2,32}$/', $siteCode)) {
            $this->error('siteCode 格式非法(2-32 位小写字母/数字/-/_)');
            return 1;
        }
        $current = UnionKeyService::siteCode();
        if ($current !== '' && $current !== $siteCode) {
            $this->error("siteCode 已配置为 [{$current}],启用后不可变更;如需重置请先清除 option yu_site_code(会使既有信任失效)。");
            return 1;
        }

        if (UnionKeyService::privateKeyPem() === null) {
            UnionKeyService::generate();
            $this->info('已生成联合签名密钥(RSA-4096,Crypt 加密存储)。');
        } else {
            $this->warn('联合签名密钥已存在,跳过生成。');
        }

        $options = [UnionKeyService::OPT_SITE_CODE => $siteCode];
        if ($this->option('name')) {
            $options[UnionKeyService::OPT_SITE_NAME] = (string) $this->option('name');
        }

        $publicKey = UnionKeyService::publicKeyPem();
        $this->info('siteCode: ' . $siteCode);
        $this->line('issuerKeyId: ' . UnionKeyService::issuerKeyId());
        $this->line('联合签名公钥(注册时提供给联盟站核对指纹):');
        $this->line($publicKey ?? '');
        $this->newLine();
        $this->info('下一步:向联盟站管理员申请 enrollToken,并完成受信注册(协议 §3.5 由联盟站侧发起)。');

        return 0;
    }
}
