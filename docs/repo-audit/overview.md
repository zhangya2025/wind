# Repo Audit Overview

## 1) 顶层结构（关键目录）

```text
.
├── docs/
│   ├── warehouse-mechanism.md
│   └── repo-audit/               # 本次新增
├── wp-admin/                     # WordPress Core
├── wp-includes/                  # WordPress Core
├── wp-content/
│   ├── plugins/
│   │   ├── wind-warehouse/       # 自研业务插件（仓库/防伪）
│   │   ├── windhard-core/        # 自研基础安全/本地化
│   │   ├── windhard-maintenance/ # 自研维护模式
│   │   ├── windhard-safe/        # 自研私有登录入口保护
│   │   └── (akismet/classic-editor/wps-hide-login)
│   └── themes/
│       ├── twentytwentyfive/
│       ├── twentytwentyfour/
│       ├── twentytwentythree/
│       └── twentytwentytwo/
├── wordpress/                    # 一套额外的 WordPress 目录副本
├── index.php
├── wp-*.php                      # 根目录可见完整 WP 入口文件
└── wp-config-codex.php           # 非标准命名配置文件
```

## 2) WordPress 代码所在位置判断

- 仓库根目录存在完整 `wp-admin/`、`wp-includes/`、`wp-content/` 与 `wp-*.php`，可判断**主工作区是“完整 WordPress 项目”**，并非仅 `wp-content` 子仓。  
- 同时存在 `wordpress/` 子目录且内含另一套 WP 核心文件，当前仓库呈现“双副本”结构；从根目录 `index.php -> /wp-blog-header.php` 的加载关系看，默认入口更偏向根目录这套。  

## 3) 当前主入口主题/运行方式判断

- `wp-content/themes` 仅包含 `twentytwentytwo~five` 四个官方默认主题，且均含 `theme.json`、`templates/*.html`、`parts/*.html`，属于 **Block Theme / FSE 路线**。  
- 仓库里没有自研主题目录；最近提交记录主要集中在 `wind-warehouse` 插件（非主题），说明当前业务功能前端主要由插件短代码页面和插件样式承载。  
- 由于仓库不含可直接确认“当前激活主题”的数据库快照/环境配置，本审计只能给出**高置信候选**：运行时很可能使用某个默认块主题（优先怀疑 `twentytwentyfive`）。

## 4) 面向“仿 seatosummit.com 外观（字体+布局）”的落地结论

**建议：优先采用 `child-theme`（基于当前激活的 block theme）而不是直接改现有主题或新起全新主题。**

理由：
1. 现有主题均为 FSE + `theme.json`，做字体、版式、间距 token 对齐时，子主题最容易复用现有模板与块能力，改动面最小。  
2. 仓库业务强耦合点在插件（尤其 `wind-warehouse`）而非主题；子主题可先统一站点壳层视觉，再按需覆写插件样式，不会破坏插件功能迭代节奏。  
3. 直接改父主题（官方默认主题）会在未来 WP/主题升级时增加冲突；新建全新主题虽然自由度高，但一次性重建模板成本大、返工风险更高。  

