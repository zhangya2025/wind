# Plugins Audit

## 1) 插件清单

### 自研插件（重点）
- wind-warehouse
- windhard-core
- windhard-maintenance
- windhard-safe

### 关键第三方插件（仅名称）
- Akismet
- Classic Editor
- WPS Hide Login

---

## 2) 自研插件能力拆解

### A. wind-warehouse
- 目的（一句话）：提供仓库管理 + 防伪查询业务能力（页面、数据表、角色能力、后台配置）。
- 提供功能：
  - Shortcodes：`wind_warehouse_portal`、`wind_warehouse_query`
  - 后台设置页：有（`add_menu_page` / `add_submenu_page`）
  - 自定义角色/能力：有（`warehouse_staff` / `warehouse_manager` / `dealer_user`）
  - Hook：大量 `add_action` / `add_filter`（激活、登录跳转、后台隔离、资源加载）
  - CPT/Taxonomy：未发现
  - 区块注册（`register_block_type`）：未发现
  - REST endpoint（`register_rest_route`）：未发现
- 关键入口与核心文件：
  - 入口：`wp-content/plugins/wind-warehouse/wind-warehouse.php`
  - 核心：
    - `includes/class-wind-warehouse-plugin.php`
    - `includes/class-wind-warehouse-portal.php`
    - `includes/class-wind-warehouse-query.php`
    - `includes/class-wind-warehouse-settings.php`
    - `includes/db/class-wind-warehouse-schema.php`
- 前端耦合点：
  - shortcode 直接输出 HTML 结构（`render_shortcode`）
  - `wp_enqueue_style/wp_enqueue_script` 加载 `assets/ww-app.css` 与若干交互脚本
  - 通过 `build_css_vars()` 注入 `--ww-font-l*` / `--ww-line-height` 变量

### B. windhard-core
- 目的（一句话）：做本地化与基础安全/性能“瘦身”策略（禁 emoji、禁外部字体等）。
- 提供功能：
  - Hook：`init` 内做若干 `remove_action` / `add_filter`
  - 模块机制：自动加载 `modules/*.php`
  - CPT/Taxonomy、Shortcodes、Blocks、REST、后台设置页：未发现
- 关键入口与核心文件：
  - `wp-content/plugins/windhard-core/windhard-core.php`
  - `wp-content/plugins/windhard-core/modules/test-module.php`
- 前端耦合点：
  - 通过 `style_loader_src` 过滤器禁用 `fonts.googleapis.com` 外链字体

### C. windhard-maintenance
- 目的（一句话）：提供可配置维护模式（范围、角色、文案、状态码）
- 提供功能：
  - 后台设置页：有（`add_options_page` + Settings API）
  - Hook：`plugins_loaded` 初始化，前台 Guard 拦截
  - 维护页面模板：有（`public/maintenance-template.php`）
  - CPT/Taxonomy、Shortcodes、Blocks、REST：未发现
- 关键入口与核心文件：
  - 入口：`wp-content/plugins/windhard-maintenance/windhard-maintenance.php`
  - 核心：
    - `includes/class-windhard-maintenance.php`
    - `includes/class-windhard-maintenance-admin.php`
    - `includes/class-windhard-maintenance-guard.php`
- 前端耦合点：
  - 维护页模板输出 HTML/CSS（含 `:root` CSS 变量）
  - 维护模式开启后影响前台可访问路径与状态码策略

### D. windhard-safe
- 目的（一句话）：强制私有登录入口 `windlogin.php`，并在请求级别守卫登录/后台访问。
- 提供功能：
  - Hook/Filter：`plugins_loaded`、`init`、`template_redirect`、`login_url/site_url/wp_redirect` 等大量 URL 重写
  - 自定义路由行为：将登录相关 URL 重写到私有入口
  - CPT/Taxonomy、Shortcodes、Blocks、REST、后台设置页：未发现
- 关键入口与核心文件：
  - `wp-content/plugins/windhard-safe/windhard-safe.php`
- 前端耦合点：
  - 登录与登出 URL 重写会影响站点头部登录链接、跳转地址等展示行为

---

## 3) 全仓库关键 API 检索统计（扫描范围：`wp-content/`）

- `register_post_type(`：0
- `register_taxonomy(`：0
- `add_shortcode(`：2
- `register_block_type(`：0
- `wp_enqueue_style(`：8
- `wp_enqueue_script(`：9
- `register_rest_route(`：6
- `add_rewrite_rule(`：0

> 说明：`register_rest_route` 主要来自第三方插件（如 Akismet），自研 `wind-*` 插件中未检出该调用。

## 4) 与后续开发直接相关的观察

1. 当前业务扩展几乎都在插件层完成，且 `wind-warehouse` 已内建一套可配置字号 token（`--ww-font-l*`），可作为“业务子系统样式基座”。
2. 未发现自研 CPT/Taxonomy/Block 注册，说明内容建模仍偏“页面 + shortcode + 自定义表”路线。
3. 登录链路已被 `windhard-safe` 深度改写，后续新增前台入口时要避免触碰登录 URL 相关 filter，避免耦合风险。

