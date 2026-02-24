# Phase 0 风格取证与目标约束（可执行参数表）

> 范围：仅定义视觉参数与验收点，不改动 Woo 页面、不改现有主题配置。目标为“仿外观气质”，并与 `docs/design/tokens.json` 保持一致。

## A. Typography

| 参数 | 目标值 | 使用点/约束 |
|---|---|---|
| font-family.sans | `Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif` | 全站正文、导航、按钮、表单 |
| font-weight.light | `200` | caption/辅助文案（谨慎使用） |
| font-weight.regular | `400` | body 默认 |
| font-weight.medium | `500` | 表头、按钮次级强调 |
| font-weight.semibold | `600` | 卡片标题/小节标题 |
| font-weight.bold | `700` | h1、关键标题 |
| h1 | `clamp(2.15rem, 4.8vw, 3rem)` | 首页主视觉标题 |
| h2 | `clamp(1.75rem, 3.5vw, 2rem)` | 区块标题 |
| h3 | `clamp(1.125rem, 2.4vw, 1.375rem)` | 卡片标题 |
| h4 | `clamp(1rem, 2vw, 1.125rem)` | 次级标题 |
| h5/h6 | `0.875rem` | 元信息、标签 |
| body | `1rem` | 正文 |
| caption | `0.875rem` | 注释/说明 |
| line-height.heading | `1.125` | h1-h6 |
| line-height.body | `1.6` | 正文段落 |
| line-height.caption | `1.4` | 小字 |
| letter-spacing.heading | `-0.1px` | 标题 |
| letter-spacing.h5 | `0.5px` | h5 |
| letter-spacing.h6 | `1.4px` | h6 + uppercase |

**验收清单**
- [ ] 全站主字体为 Inter，未回退至 serif。
- [ ] 标题层级（h1-h6）与 body/caption 尺寸符合 tokens。
- [ ] 正文行高稳定在 1.6，标题行高为 1.125。
- [ ] h6 全大写且字距显著高于正文。

## B. Color

| 参数 | 目标值 | 用途 |
|---|---|---|
| text | `#111111` | 主文本 |
| bg | `#FFFFFF` | 页面背景 |
| surface | `#FBFAF3` | 卡片/浅底区 |
| muted | `#686868` | 次要文本 |
| border | `#D8D5CF` | 分割线/输入框/卡片边框 |
| accent | `#111111` | 主按钮底色 |
| accentHover | `color-mix(in srgb, #111111 85%, transparent)` | hover 背景 |
| focus | `#686868` | focus ring |

**中性色阶（≥8 阶）**

| 阶 | 值 |
|---|---|
| 0 | `#FFFFFF` |
| 50 | `#FBFAF8` |
| 100 | `#F5F4F1` |
| 200 | `#E9E7E2` |
| 300 | `#D8D5CF` |
| 400 | `#B7B3AA` |
| 500 | `#8D897F` |
| 600 | `#68645C` |
| 700 | `#4A463F` |
| 800 | `#2D2A26` |
| 900 | `#111111` |

**验收清单**
- [ ] 页面主背景与文本对比明确（浅底深字）。
- [ ] 所有组件边框均使用统一 1px + border 色。
- [ ] hover/focus 色来自语义色，不临时硬编码随机色。
- [ ] 中性色阶可覆盖背景、边框、分割、弱文案四类场景。

## C. Layout

| 参数 | 目标值 | 说明 |
|---|---|---|
| content width | `645px` | 阅读列宽 |
| wide width | `1340px` | 宽版区块 |
| container max | `1200px` | 站点容器最大宽度 |
| container padding | `clamp(1rem, 2vw, 2rem)` | 左右安全边距 |
| section spacing | `clamp(2.5rem, 5vw, 5rem)` | 常规区块上下间距 |
| section tight | `clamp(1.5rem, 3vw, 3rem)` | 紧凑区块 |
| grid/card gap sm | `20px` | 双列/卡片小间距 |
| grid/card gap md | `30px` | 默认卡片间距 |
| grid/card gap lg | `clamp(30px, 5vw, 50px)` | 大屏拉开 |

**验收清单**
- [ ] 内容区与宽区遵循 645/1340 双轨。
- [ ] 任一页面容器左右留白在 1rem~2rem 范围自适应。
- [ ] 区块垂直节奏仅使用 section 或 sectionTight。
- [ ] 栅格/卡片间距不小于 20px。

## D. Components（参数矩阵）

### Button

| 状态 | 视觉参数 | 尺寸 |
|---|---|---|
| default | 实底 `accent`，文字 `bg`，边框 `1px transparent` | `py 1rem / px 2.25rem / radius 999px` |
| hover | 背景 `accentHover`，无下划线 | 同 default |
| focus | `2px` offset 的可见 outline（`focus`） | 同 default |
| disabled | 降低对比与可点击感（opacity ≤ 0.5） | 同 default |

### Card

| 状态 | 视觉参数 | 尺寸 |
|---|---|---|
| default | `1px` 边框 + `border`，背景 `surface` | `radius 16px`，`padding 20~30px` |
| hover | 边框加深到 neutral-400 或轻阴影 | 保持尺寸不跳动 |
| focus-within | 显示克制 focus ring | 不改变布局 |
| disabled | 降低文本/边框对比 | 保持结构 |

### Divider

| 状态 | 视觉参数 | 尺寸 |
|---|---|---|
| default | `1px` 实线，颜色 `border` | 上下间距 `20~30px` |
| hover/focus | 无特殊动画 | 保持 1px |

### Table

| 状态 | 视觉参数 | 尺寸 |
|---|---|---|
| default | `th` semibold，`td` regular，边框 `1px border` | cell padding `12px 16px` |
| hover (row optional) | 行背景可用 neutral-50 | 不改列宽策略 |
| focus | 单元格可见 focus 样式（键盘可达） | 不抖动 |
| disabled | 仅用于不可编辑单元（降对比） | - |

### Form Inputs

| 状态 | 视觉参数 | 尺寸 |
|---|---|---|
| default | `1px border`，文字 text，placeholder muted | 高度 `44px`，`radius 12px`，`px 12~16px` |
| hover | 边框加深到 neutral-400 | 高度不变 |
| focus | 可见 focus ring + 边框加深 | 高度不变 |
| disabled | 背景 neutral-100，文字 muted | 保留可读性 |

**验收清单**
- [ ] Button/Card/Divider/Table/Input 都有 default/hover/focus/disabled 定义。
- [ ] 所有边框厚度统一 1px。
- [ ] 交互态不导致布局跳动（无尺寸突变）。
- [ ] 表单控件高度统一为 44px 基线。

## E. Interaction（行为规则与验收点）

| 交互域 | 行为规则 | 验收点 |
|---|---|---|
| hover / focus / active | hover 仅改变颜色/阴影；focus 必须可见且克制；active 可轻微加深色值 | 键盘 Tab 可追踪焦点；无闪烁与跳动 |
| sticky / header | Header 可 sticky（若启用），滚动后保持轻量边框或浅阴影分层 | 吸顶后导航可读，不遮挡首屏主体 |
| accordion | 点击标题展开/收起；支持键盘 Enter/Space；展开区有平滑高度过渡（≤200ms） | 单项/多项展开策略明确；aria-expanded 状态同步 |

**验收清单**
- [ ] 键盘导航能完整走通可交互元素。
- [ ] focus 样式在浅底/深字下可见。
- [ ] sticky header 启用时不破坏内容可见区域。
- [ ] accordion 的可访问属性（aria）与视觉状态一致。
