# Components Spec（Phase 0）

> 目的：约束后续 Codex 的 CSS 修改边界，只做外观统一，不改业务逻辑。

## 1) Button

### 变体
- **solid (primary)**
  - 背景：`semantic.accent`
  - 文字：`semantic.bg`
  - 边框：`1px solid transparent`
- **outline (secondary)**
  - 背景：`transparent`
  - 文字：`semantic.text`
  - 边框：`1px solid semantic.border`
- **text (ghost)**
  - 背景：`transparent`
  - 文字：`semantic.text`
  - 边框：`1px solid transparent`

### 尺寸与排版
- padding：`1rem 2.25rem`
- radius：`999px`
- fontWeight：`500`
- fontSize：`1rem`
- lineHeight：`1.2`

### 交互
- hover：仅颜色变化（solid 变 `accentHover`；outline 边框加深）
- focus：必须有可见 focus ring（`2px` offset）
- disabled：opacity ≤ 0.5，cursor 不可点击

### 不得违反的硬约束
- 边框厚度必须是 `1px`。
- 任何变体都不得出现高度跳动。
- focus 必须可见，且不使用刺眼高饱和荧光色。

---

## 2) Card

### 默认
- 背景：`semantic.surface`
- 边框：`1px solid semantic.border`
- radius：`16px`
- padding：`20px`（密集）~ `30px`（常规）

### 交互
- hover：边框加深（建议到 neutral-400）或轻阴影 `shadow.cardHover`
- focus-within：显示克制的 ring（键盘访问时）
- disabled：降低文本与边框对比

### 不得违反的硬约束
- Card 默认必须有 1px 边框（不可仅靠阴影）。
- hover 不得改变 card 外部尺寸（避免 layout shift）。

---

## 3) Divider / Separator

### 默认
- 线条：`1px` 实线
- 颜色：`semantic.border`
- 间距：上下 `20px ~ 30px`

### 不得违反的硬约束
- 分割线必须保持 1px，不允许渐变假线。
- 不允许把 divider 当作大面积背景装饰元素。

---

## 4) Table

### 结构规范
- `th`：fontWeight `600`
- `td`：fontWeight `400`
- `th/td` padding：`12px 16px`
- vertical-align：`top`
- 行分隔：`1px solid semantic.border`

### 移动端策略
- 表格外层包裹 `overflow-x: auto` 容器。
- 最小可读列宽优先，不在移动端强行压缩到换行破坏对齐。

### 不得违反的硬约束
- 不得取消表头与正文的字重层级。
- 移动端必须可横向滚动，禁止内容溢出裁切。

---

## 5) Form Inputs

### 默认
- 高度：`44px`
- 边框：`1px solid semantic.border`
- radius：`12px`
- padding-inline：`12px ~ 16px`
- 文本色：`semantic.text`
- placeholder：`semantic.muted`

### 交互
- hover：边框加深（neutral-400）
- focus：可见 ring + 边框加深
- disabled：背景 neutral-100，文字 muted，保持可读

### 不得违反的硬约束
- 输入框高度基线必须是 44px。
- focus 样式必须可见（不能只靠微弱颜色差）。
- placeholder 不得比正文更抢眼。

---

## 全局硬约束（适用于所有组件）
- 所有边框厚度统一 `1px`。
- 默认链接样式不加下划线；仅在特定场景（如导航）按需控制。
- 所有可交互元素必须具备可见 focus 态。
- 仅可使用 `docs/design/tokens.json` 中定义的 token，不允许新增随意硬编码值。
