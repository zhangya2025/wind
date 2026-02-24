# Build & Deploy Audit

## 1) 构建清单存在性

- 根目录 `composer.json`：**无**
- Node 构建清单：**有（仅主题局部）**
  - `wp-content/themes/twentytwentyfive/package.json`
  - `wp-content/themes/twentytwentytwo/package.json`
  - 对应 lock：`package-lock.json`
- `pnpm-lock.yaml` / `yarn.lock`：未发现
- GitHub Actions（`.github/workflows`）：**未发现**

## 2) 构建方式与产物判断

### 主题层
- `twentytwentyfive` 与 `twentytwentytwo` 使用 PostCSS + cssnano：
  - `npm run build` -> `style.css` 压缩生成 `style.min.css`
- 其余默认主题（`twentytwentythree/four`）未见本地构建脚本，依赖已存在静态文件。

### 插件层
- 自研插件目录未见 `package.json` 或前端打包产物目录（如 `dist`），当前前端资产是**直接提交的静态 CSS/JS**。
- PHP 侧也未见项目级 Composer 依赖安装流程。

### 上线是否“必须先 build”
- 对当前仓库主业务（自研插件）而言：**不是必须**（代码即产物）。
- 若变更 `twentytwentyfive/twentytwentytwo` 的 `style.css` 并希望同步压缩文件：**建议执行 theme 内 `npm run build`** 以更新 `style.min.css`。

## 3) 部署方式线索（非敏感）

- 仓库中未见自动化 CI/CD workflow、deploy shell、rsync/scp 脚本。
- 结合项目形态（完整 WP 文件直接入库），更像“代码仓库 -> 人工/外部流程同步到服务器”的模式。
- 文档中未发现可验证的标准化发布流水线说明；建议后续补一份无敏感信息的发布 SOP（例如：拉取代码、缓存清理、健康检查、回滚步骤）。

## 4) 可选扫描脚本（开发用途）

- 新增：`tools/repo-audit/scan.sh`（只读扫描）
- 作用：
  - 输出主题/插件列表
  - 输出关键 WP API 使用次数
  - 输出 build/deploy 指标存在性
- 运行方式：

```bash
bash tools/repo-audit/scan.sh
```

