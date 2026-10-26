# 去中心联合认证(yudream-union)for Blessing Skin 6

让多个 Minecraft 皮肤站(Blessing Skin 6,含 SIT 定制版)组成联盟:**A 站的玩家可以直接进入 B 站认证的服务器**,无需重复注册;站点之间还可以互通封禁、互相展示皮肤。整个联盟没有中心服务器,任意两站互信即可,新站随加随用。

> 与 yggdrasil-connect(LittleSkin 集中式联盟)是两套独立体系,前缀不重叠,可同时运行。

---

## 它解决什么问题

```text
玩家 Godot 注册/登录在 A 站,B 站开了一个服务器:

  ┌─────────┐  认证   ┌─────────┐        ┌─────────┐   hasJoined   ┌─────────┐
  │ 启动器   │ ──────▶ │ A 站     │        │ MC 服务器 │ ────────────▶ │ B 站     │
  └─────────┘         └─────────┘        └─────────┘               └─────────┘
                          │                                              │
                          │ ① A 站记录:A 玩家加入了该服务器(60 秒内有效)      │
                          │ ◀───────────── ② B 站本地不认识 Godot,向 A 询问 ──┤
                          │ ③ A 站回签名断言(是谁、真的 join 过、材质是什么)   │
                          │ ④ B 站验签 → 改写材质到本站 → 重签 → 放行 ▶ 服务器    │
```

玩家侧零改动:启动器、游戏、服务器都不用装任何东西;信任关系由双方管理员用**一次性邀请码**建立,公钥指纹互通可核验。

---

## 环境

| 项 | 要求 |
|---|---|
| Blessing Skin | 6.x(SITUserCenter 定制版实测 6.0.3) |
| PHP | 8.1 – 8.3,Laravel 10 |
| 认证插件 | yggdrasil-connect 6.1.x(与内置 yggdrasil-api 互斥,站点本就只装一个) |
| 数据库 | 沿用站点现有 MySQL,无需建表 |

---

## 安装

### 第 1 步:部署插件

把 `yudream-union/` 目录放入站点的 `plugins/` 下,后台「插件管理」启用。

### 第 2 步:初始化(点按钮即可)

后台 → 插件管理 → **去中心联合认证 → 配置**:

1. 填写 **siteCode**(2-32 位小写字母/数字,如 `sitmc`)——这是站在联盟里的身份证,**启用后不可变更**,想好再填;
2. 点击「生成联合密钥并初始化」(约数秒)。

完成后页面进入管理界面。也可以用命令行:`php artisan yudream-union:init <siteCode>`。

### 第 3 步:与其他站点互信(邀请码制)

想**接纳**某个站的玩家:

1. 本站配置页 →「生成邀请码」(5 分钟有效,只显示一次,复制给对方管理员);
2. 对方在自己站的配置页 →「凭码注册到对端」→ 填本站 origin + 邀请码 → 注册。

想让本站玩家**进入**对方的服:方向反过来,对方签码、本站注册。双向互通 = 两站各做一轮。

> 受信站卡片会显示对方的**密钥指纹**——重要操作前通过可信渠道(QQ 语音等)核对一下指纹,防中间人。

### 第 4 步(仅当要接纳别人):给 yggdrasil-connect 打适配补丁

接纳联盟玩家进本站服务器,需要在 yggdrasil-connect 的 hasJoined 里加一个委托钩子:

```bash
cd plugins/yggdrasil-connect
git apply ../yudream-union/patch/yggdrasil-connect-union.patch
# 或直接用 patch/ready/src/Controllers/ 下的两个成品文件覆盖同名文件
php -l src/Controllers/SessionController.php   # 语法自检
```

补丁共三处,均带 `YD 联合认证` 注释:①join 时多写一份带时间戳的证据(60 秒窗口,推荐);②根元数据声明联合公钥端点(推荐);③hasJoined 未命中时委托本插件做联邦验证(**接纳别人的必需项**)。

### 第 5 步:清缓存 + 重启 PHP

```bash
php artisan route:clear && php artisan config:clear && php artisan view:clear
systemctl reload php-fpm-83     # 改过任何 .php/.twig 后必须做,见 FAQ
```

---

## 日常使用

### 配置页一览

- **顶部概览**:siteCode / issuerKeyId / 受信站数 / 本站封禁数;
- **站点互信**:签发邀请码(带倒计时和复制)、凭码注册到对端;直连添加是管理员工具,收在"高级"里;
- **受信站表格**:对端站点、密钥指纹、备钥、联合名前缀、TLS 指纹、材质钥钉扎状态;
- **联合封禁账本**:本站裁决(封禁/解除,append-only)+ 一键同步受信站账本;
- **密钥与轮换**:生成备钥 → 观察期 → 晋升为主钥;
- **审计**:联合登录(成功/拒绝/冲突)、管理操作,登录类留 90 天、治理类留 30 天。

