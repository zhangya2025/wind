# Themes Audit

## 1) `wp-content/themes` 主题清单

- twentytwentyfive
- twentytwentyfour
- twentytwentythree
- twentytwentytwo

## 2) 当前可能在用的主题（基于仓库线索）

- **高概率候选：`twentytwentyfive`**（最新默认主题，目录完整且含 `style.min.css` / `package.json`）。
- **不确定性说明**：仓库未提供可安全引用的运行时 `active_theme` 配置来源（如 DB `stylesheet/template` 导出），因此这里只给“可能在用”判断，不做绝对结论。

## 3) 各主题结构与能力

### A. twentytwentyfive
- 类型：**block theme (FSE)**
- `theme.json`：有
- 主要模板文件：
  - `templates/index.html`, `home.html`, `single.html`, `archive.html`, `page.html`, `search.html`, `404.html`
  - `parts/header.html`, `footer.html` 等
- 资源组织：
  - 入口：`style.css`
  - 产物：`style.min.css`
  - 构建：有 `package.json`，脚本为 `postcss style.css --use cssnano -o style.min.css`
- 样式体系：
  - 依赖 `theme.json` preset（color/spacing/typography）与 `--wp--preset--*` 变量
  - 模板里大量使用 `var(--wp--preset--spacing--*)`

### B. twentytwentyfour
- 类型：**block theme (FSE)**
- `theme.json`：有
- 主要模板文件：
  - `templates/index.html`, `home.html`, `single.html`, `archive.html`, `page*.html`, `search.html`, `404.html`
  - `parts/header.html`, `footer.html`, `sidebar.html`
- 资源组织：
  - 入口：`style.css`
  - 无本地构建脚本（目录中无 `package.json`）
- 样式体系：
  - `theme.json` 定义字体家族/字号/spacing 与 layout 宽度
  - 本地字体文件位于 `assets/fonts/`

### C. twentytwentythree
- 类型：**block theme (FSE)**
- `theme.json`：有
- 主要模板文件：
  - `templates/index.html`, `home.html`, `single.html`, `archive.html`, `page.html`, `search.html`, `404.html`
  - `parts/header.html`, `footer.html`, `comments.html`
- 资源组织：
  - 入口：`style.css`
  - 无本地构建脚本（无 `package.json`）
- 样式体系：
  - `theme.json` 提供 font families/font sizes/spacing presets

### D. twentytwentytwo
- 类型：**block theme (FSE)**
- `theme.json`：有
- 主要模板文件：
  - `templates/index.html`, `home.html`, `single.html`, `archive.html`, `page*.html`, `search.html`, `404.html`
  - `parts/header*.html`, `footer.html`
- 资源组织：
  - 入口：`style.css`
  - 产物：`style.min.css`
  - 构建：有 `package.json`，PostCSS + cssnano 生成压缩 CSS
- 样式体系：
  - `theme.json` 含 `custom`、`fontSizes`、`fontFamilies`、`layout` 配置

## 4) 关于 CSS variables / tokens / utility 类

- 主题侧：以 Gutenberg/FSE 体系为主，token 主要由 `theme.json` 生成（`--wp--preset--*`）。
- 仓库中未发现 Tailwind 类 utility 体系；也未见自研全站 `container/section` utility 框架。
- 前台业务页面的“类组件样式”主要在插件 `wind-warehouse/assets/ww-app.css`，使用 `.ww-*` 命名与 `--ww-*` 局部变量。

