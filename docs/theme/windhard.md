# Windhard 主题说明

## 1. 来源与定位

- 主题目录：`wp-content/themes/windhard/`
- 主题类型：**独立维护的 Block Theme（非 child-theme）**
- Fork 来源：`twentytwentyfive`（基于仓库审计中“最可能在用主题”的候选判断）
- 目标：
  1. 保持与现有默认主题模板结构兼容，降低未来切换启用风险。
  2. 建立后续“仿 seatosummit.com 外观”的统一落点（tokens + base.css），先搭框架，不做重视觉重构。

## 2. 当前提供的模板覆盖范围

已保留 block theme 关键结构，确保启用后基础页面可渲染：

- `templates/`: `index.html`, `home.html`, `single.html`, `archive.html`, `page.html`, `search.html`, `404.html` 等
- `parts/`: `header.html`, `footer.html` 等
- `patterns/` 与 `styles/`：从 fork 起点同步，保证初始模板/样式兼容性

## 3. 已建立的样式入口与 token 落点

- `theme.json`
  - 保留 block theme 全局样式机制
  - 新增 `settings.custom.windhard.*` 作为后续自有 token 命名空间占位
- `assets/css/base.css`
  - `:root` 级别 CSS variables（字体、颜色、container、section spacing、按钮圆角）
  - `.container`
  - `.section` / `.section--tight`
  - 基础按钮样式入口（`.button`, `.wp-block-button__link`, `button` 等）
- `functions.php`
  - 显式 enqueue `style.css` 与 `assets/css/base.css`

## 4. 测试环境启用方法

> 本 PR 不自动切换主题，仅提供可启用主题。

1. 进入测试站 WordPress 后台 → 外观 → 主题。
2. 找到 `Windhard` 主题并点击“启用”。
3. 快速验证页面：
   - 首页 / 文章页 / 页面 / 搜索 / 404
   - 检查头部、底部、正文是否正常渲染
   - 检查按钮与基础间距是否有统一样式入口

## 5. 回滚方式

- 若测试结果不符合预期：
  1. 后台切回原主题（当前线上使用主题）
  2. 清理页面缓存/CDN 缓存
  3. 复测首页与关键业务页面

该回滚不涉及数据库迁移，也不修改插件业务逻辑。

