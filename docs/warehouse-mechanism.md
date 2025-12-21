# 阶段一机制论证文档（防伪 + 仓库操作系统）

## 1. 总体架构方案
- **数据层**：
  - 以 7 张业务表承载 SKU、经销商、批次、编码、出库、出库明细、审计事件数据；所有写操作通过预处理语句进行，集中封装于单独的 data access 类。
- **服务层**：
  - SKU 管理服务（新增/编辑/停用）。
  - 经销商服务（新增/编辑/停用/启用）。
  - 防伪码生成服务（批次生成、唯一/校验位生成、幂等重试）。
  - 出库服务（扫码临时清单、提交绑定经销商、幂等校验）。
  - 查询服务（消费者 /verify，A/B 计数、10 分钟 IP 去重、B 清零逻辑）。
  - 报表导出服务（按 SKU 月度/年度汇总，UTF-8 BOM CSV）。
  - 审计服务（统一写入 events，记录 before/after 与 counted 标记）。
- **路由层**：
  - 在 WP 已登录态前提下监听 /warehouse 入口，内部子路由区分主数据、批次生成、出库、查询设置、报表、监控等功能页面。
- **页面渲染层**：
  - 复用 WP page/template 机制或 template loader 输出页面，前后端交互通过 WP Ajax/REST（限授权用户）。
- **导出层**：
  - 专用导出端点按筛选条件生成 CSV，强制 BOM，限定具有 wh_view_reports 能力的登录用户。
- **审计事件层**：
  - 所有关键操作（生成、出库、清零、消费者查询）统一写入 events 表，附带 IP、用户、请求摘要、before/after、counted。
- **关键数据流**：
  - **生成流程**：授权用户提交批次参数 → 校验 SKU/VAR → 生成唯一编码与校验位 → 批量写入 codes，记录批次与事件。
  - **出库流程**：授权用户扫码加入临时清单 → 校验编码状态/重复 → 提交时开启事务或等价补偿，写 shipments + shipment_items 并更新 codes 绑定 dealer → 记录事件。
  - **消费者查询流程**：/verify 读取 code → 校验存在/状态 → 基于 events 去重计数 → 计算 A/B 展示 → 记录事件（counted 标记）。

## 2. 登录链路零触碰证明
- **监听 hooks 与顺序（仅在插件自身作用域内）**：
  1. `register_activation_hook` / `register_deactivation_hook`：仅处理 schema_version、建表或清理缓存，不注册任何登录相关过滤。
  2. `plugins_loaded`：早期仅加载常量、类、版本升级入口，不添加任何与登录、重写、cookie 相关逻辑。
  3. `wp_loaded` 或更晚：仅在确定请求指向 /warehouse 路径时启动路由，不处理私有登录入口、/wp-login.php、/wp-admin/；未命中 /warehouse 时直接返回，不影响其他路径。
  4. `admin_init`：仅在“已登录态且角色匹配”时用于将仓库角色（warehouse_staff / warehouse_manager / dealer_user）从 wp-admin 某些入口引导回 /warehouse；不为其在 wp-admin 添加菜单，站点管理员如需可保留原生后台入口。
  5. 可选 `login_redirect`：若启用，仅在角色具备 wh_view_portal 时提供默认跳转至 /warehouse；未匹配条件则尊重核心默认流程，且不改变 wp-admin 入口可用性。
