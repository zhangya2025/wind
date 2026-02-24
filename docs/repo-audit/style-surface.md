# Style Surface Audit

## 1) 样式入口定位

### 主题样式入口
- `wp-content/themes/twentytwentyfive/style.css`（有 `style.min.css` 压缩产物）
- `wp-content/themes/twentytwentyfour/style.css`
- `wp-content/themes/twentytwentythree/style.css`
- `wp-content/themes/twentytwentytwo/style.css`（有 `style.min.css`）
- 四个主题均有 `theme.json`，全局样式大量经由 WP Global Styles 注入。

### 插件样式入口（业务相关）
- `wp-content/plugins/wind-warehouse/assets/ww-app.css`（仓库/防伪页面主样式）
- `wp-content/plugins/windhard-maintenance/public/maintenance-template.php`（内嵌维护页样式）

### 是否存在 SASS/Tailwind/PostCSS
- SASS/SCSS：未发现自研样式源文件
- Tailwind：未发现 `tailwind.config.*` 与 Tailwind 构建链
- PostCSS：仅在两个默认主题包中发现（`twentytwentyfive`、`twentytwentytwo`）

## 2) 现有排版系统痕迹

### 字体加载方式
- 主题层：以 `theme.json` 的 `fontFamilies/fontFace` + 本地 `assets/fonts` 为主。
- 插件层：`wind-warehouse` 默认 `font-family: "Helvetica Neue", Arial, sans-serif;`。
- 全局过滤：`windhard-core` 通过 `style_loader_src` 过滤掉 `fonts.googleapis.com`，意味着线上外链 Google Fonts 可能被主动禁用。

### 字体大小体系
- 主题层：`theme.json` 内已有 `fontSizes` 体系（WP preset token）。
- 业务插件层：`wind-warehouse` 已有 6 级字号变量：`--ww-font-l1`~`--ww-font-l6` + `--ww-line-height`，由后台设置生成。

### container 宽度、断点、网格
- 主题层：`theme.json` 明确定义 `layout.contentSize/wideSize`（不同主题不同值）。
- 插件层：`ww-app` 使用 `flex` 布局（`ww-shell/ww-sidebar/ww-main`），但未形成全站统一 container/栅格系统。
- 断点：仓库中未见明确统一断点 token 体系（更多是主题默认 + 局部样式）。

### 按钮/卡片/表单统一程度
- 全站层（跨主题+插件）未见统一 Design System 文档。
- `wind-warehouse` 插件内部已统一按钮/卡片/表格/表单样式（`.ww-btn/.ww-card/.ww-input` 等），但作用域限定在 `.ww-app`。

## 3) 对齐 seatosummit.com 外观的“最小改动路径”建议

1. **先做主题层 token 对齐（建议 child theme）**
   - 在子主题 `theme.json` 中先统一：字体族、字号 scale、spacing scale、content/wide 宽度、按钮基础样式。
   - 优先利用 `settings.typography`、`settings.spacing`、`styles.elements.button`、`styles.blocks`，减少散落 CSS 覆盖。

2. **再做插件层桥接**
   - 保留 `wind-warehouse` 现有 `--ww-font-*` 机制，新增“映射层”：让 `--ww-font-*` 对齐到主题 token（例如通过后台默认值或 CSS var fallback）。
   - 将 `ww-app.css` 内颜色/圆角/阴影提取为少量变量（如 `--ww-color-primary`、`--ww-radius-sm`），与主题 token 对齐。

3. **避免大改模板的渐进实施**
   - 第一阶段仅改 token 与通用块样式，不动业务模板结构。
   - 第二阶段针对关键页面（首页、内容页、仓库页）做局部布局覆写，逐步逼近目标站视觉。

## 4) 有/无 tokens 两种策略

- 若沿用现有 tokens（推荐）：
  - 主题用 `theme.json` token；插件继续用 `--ww-*`，并建立映射关系。
- 若认为 tokens 不足：
  - 全站 token 首选放在子主题 `theme.json`（站点级）
  - 业务子系统 token 放在 `ww-app` 作用域（插件级），避免污染全局。