### 互信是双向登记的

"A 接纳 B"和"B 接纳 A"是两条独立的信任。想让两站玩家互访,两站各自签发并互相注册一次即可。

### 命令行(脚本化部署用,与页面等效)

| 命令 | 用途 |
|---|---|
| `yudream-union:init <siteCode> [--name=]` | 初始化密钥与站点身份 |
| `yudream-union:peer add\|list\|remove` | 受信站管理(TOFU) |
| `yudream-union:bans-pull` | 拉取受信站封禁账本(建议 cron,≥10 分钟) |
| `yudream-union:plant-join <uuid> [serverId]` | 联调诊断:伪造一条本地 join 记录 |

---

## 常见问题(排查手册)

### 进服提示"验证用户名失败"/"您尚未登录至 Minecraft 账户"

= 服务器 hasJoined 没查到人。按顺序查:

1. **对方账号是否在你站信任列表里?** 没注册 = 不认识(这是设计);
2. 对端站点是否也启用了本插件并完成初始化?
3. join 与 hasJoined 间隔是否超过 **60 秒**?(证据窗口,协议规定);
4. 本站 `storage/logs/laravel.log` 搜 `[yudream-union]`:每一跳都有留痕(`skip`/`rejected`+原因/`accepted`);
5. 多站同时命中同名玩家 → 全部拒绝并留 `conflict` 审计(防冒名,协议规定)——给双方配**联合名前缀**可避免撞名。

### 皮肤不显示

材质签名走 **SHA1withRSA**(游戏规范)。RHEL9/OpenCloudOS 系系统策略默认禁 SHA1 签名,会导致服务器签不出、客户端验不过:

```bash
update-crypto-policies --set DEFAULT:SHA1 && systemctl reload php-fpm-83
```

其余依次查:双方信任集是否都含对方材质钥(`curl 对方 /…/minecraftservices/publickeys` 对比指纹)→ 代理材质请求是否到达本站(nginx 日志搜 `texture/union`)→ 客户端完全重启(失败结果按会话缓存)。

### 改了文件没生效

本站 PHP 生产环境 `opcache.validate_timestamps=0`:**改/覆盖任何 .php 或 .twig 后必须 `systemctl reload php-fpm-83`**;模板另需 `php artisan view:clear`。

### 后台页面报 view:cache 相关错误

BS 会对每个启用插件注册 `views/` 目录。确保 `plugins/yudream-union/views/` 与 `lang/` 目录存在(本包已自带,勿删)。

### 站点没有 tinker 命令

正常,BS 6 不带。诊断用本插件命令(如 `yudream-union:plant-join`),或 phpMyAdmin 直查 `options` 表。

---

## 运维备忘

- **封禁互通**:受信站账本靠拉取同步,GUI 手动按钮或 cron `yudream-union:bans-pull`(建议 ≥10 分钟);只导出本站裁决,不转发他站条目;`subjectSite` 归属过滤,跨站同名不连坐;
- **密钥轮换**:生成备钥(随发现文档下发,观察期)→ 晋升主钥;联盟站有 7 天备钥宽限;
- **数据都在 `options` 表**(`yu_*` 前缀),禁用插件不清数据;彻底重置 = 删除对应 `yu_*` 项 + `storage/options.php` 缓存;
- **审计**登录类 90 天、治理类 30 天自动清理,配置页可查最近记录。

## 卸载

后台禁用插件 → 删除 `plugins/yudream-union/` 目录 → (可选)删除 `options` 表中 `yu_*` 前缀的项。移除受信站后,对方玩家立即无法经联合认证进入本站。

---

## 设计文档

协议权威:`yudream-admin-plugins` 仓 `docs/yudream-union-protocol-v1.md`;客户端信任:`authlib-injector-union` 仓 `docs/union-public-keys.md`。

## 更新日志

### 0.1.0(2026-10-26)

- 双向联邦认证:B 侧断言回供、A 侧联邦 hasJoined(≤8 站并发、TOFU 验签、jti 防重放、多站命中全拒)
- 材质:URL 收敛本站代理 + 本站钥重签(SHA1withRSA),slim 模型保留
- L1 联合公钥端点(配合 authlib-injector-union ≥1.4 客户端)
- 邀请码互信(TOFU + TLS 指纹)、受信站自动钉扎材质钥
- 联合封禁账本(导出/拉取/归属过滤)、审计、密钥主备轮换、联合名前缀
- GUI 配置页(点击初始化、互信管理、封禁、轮换、审计)
- authlib 根别名端点(兼容 YDA 栈的探针路径)
