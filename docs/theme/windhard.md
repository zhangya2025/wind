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


## 6. Header 基线（公告条 + 导航间距 + sticky）

### 6.1 实际生效模板来源

- 当前所有核心模板（`index/home/single/page/archive/search/404`）都通过 `<!-- wp:template-part {"slug":"header"} /-->` 引用同一个 header template part。
- 实际生效文件为：`wp-content/themes/windhard/parts/header.html`。
- 本次仅改该实际生效 header 模板，不触碰 `vertical-header`、`header-large-title` 等备用 part。

### 6.2 两层结构

Header 统一为 `.wh-header`，拆分为两层：

1. **Top Bar（公告条）**
   - 容器：`.wh-header__top > .wh-container.wh-header__top-inner`
   - 内容：左侧公告文案，右侧辅助链接（Shipping / Support）
   - 视觉：紧凑高度（38px 基线，移动端 36px），small 字号
   - 开关：默认显示；在站点编辑器中给该 Group 增加 `is-hidden` class 即可隐藏

2. **Main Header（主导航）**
   - 容器：`.wh-header__main > .wh-container.wh-header__main-inner`
   - 左：Logo + 站点标题
   - 中：`core/navigation`
   - 右：动作区（当前为 Contact 按钮）
   - 间距：继承 `.wh-container`（`--wh-container-pad` + `--wh-container-max`）

### 6.3 Sticky 策略

- 断点：`960px`
- 桌面（`>=960px`）：
  - `.wh-header` 使用 `position: sticky; top: 0; z-index: 30;`
  - 保持固定高度节奏，不在 sticky 状态切换 padding/字号
  - 底部保留 `1px` 分割线，避免与正文粘连
- 移动端（`<960px`）：
  - 仍保持 sticky
  - 缩小主栏 vertical padding（12px）和 top bar 高度（36px）
  - 使用 WordPress Navigation 默认 overlay/collapse 行为，不引入自定义菜单动画

- 防遮挡：为 header 后第一个区块提供 `scroll-margin-top` 基线，避免锚点/首屏内容被 sticky header 视觉压住。

### 6.4 可访问性与作用域

- Header 相关样式均限定在 `.wh-header*` 作用域，避免影响正文和后台。
- 公告条默认显示；在 Site Editor 给 Top Bar Group 添加 `is-hidden` class 可隐藏。
- 链接 hover/focus 保持可感知反馈；不移除全局 focus ring。
