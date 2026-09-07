# cms-apps-for-xunrui

基于 [迅睿CMS](https://www.xunruicms.com/) 开发的应用插件集合。将各插件目录复制到站点的 `dayrui/App/` 下，在后台「应用插件」中安装启用即可。

## 仓库结构

```
cms-apps-for-xunrui/
├── Smsbao/       # 短信宝接口
├── Wxseccheck/   # 微信内容安全
└── README.md
```

## 插件列表

### Smsbao — 短信宝接口

短信宝（smsbao.com）短信发送插件，用于在迅睿CMS中发送验证码和文本短信。

**功能：**

- 发送验证码短信（支持自定义模板，`{code}` 占位符）
- 发送文本短信
- 后台配置账号、密码、签名、验证码模板
- 后台查询短信宝账户余额
- 发送失败自动写入日志（`writable/sms_log.txt`）

**安装：**

将 `Smsbao` 目录复制到迅睿CMS的 `dayrui/App/` 目录下，然后在后台「应用插件」中安装启用。

**目录结构：**

```
Smsbao/
├── Config/          # 插件配置（路由、菜单、钩子、版本等）
├── Controllers/     # 后台控制器
├── Models/          # 短信发送模型
├── Views/           # 后台配置页面
└── Files.txt        # 应用打包文件清单
```

**钩子函数：**

- `my_sendsms_code($mobile, $content)` — 发送验证码
- `my_sendsms_text($mobile, $content)` — 发送文本短信

**版本：** 1.0

---

### Wxseccheck — 微信内容安全

在模块内容**发布/编辑入库之前**调用微信 `msgSecCheck`，并在**文件上传后**调用 `imgSecCheck`，拦截违法违规文本与图片。用于满足小程序 UGC「发布」场景的内容安全要求。

**功能：**

- 挂钩 `module_content_before`（[文档](https://www.xunruicms.com/doc/370.html)）做文本检测；失败返回 `dr_return_data(0, msg)` 拦截入库
- 挂钩 `upload_file`（[文档](https://help.xunruicms.com/978.html)）做图片检测；违规删除本地文件并以 JSON 中断上传
- 文本：`security.msgSecCheck`（有 openid 走 2.0，否则降级 1.0；超 2500 字自动分段）
- 图片：`security.imgSecCheck`；兼容站点 **5M** 上传，送检前生成临时副本缩至 750×1334 并压到微信 **1M**（原图不改）
- 默认检测全部模块、全部字段；可配置模块目录、字段、scene；文本/图片可分别开关
- 违规统一提示：`所发布内容含违规信息`
- 本插件独立配置小程序 AppID/AppSecret（不读取微信插件）；后台可测 token / 文本 / 图片

**安装：**

将 `Wxseccheck` 目录复制到迅睿CMS的 `dayrui/App/` 目录下，后台「应用插件」中安装启用后填写小程序凭证。

**目录结构：**

```
Wxseccheck/
├── Config/          # App / Version / Menu / Hooks / Routes
├── Controllers/     # 后台 Controllers/Admin/Config
├── Models/          # Seccheck 检测模型
├── Views/           # config.html
└── Files.txt        # 应用打包文件清单
```

**依赖：**

- 服务器可访问 `api.weixin.qq.com`
- PHP 启用 `curl` + `CURLFile`（图片检测）；建议启用 GD（缩放/压缩）
- 在插件后台配置小程序 AppID / AppSecret

**版本：** 1.0