- **零影响论证**：
  - 不在任何时机拦截或改写现有私有登录入口，请求落点与 CSS/JS/错误处理均由核心 wp-login.php 负责，插件仅在登录成功后提供额外路由。
  - 未登录访问 /warehouse/* 直接返回 404/403，不做跳转或登录提示，避免与 windhard-safe 的登录委派链路发生任何耦合。
  - 不改变 /wp-login.php 未登录即 404 的事实；所有逻辑在 wp_loaded 之后且仅针对 /warehouse 前缀，遇到登录相关路径或 /wp-admin/ 时直接返回。
  - 不读取或改写 auth cookie，不使用 login_url、wp_redirect 等全局改写；任何跳转都限定在已登录且角色匹配的内部导航。
- **必须排除处理的 URL/请求**：
  - 登录入口相关路径、/wp-login.php、/wp-admin/（含子路径）、核心 Cron/API 路径（/wp-cron.php、/xmlrpc.php、/wp-json/），以及所有静态资源路径（/wp-includes/、/wp-content/）。

### 2.0 本 PR 实际实现点（代码）
- **角色与能力（activation 建立/补齐）**：
  - warehouse_staff：wh_view_portal, wh_ship_codes, wh_view_reports。
  - warehouse_manager：wh_view_portal, wh_manage_skus, wh_manage_dealers, wh_generate_codes, wh_ship_codes, wh_view_reports, wh_reset_consumer_count_internal。
  - dealer_user：wh_view_portal, wh_reset_consumer_count_dealer。
- **schema 维持**：activation 与 plugins_loaded 继续调用 schema 升级入口（wh_schema_version=1.0.1），保留原有建表/升级链路，不因本次角色与跳转实现而绕开。 
- **wp-admin 隔离（admin_init，仅已登录）**：
  - 触发：is_user_logged_in() 且 is_admin()，当前用户角色属于三类仓库/经销商角色且具备 wh_view_portal，且不具备 manage_options。
  - 排除：admin-ajax.php、REST 请求（REST_REQUEST）、Cron（wp_doing_cron）。
  - 动作：跳转 home_url('/warehouse/')，不引用或跳转私有登录入口。
- **登录后默认跳转（login_redirect）**：
  - 若用户角色属于三类仓库/经销商且具备 wh_view_portal 且不具备 manage_options，则强制落点 home_url('/warehouse/')。
  - 若用户具备 manage_options 或未匹配上述角色/能力，则尊重 redirect_to/核心默认。

### 2.1 角色与能力设计（论证，不含代码）
- **角色-能力矩阵**：
  - **warehouse_staff**：wh_view_portal、wh_ship_codes、wh_view_reports。定位为一线仓库操作人员，负责出库与基础报表查看，不具备批次生成或主数据维护权限。
  - **warehouse_manager**：wh_view_portal、wh_manage_skus、wh_manage_dealers、wh_generate_codes、wh_ship_codes、wh_view_reports、wh_reset_consumer_count_internal。覆盖主数据维护、批次生成、运营复核与内部清零，不具备经销商清零以避免跨界权限。
  - **dealer_user**：wh_view_portal、wh_reset_consumer_count_dealer。限定经销商侧最低权限，只能访问门户并对自身关联的码执行 B 清零，不具备出库或生成权限。
  - **站点管理员/技术角色**：保留全部核心 WP 能力，不自动授予 wh_*；通过 WP 原生管理可手动分配所需 wh_*，其默认落点仍是 wp-admin。
- **分配原因**：
  - 将批次生成与出库分离于主数据维护：生成仅限仓库主管（manager），出库仅限仓库侧（staff/manager），经销商不具备生成/出库以防越权。
  - wh_view_reports 给予仓库侧（staff/manager）用于运营监控；内部清零仅授予 manager 以确保高级审批。
  - wh_reset_consumer_count_dealer 仅授予 dealer_user，保证经销商只能作用于自身数据，且不影响 HQ 内部计数。
  - wh_view_portal 作为门户可见性基础能力，三类仓库/经销商角色均包含，以便统一路由判断与登录后落点。

### 2.2 wp-admin 隔离机制论证（仅描述机制，无代码）
- **生命周期与 hook 选择**：
  - 使用 `admin_init`（触发于已登录访问后台时）作为唯一隔离入口，因其晚于身份鉴权且在 wp-admin 载入早期，能读取当前用户与请求上下文，不影响未登录对 wp-admin 的 404 行为。
  - 不在 `template_redirect`、`init` 等全局阶段处理，避免触碰前台或登录链路；禁止在未登录态执行隔离逻辑。
- **精确触发条件**：
  - 当前用户已登录。
  - 当前用户角色/能力包含 wh_view_portal，且属于 warehouse_staff / warehouse_manager / dealer_user 任一。
  - 请求路径在 /wp-admin/ 下（含子路径）。
- **排除条件（不做隔离）**：
  - 未登录访客：交由 windhard-safe 维持 404，不执行任何逻辑，严禁对未登录请求做任何 redirect。
  - 站点管理员或未包含 wh_view_portal 的其他角色：保持原生 wp-admin 行为。
  - AJAX（admin-ajax.php）、REST（/wp-json/）和 Cron 请求：避免破坏后台 API 调用，可按请求类型提前返回不跳转。
- **动作与边界**：
  - 满足触发条件时，将已登录的仓库/经销商角色从 /wp-admin/* 导向 /warehouse/，不在 wp-admin 菜单中添加入口，保持后台界面对其不可见。
  - 不修改、覆盖或侦听任何登录入口或 /wp-login.php；admin_init 仅在已登录访问后台才运行，未登录访问仍由 windhard-safe 返回 404，保证“零触碰登录链路”。不得在任何情况下跳转或引用私有登录入口 URL。
- **验收要点**：
  - 仓库/经销商三角色已登录访问 /wp-admin/ 被引导至 /warehouse/。
  - 管理员/技术角色正常进入 wp-admin，不受影响。
  - 未登录访问 /wp-admin/ 仍为 404，由 windhard-safe 处理；本逻辑未介入且不触发任何 redirect。
  - admin_init 之外的生命周期（如 init/template_redirect）不会出现隔离逻辑，防止对前台或登录链路造成副作用。

### 2.3 登录后默认跳转机制论证（仅描述机制，无代码）
- **候选实现点**：
  - `login_redirect` 过滤器：登录成功且 auth cookie 已写入后决定落点，最贴合“登录后默认跳转”；需要谨慎限定条件以避免覆盖管理员 redirect_to。
  - 备选 `wp_login` 动作：可记录事件或设置一次性标记，但仍需结合 login_redirect 才能改变落点，增加复杂度，因此首选直接使用 login_redirect 并限制作用面。
- **决策规则**：
  - 当用户具备 wh_view_portal 且角色属于仓库/经销商三类（warehouse_staff / warehouse_manager / dealer_user）时：若用户不具备 manage_options（或等价管理员能力），统一强制返回 /warehouse/ 作为落点，覆盖外部 redirect_to（包括空值/默认），确保门户一致性。
  - 当用户具备 manage_options（管理员/技术角色）时：无论是否存在 wh_view_portal，都永远尊重 redirect_to 或核心默认值，保障后台工作流与兼容性。
  - 其他用户（不具备 wh_view_portal）：保持核心处理结果，按 redirect_to 或默认行为落点，不做覆盖。
- **风险点与规避**：
  - **与 windhard-safe 的交互**：login_redirect 仅在核心登录完成后触发，且不依赖私有登录入口的存在；通过严格角色能力判断避免对其他用户的落点改写，从而不影响既有 404/委派链路。
  - **外部 redirect_to 注入**：需在规则中优先尊重管理员与非仓库角色的 redirect_to，避免后台插件或 SSO 场景被覆盖；仓库角色的强制落点仅限 wh_view_portal 用户。
  - **AJAX/REST 登录**：如有特殊登录入口应排除，以防 login_redirect 干扰程序化登录流程；可在判断中检测请求上下文不做覆盖。
- **验收要点**：
  - wh_view_portal 用户登录后默认落点为 /warehouse/。
  - 管理员等非仓库角色的 redirect_to 被保留，例如指向 wp-admin 时按原生落点处理。
  - 登录流程本身（现有私有入口 → 核心 wp-login.php 委派）不被修改，未登录 /wp-login.php 仍由 windhard-safe 处理为 404。

## 3. 仓库门户路由方案（无代码）
- **候选 A：rewrite + template loader**
  - 在 init 或 wp_loaded 后注册 /warehouse/* 重写规则，命中后通过 template_redirect 加载自定义模板。
  - 优点：URL 结构清晰，可脱离 WP 页面；便于后续扩展。
  - 风险：需谨慎确保 rewrite 仅作用于 /warehouse，不触碰已有登录入口或 /wp-login.php；template_redirect 需防止对其他路径误触，增加测试面。
- **候选 B：固定 Page + 内部 router**
  - 创建 slug=warehouse 的固定 Page，使用 page_template 或 the_content filter 根据子路径参数渲染不同模块。
  - 优点：少量 rewrite，靠 WP 路由收敛，风险低；易与权限、菜单集成。
  - 风险：依赖 Page 存在性与 slug 唯一；需要在查询参数中携带子路由信息。
- **不改变既有登录入口行为的保障**：两种方案均仅在命中 /warehouse 后执行逻辑，未登录状态下保持 windhard-safe 的 404 行为，不拦截既有私有登录入口。
- **权限模型**：
  - 未登录：访问 /warehouse/* 一律返回 404 或 403，不做任何跳转、不引用私有登录入口，完全避免触碰登录链路并保持 windhard-safe 既有 404 行为。
  - 无权限：显示 403 级别页面或返回 WP 默认 admin 权限不足提示，不做全局重写。
  - 菜单可见性：仓库入口仅为前端 /warehouse/*，不在 WP Admin Menu 为 wh_view_portal 用户添加入口；如站点管理员需要管理侧入口，可另行添加后台菜单但不作为仓库角色主要入口。
  - wp-admin 边界：仓库角色（warehouse_staff / warehouse_manager / dealer_user）默认不进入 wp-admin；若站点管理员（非仓库角色）需要后台管理，可使用原生 wp-admin 并不影响仓库门户入口。

## 4. 数据库 schema 论证（不含 SQL）
- **wh_skus**：id PK；sku_code UNIQUE；name；status（active/disabled）；spec_json；created_at/updated_at；索引 sku_code、status。
- **wh_dealers**：id PK；dealer_code UNIQUE；name；status（active/disabled）；region；contact；created_at/updated_at；索引 dealer_code、status、region。
- **wh_code_batches**：id PK；batch_no UNIQUE；sku_id FK；variant（VAR）；quantity；created_by；created_at；索引 batch_no、sku_id、created_at。
- **wh_codes**：id PK；batch_id FK；sku_id FK；code UNIQUE；checksum；status（in_stock/shipped/consumed/blocked）；dealer_id FK nullable；shipped_at；consumed_at；internal_query_count；consumer_query_count_lifetime；consumer_query_offset；last_consumer_query_at（可选，如不存储则从 events 汇总）；索引 batch_id、sku_id、dealer_id、status、code（唯一）。
- **wh_shipments**：id PK；dealer_id FK；status（draft/submitted）；created_by；submitted_by；created_at/updated_at；索引 dealer_id、status、created_at。
- **wh_shipment_items**：id PK；shipment_id FK；code_id FK；code UNIQUE；created_at；索引 shipment_id、code（唯一确保单码唯一出库）。
- **wh_events**：id PK；code（可存储用户输入以便 code 不存在时记录）；code_id FK nullable（仅为关联加速，不能替代 code）；event_type（generate/ship/verify/reset_internal/reset_dealer/...）；ip；user_id nullable；counted（bool，表示本事件是否计入 A/B 计数）；meta_before；meta_after；created_at；
  - 索引：可选 (code, ip, created_at) 或 (ip, code, created_at) 复合索引以支持“同 code+ip 在 10 分钟窗口查询”命中，event_type + created_at（报表），code_id + event_type（溯源）。
- **唯一与索引满足性**：codes.code UNIQUE 与 shipment_items.code UNIQUE 防止重复生成/出库；events 的 ip+created_at 组合支持按时间窗口查询，配合 counted 标记实现去重。

## 5. 事务一致性与幂等策略
- **批量生成**：
  - 先生成完整待写入列表后一次性插入；如遇唯一冲突（code），重试生成冲突条目并分批插入；批次与 codes 写入在同一事务或以批次记录存在 + codes 插入幂等校验实现“写入全成或全回滚”。
- **出库提交**：
  - 提交时锁定 shipment 草稿 → 校验临时清单 codes 状态为 in_stock → 在单事务内写 shipments（状态切换）、shipment_items（唯一 code）、更新 codes.dealer_id/status/shipped_at；如环境不支持事务，采用“状态字段 + 审计事件回滚”补偿方案。
- **清零**：
  - 内部/经销商清零时记录 before/after 到 events.meta 并标记 reset 类型；若中途失败，通过 events 重放或对比 before/after 进行修复。

## 6. 消费者查询与计数逻辑
- **A/B 体系**：
  - consumer_query_count_lifetime 随每次去重后计数的消费者校验递增；consumer_query_offset 存储 B 清零偏移；展示时 B_display = max(0, consumer_query_count_lifetime - consumer_query_offset)。
- **10 分钟同 IP 去重**：
  - 在 verify 请求时查询 events 中最近 10 分钟同 IP + code 的记录，若存在 counted=true 则本次不计数；否则记录一条 counted=true 的 verify 事件。counted=false 的 verify 事件仅在需要记录“未计数原因”或辅助审计时写入。
- **HQ 展示**：
  - codes 未绑定 dealer_id 且状态允许显示“总部渠道”作为 UI 标签，仅在展示层替换，不写入 dealer_id；审计事件可记录“hq_displayed”以便监控。
- **B_display 口径与更新时机**：
  - B_display = max(0, consumer_query_count_lifetime - consumer_query_offset)。每次通过去重校验且 counted=true 的消费者验证请求递增 consumer_query_count_lifetime，同时更新 last_consumer_query_at；consumer_query_offset 仅在内部/经销商清零操作时调整，对应事件记录 before/after。内部查询或后台核查使用 internal_query_count 独立累加，不影响 B_display。

## 7. 安全与合规清单
- 所有表单/操作使用 nonce + 当前用户 capability 校验（按 wh_* 能力细分）。
- 输入均 sanitize/validate（编码长度、SKU/批次存在性、状态合法性）；数据库操作使用 prepare/参数化。
- 事件日志最小充分记录：事件类型、操作者、IP、目标 code/batch/dealer、counted、before/after 摘要、结果状态。
- 明确不做：自动封禁/风控规则仅限日志与可视化监控，不做自动拦截；不改写登录或 auth cookie；不修改风控以外核心配置。

## 8. 阶段二 PR 拆分建议（无代码）
1) **插件骨架与 schema**：注册表结构、activation 升级逻辑、能力常量；验收：表与索引到位，不影响登录。
2) **路由与权限框架**：/warehouse 入口、菜单、能力校验、未登录/无权处理；验收：未登录仍 404，登录可见。
3) **SKU/经销商管理页面**：CRUD 接口与页面渲染；验收：权限隔离、审计事件记录。
4) **防伪码批量生成**：批次创建、码生成、幂等重试；验收：唯一性、事件记录。
5) **出库流程**：扫码清单、提交出库、事务/补偿；验收：状态一致、重复拦截、事件记录。
6) **消费者 /verify**：查询展示、A/B 计数、10 分钟去重、HQ 标签；验收：计数准确、未出库显示总部、日志完备。
7) **清零与报表**：B 清零（内部/经销商）、月度/年度 CSV 导出；验收：before/after 记录、导出格式 BOM。