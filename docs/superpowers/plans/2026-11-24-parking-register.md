# 停车场登记模块 · 任务清单（Tasks）

> 规格见同目录 `../specs/2026-11-24-parking-register-design.md`。按顺序执行；每步完成后更新勾选。
> 用户要求：二维码由用户自行生成（系统只提供带「小区名+门名」标签的入口 URL）；后台含数据驾驶舱；每批完成及时同步三份文档。

## T1 设计与计划
- [x] 设计定稿（用户批准，含 2 处修改：二维码不渲染、加驾驶舱）。
- [x] 实施计划落档。

## T2 后端数据与配置
- [ ] 迁移 `2026_11_24_000001_create_parking_tables`（parking_gates / parking_registrations）。
- [ ] `ParkingController`：公开 entry/register（校验链 + IP 限频）。
- [ ] `ParkingController`：管理端 gates/config/stats/list/export（权限 + 项目范围强制）。
- [ ] `routes/api.php` 注册公开与管端路由；`routes/web.php` 加 `/parking.html`。
- [ ] `AppModules` 新增 parking 模块权限点。
- 验证：`php -l` 全通过。

## T3 后端测试
- [ ] `tests/Feature/ParkingPublicTest.php`（entry/register 全校验链 + 限频 + 禁用门）。
- [ ] `tests/Feature/ParkingAdminTest.php`（门 CRUD、配置、范围强制、导出）。
- [ ] 全量 PHPUnit 通过且不低于基线。

## T4 司机端 H5
- [ ] `public/parking.html`（照 invoice.html 模式：entry → 表单 → register → 完成屏，顶部显示小区名·门名）。
- [ ] `routes/web.php` 可访问；手机端样式可用。

## T5 管理端 SPA
- [ ] `frontend/src/modules/parking/parkingLogic.js` + `ParkingAdmin.vue`（驾驶舱/登记数据/门与URL/字段设置 4 Tab）。
- [ ] `Icon.vue` 补 `car` 图标；`menus.js` + `router/index.js` 接入（perm parking_view）。
- [ ] `parkingLogic.test.js`；Vitest 全量通过；`npm run build` 通过。

## T6 发布
- [ ] changelog.json v2026.11.24.1 + 开发记录条目，commit + push + CI 绿。
- [ ] 部署生产：快照 → 上传后端文件 + migration → `migrate --force` → 缓存重建 + reload → 冒烟（E2E：建门→entry→register 测试行→清理）。
- [ ] 回填发布记录（开发记录 + 交接文档 §5.14 + 状态行）并推送。

## T7 验收
- [ ] 用户实际扫码验证：生成二维码 → 微信扫码登记 → 后台报表/导出可见。
