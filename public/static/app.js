/* 昊轩云枢 前端 */
"use strict";

const state = { user: null, projects: [], allProjects: [], month: "", page: "", year: "", currentApp: "home" };
let TOKEN = sessionStorage.getItem("gw_token") || "";

function curMonth() {
  const d = new Date();
  return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0");
}
function money(x) {
  const n = Number(x || 0);
  return n.toLocaleString("zh-CN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function pct(x) {
  return (Number(x || 0) * 100).toFixed(2) + "%";
}
function esc(s) {
  return String(s == null ? "" : s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
}

async function api(path, opts = {}) {
  const headers = { "X-Token": TOKEN };
  let body;
  if (opts.form) { body = opts.form; }
  else if (opts.body !== undefined) { headers["Content-Type"] = "application/json"; body = JSON.stringify(opts.body); }
  const res = await fetch(path, { method: opts.method || (body ? "POST" : "GET"), headers, body });
  const ctype = res.headers.get("Content-Type") || "";
  if (ctype.includes("application/json")) {
    const data = await res.json();
    if (res.status === 401 && path !== "/api/login") { showLogin(); throw new Error("未登录"); }
    if (!data.ok) throw new Error(data.error || "操作失败");
    return data;
  }
  if (!res.ok) throw new Error("下载失败");
  return await res.blob();
}

async function download(path, fallbackName) {
  // 导出进度提示：生成中 → 完成/失败（blob 到达前用户能感知进度，避免"点了没反应"）
  const tip = document.createElement("div");
  tip.style.cssText = "position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:999;padding:10px 22px;border-radius:6px;font-size:13.5px;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.18);display:flex;gap:9px;align-items:center;background:#1e3a8a";
  tip.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:dlspin .8s linear infinite"></span><span>正在生成导出文件，请稍候…</span>`;
  document.body.appendChild(tip);
  if (!document.getElementById("dlSpin")) {
    const st = document.createElement("style"); st.id = "dlSpin";
    st.textContent = "@keyframes dlspin{to{transform:rotate(360deg)}}";
    document.head.appendChild(st);
  }
  try {
    const blob = await api(path);
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = fallbackName;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    tip.innerHTML = `<span style="font-weight:600">✓ 导出完成，已开始下载</span>`;
    tip.style.background = "#16a34a";
  } catch (e) {
    tip.innerHTML = `<span style="font-weight:600">导出失败：${esc(e.message)}</span>`;
    tip.style.background = "#dc2626";
  }
  setTimeout(() => tip.remove(), 3200);
}

function toast(msg, ok = true) {
  const el = document.createElement("div");
  el.style.cssText = "position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:999;padding:10px 22px;border-radius:6px;font-size:13.5px;box-shadow:0 4px 16px rgba(0,0,0,.15);" +
    (ok ? "background:#16a34a;color:#fff;" : "background:#dc2626;color:#fff;");
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3200);
}

function modal(html, width) {
  const root = document.getElementById("modalRoot");
  root.innerHTML = `<div class="modal-mask" onmousedown="if(event.target===this)closeModal()">
    <div class="modal" ${width ? `style="width:${width}px;position:relative"` : `style="position:relative"`}>
      <div onclick="closeModal()" title="关闭" style="position:absolute;top:10px;right:14px;width:30px;height:30px;line-height:30px;text-align:center;border-radius:50%;background:#f1f3f6;color:#6b7280;font-size:16px;font-weight:600;cursor:pointer;z-index:20;box-shadow:0 0 0 4px #fff">×</div>
      ${html}</div></div>`;
}
function closeModal() { document.getElementById("modalRoot").innerHTML = ""; }

/* ---------------- 登录 ---------------- */
function showLogin() {
  document.getElementById("loginPage").style.display = "flex";
  document.getElementById("app").style.display = "none";
}
async function doLogin() {
  const u = document.getElementById("loginUser").value.trim();
  const p = document.getElementById("loginPass").value;
  const tip = document.getElementById("loginTip");
  if (!u || !p) { tip.textContent = "请输入用户名和密码"; tip.style.color = "#dc2626"; return; }
  tip.textContent = "正在登录...";
  tip.style.color = "#8c8c8c";
  try {
    const data = await api("/api/login", { body: { username: u, password: p } });
    TOKEN = data.token;
    sessionStorage.setItem("gw_token", TOKEN);
    await bootstrap();
  } catch (e) {
    tip.textContent = e.message;
    tip.style.color = "#dc2626";
  }
}
function logout() {
  api("/api/logout").catch(() => {});
  TOKEN = ""; sessionStorage.removeItem("gw_token");
  showLogin();
}
async function bootstrap() {
  try {
    const data = await api("/api/init");
    state.user = data.user;
    state.projects = data.projects;
    state.allProjects = data.all_projects || data.projects.map(n => ({ name: n, status: "启用" }));
    state.permModules = data.perm_modules || [];
    state.appModules = data.app_modules || [];
    state.roles = data.roles || [];
    state.settings = data.settings || {};
    state.month = state.month || curMonth();
    // 聚合当前账号类型的权限点
    const permSet = new Set();
    if (data.user.role === "admin") {
      state.appModules.forEach(m => (m.perms || []).forEach(p => permSet.add(p[0])));
    } else {
      const r = state.roles.find(x => x.id === data.user.role || x.role_key === data.user.role || (x.data && x.data.id === data.user.role));
      const rp = (r && (r.perms || (r.permissions ? JSON.parse(r.permissions) : []))) || [];
      if (rp.includes("*")) {
        state.appModules.forEach(m => (m.perms || []).forEach(p => permSet.add(p[0])));
      } else {
        rp.forEach(p => permSet.add(p));
      }
    }
    state.perms = permSet;
    document.getElementById("loginPage").style.display = "none";
    document.getElementById("app").style.display = "block";
    const roleLabel = data.user.role === "admin" ? "超级管理员" : (data.user.role === "project" ? "项目账号·" + esc(data.user.project) : "只读账号");
    document.getElementById("userLabel").innerHTML =
      `${esc(data.user.name || data.user.username)} <span class="tag ${data.user.role === "admin" ? "purple" : "blue"}">${roleLabel}</span>`;
    renderMenu();
    renderApps();
    loadUnread();
    nav("home");
    setInterval(loadUnread, 120000);
  } catch (e) {
    TOKEN = "";
    sessionStorage.removeItem("gw_token");
    showLogin();
    const tip = document.getElementById("loginTip");
    if (tip) { tip.textContent = "登录未生效：" + e.message + "，请重新登录"; tip.style.color = "#dc2626"; }
  }
}

/* ---------------- 菜单与路由 ---------------- */
const ALL_MENUS = [
  { top: true, fixed: true, always: true, key: "home", label: "工作台主页", icon: "🏠" },
  { section: "采购系统", icon: "📦", app: "purchase", items: [
    { key: "purchaseDashboard", label: "📊 数据驾驶舱", perm: "purchase_view" },
    { key: "purchaseFill", label: "📝 员工填报（代填）", staffLabel: "📝 采购填报", perm: "purchase_view" },
    { key: "purchaseOverview", label: "📋 填报进度确认", perm: "purchase_view", adminOnly: true },
    { key: "purchaseCustoms", label: "🧾 清单外审核", perm: "purchase_view", adminOnly: true },
    { key: "purchaseBudget", label: "💰 预算管理", perm: "purchase_view", adminOnly: true },
    { key: "purchaseSummary", label: "📈 采购汇总查看", perm: "purchase_view", adminOnly: true },
    { key: "purchaseExportImport", label: "📤 导出报价 / 导入存档", perm: "purchase_view", adminOnly: true },
    { key: "purchaseProducts", label: "📦 标准商品库", perm: "purchase_view", adminOnly: true },
    { key: "purchaseWindows", label: "📅 填报窗口设置", perm: "purchase_view", adminOnly: true },
    { key: "purchaseOplogs", label: "📜 操作日志", perm: "purchase_view", adminOnly: true },
    { key: "purchaseMyItems", label: "📋 我的填报记录", perm: "purchase_view", staffOnly: true },
  ]},
  { section: "审批中心", icon: "✅", app: "oa", items: [
    { key: "approvalCenter", label: "📋 审批中心", perm: "" },
  ]},
  { section: "报表中心", icon: "📊", app: "report", items: [
    { key: "reportHome", label: "🏠 报表总览", perm: "hr_report" },
    { key: "hrReport", label: "👥 人力资源报表", perm: "hr_report" },
    { key: "salaryReport", label: "💰 薪酬报表", perm: "salary_report" },
    { key: "attReport", label: "📅 考勤报表", perm: "attendance_report" },
  ]},
  { section: "薪资模块", icon: "💰", app: "hr", items: [
    { key: "summary", label: "📊 汇总展示", perm: "payroll" },
    { key: "payroll", label: "🧮 薪资核算与微调", perm: "payroll" },
    { key: "taxMode", label: "🧮 个税扣除模式设置", perm: "payroll" },
    { key: "export", label: "📤 数据导出", perm: "export" },
  ]},
  { section: "考勤模块", icon: "📅", app: "hr", items: [
    { key: "attendance", label: "📅 考勤管理", perm: "attendance" },
  ]},
  { section: "项目与人事模块", icon: "👥", app: "hr", items: [
    { key: "org", label: "🏢 组织架构", perm: "projects" },
    { key: "staff", label: "👥 人员档案", perm: "staff" },
    { key: "adjust", label: "📝 调薪与记录", perm: "staff" },
  ]},
  { section: "预算模块", icon: "📊", app: "hr", items: [
    { key: "budget", label: "💰 预算管理", perm: "budget" },
  ]},
  { section: "绩效考核", icon: "🎯", app: "hr", items: [
    { key: "perfCreate", label: "📝 发起考核", perm: "perf" },
    { key: "perfApprove", label: "✅ 待我审批", perm: "perf" },
    { key: "perfReport", label: "✍️ 待我填报", perm: "perf" },
    { key: "perfMine", label: "📋 我的考核", perm: "perf" },
    { key: "perfRecords", label: "🗂️ 考核记录管理", perm: "perf_admin" },
  ]},
  { section: "财务管理", icon: "💰", app: "finance", items: [
    { key: "financeDashboard", label: "📊 数据驾驶舱", perm: "finance_view" },
    { key: "financeLedger", label: "📋 应收费用台账", perm: "finance_view" },
    { key: "financePayments", label: "💰 付款记录", perm: "finance_view" },
    { key: "financeSummary", label: "📈 汇总分析", perm: "finance_view" },
    { key: "financeImport", label: "📤 历史数据导入", perm: "finance_admin" },
  ]},
  { section: "工程维保模块", icon: "🔧", app: "maintain", items: [
    { key: "fireReport", label: "🧯 消防维保报表", perm: "maint_fire" },
    { key: "elevReport", label: "🛗 电梯维保报表", perm: "maint_elev" },
    { key: "maintFire", label: "📒 消防维保台账", perm: "maint_fire" },
    { key: "maintElev", label: "📒 电梯维保台账", perm: "maint_elev" },
    { key: "maintPartners", label: "🤝 签约方维护", perm: "maint_partners" },
  ]},
];
function canPerm(mod) { return state.user.role === "admin" || state.perms.has(mod); }
const PAGE_TITLES = {
  home: "工作台主页",
  reportHome: "报表总览", hrReport: "人力资源报表", salaryReport: "薪酬报表", attReport: "考勤报表",
  fireReport: "消防维保报表", elevReport: "电梯维保报表",
  summary: "薪资数据汇总展示", payroll: "薪资核算与人工微调",
  taxMode: "个税扣除模式设置",
  attendance: "考勤管理", export: "多模式数据导出", org: "组织架构",
  staff: "人员档案管理", adjust: "调薪记录与追溯", budget: "项目薪资预算管理",
  maintFire: "消防维保台账", maintElev: "电梯维保台账", maintPartners: "签约方维护",
  perfCreate: "发起绩效考核", perfApprove: "待我审批", perfReport: "待我填报",
  perfMine: "我的考核", perfRecords: "考核记录管理",
  approvalCenter: "审批中心", purchaseEntry: "月度采购系统",
  purchaseDashboard: "数据驾驶舱", purchaseFill: "采购填报",
  purchaseOverview: "填报进度确认", purchaseCustoms: "清单外审核",
  purchaseBudget: "预算管理", purchaseSummary: "采购汇总查看",
  purchaseExportImport: "导出报价 / 导入存档", purchaseProducts: "标准商品库",
  purchaseWindows: "填报窗口设置", purchaseOplogs: "操作日志",
  purchaseMyItems: "我的填报记录",
  financeDashboard: "财务数据驾驶舱", financeLedger: "应收费用台账",
  financePayments: "付款记录", financeSummary: "汇总分析",
  financeImport: "历史数据导入",
  rules: "工资计算规则设置", symbols: "考勤符号库设置",
  users: "系统设置", logs: "系统设置", backup: "系统设置", settings: "系统设置",
};
function renderMenu() {
  const el = document.getElementById("menu");
  let html = "";
  for (const m of ALL_MENUS) {
    // 非固定 section 仅在所属应用模块激活时显示
    if (!m.fixed && m.app && m.app !== state.currentApp) continue;
    if (m.top) {
      if (m.always || canPerm(m.perm)) {
        html += `<div class="menu-item${state.page === m.key ? " active" : ""}" data-page="${m.key}" onclick="nav('${m.key}')"><span class="ms-ico">${m.icon}</span><span class="ms-name">${m.label}</span></div>`;
      }
    } else {
      const items = m.items.filter(it => {
        if (it.perm && !canPerm(it.perm)) return false;
        if (it.adminOnly && state.user.role !== "admin") return false;
        if (it.staffOnly && state.user.role === "admin") return false;
        return true;
      });
      if (!items.length) continue;
      const isActive = items.some(it => it.key === state.page);
      const collapsed = !isActive;
      html += `<div class="menu-section${collapsed ? " collapsed" : ""}" onclick="toggleSection(this)"><span class="ms-ico">${m.icon}</span><span class="ms-name">${m.section}</span><span class="arrow">▾</span></div>`;
      html += `<div class="menu-sub${collapsed ? " collapsed" : ""}">`;
      for (const it of items) {
        const label = (it.staffLabel && state.user.role !== "admin") ? it.staffLabel : it.label;
        html += `<div class="menu-sub-item${state.page === it.key ? " active" : ""}" data-page="${it.key}" onclick="nav('${it.key}')">${label}</div>`;
      }
      html += `</div>`;
    }
  }
  el.innerHTML = html;
}
function toggleSection(el) {
  el.classList.toggle("collapsed");
  const sub = el.nextElementSibling;
  if (sub) sub.classList.toggle("collapsed");
}
const MAINT_PAGES = ["fireReport", "elevReport", "maintFire", "maintElev", "maintPartners"];
const REPORT_PAGES = ["reportHome", "hrReport", "salaryReport", "attReport"];
const PURCHASE_PAGES = ["purchaseDashboard", "purchaseFill", "purchaseOverview", "purchaseCustoms",
  "purchaseBudget", "purchaseSummary", "purchaseExportImport", "purchaseProducts",
  "purchaseWindows", "purchaseOplogs", "purchaseMyItems"];
const FINANCE_PAGES = ["financeDashboard", "financeLedger", "financePayments", "financeSummary",
  "financeImport"];
const HR_PAGES = ["summary", "payroll", "taxMode", "attendance", "org", "staff", "adjust", "budget", "export",
  "perfCreate", "perfApprove", "perfReport", "perfMine", "perfRecords"];
function nav(page) {
  state.page = page;
  // 根据页面自动切换当前应用模块（报表中心优先；系统设置页不改变当前模块）
  if (page === "home") state.currentApp = "home";
  else if (PURCHASE_PAGES.includes(page)) state.currentApp = "purchase";
  else if (FINANCE_PAGES.includes(page)) state.currentApp = "finance";
  else if (REPORT_PAGES.includes(page)) state.currentApp = "report";
  else if (MAINT_PAGES.includes(page)) state.currentApp = "maintain";
  else if (HR_PAGES.includes(page)) state.currentApp = "hr";
  document.getElementById("pageTitle").textContent = PAGE_TITLES[page] || "";
  renderMenu();
  if (typeof renderApps === "function") renderApps();
  refreshPage();
}
/* 顶部九宫格应用启动器 */
const APPS = [
  { key: "hr", name: "人力资源", icon: "👥", color: "#2563eb", current: true },
  { key: "contract", name: "合同管理", icon: "📄", color: "#e74c3c" },
  { key: "admin", name: "行政管理", icon: "🏢", color: "#8b5cf6" },
  { key: "oa", name: "OA审批", icon: "✅", color: "#3b82f6" },
  { key: "property", name: "房产管理", icon: "🏠", color: "#1e40af" },
  { key: "maintain", name: "工程维保", icon: "🔧", color: "#f59e0b" },
  { key: "security", name: "安保管理", icon: "🛡️", color: "#16a34a" },
  { key: "cleaning", name: "保洁绿化", icon: "🧹", color: "#14b8a6" },
  { key: "purchase", name: "采购管理", icon: "📦", color: "#6366f1" },
  { key: "finance", name: "财务管理", icon: "💰", color: "#0d9488" },
  { key: "report", name: "报表中心", icon: "📊", color: "#0ea5e9" },
  { key: "notice", name: "公告通知", icon: "📢", color: "#ef4444" },
  { key: "setting", name: "系统设置", icon: "⚙️", color: "#64748b" },
];
function appEnabled(a) {
  if (state.user && state.user.role === "admin") return true;
  if (a.key === "purchase") return canPerm("purchase_view"); // 采购系统按采购查看权限控制入口
  if (a.key === "finance") return canPerm("finance_view") || canPerm("finance_admin"); // 财务管理按财务权限控制入口
  if (a.key === "report") return canPerm("hr_report") || canPerm("salary_report") || canPerm("attendance_report");
  const md = (state.appModules || []).find(m => m.key === a.key);
  return (md && md.perms || []).some(p => state.perms.has(p[0]));
}
function renderApps() {
  const el = document.getElementById("alGrid");
  if (!el) return;
  el.innerHTML = APPS.map(a => {
    const enabled = appEnabled(a);
    const isCurrent = state.currentApp === a.key || (a.key === "hr" && !state.currentApp);
    return `<div class="al-item${isCurrent ? " current" : ""}${enabled ? "" : " disabled"}" onclick="launchApp('${a.key}')">
      <div class="ai-ico" style="background:${enabled ? a.color : "#94a3b8"}">${a.icon}</div>
      <div class="ai-name">${a.name}</div>
      ${isCurrent ? '<div class="ai-badge">当前</div>' : (enabled ? "" : '<div class="ai-badge lock">🔒 未授权</div>')}
    </div>`;
  }).join("");
}
function launchApp(key) {
  const app = APPS.find(a => a.key === key);
  if (!app) return;
  const launcher = document.getElementById("appLauncher");
  if (launcher) launcher.classList.remove("open");
  if (!appEnabled(app)) { toast("暂无【" + app.name + "】模块权限，请联系管理员", false); return; }
  if (key === "setting") { nav("settings"); return; }
  if (key === "hr" || app.current) { state.currentApp = "hr"; nav("summary"); return; }
  if (key === "report") { state.currentApp = "report"; nav("reportHome"); return; }
  if (key === "maintain") { state.currentApp = "maintain"; nav("fireReport"); return; }
  if (key === "oa") { state.currentApp = "oa"; nav("approvalCenter"); return; }
  if (key === "purchase") { state.currentApp = "purchase"; nav("purchaseDashboard"); return; }
  if (key === "finance") { state.currentApp = "finance"; nav("financeDashboard"); return; }
  toast("【" + app.name + "】模块建设中，敬请期待", false);
}
let _purchasePendingHash = "";
function openPurchase(hash) {
  const tk = TOKEN || sessionStorage.getItem("gw_token") || "";
  const h = (hash && hash.charAt(0) !== "#") ? "#" + hash : (hash || "");
  const c = document.getElementById("content");
  if (!c) return;
  // iframe 已在场：仅切换 hash（采购导航已并入平台左侧栏，切换菜单不重建 iframe）
  const f = document.getElementById("purchaseFrame");
  if (f && f.isConnected && f.contentWindow) {
    try {
      const w = f.contentWindow;
      if (!w.document || w.document.readyState !== "complete") { _purchasePendingHash = h; return; }
      if ((w.location.hash || "") !== h) w.location.hash = h;
    } catch (e) { /* 跨域/异常时忽略 */ }
    return;
  }
  // 首次进入：带时间戳强制加载最新版，并携带目标 hash（通知深链）
  const url = "/purchase/index.html?token=" + encodeURIComponent(tk) + "&v=" + Date.now() + h;
  c.innerHTML = '<div class="card" style="padding:0;overflow:hidden;margin-bottom:0;height:calc(100vh - 125px);display:flex;flex-direction:column;">'
    + '<iframe id="purchaseFrame" src="' + url + '" style="flex:1;width:100%;border:0;min-height:0;" onload="if(_purchasePendingHash){try{document.getElementById(\'purchaseFrame\').contentWindow.location.hash=_purchasePendingHash;}catch(e){}_purchasePendingHash=\'\';}"></iframe></div>';
}
let _financePendingHash = "";
function openFinance(hash) {
  const tk = TOKEN || sessionStorage.getItem("gw_token") || "";
  const h = (hash && hash.charAt(0) !== "#") ? "#" + hash : (hash || "");
  const c = document.getElementById("content");
  if (!c) return;
  const f = document.getElementById("financeFrame");
  if (f && f.isConnected && f.contentWindow) {
    try {
      const w = f.contentWindow;
      if (!w.document || w.document.readyState !== "complete") { _financePendingHash = h; return; }
      if ((w.location.hash || "") !== h) w.location.hash = h;
    } catch (e) { /* 跨域/异常时忽略 */ }
    return;
  }
  const url = "/finance/index.html?token=" + encodeURIComponent(tk) + "&v=" + Date.now() + h;
  c.innerHTML = '<div class="card" style="padding:0;overflow:hidden;margin-bottom:0;height:calc(100vh - 125px);display:flex;flex-direction:column;">'
    + '<iframe id="financeFrame" src="' + url + '" style="flex:1;width:100%;border:0;min-height:0;" onload="if(_financePendingHash){try{document.getElementById(\'financeFrame\').contentWindow.location.hash=_financePendingHash;}catch(e){}_financePendingHash=\'\';}"></iframe></div>';
}
function refreshPage() {
  const fn = { home: pageHome, reportHome: pageReportHome, hrReport: pageHrReport, salaryReport: pageSalaryReport,
    attReport: pageAttReport, fireReport: pageMaintFireReport, elevReport: pageMaintElevReport,
    summary: pageSummary, payroll: pagePayroll, taxMode: pageTaxMode, attendance: pageAttendance,
    export: pageExport, projects: pageProjects, org: pageOrg, staff: pageStaff, adjust: pageAdjust, budget: pageBudget,
    maintFire: () => pageMaintLedger("fire"), maintElev: () => pageMaintLedger("elevator"),
    maintPartners: pageMaintPartners,
    perfCreate: () => pagePerfEditor(0), perfApprove: () => pagePerfList("approve"),
    perfReport: () => pagePerfList("report"), perfMine: () => pagePerfList("mine"),
    perfRecords: () => pagePerfList("all"),
    purchaseDashboard: () => openPurchase("#/dashboard"),
    purchaseFill: () => openPurchase("#/fill"),
    purchaseOverview: () => openPurchase("#/overview"),
    purchaseCustoms: () => openPurchase("#/customs"),
    purchaseBudget: () => openPurchase("#/budget"),
    purchaseSummary: () => openPurchase("#/summary"),
    purchaseExportImport: () => openPurchase("#/export-import"),
    purchaseProducts: () => openPurchase("#/products"),
    purchaseWindows: () => openPurchase("#/windows"),
    purchaseOplogs: () => openPurchase("#/oplogs"),
    purchaseMyItems: () => openPurchase("#/my-items"),
    purchaseEntry: () => { openPurchase(); },
    financeDashboard: () => openFinance("#/dashboard"),
    financeLedger: () => openFinance("#/ledger"),
    financePayments: () => openFinance("#/payments"),
    financeSummary: () => openFinance("#/summary"),
    financeImport: () => openFinance("#/import"),
    approvalCenter: pageApprovalCenter,
    salarySettings: pageSalarySettings,
    users: () => pageSettings("perm"), logs: () => pageSettings("logs"),
    backup: () => pageSettings("backup"), settings: () => pageSettings("perm") }[state.page];
  if (fn) fn();
}
function monthInput() {
  return `<label class="fld">核算月份 <input type="month" value="${state.month}" onchange="state.month=this.value;refreshPage()"></label>`;
}

/* ---------------- 工作台主页 ---------------- */
const HOME_BUILT = new Set(["hr", "maintain", "report", "setting", "oa", "purchase", "finance"]);
const HOME_META = {
  hr: { desc: "薪资核算 · 考勤管理 · 人员档案 · 预算管理 · 绩效考核", go: "进入人力资源 →" },
  maintain: { desc: "消防 / 电梯维保报表、维保台账与签约方维护", go: "进入工程维保 →" },
  report: { desc: "人力资源 · 薪酬 · 考勤三类分析报表总览", go: "进入报表中心 →" },
  setting: { desc: "账号权限 · 数据备份 · 工资规则 · 字段设置", go: "进入系统设置 →" },
  oa: { desc: "录用 / 转正 / 离职审批与入职办理，流程可配置", go: "进入审批中心 →" },
  purchase: { desc: "月度采购计划 · 填报 · 审批确认 · 预算执行 · 汇总导出", go: "进入采购系统 →" },
  finance: { desc: "应收费用台账 · 付款记录 · 汇总分析 · 驾驶舱", go: "进入财务管理 →" },
};
function homeGreeting() {
  const h = new Date().getHours();
  if (h < 5) return "夜深了";
  if (h < 11) return "早上好";
  if (h < 13) return "中午好";
  if (h < 18) return "下午好";
  return "晚上好";
}
function homeToday() {
  const d = new Date();
  const w = ["日", "一", "二", "三", "四", "五", "六"][d.getDay()];
  return `${d.getFullYear()} 年 ${d.getMonth() + 1} 月 ${d.getDate()} 日 · 星期${w}`;
}
function homeSet(id, txt) { const el = document.getElementById(id); if (el) el.textContent = txt; }
async function pageHome() {
  const c = document.getElementById("content");
  const u = state.user || {};
  const roleLabel = u.role === "admin" ? "超级管理员" : (u.role === "project" ? "项目账号 · " + (u.project || "") : "只读账号");
  const dispName = u.name || u.username || "";
  const roleBadge = dispName !== roleLabel ? `<span class="hh-role">${esc(roleLabel)}</span>` : "";
  const appsHtml = APPS.map(a => {
    const built = HOME_BUILT.has(a.key);
    const enabled = built && appEnabled(a);
    const meta = HOME_META[a.key] || {};
    const tag = !built ? '<div class="ha-tag">建设中</div>' : (enabled ? "" : '<div class="ha-tag">未授权</div>');
    const desc = meta.desc || "模块规划中，敬请期待";
    const go = enabled ? (meta.go || "进入 →") : (built ? "暂无访问权限" : "建设中 · 敬请期待");
    return `<div class="home-app${enabled ? "" : " disabled"}" onclick="launchApp('${a.key}')">
      ${tag}
      <div class="ha-head"><div class="ha-ico" style="background:${enabled ? a.color : "#94a3b8"}">${a.icon}</div>
        <div class="ha-name">${esc(a.name)}</div></div>
      <div class="ha-desc">${desc}</div>
      <div class="ha-go" style="color:${enabled ? a.color : "#94a3b8"}">${go}</div>
    </div>`;
  }).join("");
  c.innerHTML = `
  <div class="home-hero">
    <div>
      <div class="hh-hi">${homeGreeting()}，${esc(dispName)}${roleBadge}</div>
      <div class="hh-sub">欢迎使用昊轩云枢，从下方模块或数据速览开始今天的工作。</div>
    </div>
    <div class="hh-date"><b>${homeToday()}</b><br>当前核算月份：${state.month}</div>
  </div>

  <div class="home-sec-title">数据速览</div>
  <div class="home-kpi-row">
    <div class="home-kpi no-link"><div class="hk-top"><span class="hk-ico" style="background:#dbeafe;color:#1d4ed8">👥</span>在职人数</div><div class="hk-num" id="hkActive">…</div><div class="hk-sub">含试用，不含离职</div></div>
    <div class="home-kpi" id="hkGrossCard" onclick="canPerm('payroll')&&nav('summary')"><div class="hk-top"><span class="hk-ico" style="background:#dcfce7;color:#15803d">💰</span>本月应发总额</div><div class="hk-num" id="hkGross">…</div><div class="hk-sub">${state.month} 应发合计 · 点击查看</div></div>
    <div class="home-kpi" id="hkHeadCard" onclick="canPerm('payroll')&&nav('summary')"><div class="hk-top"><span class="hk-ico" style="background:#fef3c7;color:#b45309">🧾</span>本月发放人数</div><div class="hk-num" id="hkHead">…</div><div class="hk-sub">有效考勤人数 · 点击查看</div></div>
    <div class="home-kpi" id="hkApproveCard" onclick="canPerm('perf')&&nav('perfApprove')"><div class="hk-top"><span class="hk-ico" style="background:#ede9fe;color:#6d28d9">✅</span>待我审批</div><div class="hk-num" id="hkApprove">…</div><div class="hk-sub">绩效考核单 · 点击处理</div></div>
    <div class="home-kpi" id="hkMaintCard" onclick="(canPerm('maint_fire')||canPerm('maint_elev'))&&(state.currentApp='maintain',nav('fireReport'))"><div class="hk-top"><span class="hk-ico" style="background:#ffedd5;color:#c2410c">🔧</span>维保在管合同</div><div class="hk-num" id="hkMaint">…</div><div class="hk-sub" id="hkMaintSub">消防 + 电梯 · 点击查看</div></div>
  </div>

  <div class="home-sec-title">我的待办</div>
  <div class="home-todo" id="homeTodo">加载中…</div>

  <div class="home-sec-title">常用模块</div>
  <div class="home-app-grid">${appsHtml}</div>`;
  homeLoadKpi();
  homeLoadTodo();
}
function homeTodoCard(icon, title, num, sub, page, color, goText) {
  const hasNum = num !== undefined && num !== null && num !== "";
  const badge = hasNum ? ` <span style="margin-left:auto;background:${color};color:#fff;font-size:11px;padding:1px 9px;border-radius:11px;font-weight:700">${num}</span>` : "";
  return `<div class="home-kpi" onclick="nav('${page}')">
    <div class="hk-top"><span class="hk-ico" style="background:${color}22;color:${color}">${icon}</span>${title}${badge}</div>
    <div class="hk-sub" style="font-size:12.5px;color:#475569;margin:1px 0 7px">${sub}</div>
    <div class="ha-go" style="color:${color}">${goText || "去处理 →"}</div></div>`;
}
async function homeLoadKpi() {
  // 在职人数（人力资源报表口径）
  if (canPerm("hr_report")) {
    try { const d = await api(`/api/report/hr?year=${state.month.slice(0, 4)}&ym=${state.month}&annual=0`); homeSet("hkActive", d.kpis.active); }
    catch (e) { homeSet("hkActive", "—"); }
  } else { homeSet("hkActive", "—"); }
  // 本月应发 / 发放人数（薪资汇总口径）
  if (canPerm("payroll")) {
    try {
      const d = await api(`/api/summary?ym=${state.month}`);
      homeSet("hkGross", money(d.total.gross));
      homeSet("hkHead", d.total.headcount);
    } catch (e) { homeSet("hkGross", "—"); homeSet("hkHead", "—"); }
  } else {
    homeSet("hkGross", "—"); homeSet("hkHead", "—");
    const gc = document.getElementById("hkGrossCard"), hc = document.getElementById("hkHeadCard");
    if (gc) gc.classList.add("no-link"); if (hc) hc.classList.add("no-link");
  }
  // 待我审批（绩效）
  if (canPerm("perf")) {
    try { const d = await api("/api/performance/plans?scope=approve"); homeSet("hkApprove", (d.plans || []).length); }
    catch (e) { homeSet("hkApprove", "—"); }
  } else { const ac = document.getElementById("hkApproveCard"); if (ac) ac.style.display = "none"; }
  // 维保在管
  if (canPerm("maint_fire") || canPerm("maint_elev")) {
    try {
      const d = await api(`/api/maintenance/dashboard?year=${new Date().getFullYear()}`);
      const f = d.overview.fireCount || 0, e = d.overview.elevatorCount || 0;
      homeSet("hkMaint", "");
      const m = document.getElementById("hkMaint");
      if (m) m.innerHTML = `${f + e}<span class="hk-unit">份</span>`;
      const ms = document.getElementById("hkMaintSub");
      if (ms) ms.textContent = `消防 ${f} · 电梯 ${e}${d.riskTotal ? ` · 临期风险 ${d.riskTotal}` : ""} · 点击查看`;
    } catch (err) { homeSet("hkMaint", "—"); }
  } else { const mc = document.getElementById("hkMaintCard"); if (mc) mc.style.display = "none"; }
}
async function homeLoadTodo() {
  const el = document.getElementById("homeTodo");
  if (!el) return;
  const cards = [];
  if (canPerm("perf")) {
    try { const d = await api("/api/performance/plans?scope=approve"); cards.push(homeTodoCard("✅", "待我审批", (d.plans || []).length, "绩效考核单等待您逐级审批确认", "perfApprove", "#8b5cf6")); } catch (e) {}
    try { const d = await api("/api/performance/plans?scope=report"); cards.push(homeTodoCard("✍️", "待我填报", (d.plans || []).length, "考核单需要您填报相关数据", "perfReport", "#f59e0b")); } catch (e) {}
    try {
      const d = await api("/api/performance/plans?scope=mine");
      const n = (d.plans || []).filter(p => p.status !== "done" && p.status !== "draft").length;
      cards.push(homeTodoCard("📋", "我的考核进行中", n, "本人考核流程当前进度", "perfMine", "#2563eb", "查看我的考核 →"));
    } catch (e) {}
  }
  if (canPerm("perf_admin")) cards.push(homeTodoCard("🗂️", "考核记录管理", "", "全部考核流程、季度明细与项目得分排名", "perfRecords", "#0ea5e9", "进入管理 →"));
  // 审批中心待办（对所有登录账号可见）
  try {
    const ad = await api("/api/approval/list?scope=approve");
    const an = (ad.items || []).length;
    if (an > 0) cards.unshift(homeTodoCard("✅", "待我审批", an, "录用/转正/离职等审批单等待您处理", "approvalCenter", "#8b5cf6"));
  } catch (e) {}
  if (state.user.role === "admin") {
    try {
      const od = await api("/api/approval/onboard/list?status=pending");
      const on = (od.items || []).length;
      if (on > 0) cards.push(homeTodoCard("🧳", "入职办理待办", on, "录用已通过，待完成入职办理清单", "approvalCenter", "#0891b2"));
    } catch (e) {}
  }
  if (canPerm("payroll")) cards.push(homeTodoCard("🧮", "薪资核算与微调", "", `进入 ${state.month} 薪资核算与人工微调`, "payroll", "#16a34a", "去核算 →"));
  if (canPerm("staff")) cards.push(homeTodoCard("👥", "人员档案", "", "维护员工档案、入职转正与离职信息", "staff", "#0891b2", "查看档案 →"));
  el.innerHTML = cards.length ? cards.join("") : '<div class="msg info">暂无待办事项</div>';
}

/* ---------------- 消息通知中心 ---------------- */
const MSG_ICON = { approval: { i: "✅", c: "#8b5cf6" }, remind: { i: "⏰", c: "#f59e0b" }, notice: { i: "📢", c: "#0ea5e9" }, salary: { i: "💰", c: "#16a34a" } };
function msgMeta(type) { return MSG_ICON[type] || { i: "🔔", c: "#64748b" }; }
async function loadUnread() {
  try {
    const d = await api("/api/messages/unread_count");
    const b = document.getElementById("bellBadge");
    if (b) {
      b.textContent = d.unread > 99 ? "99+" : d.unread;
      b.style.display = d.unread > 0 ? "flex" : "none";
    }
  } catch (e) { /* 静默 */ }
}
function toggleBell(ev) {
  if (ev) { ev.stopPropagation(); }
  const p = document.getElementById("bellPanel");
  if (!p) return;
  const show = p.style.display === "none";
  p.style.display = show ? "block" : "none";
  if (show) renderBellList();
}
document.addEventListener("click", function (e) {
  const w = document.getElementById("bellWrap");
  const p = document.getElementById("bellPanel");
  if (w && p && !w.contains(e.target)) p.style.display = "none";
});
async function renderBellList() {
  const el = document.getElementById("bellList");
  if (!el) return;
  el.innerHTML = "加载中…";
  try {
    const d = await api("/api/messages?limit=50");
    loadUnread();
    if (!d.items || !d.items.length) { el.innerHTML = '<div class="bell-empty">暂无消息</div>'; return; }
    el.innerHTML = d.items.map(m => {
      const meta = msgMeta(m.type);
      return `<div class="bell-item${m.read ? "" : " unread"}" onclick="openMsg(${m.id},'${esc(m.link)}')">
        <div class="bell-ico" style="background:${meta.c}22;color:${meta.c}">${meta.i}</div>
        <div class="bell-body">
          <div class="bell-t">${esc(m.title)}</div>
          ${m.content ? `<div class="bell-c">${esc(m.content)}</div>` : ""}
          <div class="bell-tm">${esc(m.project || "")}${m.project ? " · " : ""}${esc(m.time)}</div>
        </div></div>`;
    }).join("");
  } catch (e) { el.innerHTML = '<div class="bell-empty">加载失败</div>'; }
}
async function openMsg(id, link) {
  await api("/api/messages/read", { body: { id } }).catch(() => {});
  loadUnread();
  document.getElementById("bellPanel").style.display = "none";
  if (link) msgJump(link);
}
function msgJump(link) {
  // 采购系统通知 → 内嵌打开并定位到对应页面（不另开新窗）
  if (/\/purchase\/index\.html/.test(link)) {
    const h = String(link).split("#")[1] || "";
    const routePath = h.replace(/^#?\//, "").split("?")[0];
    const hashMap = { dashboard: "purchaseDashboard", fill: "purchaseFill", "fill-form": "purchaseFill",
      overview: "purchaseOverview", customs: "purchaseCustoms", budget: "purchaseBudget",
      summary: "purchaseSummary", "export-import": "purchaseExportImport", products: "purchaseProducts",
      windows: "purchaseWindows", oplogs: "purchaseOplogs", "my-items": "purchaseMyItems" };
    state.page = hashMap[routePath] || "purchaseDashboard";
    state.currentApp = "purchase";
    document.getElementById("pageTitle").textContent = PAGE_TITLES[state.page] || "";
    renderMenu();
    if (typeof renderApps === "function") renderApps();
    openPurchase(h);
    return;
  }
  const key = String(link).replace(/^\/+/, "").replace(/\?.*$/, "");
  if (PAGE_TITLES[key] || PAGE_TITLES[link]) { nav(link); return; }
  if (/^https?:\/\//i.test(link)) { window.open(link, "_blank"); return; }
  if (link && link !== "/") { window.location.href = link; }
}
async function markAllRead() {
  await api("/api/messages/read_all", { method: "POST" });
  loadUnread();
  renderBellList();
}

/* ---------------- 汇总展示 ---------------- */
async function pageSummary() {
  const c = document.getElementById("content");
  c.innerHTML = `<div class="card">${monthInput()}
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px">
      <b>项目筛选：</b>
      <div style="position:relative;display:inline-block">
        <button class="btn" onclick="toggleSumDrop(event)">全部项目</button>
        <div id="sumDropPanel" style="display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:99;background:#fff;border:1px solid #d9d9d9;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.12);padding:10px 12px;min-width:260px;max-height:320px;overflow:auto">
          <label style="display:flex;align-items:center;gap:4px;padding:3px 0;cursor:pointer"><input type="checkbox" id="sumProjAll" checked onchange="toggleSumAll(this)"> 全选</label>
          <div style="border-top:1px solid #eee;margin:4px 0 2px"></div>
          <div id="sumProjChecks"></div>
        </div>
      </div>
    </div>
    <div style="margin-top:8px">
      <button class="btn" onclick="exportSummary()">导出表格</button>
      <button class="btn primary" onclick="pageSummary()">刷新</button>
    </div>
    <div id="sumArea" style="margin-top:12px">加载中...</div></div>`;
  try {
    const data = await api(`/api/summary?ym=${state.month}`);
    window._sumData = data;
    const box = document.getElementById("sumProjChecks");
    if (box) {
      box.innerHTML = data.items.map(it => `<label style="display:flex;align-items:center;gap:4px;padding:2px 0;cursor:pointer;white-space:nowrap"><input type="checkbox" value="${esc(it.project)}" checked onchange="renderSummary();syncSumAll()"> ${esc(it.project)}</label>`).join("");
      syncSumAll();
      updateSumDropLabel();
    }
    renderSummary();
  } catch (e) { document.getElementById("sumArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function renderSummary() {
  const area = document.getElementById("sumArea");
  if (!area || !window._sumData) return;
  const data = window._sumData;
  const checks = Array.from(document.querySelectorAll("#sumProjChecks input:checked")).map(cb => cb.value);
  const items = checks.length ? data.items.filter(it => checks.includes(it.project)) : data.items;
  const t = { headcount: 0, gross: 0, net: 0, month_budget: 0, annual_budget: 0, ytd_gross: 0 };
  for (const it of items) {
    t.headcount += it.headcount; t.gross += it.gross; t.net += it.net;
    t.month_budget += it.month_budget; t.annual_budget += it.annual_budget; t.ytd_gross += it.ytd_gross;
  }
  t.month_rate = t.month_budget > 0 ? t.gross / t.month_budget : 0;
  t.annual_rate = t.annual_budget > 0 ? t.ytd_gross / t.annual_budget : 0;
  let html = `<div class="stat-cards">
      <div class="stat"><div class="k">发放人数（有效考勤）</div><div class="v">${t.headcount}</div></div>
      <div class="stat"><div class="k">应发总金额</div><div class="v">${money(t.gross)}</div></div>
      <div class="stat"><div class="k">实发总金额</div><div class="v">${money(t.net)}</div></div>
      <div class="stat"><div class="k">当月预算执行率</div><div class="v">${pct(t.month_rate)}</div></div>
      <div class="stat"><div class="k">年度预算执行率</div><div class="v">${pct(t.annual_rate)}</div></div>
    </div>`;
  if (data.archived) html += `<div class="msg info">该月已归档锁定，如需修改须超管在"薪资核算"页解锁。</div>`;
  html += `<div class="table-wrap"><table class="tb"><thead><tr>
      <th>项目</th><th>发放人数</th><th>应发总金额</th><th>实发总金额</th><th>当月预算</th><th>当月执行率</th>
      <th>年度预算</th><th>年度累计应发</th><th>年度执行率</th><th>核算状态</th></tr></thead><tbody>`;
  for (const it of items) {
    html += `<tr><td>${esc(it.project)}</td><td class="num">${it.headcount}</td>
        <td class="num">${money(it.gross)}</td><td class="num">${money(it.net)}</td>
        <td class="num">${money(it.month_budget)}</td><td class="num">${pct(it.month_rate)}</td>
        <td class="num">${money(it.annual_budget)}</td><td class="num">${money(it.ytd_gross)}</td>
        <td class="num">${pct(it.annual_rate)}</td>
        <td>${it.calculated ? '<span class="tag green">已核算</span>' : '<span class="tag gray">未核算</span>'}</td></tr>`;
  }
  const label = (checks.length === data.items.length) ? '总计' : (checks.length ? '总计（' + checks.length + '个项目）' : '总计');
  html += `<tr style="font-weight:bold;background:#f3f6fb"><td>${label}</td><td class="num">${t.headcount}</td>
      <td class="num">${money(t.gross)}</td><td class="num">${money(t.net)}</td><td class="num">${money(t.month_budget)}</td>
      <td class="num">${pct(t.month_rate)}</td><td class="num">${money(t.annual_budget)}</td>
      <td class="num">${money(t.ytd_gross)}</td><td class="num">${pct(t.annual_rate)}</td><td></td></tr>`;
  html += `</tbody></table></div><div class="hint">发放人数口径：已核算时取实际参与核算的人数，未核算时取当月考勤记录人数。执行率口径：当月执行率=当月应发÷当月预算；年度执行率=年度累计应发÷年度预算；预算为0或无工资时按0显示。筛选后总计与顶部统计卡随勾选范围联动；全不勾选视为全部。</div>`;
  area.innerHTML = html;
  updateSumDropLabel();
}
function exportSummary() {
  const checks = Array.from(document.querySelectorAll("#sumProjChecks input:checked")).map(cb => cb.value);
  const p = checks.join(",");
  const label = (checks.length === (window._sumData ? window._sumData.items.length : 0)) ? "" : (checks.length ? "_筛选" : "");
  download(`/api/summary/export?ym=${state.month}&project=${encodeURIComponent(p)}`, `薪资汇总展示_${state.month}${label}.xlsx`);
}
function toggleSumDrop(ev) {
  if (ev && ev.stopPropagation) ev.stopPropagation();
  const p = document.getElementById("sumDropPanel");
  if (!p) return;
  p.style.display = p.style.display === "none" ? "block" : "none";
}
function updateSumDropLabel() {
  const btn = document.querySelector("#sumDropPanel") ? document.querySelector("#sumDropPanel").previousElementSibling : null;
  const all = document.getElementById("sumProjAll");
  if (!btn || !all) return;
  const total = document.querySelectorAll("#sumProjChecks input").length;
  const checked = document.querySelectorAll("#sumProjChecks input:checked").length;
  btn.textContent = (checked === 0 || checked === total) ? `全部项目（${total}项）` : `已选${checked}项`;
}
document.addEventListener("click", function (ev) {
  const p = document.getElementById("sumDropPanel");
  if (p && p.style.display !== "none") {
    let el = ev.target;
    while (el) { if (el.id === "sumDropPanel") return; el = el.parentElement; }
    p.style.display = "none";
  }
});
function toggleSumAll(el) {
  document.querySelectorAll("#sumProjChecks input").forEach(cb => { cb.checked = el.checked; });
  renderSummary();
}
function syncSumAll() {
  const all = document.getElementById("sumProjAll");
  const cbs = document.querySelectorAll("#sumProjChecks input");
  if (all && cbs.length) all.checked = Array.from(cbs).every(cb => cb.checked);
}

/* ---------------- 个税扣除模式设置 ---------------- */
let _tmStaff = [];
window._tmSel = new Set();
async function pageTaxMode() {
  const c = document.getElementById("content");
  c.innerHTML = `<div class="card"><h3>个税扣除模式设置</h3>
  <div class="msg info">普通模式：每月按 5000 元累计减除费用（常规预扣）。<br>6万扣除模式：年初一次性按全年 6 万元减除费用扣除，累计收入不超过 6 万元的月份不预扣个税（适用于上年度全年收入≤6万且在同一单位的人员；最终以汇算清缴为准）。切换后，当月及后续月份个税按新模式重算。</div>
  <div class="row">
    <label class="fld">项目 <select id="tmProj" onchange="tmLoad()"><option value="">全部</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
    <input type="text" id="tmKw" placeholder="姓名/职位搜索" onkeydown="if(event.key==='Enter')tmLoad()">
    <button class="btn primary" onclick="tmLoad()">查询</button>
    <label class="fld" style="display:inline-flex;align-items:center;width:auto"><input type="checkbox" id="tmAll" onchange="tmToggleAll(this.checked)" style="width:auto;margin-right:6px"> 全选</label>
    <span class="tag blue" id="tmSelCount">已选 0 人</span>
    <button class="btn warn" onclick="tmBulkSet(0)">批量设为普通</button>
    <button class="btn warn" onclick="tmBulkSet(1)">批量设为6万扣除</button>
  </div>
  <div id="tmArea" style="margin-top:12px">加载中...</div></div>`;
  window._tmSel = new Set();
  tmLoad();
}
async function tmLoad() {
  const area = document.getElementById("tmArea");
  try {
    const proj = document.getElementById("tmProj").value;
    const kw = document.getElementById("tmKw").value;
    const q = `cat=${encodeURIComponent("在职")}&project=${encodeURIComponent(proj)}&kw=${encodeURIComponent(kw)}`;
    const data = await api("/api/staff?" + q);
    window._tmStaff = data.staff;
    let html = `<div class="table-wrap" style="overflow-x:auto"><table class="tb" style="min-width:960px"><thead><tr>
      <th>选择</th><th>姓名</th><th>项目</th><th>部门</th><th>职位</th><th>当前模式</th><th>切换为</th></tr></thead><tbody>`;
    for (const s of data.staff) {
      const mode = Number(s.tax_mode ?? 0);
      html += `<tr>
        <td style="text-align:center"><input type="checkbox" class="tmChk" ${window._tmSel.has(s.id) ? "checked" : ""} onchange="tmToggle(${s.id},this.checked)"></td>
        <td><b>${esc(s.name)}</b></td><td>${esc(s.project)}</td><td>${esc(s.dept_path || "未分配")}</td><td>${esc(s.position)}</td>
        <td><span class="tag ${mode === 1 ? "blue" : "gray"}">${mode === 1 ? "6万扣除" : "普通"}</span></td>
        <td><select onchange="tmSaveOne(${s.id},this.value)">
          <option value="0" ${mode === 1 ? "" : "selected"}>普通模式</option>
          <option value="1" ${mode === 1 ? "selected" : ""}>6万扣除</option>
        </select></td></tr>`;
    }
    html += `</tbody></table></div><div class="hint">共 ${data.staff.length} 名在职员工。切换为「6万扣除」后，自当年 1 月起按全年 6 万元减除费用累计预扣；普通模式恢复每月 5000 元累计。历史月份如需更正，请在「薪资核算与微调」中重新核算。</div>`;
    area.innerHTML = html;
    tmUpdSel();
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function tmToggle(id, on) { if (on) window._tmSel.add(id); else window._tmSel.delete(id); tmUpdSel(); }
function tmUpdSel() {
  const el = document.getElementById("tmSelCount");
  if (el) el.textContent = `已选 ${window._tmSel.size} 人`;
}
function tmToggleAll(on) {
  const list = window._tmStaff || [];
  window._tmSel = on ? new Set(list.map(s => s.id)) : new Set();
  document.querySelectorAll(".tmChk").forEach(cb => cb.checked = on);
  tmUpdSel();
}
async function tmSaveOne(id, mode) {
  try {
    const r = await api("/api/staff/bulk_tax_mode", { body: { ids: [id], mode: parseInt(mode) } });
    toast(`已设置${parseInt(mode) === 1 ? "6万扣除" : "普通"}模式`);
    const s = window._tmStaff.find(x => x.id === id);
    if (s) s.tax_mode = parseInt(mode);
    // 同步刷新该行"当前模式"标签
    document.querySelectorAll("#tmArea table tbody tr").forEach(tr => {
      if (tr.querySelector("select") && tr.textContent.includes(s ? s.name : "")) {
        const td = tr.querySelector("td:nth-child(6)");
        if (td) td.innerHTML = `<span class="tag ${mode == 1 ? "blue" : "gray"}">${mode == 1 ? "6万扣除" : "普通"}</span>`;
      }
    });
  } catch (e) { alert(e.message); }
}
async function tmBulkSet(mode) {
  if (!window._tmSel.size) return toast("请先勾选人员", false);
  try {
    const r = await api("/api/staff/bulk_tax_mode", { body: { ids: [...window._tmSel], mode } });
    toast(`已为 ${r.count} 人设置${mode === 1 ? "6万扣除" : "普通"}模式`);
    window._tmSel = new Set();
    tmLoad();
  } catch (e) { alert(e.message); }
}

/* ---------------- 薪资核算 ---------------- */
let calcProjects = [];
let payTab = "emp";
function switchPayTab(t) {
  payTab = t;
  const e = document.getElementById("empPayBlock");
  const m = document.getElementById("mgrPayBlock");
  const c = document.getElementById("casePayBlock");
  const h = document.getElementById("hqPayBlock");
  if (e) e.style.display = t === "emp" ? "" : "none";
  if (m) m.style.display = t === "mgr" ? "" : "none";
  if (c) c.style.display = t === "case" ? "" : "none";
  if (h) h.style.display = t === "hq" ? "" : "none";
  document.querySelectorAll("[data-paytab]").forEach(b => {
    if (b.classList.contains("pay-tab")) b.classList.toggle("active", b.dataset.paytab === t);
    else b.classList.toggle("primary", b.dataset.paytab === t);
  });
  if (t === "mgr") loadMgrs();
  else if (t === "case") loadCases();
  else if (t === "hq") loadHqs();
  else loadPayroll();
}
async function pagePayroll() {
  const c = document.getElementById("content");
  calcProjects = state.projects.slice();
  const isAdmin = state.user && state.user.role === "admin";
  c.innerHTML = `
  <div class="card">
    <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
      ${monthInput()}
      <span style="flex:1"></span>
      <div class="pay-tabs">
        <button class="pay-tab ${payTab === "emp" ? "active" : ""}" data-paytab="emp" onclick="switchPayTab('emp')">员工核算</button>
        ${isAdmin ? `<button class="pay-tab ${payTab === "mgr" ? "active" : ""}" data-paytab="mgr" onclick="switchPayTab('mgr')">管理人员核算</button>` : ""}
        ${isAdmin ? `<button class="pay-tab ${payTab === "case" ? "active" : ""}" data-paytab="case" onclick="switchPayTab('case')">案场人员核算</button>` : ""}
        ${isAdmin ? `<button class="pay-tab ${payTab === "hq" ? "active" : ""}" data-paytab="hq" onclick="switchPayTab('hq')">总部人员核算</button>` : ""}
      </div>
    </div>
    <div id="empPayBlock" style="${payTab === "emp" ? "" : "display:none"}">
      ${isAdmin ? `<div class="card" style="margin-top:10px"><h3>员工核算操作</h3>
        <div class="row">
          <button class="btn" onclick="calcProjects=state.projects.slice();pagePayroll()">全选</button>
          <button class="btn" onclick="calcProjects=[];pagePayroll()">清空</button>
          <button class="btn primary" onclick="doCalc()">开始核算（覆盖旧数据）</button>
          <span id="archiveBtns"></span>
        </div>
        <div class="checkbox-list" style="margin-top:10px" id="projChecks"></div>
        <div id="calcMsg"></div>
      </div>` : `<div class="card" style="margin-top:10px"><h3>核算操作</h3>
        <div class="msg info">项目账号仅可查看/导出本项目已由总部核定完成的薪资数据，薪资核定与归档权限仅总部管理员拥有。</div>
      </div>`}
      <div class="card" style="margin-top:10px"><h3>员工核算结果明细</h3><div id="payrollArea">加载中...</div></div>
    </div>
    ${isAdmin ? `<div id="mgrPayBlock" style="${payTab === "mgr" ? "" : "display:none"}">
      <div class="card" style="margin-top:10px"><h3>管理人员核算（仅总部 · 所有项目管理人员汇总）</h3>
        <div class="row">
          <button class="btn primary" onclick="doCalcMgrs()">管理人员核算（覆盖旧数据）</button>
          <button class="btn" onclick="exportGo('managers')">导出管理人员工资表（全部项目）</button>
          <span id="mgrArchiveBtns"></span>
        </div>
        <div class="hint">管理人员（人员档案中勾选"是否管理人员"）不参与项目工资表核算，由这里按月份汇总所有项目管理人员单独核算生成管理人员工资表；仅总部可查看/导出/锁定，项目账号不可见。</div>
        <div id="mgrMsg"></div>
        <div id="mgrArea" style="margin-top:10px">加载中...</div>
      </div>
    </div>` : ""}
    ${isAdmin ? `<div id="casePayBlock" style="${payTab === "case" ? "" : "display:none"}">
      <div class="card" style="margin-top:10px"><h3>案场人员核算（仅总部 · 所有项目案场人员汇总）</h3>
        <div class="row">
          <button class="btn primary" onclick="doCalcCase()">案场人员核算（覆盖旧数据）</button>
          <button class="btn" onclick="exportGo('caseAll')">导出案场人员工资表（全部项目）</button>
          <span id="caseArchiveBtns"></span>
        </div>
        <div class="hint">案场人员（人员档案中勾选"是否案场人员"）不参与项目工资表核算，由这里按月份汇总所有项目案场人员单独核算生成案场人员工资表；各项目可查看/导出本项目案场人员。</div>
        <div id="caseMsg"></div>
        <div id="caseArea" style="margin-top:10px">加载中...</div>
      </div>
    </div>` : ""}
    ${isAdmin ? `<div id="hqPayBlock" style="${payTab === "hq" ? "" : "display:none"}">
      <div class="card" style="margin-top:10px"><h3>总部人员核算（仅总部 · 物业总部所有人员汇总）</h3>
        <div class="row">
          <button class="btn primary" onclick="doCalcHq()">总部人员核算（覆盖旧数据）</button>
          <button class="btn" onclick="exportGo('hqAll')">导出总部人员工资表</button>
          <span id="hqArchiveBtns"></span>
        </div>
        <div class="hint">物业总部所有人员（不区分是否管理人员/案场人员标记）不参与项目工资表与管理/案场人员核算，由这里单独核算生成总部人员工资表；总部考勤表随项目考勤单独上传（项目=物业总部）。仅总部可查看/导出/锁定。</div>
        <div id="hqMsg"></div>
        <div id="hqArea" style="margin-top:10px">加载中...</div>
      </div>
    </div>` : ""}
  </div>`;
  if (isAdmin) {
    document.getElementById("projChecks").innerHTML = state.projects.filter(p => p !== "物业总部").map(p =>
      `<label><input type="checkbox" ${calcProjects.includes(p) ? "checked" : ""} onchange="toggleCalcProj('${esc(p)}',this.checked)"> ${esc(p)}</label>`).join("");
    if (payTab === "mgr") loadMgrs();
    if (payTab === "case") loadCases();
    if (payTab === "hq") loadHqs();
  }
  loadPayroll();
}
async function doCalcMgrs() {
  if (!confirm(`确认核算 ${state.month} 管理人员工资表（所有项目管理人员）？同月重复核算将覆盖旧数据。`)) return;
  try {
    const r = await api("/api/payroll/calc-managers", { body: { ym: state.month } });
    document.getElementById("mgrMsg").innerHTML = `<div class="msg ok">管理人员核算完成，共 ${r.count} 人。${r.skipped && r.skipped.length ? "（" + r.skipped.join("、") + "）" : ""}</div>`;
    loadMgrs(); loadPayroll();
  } catch (e) { document.getElementById("mgrMsg").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function loadMgrs() {
  const area = document.getElementById("mgrArea");
  if (!area) return;
  try {
    const data = await api(`/api/payroll?ym=${state.month}&type=manager`);
    const attStatus = await api(`/api/attendance/status?ym=${state.month}`);
    document.getElementById("mgrArchiveBtns") && (document.getElementById("mgrArchiveBtns").innerHTML = data.archived
      ? `<span class="tag red">已归档锁定</span> <button class="btn warn sm" onclick="setArchiveMgrs(false)">解锁归档</button>`
      : (data.rows.length ? `<button class="btn success sm" onclick="setArchiveMgrs(true)">确认归档锁定</button>` : ""));
    const statusHtml = renderAttStatus(attStatus);
    if (!data.rows.length) { area.innerHTML = statusHtml + `<div class="msg info">该月暂无管理人员核算数据。点击"管理人员核算"生成。</div>`; return; }
    let html = statusHtml + `<div class="row" style="margin-bottom:8px"><span class="tag blue">${data.rows.length} 名管理人员</span>
      <label class="fld">项目筛选 <select id="mgrProjFilter" onchange="filterMgrs()"><option value="">全部项目</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
      </div>
    <div class="table-wrap" style="overflow-x:auto"><table class="tb" id="mgrTable"><thead><tr>
      <th>项目</th><th>部门</th><th>职位</th><th>姓名</th><th>员工状态</th><th>固定月薪</th><th>基本工资</th>
      <th>应出勤</th><th>出勤</th><th>绩效计薪</th><th>系数</th><th>基本工资(折算)</th><th>绩效工资</th>
      <th>病假天数</th><th>病假工资</th><th>夜班/话费</th><th>餐补</th><th>其他补贴</th><th>奖励</th><th>福利</th>
      <th>扣罚</th><th>迟早扣</th><th>缺卡扣</th><th>其他扣</th><th>工装扣</th><th>应发合计</th>
      <th>社保合计</th><th>附加扣除</th><th>本月个税</th><th>实发工资</th><th>操作</th></tr></thead><tbody>`;
    window._mgrRows = data.rows;
    html += payRowsHtml(data.rows);
    html += `</tbody><tfoot>${payTotalHtml(data.rows)}</tfoot></table></div>
    <div class="hint">管理人员工资表仅总部可见；项目账号无法查看/导出。归档锁定后禁止修改、重算，仅超管可解锁。微调操作与项目工资表一致。</div>`;
    area.innerHTML = html;
    initStickyCols("mgrTable", 4);
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function filterMgrs() {
  const p = document.getElementById("mgrProjFilter") ? document.getElementById("mgrProjFilter").value : "";
  const rows = (window._mgrRows || []).filter(r => !p || r.project === p);
  const tbody = document.querySelector("#mgrTable tbody");
  const tfoot = document.querySelector("#mgrTable tfoot");
  if (tbody) tbody.innerHTML = payRowsHtml(rows);
  if (tfoot) tfoot.innerHTML = payTotalHtml(rows);
  initStickyCols("mgrTable", 4);
}
async function setArchiveMgrs(locked) {
  if (!confirm(locked ? "确认归档锁定管理人员工资表？锁定后禁止修改/重算，仅超管可解锁。" : "确认解锁归档？")) return;
  try {
    await api("/api/payroll/archive", { body: { ym: state.month, locked, type: "manager" } });
    toast(locked ? "已归档锁定" : "已解锁");
    loadMgrs(); loadPayroll();
  } catch (e) { alert(e.message); }
}
async function doCalcCase() {
  if (!confirm(`确认核算 ${state.month} 案场人员工资表（所有项目案场人员）？同月重复核算将覆盖旧数据。`)) return;
  try {
    const r = await api("/api/payroll/calc-case", { body: { ym: state.month } });
    document.getElementById("caseMsg").innerHTML = `<div class="msg ok">案场人员核算完成，共 ${r.count} 人。${r.skipped && r.skipped.length ? "（" + r.skipped.join("、") + "）" : ""}</div>`;
    loadCases(); loadPayroll();
  } catch (e) { document.getElementById("caseMsg").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function loadCases() {
  const area = document.getElementById("caseArea");
  if (!area) return;
  try {
    const data = await api(`/api/payroll?ym=${state.month}&type=case`);
    const attStatus = await api(`/api/attendance/status?ym=${state.month}`);
    document.getElementById("caseArchiveBtns") && (document.getElementById("caseArchiveBtns").innerHTML = data.archived
      ? `<span class="tag red">已归档锁定</span> <button class="btn warn sm" onclick="setArchiveCase(false)">解锁归档</button>`
      : (data.rows.length ? `<button class="btn success sm" onclick="setArchiveCase(true)">确认归档锁定</button>` : ""));
    const statusHtml = renderAttStatus(attStatus);
    if (!data.rows.length) { area.innerHTML = statusHtml + `<div class="msg info">该月暂无案场人员核算数据。点击"案场人员核算"生成。</div>`; return; }
    let html = statusHtml + `<div class="row" style="margin-bottom:8px"><span class="tag blue">${data.rows.length} 名案场人员</span>
      <label class="fld">项目筛选 <select id="caseProjFilter" onchange="filterCases()"><option value="">全部项目</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
      </div>
    <div class="table-wrap" style="overflow-x:auto"><table class="tb" id="caseTable"><thead><tr>
      <th>项目</th><th>部门</th><th>职位</th><th>姓名</th><th>员工状态</th><th>固定月薪</th><th>基本工资</th>
      <th>应出勤</th><th>出勤</th><th>绩效计薪</th><th>系数</th><th>基本工资(折算)</th><th>绩效工资</th>
      <th>病假天数</th><th>病假工资</th><th>夜班/话费</th><th>餐补</th><th>其他补贴</th><th>奖励</th><th>福利</th>
      <th>扣罚</th><th>迟早扣</th><th>缺卡扣</th><th>其他扣</th><th>工装扣</th><th>应发合计</th>
      <th>社保合计</th><th>附加扣除</th><th>本月个税</th><th>实发工资</th><th>操作</th></tr></thead><tbody>`;
    window._caseRows = data.rows;
    html += payRowsHtml(data.rows);
    html += `</tbody><tfoot>${payTotalHtml(data.rows)}</tfoot></table></div>
    <div class="hint">案场人员工资表各项目可查看/导出本项目数据；归档锁定后禁止修改、重算，仅超管可解锁。微调操作与项目工资表一致。</div>`;
    area.innerHTML = html;
    initStickyCols("caseTable", 4);
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function filterCases() {
  const p = document.getElementById("caseProjFilter") ? document.getElementById("caseProjFilter").value : "";
  const rows = (window._caseRows || []).filter(r => !p || r.project === p);
  const tbody = document.querySelector("#caseTable tbody");
  const tfoot = document.querySelector("#caseTable tfoot");
  if (tbody) tbody.innerHTML = payRowsHtml(rows);
  if (tfoot) tfoot.innerHTML = payTotalHtml(rows);
  initStickyCols("caseTable", 4);
}
async function setArchiveCase(locked) {
  if (!confirm(locked ? "确认归档锁定案场人员工资表？锁定后禁止修改/重算，仅超管可解锁。" : "确认解锁归档？")) return;
  try {
    await api("/api/payroll/archive", { body: { ym: state.month, locked, type: "case" } });
    toast(locked ? "已归档锁定" : "已解锁");
    loadCases(); loadPayroll();
  } catch (e) { alert(e.message); }
}
async function doCalcHq() {
  if (!confirm(`确认核算 ${state.month} 总部人员工资表（物业总部所有人员）？同月重复核算将覆盖旧数据。`)) return;
  try {
    const r = await api("/api/payroll/calc-hq", { body: { ym: state.month } });
    document.getElementById("hqMsg").innerHTML = `<div class="msg ok">总部人员核算完成，共 ${r.count} 人。${r.skipped && r.skipped.length ? "（" + r.skipped.join("、") + "）" : ""}</div>`;
    loadHqs(); loadPayroll();
  } catch (e) { document.getElementById("hqMsg").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function loadHqs() {
  const area = document.getElementById("hqArea");
  if (!area) return;
  try {
    const data = await api(`/api/payroll?ym=${state.month}&type=hq`);
    const attStatus = await api(`/api/attendance/status?ym=${state.month}`);
    document.getElementById("hqArchiveBtns") && (document.getElementById("hqArchiveBtns").innerHTML = data.archived
      ? `<span class="tag red">已归档锁定</span> <button class="btn warn sm" onclick="setArchiveHq(false)">解锁归档</button>`
      : (data.rows.length ? `<button class="btn success sm" onclick="setArchiveHq(true)">确认归档锁定</button>` : ""));
    const statusHtml = renderAttStatus(attStatus);
    if (!data.rows.length) { area.innerHTML = statusHtml + `<div class="msg info">该月暂无总部人员核算数据。请先上传物业总部考勤表，再点击"总部人员核算"生成。</div>`; return; }
    let html = statusHtml + `<div class="row" style="margin-bottom:8px"><span class="tag blue">${data.rows.length} 名总部人员</span>
      </div>
    <div class="table-wrap" style="overflow-x:auto"><table class="tb" id="hqTable"><thead><tr>
      <th>项目</th><th>部门</th><th>职位</th><th>姓名</th><th>员工状态</th><th>固定月薪</th><th>基本工资</th>
      <th>应出勤</th><th>出勤</th><th>绩效计薪</th><th>系数</th><th>基本工资(折算)</th><th>绩效工资</th>
      <th>病假天数</th><th>病假工资</th><th>夜班/话费</th><th>餐补</th><th>其他补贴</th><th>奖励</th><th>福利</th>
      <th>扣罚</th><th>迟早扣</th><th>缺卡扣</th><th>其他扣</th><th>工装扣</th><th>应发合计</th>
      <th>社保合计</th><th>附加扣除</th><th>本月个税</th><th>实发工资</th><th>操作</th></tr></thead><tbody>`;
    window._hqRows = data.rows;
    html += payRowsHtml(data.rows);
    html += `</tbody><tfoot>${payTotalHtml(data.rows)}</tfoot></table></div>
    <div class="hint">总部人员工资表仅总部可见；项目账号无法查看/导出。归档锁定后禁止修改、重算，仅超管可解锁。微调操作与项目工资表一致。</div>`;
    area.innerHTML = html;
    initStickyCols("hqTable", 4);
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function setArchiveHq(locked) {
  if (!confirm(locked ? "确认归档锁定总部人员工资表？锁定后禁止修改/重算，仅超管可解锁。" : "确认解锁归档？")) return;
  try {
    await api("/api/payroll/archive", { body: { ym: state.month, locked, type: "hq" } });
    toast(locked ? "已归档锁定" : "已解锁");
    loadHqs(); loadPayroll();
  } catch (e) { alert(e.message); }
}
function toggleCalcProj(p, on) {
  if (on && !calcProjects.includes(p)) calcProjects.push(p);
  if (!on) calcProjects = calcProjects.filter(x => x !== p);
}
async function doCalc() {
  if (!calcProjects.length) return toast("请至少选择一个项目", false);
  if (!confirm(`确认核算 ${state.month}：${calcProjects.length} 个项目？同月重复核算将自动覆盖旧数据。`)) return;
  try {
    const r = await api("/api/payroll/calc", { body: { ym: state.month, projects: calcProjects } });
    let msg = `核算完成，共 ${r.count} 人。`;
    if (r.skipped_count) msg += ` 跳过 ${r.skipped_count} 人（无考勤记录）：` + r.skipped.map(s => `${s.name}(${s.project})`).join("、");
    if (r.warning) msg += "\n⚠ " + r.warning;
    document.getElementById("calcMsg").innerHTML = `<div class="msg ok">${esc(msg).replace(/\n/g, "<br>")}</div>`;
    loadPayroll();
  } catch (e) { document.getElementById("calcMsg").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function loadPayroll() {
  const area = document.getElementById("payrollArea");
  try {
    const data = await api(`/api/payroll?ym=${state.month}`);
    const attStatus = await api(`/api/attendance/status?ym=${state.month}`);
    document.getElementById("archiveBtns") && (document.getElementById("archiveBtns").innerHTML = data.archived
      ? `<span class="tag red">已归档</span> <button class="btn warn sm" onclick="setArchive(false)">解锁归档</button>`
      : (data.rows.length ? `<button class="btn success sm" onclick="setArchive(true)">确认归档锁定</button>` : ""));
    // 考勤上传状态行（收窄为一行小字）
    let statusHtml = renderAttStatus(attStatus);
    if (!data.rows.length) { area.innerHTML = statusHtml + `<div class="msg info">该月暂无核算数据。选择项目后点击"开始核算"。</div>`; return; }
    let html = statusHtml + `<div class="row" style="margin-bottom:8px">
      <span class="tag blue">核算时间 ${esc(data.calc_at || "-")}</span>
      <span class="tag gray">${data.rows.length} 人</span>
      <label class="fld">项目筛选 <select id="payProjFilter" onchange="onPayProjChange()">
        <option value="">全部项目</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
      <label class="fld">部门筛选 <select id="payDeptFilter" onchange="filterPayroll()">
        <option value="">全部部门</option>${payDeptOptions(data.rows, "").map(d => `<option>${esc(d)}</option>`).join("")}</select></label>
      <button class="btn sm" onclick="exportGo('project')">导出当前项目表</button>
    </div>
    <div class="table-wrap"><table class="tb" id="payTable"><thead><tr>
      <th>项目</th><th>部门</th><th>职位</th><th>姓名</th><th>员工状态</th><th>固定月薪</th><th>基本工资</th>
      <th>应出勤</th><th>出勤</th><th>绩效计薪</th><th>系数</th><th>基本工资(折算)</th><th>绩效工资</th>
      <th>病假天数</th><th>病假工资</th><th>夜班/话费</th><th>餐补</th><th>其他补贴</th><th>奖励</th><th>福利</th>
      <th>扣罚</th><th>迟早扣</th><th>缺卡扣</th><th>其他扣</th><th>工装扣</th><th>应发合计</th>
      <th>社保合计</th><th>附加扣除</th><th>本月个税</th><th>实发工资</th><th>操作</th></tr></thead><tbody>`;
    window._payRows = data.rows;
    html += payRowsHtml(data.rows);
    html += `</tbody><tfoot>${payTotalHtml(data.rows)}</tfoot></table></div>
    <div class="hint">点击"微调"可对单人补贴/扣款/社保/个税等字段人工修正（留存操作日志）。归档后禁止修改、重传考勤、重算，仅超管可解锁。微调日志见下方。</div>`;
    if (data.logs && data.logs.length) {
      html += `<h3 style="margin-top:14px">微调日志（本月）</h3><div class="table-wrap" style="max-height:200px"><table class="tb"><thead><tr><th>时间</th><th>操作人</th><th>姓名</th><th>字段</th><th>原值</th><th>新值</th><th>原因</th></tr></thead><tbody>`;
      for (const l of data.logs.slice(-50).reverse()) {
        html += `<tr><td>${esc(l.ts)}</td><td>${esc(l.by)}</td><td>${esc(l.staff)}</td><td>${esc(FIELD_CN[l.field] || l.field)}</td><td class="num">${esc(l.old)}</td><td class="num">${esc(l.new)}</td><td>${esc(l.reason)}</td></tr>`;
      }
      html += `</tbody></table></div>`;
    }
    area.innerHTML = html;
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function renderAttStatus(attStatus) {
  let s = `<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;padding:5px 10px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0">
    <span style="font-weight:600;font-size:12px;color:#475569">考勤上传：</span>`;
  for (const p of (attStatus.projects || [])) {
    const color = p.uploaded ? "#16a34a" : "#94a3b8";
    const bg = p.uploaded ? "#f0fdf4" : "#f1f5f9";
    const tip = p.uploaded ? `${p.count}人 ${p.uploaded_at} by ${p.uploaded_by}` : "未上传";
    s += `<span title="${esc(tip)}" style="color:${color};background:${bg};border:1px solid ${color}33;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:500">${esc(p.project)}：${p.uploaded ? "已上传" : "未上传"}</span>`;
  }
  return s + `</div>`;
}
function initStickyCols(tableId, n) {
  const tb = document.getElementById(tableId);
  if (!tb) return;
  const firstRow = tb.querySelector("tbody tr");
  if (!firstRow) return;
  let acc = 0;
  const counts = Math.min(n, firstRow.children.length);
  for (let i = 0; i < counts; i++) {
    const w = firstRow.children[i].offsetWidth;
    tb.querySelectorAll(`thead th:nth-child(${i + 1}), tbody td:nth-child(${i + 1}), tfoot td:nth-child(${i + 1})`).forEach(cell => {
      cell.classList.add("sticky-col");
      cell.style.left = acc + "px";
    });
    acc += w;
  }
}
const FIELD_CN = { night: "夜班/话费补贴", meal: "餐补", title_sub: "其他补贴", reward: "月度奖励", welfare: "已发福利(计税不发现金)",
  punish: "月度扣罚", late_d: "迟到早退扣款", miss_d: "缺卡扣款", other_d: "其他扣款", uniform_d: "工装扣款",
  pen: "养老保险", med: "医疗保险", une: "失业保险", house: "公积金", big: "大病", coef: "绩效系数",
  req_att: "应出勤", act_att: "实际出勤", perf_att: "绩效计薪出勤", actual_tax: "本月实缴个税",
  spec_rent: "租房租金", spec_loan: "住房贷款利息", spec_child: "子女教育", spec_elder: "赡养老人",
  spec_edu: "继续教育", spec_baby: "婴幼儿照护", remark: "备注" };
const STATUS_TAG = { "正式": "green", "新聘": "blue", "转正": "purple", "试用": "orange", "离职": "gray" };
function payRowSel(el) {
  const tb = el.closest("table") || document.getElementById("payTable");
  if (tb) tb.querySelectorAll("tbody tr").forEach(tr => {
    tr.style.background = ""; tr.style.color = "";
    tr.querySelectorAll("td.sticky-col").forEach(td => td.style.background = "");
  });
  el.style.background = "#fff6d6";
  el.style.color = "#1f2937";
  el.querySelectorAll("td.sticky-col").forEach(td => td.style.background = "#fff6d6");
}
function payRowsHtml(rows) {
  return rows.map(r => `<tr onclick="payRowSel(this)" style="cursor:pointer">
    <td>${esc(r.project)}</td><td>${esc(r.department || "")}</td><td>${esc(r.position)}</td><td>${esc(r.name)}</td>
    <td><span class="tag ${STATUS_TAG[r.status] || "gray"}">${esc(r.status)}</span></td>
    <td class="num">${money(r.fixed)}</td><td class="num">${money(r.base)}</td>
    <td class="num">${r.req_att}</td><td class="num">${r.act_att}</td><td class="num">${r.perf_att}</td><td class="num">${r.coef}</td>
    <td class="num">${money(r.base_pay)}</td><td class="num">${money(r.perf_pay)}</td>
    <td class="num">${r.sick_days}</td><td class="num">${money(r.sick_pay)}</td>
    <td class="num">${money(r.night)}</td><td class="num">${money(r.meal)}</td><td class="num">${money(r.title_sub)}</td>
    <td class="num">${money(r.reward)}</td><td class="num">${money(r.welfare)}</td>
    <td class="num">${money(r.punish)}</td><td class="num">${money(r.late_d)}</td><td class="num">${money(r.miss_d)}</td>
    <td class="num">${money(r.other_d)}</td><td class="num">${money(r.uniform_d)}</td>
    <td class="num" style="font-weight:bold">${money(r.gross)}</td>
    <td class="num">${money(r.soc_total)}</td><td class="num">${money(r.spec_total)}</td>
    <td class="num">${money(r.actual_tax)}</td><td class="num" style="font-weight:bold;color:#16a34a">${money(r.net)}</td>
    <td><button class="btn sm" onclick="openAdjust(${r.staff_id})">微调</button></td></tr>`).join("");
}
function payTotalHtml(rows) {
  const sum = f => rows.reduce((s, r) => s + (Number(r[f]) || 0), 0);
  const td = (v, bold, color) => `<td class="num"${bold ? ' style="font-weight:bold"' : ""}${color ? ` style="color:${color};font-weight:bold"` : ""}>${money(v)}</td>`;
  return `<tr style="background:#f0fdf4;font-weight:bold">
    <td colspan="11" style="text-align:right">合计（${rows.length}人）：</td>
    ${td(sum("base_pay"))}${td(sum("perf_pay"))}<td></td>${td(sum("sick_pay"))}
    ${td(sum("night"))}${td(sum("meal"))}${td(sum("title_sub"))}
    ${td(sum("reward"))}${td(sum("welfare"))}
    ${td(sum("punish"))}${td(sum("late_d"))}${td(sum("miss_d"))}
    ${td(sum("other_d"))}${td(sum("uniform_d"))}
    ${td(sum("gross"), true)}
    ${td(sum("soc_total"))}${td(sum("spec_total"))}
    ${td(sum("actual_tax"))}${td(sum("net"), true, "#16a34a")}
    <td></td></tr>`;
}
function payDeptOptions(rows, project) {
  const list = rows.filter(r => !project || r.project === project)
    .map(r => r.department || "").filter(d => d !== "");
  return Array.from(new Set(list));
}
function onPayProjChange() {
  const p = document.getElementById("payProjFilter").value;
  const deptSel = document.getElementById("payDeptFilter");
  if (!deptSel) return;
  const cur = deptSel.value;
  const opts = payDeptOptions(window._payRows || [], p);
  deptSel.innerHTML = `<option value="">全部部门</option>` + opts.map(d => `<option${d === cur ? " selected" : ""}>${esc(d)}</option>`).join("");
  filterPayroll();
}
function filterPayroll() {
  const p = document.getElementById("payProjFilter") ? document.getElementById("payProjFilter").value : "";
  const d = document.getElementById("payDeptFilter") ? document.getElementById("payDeptFilter").value : "";
  const rows = (window._payRows || []).filter(r => (!p || r.project === p) && (!d || (r.department || "") === d));
  const tbody = document.querySelector("#payTable tbody");
  const tfoot = document.querySelector("#payTable tfoot");
  tbody.innerHTML = payRowsHtml(rows);
  if (tfoot) tfoot.innerHTML = payTotalHtml(rows);
  initStickyCols("payTable", 4);
}
async function setArchive(locked) {
  try {
    await api("/api/payroll/archive", { body: { ym: state.month, locked } });
    toast(locked ? "已归档锁定" : "已解锁");
    loadPayroll();
  } catch (e) { alert(e.message); }
}const ADJUST_FIELDS = ["req_att", "act_att", "perf_att", "coef",
  "night", "meal", "title_sub", "reward", "welfare",
  "punish", "late_d", "miss_d", "other_d", "uniform_d",
  "pen", "med", "une", "house", "big",
  "spec_rent", "spec_loan", "spec_child", "spec_elder", "spec_edu", "spec_baby",
  "actual_tax"];
function _findPayRow(sid) {
  return (window._payRows || []).find(x => x.staff_id === sid) || (window._mgrRows || []).find(x => x.staff_id === sid);
}
function openAdjust(sid) {
  const r = _findPayRow(sid);
  if (!r) return;
  const val = f => (r[f] === null || r[f] === undefined) ? "" : r[f];
  let html = `<h3>薪资微调 — ${esc(r.name)}（${esc(r.project)}）</h3>
  <div class="msg info">修改后系统自动重算应发合计、个税累计预扣与实发工资；所有修改留存日志。</div>
  <div class="form-grid">
    <label>应出勤(天)<input type="number" step="0.01" id="adj_req_att" value="${val("req_att")}"></label>
    <label>实际出勤(天)<input type="number" step="0.01" id="adj_act_att" value="${val("act_att")}"></label>
    <label>绩效计薪出勤(天)<input type="number" step="0.01" id="adj_perf_att" value="${val("perf_att")}"></label>
    <label>绩效系数<input type="number" step="0.01" id="adj_coef" value="${val("coef")}"></label>`;
  for (const f of ["night", "meal", "title_sub", "reward", "welfare"])
    html += `<label>${FIELD_CN[f]}<input type="number" step="0.01" id="adj_${f}" value="${val(f)}"></label>`;
  for (const f of ["punish", "late_d", "miss_d", "other_d", "uniform_d"])
    html += `<label>${FIELD_CN[f]}<input type="number" step="0.01" id="adj_${f}" value="${val(f)}"></label>`;
  for (const f of ["pen", "med", "une", "house", "big"])
    html += `<label>${FIELD_CN[f]}<input type="number" step="0.01" id="adj_${f}" value="${val(f)}"></label>`;
  for (const f of ["spec_rent", "spec_loan", "spec_child", "spec_elder", "spec_edu", "spec_baby"])
    html += `<label>${FIELD_CN[f]}<input type="number" step="0.01" id="adj_${f}" value="${val(f)}"></label>`;
  html += `<label>${FIELD_CN["actual_tax"]}<input type="number" step="0.01" id="adj_actual_tax" value="${val("actual_tax")}"></label>
  </div>
  <div class="form-grid" style="margin-top:10px">
    <label class="full">备注<input type="text" id="adj_remark" value="${esc(val("remark") || "")}"></label>
    <label class="full">修改原因（记入日志）<input type="text" id="adj_reason" placeholder="必填"></label>
  </div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="saveAdjust(${sid})">保存微调</button></div>`;
  modal(html, 780);
}
async function saveAdjust(sid) {
  const reason = document.getElementById("adj_reason").value.trim();
  if (!reason) return toast("请填写修改原因", false);
  const cur = _findPayRow(sid);
  const SPEC_FIELDS = ["spec_rent", "spec_loan", "spec_child", "spec_elder", "spec_edu", "spec_baby"];
  const changes = {};
  let specChanged = false;
  const specAll = {};
  for (const f of ADJUST_FIELDS) {
    const el = document.getElementById("adj_" + f);
    if (!el) continue;
    const raw = el.value;
    if (SPEC_FIELDS.includes(f)) {
      // 专项附加六项：始终全量收集（空按0），避免只改一项时其余项在后端被清零
      const sv = raw === "" ? 0 : (parseFloat(raw) || 0);
      specAll[f] = sv;
      if (Math.abs(sv - Number(cur[f] || 0)) > 0.001) specChanged = true;
      continue;
    }
    if (raw === "") continue;
    const v = parseFloat(raw);
    if (isNaN(v)) continue;
    if (Math.abs(v - Number(cur[f] || 0)) > 0.001) changes[f] = v;
  }
  // 任一项专项附加有改动，则六项整体回传，保证后端重算时不丢项
  if (specChanged) Object.assign(changes, specAll);
  const remarkEl = document.getElementById("adj_remark");
  if (remarkEl && remarkEl.value !== (cur.remark || "")) changes.remark = remarkEl.value;
  if (!Object.keys(changes).length) { closeModal(); return toast("没有修改"); }
  try {
    await api("/api/payroll/adjust", { body: { ym: state.month, staff_id: sid, fields: changes, reason } });
    closeModal(); toast("微调已保存，个税已联动重算");
    loadPayroll();
    if (document.getElementById("mgrArea")) loadMgrs();
  } catch (e) { alert(e.message); }
}

/* ---------------- 考勤管理 ---------------- */
async function pageAttendance() {
  const c = document.getElementById("content");
  const isProj = state.user.role === "project";
  c.innerHTML = `
  <div class="card"><h3>考勤上传</h3>
    <div class="row">${monthInput()}
      ${isProj ? `<span class="tag blue">本项目：${esc(state.user.project)}</span>` :
      `<label class="fld">项目 <select id="attProj">${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>`}
      <button class="btn" onclick="attTemplate()">① 下载本月考勤模板</button>
      <input type="file" id="attFile" accept=".xlsx" style="display:none" onchange="attUpload()">
      <button class="btn primary" onclick="document.getElementById('attFile').click()">② 上传考勤表</button>
      <button class="btn" onclick="attView()">查看已上传数据</button>
      <button class="btn" id="attLockBtn" onclick="attLock()">锁定考勤</button>
      <button class="btn success" onclick="attExport()">导出考勤</button>
      ${isProj ? "" : `<button class="btn danger sm" onclick="attDelete()">删除本项目本月考勤</button>`}
    </div>
    <div id="attMsg"></div>
    <div class="hint">流程：先下载模板→项目人力按符号填写→上传。上传校验：姓名必须在本项目人员档案中；符号必须在符号库内（超管可在"考勤符号库设置"维护）；同月重复上传直接覆盖。</div>
  </div>
  <div class="card"><h3>考勤统计预览</h3><div id="attArea"><div class="msg info">选择项目后点击"查看已上传数据"。</div></div></div>`;
}
function attProjSel() {
  return state.user.role === "project" ? state.user.project : document.getElementById("attProj").value;
}
async function attTemplate() {
  const p = attProjSel();
  download(`/api/attendance/template?ym=${state.month}&project=${encodeURIComponent(p)}`, `考勤表模板_${p}_${state.month}.xlsx`);
}
async function attUpload() {
  const f = document.getElementById("attFile").files[0];
  if (!f) return;
  const proj = attProjSel();
  const form = new FormData();
  form.append("ym", state.month);
  form.append("project", proj);
  form.append("file", f);
  form.append("dry_run", "1");
  const msg = document.getElementById("attMsg");
  msg.innerHTML = `<div class="msg info">正在校验考勤表...</div>`;
  try {
    const r = await api("/api/attendance/upload", { form });
    // 显示预览确认
    let warnHtml = "";
    if (r.resigned && r.resigned.length) warnHtml += `<div style="color:#d97706;margin-top:6px">⚠ 以下人员本月之前已离职，不参与核算：${esc(r.resigned.slice(0,10).join("、"))}${r.resigned.length>10?"等":""}</div>`;
    if (r.has_calc) warnHtml += `<div style="color:#dc2626;margin-top:6px">⚠ ${state.month} 已有该项目核算结果，确认上传后需重新核算！</div>`;
    if (r.overwrite) warnHtml += `<div style="color:#d97706;margin-top:6px">⚠ 将覆盖已有的考勤数据！</div>`;
    msg.innerHTML = `<div class="msg ok" style="border-color:#16a34a">
      <div style="font-weight:600;margin-bottom:6px">校验通过：${r.count} 人${r.overwrite ? "（将覆盖旧数据）" : ""}</div>
      <div style="font-size:12px;color:#64748b;max-height:120px;overflow-y:auto">${esc(r.names.join("、"))}</div>
      ${warnHtml}
      <div class="row" style="margin-top:10px">
        <button class="btn success" onclick="attUploadConfirm()">③ 确认上传</button>
        <button class="btn" onclick="document.getElementById('attMsg').innerHTML='';document.getElementById('attFile').value=''">取消</button>
      </div>
    </div>`;
  } catch (e) { msg.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function attUploadConfirm() {
  const f = document.getElementById("attFile").files[0];
  if (!f) return;
  const form = new FormData();
  form.append("ym", state.month);
  form.append("project", attProjSel());
  form.append("file", f);
  const msg = document.getElementById("attMsg");
  msg.innerHTML = `<div class="msg info">正在正式上传...</div>`;
  try {
    const r = await api("/api/attendance/upload", { form });
    msg.innerHTML = `<div class="msg ok">上传成功：${r.count} 人${r.overwrite ? "（已覆盖旧数据）" : ""}${r.warning ? "<br>⚠ " + esc(r.warning) : ""}</div>`;
    document.getElementById("attFile").value = "";
    attView();
  } catch (e) { msg.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function attRowSel(el) {
  const rows = el.parentNode ? el.parentNode.querySelectorAll("tr.att-hl") : [];
  rows.forEach(r => { if (r !== el) r.classList.remove("att-hl"); });
  el.classList.toggle("att-hl");
}
async function attView() {
  const area = document.getElementById("attArea");
  try {
    const p = attProjSel();
    const data = await api(`/api/attendance?ym=${state.month}&project=${encodeURIComponent(p)}`);
    const names = Object.keys(data.rows);
    if (!names.length) { area.innerHTML = `<div class="msg info">${p} ${state.month} 暂无考勤数据。</div>`; return; }
    let html = `<div class="row" style="margin-bottom:8px"><span class="tag blue">${esc(p)} ${state.month}</span>
      <span class="tag green">${names.length} 人</span>
      <span class="tag gray">上传：${esc(data.meta.uploaded_by || "")} ${esc(data.meta.uploaded_at || "")}</span>
      ${data.locked ? `<span class="tag" style="background:#fef2f2;color:#dc2626;border-color:#fecaca">🔒 已锁定</span>` : `<span class="tag">未锁定</span>`}</div>
    <div class="table-wrap"><table class="tb"><thead><tr>
      <th>姓名</th><th>职位</th><th>人员状态</th><th>应出勤</th><th>实出勤</th><th>应出勤(符号统计)</th><th>事假</th><th>病假</th><th>产假</th><th>带薪假</th>
      <th>缺卡</th><th>旷工</th><th>迟到</th><th>早退</th><th>绩效系数</th><th>餐补</th><th>奖励</th><th>扣罚</th>
      <th>养老</th><th>医疗</th><th>失业</th><th>公积金</th><th>大病</th><th>缺卡扣款</th><th>迟早扣款</th><th>其他扣款</th><th>工装扣款</th></tr></thead><tbody>`;
    for (const n of names) {
      const a = data.rows[n], s = data.stats[n] || {};
      html += `<tr onclick="attRowSel(this)"><td>${esc(n)}</td><td>${esc(a.position)}</td><td>${esc(a.status || "-")}</td>
        <td class="num">${a.req_attend || "-"}</td><td class="num">${a.act_attend > 0 ? a.act_attend : s.attend}</td><td class="num">${s.required}</td>
        <td class="num">${s.personal}</td><td class="num">${s.sick}</td><td class="num">${s.maternity}</td><td class="num">${s.paid}</td>
        <td class="num">${s.miss}</td><td class="num">${s.absent}</td><td class="num">${s.late}</td><td class="num">${s.early}</td>
        <td class="num">${a.coef == null ? 0 : a.coef}</td><td class="num">${money(a.meal_sub)}</td><td class="num">${money(a.reward)}</td><td class="num">${money(a.punish)}</td>
        <td class="num">${money(a.pen)}</td><td class="num">${money(a.med)}</td><td class="num">${money(a.une)}</td>
        <td class="num">${money(a.house)}</td><td class="num">${money(a.big)}</td>
        <td class="num">${money(a.miss_deduct)}</td><td class="num">${money(a.late_deduct)}</td>
        <td class="num">${money(a.other_deduct)}</td><td class="num">${money(a.uniform_deduct)}</td></tr>`;
    }
    html += `</tbody></table></div>`;
    area.innerHTML = html;
    const lb = document.getElementById("attLockBtn");
    if (lb) { lb.textContent = data.locked ? "🔓 解锁考勤" : "🔒 锁定考勤"; lb.dataset.locked = data.locked ? "1" : "0"; }
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function attDelete() {
  const p = attProjSel();
  if (!confirm(`确认删除 ${p} ${state.month} 的考勤数据？`)) return;
  try {
    await api("/api/attendance/delete", { body: { ym: state.month, project: p } });
    toast("已删除"); attView();
  } catch (e) { alert(e.message); }
}
async function attLock() {
  const btn = document.getElementById("attLockBtn");
  const p = attProjSel();
  const willLock = !(btn && btn.dataset.locked === "1");
  if (willLock) {
    if (!confirm(`确认锁定 ${p} ${state.month} 考勤为最终版本？\n锁定后不能重传或删除。`)) return;
  } else {
    if (!confirm(`确认解锁 ${p} ${state.month} 考勤？`)) return;
  }
  try {
    await api("/api/attendance/lock", { body: { ym: state.month, project: p, locked: willLock } });
    toast(willLock ? "已锁定为最终版本" : "已解锁");
    attView();
  } catch (e) { alert(e.message); }
}
async function attExport() {
  const p = attProjSel();
  const types = [["", "全部人员"], ["管理人员", "管理人员"], ["基层人员", "基层人员"], ["案场人员", "案场人员"], ["总部人员", "总部人员"]];
  const sel = prompt("导出哪种人员？\n" + types.map((t,i)=>`${i}=${t[1]}`).join("  "), "0");
  if (sel === null) return;
  const t = types[parseInt(sel)] || types[0];
  const params = new URLSearchParams({ ym: state.month, project: p, staff_type: t[0] });
  try {
    const resp = await fetch("/api/attendance/export?" + params.toString(), { headers: { "X-Token": state.token || "" } });
    if (!resp.ok) { const e = await resp.json().catch(()=>({error:"导出失败"})); alert(e.error||"导出失败"); return; }
    const blob = await resp.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a"); a.href = url; a.download = `考勤导出_${p}_${state.month}_${t[1]}.xlsx`; a.click();
    URL.revokeObjectURL(url);
  } catch (e) { alert(e.message); }
}

/* ---------------- 组织架构辅助 ---------------- */
let ORG_TREE = null; // 组织树缓存
let ORG_SEL = null;  // 当前选中节点
// SVG 线性图标（lucide 风格），stroke 渲染
function svgIco(name, size, color, sw) {
  const paths = {
    building: 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M14 8h4a2 2 0 0 1 2 2v11M2 21h20M7 7h2M11 7h2M7 11h2M11 11h2M7 15h2M11 15h2M7 19h2M11 19h2',
    pin: 'M12 21s-7-5.1-7-11a7 7 0 1 1 14 0c0 5.9-7 11-7 11zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    folder: 'M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9L9.2 3.9A2 2 0 0 0 7.5 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z',
    plus: 'M12 5v14M5 12h14',
    pencil: 'M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z',
    trash: 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h14zM10 11v6M14 11v6',
    more: 'M5 12h.01M12 12h.01M19 12h.01',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
    network: 'M12 3a2 2 0 0 1 2 2c0 .4-.1.7-.3 1H17a3 3 0 0 1 3 3v.5M12 3a2 2 0 0 0-2 2c0 .4.1.7.3 1H7a3 3 0 0 0-3 3v.5M12 3v18M12 21a2 2 0 0 1-2-2c0-.4.1-.7.3-1M12 21a2 2 0 0 0 2-2c0-.4-.1-.7-.3-1M4 9.5A2.5 2.5 0 0 1 4 14.5M20 9.5a2.5 2.5 0 0 0 0 5M4 9.5V11a2 2 0 0 0 2 2h1.3M20 9.5V11a2 2 0 0 1-2 2h-1.3M6 13h1.3a2 2 0 0 1 1.4.6l2 2a2 2 0 0 0 1.4.6h2.6a2 2 0 0 0 2-2v-.2M12 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM12 21.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
    printer: 'M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z',
    arrow: 'M19 12H5M12 19l-7-7 7-7',
    zoomout: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35M8 11h6',
    zoomin: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35M11 8v6M8 11h6',
    x: 'M18 6L6 18M6 6l12 12',
    pause: 'M10 4H6v16h4zM18 4h-4v16h4z',
    play: 'M6 4l14 8-14 8z',
    user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    home: 'M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1V11z',
    dot: 'M12 12m-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0'
  };
  const d = paths[name] || paths.dot;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="${d}"/></svg>`;
}
const ORG_ICON = { company: "building", region: "pin", project: "pin", department: "folder", team: "folder" };
const ORG_COLOR = { company: "#165dff", region: "#7c3aed", project: "#7c3aed", department: "#00b42a", team: "#00b42a" };
const ORG_BG = { company: "#e8f3ff", region: "#f3edff", project: "#f3edff", department: "#e8ffea", team: "#e8ffea" };
function orgNodeIco(type, size) { return svgIco(ORG_ICON[type] || "dot", size, ORG_COLOR[type] || "#86909c", 2); }
async function loadOrgTree(force) {
  if (!ORG_TREE || force) { const d = await api("/api/org/tree"); ORG_TREE = d.tree; }
  return ORG_TREE;
}
function orgFlat(tree, out) {
  out = out || [];
  for (const n of tree) { out.push(n); orgFlat(n.children, out); }
  return out;
}
function orgLeaves() { return (ORG_TREE ? orgFlat(ORG_TREE) : []).filter(n => n.type === "department" || n.type === "team"); }
function orgNode(id) { return (ORG_TREE ? orgFlat(ORG_TREE) : []).find(n => n.id === Number(id)); }
function orgProjectOf(id) {
  let n = orgNode(id); const seen = new Set();
  while (n && !seen.has(n.id)) { seen.add(n.id); if (n.type === "project") return n; n = n.parent_id ? orgNode(n.parent_id) : null; }
  return null;
}
function orgPathOf(id) { const n = orgNode(id); return n ? n.path : ""; }
function orgLeafOptionsHtml(selId, current) {
  const leaves = orgLeaves();
  let html = `<select id="${selId}" onchange="sfOrgChanged()"><option value="">— 请选择部门/班组 —</option>`;
  for (const n of leaves) {
    const proj = orgProjectOf(n.id);
    const label = `${n.path}${proj && !n.path.includes(proj.name) ? "" : ""}`;
    html += `<option value="${n.id}" ${current === n.id ? "selected" : ""}>${esc(label)}</option>`;
  }
  return html + `</select>`;
}

/* ---------------- 人员档案 ---------------- */
async function pageStaff() {
  const c = document.getElementById("content");
  await refreshProjects();  // 人员档案项目下拉始终以最新项目档案为准
  try { await loadOrgTree(true); } catch (e) { ORG_TREE = ORG_TREE || []; }  // 强制刷新组织树，保证新项目及其部门立即可选
  c.innerHTML = `
  <div class="card"><h3>人员档案</h3>
    <div id="stCounts" style="margin-bottom:14px">加载中...</div>
    <div class="row">
      <label class="fld">项目 <select id="stProj" onchange="loadStaff()"><option value="">全部</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
      <label class="fld">部门 <select id="stOrg" onchange="loadStaff()"><option value="">全部部门</option></select></label>
      <label class="fld">状态 <select id="stStatus" onchange="loadStaff()"><option value="">全部</option><option>正式</option><option>试用</option><option>离职</option></select></label>
      <label class="fld">人员分类 <select id="stPersonType" onchange="loadStaff()"><option value="">全部</option><option value="staff">基层员工</option><option value="manager">管理人员</option><option value="case">案场人员</option></select></label>
      <input type="text" id="stKw" placeholder="姓名/职位搜索" onkeydown="if(event.key==='Enter')loadStaff()">
      <button class="btn primary" onclick="loadStaff()">查询</button>
      <button class="btn success" onclick="dingtalkSyncNow()">🔄 立即钉钉同步</button>
    </div>
    <div class="row" style="margin-top:8px">
      <span class="tag blue" id="stSelCount">已选 0 人</span>
      <button class="btn warn" onclick="staffBulkDeduct()">批量附加扣除设置</button>
      <button class="btn warn" onclick="staffBulkTaxMode()">批量个税模式</button>
      <button class="btn" onclick="staffExport()">📤 导出当前筛选</button>
    </div>
    <div id="stMsg"></div>
    <div id="staffArea" style="margin-top:12px">加载中...</div>
  </div>`;
  window._stSel = new Set();
  loadStaff();
}
function renderStCounts(counts) {
  const el = document.getElementById("stCounts");
  if (!el) return;
  const defs = [["在职", "#16a34a"], ["离职", "#64748b"], ["黑名单", "#dc2626"]];
  let total = 0; defs.forEach(d => total += (counts[d[0]] || 0));
  let html = `<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">`;
  html += `<div onclick="stSetCat('')" title="点击筛选全部人员" style="cursor:pointer;background:#fff;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.05);border:2px solid ${stCat === "" ? "#1e3a8a" : "transparent"}">
    <div style="font-size:12px;color:#8c8c8c;margin-bottom:4px">全部人员</div>
    <div style="font-size:22px;font-weight:bold;color:#1e3a8a;font-variant-numeric:tabular-nums">${total}</div></div>`;
  defs.forEach(([k, col]) => {
    html += `<div onclick="stSetCat('${k}')" title="点击筛选${k}人员" style="cursor:pointer;background:#fff;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.05);border-top:3px solid ${col};border:2px solid ${stCat === k ? col : "transparent"}">
      <div style="font-size:12px;color:#8c8c8c;margin-bottom:4px">${k}人员</div>
      <div style="font-size:22px;font-weight:bold;color:${col};font-variant-numeric:tabular-nums">${counts[k] || 0}</div></div>`;
  });
  el.innerHTML = html + `</div>`;
}
function stSetCat(c) { stCat = c; loadStaff(); }
function stToggleAll(on) {
  window._stSel = on ? new Set(window._staff.map(s => s.id)) : new Set();
  document.querySelectorAll(".stChk").forEach(cb => cb.checked = on);
  stUpdCount();
}
function stToggle(id, on) {
  if (on) window._stSel.add(id); else window._stSel.delete(id);
  stUpdCount();
}
function stUpdCount() {
  const el = document.getElementById("stSelCount");
  if (el) el.textContent = `已选 ${window._stSel.size} 人`;
}
let stCat = "在职";  // 人员档案分类筛选：在职/离职/黑名单，空=全部
const CAT_TAG = { "在职": "green", "离职": "gray", "黑名单": "red" };
async function loadStaff() {
  const area = document.getElementById("staffArea");
  try {
    if (!ORG_TREE) await loadOrgTree();
    fillOrgDeptFilter();
    const q = `cat=${encodeURIComponent(stCat)}&project=${encodeURIComponent(document.getElementById("stProj").value)}&status=${encodeURIComponent(document.getElementById("stStatus").value)}&person_type=${encodeURIComponent(document.getElementById("stPersonType").value)}&kw=${encodeURIComponent(document.getElementById("stKw").value)}&org_id=${encodeURIComponent(document.getElementById("stOrg").value)}`;
    const data = await api("/api/staff?" + q);
    window._staff = data.staff;
    renderStCounts(data.counts);
    let html = `<div class="row" style="margin-bottom:8px"><span class="tag gray">${data.staff.length} 人</span></div>
    <div class="table-wrap" style="overflow-x:auto"><table class="tb" style="min-width:1500px"><thead><tr>
      <th><input type="checkbox" onchange="stToggleAll(this.checked)"></th>
      <th>分类</th><th>姓名</th><th>项目</th><th>部门</th><th>职位</th><th>岗位职级</th><th>直属上级</th><th>员工状态</th>
      <th>性别</th><th>学历</th><th>籍贯</th><th>联系方式</th>
      <th>固定月薪</th><th>基本工资</th><th>个税模式</th>
      <th>入职时间</th><th>实际转正日期</th><th>离职日期</th><th>银行卡号</th><th>证件号码</th><th>操作</th></tr></thead><tbody>`;
    const leaders = new Map(window._staff.filter(x => x.id).map(x => [x.id, x.name]));
    for (const s of data.staff) {
      const leaderName = s.leader_id ? (leaders.get(s.leader_id) || "—") : "—";
      html += `<tr><td style="text-align:center"><input type="checkbox" class="stChk" ${window._stSel.has(s.id) ? "checked" : ""} onchange="stToggle(${s.id},this.checked)"></td>
        <td><span class="tag ${CAT_TAG[s.category] || "gray"}">${esc(s.category || "-")}</span></td>
        <td><b>${esc(s.name)}</b></td><td>${esc(s.project)}</td><td>${esc(s.dept_path || "未分配")}</td><td>${esc(s.position)}</td>
        <td><span class="tag ${s.person_type === "case" ? "green" : (s.person_type === "manager" ? "purple" : "gray")}">${s.person_type === "case" ? "案场人员" : (s.person_type === "manager" ? "管理人员" : "基层员工")}</span></td>
        <td>${esc(leaderName)}</td>
        <td><span class="tag ${STATUS_TAG[s.status] || "gray"}">${esc(s.status)}</span></td>
        <td>${esc(s.gender || "-")}</td><td>${esc(s.education || "-")}</td><td>${esc(s.hometown || "-")}</td><td>${esc(s.phone || "-")}</td>
        <td class="num">${money(s.fixed_monthly)}</td><td class="num">${money(s.base_salary)}</td>
        <td><span class="tag ${Number(s.tax_mode ?? 0) === 1 ? "blue" : "gray"}">${Number(s.tax_mode ?? 0) === 1 ? "6万扣除" : "普通"}</span></td>
        <td>${esc(s.hire_date || "-")}</td><td>${esc(s.regular_date || "-")}</td><td>${esc(s.resign_date || "-")}</td>
        <td>${esc((s.bank_card || "").replace(/^(\d{4})\d+(\d{4})$/, "$1****$2"))}</td>
        <td>${esc((s.id_card || "").replace(/^(.{4}).+(.{4})$/, "$1**********$2") || "-")}</td>
        <td><button class="btn sm" onclick="staffDeduct(${s.id})">附加扣除</button>
        <button class="btn sm" onclick="staffHistory(${s.id})">薪资历史</button>
        <button class="btn sm" onclick="staffTransfers(${s.id})">调动</button></td></tr>`;
    }
    html += `</tbody></table></div><div class="hint">顶部卡片点击可按分类筛选（在职/离职/黑名单），可再叠加项目、部门、状态、关键字条件后导出。档案状态按入职/离职日期自动判断：有离职日期（≤今天）→离职，否则→在职；黑名单需在编辑时勾选"加入黑名单"标记。档案状态与"人员状态"（正式/试用/离职，用于核算）相互独立。先选择所属项目，再选择该项目下已设立的部门。勾选多人后可批量删除、批量设置附加扣除；工资标准（固定月薪/基本工资）的变更请到"调薪与记录"模块操作，以留存全量调薪历史。导出文件含全部档案字段（含性别/学历/籍贯/联系方式/民族/婚姻/毕业院校/专业/证书/政治面貌/家庭住址等）。</div>`;
    area.innerHTML = html;
    stUpdCount();
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function fillOrgDeptFilter() {
  const el = document.getElementById("stOrg");
  if (!el) return;
  const projEl = document.getElementById("stProj");
  const proj = projEl ? projEl.value : "";  // 当前选中项目名，空=全部项目
  const prev = el.value;
  if (!proj) {
    // 未选项目：部门不可选，避免列出所有项目的部门
    el.disabled = true;
    el.innerHTML = `<option value="">请先选择项目</option>`;
    return;
  }
  el.disabled = false;
  let html = `<option value="">全部部门</option>`;
  const seen = new Set();
  for (const n of orgLeaves()) {
    // 选了项目时，只保留归属该项目的部门/班组（先选项目→部门只能选该项目下已设立的）
    const pn = orgProjectOf(n.id);
    if (!pn || pn.name !== proj) continue;
    if (seen.has(n.path)) continue; seen.add(n.path);
    html += `<option value="${n.id}">${esc(n.path)}</option>`;
  }
  el.innerHTML = html;
  // 切换项目后，原选中部门若已不属于当前项目则清空
  el.value = [...el.options].some(o => o.value === prev) ? prev : "";
}
async function staffExport() {
  const q = `cat=${encodeURIComponent(stCat)}&project=${encodeURIComponent(document.getElementById("stProj").value)}&status=${encodeURIComponent(document.getElementById("stStatus").value)}&kw=${encodeURIComponent(document.getElementById("stKw").value)}&org_id=${encodeURIComponent(document.getElementById("stOrg").value)}`;
  download("/api/staff/export?" + q, "人员档案.xlsx");
}
async function staffBulkDelete() {
  if (!window._stSel.size) return toast("请先勾选人员", false);
  if (!confirm(`确认删除所选 ${window._stSel.size} 人？删除后不参与核算，可在备份中找回。`)) return;
  try {
    const r = await api("/api/staff/bulk_delete", { body: { ids: [...window._stSel] } });
    toast(`已删除 ${r.count} 人`);
    window._stSel = new Set();
    loadStaff();
  } catch (e) { alert(e.message); }
}
function staffBulkDeduct() {
  if (!window._stSel.size) return toast("请先勾选人员", false);
  let html = `<h3>批量附加扣除设置（${window._stSel.size} 人）</h3>
  <div class="msg info">为所选人员统一设置6项专项附加扣除的月度金额与生效月份。</div>
  <div class="form-grid">`;
  DEDUCT_ITEMS.forEach((item, i) => {
    html += `<label>${item}（元/月）<input type="number" step="0.01" id="bd_${i}" value="0"></label>
    <label>　生效月份（空=年初起）<input type="month" id="bf_${i}"></label>`;
  });
  html += `</div><div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="bulkDeductSave()">保存</button></div>`;
  modal(html);
}
async function bulkDeductSave() {
  const items = DEDUCT_ITEMS.map((item, i) => ({
    item, amount: parseFloat(document.getElementById("bd_" + i).value || 0),
    from_ym: document.getElementById("bf_" + i).value,
  }));
  try {
    const r = await api("/api/staff/bulk_deduct", { body: { ids: [...window._stSel], items } });
    toast(`已为 ${r.count} 人设置附加扣除`);
    closeModal();
    loadStaff();
  } catch (e) { alert(e.message); }
}
function staffBulkTaxMode() {
  if (!window._stSel.size) return toast("请先勾选人员", false);
  const html = `<h3>批量个税扣除模式设置（${window._stSel.size} 人）</h3>
  <div class="msg info">普通模式：每月按 5000 元累计减除费用（常规预扣）；<br>6万扣除模式：年初一次性按全年 6 万元减除费用扣除，累计收入不超 6 万元的月份不预扣个税（适用于上年度全年收入≤6万且在同一单位的人员，最终以汇算清缴为准）。</div>
  <div class="form-grid">
    <label>扣除模式<select id="bt_mode">
      <option value="0">普通模式（每月5000累计）</option>
      <option value="1">6万扣除模式（年初一次性6万）</option>
    </select></label>
  </div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="bulkTaxModeSave()">保存</button></div>`;
  modal(html);
}
async function bulkTaxModeSave() {
  const mode = parseInt(document.getElementById("bt_mode").value || 0);
  try {
    const r = await api("/api/staff/bulk_tax_mode", { body: { ids: [...window._stSel], mode } });
    toast(`已为 ${r.count} 人设置${mode === 1 ? "6万扣除" : "普通"}模式`);
    closeModal();
    loadStaff();
  } catch (e) { alert(e.message); }
}
function sfOrgChanged() {
  const sel = document.getElementById("sf_org");
  const projSel = document.getElementById("sf_proj");
  if (!sel) return;
  const id = sel.value;
  if (id && projSel) {
    const proj = orgProjectOf(id);
    if (proj && String(projSel.value) !== String(proj.id)) projSel.value = proj.id;
  }
  const dl = document.getElementById("sf_pos_list");
  if (dl && id) api("/api/org/positions?node_id=" + id).then(d => {
    if (d.positions) dl.innerHTML = d.positions.map(p => `<option value="${esc(p)}">`).join("");
  }).catch(() => {});
}
async function staffTransfers(id) {
  const s = window._staff.find(x => x.id === id);
  try {
    const data = await api(`/api/org/transfer-logs?staff_id=${id}`);
    let html = `<h3>调动履历 — ${esc(s ? s.name : "")}</h3>`;
    if (!data.logs.length) html += `<div class="hint">暂无调动记录</div>`;
    else {
      html += `<table class="tb"><thead><tr><th>时间</th><th>原部门</th><th>新部门</th><th>原因</th><th>操作人</th></tr></thead><tbody>`;
      for (const l of data.logs) {
        html += `<tr><td>${esc(l.ts || l.change_date || "-")}</td><td>${esc(l.from_path || "—")}</td><td>${esc(l.to_path || "—")}</td><td>${esc(l.reason || "")}</td><td>${esc(l.by_user || "")}</td></tr>`;
      }
      html += `</tbody></table>`;
    }
    html += `<div class="row end" style="margin-top:12px"><button class="btn" onclick="closeModal()">关闭</button></div>`;
    modal(html, 720);
  } catch (e) { alert(e.message); }
}
const DEDUCT_ITEMS = ["租房租金", "住房贷款利息", "子女教育", "赡养老人", "继续教育", "婴幼儿照护"];
function staffDeduct(id) {
  const s = window._staff.find(x => x.id === id);
  const sd = s.special_deductions;
  const list = Array.isArray(sd) ? sd : (sd && typeof sd === "object" ? Object.keys(sd).map(k => ({ item: k, amount: (typeof sd[k] === "object" && sd[k] !== null) ? sd[k].amount : sd[k], from_ym: (typeof sd[k] === "object" && sd[k] !== null) ? (sd[k].from_ym || "") : "" })) : []);
  const map = {};
  list.forEach(e => map[e.item] = e);
  let html = `<h3>个税专项附加扣除 — ${esc(s.name)}</h3>
  <div class="msg info">默认年度固定；年中变更时填写"生效月份"，自该月起按新金额扣除。金额为国家标准的月度金额（如子女教育2000元/月/孩）。</div>
  <div class="form-grid">`;
  for (const item of DEDUCT_ITEMS) {
    const e = map[item] || { amount: 0, from_ym: "" };
    html += `<label>${item}（元/月）<input type="number" step="0.01" id="dd_${DEDUCT_ITEMS.indexOf(item)}" value="${e.amount}"></label>
    <label>　生效月份（空=年初起）<input type="month" id="df_${DEDUCT_ITEMS.indexOf(item)}" value="${esc(e.from_ym || "")}"></label>`;
  }
  html += `</div><div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="deductSave(${id})">保存</button></div>`;
  modal(html);
}
async function deductSave(id) {
  const items = DEDUCT_ITEMS.map((item, i) => ({
    item, amount: parseFloat(document.getElementById("dd_" + i).value || 0),
    from_ym: document.getElementById("df_" + i).value,
  }));
  try {
    await api("/api/staff/deduct", { body: { staff_id: id, items } });
    const s = window._staff.find(x => x.id === id);
    if (s) s.special_deductions = items;
    closeModal(); toast("已保存"); loadStaff();
  } catch (e) { alert(e.message); }
}
async function staffHistory(id) {
  const s = window._staff.find(x => x.id === id);
  try {
    const data = await api(`/api/staff_history?id=${id}`);
    const adj = (await api(`/api/salary_adjusts?id=${id}`)).adjusts;
    let html = `<h3>薪资历史 — ${esc(s.name)}（当前：固定${money(s.fixed_monthly)} / 基本${money(s.base_salary)}）</h3>
    <table class="tb"><thead><tr><th>生效日期</th><th>类型</th><th>固定月薪</th><th>基本工资</th><th>说明</th></tr></thead><tbody>`;
    for (const h of data.history) {
      html += `<tr><td>${esc(h.effective_date || "—")}</td><td>${esc(h.type)}</td><td class="num">${money(h.fixed_monthly)}</td><td class="num">${money(h.base_salary)}</td><td>${esc(h.note || "")}</td></tr>`;
    }
    html += `</tbody></table>`;
    if (adj.length) {
      html += `<h3 style="margin-top:12px">调薪操作记录</h3><table class="tb"><thead><tr><th>时间</th><th>操作人</th><th>类型</th><th>生效日期</th><th>原固定/基本</th><th>新固定/基本</th><th>增减(固定)</th><th>备注</th></tr></thead><tbody>`;
      for (const a of adj) {
        html += `<tr><td>${esc(a.ts)}</td><td>${esc(a.by)}</td><td>${esc(a.type)}</td><td>${esc(a.effective_date)}</td>
          <td class="num">${money(a.old_fixed)} / ${money(a.old_base)}</td><td class="num">${money(a.new_fixed)} / ${money(a.new_base)}</td>
          <td class="num">${a.delta_fixed >= 0 ? "+" : ""}${money(a.delta_fixed)}</td><td>${esc(a.note)}</td></tr>`;
      }
      html += `</tbody></table>`;
    }
    html += `<div class="row end" style="margin-top:12px"><button class="btn" onclick="closeModal()">关闭</button></div>`;
    modal(html, 760);
  } catch (e) { alert(e.message); }
}

/* ---------------- 调薪记录 ---------------- */
async function pageAdjust() {
  const c = document.getElementById("content");
  c.innerHTML = `
  <div class="card"><h3>发起调薪 / 转正定薪</h3>
    <div class="row">
      <input type="text" id="adjSearch" placeholder="输入姓名搜索人员" style="width:180px">
      <button class="btn" onclick="adjSearchGo()">查找</button>
    </div>
    <div id="adjPick" style="margin-top:10px"></div>
    <div id="adjForm"></div>
  </div>
  <div class="card"><h3>全量调薪历史流水</h3>
    <div class="row" style="margin-bottom:10px"><label class="fld">按项目 <select id="adjProjF" onchange="loadAdjusts()"><option value="">全部</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label></div>
    <div id="adjList">加载中...</div>
  </div>`;
  loadAdjusts();
}
async function adjSearchGo() {
  const kw = document.getElementById("adjSearch").value.trim();
  const data = await api(`/api/staff?kw=${encodeURIComponent(kw)}`);
  const el = document.getElementById("adjPick");
  if (!data.staff.length) { el.innerHTML = `<div class="msg err">未找到匹配人员</div>`; return; }
  el.innerHTML = data.staff.slice(0, 12).map(s =>
    `<span class="tag blue" style="cursor:pointer;margin:3px" onclick='adjPick(${JSON.stringify({ id: s.id, name: s.name, project: s.project, status: s.status, fixed: s.fixed_monthly, base: s.base_salary }).replace(/'/g, "&#39;")})'>${esc(s.name)}（${esc(s.project)}·${esc(s.status)}）</span>`).join("");
}
function adjPick(s) {
  window._adjStaff = s;
  document.getElementById("adjForm").innerHTML = `
  <div class="msg info">已选择：<b>${esc(s.name)}</b>（${esc(s.project)}，${esc(s.status)}）　现有薪资：固定 ${money(s.fixed)} / 基本 ${money(s.base)}</div>
  <div class="form-grid" style="max-width:680px">
    <label>变更类型<select id="aj_type"><option>调薪</option><option>转正</option></select></label>
    <label>生效日期（按此日期拆分当月工资）<input type="date" id="aj_date"></label>
    <label>调整后固定月薪<input type="number" id="aj_fixed" value="${s.fixed}"></label>
    <label>调整后基本工资<input type="number" id="aj_base" value="${s.base}"></label>
    <label class="full">备注<input type="text" id="aj_note" placeholder="如：年度调薪/试用期转正"></label>
  </div>
  <div class="hint">月中生效自动拆分：生效日前按原薪资、当日起按新薪资，各段按出勤折算；试用期段无绩效。记录永久存档可追溯。</div>
  <div class="row" style="margin-top:10px"><button class="btn primary" onclick="adjSubmit()">提交调薪</button></div>`;
}
async function adjSubmit() {
  const s = window._adjStaff;
  const g = x => document.getElementById(x).value;
  try {
    await api("/api/salary_adjust", { body: { staff_id: s.id, type: g("aj_type"), effective_date: g("aj_date"),
      fixed_monthly: g("aj_fixed"), base_salary: g("aj_base"), note: g("aj_note") } });
    toast("调薪已记录");
    document.getElementById("adjForm").innerHTML = "";
    document.getElementById("adjPick").innerHTML = "";
    loadAdjusts();
  } catch (e) { alert(e.message); }
}
async function loadAdjusts() {
  const area = document.getElementById("adjList");
  try {
    const data = await api("/api/salary_adjusts");
    const pf = document.getElementById("adjProjF") ? document.getElementById("adjProjF").value : "";
    let list = data.adjusts.slice().reverse();
    if (pf) list = list.filter(a => a.project === pf);
    if (!list.length) { area.innerHTML = `<div class="msg info">暂无调薪记录。</div>`; return; }
    let html = `<div class="table-wrap"><table class="tb"><thead><tr><th>时间</th><th>操作人</th><th>姓名</th><th>项目</th><th>类型</th><th>生效日期</th><th>原固定/基本</th><th>新固定/基本</th><th>固定增减</th><th>备注</th></tr></thead><tbody>`;
    for (const a of list) {
      html += `<tr><td>${esc(a.ts)}</td><td>${esc(a.by)}</td><td>${esc(a.name)}</td><td>${esc(a.project)}</td>
        <td><span class="tag ${a.type === "转正" ? "purple" : "blue"}">${esc(a.type)}</span></td>
        <td>${esc(a.effective_date)}</td><td class="num">${money(a.old_fixed)} / ${money(a.old_base)}</td>
        <td class="num">${money(a.new_fixed)} / ${money(a.new_base)}</td>
        <td class="num" style="color:${a.delta_fixed >= 0 ? "#16a34a" : "#dc2626"}">${a.delta_fixed >= 0 ? "增 " : "减 "}${money(Math.abs(a.delta_fixed))}</td>
        <td>${esc(a.note)}</td></tr>`;
    }
    area.innerHTML = html + `</tbody></table></div>`;
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}

/* ---------------- 预算管理 ---------------- */
function budgetYear() {
  return state.month.slice(0, 4);
}
async function pageBudget() {
  const c = document.getElementById("content");
  const year = budgetYear();
  c.innerHTML = `
  <div class="card"><h3>项目薪资预算管理</h3>
    <div class="row">
      <label class="fld">预算年度 <select id="budYear" onchange="state.year=this.value;loadBudget()">
        ${[year - 1, year, year + 1].map(y => `<option ${y == (state.year || year) ? "selected" : ""}>${y}</option>`).join("")}</select></label>
      <button class="btn primary" onclick="loadBudget()">查询</button>
      <button class="btn" onclick="download('/api/budget/template?year=${state.year || year}','预算导入模板.xlsx')">下载导入模板</button>
      <input type="file" id="budFile" accept=".xlsx" style="display:none" onchange="budgetImport()">
      <button class="btn" onclick="document.getElementById('budFile').click()">Excel导入预算</button>
    </div>
    <div id="budMsg"></div>
  </div>
  <div id="budArea">加载中...</div>`;
  if (!state.year) state.year = year;
  loadBudget();
}
async function loadBudget() {
  const area = document.getElementById("budArea");
  const year = state.year || budgetYear();
  try {
    const data = await api(`/api/budget/view?year=${year}`);
    let html = "";
    for (const it of data.items) {
      html += `<div class="card"><h3>${esc(it.project)} · ${year}年度预算　<span class="tag ${it.status === "启用" ? "green" : "gray"}">${it.status}</span></h3>
      <div class="row" style="margin-bottom:10px">
        <label class="fld">年度总预算(元) <input type="number" step="0.01" style="width:150px" id="ba_${esc(it.project)}" value="${it.annual || 0}" oninput="budgetSyncAnnual('${esc(it.project)}')"></label>
        <span class="hint" style="margin:0">年度总预算自动 = 各月预算之和；年度执行率 = 本年累计实发 ÷ 年度总预算 = <b id="br_${esc(it.project)}">${pct(it.annual_rate)}</b></span>
      </div>
      <div class="table-wrap"><table class="tb"><thead><tr><th>月份</th>${[1,2,3,4,5,6,7,8,9,10,11,12].map(m => `<th>${m}月</th>`).join("")}</tr></thead><tbody>
      <tr><td>月度预算</td>${[1,2,3,4,5,6,7,8,9,10,11,12].map(m => `<td class="num"><input type="number" step="0.01" style="width:80px" data-bp="${esc(it.project)}" data-bm="${m}" value="${it.months_budget[String(m)] || 0}" oninput="budgetSyncAnnual('${esc(it.project)}')"></td>`).join("")}</tr>
      <tr><td>当月实发</td>${[1,2,3,4,5,6,7,8,9,10,11,12].map(m => `<td class="num">${money(it.months_actual[String(m)])}</td>`).join("")}</tr>
      <tr><td>月度执行率</td>${[1,2,3,4,5,6,7,8,9,10,11,12].map(m => `<td class="num" id="mr_${esc(it.project)}_${m}">${pct(it.month_rates[String(m)])}</td>`).join("")}</tr>
      </tbody></table></div>
      <div class="hint">年中修改预算后，所有历史月份执行率自动按新预算重新计算；预算为0或无产出时执行率显示0。</div>
      <div class="row" style="margin-top:8px"><button class="btn primary" onclick="budgetSave('${esc(it.project)}')">保存预算</button></div>
      </div>`;
    }
    area.innerHTML = html || `<div class="card"><div class="msg info">暂无项目，请先在"项目档案"中添加。</div></div>`;
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function budgetSyncAnnual(proj) {
  const sum = Array.from(document.querySelectorAll(`input[data-bp="${proj}"]`))
    .reduce((s, inp) => s + (Number(inp.value) || 0), 0);
  const ba = document.getElementById("ba_" + proj);
  if (ba) ba.value = Math.round(sum * 100) / 100;
}
async function budgetSave(proj) {
  const year = state.year || budgetYear();
  const months = {};
  document.querySelectorAll(`input[data-bp="${proj}"]`).forEach(inp => { months[inp.dataset.bm] = parseFloat(inp.value || 0); });
  const annual = Object.values(months).reduce((s, v) => s + (Number(v) || 0), 0); // 年度总预算=各月之和
  try {
    await api("/api/budget/save", { body: { year, project: proj, annual, months } });
    toast("预算已保存，执行率已同步");
    loadBudget();
  } catch (e) { alert(e.message); }
}
async function budgetImport() {
  const f = document.getElementById("budFile").files[0];
  if (!f) return;
  const form = new FormData();
  form.append("year", state.year || budgetYear());
  form.append("file", f);
  try {
    const r = await api("/api/budget/import", { form });
    document.getElementById("budMsg").innerHTML = `<div class="msg ok">导入成功：${r.count} 个项目</div>`;
    document.getElementById("budFile").value = "";
    loadBudget();
  } catch (e) { document.getElementById("budMsg").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}

/* ==================== 报表中心 ==================== */
const REPORT = { charts: {}, year: new Date().getFullYear(), ym: (() => { const d = new Date(); return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0"); })(), annual: false };
const PALETTE = ["#2563eb", "#f59e0b", "#16a34a", "#ef4444", "#8b5cf6", "#0ea5e9", "#f97316", "#10b981", "#e11d48", "#6366f1", "#14b8a6", "#d97706"];
function rc(key, canvasId, cfg) {
  const el = document.getElementById(canvasId);
  if (!el) return null;
  if (REPORT.charts[key]) { REPORT.charts[key].destroy(); delete REPORT.charts[key]; }
  REPORT.charts[key] = new Chart(el, cfg);
  return REPORT.charts[key];
}
function rLabel(v) { return v && typeof v === "object" ? v.label : v; }
function rValue(v) { return v && typeof v === "object" ? v.value : v; }
function rcBar(key, canvasId, labels, data, opt) {
  return rc(key, canvasId, {
    type: "bar",
    data: { labels, datasets: [{ label: (opt && opt.label) || "", data, backgroundColor: (opt && opt.color) || "#2563eb", borderRadius: 4, barThickness: (opt && opt.thick) || undefined }] },
    options: Object.assign({
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: !!(opt && opt.label) }, tooltip: (opt && opt.tooltip) || undefined },
      scales: { x: { ticks: { color: "#64748b", font: { size: 10 } }, grid: { color: "rgba(148,163,184,.15)" } }, y: { beginAtZero: true, ticks: { color: "#64748b", font: { size: 10 } }, grid: { color: "rgba(148,163,184,.15)" } } },
    }, (opt && opt.opts) || {}),
  });
}
function rcHBar(key, canvasId, labels, data, color, fmt) {
  return rc(key, canvasId, {
    type: "bar",
    data: { labels, datasets: [{ label: "", data, backgroundColor: color, borderRadius: 4, barThickness: 18 }] },
    options: { indexAxis: "y", responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: fmt ? { callbacks: { label: c => fmt(c.raw) } } : undefined },
      scales: { x: { beginAtZero: true, ticks: { color: "#64748b", font: { size: 10 } }, grid: { color: "rgba(148,163,184,.15)" } }, y: { ticks: { color: "#475569", font: { size: 11 } }, grid: { display: false } } } },
  });
}
function rcLine(key, canvasId, labels, series, extra) {
  const datasets = series.map((s, i) => ({
    label: s.label, data: s.data, borderColor: s.color || PALETTE[i % PALETTE.length],
    backgroundColor: s.color || PALETTE[i % PALETTE.length], tension: .3, borderWidth: 2, pointRadius: 3, fill: s.fill || false,
  }));
  return rc(key, canvasId, {
    type: "line", data: { labels, datasets },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: "top", labels: { boxWidth: 10, font: { size: 10 }, color: "#475569" } } },
      scales: { x: { ticks: { color: "#64748b", font: { size: 10 } }, grid: { color: "rgba(148,163,184,.15)" } }, y: { beginAtZero: true, ticks: { color: "#64748b", font: { size: 10 }, callback: v => (extra && extra.fmt) ? extra.fmt(v) : v }, grid: { color: "rgba(148,163,184,.15)" } } } },
  });
}
function rcDoughnut(key, canvasId, labels, data, colors, centerLabel, total, fmt) {
  return rc(key, canvasId, {
    type: "doughnut",
    data: { labels, datasets: [{ data, backgroundColor: colors.slice(0, labels.length), borderWidth: 2, borderColor: "#fff", hoverOffset: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: "58%",
      plugins: { legend: { position: "bottom", labels: { boxWidth: 10, font: { size: 10 }, color: "#475569", padding: 6 } },
        tooltip: { callbacks: { label: c => " " + c.label + "：" + (fmt ? fmt(c.parsed) : c.parsed) } } } },
    plugins: centerLabel && total !== undefined ? [{ id: "centerText", afterDraw(chart) {
      const ctx = chart.ctx, cx = (chart.chartArea.left + chart.chartArea.right) / 2, cy = (chart.chartArea.top + chart.chartArea.bottom) / 2;
      ctx.save(); ctx.textAlign = "center"; ctx.textBaseline = "middle";
      ctx.fillStyle = "#94a3b8"; ctx.font = '600 10px "Microsoft YaHei"'; ctx.fillText(centerLabel, cx, cy - 10);
      ctx.fillStyle = "#1e293b"; ctx.font = '800 15px "Microsoft YaHei"'; ctx.fillText(fmt ? fmt(total) : total, cx, cy + 10); ctx.restore();
    } }] : [],
  });
}
function rDelta(v, suffix) {
  if (v === null || v === undefined) return "";
  const up = v > 0, down = v < 0;
  const cls = up ? "color:#16a34a" : (down ? "color:#dc2626" : "color:#94a3b8");
  const arrow = up ? "↑" : (down ? "↓" : "—");
  return `<div class="rpt-delta" style="${cls};font-size:12px;margin-top:2px">较上期 ${arrow} ${Math.abs(v)}${suffix || "%"}</div>`;
}
function rMoney(v) { return "¥" + (Number(v) || 0).toLocaleString("zh-CN", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function rCard(label, value, delta, sub, color) {
  return `<div class="rpt-kpi" style="border-top:3px solid ${color}">
    <div class="rpt-kpi-lbl">${label}</div>
    <div class="rpt-kpi-num" style="color:${color}">${value}</div>
    ${delta ? rDelta(delta.delta, delta.suffix) : ""}${sub ? `<div class="rpt-kpi-sub">${sub}</div>` : ""}
  </div>`;
}
function rSection(title, bodyHtml) {
  return `<div class="card rpt-card" style="margin-top:16px"><div class="rpt-card-head">${title}</div>${bodyHtml}</div>`;
}
function rEmpty(msg) {
  return `<div class="msg info" style="text-align:center;padding:30px">${msg || "暂无数据"}</div>`;
}

/* ---- 报表总览（卡片页）---- */
async function pageReportHome() {
  const c = document.getElementById("content");
  const canHr = canPerm("hr_report"), canSal = canPerm("salary_report"), canAtt = canPerm("attendance_report");
  c.innerHTML = `<div class="card"><h3>报表总览</h3>
    <div class="msg info" style="margin-bottom:14px">点击下方报表卡片进入对应分析报表；各报表可按时段筛选并按项目/部门下钻。</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px">${[
      canHr ? { k: "hrReport", i: "👥", t: "人力资源报表", d: "在职结构 · 人员趋势 · 年龄/司龄/学历/籍贯 · 入离职", c: "#2563eb", p: 0 } : null,
      canSal ? { k: "salaryReport", i: "💰", t: "薪酬报表", d: "应发/实发 · 薪资构成 · 五险一金与个税 · 预算执行率 · 绩效工资", c: "#16a34a", p: 1 } : null,
      canAtt ? { k: "attReport", i: "📅", t: "考勤报表", d: "出勤率 · 迟到/早退/缺卡/旷工/请假 · 异常趋势 · 异常Top10", c: "#f59e0b", p: 2 } : null,
    ].filter(Boolean).map(x => `<div class="rpt-home-card" style="border-top:4px solid ${x.c}" onclick="nav('${x.k}')">
      <div class="rpt-home-ico" style="background:${x.c}22">${x.i}</div>
      <div class="rpt-home-name" style="color:${x.c}">${x.t}</div>
      <div class="rpt-home-desc">${x.d}</div>
      <div class="rpt-home-go" style="color:${x.c}">进入报表 →</div>
    </div>`).join("")}
    </div>
    <div class="hint" style="margin-top:14px">报表查看权限：管理员可在「系统设置 → 权限」中为各账号类型勾选报表权限；项目账号默认仅可见本项目数据。</div>
  </div>`;
}

/* ---- 人力资源报表 ---- */
async function pageHrReport() {
  const c = document.getElementById("content");
  const yearOpts = [], monthOpts = [];
  for (let y = 2020; y <= 2035; y++) yearOpts.push(`<option value="${y}" ${y == REPORT.year ? "selected" : ""}>${y} 年</option>`);
  for (let m = 1; m <= 12; m++) monthOpts.push(`<option value="${m}" ${m == Number(REPORT.ym.slice(5)) ? "selected" : ""}>${m} 月</option>`);
  c.innerHTML = `<div class="card"><h3>人力资源报表</h3>
    <div class="row" style="flex-wrap:wrap">
      <label class="fld">查看维度
        <select onchange="REPORT.annual=this.value==='annual';document.getElementById('hrYmRow').style.display=this.value==='annual'?'none':'flex'">
          <option value="month" ${REPORT.annual ? "" : "selected"}>按月</option>
          <option value="annual" ${REPORT.annual ? "selected" : ""}>按年</option>
        </select></label>
      <label class="fld">年份 <select id="hrYear" onchange="REPORT.year=Number(this.value)">${yearOpts.join("")}</select></label>
      <span id="hrYmRow" style="display:${REPORT.annual ? "none" : "flex"};gap:8px">
        <label class="fld">月份 <select id="hrMonth" onchange="REPORT.ym=REPORT.year+'-'+String(this.value).padStart(2,'0')">${monthOpts.join("")}</select></label>
      </span>
      <button class="btn primary" onclick="pageHrReport()">刷新</button>
    </div>
    <div id="hrReportArea" style="margin-top:12px">加载中...</div></div>`;
  try {
    const ym = REPORT.annual ? "" : REPORT.ym;
    const d = await api(`/api/report/hr?year=${REPORT.year}${ym ? "&ym=" + ym : ""}&annual=${REPORT.annual ? 1 : 0}`);
    hrRender(d);
  } catch (e) { document.getElementById("hrReportArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function hrRender(d) {
  const area = document.getElementById("hrReportArea");
  const k = d.kpis;
  const kpis = [
    rCard("在职员工总数", k.active, { delta: d.deltas.active }, "含试用，不含离职", "#2563eb"),
    rCard("本月入职", k.in, { delta: d.deltas.in }, null, "#16a34a"),
    rCard("本月离职", k.out, { delta: d.deltas.out }, null, "#dc2626"),
    rCard("本月转正", k.regular, { delta: d.deltas.regular }, null, "#8b5cf6"),
    rCard("人均薪酬", rMoney(k.avg_pay), { delta: d.deltas.avg_pay }, "当月应发/发放人数", "#f59e0b"),
    rCard("人均司龄", k.avg_tenure + " 年", null, "在职员工平均司龄", "#0ea5e9"),
  ];
  let html = `<div class="rpt-kpi-row">${kpis.join("")}</div>`;

  // 人员结构分析（按项目）+ 人员趋势
  html += `<div class="rpt-grid-2">
    ${rSection("人员结构分析（按项目）", d.structure.length ? `<div style="height:${Math.max(150, d.structure.length * 40)}px"><canvas id="hrChartStructure"></canvas></div>` : rEmpty("暂无项目数据"))}
    ${rSection("人员趋势（近6月）", d.trend.length ? `<div style="height:260px"><canvas id="hrChartTrend"></canvas></div>` : rEmpty())}
  </div>`;

  // 年龄 + 司龄
  html += `<div class="rpt-grid-2">
    ${rSection("年龄结构", `<div style="height:240px"><canvas id="hrChartAge"></canvas></div>`)}
    ${rSection("司龄分布", `<div style="height:240px"><canvas id="hrChartTenure"></canvas></div>`)}
  </div>`;

  // 学历 + 籍贯Top10
  html += `<div class="rpt-grid-2">
    ${rSection("学历分布", d.edu_dist.length ? `<div style="height:240px"><canvas id="hrChartEdu"></canvas></div>` : rEmpty("暂未录入学历信息"))}
    ${rSection("籍贯分布 Top10（按市）", d.hometown_top.length ? `<div style="height:${Math.max(180, d.hometown_top.length * 30)}px"><canvas id="hrChartHome"></canvas></div>` : rEmpty("暂未录入籍贯信息"))}
  </div>`;

  // 入职与离职分析 + 人员状态分布
  html += `<div class="rpt-grid-2">
    ${rSection("入职与离职分析（近6月）", `<div style="height:240px"><canvas id="hrChartInOut"></canvas></div>`)}
    ${rSection("人员状态分布", `<div style="height:240px"><canvas id="hrChartStatus"></canvas></div>`)}
  </div>`;

  area.innerHTML = html;
  if (d.structure.length) rcHBar("hrStruct", "hrChartStructure", d.structure.map(rLabel), d.structure.map(rValue), "#2563eb");
  if (d.trend.length) rcLine("hrTrend", "hrChartTrend", d.trend.map(t => t.month), [
    { label: "在职人数", data: d.trend.map(t => t.active), color: "#2563eb" },
    { label: "入职", data: d.trend.map(t => t.in), color: "#16a34a" },
    { label: "离职", data: d.trend.map(t => t.out), color: "#dc2626" },
  ]);
  rcBar("hrAge", "hrChartAge", d.age_dist.map(rLabel), d.age_dist.map(rValue), { label: "人数", color: "#8b5cf6" });
  rcBar("hrTenure", "hrChartTenure", d.tenure_dist.map(rLabel), d.tenure_dist.map(rValue), { label: "人数", color: "#0ea5e9" });
  if (d.edu_dist.length) rcHBar("hrEdu", "hrChartEdu", d.edu_dist.map(rLabel), d.edu_dist.map(rValue), "#16a34a");
  if (d.hometown_top.length) rcHBar("hrHome", "hrChartHome", d.hometown_top.map(rLabel), d.hometown_top.map(rValue), "#f59e0b");
  rcLine("hrInOut", "hrChartInOut", d.trend.map(t => t.month), [
    { label: "入职", data: d.trend.map(t => t.in), color: "#16a34a" },
    { label: "离职", data: d.trend.map(t => t.out), color: "#dc2626" },
  ]);
  rcBar("hrStatus", "hrChartStatus", d.status_dist.map(rLabel), d.status_dist.map(rValue), { label: "人数", color: "#64748b" });
}

/* ---- 薪酬报表 ---- */
async function pageSalaryReport() {
  const c = document.getElementById("content");
  const monthOpts = [];
  for (let m = 1; m <= 12; m++) monthOpts.push(`<option value="${m}" ${m == Number(REPORT.ym.slice(5)) ? "selected" : ""}>${m} 月</option>`);
  c.innerHTML = `<div class="card"><h3>薪酬报表</h3>
    <div class="row">
      <label class="fld">统计月份
        <select id="salYear" onchange="REPORT.year=Number(this.value);document.getElementById('salMonth').value=12;REPORT.ym=REPORT.year+'-12'">
          ${[...Array(16)].map((_, i) => { const y = 2020 + i; return `<option value="${y}" ${y == REPORT.year ? "selected" : ""}>${y} 年</option>`; }).join("")}
        </select>
        <select id="salMonth" onchange="REPORT.ym=REPORT.year+'-'+String(this.value).padStart(2,'0')">${monthOpts.join("")}</select>
      </label>
      <button class="btn primary" onclick="pageSalaryReport()">刷新</button>
    </div>
    <div id="salReportArea" style="margin-top:12px">加载中...</div></div>`;
  try {
    const d = await api(`/api/report/salary?ym=${REPORT.ym}`);
    salRender(d);
  } catch (e) { document.getElementById("salReportArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function salRender(d) {
  const area = document.getElementById("salReportArea");
  const colorOf = k => ({ active: "#2563eb", count: "#0ea5e9", gross: "#16a34a", net: "#10b981", avg: "#f59e0b",
    ytd_gross: "#8b5cf6", ytd_net: "#6366f1", tax: "#ef4444", soc: "#f97316", ytd_tax: "#dc2626", ytd_soc: "#d97706", budget: "#14b8a6", budget_rate: "#e11d48" }[k] || "#2563eb");
  const cards = d.kpis.map(k => {
    const isMoney = k.money;
    const value = isMoney ? rMoney(k.value) : (k.unit === "%" ? k.value + "%" : k.value);
    return rCard(k.label, value, k.delta !== null && k.delta !== undefined ? { delta: k.delta } : null, null, colorOf(k.key));
  }).join("");
  let html = `<div class="rpt-kpi-row">${cards}</div>`;

  html += `<div class="rpt-grid-2">
    ${rSection("月度工资总额趋势（近12月）", `<div style="height:260px"><canvas id="salChartTrend"></canvas></div>`)}
    ${rSection("各项目薪酬对比（当月应发）", d.project_compare.length ? `<div style="height:${Math.max(180, d.project_compare.length * 48)}px"><canvas id="salChartDept"></canvas></div>` : rEmpty("当月无核算数据"))}
  </div>`;

  html += `<div class="rpt-grid-2">
    ${rSection("累计应发工资金额（每月人数 + 累计应发）", `<div style="height:280px"><canvas id="salChartCum"></canvas></div>`)}
    ${rSection("人均薪酬趋势（近12月）", `<div style="height:260px"><canvas id="salChartAvg"></canvas></div>`)}
  </div>`;

  html += `<div class="rpt-grid-2">
    ${rSection("五险一金与个税月度趋势（近12月）", `<div style="height:260px"><canvas id="salChartSoc"></canvas></div>`)}
    ${rSection("月度预算执行率（近12月）", `<div style="height:260px"><canvas id="salChartBudget"></canvas></div>`)}
  </div>`;

  html += rSection("绩效工资分布（固定−基本，当月发放人员）", `<div style="height:240px"><canvas id="salChartPerf"></canvas></div>`);

  area.innerHTML = html;
  rcLine("salTrend", "salChartTrend", d.monthly_trend.map(t => t.month), [
    { label: "应发", data: d.monthly_trend.map(t => t.gross), color: "#16a34a" },
    { label: "实发", data: d.monthly_trend.map(t => t.net), color: "#2563eb" },
  ], { fmt: v => v >= 10000 ? (v / 10000).toFixed(1) + "万" : v });
  if (d.project_compare.length) rcHBar("salDept", "salChartDept", d.project_compare.map(x => x.label), d.project_compare.map(x => x.gross), "#0ea5e9", v => rMoney(v));
  // 累计应发趋势：柱=当月发放人数（左轴），线=当年累计应发金额（右轴）
  if (d.monthly_trend.length) rc("salCum", "salChartCum", {
    type: "bar",
    data: { labels: d.monthly_trend.map(t => t.month), datasets: [
      { type: "bar", label: "当月发放人数", data: d.monthly_trend.map(t => t.cnt), backgroundColor: "rgba(14,165,233,.55)", borderRadius: 4, yAxisID: "y" },
      { type: "line", label: "累计应发金额", data: d.monthly_trend.map(t => t.cum_gross), borderColor: "#f59e0b", backgroundColor: "#f59e0b", tension: .3, borderWidth: 2, pointRadius: 3, yAxisID: "y1" },
    ] },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: "top", labels: { boxWidth: 10, font: { size: 10 }, color: "#475569" } },
        tooltip: { callbacks: { label: c => c.datasetIndex === 0 ? " 当月发放人数：" + c.parsed.y + " 人" : " 累计应发：" + rMoney(c.parsed.y) } } },
      scales: {
        x: { ticks: { color: "#64748b", font: { size: 10 } }, grid: { color: "rgba(148,163,184,.15)" } },
        y: { beginAtZero: true, position: "left", ticks: { color: "#0284c7", font: { size: 10 } }, grid: { color: "rgba(148,163,184,.15)" }, title: { display: true, text: "人数（人）", color: "#0284c7", font: { size: 10 } } },
        y1: { beginAtZero: true, position: "right", ticks: { color: "#d97706", font: { size: 10 }, callback: v => v >= 10000 ? (v / 10000).toFixed(1) + "万" : v }, grid: { drawOnChartArea: false }, title: { display: true, text: "累计应发（元）", color: "#d97706", font: { size: 10 } } },
      } },
  });
  rcLine("salAvg", "salChartAvg", d.monthly_trend.map(t => t.month), [{ label: "人均应发", data: d.monthly_trend.map(t => t.avg), color: "#f59e0b" }], { fmt: v => rMoney(v) });
  rcLine("salSoc", "salChartSoc", d.monthly_trend.map(t => t.month), [
    { label: "五险一金", data: d.monthly_trend.map(t => t.soc), color: "#f97316" },
    { label: "个税", data: d.monthly_trend.map(t => t.tax), color: "#ef4444" },
  ], { fmt: v => v >= 10000 ? (v / 10000).toFixed(1) + "万" : v });
  rcLine("salBudget", "salChartBudget", d.budget_trend.map(t => t.month), [
    { label: "当月应发", data: d.budget_trend.map(t => t.gross), color: "#16a34a" },
    { label: "当月预算", data: d.budget_trend.map(t => t.budget), color: "#94a3b8" },
  ], { fmt: v => v >= 10000 ? (v / 10000).toFixed(1) + "万" : v });
  rcBar("salPerf", "salChartPerf", d.perf_dist.map(rLabel), d.perf_dist.map(rValue), { label: "人数", color: "#14b8a6" });
}

/* ---- 考勤报表 ---- */
async function pageAttReport() {
  const c = document.getElementById("content");
  const monthOpts = [];
  for (let m = 1; m <= 12; m++) monthOpts.push(`<option value="${m}" ${m == Number(REPORT.ym.slice(5)) ? "selected" : ""}>${m} 月</option>`);
  c.innerHTML = `<div class="card"><h3>考勤报表</h3>
    <div class="row">
      <label class="fld">统计月份
        <select id="attYear" onchange="REPORT.year=Number(this.value);document.getElementById('attMonth').value=12;REPORT.ym=REPORT.year+'-12'">
          ${[...Array(16)].map((_, i) => { const y = 2020 + i; return `<option value="${y}" ${y == REPORT.year ? "selected" : ""}>${y} 年</option>`; }).join("")}
        </select>
        <select id="attMonth" onchange="REPORT.ym=REPORT.year+'-'+String(this.value).padStart(2,'0')">${monthOpts.join("")}</select>
      </label>
      <button class="btn primary" onclick="pageAttReport()">刷新</button>
    </div>
    <div id="attReportArea" style="margin-top:12px">加载中...</div></div>`;
  try {
    const d = await api(`/api/report/attendance?ym=${REPORT.ym}`);
    attRender(d);
  } catch (e) { document.getElementById("attReportArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function attRender(d) {
  const area = document.getElementById("attReportArea");
  const ACOLOR = { "迟到": "#f59e0b", "早退": "#8b5cf6", "缺卡": "#ef4444", "旷工": "#dc2626", "事假": "#0ea5e9", "病假": "#10b981", "产假": "#e11d48" };
  let html = `<div class="rpt-kpi-row">${Object.keys(d.anomaly_totals).map(k => rCard(k + "（次）", d.anomaly_totals[k], null, null, ACOLOR[k] || "#64748b")).join("")}</div>`;

  // 出勤率汇总表
  let tbl = `<div class="table-wrap"><table class="tb"><thead><tr><th>项目</th><th>人数</th><th>应出勤(天)</th><th>实际出勤(天)</th><th>出勤率</th></tr></thead><tbody>`;
  if (!d.projects.length) tbl += `<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px">该月份暂无考勤数据</td></tr>`;
  for (const p of d.projects) tbl += `<tr><td><b>${esc(p.project)}</b></td><td>${p.headcount}</td><td>${p.required}</td><td>${p.actual}</td><td><b style="color:${p.rate >= 90 ? "#16a34a" : (p.rate >= 80 ? "#f59e0b" : "#dc2626")}">${p.rate}%</b></td></tr>`;
  tbl += `</tbody></table></div>`;
  html += rSection(`出勤率汇总（${d.ym} · 各项目）`, tbl);

  html += `<div class="rpt-grid-2">
    ${rSection("异常趋势（近6月）", `<div style="height:260px"><canvas id="attChartTrend"></canvas></div>`)}
    ${rSection("各项目出勤率对比", d.projects.length ? `<div style="height:${Math.max(160, d.projects.length * 40)}px"><canvas id="attChartRate"></canvas></div>` : rEmpty("暂无数据"))}
  </div>`;

  html += `<div class="rpt-grid-2">
    ${rSection("出勤符号构成", `<div style="height:260px"><canvas id="attChartSymbol"></canvas></div>`)}
    ${rSection("异常人员 Top10", d.top.length ? `<div class="table-wrap"><table class="tb"><thead><tr><th>#</th><th>姓名</th><th>项目</th><th>异常次数</th><th>异常类型</th></tr></thead><tbody>` + d.top.map((t, i) => `<tr><td>${i + 1}</td><td><b>${esc(t.name)}</b></td><td>${esc(t.project)}</td><td style="color:#dc2626;font-weight:700">${t.anomalyCount}</td><td>${esc((t.anomalies || []).join("、") || "-")}</td></tr>`).join("") + `</tbody></table></div>` : rEmpty("本月无异常记录"))}
  </div>`;

  area.innerHTML = html;
  const trendKeys = ["迟到", "早退", "缺卡", "旷工", "事假", "病假", "产假"];
  rcLine("attTrend", "attChartTrend", d.anomaly_trend.map(t => t.month),
    trendKeys.filter(k => d.anomaly_trend.some(t => t[k] > 0)).map(k => ({ label: k, data: d.anomaly_trend.map(t => t[k] || 0), color: ACOLOR[k] })));
  if (d.projects.length) rcHBar("attRate", "attChartRate", d.projects.map(p => p.project), d.projects.map(p => p.rate), "#f59e0b", v => v + "%");
  if (d.symbol_dist.length) rcHBar("attSymbol", "attChartSymbol", d.symbol_dist.map(rLabel), d.symbol_dist.map(rValue), "#0ea5e9");
}


const MAINT = {
  data: { fire: [], elevator: [] },
  partners: [],
  dashboard: null,
  dashYear: new Date().getFullYear(),
  charts: {},
  sort: { fire: { field: "end_date", dir: "asc" }, elevator: { field: "end_date", dir: "asc" } },
  focus: { type: null, id: null, projectFilter: null },
};
const MAINT_TYPE_LABEL = { fire: "消防", elevator: "电梯" };
const MAINT_STATUS_TEXT = { normal: "正常在保", soon: "30天内即将到期", expired: "已过期" };

function maintToday() {
  const d = new Date();
  return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
}
function maintAddDays(dstr, n) {
  const d = new Date(dstr + "T00:00:00");
  d.setDate(d.getDate() + n);
  return d.toISOString().slice(0, 10);
}
function maintStatusOf(endDate) {
  const t = maintToday();
  const thr = maintAddDays(t, 30);
  if (!endDate) return "normal";
  if (endDate < t) return "expired";
  if (endDate <= thr) return "soon";
  return "normal";
}
function maintOverlapsYear(start, end, year) {
  return start <= year + "-12-31" && end >= year + "-01-01";
}
function maintFmtMoney(n) { return (Number(n) || 0).toLocaleString("zh-CN"); }

/* ---------- 通用弹窗 ---------- */
function maintModalOpen(title, bodyHtml, onSave) {
  let mask = document.getElementById("maintModal");
  if (!mask) {
    mask = document.createElement("div");
    mask.id = "maintModal";
    mask.className = "maint-modal-mask";
    mask.innerHTML = `<div class="maint-modal-box">
      <div class="maint-modal-head"><span id="maintModalTitle"></span><button class="maint-modal-x" onclick="maintModalClose()">×</button></div>
      <div class="maint-modal-body" id="maintModalBody"></div>
      <div class="maint-modal-foot"><button class="btn" onclick="maintModalClose()">取消</button><button class="btn primary" id="maintModalSave">保存</button></div>
    </div>`;
    document.body.appendChild(mask);
    // 点击遮罩空白处不再关闭弹窗，仅通过 × 关闭按钮或「取消」按钮关闭（用户要求）
    // mask.addEventListener("click", e => { if (e.target === mask) maintModalClose(); });
  }
  document.getElementById("maintModalTitle").textContent = title;
  document.getElementById("maintModalBody").innerHTML = bodyHtml;
  document.getElementById("maintModalSave").onclick = onSave;
  mask.classList.add("show");
}
function maintModalClose() {
  const mask = document.getElementById("maintModal");
  if (mask) mask.classList.remove("show");
}

/* ---------- 消防/电梯维保报表 ---------- */
const MAINT_REPORT = { year: new Date().getFullYear(), type: "fire" };
async function pageMaintFireReport() { MAINT_REPORT.type = "fire"; await pageMaintReport(); }
async function pageMaintElevReport() { MAINT_REPORT.type = "elevator"; await pageMaintReport(); }
async function pageMaintReport() {
  const c = document.getElementById("content");
  const isFire = MAINT_REPORT.type === "fire";
  const yearOpts = [];
  for (let y = 2020; y <= 2035; y++) yearOpts.push(`<option value="${y}" ${y == MAINT_REPORT.year ? "selected" : ""}>${y} 年</option>`);
  c.innerHTML = `<div class="card"><h3>${isFire ? "🧯 消防维保报表" : "🛗 电梯维保报表"}（${MAINT_REPORT.year}年）</h3>
    <div class="row">
      <label class="fld">统计年度 <select onchange="MAINT_REPORT.year=Number(this.value);pageMaintReport()">${yearOpts.join("")}</select></label>
      <button class="btn primary" onclick="pageMaintReport()">刷新</button>
      <button class="btn" onclick="nav('${isFire ? "maintFire" : "maintElev"}')">前往${isFire ? "消防" : "电梯"}台账</button>
    </div>
    <div id="maintReportArea" style="margin-top:12px">加载中...</div></div>`;
  try {
    const d = await api(`/api/maintenance/${isFire ? "fire" : "elev"}_report?year=${MAINT_REPORT.year}`);
    maintReportRender(d);
  } catch (e) { document.getElementById("maintReportArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function maintReportRender(d) {
  const area = document.getElementById("maintReportArea");
  const isFire = d.type === "fire";
  const STATE_COLOR = { normal: "#16a34a", soon: "#f59e0b", expired: "#dc2626" };
  const STATE_TEXT = { normal: "正常在保", soon: "30天内到期", expired: "已过期" };
  const scaleLabel = isFire ? "维保面积(㎡)" : "维保台数(台)";
  const kpis = [
    rCard("维保合同数", d.contract_count, null, "本年度有效合同", "#2563eb"),
    rCard("在管项目数", d.project_count, null, "覆盖物业项目", "#0ea5e9"),
    rCard("签约总金额", rMoney(d.total_amount), null, "本年度", "#16a34a"),
    rCard("风险合同", d.risk_total, null, "临期/已过期", "#ef4444"),
  ];
  let html = `<div class="rpt-kpi-row">${kpis.join("")}</div>`;

  // 状态分布 + 合作方金额
  html += `<div class="rpt-grid-2">
    ${rSection("合同状态分布", `<div style="height:240px"><canvas id="maintRptState"></canvas></div>`)}
    ${rSection("合作方金额分布", d.partner_amount.length ? `<div style="height:240px"><canvas id="maintRptPartner"></canvas></div>` : rEmpty("无签约合作方数据"))}
  </div>`;

  // 各项目金额 + 合作方维保面积/台数
  html += `<div class="rpt-grid-2">
    ${rSection("各项目维保金额", d.project_amount.length ? `<div style="height:${Math.max(180, d.project_amount.length * 44)}px"><canvas id="maintRptProj"></canvas></div>` : rEmpty("暂无项目数据"))}
    ${rSection(`各合作方${isFire ? "维保面积" : "维保台数"}分布`, d.partner_scale.length ? `<div style="height:${Math.max(180, d.partner_scale.length * 44)}px"><canvas id="maintRptScale"></canvas></div>` : rEmpty("暂无数据"))}
  </div>`;

  // 风险清单
  let riskTbl = `<div class="table-wrap"><table class="tb"><thead><tr><th>项目名称</th><th>签约方</th><th>签约金额</th><th>到期日期</th><th>剩余天数</th><th>员工状态</th></tr></thead><tbody>`;
  if (!d.risk_list.length) riskTbl += `<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px">✅ 本年度无临期/已过期合同</td></tr>`;
  for (const r of d.risk_list) {
    const diff = Math.ceil((new Date(r.end_date + "T00:00:00") - new Date()) / 86400000);
    const daysText = diff > 0 ? diff + "天" : (diff === 0 ? "今天" : Math.abs(diff) + "天前已过");
    riskTbl += `<tr><td><b>${esc(r.project)}</b></td><td>${esc(r.party)}</td><td class="num">${rMoney(r.amount)}</td><td>${esc(r.end_date)}</td><td>${daysText}</td><td><span class="tag ${r.status === "expired" ? "red" : "orange"}">${STATE_TEXT[r.status]}</span></td></tr>`;
  }
  riskTbl += `</tbody></table></div>`;
  html += rSection("⚡ 风险合同清单", riskTbl);

  area.innerHTML = html;
  rcBar("maintRptState", "maintRptState", d.state_dist.map(x => STATE_TEXT[x.label] || x.label), d.state_dist.map(rValue), { label: "合同数", color: "#f59e0b" });
  if (d.partner_amount.length) rcDoughnut("maintRptPartner", "maintRptPartner", d.partner_amount.map(rLabel), d.partner_amount.map(rValue), PALETTE, "总金额", d.total_amount, v => rMoney(v));
  if (d.project_amount.length) rcHBar("maintRptProj", "maintRptProj", d.project_amount.map(p => p.name), d.project_amount.map(p => p.amount), isFire ? "#f59e0b" : "#3b82f6", v => rMoney(v));
  if (d.partner_scale.length) rcHBar("maintRptScale", "maintRptScale", d.partner_scale.map(rLabel), d.partner_scale.map(rValue), isFire ? "#0ea5e9" : "#8b5cf6", v => v + (isFire ? " ㎡" : " 台"));
}

/* ---------- 工程维保驾驶舱 ---------- */
async function pageMaintDash() {
  const c = document.getElementById("content");
  const year = MAINT.dashYear;
  const yearOpts = [];
  for (let y = 2020; y <= 2035; y++) yearOpts.push(`<option value="${y}" ${y == year ? "selected" : ""}>${y} 年</option>`);
  c.innerHTML = `<div class="card"><h3>工程维保驾驶舱（${year}年）</h3>
    <div class="row">
      <label class="fld">统计年度 <select id="maintDashYear" onchange="MAINT.dashYear=Number(this.value);pageMaintDash()">${yearOpts.join("")}</select></label>
      <button class="btn primary" onclick="pageMaintDash()">刷新</button>
      <button class="btn" onclick="maintExportRisk()">导出风险清单</button>
    </div>
    <div id="maintDashArea" style="margin-top:12px">加载中...</div></div>`;
  try {
    const d = await api(`/api/maintenance/dashboard?year=${year}`);
    MAINT.dashboard = d;
    maintRenderDash(d);
  } catch (e) {
    document.getElementById("maintDashArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`;
  }
}

function maintRenderDash(d) {
  const area = document.getElementById("maintDashArea");
  const totalContracts = d.overview.fireCount + d.overview.elevatorCount;
  if (totalContracts === 0) {
    area.innerHTML = `<div class="msg info" style="text-align:center;padding:40px">📋 该年度暂无维保合同数据，请切换年份或在台账中录入合同。</div>`;
    return;
  }
  const fireTotal = d.fire.normal + d.fire.soon + d.fire.expired;
  const elevTotal = d.elev.normal + d.elev.soon + d.elev.expired;

  const kpi = (icon, label, num, unit, color) =>
    `<div class="maint-kpi" style="border-top:3px solid ${color}">
      <div class="maint-kpi-icon">${icon}</div>
      <div class="maint-kpi-body"><div class="maint-kpi-lbl">${label}</div>
      <div class="maint-kpi-num" style="color:${color}">${num}</div><div class="maint-kpi-unit">${unit}</div></div></div>`;

  let html = `<div class="maint-kpi-row">
    ${kpi("🏢", "在管项目总数", d.overview.projectCount, "个物业项目", "#2563eb")}
    ${kpi("🧯", "消防维保合同", d.overview.fireCount, `${fireTotal}份 · 正常${d.fire.normal}/临期${d.fire.soon}/过期${d.fire.expired}`, "#f59e0b")}
    ${kpi("🛗", "电梯维保合同", d.overview.elevatorCount, `${elevTotal}份 · 正常${d.elev.normal}/临期${d.elev.soon}/过期${d.elev.expired}`, "#3b82f6")}
    ${kpi("💰", "年度签约总金额", maintFmtMoney(d.overview.totalAmount), "元", "#16a34a")}
    ${kpi("⚠️", "临近到期风险", d.riskTotal, "份合同需关注续签", "#ef4444")}
  </div>`;

  // 图表上排：消防环形图 + 电梯环形图 + 状态分布
  html += `<div class="maint-grid-3" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:16px">
    <div class="card" style="margin:0"><h3>🧯 消防维保 · 合作方金额分布</h3>
      <div style="font-size:12px;color:#64748b;margin-bottom:4px">年度签约总额 <b style="color:#f59e0b;font-size:15px">${maintFmtMoney(d.overview.fireAmount)}</b> 元 · 签约 ${d.overview.fireProjectCount} 个项目</div>
      <div style="height:220px"><canvas id="maintChartPieFire"></canvas></div></div>
    <div class="card" style="margin:0"><h3>🛗 电梯维保 · 合作方金额分布</h3>
      <div style="font-size:12px;color:#64748b;margin-bottom:4px">年度签约总额 <b style="color:#3b82f6;font-size:15px">${maintFmtMoney(d.overview.elevAmount)}</b> 元 · 签约 ${d.overview.elevProjectCount} 个项目</div>
      <div style="height:220px"><canvas id="maintChartPieElev"></canvas></div></div>
    <div class="card" style="margin:0"><h3>合同状态分布</h3>${maintStatusBars(d, fireTotal, elevTotal, totalContracts)}</div>
  </div>`;

  // 图表下排：合作方项目数排行 + 电梯台数排行
  html += `<div class="maint-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
    <div class="card" style="margin:0"><h3>签约合作方 · 负责项目数排行</h3><div style="height:240px"><canvas id="maintChartProjects"></canvas></div></div>
    <div class="card" style="margin:0"><h3>签约合作方 · 电梯维保台数排行</h3><div style="height:240px"><canvas id="maintChartElev"></canvas></div></div>
  </div>`;

  // 各项目消防维保金额
  const fireProjs = (d.projectAmounts || []).filter(p => p.fireCount > 0);
  if (fireProjs.length) {
    html += `<div class="card" style="margin-top:16px"><h3>🧯 各项目消防维保金额（${d.year}年度）</h3>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px">
      ${fireProjs.map(p => `<div class="maint-proj-card" onclick="maintGoLedger('fire','${esc(p.name)}')" style="border-left:3px solid #f59e0b">
        <div style="font-weight:600">${esc(p.name)}</div><div style="font-size:18px;font-weight:700;color:#f59e0b">${maintFmtMoney(p.fireAmount)}</div>
        <div style="font-size:11px;color:#64748b">${p.fireCount}份合同${p.fireTotalArea ? ` · 📐${p.fireTotalArea.toLocaleString()}㎡` : ""}</div></div>`).join("")}
      </div><div style="height:${Math.max(120, fireProjs.length * 36)}px"><canvas id="maintChartProjFire"></canvas></div></div>`;
  }

  // 各项目电梯维保金额
  const elevProjs = (d.projectAmounts || []).filter(p => p.elevCount > 0);
  if (elevProjs.length) {
    html += `<div class="card" style="margin-top:16px"><h3>🛗 各项目电梯维保金额（${d.year}年度）</h3>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px">
      ${elevProjs.map(p => `<div class="maint-proj-card" onclick="maintGoLedger('elevator','${esc(p.name)}')" style="border-left:3px solid #3b82f6">
        <div style="font-weight:600">${esc(p.name)}</div><div style="font-size:18px;font-weight:700;color:#3b82f6">${maintFmtMoney(p.elevAmount)}</div>
        <div style="font-size:11px;color:#64748b">${p.elevCount}份合同 · 🛗${p.elevTotalUnits || 0}台</div></div>`).join("")}
      </div><div style="height:${Math.max(120, elevProjs.length * 36)}px"><canvas id="maintChartProjElev"></canvas></div></div>`;
  }

  // 风险列表
  html += `<div class="card" style="margin-top:16px"><h3>⚡ 风险项目高亮列表</h3><div class="table-wrap"><table class="tb">
    <thead><tr><th>类型</th><th>项目名称</th><th>签约方</th><th>签约金额</th><th>到期日期</th><th>剩余天数</th><th>员工状态</th></tr></thead><tbody>`;
  if (d.riskList.length === 0) {
    html += `<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px">✅ 该年度无即将到期 / 已过期合同</td></tr>`;
  } else {
    for (const r of d.riskList) {
      const diff = Math.ceil((new Date(r.end_date + "T00:00:00") - new Date()) / 86400000);
      const daysText = diff > 0 ? diff + "天" : (diff === 0 ? "今天" : Math.abs(diff) + "天前已过");
      const badge = r.status === "expired" ? '<span class="tag red">已过期</span>' : '<span class="tag orange">即将到期</span>';
      html += `<tr style="cursor:pointer" onclick="maintGoLedger('${r.type}',null,${r.id})">
        <td>${MAINT_TYPE_LABEL[r.type]}</td><td><b>${esc(r.project)}</b></td><td>${esc(r.party)}</td>
        <td class="num">${maintFmtMoney(r.amount)}</td><td>${r.end_date}</td>
        <td>${daysText}</td><td>${badge}</td></tr>`;
    }
  }
  html += `</tbody></table></div></div>`;

  area.innerHTML = html;
  maintDrawCharts(d);
}

function maintStatusBars(d, fireTotal, elevTotal, totalContracts) {
  const bar = (label, n, s, e, exp, color1, color2, color3) => {
    const pct = (v) => n ? (v / n * 100) : 0;
    return `<div style="margin-bottom:14px">
      <div style="font-size:13px;font-weight:600;margin-bottom:4px">${label}</div>
      <div style="display:flex;height:22px;border-radius:6px;overflow:hidden;background:#f1f5f9">
        <div style="width:${pct(n)}%;background:${color1}" title="正常${s}"></div>
        <div style="width:${pct(s)}%;background:${color2}" title="临期${e}"></div>
        <div style="width:${pct(e)}%;background:${color3}" title="过期${exp}"></div>
      </div>
      <div style="display:flex;gap:12px;font-size:12px;margin-top:3px;color:#64748b">
        <span>${s} 正常</span><span>${e} 临期</span><span>${exp} 过期</span>
      </div></div>`;
  };
  return bar("🧯 消防维保", fireTotal, d.fire.normal, d.fire.soon, d.fire.expired, "#22c55e", "#f59e0b", "#ef4444")
    + bar("🛗 电梯维保", elevTotal, d.elev.normal, d.elev.soon, d.elev.expired, "#22c55e", "#f59e0b", "#ef4444")
    + bar("📊 合计", totalContracts, d.fire.normal + d.elev.normal, d.fire.soon + d.elev.soon, d.fire.expired + d.elev.expired, "#22c55e", "#f59e0b", "#ef4444");
}

function maintDrawCharts(d) {
  Object.values(MAINT.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
  MAINT.charts = {};
  const blueSet = ["#2563eb", "#3b82f6", "#60a5fa", "#93c5fd", "#1d4ed8", "#1e40af", "#0ea5e9", "#06b6d4", "#14b8a6", "#0891b2"];
  const fireSet = ["#f59e0b", "#f97316", "#ef4444", "#eab308", "#fb923c", "#fbbf24", "#fca5a5", "#fdba74", "#fcd34d", "#f87171"];
  const gridColor = "rgba(148,163,184,.15)";
  const tickColor = "#64748b";

  // 环形图通用绘制
  const drawPie = (canvasId, list, total, centerLabel, colorSet) => {
    const cv = document.getElementById(canvasId);
    if (!cv || !list.length) return;
    MAINT.charts[canvasId] = new Chart(cv, {
      type: "doughnut",
      data: { labels: list.map(p => p.name), datasets: [{ data: list.map(p => p.totalAmount), backgroundColor: colorSet, borderWidth: 2, borderColor: "#fff", hoverOffset: 6 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: "58%",
        plugins: { legend: { position: "bottom", labels: { boxWidth: 10, font: { size: 10 }, color: "#475569", padding: 6 } },
          tooltip: { callbacks: { label: c => " " + c.label + "：" + maintFmtMoney(c.parsed) + " 元" + (total > 0 ? " (" + (c.parsed / total * 100).toFixed(1) + "%)" : "") } } } },
      plugins: [{ id: "centerText", afterDraw(chart) {
        const ctx = chart.ctx, cx = (chart.chartArea.left + chart.chartArea.right) / 2, cy = (chart.chartArea.top + chart.chartArea.bottom) / 2;
        ctx.save(); ctx.textAlign = "center"; ctx.textBaseline = "middle";
        ctx.fillStyle = "#94a3b8"; ctx.font = '600 10px "Microsoft YaHei"'; ctx.fillText(centerLabel, cx, cy - 10);
        ctx.fillStyle = "#1e293b"; ctx.font = '800 15px "Microsoft YaHei"'; ctx.fillText(maintFmtMoney(total), cx, cy + 10);
        ctx.restore();
      } }],
    });
  };
  drawPie("maintChartPieFire", d.firePartners || [], d.overview.fireAmount, "消防年度总额", fireSet);
  drawPie("maintChartPieElev", d.elevPartners || [], d.overview.elevAmount, "电梯年度总额", blueSet);


  // 合作方项目数排行
  const barP = document.getElementById("maintChartProjects");
  if (barP) {
    const sorted = [...d.partners].sort((a, b) => b.projectCount - a.projectCount);
    MAINT.charts.projects = new Chart(barP, {
      type: "bar", data: { labels: sorted.map(p => p.name), datasets: [{ label: "项目数", data: sorted.map(p => p.projectCount), backgroundColor: "#3b82f6", borderRadius: 4, barThickness: 20 }] },
      options: { indexAxis: "y", responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { stepSize: 1, color: tickColor }, grid: { color: gridColor } }, y: { ticks: { color: "#475569", font: { size: 11 } }, grid: { display: false } } } },
    });
  }

  // 电梯台数排行
  const barE = document.getElementById("maintChartElev");
  if (barE) {
    const sorted = d.partners.filter(p => p.elevatorCount > 0).sort((a, b) => b.elevatorCount - a.elevatorCount);
    if (sorted.length) MAINT.charts.elev = new Chart(barE, {
      type: "bar", data: { labels: sorted.map(p => p.name), datasets: [{ label: "电梯台数", data: sorted.map(p => p.elevatorCount), backgroundColor: "#1d4ed8", borderRadius: 4, barThickness: 20 }] },
      options: { indexAxis: "y", responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { stepSize: 1, color: tickColor }, grid: { color: gridColor } }, y: { ticks: { color: "#475569", font: { size: 11 } }, grid: { display: false } } } },
    });
  }

  // 各项目消防金额
  const projFire = document.getElementById("maintChartProjFire");
  const fireProjects = (d.projectAmounts || []).filter(p => p.fireCount > 0);
  if (projFire && fireProjects.length) MAINT.charts.projFire = new Chart(projFire, {
    type: "bar", data: { labels: fireProjects.map(p => p.name), datasets: [{ label: "消防维保金额", data: fireProjects.map(p => p.fireAmount), backgroundColor: "#f59e0b", borderRadius: 4, barThickness: 18 }] },
    options: { indexAxis: "y", responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => "🧯 " + maintFmtMoney(c.raw) } } },
      scales: { x: { beginAtZero: true, ticks: { color: tickColor, callback: v => v >= 10000 ? (v / 10000).toFixed(0) + "万" : v }, grid: { color: gridColor } }, y: { ticks: { color: "#475569", font: { size: 11 } }, grid: { display: false } } } },
  });

  // 各项目电梯金额
  const projElev = document.getElementById("maintChartProjElev");
  const elevProjects = (d.projectAmounts || []).filter(p => p.elevCount > 0);
  if (projElev && elevProjects.length) MAINT.charts.projElev = new Chart(projElev, {
    type: "bar", data: { labels: elevProjects.map(p => p.name), datasets: [{ label: "电梯维保金额", data: elevProjects.map(p => p.elevAmount), backgroundColor: "#3b82f6", borderRadius: 4, barThickness: 18 }] },
    options: { indexAxis: "y", responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => "🛗 " + maintFmtMoney(c.raw) } } },
      scales: { x: { beginAtZero: true, ticks: { color: tickColor, callback: v => v >= 10000 ? (v / 10000).toFixed(0) + "万" : v }, grid: { color: gridColor } }, y: { ticks: { color: "#475569", font: { size: 11 } }, grid: { display: false } } } },
  });
}

function maintGoLedger(type, projectFilter, id) {
  MAINT.focus = { type, projectFilter: projectFilter || null, id: id || null };
  nav(type === "fire" ? "maintFire" : "maintElev");
}

function maintExportRisk() {
  const d = MAINT.dashboard;
  if (!d || !d.riskList.length) { toast("无风险清单可导出", false); return; }
  const rows = d.riskList.map(r => ({ "类型": MAINT_TYPE_LABEL[r.type], "项目名称": r.project, "签约方": r.party, "签约金额": Number(r.amount) || 0, "到期日期": r.end_date, "状态": MAINT_STATUS_TEXT[r.status] }));
  maintDownloadCsv("风险清单_" + d.year + ".csv", rows);
}

function maintDownloadCsv(filename, rows) {
  if (!rows.length) return;
  const keys = Object.keys(rows[0]);
  const bom = "\uFEFF";
  const csv = bom + keys.join(",") + "\n" + rows.map(r => keys.map(k => `"${String(r[k] ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob); a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
}

/* ---------- 消防/电梯维保台账 ---------- */
async function pageMaintLedger(type) {
  const c = document.getElementById("content");
  const isElev = type === "elevator";
  c.innerHTML = `<div class="card"><h3>${MAINT_TYPE_LABEL[type]}维保台账</h3>
    <div class="row" style="flex-wrap:wrap;gap:8px">
      <button class="btn primary sm" onclick="maintContractEdit('${type}',null)">＋ 新增合同</button>
      <input id="mf_kw_${type}" placeholder="关键词(项目/签约方/备注)" style="width:180px" oninput="maintApplyFilter('${type}')">
      <input type="date" id="mf_efrom_${type}" onchange="maintApplyFilter('${type}')">
      <input type="date" id="mf_eto_${type}" onchange="maintApplyFilter('${type}')">
      <input id="mf_party_${type}" placeholder="签约方" style="width:120px" oninput="maintApplyFilter('${type}')">
      <input type="number" id="mf_amin_${type}" placeholder="金额下限" style="width:100px" oninput="maintApplyFilter('${type}')">
      <input type="number" id="mf_amax_${type}" placeholder="金额上限" style="width:100px" oninput="maintApplyFilter('${type}')">
      ${isElev ? `<input type="number" id="mf_emin_${type}" placeholder="台数下限" style="width:90px" oninput="maintApplyFilter('${type}')"><input type="number" id="mf_emax_${type}" placeholder="台数上限" style="width:90px" oninput="maintApplyFilter('${type}')">` : ""}
      <select id="mf_year_${type}" onchange="maintApplyFilter('${type}')"><option value="">全部年份</option>${[...Array(16)].map((_,i)=>2020+i).map(y=>`<option>${y}</option>`).join("")}</select>
      <select id="mf_sort_${type}" onchange="maintApplyFilter('${type}')">
        <option value="end_date">按到期时间</option><option value="party">按签约方</option><option value="amount">按签约金额</option>${isElev ? '<option value="elevator_count">按电梯台数</option>' : ""}
      </select>
      <button class="btn sm" id="mf_dir_${type}" onclick="maintToggleSortDir('${type}')">升序 ↑</button>
      <button class="btn sm" onclick="maintExportLedger('${type}')">导出CSV</button>
      <button class="btn sm" onclick="MAINT.focus={type:null,id:null,projectFilter:null};maintApplyFilter('${type}')">清除筛选</button>
    </div>
    <div id="maintFocus_${type}"></div>
    <div class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr><th>项目名称</th><th>签约方</th><th>签约金额</th><th>签订日期</th><th>生效开始</th><th>到期日期</th>
      ${isElev ? "<th>电梯台数</th><th>每台单价</th>" : "<th>建筑面积(㎡)</th><th>每㎡单价</th>"}
      <th>员工状态</th><th>备注</th><th>PDF扫描件</th><th>操作</th></tr></thead>
      <tbody id="maintTbody_${type}">加载中...</tbody></table></div></div>`;
  try {
    if (!MAINT.data[type].length) {
      const r = await api(`/api/maintenance/contracts?type=${type}`);
      MAINT.data[type] = r.contracts || [];
    }
    maintApplyFilter(type);
  } catch (e) {
    document.getElementById(`maintTbody_${type}`).innerHTML = `<tr><td colspan="12" class="msg err">${esc(e.message)}</td></tr>`;
  }
}

function maintToggleSortDir(type) {
  MAINT.sort[type].dir = MAINT.sort[type].dir === "asc" ? "desc" : "asc";
  document.getElementById(`mf_dir_${type}`).textContent = MAINT.sort[type].dir === "asc" ? "升序 ↑" : "降序 ↓";
  maintApplyFilter(type);
}

function maintGetFilters(type) {
  const g = id => { const el = document.getElementById(id); return el ? el.value.trim() : ""; };
  return {
    kw: g(`mf_kw_${type}`).toLowerCase(),
    efrom: g(`mf_efrom_${type}`), eto: g(`mf_eto_${type}`),
    party: g(`mf_party_${type}`).toLowerCase(),
    amin: g(`mf_amin_${type}`) === "" ? null : Number(g(`mf_amin_${type}`)),
    amax: g(`mf_amax_${type}`) === "" ? null : Number(g(`mf_amax_${type}`)),
    emin: g(`mf_emin_${type}`) === "" ? null : Number(g(`mf_emin_${type}`)),
    emax: g(`mf_emax_${type}`) === "" ? null : Number(g(`mf_emax_${type}`)),
    year: g(`mf_year_${type}`),
    sortField: document.getElementById(`mf_sort_${type}`).value,
  };
}

function maintApplyFilter(type) {
  const f = maintGetFilters(type);
  let rows = MAINT.data[type].slice();
  const focus = MAINT.focus;

  if (focus.type === type && focus.id != null) {
    rows = rows.filter(r => r.id === focus.id);
  } else if (focus.type === type && focus.projectFilter) {
    rows = rows.filter(r => r.project_name === focus.projectFilter);
  } else {
    if (f.kw) rows = rows.filter(r => (r.project_name + r.party + r.remark).toLowerCase().includes(f.kw));
    if (f.efrom) rows = rows.filter(r => r.end_date >= f.efrom);
    if (f.eto) rows = rows.filter(r => r.end_date <= f.eto);
    if (f.party) rows = rows.filter(r => (r.party || "").toLowerCase().includes(f.party));
    if (f.amin != null) rows = rows.filter(r => (Number(r.amount) || 0) >= f.amin);
    if (f.amax != null) rows = rows.filter(r => (Number(r.amount) || 0) <= f.amax);
    if (type === "elevator") {
      if (f.emin != null) rows = rows.filter(r => (Number(r.elevator_count) || 0) >= f.emin);
      if (f.emax != null) rows = rows.filter(r => (Number(r.elevator_count) || 0) <= f.emax);
    }
    if (f.year) rows = rows.filter(r => maintOverlapsYear(r.start_date, r.end_date, f.year));
  }

  const dir = MAINT.sort[type].dir === "asc" ? 1 : -1;
  const field = f.sortField;
  rows.sort((a, b) => {
    let va = a[field], vb = b[field];
    if (field === "amount" || field === "elevator_count") { va = Number(va) || 0; vb = Number(vb) || 0; }
    if (va < vb) return -1 * dir;
    if (va > vb) return 1 * dir;
    return 0;
  });

  maintRenderLedgerRows(type, rows);

  // focus 横幅
  const fb = document.getElementById(`maintFocus_${type}`);
  if (!fb) return;
  if (focus.type === type && focus.id != null) {
    const r = MAINT.data[type].find(x => x.id === focus.id);
    fb.innerHTML = `<div class="msg info" style="margin-top:8px">仅查看：${r ? esc(r.project_name) : ""}（${MAINT_TYPE_LABEL[type]}维保 · 合同 #${focus.id}）</div>`;
  } else if (focus.type === type && focus.projectFilter) {
    fb.innerHTML = `<div class="msg info" style="margin-top:8px">仅查看项目：「${esc(focus.projectFilter)}」的${MAINT_TYPE_LABEL[type]}维保合同</div>`;
  } else { fb.innerHTML = ""; }
}

function maintRenderLedgerRows(type, rows) {
  const tb = document.getElementById(`maintTbody_${type}`);
  if (!tb) return;
  const isElev = type === "elevator";
  if (!rows.length) {
    tb.innerHTML = `<tr><td colspan="${isElev ? 12 : 12}" style="text-align:center;color:#94a3b8;padding:24px">无匹配合同记录</td></tr>`;
    return;
  }
  tb.innerHTML = rows.map(r => {
    const st = maintStatusOf(r.end_date);
    const badge = st === "expired" ? '<span class="tag red">已过期</span>' : st === "soon" ? '<span class="tag orange">即将到期</span>' : '<span class="tag green">正常在保</span>';
    const pdf = r.pdf_name
      ? `<button class="btn sm" onclick="maintViewPdf(${r.id})">预览</button> <button class="btn sm" onclick="maintDownloadPdf(${r.id})">下载</button>`
      : '<span style="color:#94a3b8">无</span>';
    return `<tr>
      <td>${esc(r.project_name)}</td><td>${esc(r.party)}</td><td class="num">${maintFmtMoney(r.amount)}</td>
      <td>${r.sign_date || ""}</td><td>${r.start_date || ""}</td><td>${r.end_date || ""}</td>
      ${isElev ? `<td>${r.elevator_count || 0}</td><td>${r.price_per_unit ? maintFmtMoney(r.price_per_unit) : "-"}</td>` : `<td>${r.building_area_sqm || "-"}</td><td>${r.price_per_sqm ? maintFmtMoney(r.price_per_sqm) : "-"}</td>`}
      <td>${badge}</td><td>${esc(r.remark || "")}</td>
      <td>${pdf} <label class="btn sm primary" style="cursor:pointer" for="mpdf_${r.id}">上传</label><input id="mpdf_${r.id}" type="file" accept="application/pdf,.pdf" style="display:none" onchange="maintUploadPdf('${type}',${r.id},this)"></td>
      <td><button class="btn sm" onclick="maintContractEdit('${type}',${r.id})">编辑</button> <button class="btn sm danger" onclick="maintContractDelete('${type}',${r.id})">删除</button></td>
    </tr>`;
  }).join("");
}

async function maintLoadPartners() {
  try { const r = await api("/api/maintenance/partners"); MAINT.partners = r.partners || []; } catch (e) { MAINT.partners = []; }
}

async function maintContractEdit(type, id) {
  await maintLoadPartners();
  const row = id ? MAINT.data[type].find(r => r.id === id) : null;
  const isElev = type === "elevator";
  const v = k => row ? (row[k] ?? "") : "";
  const partnerOpts = '<option value="">-- 请选择签约方 --</option>' + MAINT.partners
    .filter(p => p.type === "both" || p.type === type)
    .map(p => `<option value="${esc(p.name)}" ${row && row.party === p.name ? "selected" : ""}>${esc(p.name)}${p.type !== "both" ? " (" + (p.type === "fire" ? "消防" : "电梯") + ")" : ""}</option>`).join("");

  const body = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div style="grid-column:1/3"><label>所属项目名称</label><input id="mc_project" value="${esc(v("project_name"))}" placeholder="如：盈科国际广场" style="width:100%"></div>
    <div style="grid-column:1/3"><label>签约方（${MAINT_TYPE_LABEL[type]}维保单位）</label><select id="mc_party" style="width:100%">${partnerOpts}</select>
      <div style="font-size:11px;color:#94a3b8;margin-top:2px">可在「签约方维护」中管理维保单位列表</div></div>
    <div><label>合同签约金额（元）</label><input id="mc_amount" type="number" value="${v("amount")}" placeholder="0" style="width:100%"></div>
    ${isElev ? `<div><label>电梯维保总台数</label><input id="mc_elev" type="number" value="${v("elevator_count")}" style="width:100%"></div>
      <div><label>每台单价（元）</label><input id="mc_price_unit" type="number" value="${v("price_per_unit")}" style="width:100%" title="自动计算：合同签约金额 ÷ 电梯总台数；也可手动修改覆盖"></div>`
      : `<div><label>项目建筑面积（㎡）</label><input id="mc_area" type="number" value="${v("building_area_sqm")}" style="width:100%"></div>
      <div><label>每平方米单价（元/㎡）</label><input id="mc_price_sqm" type="number" value="${v("price_per_sqm")}" style="width:100%" title="自动计算：合同签约金额 ÷ 项目建筑面积；也可手动修改覆盖"></div>`}
    <div><label>合同签订日期</label><input id="mc_sign" type="date" value="${v("sign_date")}" style="width:100%"></div>
    <div><label>合同生效开始日期</label><input id="mc_start" type="date" value="${v("start_date")}" style="width:100%"></div>
    <div><label>合同到期日期</label><input id="mc_end" type="date" value="${v("end_date")}" style="width:100%"></div>
    <div style="grid-column:1/3"><label>特殊要求 / 备注</label><textarea id="mc_remark" rows="2" style="width:100%">${esc(v("remark"))}</textarea></div>
  </div>`;

  maintModalOpen((row ? "编辑" : "新增") + MAINT_TYPE_LABEL[type] + "维保合同", body, async () => {
    const payload = {
      type, project_name: document.getElementById("mc_project").value.trim(),
      party: document.getElementById("mc_party").value, amount: Number(document.getElementById("mc_amount").value) || 0,
      sign_date: document.getElementById("mc_sign").value, start_date: document.getElementById("mc_start").value,
      end_date: document.getElementById("mc_end").value, remark: document.getElementById("mc_remark").value.trim(),
    };
    if (isElev) { payload.elevator_count = Number(document.getElementById("mc_elev").value) || 0; payload.price_per_unit = Number(document.getElementById("mc_price_unit").value) || 0; }
    else { payload.building_area_sqm = Number(document.getElementById("mc_area").value) || 0; payload.price_per_sqm = Number(document.getElementById("mc_price_sqm").value) || 0; }
    try {
      if (id) await api("/api/maintenance/contracts/" + id, { method: "PUT", body: payload });
      else await api("/api/maintenance/contracts", { body: payload });
      maintModalClose();
      MAINT.data[type] = [];  // 强制刷新
      toast("已保存");
      pageMaintLedger(type);
    } catch (e) { toast("保存失败：" + e.message, false); }
  });

  // 单价自动计算联动：签约金额 ÷ 面积(㎡)/台数；用户手动改过单价后不再自动覆盖，保存时以当前框值为准
  (function() {
    const priceEl = document.getElementById(isElev ? "mc_price_unit" : "mc_price_sqm");
    if (!priceEl) return;
    let manual = false, auto = false;
    priceEl.addEventListener("input", () => { if (!auto) manual = true; });
    const calc = () => {
      if (manual) return;
      const amt = Number(document.getElementById("mc_amount").value) || 0;
      if (isElev) {
        const cnt = Number(document.getElementById("mc_elev").value) || 0;
        priceEl.value = cnt > 0 ? (amt / cnt).toFixed(2) : "";
      } else {
        const area = Number(document.getElementById("mc_area").value) || 0;
        priceEl.value = area > 0 ? (amt / area).toFixed(2) : "";
      }
    };
    const bind = el => { if (el) el.addEventListener("input", () => { auto = true; calc(); auto = false; }); };
    bind(document.getElementById("mc_amount"));
    bind(document.getElementById(isElev ? "mc_elev" : "mc_area"));
    calc();
  })();
}

async function maintContractDelete(type, id) {
  const row = MAINT.data[type].find(r => r.id === id);
  if (!confirm(`确认删除合同「${row.project_name} / ${row.party}」？\n删除后对应 PDF 附件也会删除，不可恢复。`)) return;
  try {
    await api("/api/maintenance/contracts/" + id, { method: "DELETE" });
    MAINT.data[type] = [];
    toast("已删除");
    pageMaintLedger(type);
  } catch (e) { toast("删除失败：" + e.message, false); }
}

async function maintUploadPdf(type, id, input) {
  const file = input.files[0];
  if (!file) return;
  try {
    await fetch("/api/maintenance/contracts/" + id + "/pdf", { method: "POST", headers: { "Content-Type": "application/pdf", "X-Token": TOKEN }, body: file });
    MAINT.data[type] = [];
    toast("PDF 已上传");
    pageMaintLedger(type);
  } catch (e) { toast("PDF 上传失败：" + e.message, false); }
}

async function maintViewPdf(id) {
  try {
    const res = await fetch("/api/maintenance/contracts/" + id + "/pdf", { headers: { "X-Token": TOKEN } });
    if (!res.ok) throw new Error("加载失败");
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    window.open(url, "_blank");
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) { toast("PDF 预览失败：" + e.message, false); }
}

async function maintDownloadPdf(id) {
  try {
    const res = await fetch("/api/maintenance/contracts/" + id + "/pdf?download=1", { headers: { "X-Token": TOKEN } });
    if (!res.ok) throw new Error("下载失败");
    const blob = await res.blob();
    const cd = res.headers.get("Content-Disposition") || "";
    const m = cd.match(/filename="?([^"]+)"?/);
    const fname = m ? decodeURIComponent(m[1]) : "contract.pdf";
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob); a.download = fname;
    document.body.appendChild(a); a.click(); a.remove();
  } catch (e) { toast("PDF 下载失败：" + e.message, false); }
}

function maintExportLedger(type) {
  const f = maintGetFilters(type);
  let rows = MAINT.data[type].slice();
  if (f.kw) rows = rows.filter(r => (r.project_name + r.party + r.remark).toLowerCase().includes(f.kw));
  if (f.year) rows = rows.filter(r => maintOverlapsYear(r.start_date, r.end_date, f.year));
  if (!rows.length) { toast("当前无数据可导出", false); return; }
  const isElev = type === "elevator";
  const out = rows.map(r => {
    const o = { "项目名称": r.project_name, "签约方": r.party, "签约金额": Number(r.amount) || 0, "签订日期": r.sign_date, "生效开始": r.start_date, "到期日期": r.end_date };
    if (isElev) { o["电梯台数"] = Number(r.elevator_count) || 0; o["每台单价"] = Number(r.price_per_unit) || 0; }
    else { o["建筑面积(㎡)"] = Number(r.building_area_sqm) || 0; o["每㎡单价"] = Number(r.price_per_sqm) || 0; }
    o["状态"] = MAINT_STATUS_TEXT[maintStatusOf(r.end_date)]; o["备注"] = r.remark || "";
    return o;
  });
  maintDownloadCsv(MAINT_TYPE_LABEL[type] + "维保台账.csv", out);
}

/* ---------- 签约方维护 ---------- */
async function pageMaintPartners() {
  const c = document.getElementById("content");
  c.innerHTML = `<div class="card"><h3>签约方维护</h3>
    <div class="row"><button class="btn primary sm" onclick="maintPartnerEdit(null)">＋ 新增签约方</button></div>
    <div class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr><th>名称</th><th>业务类型</th><th>联系人</th><th>电话</th><th>备注</th><th>操作</th></tr></thead>
      <tbody id="maintPartnersBody">加载中...</tbody></table></div></div>`;
  try {
    const r = await api("/api/maintenance/partners");
    MAINT.partners = r.partners || [];
    maintRenderPartners();
  } catch (e) {
    document.getElementById("maintPartnersBody").innerHTML = `<tr><td colspan="6" class="msg err">${esc(e.message)}</td></tr>`;
  }
}

function maintRenderPartners() {
  const tb = document.getElementById("maintPartnersBody");
  const typeMap = { fire: "仅消防", elevator: "仅电梯", both: "消防+电梯" };
  if (!MAINT.partners.length) {
    tb.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px">暂无签约方，点击上方按钮新增</td></tr>`;
    return;
  }
  tb.innerHTML = MAINT.partners.map(p => `<tr>
    <td><b>${esc(p.name)}</b></td><td><span class="tag blue">${typeMap[p.type] || p.type}</span></td>
    <td>${esc(p.contact || "")}</td><td>${esc(p.phone || "")}</td><td>${esc(p.remark || "-")}</td>
    <td><button class="btn sm" onclick="maintPartnerEdit(${p.id})">编辑</button> <button class="btn sm danger" onclick="maintPartnerDelete(${p.id})">删除</button></td>
  </tr>`).join("");
}

function maintPartnerEdit(id) {
  const row = id ? MAINT.partners.find(p => p.id === id) : null;
  const v = k => row ? (row[k] ?? "") : "";
  const body = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div style="grid-column:1/3"><label>签约方名称 <span style="color:#ef4444">*</span></label><input id="mp_name" value="${esc(v("name"))}" placeholder="如：华安消防工程有限公司" style="width:100%"></div>
    <div><label>业务类型</label><select id="mp_type" style="width:100%">
      <option value="both" ${!row || row.type === "both" ? "selected" : ""}>消防+电梯</option>
      <option value="fire" ${row && row.type === "fire" ? "selected" : ""}>仅消防</option>
      <option value="elevator" ${row && row.type === "elevator" ? "selected" : ""}>仅电梯</option>
    </select></div>
    <div><label>联系人</label><input id="mp_contact" value="${esc(v("contact"))}" style="width:100%"></div>
    <div><label>联系电话</label><input id="mp_phone" value="${esc(v("phone"))}" style="width:100%"></div>
    <div style="grid-column:1/3"><label>备注</label><textarea id="mp_remark" rows="2" style="width:100%">${esc(v("remark"))}</textarea></div>
  </div>`;
  maintModalOpen((row ? "编辑" : "新增") + "签约方", body, async () => {
    const payload = { name: document.getElementById("mp_name").value.trim(), type: document.getElementById("mp_type").value,
      contact: document.getElementById("mp_contact").value.trim(), phone: document.getElementById("mp_phone").value.trim(),
      remark: document.getElementById("mp_remark").value.trim() };
    if (!payload.name) { toast("请填写签约方名称", false); return; }
    try {
      if (id) await api("/api/maintenance/partners/" + id, { method: "PUT", body: payload });
      else await api("/api/maintenance/partners", { body: payload });
      maintModalClose();
      toast(id ? "已更新" : "已新增");
      pageMaintPartners();
    } catch (e) { toast(e.message || "操作失败", false); }
  });
}

async function maintPartnerDelete(id) {
  if (!confirm("确定删除该签约方？已关联的合同不受影响，但表单中不再显示此选项。")) return;
  try {
    await api("/api/maintenance/partners/" + id, { method: "DELETE" });
    toast("已删除");
    pageMaintPartners();
  } catch (e) { toast(e.message, false); }
}

/* ---------------- 数据导出 ---------------- */
function pageExport() {
  const c = document.getElementById("content");
  const isAdmin = state.user && state.user.role === "admin";
  c.innerHTML = `
  <div class="card"><h3>导出模式</h3>
    <div class="row">${monthInput()}</div>
    <div class="row" style="margin-top:12px">
      <button class="btn primary" onclick="download('/api/export/summary?ym=${state.month}','工资汇总_${state.month}.xlsx')">① 汇总导出（整体汇总Sheet+各项目明细Sheet）</button>
      <button class="btn primary" onclick="download('/api/export/projects_all?ym=${state.month}','全部项目工资表_${state.month}.zip')">② 分项目导出（全选，一键多文件打包）</button>
    </div>
    <div class="row" style="margin-top:12px">
      <label class="fld">分项目导出-选择项目 <select id="expProj">${state.projects.filter(p => p !== "物业总部").map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
      <button class="btn" onclick="exportGo('project')">导出该项目工资表</button>
      ${isAdmin ? `<button class="btn" onclick="exportGo('projectMgrs')">导出该项目管理人员表</button>` : ""}
    </div>
    <div class="card" style="margin-top:12px;padding:10px 14px">
      <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <b>案场人员工资表</b>
        <label class="fld" style="margin:0">选择项目 <select id="expCaseProj"><option value="">全部项目</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
        <button class="btn" onclick="exportGo('projectCase')">导出该项目案场人员表</button>
        ${isAdmin ? `<button class="btn" onclick="exportGo('caseAll')">导出全部案场人员表</button>` : ""}
        <span class="hint" style="margin:0;display:inline">案场人员由总部单独核算（人员档案中勾选"是否案场人员"），各项目可查看/导出本项目案场人员工资。</span>
      </div>
    </div>
    <div class="card" style="margin-top:12px;padding:10px 14px">
      <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <b>总部人员工资表</b>
        ${isAdmin ? `<button class="btn" onclick="exportGo('hqAll')">导出总部人员工资表（物业总部）</button>` : ""}
        <span class="hint" style="margin:0;display:inline">物业总部所有人员由总部单独核算（总部人员核算），仅总部可导出。</span>
      </div>
    </div>
    <div class="row" style="margin-top:12px">
      <label class="fld">绩效专项-项目 <select id="expPerfProj"><option value="">全部项目</option>${state.projects.map(p => `<option>${esc(p)}</option>`).join("")}</select></label>
      <button class="btn" onclick="exportGo('perf')">③ 绩效专项导出</button>
    </div>
    <div class="hint">所有导出均为Excel文件，文件名自动携带核算月份与项目名称；表结构与核算结果明细完全一致（34列）。</div>
  </div>`;
}
function exportGo(mode) {
  if (mode === "project") {
    let p = null;
    const f1 = document.getElementById("expProj");
    const f2 = document.getElementById("payProjFilter");
    if (f1) p = f1.value;
    else if (f2 && f2.value) p = f2.value;
    if (!p) p = state.projects[0];
    download(`/api/export/project?ym=${state.month}&project=${encodeURIComponent(p)}`, `${p}_${state.month}工资表.xlsx`);
  } else if (mode === "perf") {
    const p = document.getElementById("expPerfProj") ? document.getElementById("expPerfProj").value : "";
    download(`/api/export/performance?ym=${state.month}&project=${encodeURIComponent(p)}`, `绩效明细_${state.month}${p ? "_" + p : ""}.xlsx`);
  } else if (mode === "projectMgrs") {
    const p = document.getElementById("expProj") ? document.getElementById("expProj").value : state.projects[0];
    download(`/api/export/project-managers?ym=${state.month}&project=${encodeURIComponent(p)}`, `${p}_管理人员工资表_${state.month}.xlsx`);
  } else if (mode === "hqAll") {
    download(`/api/export/hq-staff?ym=${state.month}`, `总部人员工资表_${state.month}.xlsx`);
  } else if (mode === "managers") {
    download(`/api/export/managers?ym=${state.month}`, `管理人员工资表_${state.month}.xlsx`);
  } else if (mode === "projectCase") {
    const p = document.getElementById("expCaseProj") ? document.getElementById("expCaseProj").value : "";
    if (!p) return toast("请先选择项目", false);
    download(`/api/export/project-case?ym=${state.month}&project=${encodeURIComponent(p)}`, `${p}_案场人员工资表_${state.month}.xlsx`);
  } else if (mode === "caseAll") {
    download(`/api/export/case-staff?ym=${state.month}`, `案场人员工资表_${state.month}.xlsx`);
  }
}

/* ---------------- 计算规则设置 ---------------- */
/* ---------------- 薪酬设置（统一入口） ---------------- */
/* ---------------- 薪酬设置（重构版） ---------------- */
async function pageSalarySettings(container) {
  const c = container || document.getElementById("content");
  c.innerHTML = `<div class="card"><h3>💰 薪酬设置</h3>
    <div id="salarySettingsArea"><div class="hint">加载中...</div></div></div>`;
  try {
    const [rulesData, cfData] = await Promise.all([api("/api/calc_rules"), api("/api/custom_fields")]);
    window._rules = rulesData.rules;
    window._customFields = cfData.fields || [];
    renderSalarySettingsUI();
  } catch (e) { document.getElementById("salarySettingsArea").innerHTML = '<div class="msg err">' + esc(e.message) + '</div>'; }
}

function renderSalarySettingsUI() {
  const R = window._rules;
  const fields = window._customFields || [];
  const area = document.getElementById("salarySettingsArea");
  const fm = R.formula || {};
  const grossF = fm.gross || "";
  const netF = fm.net || "";

  let html = "";

  // ========== 1. 自定义薪酬项（顶部醒目区域） ==========
  html += '<div style="border:2px solid #10b981;border-radius:8px;padding:16px;margin-bottom:20px;background:#f0fdf4">';
  html += '<div style="font-size:16px;font-weight:bold;margin-bottom:4px;color:#059669">📋 自定义薪酬项</div>';
  html += '<div class="hint" style="margin-bottom:12px;color:#047857">在此定义的字段会出现在考勤模板和工资表中，字段名可直接在下方核算公式中引用。</div>';
  html += '<div id="cfTableWrap"></div>';
  html += '<div style="margin-top:12px;display:flex;gap:8px">';
  html += '<button class="btn primary" onclick="cfAdd()">＋ 添加薪酬项</button>';
  html += '<button class="btn success" onclick="cfSave()">💾 保存薪酬项</button>';
  html += '</div></div>';

  // ========== 2. 基础计算参数 ==========
  html += '<details style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:6px;padding:14px" open>';
  html += '<summary style="font-weight:bold;font-size:15px;cursor:pointer;margin-bottom:12px;color:#1e40af">📐 基础计算参数</summary>';
  html += '<div id="basicParamsWrap"></div>';
  html += '</details>';

  // ========== 3. 核算公式 ==========
  const VAR_CN = {base_pay:"应发基本工资",perf_pay:"应发绩效工资",sick_pay:"病假工资",night:"夜班话费补贴",meal:"餐补",title_sub:"其他补贴",reward:"月度奖励",welfare:"已发福利",punish:"月度扣罚",miss_d:"缺卡扣款",late_d:"迟到早退扣款",other_d:"其他扣款",uniform_d:"工装扣款",gross:"应发合计",soc_total:"五险一金合计",actual_tax:"本月个税",pen:"养老保险",med:"医疗保险",une:"失业保险",house:"住房公积金",big:"大病",spec_total:"附加扣除合计"};
  const cnF = f => String(f||"").replace(/[a-zA-Z_]\w*/g, m => VAR_CN[m] || m);
  const grossDefault = "应发基本工资 + 应发绩效工资 + 病假工资 + 夜班话费补贴 + 餐补 + 其他补贴 + 月度奖励 + 已发福利 - 月度扣罚 - 缺卡扣款 - 迟到早退扣款 - 其他扣款 - 工装扣款";
  const netDefault = "应发合计 - 五险一金合计 - 本月个税 - 已发福利";
  const grossExpr = cnF(fm.gross) || grossDefault;
  const netExpr = cnF(fm.net) || netDefault;

  const builtinVars = ["应发基本工资","应发绩效工资","病假工资","夜班话费补贴","餐补","其他补贴","月度奖励","已发福利","月度扣罚","缺卡扣款","迟到早退扣款","其他扣款","工装扣款","应发合计","五险一金合计","本月个税","养老保险","医疗保险","失业保险","住房公积金","大病","附加扣除合计"];
  const customVars = fields.filter(f => f.enabled).map(f => f.name);

  html += '<div style="border:2px solid #6366f1;border-radius:8px;padding:16px;margin-bottom:16px;background:#f8f9ff">';
  html += '<div style="font-size:16px;font-weight:bold;margin-bottom:4px;color:#4f46e5">🧮 核算公式</div>';
  html += '<div class="hint" style="margin-bottom:12px;color:#4338ca">计算流程：基本工资 → 绩效 → 病假 → 补贴 → 奖惩 → <b>应发合计</b> → 五险一金 → 个税 → <b>实发工资</b></div>';
  html += '<div style="margin-bottom:12px"><label style="font-weight:600;display:block;margin-bottom:4px">公式一：应发合计</label>';
  html += '<input type="text" id="rl_formula_gross" value="' + esc(grossExpr) + '" style="width:100%;font-size:13px;padding:8px;border:1px solid #c7d2fe;border-radius:4px"></div>';
  html += '<div style="margin-bottom:12px"><label style="font-weight:600;display:block;margin-bottom:4px">公式二：实发工资</label>';
  html += '<input type="text" id="rl_formula_net" value="' + esc(netExpr) + '" style="width:100%;font-size:13px;padding:8px;border:1px solid #c7d2fe;border-radius:4px"></div>';
  html += '<div style="margin-bottom:12px"><button class="btn sm" onclick="formulaTest()">✓ 验证公式</button> ';
  html += '<button class="btn sm" onclick="formulaReset()">↺ 恢复默认</button> ';
  html += '<span id="formulaTestResult" style="margin-left:10px;font-size:13px"></span></div>';
  html += '<div style="padding:10px;background:#f0f0ff;border-radius:6px">';
  html += '<div style="font-weight:600;margin-bottom:6px;font-size:13px">可用变量（点击可插入到光标位置）：</div>';
  html += '<div style="margin-bottom:6px"><span style="font-size:11px;color:#6b7280">内置：</span>';
  html += builtinVars.map(v => '<span onclick="insertVarAtCursor(\'rl_formula_gross\',\'' + esc(v) + '\')" style="display:inline-block;background:#e0e7ff;padding:2px 7px;margin:2px;border-radius:3px;font-size:12px;cursor:pointer">' + esc(v) + '</span>').join("");
  html += '</div>';
  if (customVars.length) {
    html += '<div><span style="font-size:11px;color:#059669">自定义：</span>';
    html += customVars.map(v => '<span onclick="insertVarAtCursor(\'rl_formula_gross\',\'' + esc(v) + '\')" style="display:inline-block;background:#d1fae5;padding:2px 7px;margin:2px;border-radius:3px;font-size:12px;cursor:pointer;border:1px solid #6ee7b7">' + esc(v) + '</span>').join("");
    html += '</div>';
  }
  html += '</div></div>';

  // ========== 4. 符号库 ==========
  html += '<details style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:6px;padding:14px">';
  html += '<summary style="font-weight:bold;font-size:15px;cursor:pointer;margin-bottom:10px;color:#7c3aed">🔣 符号库（考勤符号定义）</summary>';
  html += '<div id="symSubArea">加载中...</div>';
  html += '</details>';

  // ========== 5. 保存按钮 ==========
  html += '<div class="row" style="margin-top:20px"><button class="btn primary lg" onclick="saveAllSalarySettings()">💾 保存所有设置</button></div>';

  area.innerHTML = html;

  // Render sub-sections
  renderCfTable();
  renderBasicParams();
  loadSymbols();
}

/* ---- 自定义薪酬项表格 ---- */
function renderCfTable() {
  const fields = window._customFields || [];
  const wrap = document.getElementById("cfTableWrap");
  const fm = (window._rules.formula || {});
  const gF = fm.gross || "", nF = fm.net || "";
  if (!fields.length) {
    wrap.innerHTML = '<div class="hint" style="padding:16px;text-align:center;background:#f9fafb;border-radius:6px">暂无自定义薪酬项，点击"添加薪酬项"创建。</div>';
    return;
  }
  let html = '<table class="tb"><thead><tr>';
  html += '<th>字段名称</th><th>类型</th><th>金额来源</th><th>默认值</th><th>参与公式</th><th>启用</th><th>操作</th>';
  html += '</tr></thead><tbody>';
  fields.forEach((f, i) => {
    const inG = gF.includes(f.name), inN = nF.includes(f.name);
    html += '<tr>';
    html += '<td><input type="text" style="width:120px" value="' + esc(f.name) + '" onchange="window._customFields[' + i + '].name=this.value"></td>';
    html += '<td><select style="width:80px" onchange="window._customFields[' + i + '].type=this.value">';
    html += '<option value="subsidy"' + (f.type==='subsidy'?' selected':'') + '>补贴</option>';
    html += '<option value="deduction"' + (f.type==='deduction'?' selected':'') + '>扣款</option></select></td>';
    html += '<td><select style="width:100px" onchange="window._customFields[' + i + '].source=this.value">';
    html += '<option value="attendance"' + (f.source==='attendance'?' selected':'') + '>考勤表导入</option>';
    html += '<option value="fixed"' + (!f.source||f.source==='fixed'?' selected':'') + '>固定金额</option></select></td>';
    html += '<td><input type="number" step="0.01" style="width:70px" value="' + (f.default||0) + '" onchange="window._customFields[' + i + '].default=parseFloat(this.value)"></td>';
    html += '<td style="font-size:12px">';
    html += '<span style="display:inline-block;padding:1px 6px;border-radius:3px;margin:1px;' + (inG?'background:#dbeafe;color:#1e40af':'background:#f3f4f6;color:#9ca3af') + '">应发' + (inG?' ✓':'') + '</span> ';
    html += '<span style="display:inline-block;padding:1px 6px;border-radius:3px;margin:1px;' + (inN?'background:#dbeafe;color:#1e40af':'background:#f3f4f6;color:#9ca3af') + '">实发' + (inN?' ✓':'') + '</span>';
    html += '</td>';
    html += '<td style="text-align:center"><input type="checkbox"' + (f.enabled?' checked':'') + ' onchange="window._customFields[' + i + '].enabled=this.checked"></td>';
    html += '<td><button class="btn sm danger" onclick="cfDel(' + i + ')">删除</button></td>';
    html += '</tr>';
  });
  html += '</tbody></table>';
  wrap.innerHTML = html;
}
function cfAdd() {
  if (!window._customFields) window._customFields = [];
  window._customFields.push({name:"新薪酬项", type:"subsidy", source:"fixed", enabled:true, default:0});
  renderCfTable();
}
function cfDel(i) {
  const f = window._customFields[i];
  const fm = window._rules.formula || {};
  const inF = (fm.gross||"").includes(f.name) || (fm.net||"").includes(f.name);
  let msg = "确认删除「" + f.name + "」？";
  if (inF) msg += "\n\n⚠ 该字段正在核算公式中使用，删除后请手动修改公式。";
  if (!confirm(msg)) return;
  window._customFields.splice(i, 1);
  renderCfTable();
}
async function cfSave() {
  try {
    const fields = (window._customFields||[]).filter(f => f.name && f.name.trim()).map(f => ({
      name: f.name.trim(), type: f.type||'subsidy', source: f.source||'fixed',
      enabled: !!f.enabled, default: parseFloat(f.default)||0
    }));
    const r = await api("/api/custom_fields/save", {body:{fields}});
    window._customFields = r.fields || fields;
    toast("✓ 薪酬项已保存");
    renderCfTable();
  } catch(e) { alert("保存失败：" + e.message); }
}

/* ---- 基础计算参数 ---- */
function renderBasicParams() {
  const R = window._rules;
  const wrap = document.getElementById("basicParamsWrap");
  const sec = (t, inner) => '<div style="margin-bottom:10px;padding:10px;border:1px solid #e5e7eb;border-radius:6px"><div style="font-weight:600;margin-bottom:6px">' + t + '</div>' + inner + '</div>';
  let h = "";
  h += sec("① 应发基本工资",
    '<div class="row"><label class="fld"><input type="checkbox" id="rl_seg" ' + (R.base_salary.segment_by_date?"checked":"") + '> 月中调薪按生效日期分段折算</label>' +
    '<label class="fld" style="margin-left:16px">折算基数 <select id="rl_prorate" style="width:120px">' +
    '<option value="required" ' + (R.base_salary.prorate_base!=="calendar"?"selected":"") + '>按应出勤天数</option>' +
    '<option value="calendar" ' + (R.base_salary.prorate_base==="calendar"?"selected":"") + '>按自然天数</option></select></label></div>');
  h += sec("② 绩效工资",
    '<div class="row"><label class="fld"><input type="checkbox" id="rl_perf_on" ' + (R.performance.enabled?"checked":"") + '> 启用绩效工资</label>' +
    '<label class="fld" style="margin-left:16px"><input type="checkbox" id="rl_perf_prob" ' + (R.performance.probation_excluded?"checked":"") + '> 试用期不参与</label></div>');
  h += sec("③ 病假工资",
    '<div class="row"><label class="fld"><input type="checkbox" id="rl_sick_on" ' + (R.sick_pay.enabled?"checked":"") + '> 启用</label>' +
    '<label class="fld" style="margin-left:12px">系数 <input type="number" step="0.01" id="rl_sick_a" value="' + R.sick_pay.params.factor_a + '" style="width:55px"> × <input type="number" step="0.01" id="rl_sick_b" value="' + R.sick_pay.params.factor_b + '" style="width:55px"></label>' +
    '<label class="fld" style="margin-left:12px">基数 <select id="rl_sick_base" style="width:90px">' +
    '<option value="base" ' + (R.sick_pay.params.sick_base!=="fixed"?"selected":"") + '>基本工资</option>' +
    '<option value="fixed" ' + (R.sick_pay.params.sick_base==="fixed"?"selected":"") + '>固定月薪</option></select></label></div>' +
    '<div class="hint" style="margin:4px 0 0">最终计发比例 = 系数A × 系数B = ' + (Number(R.sick_pay.params.factor_a)*Number(R.sick_pay.params.factor_b)*100).toFixed(0) + '%</div>');
  h += sec("④ 补贴发放",
    '<div class="row"><label class="fld">餐补 <select id="rl_meal_mode" style="width:110px"><option value="full" ' + (R.meal_subsidy.mode==="full"?"selected":"") + '>全额</option><option value="prorate" ' + (R.meal_subsidy.mode==="prorate"?"selected":"") + '>按出勤折算</option></select></label>' +
    '<label class="fld" style="margin-left:16px">其他补贴 <select id="rl_allow_mode" style="width:110px"><option value="full" ' + (R.allowances.mode==="full"?"selected":"") + '>全额</option><option value="prorate" ' + (R.allowances.mode==="prorate"?"selected":"") + '>按出勤折算</option></select></label></div>');
  h += sec("⑤ 奖惩",
    '<label class="fld"><input type="checkbox" id="rl_rp" ' + (R.reward_punish.full_in_gross?"checked":"") + '> 月度奖励/扣罚全额计入税前应发</label>');
  const dr = R.deduction_rules || {miss_punch:{enabled:true,first_3:30,after_3:50},absent:{enabled:true,multiplier:3}};
  const le = dr.late_early || {enabled:true,per_time:10};
  h += sec("⑥ 考勤扣款",
    '<div class="row"><label class="fld"><input type="checkbox" id="rl_miss_on" ' + (dr.miss_punch.enabled?"checked":"") + '> 缺卡扣款</label>' +
    '<label class="fld" style="margin-left:8px">前3次 <input type="number" id="rl_miss_f3" value="' + dr.miss_punch.first_3 + '" style="width:50px"> 元/次</label>' +
    '<label class="fld" style="margin-left:8px">第4次起 <input type="number" id="rl_miss_a3" value="' + dr.miss_punch.after_3 + '" style="width:50px"> 元/次</label></div>' +
    '<div class="row" style="margin-top:6px"><label class="fld"><input type="checkbox" id="rl_abs_on" ' + (dr.absent.enabled?"checked":"") + '> 旷工扣款</label>' +
    '<label class="fld" style="margin-left:8px">扣 <input type="number" step="0.5" id="rl_abs_mult" value="' + dr.absent.multiplier + '" style="width:50px"> 倍日薪</label></div>' +
    '<div class="row" style="margin-top:6px"><label class="fld"><input type="checkbox" id="rl_late_on" ' + (le.enabled?"checked":"") + '> 迟到/早退扣款</label>' +
    '<label class="fld" style="margin-left:8px">每次 <input type="number" step="0.5" id="rl_late_per" value="' + le.per_time + '" style="width:50px"> 元</label></div>');
  h += sec("⑦ 个人所得税",
    '<div class="row"><label class="fld">基本减除 <input type="number" id="rl_tax_basic" value="' + R.tax.basic_deduction + '" style="width:70px"> 元/月</label>' +
    '<label class="fld" style="margin-left:16px">累计起算 <select id="rl_cum_start" style="width:100px">' +
    '<option value="jan" ' + (R.tax.cum_start!=="month"?"selected":"") + '>当年1月</option>' +
    '<option value="month" ' + (R.tax.cum_start==="month"?"selected":"") + '>核算当月</option></select></label></div>' +
    '<div class="hint" style="margin:8px 0 4px">个人所得税税率级距表（累计预扣法）— 累计应纳税所得额落入哪一档即按该档税率计税并减去速算扣除数；最后一级为最高档，不设上限。修改后点下方「保存全部设置」即与薪资核算联动。</div>' +
    '<div id="taxBracketBox"></div>' +
    '<div class="row" style="margin-top:6px"><button type="button" class="btn sm" onclick="taxAddBracket()">+ 增加一级</button>' +
    '<button type="button" class="btn sm" style="margin-left:8px" onclick="taxRestoreStd()">恢复标准 7 级</button></div>');
  h += sec("⑧ 五险一金 / 专项附加",
    '<div class="hint" style="margin:0">五险一金：' + esc(R.social.formula_text) + '<br>专项附加扣除：' + esc(R.special_deduction.formula_text) + '（' + R.special_deduction.items.join("、") + '）</div>');
  wrap.innerHTML = h;
  taxResetDraft(); taxRenderBrackets();
}

/* ---- 公式变量插入 ---- */
function insertVarAtCursor(inputId, varName) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const start = input.selectionStart || 0;
  const end = input.selectionEnd || 0;
  const val = input.value;
  input.value = val.substring(0, start) + varName + val.substring(end);
  const newPos = start + varName.length;
  input.setSelectionRange(newPos, newPos);
  input.focus();
}

/* ---- 统一保存 ---- */
/* ---------------- 个税税率级距表（联动 calc_rules.tax.brackets） ---------------- */
const STD_TAX_BRACKETS = [
  [36000, 0.03, 0], [144000, 0.10, 2520], [300000, 0.20, 16920],
  [420000, 0.25, 31920], [660000, 0.30, 52920], [960000, 0.35, 85920],
  [99999999999, 0.45, 181920]
];
function taxDraft() {
  if (!window._taxDraft) {
    const src = (window._rules && window._rules.tax && Array.isArray(window._rules.tax.brackets) && window._rules.tax.brackets.length)
      ? window._rules.tax.brackets : STD_TAX_BRACKETS;
    window._taxDraft = src.map(b => [Number(b[0]), Number(b[1]), Number(b[2])]);
  }
  return window._taxDraft;
}
function taxResetDraft() { window._taxDraft = null; return taxDraft(); }
function taxSync() {
  const arr = taxDraft();
  document.querySelectorAll('#taxBracketBox [data-brl]').forEach(el => { const i = +el.dataset.brl; if (arr[i]) arr[i][0] = (el.value === "" ) ? 99999999999 : parseFloat(el.value); });
  document.querySelectorAll('#taxBracketBox [data-brr]').forEach(el => { const i = +el.dataset.brr; if (arr[i]) arr[i][1] = (parseFloat(el.value) || 0) / 100; });
  document.querySelectorAll('#taxBracketBox [data-brq]').forEach(el => { const i = +el.dataset.brq; if (arr[i]) arr[i][2] = parseFloat(el.value) || 0; });
  return arr;
}
function taxRenderBrackets() {
  const box = document.getElementById('taxBracketBox'); if (!box) return;
  const arr = taxDraft();
  const rows = arr.map((b, i) => {
    const isLast = i === arr.length - 1;
    const ratePct = Math.round(Number(b[1]) * 10000) / 100;
    return '<tr>' +
      '<td style="text-align:center">' + (i + 1) + '</td>' +
      '<td>' + (isLast ? '<span style="color:#94a3b8">最高档 · 不设上限</span>' : '<input type="number" step="1000" min="1" data-brl="' + i + '" value="' + b[0] + '" style="width:130px"> 元') + '</td>' +
      '<td><input type="number" step="0.1" min="0" max="100" data-brr="' + i + '" value="' + ratePct + '" style="width:70px"> %</td>' +
      '<td><input type="number" step="10" data-brq="' + i + '" value="' + b[2] + '" style="width:110px"></td>' +
      '<td style="text-align:center">' + (isLast ? '—' : '<button type="button" class="btn sm warn" onclick="taxDelBracket(' + i + ')">删除</button>') + '</td>' +
      '</tr>';
  }).join('');
  box.innerHTML = '<div class="table-wrap" style="overflow-x:auto"><table class="tb" style="min-width:560px"><thead><tr>' +
    '<th>级</th><th>累计应纳税所得额上限(元)</th><th>税率</th><th>速算扣除数</th><th>操作</th></tr></thead>' +
    '<tbody>' + rows + '</tbody></table></div>';
}
function taxAddBracket() {
  taxSync();
  const arr = taxDraft();
  const n = arr.length;
  const prevCap = n >= 2 ? arr[n - 2][0] : 36000;
  const newCap = Math.max(Math.round(prevCap * 2), prevCap + 36000);
  const prevRate = n >= 1 ? arr[n - 1][1] : 0.03;
  arr.splice(Math.max(n - 1, 0), 0, [newCap, Math.min(Number(prevRate) + 0.05, 0.45), 0]);
  taxRenderBrackets();
}
function taxDelBracket(i) {
  taxSync();
  const arr = taxDraft();
  if (arr.length <= 1) { alert('至少保留一级'); return; }
  arr.splice(i, 1);
  taxRenderBrackets();
}
function taxRestoreStd() { window._taxDraft = STD_TAX_BRACKETS.map(b => [Number(b[0]), Number(b[1]), Number(b[2])]); taxRenderBrackets(); }
async function saveAllSalarySettings() {
  try {
    // 1. Save custom fields
    const cfFields = (window._customFields||[]).filter(f => f.name && f.name.trim()).map(f => ({
      name: f.name.trim(), type: f.type||'subsidy', source: f.source||'fixed',
      enabled: !!f.enabled, default: parseFloat(f.default)||0
    }));
    await api("/api/custom_fields/save", {body:{fields: cfFields}});

    // 2. Save calc rules
    const R = JSON.parse(JSON.stringify(window._rules));
    const gv = id => (document.getElementById(id)||{}).value || "";
    const gc = id => (document.getElementById(id)||{}).checked || false;
    R.base_salary.segment_by_date = gc("rl_seg");
    R.base_salary.prorate_base = gv("rl_prorate");
    R.performance.enabled = gc("rl_perf_on");
    R.performance.probation_excluded = gc("rl_perf_prob");
    R.sick_pay.enabled = gc("rl_sick_on");
    R.sick_pay.params.factor_a = parseFloat(gv("rl_sick_a")) || 0;
    R.sick_pay.params.factor_b = parseFloat(gv("rl_sick_b")) || 0;
    R.sick_pay.params.sick_base = gv("rl_sick_base");
    R.meal_subsidy.mode = gv("rl_meal_mode");
    R.allowances.mode = gv("rl_allow_mode");
    R.reward_punish.full_in_gross = gc("rl_rp");
    R.tax.basic_deduction = parseFloat(gv("rl_tax_basic")) || 5000;
    R.tax.cum_start = gv("rl_cum_start");
    let _br = taxSync().map(x => [Number(x[0]), Number(x[1]), Number(x[2])]);
    _br = _br.filter(x => x[1] > 0 && x[1] <= 1);
    _br.sort((a, b) => a[0] - b[0]);
    if (_br.length === 0) { alert('请至少填写一级有效税率（税率需在 0~100% 之间）'); return; }
    _br[_br.length - 1][0] = 99999999999;
    R.tax.brackets = _br;
    R.deduction_rules = R.deduction_rules || {};
    R.deduction_rules.miss_punch = {enabled: gc("rl_miss_on"), first_3: parseFloat(gv("rl_miss_f3"))||30, after_3: parseFloat(gv("rl_miss_a3"))||50};
    R.deduction_rules.absent = {enabled: gc("rl_abs_on"), multiplier: parseFloat(gv("rl_abs_mult"))||2};
    R.deduction_rules.late_early = {enabled: gc("rl_late_on"), per_time: parseFloat(gv("rl_late_per"))||10};
    R.formula = R.formula || {};
    R.formula.gross = gv("rl_formula_gross").trim();
    R.formula.net = gv("rl_formula_net").trim();
    if (!R.formula.gross || !R.formula.net) { alert("应发合计和实发工资公式不能为空"); return; }
    await api("/api/calc_rules/save", {body:{rules: R}});
    window._rules = R;
    window._customFields = cfFields;
    toast("✓ 所有薪酬设置已保存，下次核算生效");
    renderSalarySettingsUI();
  } catch(e) { alert("保存失败：" + e.message); }
}

async function rulesSave() {
  const R = JSON.parse(JSON.stringify(window._rules));
  const gv = id => document.getElementById(id).value;
  const gc = id => document.getElementById(id).checked;
  R.base_salary.segment_by_date = gc("rl_seg");
  R.base_salary.prorate_base = gv("rl_prorate");
  R.performance.enabled = gc("rl_perf_on");
  R.performance.probation_excluded = gc("rl_perf_prob");
  R.sick_pay.enabled = gc("rl_sick_on");
  R.sick_pay.params.factor_a = parseFloat(gv("rl_sick_a"));
  R.sick_pay.params.factor_b = parseFloat(gv("rl_sick_b"));
  R.sick_pay.params.sick_base = gv("rl_sick_base");
  R.meal_subsidy.mode = gv("rl_meal_mode");
  R.allowances.mode = gv("rl_allow_mode");
  R.reward_punish.full_in_gross = gc("rl_rp");
  R.tax.basic_deduction = parseFloat(gv("rl_tax_basic"));
  R.tax.cum_start = gv("rl_cum_start");
  const brackets = [];
  R.tax.brackets.forEach((b, i) => {
    const lv = gv("rb_l_" + i);
    brackets.push([lv === "" ? 99999999999 : parseFloat(lv), parseFloat(gv("rb_r_" + i)), parseFloat(gv("rb_q_" + i))]);
  });
  brackets.sort((a, b) => a[0] - b[0]);
  R.tax.brackets = brackets;
  // 考勤扣款规则
  R.deduction_rules = R.deduction_rules || {};
  R.deduction_rules.miss_punch = {enabled: gc("rl_miss_on"), first_3: parseFloat(gv("rl_miss_f3")), after_3: parseFloat(gv("rl_miss_a3"))};
  R.deduction_rules.absent = {enabled: gc("rl_abs_on"), multiplier: parseFloat(gv("rl_abs_mult"))};
  R.deduction_rules.late_early = {enabled: gc("rl_late_on"), per_time: parseFloat(gv("rl_late_per"))};
  // 完整核算公式
  R.formula = R.formula || {};
  R.formula.gross = gv("rl_formula_gross").trim();
  R.formula.net = gv("rl_formula_net").trim();
  if (!R.formula.gross || !R.formula.net) { alert("应发合计公式和实发工资公式不能为空"); return; }
  try {
    await api("/api/calc_rules/save", { body: { rules: R } });
    toast("计算规则已保存，下次核算生效");
  } catch (e) { alert(e.message); }
}

// 公式验证（前端用示例数据试算）
function formulaTest() {
  const gv = id => (document.getElementById(id) || {}).value || "";
  const grossExpr = gv("rl_formula_gross").trim();
  const netExpr = gv("rl_formula_net").trim();
  const sample = {"应发基本工资":5000, "应发绩效工资":1000, "病假工资":0, "夜班话费补贴":0, "餐补":0, "其他补贴":0,
    "月度奖励":0, "已发福利":0, "月度扣罚":0, "缺卡扣款":0, "迟到早退扣款":0, "其他扣款":0, "工装扣款":0,
    "应发合计":0, "五险一金合计":500, "本月个税":30, "养老保险":300, "医疗保险":100, "失业保险":20, "住房公积金":80, "大病":0, "附加扣除合计":0};
  const _cfS = (window._rules?.custom_fields || []).filter(f => f.enabled);
  _cfS.forEach(f => { sample[f.name] = 0; });
  try {
    sample["应发合计"] = evalFormulaSafe(grossExpr, sample);
    const net = evalFormulaSafe(netExpr, sample);
    const el = document.getElementById("formulaTestResult");
    el.innerHTML = `<span style="color:#16a34a">验证通过：应发=${sample["应发合计"].toFixed(2)}，实发=${net.toFixed(2)}</span>`;
  } catch (e) {
    const el = document.getElementById("formulaTestResult");
    el.innerHTML = `<span style="color:#dc2626">公式错误：${esc(e.message)}</span>`;
  }
}
function evalFormulaSafe(expr, vars) {
  // 前端安全求值：仅允许 数字、中文/英文白名单变量、+ - * / ( )
  const tokens = expr.match(/[\u4e00-\u9fa5]+|[a-zA-Z_]\w*|[0-9.]+|[+\-*/()]/g) || [];
  const rebuilt = tokens.join("");
  if (rebuilt !== expr.replace(/\s/g, "")) throw new Error("含非法字符");
  const allowed = Object.keys(vars);
  const code = tokens.map(t => {
    if (/^[0-9.]+$/.test(t)) return t;
    if (/^[\u4e00-\u9fa5]+$/.test(t) || /^[a-zA-Z_]\w*$/.test(t)) {
      if (!allowed.includes(t)) throw new Error("未知项目: " + t);
      return "(" + vars[t] + ")";
    }
    return t;
  }).join("");
  return Function('"use strict";return (' + code + ')')();
}
function formulaReset() {
  if (!confirm("确认将应发合计和实发工资公式恢复为默认值？")) return;
  document.getElementById("rl_formula_gross").value = "应发基本工资 + 应发绩效工资 + 病假工资 + 夜班话费补贴 + 餐补 + 其他补贴 + 月度奖励 + 已发福利 - 月度扣罚 - 缺卡扣款 - 迟到早退扣款 - 其他扣款 - 工装扣款";
  document.getElementById("rl_formula_net").value = "应发合计 - 五险一金合计 - 本月个税 - 已发福利";
  toast("已恢复默认公式，记得点保存");
}

/* ---------------- 符号库设置 ---------------- */
const SYM_CATEGORIES = ["正常", "事假", "病假", "产假", "年假调休", "缺卡", "旷工", "迟到", "早退", "值班", "公休", "其他"];
async function pageSymbols(container, subMode) {
  if (subMode) { container.innerHTML = '<div id="symSubArea">加载中...</div>'; loadSymbols(); return; }
  const c = container || document.getElementById("content");
  c.innerHTML = `<div class="card"><h3>考勤符号库设置（符号应用计算逻辑）</h3>
  <div class="msg info">每个符号的「计入实际出勤 / 折算出勤天数 / 归类统计」即为该符号的计算逻辑。应出勤天数已改为手动填写，不再由符号统计。修改后新上传考勤立即按新口径统计；历史月份需重新上传考勤或重算才会更新。考勤表统计公式由符号库自动生成并同步（见下方"考勤表公式预览"），也可导出/导入配置。</div>
  <div class="card" style="box-shadow:none;border:1px solid #e5e7eb"><h3>新增符号</h3>
    <div class="row">
      <label class="fld">符号 <input type="text" id="ns_sym" style="width:60px" placeholder="如 ∨"></label>
      <label class="fld">释义 <input type="text" id="ns_name" style="width:120px" placeholder="如 正常出勤"></label>
      <label class="fld"><input type="checkbox" id="ns_act" checked> 计入实际出勤</label>
      <label class="fld">折算出勤天数 <input type="number" step="0.5" id="ns_val" value="1" style="width:70px"></label>
      <label class="fld">归类统计 <select id="ns_cat">${SYM_CATEGORIES.map(x => `<option>${x}</option>`).join("")}</select></label>
      <button class="btn primary" onclick="symAdd()">添加</button>
    </div>
  </div>
  <div class="row" style="margin-bottom:10px">
    <button class="btn primary" onclick="symSave()">保存符号库</button>
    <button class="btn" onclick="symReset()">恢复默认符号库</button>
    <button class="btn" onclick="download('/api/symbols/export','考勤符号与公式配置.json')">导出配置</button>
    <input type="file" id="symFile" accept=".json" style="display:none" onchange="symImport()">
    <button class="btn" onclick="document.getElementById('symFile').click()">导入配置</button>
  </div>
  <div id="symArea">加载中...</div>
  <div class="hint">归类统计含义：事假/病假/产假/年假调休/旷工 → 计入对应假期天数统计；迟到/早退/缺卡 → 计入对应次数统计；正常/值班/公休/其他 → 不计入假期与缺勤统计。上传考勤时出现库外符号将被拦截。</div>
  <div class="card" style="box-shadow:none;border:1px solid #e5e7eb;margin-top:12px"><h3>考勤表统计公式预览（随符号库自动生成）</h3><div id="symFormula" class="hint" style="margin:0">加载中...</div></div>`;
  loadSymbols();
}
async function loadSymbols() {
  try {
    const data = await api("/api/symbols");
    window._symbols = data.items;
    renderSymTable();
    const fd = await api("/api/symbols/formulas");
    const F = fd.formulas;
    const _symFEl = document.getElementById("symFormula") || document.getElementById("symFormulaSub");
    if (_symFEl) _symFEl.innerHTML = 
      Object.entries(F.categories).map(([k, v]) => `<b>${esc({personal:"事假",sick:"病假",maternity:"产假",paid:"带薪假",miss:"缺卡",absent:"旷工",late:"迟到",early:"早退"}[k])}</b> = ${v === "0" ? "0" : esc("=" + v)}`).join("<br>");
  } catch (e) { const _t = document.getElementById("symSubArea") || document.getElementById("symArea"); if (_t) _t.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function renderSymTable() {
  let html = `<div class="table-wrap"><table class="tb"><thead><tr>
    <th>符号</th><th>释义</th><th>计入实际出勤</th><th>折算出勤天数</th><th>归类统计</th><th>说明</th><th>操作</th></tr></thead><tbody>`;
  window._symbols.forEach((s, i) => {
    html += `<tr>
      <td><input type="text" style="width:52px;text-align:center" value="${esc(s.symbol)}" onchange="window._symbols[${i}].symbol=this.value"></td>
      <td><input type="text" style="width:110px" value="${esc(s.name)}" onchange="window._symbols[${i}].name=this.value"></td>
      <td style="text-align:center"><input type="checkbox" ${s.in_actual ? "checked" : ""} onchange="window._symbols[${i}].in_actual=this.checked"></td>
      <td><input type="number" step="0.5" style="width:70px" value="${s.value}" onchange="window._symbols[${i}].value=this.value"></td>
      <td><select onchange="window._symbols[${i}].category=this.value">${SYM_CATEGORIES.map(x => `<option ${s.category === x ? "selected" : ""}>${x}</option>`).join("")}</select></td>
      <td><input type="text" style="width:200px" value="${esc(s.desc || "")}" onchange="window._symbols[${i}].desc=this.value"></td>
      <td><button class="btn sm primary" onclick="symSave()">保存</button> <button class="btn sm danger" onclick="symDel(${i})">删除</button></td></tr>`;
  });
  html += `</tbody></table></div>`;
  const _target = document.getElementById("symSubArea") || document.getElementById("symArea"); if(_target) _target.innerHTML = html;
}
function symAdd() {
  const g = id => document.getElementById(id);
  const sym = g("ns_sym").value.trim();
  if (!sym) return toast("符号不能为空", false);
  if (window._symbols.some(s => s.symbol === sym)) return toast("符号已存在", false);
  window._symbols.push({ symbol: sym, name: g("ns_name").value.trim(), in_required: true,
    in_actual: g("ns_act").checked, value: parseFloat(g("ns_val").value || 0), category: g("ns_cat").value, desc: "" });
  g("ns_sym").value = ""; g("ns_name").value = "";
  renderSymTable();
}
function symDel(i) {
  if (!confirm(`确认删除符号"${window._symbols[i].symbol}"？删除后需点击"保存符号库"生效。`)) return;
  window._symbols.splice(i, 1);
  renderSymTable();
}
async function symSave() {
  for (const s of window._symbols) {
    if (!String(s.symbol).trim()) return toast("存在空符号，请检查", false);
    s.value = parseFloat(s.value);
  }
  try {
    await api("/api/symbols/save", { body: { items: window._symbols } });
    toast("符号库已保存，考勤表公式已同步");
    loadSymbols();
  } catch (e) { alert(e.message); }
}
async function symReset() {
  if (!confirm("确认恢复默认符号库？当前自定义符号将被覆盖。")) return;
  try { await api("/api/symbols/reset", { body: {} }); toast("已恢复默认"); loadSymbols(); }
  catch (e) { alert(e.message); }
}
async function symImport() {
  const f = document.getElementById("symFile").files[0];
  if (!f) return;
  const form = new FormData();
  form.append("file", f);
  try { await api("/api/symbols/import", { form }); toast("配置已导入"); document.getElementById("symFile").value = ""; loadSymbols(); }
  catch (e) { alert(e.message); }
}

/* ---------------- 权限管理 ---------------- */
/* 人员档案字段设置（必填项配置） */
async function settingsStaffField(el) {
  el.innerHTML = `<div class="perm-block">
    <h4 style="margin:0 0 6px">人员档案字段设置</h4>
    <div class="msg info" style="margin-bottom:12px">勾选以下字段为「必填项」。在新增/编辑人员、以及批量导入时都会按此校验：必填为空、身份证/手机/日期/枚举格式错误、上级不存在 → 保存失败或整批拒绝导入。<b>姓名、所属项目</b>始终必填，不可取消。</div>
    <div id="staffFieldBox">加载中...</div>
    <div class="row end" style="margin-top:14px"><button class="btn primary" onclick="saveStaffFieldRequired()">保存必填项设置</button></div>
  </div>`;
  try {
    const d = await api("/api/staff/field_config");
    window._staffReq = new Set(d.required || []);
    const box = document.getElementById("staffFieldBox");
    box.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px">` +
      Object.keys(d.requirable).map(k => {
        const locked = k === "name" || k === "project";
        const checked = window._staffReq.has(k);
        return `<label class="rt-func" style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#fff">
          <input type="checkbox" value="${k}" ${checked || locked ? "checked" : ""} ${locked ? "disabled" : ""} onchange="window._staffReq.has('${k}')?window._staffReq.delete('${k}'):window._staffReq.add('${k}')">
          ${esc(d.requirable[k])}${locked ? "（固定）" : ""}</label>`;
      }).join("") + `</div>`;
  } catch (e) { document.getElementById("staffFieldBox").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function saveStaffFieldRequired() {
  try {
    const r = await api("/api/staff/field_config", { body: { required: [...window._staffReq] } });
    toast("必填项设置已保存"); toast("已生效于新增/编辑与批量导入校验", false);
  } catch (e) { alert(e.message); }
}


/* ---------------- 系统设置中心 ---------------- */
const SETTINGS_TABS = [
  { key: "perm", label: "👤 权限管理", perm: "users" },
  { key: "approvalFlow", label: "✅ 审批权责设置", perm: "users" },
  { key: "salarySettings", label: "💰 薪酬设置", perm: "rules" },
  { key: "company", label: "🏢 公司信息", perm: "settings" },
  { key: "security", label: "🔒 登录安全", perm: "settings" },
  { key: "backup", label: "💾 数据备份", perm: "backup" },
  { key: "logs", label: "📜 操作日志", perm: "logs" },
];
let _setTab = "perm";
async function pageSettings(tab) {
  if (tab) _setTab = tab;
  const c = document.getElementById("content");
  c.innerHTML = `<div class="card"><h3>系统设置</h3>
    <div class="settings-wrap">
      <div class="settings-side" id="setTabs"></div>
      <div class="settings-content" id="setContent">加载中...</div>
    </div></div>`;
  renderSettingsTabs();
  await loadSettingsTab(_setTab);
}
function renderSettingsTabs() {
  const el = document.getElementById("setTabs");
  if (!el) return;
  el.innerHTML = SETTINGS_TABS.filter(t => canPerm(t.perm)).map(t =>
    `<div class="settings-tab${_setTab === t.key ? " active" : ""}" onclick="settingsGo('${t.key}')">${t.label}</div>`).join("");
}
async function settingsGo(tab) {
  _setTab = tab;
  renderSettingsTabs();
  await loadSettingsTab(tab);
}
async function loadSettingsTab(tab) {
  const el = document.getElementById("setContent");
  if (!el) return;
  el.innerHTML = "加载中...";
  if (tab === "salarySettings") return pageSalarySettings(el);
  const fn = { perm: settingsPerm, company: settingsCompany, security: settingsSecurity, backup: settingsBackup, logs: settingsLogs, approvalFlow: settingsApprovalFlow }[tab];
  if (fn) { try { await fn(el); } catch (e) { el.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; } }
}

/* -- 权限管理 -- */
let _roleTypeId = null;
let _permTab = "perm"; // perm=权限配置, members=成员管理
async function settingsPerm(el) {
  el.innerHTML = `
    <div class="perm-layout">
      <div class="perm-side">
        <div class="perm-side-head"><span>角色列表</span><button class="btn primary sm" onclick="roleTypeNew()">＋ 新建</button></div>
        <div id="permRoleList" class="perm-role-list">加载中...</div>
      </div>
      <div class="perm-main"><div id="permRoleDetail"><div class="hint" style="padding:24px;text-align:center">请选择左侧角色查看详情</div></div></div>
    </div>`;
  try {
    const data = await api("/api/roles");
    window._allRoles = data.roles; state.roles = data.roles;
    if (!_roleTypeId || !data.roles.find(r => r.id === _roleTypeId)) _roleTypeId = data.roles[0] ? data.roles[0].id : "viewer";
    await loadUsersList();
    renderRoleSide();
    renderRoleDetail();
  } catch (e) { el.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function roleIcon(id, name) {
  const map = { admin: "超", project: "项", staff: "员", viewer: "只" };
  if (map[id]) return map[id];
  return (name || "角").slice(0, 1);
}
function roleColor(id) {
  const map = { admin: "#2B7CF6", project: "#23A355", staff: "#F58B1F", viewer: "#8A94A6" };
  return map[id] || "#9EACEA";
}
function renderRoleSide() {
  const list = document.getElementById("permRoleList");
  if (!list) return;
  list.innerHTML = window._allRoles.map(r => {
    const cnt = (window._roleCounts && window._roleCounts[r.id]) || 0;
    return `<div class="perm-role-item${_roleTypeId === r.id ? " active" : ""}" onclick="selectRoleType('${r.id}')">
      <div class="perm-role-ico" style="background:${roleColor(r.id)}">${roleIcon(r.id, r.name)}</div>
      <div class="perm-role-info">
        <div class="perm-role-name">${esc(r.name)}${r.builtin ? '<span class="perm-builtin">内置</span>' : ""}</div>
        <div class="perm-role-meta">${cnt} 个账号</div>
      </div>
      ${r.builtin ? "" : `<span class="perm-role-del" onclick="event.stopPropagation();roleTypeDel('${r.id}')" title="删除">×</span>`}
    </div>`;
  }).join("");
}
async function selectRoleType(id) {
  _roleTypeId = id;
  _permTab = "perm";
  renderRoleSide();
  renderRoleDetail();
}
function renderRoleDetail() {
  const ed = document.getElementById("permRoleDetail");
  if (!ed) return;
  const r = window._allRoles.find(x => x.id === _roleTypeId);
  if (!r) { ed.innerHTML = ""; return; }
  const cnt = (window._roleCounts && window._roleCounts[r.id]) || 0;
  ed.innerHTML = `
    <div class="perm-role-head">
      <div class="perm-role-head-info">
        <div class="perm-role-ico lg" style="background:${roleColor(r.id)}">${roleIcon(r.id, r.name)}</div>
        <div>
          <div class="perm-role-title">${esc(r.name)} ${r.builtin ? '<span class="perm-builtin">内置</span>' : ""}</div>
          <div class="perm-role-desc">${r.id === "admin" ? "拥有全部权限，数据范围固定为全部项目，不可修改" : "自定义该角色的模块权限与数据范围，保存后对使用此角色的账号立即生效"} · ${cnt} 个账号使用此类型</div>
        </div>
      </div>
      ${r.id === "admin" ? "" : '<button class="btn primary sm" onclick="roleTypeSave()">保存此角色</button>'}
    </div>
    <div class="perm-tabs">
      <div class="perm-tab${_permTab === "perm" ? " active" : ""}" onclick="permTab('perm')">权限配置</div>
      <div class="perm-tab${_permTab === "members" ? " active" : ""}" onclick="permTab('members')">成员管理（${cnt}）</div>
    </div>
    <div id="permTabBody" style="margin-top:14px">${_permTab === "perm" ? permTreeHtml(r) : ""}</div>
    <div id="permMemberBody" style="display:${_permTab === "members" ? "block" : "none"}"></div>`;
  if (_permTab === "members") renderMembers(r.id);
}
function permTab(t) {
  _permTab = t;
  const r = window._allRoles.find(x => x.id === _roleTypeId);
  if (r) renderRoleDetail();
}
function permTreeHtml(r) {
  const isAdmin = r.id === "admin";
  const perms = new Set(r.perms || []);
  const modTree = state.appModules.map(m => {
    const mperms = (m.perms || []).map(p => p[0]);
    const checked = isAdmin ? mperms.length : mperms.filter(p => perms.has(p)).length;
    const all = mperms.length > 0 && checked === mperms.length;
    const some = checked > 0 && !all;
    return `<div class="rt-mod${all || some ? " on" : ""}">
      <div class="rt-mod-head" onclick="toggleModTree(this)">
        <input type="checkbox" class="rt-mod-cb" ${isAdmin ? "checked disabled" : (all ? "checked" : "")} onchange="toggleModAll(this)" onclick="event.stopPropagation()">
        <span class="mpr-ico" style="background:${m.color}">${m.icon}</span>
        <span class="rt-mod-name">${m.name}</span>
        <span class="rt-mod-count">${checked}/${mperms.length}</span>
        <span class="rt-arrow">▸</span>
      </div>
      <div class="rt-mod-sub">
        ${(m.perms || []).map(p => `<label class="rt-func"><input type="checkbox" class="rt-func-cb" value="${p[0]}" ${isAdmin ? "checked disabled" : (perms.has(p[0]) ? "checked" : "")} onchange="onFuncCheck(this)"> ${esc(p[1])}</label>`).join("")}
      </div>
    </div>`;
  }).join("");
  return `
    <div class="perm-scope"><span style="font-weight:600;color:#1f2937">数据范围</span>
      <label><input type="radio" name="rt_scope" value="all" ${r.scope !== "project" ? "checked" : ""} ${isAdmin ? "disabled" : ""}> 全部项目（all）</label>
      <label><input type="radio" name="rt_scope" value="project" ${r.scope === "project" ? "checked" : ""} ${isAdmin ? "disabled" : ""}> 仅本项目（project）</label>
    </div>
    <div class="rt-tree" style="max-height:400px">${modTree}</div>
    <div class="hint" style="margin-top:10px">${isAdmin ? "超级管理员固定拥有全部权限，不可修改。" : "勾选模块 = 全选该模块权限点，支持半选状态；保存后对所有使用此角色的账号立即生效。"}</div>`;
}
function renderMembers(roleId) {
  const body = document.getElementById("permMemberBody");
  if (!body) return;
  const users = (window._usersList || []).filter(u => u.role === roleId);
  const html = users.map(u => {
    const opts = state.roles.map(r => `<option value="${r.id}" ${u.role === r.id ? "selected" : ""}>${esc(r.name)}</option>`).join("");
    const stateTag = u.enabled ? `<span class="tag green">启用</span>` : `<span class="tag gray">停用</span>`;
    const bound = u.staff_id ? `${esc(u.staff_name || "已关联")}${u.staff_deleted ? '<span class="tag red" style="margin-left:4px">已离职</span>' : ""}` : `<span class="tag gray">未绑定人员</span>`;
    return `<tr><td>${esc(u.username)}</td><td>${esc(u.name)}</td><td>${bound}</td>
      <td><select class="role-quick-sel" onchange="quickSetRole(${u.id}, this.value)">${opts}</select></td>
      <td>${esc(u.project || "-")}</td><td>${stateTag}</td>
      <td><button class="btn sm" onclick='userEdit(${JSON.stringify(u).replace(/'/g, "&#39;")})'>编辑</button>
      <button class="btn sm ${u.enabled ? "danger" : "success"}" onclick="userToggleStatus(${u.id},${u.enabled ? "false" : "true"})">${u.enabled ? "停用" : "启用"}</button></td></tr>`;
  }).join("");
  body.innerHTML = `
    <div class="row" style="margin-bottom:8px;justify-content:space-between">
      <span class="hint" style="margin:0">该角色下的账号（${users.length}）—— 审批入职办理开通的账号也会出现在这里</span>
      <button class="btn success sm" onclick="userEdit(0)">＋ 新增账号</button>
    </div>
    ${users.length ? `<div class="table-wrap"><table class="tb"><thead><tr><th>用户名</th><th>姓名</th><th>绑定人员</th><th>账号类型</th><th>绑定项目</th><th>员工状态</th><th>操作</th></tr></thead><tbody>${html}</tbody></table></div>` : '<div class="hint" style="padding:22px;text-align:center;color:#9ca3af">该角色下暂无账号，点击右上角"新增账号"创建</div>'}`;
}
function toggleModTree(head) { head.parentElement.classList.toggle("open"); }
function toggleModAll(cb) {
  const mod = cb.closest(".rt-mod");
  mod.querySelectorAll(".rt-func-cb").forEach(f => f.checked = cb.checked);
  mod.classList.toggle("on", cb.checked);
  mod.querySelector(".rt-mod-count").textContent = cb.checked ? mod.querySelectorAll(".rt-func-cb").length + "/" + mod.querySelectorAll(".rt-func-cb").length : "0/" + mod.querySelectorAll(".rt-func-cb").length;
}
function onFuncCheck(cb) {
  const mod = cb.closest(".rt-mod");
  const funcs = mod.querySelectorAll(".rt-func-cb");
  const checked = mod.querySelectorAll(".rt-func-cb:checked").length;
  mod.querySelector(".rt-mod-cb").checked = checked === funcs.length;
  mod.querySelector(".rt-mod-cb").indeterminate = checked > 0 && checked < funcs.length;
  mod.classList.toggle("on", checked > 0);
  mod.querySelector(".rt-mod-count").textContent = checked + "/" + funcs.length;
}
async function roleTypeSave() {
  const r = window._allRoles.find(x => x.id === _roleTypeId);
  const perms = Array.from(document.querySelectorAll(".rt-func-cb:checked")).map(c => c.value);
  const scope = (document.querySelector("input[name=rt_scope]:checked") || {}).value || "all";
  const roles = window._allRoles.map(x => x.id === r.id ? Object.assign({}, x, {perms, scope}) : x);
  try {
    await api("/api/roles/save", {body: {roles}});
    window._allRoles = roles; state.roles = roles;
    renderRoleSide(); renderRoleDetail();
    toast("账号类型权限已保存");
  } catch (e) { alert(e.message); }
}
function roleTypeNew() {
  const name = prompt("请输入新账号类型名称：");
  if (!name) return;
  const id = "custom_" + Date.now();
  window._allRoles.push({id, name: name.trim(), builtin: false, scope: "all", perms: []});
  _roleTypeId = id;
  _permTab = "perm";
  renderRoleSide();
  renderRoleDetail();
}
async function roleTypeDel(id) {
  if (!confirm("确认删除该账号类型？使用此类型的账号将自动转为只读账号。")) return;
  const roles = window._allRoles.filter(r => r.id !== id);
  try {
    const resp = await api("/api/roles/save", {body: {roles}});
    window._allRoles = resp.roles; state.roles = resp.roles;
    _roleTypeId = resp.roles[0] ? resp.roles[0].id : "viewer";
    _permTab = "perm";
    renderRoleSide(); renderRoleDetail(); loadUsersList();
    toast("已删除");
  } catch (e) { alert(e.message); }
}
async function loadUsersList() {
  try {
    const data = await api("/api/users");
    window._usersMap = {};
    window._usersList = data.users;
    data.users.forEach(u => window._usersMap[u.id] = u);
    window._roleCounts = {};
    data.users.forEach(u => { window._roleCounts[u.role] = (window._roleCounts[u.role] || 0) + 1; });
    renderRoleSide();
    if (_permTab === "members" && _roleTypeId) renderMembers(_roleTypeId);
  } catch (e) { console.error(e); }
}
async function userToggleStatus(id, enabled) {
  try {
    await api("/api/users/status", { body: { id, enabled } });
    toast(enabled ? "已启用" : "已停用");
    loadUsersList();
  } catch (e) { alert(e.message); }
}
async function quickSetRole(uid, roleId) {
  const u = window._usersMap[uid];
  const rdef = state.roles.find(r => r.id === roleId);
  const project = u.project || "";
  try {
    await api("/api/users/save", {body: {user: {id: uid, role: roleId, name: u.name, username: u.username, project}}});
    toast("已设为「" + rdef.name + "」");
    loadUsersList();
  } catch (e) { alert(e.message); loadUsersList(); }
}

/* -- 公司信息 -- */
async function settingsCompany(el) {
  const c = (state.settings && state.settings.company) || {};
  el.innerHTML = `<div class="form-grid" style="max-width:560px">
    <label>公司简称<input type="text" id="co_name" value="${esc(c.name || "")}"></label>
    <label>版本号<input type="text" id="co_ver" value="${esc(c.version || "")}"></label>
    <label class="full">系统全称<input type="text" id="co_full" value="${esc(c.full_name || "")}"></label>
    <label class="full">副标题<input type="text" id="co_sub" value="${esc(c.subtitle || "")}"></label>
    <label class="full">版权文字<input type="text" id="co_copy" value="${esc(c.copyright || "")}"></label>
  </div>
  <div class="hint">保存后刷新登录页和侧边栏标题生效。</div>
  <div class="row" style="margin-top:12px"><button class="btn primary" onclick="companySave()">保存</button></div>`;
}
async function companySave() {
  const g = id => document.getElementById(id).value;
  try {
    await api("/api/settings/save", { body: { settings: { company: { name: g("co_name"), full_name: g("co_full"), subtitle: g("co_sub"), copyright: g("co_copy"), version: g("co_ver") } } } });
    const d = await api("/api/settings"); state.settings = d.settings;
    toast("公司信息已保存");
  } catch (e) { alert(e.message); }
}

/* -- 登录安全 -- */
async function settingsSecurity(el) {
  const s = (state.settings && state.settings.security) || {};
  el.innerHTML = `<h4 style="margin:0 0 10px">密码策略</h4>
  <div class="form-grid" style="max-width:560px">
    <label>密码最小长度<input type="number" id="se_pwlen" min="4" max="32" value="${s.password_min_length || 6}"></label>
  </div>
  <div class="row" style="margin:10px 0 18px"><button class="btn primary" onclick="securitySave()">保存密码策略</button></div>
  <h4 style="margin:0 0 10px">工资条自助查询</h4>
  <div id="payslipCfgArea">加载中...</div>`;
  try {
    const ps = await api("/api/payslip/config");
    const cfg = ps.config || ps || {};
    const slipUrl = location.origin + "/payslip.html";
    document.getElementById("payslipCfgArea").innerHTML = `<div class="form-grid">
      <label><input type="checkbox" id="ps_enabled" ${cfg.enabled ? "checked" : ""}> 启用工资条自助查询</label>
      <label>查询月份<select id="ps_month"><option value="prev" ${cfg.query_month === "prev" ? "selected" : ""}>上月工资</option><option value="current" ${cfg.query_month === "current" ? "selected" : ""}>当月工资</option></select></label>
      <label>每月开放开始日<input type="number" min="1" max="31" id="ps_start" value="${cfg.open_day_start ?? ""}" style="width:80px"></label>
      <label>每月开放结束日<input type="number" min="1" max="31" id="ps_end" value="${cfg.open_day_end ?? ""}" style="width:80px"></label>
      <label class="full">系统标题<input type="text" id="ps_title" value="${esc(cfg.title || "")}"></label>
    </div>
    <div class="hint" style="margin:8px 0">开放日期：开始日≤结束日当月开放；开始日>结束日跨月开放。员工查询地址：<a href="${slipUrl}" target="_blank" style="color:#2563eb;text-decoration:underline"><code>${slipUrl}</code></a>，凭姓名+身份证后六位查询。</div>
    <div class="row"><button class="btn primary" onclick="payslipCfgSave()">保存工资条设置</button></div>`;
  } catch (e) { document.getElementById("payslipCfgArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function securitySave() {
  try {
    await api("/api/settings/save", { body: { settings: { security: { password_min_length: parseInt(document.getElementById("se_pwlen").value) } } } });
    const d = await api("/api/settings"); state.settings = d.settings;
    toast("密码策略已保存");
  } catch (e) { alert(e.message); }
}

/* -- 数据备份 -- */
async function settingsBackup(el) {
  const b = (state.settings && state.settings.backup) || {};
  el.innerHTML = `<h4 style="margin:0 0 10px">备份策略</h4>
  <div class="form-grid" style="max-width:560px">
    <label><input type="checkbox" id="bk_auto" ${b.auto_backup ? "checked" : ""}> 启用自动备份（预留）</label>
    <label>备份频率<select id="bk_freq"><option value="daily" ${b.frequency === "daily" ? "selected" : ""}>每日</option><option value="weekly" ${b.frequency === "weekly" ? "selected" : ""}>每周</option><option value="monthly" ${b.frequency === "monthly" ? "selected" : ""}>每月</option></select></label>
    <label>保留份数<input type="number" id="bk_keep" min="1" max="100" value="${b.keep_count || 10}"></label>
  </div>
  <div class="row" style="margin:10px 0 18px"><button class="btn primary" onclick="backupSetSave()">保存策略</button></div>
  <h4 style="margin:0 0 10px">立即备份</h4>
  <div class="row"><button class="btn success" onclick="doBackup()">立即备份全部业务数据</button></div>
  <div id="bkMsg"></div>
  <h4 style="margin:18px 0 10px;color:#dc2626">一键清除数据（危险操作）</h4>
  <div class="hint" style="color:#b91c1c">清除前请先确认已备份。将清除人员档案、考勤、工资核算、工资调整、预算、绩效、审批单、入职办理、维保合同/合作方、调动日志、系统消息、项目档案表；采购系统：填报明细、报价存档、清单外审核、采购预算、月度归档、采购通知、操作日志、标准商品库。保留管理员账号、角色权限、工资规则、考勤符号库、字段设置、审批流程设计、组织架构、采购项目/账号/填报窗口。项目将按组织架构自动重建。</div>
  <div class="row"><button class="btn danger" onclick="clearDataWizard()">⚠ 一键清除数据</button></div>
  <div id="cdMsg"></div>
  <div id="bkList" style="margin-top:12px">加载中...</div>
  <div class="hint">备份内容：人员档案、调薪记录、考勤、工资核算、预算、符号库、计算规则、账号、角色、设置等全部JSON数据；采购系统：填报明细、报价存档、清单外审核、采购预算、月度归档、采购通知、操作日志、标准商品库。建议每月归档后备份一次，并将备份文件另存到U盘/网盘。</div>`;
  loadBackups();
}
function clearDataWizard() {
  modal(`<h3 style="color:#dc2626">⚠ 一键清除数据</h3>
  <div class="msg warn" style="margin:10px 0">此操作<b>不可恢复</b>！将清除以下全部业务数据：</div>
  <div class="hint" style="margin-bottom:10px">人员档案、考勤记录、工资核算、工资调整、预算数据、绩效考核、审批单（含草稿/分享）、入职办理清单、维保合同/合作方、调动日志、系统消息、项目档案表（项目将按组织架构自动重建）；采购系统：填报明细、报价存档、清单外审核、采购预算、月度归档、采购通知、操作日志、标准商品库。</div>
  <div class="hint" style="margin-bottom:14px">保留：管理员账号、角色权限、工资计算规则、考勤符号库、人员字段设置、审批流程设计、组织架构、采购项目/账号/填报窗口/系统设置。</div>
  <label style="display:block;margin-bottom:12px"><input type="checkbox" id="cd_backup" checked> 清除前自动备份全部业务数据（强烈建议勾选）</label>
  <label style="display:block;margin-bottom:12px">请输入「确认清除」四个字以继续：<input type="text" id="cd_kw" placeholder="确认清除" style="margin-top:4px"></label>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn danger" onclick="clearDataRun()">执行清除</button></div>`);
}
async function clearDataRun() {
  const kw = document.getElementById("cd_kw").value.trim();
  if (kw !== "确认清除") { toast("请输入「确认清除」四个字", false); return; }
  const doBackup = document.getElementById("cd_backup").checked;
  try {
    const data = await api("/api/admin/clear-data", { body: { backup: doBackup } });
    closeModal();
    let msg = "✅ 清除完成";
    if (data.backup) msg += "\n已自动备份：" + data.backup;
    msg += "\n\n清除明细：";
    const names = { payroll_staff: "人员档案", payroll_attendance: "考勤记录", payroll_results: "工资核算", payroll_salary_adjustments: "工资调整", payroll_budgets: "预算数据", payroll_projects: "项目档案", performance_plans: "绩效考核", maintenance_partners: "维保合作方", maintenance_contracts: "维保合同", approval_instances: "审批单", approval_drafts: "审批草稿", approval_shares: "审批分享", onboard_checklists: "入职办理清单", org_transfer_logs: "调动日志", app_messages: "系统消息", proc_purchase_items: "采购填报明细", proc_archived_purchases: "采购报价存档", proc_audit_records: "清单外审核", proc_budget_plan: "采购预算", proc_monthly_archive: "月度归档", proc_notifications: "采购通知", proc_op_logs: "采购操作日志", proc_products: "标准商品库", proc_product_synonyms: "商品别名" };
    for (const k in names) { if (typeof data.cleared[k] === "number") msg += "\n" + names[k] + "：" + data.cleared[k] + " 条"; }
    alert(msg);
    try { await loadStaff(); } catch (e) {}
    try { await orgLoad(true); } catch (e) {}
    try { await refreshProjects(); } catch (e) {}
  } catch (e) { alert("清除失败：" + e.message); }
}

async function backupSetSave() {
  try {
    await api("/api/settings/save", { body: { settings: { backup: { auto_backup: document.getElementById("bk_auto").checked, frequency: document.getElementById("bk_freq").value, keep_count: parseInt(document.getElementById("bk_keep").value) } } } });
    const d = await api("/api/settings"); state.settings = d.settings;
    toast("备份策略已保存");
  } catch (e) { alert(e.message); }
}

/* -- 操作日志 -- */
async function settingsLogs(el) {
  el.innerHTML = `<div id="logsArea">加载中...</div>`;
  try {
    const data = await api("/api/op_logs");
    if (!data.logs.length) { document.getElementById("logsArea").innerHTML = `<div class="msg info">暂无日志</div>`; return; }
    let html = `<div class="table-wrap"><table class="tb"><thead><tr><th>时间</th><th>操作人</th><th>操作</th><th>详情</th></tr></thead><tbody>`;
    for (const l of data.logs) {
      html += `<tr><td>${esc(l.ts)}</td><td>${esc(l.user)}</td><td><span class="tag blue">${esc(l.action)}</span></td><td>${esc(l.detail)}</td></tr>`;
    }
    document.getElementById("logsArea").innerHTML = html + `</tbody></table></div>`;
  } catch (e) { document.getElementById("logsArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}

async function payslipCfgSave() {
  const g = id => document.getElementById(id).value;
  const gc = id => document.getElementById(id).checked;
  const config = {
    enabled: gc("ps_enabled"),
    query_month: g("ps_month"),
    open_day_start: parseInt(g("ps_start")),
    open_day_end: parseInt(g("ps_end")),
    title: g("ps_title").trim(),
  };
  try {
    await api("/api/payslip/config/save", { body: { config } });
    toast("工资条查询设置已保存");
  } catch (e) { alert(e.message); }
}
async function userEdit(u) {
  const isNew = !u;
  const v = u || { username: "", name: "", role: "project", project: "", enabled: true };
  window._editingUid = v.id || 0;
  const isAdmin = v.role === "admin" || v.username === "admin";
  const roleOpts = state.roles.map(r => `<option value="${r.id}" ${v.role === r.id ? "selected" : ""}>${esc(r.name)}</option>`).join("");
  // 绑定人员（在职人员搜索下拉）
  let staffOpts = `<option value="">${isAdmin ? "（admin 内置账号，可不绑定）" : "— 请选择在职人员（一人一号）—"}</option>`;
  try {
    const so = await api("/api/org/staff-options");
    for (const x of so.staff) {
      staffOpts += `<option value="${x.id}" ${String(v.staff_id) === String(x.id) ? "selected" : ""}>${esc(x.name)}（${esc(x.project)}${x.dept_path ? "/" + esc(x.dept_path) : ""}）</option>`;
    }
  } catch (e) {}
  const html = `<h3>${isNew ? "新增账号" : "编辑账号 — " + esc(v.username)}</h3>
  <div class="form-grid">
    <label>用户名<input type="text" id="us_name" value="${esc(v.username)}" ${isNew ? "" : "readonly style='background:#f5f5f5'"}></label>
    <label>姓名<input type="text" id="us_real" value="${esc(v.name)}" readonly style="background:#f5f5f5"></label>
    <label class="full">绑定人员（选人后自动带姓名与项目）<select id="us_staff" onchange="onUserStaffChange()">${staffOpts}</select></label>
    <label>账号类型<select id="us_role" onchange="onUserRoleChange()">${roleOpts}</select></label>
    <label id="us_proj_wrap">绑定项目<select id="us_proj"><option value="">-</option>${state.projects.map(p => `<option ${p === v.project ? "selected" : ""}>${esc(p)}</option>`).join("")}</select></label>
    <label>账号状态<select id="us_enabled"><option value="1" ${v.enabled ? "selected" : ""}>启用</option><option value="0" ${!v.enabled ? "selected" : ""}>停用</option></select></label>
    <label class="full">${isNew ? "初始密码（至少8位）" : "重置密码（留空则不修改）"}<input type="password" id="us_pass"></label>
  </div>
  <div class="hint" style="margin-top:8px">账号必须绑定具体人员（admin 超管可豁免）：一人一号，绑定人员离职/拉黑时账号自动停用、复职恢复。账号类型的功能权限在上方"账号类型"区域配置，选择类型后自动应用。</div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="userSave(${isNew ? 0 : v.id})">保存</button></div>`;
  modal(html, 620);
  onUserRoleChange();
}
function onUserStaffChange() {
  const sel = document.getElementById("us_staff");
  const real = document.getElementById("us_real");
  if (sel && real) {
    const t = sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : "";
    const m = t.match(/^([^（(]+)/);
    if (m) real.value = m[1].trim();
  }
}
function onUserRoleChange() {
  const rid = document.getElementById("us_role").value;
  const r = state.roles.find(x => x.id === rid);
  const wrap = document.getElementById("us_proj_wrap");
  if (wrap) wrap.style.display = (r && r.scope === "project") ? "" : "none";
}
async function userSave(id) {
  const g = x => document.getElementById(x).value;
  const role = g("us_role");
  const rdef = state.roles.find(r => r.id === role);
  const project = (rdef && rdef.scope === "project") ? g("us_proj") : "";
  const staffId = g("us_staff") ? Number(g("us_staff")) : null;
  try {
    await api("/api/users/save", { body: { user: { id: id || null, username: g("us_name").trim(), name: g("us_real").trim(),
      role, project, staff_id: staffId, enabled: g("us_enabled") === "1", password: g("us_pass") } } });
    closeModal(); toast("已保存"); loadUsersList();
  } catch (e) { alert(e.message); }
}
function changePassword() {
  modal(`<h3>修改密码</h3><div class="form-grid">
    <label class="full">原密码<input type="password" id="pw_old"></label>
    <label class="full">新密码（至少6位）<input type="password" id="pw_new"></label></div>
    <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
    <button class="btn primary" onclick="doChangePw()">确认修改</button></div>`);
}
async function doChangePw() {
  try {
    await api("/api/change_password", { body: { old: document.getElementById("pw_old").value, new: document.getElementById("pw_new").value } });
    closeModal(); toast("密码已修改");
  } catch (e) { alert(e.message); }
}

/* ---------------- 数据备份操作 ---------------- */
async function doBackup() {
  try {
    const r = await api("/api/backup", { body: {} });
    document.getElementById("bkMsg").innerHTML = `<div class="msg ok">备份完成：${esc(r.file)}</div>`;
    loadBackups();
  } catch (e) { alert(e.message); }
}
async function loadBackups() {
  try {
    const data = await api("/api/backups");
    if (!data.backups.length) { document.getElementById("bkList").innerHTML = `<div class="msg info">暂无备份</div>`; return; }
    let html = `<div class="table-wrap"><table class="tb"><thead><tr><th>备份文件</th><th>时间</th><th>大小</th><th>操作</th></tr></thead><tbody>`;
    for (const b of data.backups) {
      html += `<tr><td>${esc(b.name)}</td><td>${esc(b.ts)}</td><td>${(b.size / 1024).toFixed(1)} KB</td>
        <td><a href="javascript:void(0)" onclick="download('/api/backups/download?f=${encodeURIComponent(b.name)}','${esc(b.name)}')">下载</a></td></tr>`;
    }
    document.getElementById("bkList").innerHTML = html + `</tbody></table></div>`;
  } catch (e) { document.getElementById("bkList").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}

/* ---------------- 数据可视化驾驶舱 ---------------- */
let _dashUid = 0;
function _duid() { return "dg" + (++_dashUid); }
const DASH_COLORS = ["#4f7cff", "#22c48a", "#f5a623", "#8b5cf6", "#06b6d4", "#ef4444"];
function smoothPath(pts) {
  if (!pts.length) return "";
  let d = `M${pts[0][0].toFixed(1)},${pts[0][1].toFixed(1)}`;
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[Math.max(0, i - 1)], p1 = pts[i], p2 = pts[i + 1], p3 = pts[Math.min(pts.length - 1, i + 2)];
    d += ` C${(p1[0] + (p2[0] - p0[0]) / 6).toFixed(1)},${(p1[1] + (p2[1] - p0[1]) / 6).toFixed(1)} ${(p2[0] - (p3[0] - p1[0]) / 6).toFixed(1)},${(p2[1] - (p3[1] - p1[1]) / 6).toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
  }
  return d;
}
function dashAxis(W, H, padL, padB, padT, maxV) {
  return [0.25, 0.5, 0.75, 1].map(f => {
    const y = H - padB - f * (H - padB - padT);
    const v = maxV * f;
    const lab = v >= 10000 ? (v / 10000).toFixed(v >= 100000 ? 0 : 1) + "万" : Math.round(v / 1000) + "k";
    return `<line x1="${padL}" y1="${y}" x2="${W - 8}" y2="${y}" stroke="#edf1f6" stroke-dasharray="3 4"/><text x="${padL - 8}" y="${y + 3}" font-size="9.5" fill="#b6bcc8" text-anchor="end">${lab}</text>`;
  }).join("");
}
function svgAreaTrend(months) {
  const uid = _duid();
  const W = 960, H = 280, padL = 52, padB = 30, padT = 16;
  const maxV = Math.max(1, ...months.map(m => Math.max(m.gross, m.net))) * 1.08;
  const iw = (W - padL - 16) / months.length;
  const px = i => +(padL + i * iw + iw / 2).toFixed(1);
  const py = v => +(H - padB - (v / maxV) * (H - padB - padT)).toFixed(1);
  const gPts = months.map((m, i) => [px(i), py(m.gross)]);
  const nPts = months.map((m, i) => [px(i), py(m.net)]);
  const gLine = smoothPath(gPts), nLine = smoothPath(nPts);
  const gArea = gLine + ` L${px(months.length - 1)},${H - padB} L${px(0)},${H - padB} Z`;
  const dots = months.map((m, i) =>
    `<circle cx="${px(i)}" cy="${py(m.gross)}" r="3.2" fill="#fff" stroke="#4f7cff" stroke-width="2"><title>${m.month}月 应发 ${money(m.gross)}</title></circle>` +
    `<circle cx="${px(i)}" cy="${py(m.net)}" r="3.2" fill="#fff" stroke="#22c48a" stroke-width="2"><title>${m.month}月 实发 ${money(m.net)}</title></circle>`).join("");
  const labels = months.map((m, i) => `<text x="${px(i)}" y="${H - 10}" font-size="10" fill="#9aa2af" text-anchor="middle">${m.month}</text>`).join("");
  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%">
    <defs>
      <linearGradient id="${uid}a" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4f7cff" stop-opacity=".25"/><stop offset="1" stop-color="#4f7cff" stop-opacity="0"/></linearGradient>
      <linearGradient id="${uid}b" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#4f7cff"/><stop offset="1" stop-color="#7ea2ff"/></linearGradient>
      <linearGradient id="${uid}c" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#22c48a"/><stop offset="1" stop-color="#5ee0b0"/></linearGradient>
    </defs>
    ${dashAxis(W, H, padL, padB, padT, maxV)}
    <path d="${gArea}" fill="url(#${uid}a)"/>
    <path d="${gLine}" fill="none" stroke="url(#${uid}b)" stroke-width="2.6" stroke-linecap="round"/>
    <path d="${nLine}" fill="none" stroke="url(#${uid}c)" stroke-width="2.2" stroke-linecap="round"/>
    ${dots}${labels}
  </svg>
  <div class="hint" style="margin-top:2px"><i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#4f7cff;margin-right:4px"></i>应发　<i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#22c48a;margin-right:4px"></i>实发</div>`;
}
function svgBudgetBars(months, budgets) {
  const uid = _duid();
  const W = 960, H = 260, padL = 52, padB = 30, padT = 14;
  const data = months.map((m, i) => ({ m: m.month, g: m.gross, b: (budgets[i] || {}).budget || 0 }));
  const maxV = Math.max(1, ...data.map(x => Math.max(x.g, x.b))) * 1.08;
  const bw = (W - padL - 16) / data.length;
  const barW = Math.min(24, bw * 0.4);
  let bars = "";
  data.forEach((x, i) => {
    const cx = padL + i * bw + bw / 2, x0 = cx - barW / 2;
    const hB = (x.b / maxV) * (H - padB - padT), hG = (x.g / maxV) * (H - padB - padT);
    bars += `<rect x="${x0}" y="${H - padB - hB}" width="${barW}" height="${Math.max(hB, 2)}" rx="${Math.min(6, barW / 2)}" fill="#e6ebf4"><title>${x.m}月 预算 ${money(x.b)}</title></rect>`;
    if (hG > 0) bars += `<rect x="${x0}" y="${H - padB - hG}" width="${barW}" height="${Math.max(hG, 2)}" rx="${Math.min(6, barW / 2)}" fill="url(#${uid}g)"><title>${x.m}月 应发 ${money(x.g)}</title></rect>`;
    bars += `<text x="${cx}" y="${H - 10}" font-size="10" fill="#9aa2af" text-anchor="middle">${x.m}</text>`;
  });
  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%">
    <defs><linearGradient id="${uid}g" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#2f6bff"/><stop offset="1" stop-color="#6fa0ff"/></linearGradient></defs>
    ${dashAxis(W, H, padL, padB, padT, maxV)}${bars}</svg>
  <div class="hint" style="margin-top:2px"><i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#e6ebf4;margin-right:4px"></i>月度预算（全项目合计）　<i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#4f7cff;margin-right:4px"></i>实际应发</div>`;
}
function svgGauge(rate, title, lines) {
  const uid = _duid();
  const C = 2 * Math.PI * 62;
  const seg = Math.max(0.005, Math.min(rate, 1)) * C;
  return `<div style="display:flex;align-items:center;gap:22px">
    <svg viewBox="0 0 160 160" style="width:150px;flex-shrink:0">
      <defs><linearGradient id="${uid}r" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4f7cff"/><stop offset="1" stop-color="#22c48a"/></linearGradient></defs>
      <circle cx="80" cy="80" r="62" fill="none" stroke="#edf1f7" stroke-width="13"/>
      <circle cx="80" cy="80" r="62" fill="none" stroke="${rate > 1 ? "#ef4444" : `url(#${uid}r)`}" stroke-width="13" stroke-linecap="round"
        stroke-dasharray="${seg.toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 80 80)"/>
      <text x="80" y="77" font-size="23" font-weight="700" fill="#1f2937" text-anchor="middle">${pct(rate)}</text>
      <text x="80" y="97" font-size="10.5" fill="#9aa2af" text-anchor="middle">${title}</text>
    </svg>
    <div style="font-size:12.5px;line-height:2.1;color:#595959">${lines}</div></div>`;
}
function svgDonutStatus(dist) {
  const entries = Object.entries(dist).filter(e => e[1] > 0);
  if (!entries.length) return `<div class="hint">暂无数据</div>`;
  const total = entries.reduce((s, e) => s + e[1], 0);
  const C = 2 * Math.PI * 54;
  let acc = 0, segs = "";
  entries.forEach((e, i) => {
    const frac = e[1] / total;
    const gap = Math.min(0.015, frac / 4);
    segs += `<circle cx="70" cy="70" r="54" fill="none" stroke="${DASH_COLORS[i % 6]}" stroke-width="17"
      stroke-dasharray="${Math.max(0.5, (frac - gap) * C).toFixed(1)} ${C.toFixed(1)}" stroke-dashoffset="${(-acc * C).toFixed(1)}" transform="rotate(-90 70 70)"><title>${e[0]} ${e[1]}人</title></circle>`;
    acc += frac;
  });
  const legend = entries.map((e, i) => `<div><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${DASH_COLORS[i % 6]};margin-right:6px"></i>${e[0]}　<b>${e[1]}</b> 人（${((e[1] / total) * 100).toFixed(0)}%）</div>`).join("");
  return `<div style="display:flex;align-items:center;gap:18px">
    <svg viewBox="0 0 140 140" style="width:118px;flex-shrink:0">${segs}
      <text x="70" y="67" text-anchor="middle" font-size="22" font-weight="700" fill="#1f2937">${total}</text>
      <text x="70" y="85" text-anchor="middle" font-size="10" fill="#9aa2af">在职总人数</text></svg>
    <div style="font-size:12px;line-height:2.1;color:#595959">${legend}</div></div>`;
}
function projCostTable(items) {
  if (!items || !items.length) return `<div class="hint">当月暂无核算数据</div>`;
  let html = `<table class="tb" style="font-size:12px"><thead><tr><th>项目名称</th><th>人数</th><th>人力成本</th><th>人均成本</th></tr></thead><tbody>`;
  let tc = 0, tg = 0;
  items.forEach(it => {
    tc += it.headcount; tg += it.cost;
    html += `<tr><td>${esc(it.project)}</td><td style="text-align:center">${it.headcount}</td>
      <td style="text-align:right">${money(it.cost)}</td><td style="text-align:right">${money(it.avg_cost)}</td></tr>`;
  });
  html += `<tr><td><b>合计</b></td><td style="text-align:center"><b>${tc}</b></td>
    <td style="text-align:right"><b>${money(tg)}</b></td><td style="text-align:right"><b>${money(tc ? tg / tc : 0)}</b></td></tr>`;
  return html + `</tbody></table>`;
}
function svgCostBars(items) {
  if (!items || !items.length) return `<div class="hint">当月暂无核算数据</div>`;
  const maxV = Math.max(1, ...items.map(i => i.cost));
  return items.slice(0, 12).map(it => {
    const w = Math.max(2, (it.cost / maxV) * 100);
    return `<div style="margin-bottom:11px">
      <div style="display:flex;justify-content:space-between;font-size:12px;color:#595959;margin-bottom:3px">
        <span>${esc(it.project)}　<span style="color:#9aa2af">${it.headcount}人 · 人均 ${money(it.avg_cost)}</span></span>
        <b style="color:#1f2937">${money(it.cost)}</b></div>
      <div style="background:#f1f5f9;border-radius:6px;height:12px;overflow:hidden">
        <div style="width:${w}%;height:12px;border-radius:6px;background:linear-gradient(90deg,#22c48a,#7ee0bb)"></div></div></div>`;
  }).join("");
}
function svgBulletYear(items) {
  const list = items.filter(i => i.ytd_gross > 0 || i.annual_budget > 0);
  if (!list.length) return `<div class="hint">暂无年度数据</div>`;
  return list.map(it => {
    const base = Math.max(it.ytd_gross, it.annual_budget, 1);
    const wY = Math.max(1.5, (it.ytd_gross / base) * 100), wB = (it.annual_budget / base) * 100;
    const over = it.annual_budget > 0 && it.ytd_gross > it.annual_budget;
    return `<div style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;font-size:12px;color:#595959;margin-bottom:3px">
        <span>${esc(it.project)}　<span style="color:#9aa2af">${it.headcount}人</span></span>
        <span>执行率 <b style="color:${over ? "#dc2626" : "#16a34a"}">${pct(it.annual_rate)}</b></span></div>
      <div style="position:relative;background:#f1f5f9;border-radius:6px;height:12px">
        <div style="position:absolute;width:${wB}%;height:12px;background:#e2e8f0;border-radius:6px"></div>
        <div style="position:absolute;width:${wY}%;height:12px;background:linear-gradient(90deg,${over ? "#ef4444,#f87171" : "#4f7cff,#7ea2ff"});border-radius:6px"></div>
        ${wB > 0 ? `<div style="position:absolute;left:${wB}%;top:-3px;width:2px;height:18px;background:#94a3b8"></div>` : ""}
      </div>
      <div class="hint" style="margin:3px 0 0">累计应发 ${money(it.ytd_gross)} / 年预算 ${money(it.annual_budget)}</div></div>`;
  }).join("");
}
function svgStaffTrend(trend) {
  if (!trend || !trend.length) return `<div class="hint">暂无人员数据</div>`;
  const uid = _duid();
  const W = 960, H = 150, padL = 46, padB = 20, padT = 10;
  const maxRaw = Math.max(1, ...trend.map(t => Math.max(t.active, t.joined, t.left)));
  // 人数类整数刻度（1/2/5×10^n 取整），避免 0k 这类金额刻度
  const raw = maxRaw / 4;
  const mag = Math.pow(10, Math.floor(Math.log10(raw)));
  const norm = raw / mag;
  const step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag;
  const maxV = Math.ceil(maxRaw / step) * step;
  const iw = (W - padL - 14) / trend.length;
  const px = i => +(padL + i * iw + iw / 2).toFixed(1);
  const py = v => +(H - padB - (v / maxV) * (H - padB - padT)).toFixed(1);
  let grid = "";
  for (let v = 0; v <= maxV; v += step) {
    const y = py(v);
    grid += `<line x1="${padL}" y1="${y}" x2="${W - 8}" y2="${y}" stroke="#edf1f6" stroke-dasharray="3 4"/>
      <text x="${padL - 7}" y="${y + 3}" font-size="9.5" fill="#b6bcc8" text-anchor="end">${v}</text>`;
  }
  const NAME = { active: "在职", joined: "入职", left: "离职" };
  const COL = { active: "#16a34a", joined: "#4f7cff", left: "#ef4444" };
  const mk = key => {
    const pts = trend.map((t, i) => [px(i), py(t[key])]);
    const line = smoothPath(pts);
    const dots = trend.map((t, i) =>
      `<circle cx="${px(i)}" cy="${py(t[key])}" r="3" fill="#fff" stroke="${COL[key]}" stroke-width="2"><title>${t.ym} ${NAME[key]} ${t[key]}人</title></circle>`).join("");
    return `<path d="${line}" fill="none" stroke="${COL[key]}" stroke-width="2.4" stroke-linecap="round"/>${dots}`;
  };
  const area = trend.map((t, i) => `${i ? "L" : "M"}${px(i)},${py(t.active)}`).join(" ") +
    ` L${px(trend.length - 1)},${H - padB} L${px(0)},${H - padB} Z`;
  const labels = trend.map((t, i) =>
    `<text x="${px(i)}" y="${H - 8}" font-size="10" fill="#9aa2af" text-anchor="middle">${t.month}月</text>`).join("");
  const leg = key => `<i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:${COL[key]};margin-right:4px"></i>${NAME[key]}`;
  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;max-width:680px;display:block;margin:0 auto">
    <defs><linearGradient id="${uid}a" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#22c48a" stop-opacity=".18"/><stop offset="1" stop-color="#22c48a" stop-opacity="0"/></linearGradient></defs>
    ${grid}
    <path d="${area}" fill="url(#${uid}a)"/>
    ${mk("active")}${mk("joined")}${mk("left")}${labels}
  </svg>
  <div class="hint" style="margin-top:2px">${leg("active")}　${leg("joined")}　${leg("left")}　口径：在职=当月末在职人数（按当前在职档案回溯入职/离职日期）；入职/离职=当月入职日期/离职日期落在当月的有效档案数。</div>`;
}
function statCard(k, v, color) {
  return `<div class="stat" style="border-top:3px solid ${color}"><div class="k">${k}</div><div class="v">${v}</div></div>`;
}
async function pageDashboard() {
  const c = document.getElementById("content");
  const year = state.month.slice(0, 4);
  c.innerHTML = `<div class="card"><h3>公司薪资数据驾驶舱（${year}年）</h3>
    <div class="row">${monthInput()} <button class="btn primary" onclick="pageDashboard()">刷新</button></div>
    <div id="dashArea" style="margin-top:12px">加载中...</div></div>`;
  try {
    const d = await api(`/api/dashboard?year=${year}&ym=${state.month}`);
    const momTxt = d.prev_gross > 0 ? `${d.mom >= 0 ? "↑" : "↓"} ${pct(Math.abs(d.mom))}` : "—";
    const an = d.attendance_anomaly || {};
    const cols = ["#4f7cff", "#22c48a", "#f5a623", "#8b5cf6", "#06b6d4", "#ef4444"];
    const stats = [
      ["在职人员", d.active_staff], ["当月发放人数", d.month_headcount],
      ["当月应发", money(d.month_gross)], ["当月实发", money(d.month_net)],
      ["人均应发", money(d.avg_gross)], ["应发环比上月", momTxt],
      ["本年累计应发", money(d.ytd_gross)], ["本年累计实发", money(d.ytd_net)],
      ["本年累计个税", money(d.ytd_tax)], ["本年累计五险一金", money(d.ytd_social)],
      ["年度总预算", money(d.annual_budget)], ["年度预算执行率", pct(d.annual_rate)]];
    const chip = (label, v, bg, fg) => `<div style="flex:1;min-width:88px;background:${bg};border-radius:8px;padding:10px 8px;text-align:center">
      <div style="font-size:19px;font-weight:700;color:${fg};font-variant-numeric:tabular-nums">${v}</div>
      <div style="font-size:11px;color:${fg};opacity:.8;margin-top:2px">${label}</div></div>`;
    let html = `<div class="stat-cards" style="grid-template-columns:repeat(6,1fr)">` +
      stats.map((s, i) => statCard(s[0], s[1], cols[i % 6])).join("") + `</div>
    <div class="card" style="margin:0 0 16px"><h3>人员趋势分析（近6个月 在职 / 入职 / 离职人数）</h3>${svgStaffTrend(d.staff_trend || [])}</div>
    <div style="display:grid;grid-template-columns:5fr 3fr;gap:16px">
      <div class="card" style="margin:0"><h3>月度应发 / 实发趋势</h3>${svgAreaTrend(d.months)}</div>
      <div class="card" style="margin:0"><h3>年度预算执行</h3>
        ${svgGauge(d.annual_rate, "年度预算执行率", `
          <div>本年累计应发　<b style="color:#1f2937">${money(d.ytd_gross)}</b></div>
          <div>本年累计实发　<b style="color:#1f2937">${money(d.ytd_net)}</b></div>
          <div>年度总预算　<b style="color:#1f2937">${money(d.annual_budget)}</b></div>`)}
        <h3 style="margin-top:16px">人员状态分布</h3>${svgDonutStatus(d.status_dist)}
      </div>
    </div>
    <div style="display:grid;grid-template-columns:5fr 3fr;gap:16px;margin-top:16px">
      <div class="card" style="margin:0"><h3>月度预算执行对比</h3>${svgBudgetBars(d.months, d.month_budgets)}</div>
      <div class="card" style="margin:0"><h3>${state.month} 项目人力成本分析</h3>${projCostTable(d.proj_cost)}
        <h3 style="margin-top:16px">${state.month} 考勤异常概览</h3>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          ${chip("涉及人数", an.people || 0, "#f1f5f9", "#475569")}
          ${chip("迟到(次)", an.late || 0, "#fff7ed", "#c2410c")}
          ${chip("早退(次)", an.early || 0, "#fff7ed", "#c2410c")}
          ${chip("缺卡(次)", an.miss || 0, "#eff6ff", "#1d4ed8")}
          ${chip("旷工(天)", an.absent || 0, "#fef2f2", "#b91c1c")}
          ${chip("事假(天)", an.personal || 0, "#f5f3ff", "#6d28d9")}
          ${chip("病假(天)", an.sick || 0, "#f5f3ff", "#6d28d9")}
        </div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
      <div class="card" style="margin:0"><h3>${state.month} 各项目人力成本分布</h3>${svgCostBars(d.proj_cost)}</div>
      <div class="card" style="margin:0"><h3>各项目年度累计应发 vs 年度预算</h3>${svgBulletYear(d.proj_year || [])}</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
      <div class="card" style="margin:0"><h3>在职部门人数分布</h3>${svgDeptHeadcount(d.dept_headcount || {})}</div>
      <div class="card" style="margin:0"><h3>${state.month} 部门人工成本</h3>${deptCostTable(d.dept_cost || [])}</div>
    </div>`;
    document.getElementById("dashArea").innerHTML = html;
  } catch (e) { document.getElementById("dashArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function svgDeptHeadcount(map) {
  const entries = Object.entries(map || {}).sort((a, b) => b[1] - a[1]);
  if (!entries.length) return `<span class="hint">暂无数据（需先在组织架构中为人员分配部门）</span>`;
  const max = Math.max(...entries.map(e => e[1]));
  const barW = Math.min(240, Math.max(80, entries.length ? 900 / entries.length - 8 : 80));
  return `<div style="display:flex;flex-direction:column;gap:6px">` + entries.map(([name, cnt]) => `
    <div style="display:flex;align-items:center;gap:8px">
      <span style="width:150px;text-align:right;font-size:12px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${esc(name)}">${esc(name)}</span>
      <div style="flex:1;background:#f1f5f9;border-radius:4px;height:18px;overflow:hidden"><div style="width:${max ? Math.round(cnt / max * 100) : 0}%;background:linear-gradient(90deg,#4f7cff,#22c48a);height:100%;border-radius:4px"></div></div>
      <span style="width:34px;font-size:12.5px;font-weight:600;font-variant-numeric:tabular-nums">${cnt}</span>
    </div>`).join("") + `</div>`;
}
function deptCostTable(list) {
  if (!list.length) return `<span class="hint">暂无核算数据</span>`;
  return `<div class="table-wrap" style="max-height:360px"><table class="tb"><thead><tr><th>部门</th><th>人数</th><th>应发</th><th>实发</th></tr></thead><tbody>` +
    list.map(d => `<tr><td>${esc(d.dept)}</td><td class="num">${d.headcount}</td><td class="num">${money(d.gross)}</td><td class="num">${money(d.net)}</td></tr>`).join("") +
    `</tbody></table></div>`;
}
/* ---------------- 项目档案 ---------------- */
async function refreshProjects() {
  try {
    const data = await api("/api/init");
    state.projects = data.projects;
    state.allProjects = data.all_projects || data.projects.map(n => ({ name: n, status: "启用" }));
  } catch (e) { /* 忽略：保留旧列表 */ }
}
/* ---------------- 组织架构（替换项目档案入口） ---------------- */
async function pageOrg() {
  const c = document.getElementById("content");
  c.innerHTML = `
  <div class="org-page">
    <div class="org-topbar">
      <span class="org-topbar-title">${svgIco("network", 18, "#165dff", 2)} 组织架构 <span style="color:#86909c;font-weight:400;font-size:13px">/ 部门管理</span></span>
      <div style="margin-left:auto;display:flex;gap:10px">
        <button class="btn primary" onclick="orgChartView()">${svgIco("network", 14, "#fff", 2)} 组织架构图</button>
        <button class="btn" onclick="orgAddChild(null)">${svgIco("plus", 14, "#1f2329", 2.2)} 添加部门</button>
      </div>
    </div>
    <div style="display:flex;gap:14px;align-items:flex-start;margin-top:14px">
      <div class="org-side">
        <div class="org-search"><div class="org-search-box">${svgIco("search", 14, "#86909c", 2)}<input placeholder="搜索部门" oninput="orgSearch(this.value)"></div></div>
        <div id="orgTreePanel" class="org-tree-list"></div>
      </div>
      <div class="org-main" id="orgDetailPanel"><div class="msg info">← 在左侧选择节点查看详情；也可以在节点上执行「＋子节点 / 编辑 / 停用 / 删除」。</div></div>
    </div>
    <div id="orgMigrateMsg" style="margin-top:10px"></div>
    <div style="margin-top:10px"><button class="btn success" onclick="dingtalkSyncNow()">🔄 立即从钉钉同步</button><span class="hint" style="margin:0 0 0 10px">组织架构由钉钉自动同步，请勿手动修改。</span></div>
  </div>`;
  orgLoad(true);
}
async function orgLoad(force) {
  try {
    await loadOrgTree(force);
    const data = await api("/api/org/tree");
    ORG_TREE = data.tree;
    // 组织树变化后同步刷新项目列表（人员档案/考勤等项目下拉以 payroll_projects 为数据源）
    if (Array.isArray(data.projects)) {
      state.projects = data.projects.filter(p => p.status === "启用").map(p => p.name);
      state.allProjects = data.projects.map(p => ({ name: p.name, status: p.status }));
    }
    orgRenderTree();
  } catch (e) { const el = document.getElementById("orgTreePanel"); if (el) el.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function orgSearch(kw) {
  const el = document.getElementById("orgTreePanel");
  if (!el || !ORG_TREE) return;
  kw = (kw || "").trim();
  if (!kw) { orgRenderTree(); return; }
  const flat = orgFlat(ORG_TREE);
  const hits = flat.filter(n => n.name.includes(kw) || (n.path || "").includes(kw));
  if (!hits.length) { el.innerHTML = `<div class="msg info" style="font-size:12px">未找到「${esc(kw)}」相关部门</div>`; return; }
  el.innerHTML = hits.map(n => `<div class="tree-row" data-id="${n.id}" style="padding-left:10px" onclick="orgSelNode(${n.id})">
    <span class="tree-ico">${orgNodeIco(n.type, 15)}</span>
    <span class="tree-nm">${esc(n.name)}</span>
    <span class="tree-cnt">${n.count_in || 0}</span></div>`).join("");
}
function orgRenderTree() {
  const el = document.getElementById("orgTreePanel");
  if (!el) return;
  if (!ORG_TREE || !ORG_TREE.length) {
    el.innerHTML = `<div class="msg info">尚未初始化组织架构。请点击下方「老数据自动迁移」一键生成默认树（公司→项目→客服/工程/秩序/环境/总经理办公室 5 部门），人员将按岗位关键词自动归部门。</div>`;
    return;
  }
  el.innerHTML = orgTreeNodesHtml(ORG_TREE, 0);
}
function orgTreeNodesHtml(nodes, depth) {
  if (!nodes || !nodes.length) return "";
  let html = "";
  for (const n of nodes) {
    const hasKids = n.children && n.children.length;
    const active = ORG_SEL === n.id;
    html += `<div>
      <div class="tree-row ${active ? "active" : ""}" data-id="${n.id}" style="padding-left:${depth * 18 + 6}px" onclick="orgSelNode(${n.id})">
        <span class="tree-arrow ${hasKids ? "open" : ""}" style="${hasKids ? "" : "visibility:hidden"}">▶</span>
        <span class="tree-ico">${orgNodeIco(n.type, 15)}</span>
        <span class="tree-nm">${esc(n.name)}${n.enabled ? "" : ' <span style="font-size:10px;color:#f53f3f">停用</span>'}</span>
        <span class="tree-cnt">${n.count_in || 0}</span>
      </div>
      ${hasKids ? `<div>` + orgTreeNodesHtml(n.children, depth + 1) + `</div>` : ""}
    </div>`;
  }
  return html;
}
function orgSelNode(id) {
  ORG_SEL = id;
  document.querySelectorAll(".tree-row").forEach(x => x.classList.toggle("active", Number(x.dataset.id) === id));
  orgRenderDetail(id);
}
async function orgRenderDetail(id) {
  const n = orgNode(id);
  const panel = document.getElementById("orgDetailPanel");
  if (!n) { panel.innerHTML = `<div class="msg err">节点不存在</div>`; return; }
  const typeName = { company: "公司", region: "区域", project: "项目", department: "部门", team: "班组" }[n.type] || n.type;
  const typeTagCls = { company: "org-tag-company", project: "org-tag-project", department: "org-tag-dept" }[n.type] || "org-tag-dept";
  const sub = (n.children || []).filter(c => !c.hidden);
  let html = `
  <div class="org-card">
    <div class="org-card-head">
      <span class="org-node-big-ico" style="background:${ORG_BG[n.type] || "#e8f3ff"}">${orgNodeIco(n.type, 22)}</span>
      <div>
        <div style="display:flex;align-items:center;gap:10px">
          <h2 style="font-size:18px;font-weight:600">${esc(n.name)}</h2>
          <span class="org-tag ${typeTagCls}">${typeName}</span>
          ${n.enabled ? '<span class="org-tag" style="background:#eafff1;color:#00b42a">启用</span>' : '<span class="org-tag" style="background:#ffece8;color:#f53f3f">停用</span>'}
        </div>
        <div style="font-size:12px;color:#86909c;margin-top:4px">路径：${esc(n.path || "—")}</div>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px">
        ${n.type === "project" ? `<button class="btn sm primary" onclick="orgAddChild(${id})">${svgIco("plus", 12, "#fff", 2.2)} 添加部门</button>` : ""}
        <button class="btn sm" onclick="orgEditNode(${id})">${svgIco("pencil", 11, "#1f2329", 2)} 编辑</button>
        ${n.type !== "company" ? `<button class="btn sm ${n.enabled ? "danger" : ""}" onclick="orgToggleStatus(${id})">${n.enabled ? "停用" : "启用"}</button>` : ""}
      </div>
    </div>
    <div class="org-meta">
      <span>上级：<b>${orgParentName(n) || "—"}</b></span>
      <span>在职人数：<b>${n.count_in || 0}</b></span>
      <span>离职人数：<b>${n.count_out || 0}</b></span>
      <span>子部门：<b>${sub.length}</b></span>
      ${n.type === "project" ? `<span>状态：<b style="color:#00b42a">启用</b></span>` : ""}
    </div>
  </div>
  <div class="org-kpis">
    <div class="org-kpi"><div class="org-kpi-lbl">在职人数</div><div class="org-kpi-num">${n.count_in || 0}<small>人</small></div></div>
    <div class="org-kpi"><div class="org-kpi-lbl">离职（历史）</div><div class="org-kpi-num">${n.count_out || 0}<small>人</small></div></div>
    <div class="org-kpi"><div class="org-kpi-lbl">子部门</div><div class="org-kpi-num">${sub.length}<small>个</small></div></div>
    <div class="org-kpi"><div class="org-kpi-lbl">节点编码</div><div class="org-kpi-num" style="font-size:17px;line-height:34px">${esc(n.code || "—")}</div></div>
  </div>`;
  if (n.type === "project") {
    html += `<div class="org-card" style="margin-top:12px"><div class="org-panel-head">项目档案字段</div>
      <div class="org-meta" style="margin-top:8px">
        <span>负责人：<b>${esc(n.contact || "—")}</b></span>
        <span>电话：<b>${esc(n.phone || "—")}</b></span>
        <span>地址：<b>${esc(n.address || "—")}</b></span>
        <span>别名：<b>${esc((n.aliases || []).join("、") || "—")}</b></span>
      </div>
      <div style="margin-top:8px"><button class="btn sm" onclick="projectEditFromOrg(${id})">编辑项目档案字段</button></div></div>`;
  }
  if (sub.length) {
    html += `<div class="org-card" style="margin-top:12px"><div class="org-panel-head">下级部门 <span style="font-weight:400;color:#86909c;font-size:12px">（点击进入）</span></div>` +
      sub.map(s => `<div class="org-subdept" onclick="orgSelNode(${s.id})" style="cursor:pointer">
        <span class="tree-ico" style="margin-right:8px">${orgNodeIco(s.type, 15)}</span>
        <span style="font-weight:500;font-size:13.5px">${esc(s.name)}</span>
        <span style="color:#86909c;font-size:12px;margin-left:8px">${s.count_in || 0} 人</span>
        <span style="margin-left:auto;display:flex;gap:8px" onclick="event.stopPropagation()">
          <span class="org-link danger" onclick="orgDeleteNode(${s.id})">删除</span>
          <span class="org-link" onclick="orgEditNode(${s.id})">编辑</span>
        </span>
      </div>`).join("") + `</div>`;
  }
  html += `<div class="org-card" style="margin-top:12px">
    <div class="org-panel-head">部门成员 <span style="font-weight:400;color:#86909c;font-size:12px">（含本节点及子孙部门，来自人事档案）</span></div>
    <div id="orgStaffList_${n.id}" style="padding:10px 14px"><span class="hint">加载中...</span></div>
  </div>`;
  panel.innerHTML = html;
  try {
    const d = await api(`/api/org/staff?org_id=${id}`);
    const listEl = document.getElementById("orgStaffList_" + n.id);
    if (!listEl) return;
    if (!d.staff.length) { listEl.innerHTML = `<div style="color:#86909c;font-size:13px;padding:8px 0">暂无在职人员（成员需在【人员档案】中维护，此处仅展示）</div>`; return; }
    listEl.innerHTML = `<div class="table-wrap"><table class="tb"><thead><tr><th>姓名</th><th>职位</th><th>员工状态</th><th>项目</th><th>操作</th></tr></thead><tbody>` +
      d.staff.map(s => `<tr><td>${esc(s.name)}</td><td>${esc(s.position || "-")}</td>
        <td><span class="tag ${s.deleted ? "gray" : "green"}">${s.deleted ? "离职" : "在职"}</span></td>
        <td>${esc(s.project || "-")}</td>
        <td><button class="btn sm" onclick="orgMoveStaffTo(${s.id})">调动</button></td></tr>`).join("") + `</tbody></table></div>`;
  } catch (e) { /* 忽略 */ }
}
function orgParentName(n) {
  if (!n.parent_id) return null;
  const p = orgNode(n.parent_id);
  return p ? p.name : null;
}
async function orgRunMigrate() {
  if (!confirm("执行「老数据自动迁移」：将根据现有项目/人员/账号生成组织树（公司→项目→5标准部门），人员按岗位关键词智能归部门，迁移前自动备份。已初始化过会提示。继续？")) return;
  const msg = document.getElementById("orgMigrateMsg");
  msg.innerHTML = `<div class="msg info">正在迁移...</div>`;
  try {
    const r = await api("/api/org/migrate", { body: {} });
    const rep = r.report;
    msg.innerHTML = `<div class="msg ok">迁移完成：公司 ${rep.company} 个、项目 ${rep.projects} 个、部门 ${rep.departments} 个；人员匹配部门 ${rep.staff_matched} 人、待分配 ${rep.staff_pending} 人；账号绑定 ${rep.accounts_bound} 个、待关联 ${rep.accounts_pending} 个。迁移前已自动备份。</div>`;
    await orgLoad(true);
    if (rep.accounts_pending) toast("有老账号待关联人员，请到「权限管理-账号列表」编辑绑定", false);
  } catch (e) { msg.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
async function orgAddChild(parentId) {
  const baseId = parentId || ORG_SEL;
  const parent = baseId ? orgNode(baseId) : (ORG_TREE && ORG_TREE.length ? ORG_TREE[0] : null);
  const parentName = parent ? parent.name : "—";
  const parentType = parent ? parent.type : "company";
  let typeOptions = "";
  if (parentType === "company") typeOptions = `<option value="project">项目</option>`;
  else if (parentType === "project") typeOptions = `<option value="department">部门</option>`;
  else typeOptions = `<option value="department">部门</option>`;
  modal(`<h3>在「${esc(parentName)}」下新增${parentType === "company" ? "项目" : "部门"}</h3>
  <div class="form-grid">
    <label>节点名称<input type="text" id="og_name" placeholder="${parentType === "company" ? "如：临沂万城花开" : "如：客服一部"}"></label>
    <label>节点类型<select id="og_type">${typeOptions}</select></label>
    <label>编码（可选）<input type="text" id="og_code"></label>
  </div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="orgSaveChild(${baseId})">创建</button></div>`);
}
async function orgSaveChild(parentId) {
  const name = document.getElementById("og_name").value.trim();
  if (!name) return toast("节点名称必填", false);
  try {
    await api("/api/org/node", { body: { parent_id: parentId, name, type: document.getElementById("og_type").value, code: document.getElementById("og_code").value } });
    closeModal(); toast("已创建");
    const cur = ORG_SEL || parentId;
    await orgLoad(true);
    if (cur) orgSelNode(cur);
  } catch (e) { alert(e.message); }
}
async function orgEditNode(id) {
  const n = orgNode(id);
  let extra = "";
  if (n.type === "project") {
    extra = `<label>负责人<input type="text" id="og_contact" value="${esc(n.contact || "")}"></label>
      <label>联系电话<input type="text" id="og_phone" value="${esc(n.phone || "")}"></label>
      <label>地址<input type="text" id="og_addr" value="${esc(n.address || "")}"></label>
      <label>别名（逗号分隔）<input type="text" id="og_alias" value="${esc((n.aliases || []).join(","))}"></label>`;
  }
  modal(`<h3>编辑节点 — ${esc(n.name)}</h3>
  <div class="form-grid">
    <label>节点名称<input type="text" id="og_name" value="${esc(n.name)}"></label>
    <label>编码<input type="text" id="og_code" value="${esc(n.code || "")}"></label>
    <label>备注<input type="text" id="og_note" value="${esc(n.note || "")}"></label>
    ${n.type !== "company" ? `<label>在组织架构中隐藏<select id="og_hidden"><option value="0">否</option><option value="1" ${n.hidden ? "selected" : ""}>是</option></select>
    <div class="hint" style="font-size:11px;color:#6b7280">隐藏后：组织架构图与人事档案「选部门」中均不显示该节点及其下级</div></label>` : ""}
    ${extra}
  </div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="orgSaveNode(${id})">保存</button></div>`);
}
async function orgSaveNode(id) {
  const n = orgNode(id);
  const body = { id, name: document.getElementById("og_name").value.trim(), code: document.getElementById("og_code").value, note: document.getElementById("og_note").value };
  const oh = document.getElementById("og_hidden");
  if (oh) body.hidden = oh.value === "1";
  if (n.type === "project") {
    body.contact = document.getElementById("og_contact").value;
    body.phone = document.getElementById("og_phone").value;
    body.address = document.getElementById("og_addr").value;
    body.aliases = document.getElementById("og_alias").value.split(/[,，]/).map(s => s.trim()).filter(Boolean);
  }
  try {
    await api("/api/org/node/update", { body });
    closeModal(); toast("已保存");
    await orgLoad(true); orgSelNode(id);
  } catch (e) { alert(e.message); }
}
async function orgToggleStatus(id) {
  const n = orgNode(id);
  const on = !n.enabled;
  try {
    await api("/api/org/node/status", { body: { id, enabled: on } });
    toast(on ? "已启用" : "已停用（不再接收新人员）");
    await orgLoad(true); orgSelNode(id);
  } catch (e) { alert(e.message); }
}
async function orgDeleteNode(id) {
  const n = orgNode(id);
  if (!confirm(`确认删除「${esc(n.name)}」？有在职人员或子节点时将被拒绝（只能停用）。`)) return;
  try {
    await api("/api/org/node/delete", { body: { id } });
    toast("已删除");
    ORG_SEL = null;
    await orgLoad(true);
    document.getElementById("orgDetailPanel").innerHTML = `<div class="msg info">节点已删除</div>`;
  } catch (e) { alert(e.message); }
}
async function orgMoveStaffIn(nodeId) {
  const n = orgNode(nodeId);
  let opts = `<option value="">— 选择人员 —</option>`;
  try {
    const d = await api("/api/org/staff-options");
    for (const s of d.staff) {
      opts += `<option value="${s.id}">${esc(s.name)}（${esc(s.project)}${s.dept_path ? "/" + esc(s.dept_path) : ""}${s.position ? "/" + esc(s.position) : ""}）</option>`;
    }
  } catch (e) {}
  modal(`<h3>调入人员到「${esc(n.name)}」</h3>
  <div class="form-grid">
    <label>选择在职人员<select id="og_staff">${opts}</select></label>
    <label>调动原因<input type="text" id="og_reason" placeholder="例：岗位调整"></label>
  </div>
  <div class="hint">调动后将自动带出所属项目并写入调动记录。</div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="orgDoMoveStaff(${nodeId})">确认调入</button></div>`);
}
async function orgDoMoveStaff(nodeId) {
  const staffId = document.getElementById("og_staff").value;
  if (!staffId) return toast("请选择人员", false);
  try {
    const r = await api("/api/org/staff/move", { body: { staff_id: Number(staffId), org_id: nodeId, reason: document.getElementById("og_reason").value } });
    closeModal(); toast("已调入 " + r.dept_path); await orgLoad(true); orgSelNode(nodeId);
  } catch (e) { alert(e.message); }
}
async function orgMoveStaffTo(staffId) {
  const leaves = orgLeaves();
  const opts = `<option value="">— 选择目标部门 —</option>` + leaves.map(n => `<option value="${n.id}">${esc(n.path)}</option>`).join("");
  modal(`<h3>调动人员（ID ${staffId}）到新部门</h3>
  <div class="form-grid">
    <label>目标部门<select id="og_staff">${opts}</select></label>
    <label>调动原因<input type="text" id="og_reason2" placeholder="例：调往客服部"></label>
  </div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="orgDoMoveStaff2(${staffId})">确认调动</button></div>`);
}
async function orgDoMoveStaff2(staffId) {
  const orgId = document.getElementById("og_staff").value;
  if (!orgId) return toast("请选择目标部门", false);
  try {
    const r = await api("/api/org/staff/move", { body: { staff_id: staffId, org_id: Number(orgId), reason: document.getElementById("og_reason2").value } });
    closeModal(); toast("已调动到 " + r.dept_path);
    await orgLoad(true);
  } catch (e) { alert(e.message); }
}
function orgChartView() {
  if (!ORG_TREE || !ORG_TREE.length) { toast("请先初始化组织架构", false); return; }
  orgChartOpen();
}
let ORG_CHART_ZOOM = 1;
function orgChartOpen() {
  const c = document.getElementById("content");
  c.innerHTML = `
  <div class="org-chart-page">
    <div class="org-chart-toolbar">
      <span class="org-chart-title">${svgIco("network", 16, "#165dff", 2)} 组织架构图</span>
      <span style="font-size:12px;color:#86909c">公司 / 项目 / 部门</span>
      <div style="display:flex;gap:16px;font-size:12px;color:#4e5969">
        <span>${svgIco("building", 13, "#165dff", 2)} 公司</span>
        <span>${svgIco("pin", 13, "#7c3aed", 2)} 项目</span>
        <span>${svgIco("folder", 13, "#00b42a", 2)} 部门</span>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        <div style="display:flex;align-items:center;gap:6px">
          <button class="btn sm" onclick="orgChartZoom(-1)">${svgIco("zoomout", 13, "#1f2329", 2)}</button>
          <span style="font-size:12px;color:#86909c;width:44px;text-align:center" id="orgZoomVal">100%</span>
          <button class="btn sm" onclick="orgChartZoom(1)">${svgIco("zoomin", 13, "#1f2329", 2)}</button>
        </div>
        <button class="btn sm" onclick="orgChartZoom(0)">适应窗口</button>
        <button class="btn sm" onclick="window.print()">${svgIco("printer", 13, "#1f2329", 2)} 打印</button>
        <button class="btn sm" onclick="pageOrg()">${svgIco("arrow", 13, "#1f2329", 2)} 返回部门管理</button>
      </div>
    </div>
    <div class="org-chart-stage" id="orgChartStage"><div class="org-chart" id="orgChartBox"></div></div>
  </div>`;
  const walk = (nodes) => {
    let html = `<ul>`;
    for (const n of nodes) {
      html += `<li><div class="org-chart-node ${n.type}" onclick="orgChartSel(${n.id})">
        <div class="org-chart-node-ico">${orgNodeIco(n.type, 24)}</div>
        <div class="org-chart-node-nm">${esc(n.name)}</div>
        <div class="org-chart-node-cnt">在职 ${n.count_in || 0} 人</div>
      </div>`;
      if (n.children && n.children.length) html += walk(n.children);
      html += `</li>`;
    }
    return html + `</ul>`;
  };
  document.getElementById("orgChartBox").innerHTML = walk(ORG_TREE);
  ORG_CHART_ZOOM = 1; orgChartApplyZoom();
}
function orgChartSel(id) {
  ORG_SEL = id;
  pageOrg();
  setTimeout(() => orgSelNode(id), 60);
}
function orgChartZoom(d) {
  if (d === 0) ORG_CHART_ZOOM = 1;
  else ORG_CHART_ZOOM = Math.min(1.8, Math.max(0.5, ORG_CHART_ZOOM + d * 0.1));
  orgChartApplyZoom();
}
function orgChartApplyZoom() {
  const b = document.getElementById("orgChartBox");
  if (b) b.style.transform = `scale(${ORG_CHART_ZOOM})`;
  const v = document.getElementById("orgZoomVal");
  if (v) v.textContent = Math.round(ORG_CHART_ZOOM * 100) + "%";
}
async function projectEditFromOrg(nodeId) {
  const n = orgNode(nodeId);
  const p = { name: n.name, aliases: n.aliases || [], contact: n.contact || "", phone: n.phone || "", address: n.address || "", note: n.note || "" };
  modal(`<h3>编辑项目档案字段 — ${esc(n.name)}</h3>
  <div class="form-grid">
    <label>项目名称<input type="text" id="pj_name" value="${esc(p.name)}"></label>
    <label>别名（逗号分隔，用于导入匹配）<input type="text" id="pj_alias" value="${esc((p.aliases || []).join(","))}"></label>
    <label>负责人<input type="text" id="pj_contact" value="${esc(p.contact)}"></label>
    <label>联系电话<input type="text" id="pj_phone" value="${esc(p.phone)}"></label>
    <label class="full">地址<input type="text" id="pj_addr" value="${esc(p.address)}"></label>
    <label class="full">备注<input type="text" id="pj_note" value="${esc(p.note)}"></label>
  </div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="projectSave(${JSON.stringify(p.name).replace(/'/g, "&#39;")})">保存</button></div>`);
  if (typeof window._orgEditProjectNodeId === "undefined") window._orgEditProjectNodeId = nodeId;
}

async function pageProjects() {
  const c = document.getElementById("content");
  c.innerHTML = `<div class="card"><h3>项目档案</h3>
    <div class="row" style="margin-bottom:10px"><button class="btn success" onclick="projectEdit(null)">＋ 新增项目</button></div>
    <div id="projArea">加载中...</div>
    <div class="hint">停用后的项目不再出现在核算/人员等下拉选项中，但历史数据保留；名下仍有在职人员的项目不能删除，可改用停用。</div></div>`;
  loadProjects();
}
async function loadProjects() {
  try {
    const data = await api("/api/projects");
    let html = `<div class="table-wrap"><table class="tb"><thead><tr><th>项目名称</th><th>别名</th><th>负责人</th><th>联系电话</th><th>地址</th><th>员工状态</th><th>操作</th></tr></thead><tbody>`;
    for (const p of data.projects) {
      const on = (p.status || "启用") === "启用";
      html += `<tr><td>${esc(p.name)}</td><td>${esc((p.aliases || []).join("、"))}</td><td>${esc(p.contact || "")}</td>
        <td>${esc(p.phone || "")}</td><td>${esc(p.address || "")}</td>
        <td><span class="tag ${on ? "green" : "gray"}">${on ? "启用" : "停用"}</span></td>
        <td><button class="btn sm" onclick='projectEdit(${JSON.stringify(p).replace(/'/g, "&#39;")})'>编辑</button>
        <button class="btn sm ${on ? "warn" : "success"}" onclick="projectToggle('${esc(p.name)}',${on ? 0 : 1})">${on ? "停用" : "启用"}</button>
        <button class="btn sm danger" onclick="projectDelete('${esc(p.name)}')">删除</button></td></tr>`;
    }
    document.getElementById("projArea").innerHTML = html + `</tbody></table></div>`;
  } catch (e) { document.getElementById("projArea").innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function projectEdit(p) {
  const isNew = !p;
  const v = p || { name: "", aliases: [], contact: "", phone: "", address: "", note: "" };
  modal(`<h3>${isNew ? "新增项目" : "编辑项目 — " + esc(v.name)}</h3>
  <div class="form-grid">
    <label>项目名称<input type="text" id="pj_name" value="${esc(v.name)}"></label>
    <label>别名（逗号分隔，用于导入匹配）<input type="text" id="pj_alias" value="${esc((v.aliases || []).join(","))}"></label>
    <label>负责人<input type="text" id="pj_contact" value="${esc(v.contact || "")}"></label>
    <label>联系电话<input type="text" id="pj_phone" value="${esc(v.phone || "")}"></label>
    <label class="full">地址<input type="text" id="pj_addr" value="${esc(v.address || "")}"></label>
    <label class="full">备注<input type="text" id="pj_note" value="${esc(v.note || "")}"></label>
  </div>
  <div class="row end" style="margin-top:14px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="projectSave(${isNew ? "null" : `'${esc(v.name)}'`})">保存</button></div>`);
}
async function projectSave(orig) {
  const g = x => document.getElementById(x).value;
  const p = { name: g("pj_name").trim(), aliases: g("pj_alias").split(/[,，]/).map(s => s.trim()).filter(Boolean),
    contact: g("pj_contact"), phone: g("pj_phone"), address: g("pj_addr"), note: g("pj_note") };
  if (orig) p._orig = orig;
  try {
    await api("/api/projects/save", { body: { project: p } });
    await refreshProjects();
    closeModal();
    if (document.getElementById("projArea")) loadProjects();
    if (typeof ORG_TREE !== "undefined" && ORG_TREE) { await loadOrgTree(true); }
    toast("已保存（项目档案/组织树已同步）");
  } catch (e) { alert(e.message); }
}
async function projectToggle(name, on) {
  try { await api("/api/projects/save", { body: { project: { name, _orig: name, status: on ? "启用" : "停用" } } }); await refreshProjects(); loadProjects(); toast(on ? "已启用" : "已停用"); }
  catch (e) { alert(e.message); }
}
async function projectDelete(name) {
  if (!confirm(`确认删除项目「${name}」？名下有人员档案时将无法删除。`)) return;
  try { await api("/api/projects/save", { body: { action: "delete", project: { name } } }); await refreshProjects(); loadProjects(); toast("已删除（其他模块项目列表已同步）"); }
  catch (e) { alert(e.message); }
}

/* ==================== 绩效考核模块 ==================== */
const PERF = { users: [], staff: [], metaLoaded: false, draft: null, detail: null };
const PERF_STATUS_LABEL = { draft:"草稿", confirm:"待确认指标", ongoing:"考核进行中", report:"待数据填报", self:"待发起人自评", approve:"待逐级审批", done:"已归档" };
const PERF_STATUS_COLOR = { draft:"gray", confirm:"orange", ongoing:"blue", report:"orange", self:"orange", approve:"purple", done:"green" };
const CALC_LABEL = { ratio:"比率得分", ladder:"阶梯扣分", count:"数量达标", manual:"人工评分" };
// 算分方式说明（含义 + 计算方法）
const CALC_HELP = {
  ratio:"比率得分：按完成比例折算得分。得分=权重×(实际值÷目标值)，最高不超过该指标权重、最低0分。适合达标类指标（如收缴率、入住率）。示例：权重20、目标100、实际完成80 → 得20×(80/100)=16分。",
  ladder:"阶梯扣分：以目标值为基准，每低一个阶梯单位扣指定分数，低于“记0阈值”整项记0分。得分=权重−(目标值−实际值)÷步长×每步扣分，封顶权重、最低0分。适合量化递减类指标（如投诉次数、差错数）。示例：目标100、每差5扣2分、实际90 → 扣(100−90)/5×2=4分。",
  count:"数量达标：以“应完成数量”为基准，每少完成1个单位扣指定分数。得分=权重−(应完成−实际)×每缺扣分，封顶权重、最低0分。适合件数/次数类指标。示例：应完成6件、每少1件扣1分、实际4件 → 扣2分。",
  manual:"人工评分：系统不自动计算分数，由发起人自评与考核人逐级评分分别打分，最终分=自评分×自评占比＋考核人评分×考核人占比（占比在系统设置中可调）。"
};
const PERF_FLOW = ["draft","confirm","ongoing","report","self","approve","done"];
const PERF_FLOW_NAME = ["制表","确认指标","周期进行","数据填报","发起人自评","逐级审批","归档"];

function perfNum(v){ const n=Number(v); return (v===""||v===null||v===undefined||Number.isNaN(n))?null:n; }
function perfFmt(v){ const n=perfNum(v); return n===null?"-":n.toFixed(2).replace(/\.00$/,"").replace(/(\.\d)0$/,"$1"); }
// 与后端一致的算分逻辑（用于实时预览）
function perfCalcItem(item){
  const w=perfNum(item.weight)||0, v=perfNum(item.actualValue), p=item.calcParams||{};
  if(item.calcType==="manual"||v===null) return null;
  const P=k=>{const x=perfNum(p[k]);return x===null?0:x;};
  if(item.calcType==="ratio"){ const t=P("target"); if(!t)return 0; return Math.round(Math.max(0,Math.min(w,w*v/t))*100)/100; }
  if(item.calcType==="ladder"){
    const target=P("target")||100, unit=P("stepUnit")||1, ded=P("stepDeduct");
    const zt=perfNum(p.zeroThreshold);
    if(zt!==null&&v<zt)return 0;
    const gap=Math.max(0,target-v)/(unit||1);
    return Math.round(Math.max(0,Math.min(w,w-gap*ded))*100)/100;
  }
  if(item.calcType==="count"){ const req=P("required"),ded=P("deductEach"); const lack=Math.max(0,req-v); return Math.round(Math.max(0,Math.min(w,w-lack*ded))*100)/100; }
  return null;
}
function perfWeightSum(d){ let s=0; (d.categories||[]).forEach(c=>(c.items||[]).forEach(it=>{const w=perfNum(it.weight);if(w!==null)s+=w;})); return Math.round(s*100)/100; }

async function perfLoadMeta(force){
  if(PERF.metaLoaded&&!force) return;
  const [u,s]=await Promise.all([api("/api/performance/user_options"), api("/api/staff?cat=在职")]);
  PERF.users=u.users||[];
  PERF.staff=(s.staff||[]).filter(x=>!x.deleted);
  PERF.me=PERF.users.find(x=>String(x.username)===String(state.user.username))||{staffId:null,role:state.user.role};
  PERF.metaLoaded=true;
}
function perfUserOpts(uid, withAny){
  let o=withAny?`<option value="">选择账号…</option>`:"";
  o+=PERF.users.map(u=>`<option value="${u.userId}" ${String(uid)===String(u.userId)?"selected":""}>${esc(u.name)}（${esc(u.username)}）</option>`).join("");
  return o;
}
function perfUserName(uid){ const u=PERF.users.find(x=>String(x.userId)===String(uid)); return u?u.name:""; }
/* 选“人员”（不是账号）：审批人/数据填报人统一选员工；有启用账号的员工标注“可登录审批” */
function perfStaffName(sid){ const s=PERF.staff.find(x=>String(x.id)===String(sid)); return s?s.name:""; }
function perfStaffOpts(sid, withAny, anyText){
  const accStaff = new Set((PERF.users||[]).filter(u=>u.enabled!==false&&u.staffId).map(u=>String(u.staffId)));
  let o=withAny?`<option value="">${esc(anyText||"选择人员…")}</option>`:"";
  o+=(PERF.staff||[]).map(s=>{
    const has=accStaff.has(String(s.id));
    return `<option value="${s.id}" ${has?"":"disabled"} ${String(sid)===String(s.id)?"selected":""}>${esc(s.name)}｜${esc(s.project)}｜${esc(s.position||"")}${has?"":"（未绑定账号，不可选）"}</option>`;
  }).join("");
  return o;
}

/* ---------- 发起/编辑考核 ---------- */
async function pagePerfEditor(id){
  const c=document.getElementById("content");
  c.innerHTML=`<div class="card"><h3>${id?"编辑考核单":"发起绩效考核"}</h3><div id="perfEditBody">加载中…</div></div>`;
  try{
    await perfLoadMeta(true);
    if(id){
      const d=await api(`/api/performance/plans/${id}`);
      PERF.draft=JSON.parse(JSON.stringify(d.plan));
      PERF.me = Object.assign(PERF.me || {}, { isAdmin: d.isAdmin });
    }else{
      PERF.draft={ id:null, employeeId:null, employeeName:"", line:"", project:"", periodStart:"", periodEnd:"",
        categories:[{name:"经营指标",catWeight:"",items:[]},{name:"管理指标",catWeight:"",items:[]}], approvers:[] };
    }
    perfRenderEditor();
  }catch(e){ document.getElementById("perfEditBody").innerHTML=`<div class="msg err">${esc(e.message)}</div>`; }
}
function perfRenderEditor(){
  const d=PERF.draft; if(!d)return;
  const isAdmin = PERF.me && (PERF.me.isAdmin || PERF.me.role === "admin" || state.user.role === "admin");
  const myStaffId = PERF.me && PERF.me.staffId;
  const me = PERF.staff.find(x=>String(x.id)===String(myStaffId));
  // 普通员工锁定被考核人为自己
  let empOpts, empShow;
  if (isAdmin) {
    empOpts=`<option value="">选择被考核员工…</option>`+PERF.staff.map(s=>`<option value="${s.id}" ${String(d.employeeId)===String(s.id)?"selected":""}>${esc(s.name)}｜${esc(s.project)}｜${esc(s.position||"")}</option>`).join("");
    empShow = empOpts;
  } else {
    empOpts = me ? `<option value="${me.id}" selected>${esc(me.name)}｜${esc(me.project)}｜${esc(me.position||"")}（本人）</option>` : `<option value="">（未绑定人员档案，请联系管理员）</option>`;
    empShow = `<div style="font-size:16px;font-weight:700">${me ? esc(me.name + "（本人，发起人自动锁定为被考核人）") : "未绑定人员档案，请联系管理员"}</div><input type="hidden" id="pfEmployee" value="${myStaffId||""}">`;
  }
  // 默认周期：本次考核（若未设置，默认本季度起止）
  if (!d.periodStart || !d.periodEnd) {
    const now = new Date(); const q = Math.floor(now.getMonth()/3);
    d.periodStart = now.getFullYear() + "-" + String(q*3+1).padStart(2,"0") + "-01";
    d.periodEnd = now.getFullYear() + "-" + String(q*3+3).padStart(2,"0") + "-" + (q*3+3===12?"31":"30");
  }
  let html=`
  <div class="perf-head" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:10px;padding:16px 20px;color:#fff;display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-bottom:14px">
    <div style="flex:1;min-width:180px">
      <div style="font-size:12px;opacity:.85;margin-bottom:4px">被考核员工</div>
      ${isAdmin ? `<select id="pfEmployee" style="min-width:220px;border-radius:6px;padding:6px 8px;border:none" onchange="perfPickEmployee(this.value)">${empShow}</select>`
        : empShow}
    </div>
    <label style="color:#fff;font-size:12px">条线/岗位<input type="text" id="pfLine" value="${esc(d.line||"")}" placeholder="如：品宣条线" style="margin-top:4px;border:none;border-radius:6px;padding:6px 8px" oninput="PERF.draft.line=this.value"></label>
    <label style="color:#fff;font-size:12px">所属项目<input type="text" value="${esc(d.project||"")}" id="pfProject" style="margin-top:4px;border:none;border-radius:6px;padding:6px 8px" oninput="PERF.draft.project=this.value"></label>
  </div>
  <div class="row" style="margin-bottom:10px">
    <label class="fld">本次考核周期 · 开始日期 <input type="date" value="${esc(d.periodStart||"")}" onchange="PERF.draft.periodStart=this.value"></label>
    <label class="fld">结束日期 <input type="date" value="${esc(d.periodEnd||"")}" onchange="PERF.draft.periodEnd=this.value"></label>
    <span class="hint" style="margin:0">自定义起止，同一员工在同一时间段内不可重复发起。</span>
  </div>
  <div id="pfWeightBar" style="display:flex;align-items:center;gap:18px;padding:18px 24px;margin:8px 0 16px;border-radius:12px;background:linear-gradient(90deg,#0f172a,#1e293b);border:2px solid #475569;box-shadow:0 4px 14px rgba(15,23,42,.18)">
    <div style="font-size:16px;font-weight:800;color:#ffffff;letter-spacing:1px">全部指标权重合计</div>
    <div id="pfWeightSum" style="font-size:42px;font-weight:900;line-height:1;letter-spacing:1px;font-variant-numeric:tabular-nums;color:#ffffff">0</div>
    <div style="font-size:20px;font-weight:800;color:#cbd5e1">/ 100 分</div>
    <div id="pfWeightTag" style="font-size:14px;font-weight:700;padding:6px 14px;border-radius:999px"></div>
    <div style="margin-left:auto;font-size:12px;color:#cbd5e1">提交前权重合计必须=100，否则无法提交</div>
  </div>
  <div id="perfCats"></div>
  <div class="row" style="margin:10px 0">
    <button class="btn" onclick="perfAddCat()">＋ 添加指标类别</button>
    <span class="hint" style="margin:0">每项权重即该项满分；下方实时显示全部指标权重合计。</span>
  </div>
  <div class="card" style="background:#f8fafc;box-shadow:none">
    <h3 style="border-left-color:#16a34a">多级审批人（按顺序逐级审批，选择的是“人员”）</h3>
    <div class="row">
      <select id="pfApproverSel" style="min-width:320px">${perfStaffOpts(null,true,"选择审批人员…")}</select>
      <button class="btn success sm" onclick="perfAddApprover()">＋ 添加为审批人</button>
    </div>
    <div class="hint" style="margin:6px 0 0">只能选择「已在人事档案中且已绑定启用账号」的人员作为审批人；未绑定账号的人员已置灰不可选（判定顺序：先查档案是否存在，再查是否绑定账号）。</div>
    <div id="pfApprovers" style="margin-top:10px"></div>
  </div>
  <div class="row end" style="margin-top:16px">
    <button class="btn" onclick="nav('perfMine')">返回</button>
    <button class="btn" onclick="perfSave(false)">💾 保存草稿</button>
    <button class="btn primary" onclick="perfSave(true)">✅ 提交（进入上级确认）</button>
  </div>`;
  document.getElementById("perfEditBody").innerHTML=html;
  perfRenderCats(); perfRenderApprovers(); perfUpdateWeightSum();
}
function perfPickEmployee(v){
  const d=PERF.draft; const s=PERF.staff.find(x=>String(x.id)===String(v));
  d.employeeId=v?Number(v):null; d.employeeName=s?s.name:"";
  if(s){ d.line=s.position||d.line||""; d.project=s.project||"";
    const le=document.getElementById("pfLine"),pe=document.getElementById("pfProject");
    if(le)le.value=d.line; if(pe)pe.value=d.project; }
  // 组织联动：按直属上级自动带审批链（按人员；上级无账号仅提示，仍可保留在审批链）
  if (s) {
    api("/api/performance/leader_approver?staff_id=" + s.id).then(r => {
      const box = document.getElementById("pfApprovers");
      if (r.leader) {
        if (!d.approvers.some(a => Number(a.staffId) === Number(r.leader.staffId))) {
          d.approvers.push({ staffId: Number(r.leader.staffId), name: r.leader.name });
          perfRenderApprovers();
        }
        if (box) {
          const tip = r.leader.hasAccount ? `已自动带出直属上级「${esc(r.leader.name)}」为审批人，可手动增删调整。`
            : `已带出直属上级「${esc(r.leader.name)}」，但其暂无启用账号，需先在权限页为其绑定账号才能登录审批。`;
          box.insertAdjacentHTML("afterbegin", `<div class="msg ${r.leader.hasAccount?"ok":"err"}" style="margin:4px 0">${tip}</div>`);
        }
      }
    }).catch(() => {});
  }
}
function perfAddCat(){ PERF.draft.categories.push({name:"新类别",catWeight:"",items:[]}); perfRenderCats(); }
function perfDelCat(ci){ PERF.draft.categories.splice(ci,1); perfRenderCats(); perfUpdateWeightSum(); }
function perfAddItem(ci){
  PERF.draft.categories[ci].items.push({id:"i"+Date.now()+Math.floor(Math.random()*99),content:"",definition:"",finishTime:"每季度末",
    weight:"",sourceDept:"",reporterId:null,reporterName:"",calcType:"ratio",calcParams:{target:100}});
  perfRenderCats(); perfUpdateWeightSum();
}
function perfDelItem(ci,ii){ PERF.draft.categories[ci].items.splice(ii,1); perfRenderCats(); perfUpdateWeightSum(); }
function perfSetField(ci,ii,field,val){
  if(ii===undefined||ii===null){ PERF.draft.categories[ci][field]=val; }
  else { PERF.draft.categories[ci].items[ii][field]=val; }
  perfUpdateWeightSum();
}
function perfSetParam(ci,ii,field,val){ const it=PERF.draft.categories[ci].items[ii]; it.calcParams[field]=val; }
function perfPickReporter(ci,ii,val){
  const it=PERF.draft.categories[ci].items[ii];
  it.reporterId=val?Number(val):null; it.reporterName=perfStaffName(val);
}
function perfChangeCalc(ci,ii,val){
  const it=PERF.draft.categories[ci].items[ii]; it.calcType=val;
  it.calcParams = val==="ratio"?{target:100}: val==="ladder"?{target:100,stepUnit:1,stepDeduct:5,zeroThreshold:80}
    : val==="count"?{required:6,deductEach:1}:{};
  // 仅重绘参数单元格与算分方式说明
  const box=document.getElementById(`pfParams_${ci}_${ii}`);
  if(box) box.innerHTML=perfCalcParamsHtml(ci,ii,it);
  const hb=document.getElementById(`pfHelp_${ci}_${ii}`);
  if(hb) hb.innerHTML="💡 "+(CALC_HELP[val]||"");
}
function perfCalcParamsHtml(ci,ii,it){
  const p=it.calcParams||{}, num=(k,ph,label)=>`<input type="number" style="width:78px" placeholder="${ph}" title="${label}" value="${p[k]??""}" oninput="perfSetParam(${ci},${ii},'${k}',this.value)">`;
  if(it.calcType==="ratio") return `目标值 ${num("target","100","达到该值得满分")}`;
  if(it.calcType==="ladder") return `目标 ${num("target","100","目标值")} 每差 ${num("stepUnit","1","单位")} 扣 ${num("stepDeduct","5","分")} 低于 ${num("zeroThreshold","80","记0")} 记0`;
  if(it.calcType==="count") return `应完成 ${num("required","6","数量")} 每少1扣 ${num("deductEach","1","分")}`;
  return `<span class="tag gray">评分人直接打分</span>`;
}
function perfRenderCats(){
  const d=PERF.draft;
  let html="";
  d.categories.forEach((cat,ci)=>{
    html+=`<div class="perf-cat">
      <div class="row" style="margin-bottom:8px">
        <b>指标类别</b>
        <input type="text" style="width:150px" value="${esc(cat.name)}" oninput="perfSetField(${ci},null,'name',this.value)">
        <span class="hint" style="margin:0">类别权重 ${(cat.items||[]).reduce((s,it)=>s+(perfNum(it.weight)||0),0).toFixed(1)} 分（自动汇总，仅供参考）</span>
        <button class="btn sm danger" style="margin-left:auto" onclick="perfDelCat(${ci})">删除类别</button>
      </div>
      <div class="table-wrap" style="max-height:none"><table class="tb">
        <thead><tr><th style="min-width:170px">指标内容</th><th style="min-width:230px">指标定义与扣分规则</th><th>完成时间</th><th>权重(满分)</th><th>来源部门</th><th>数据填报人</th><th style="min-width:200px">算分方式与参数</th><th></th></tr></thead><tbody>`;
    (cat.items||[]).forEach((it,ii)=>{
      html+=`<tr>
        <td><input type="text" style="width:160px" value="${esc(it.content)}" oninput="perfSetField(${ci},${ii},'content',this.value)"></td>
        <td><textarea rows="2" style="width:220px" oninput="perfSetField(${ci},${ii},'definition',this.value)">${esc(it.definition)}</textarea></td>
        <td><input type="text" style="width:84px" value="${esc(it.finishTime||"每季度末")}" oninput="perfSetField(${ci},${ii},'finishTime',this.value)"></td>
        <td><input type="number" style="width:64px" value="${it.weight??""}" oninput="perfSetField(${ci},${ii},'weight',this.value)"></td>
        <td><input type="text" style="width:104px" value="${esc(it.sourceDept||"")}" oninput="perfSetField(${ci},${ii},'sourceDept',this.value)"></td>
        <td><select style="max-width:150px" onchange="perfPickReporter(${ci},${ii},this.value)" title="未选择填报人的指标在数据填报阶段自动跳过，无需填报">${perfStaffOpts(it.reporterId,true,"选择填报人…")}</select>
          ${it.reporterId?"":`<div class="hint" style="margin:0;color:#c2410c">未选择填报人，填报阶段自动跳过</div>`}</td>
        <td><select style="margin-bottom:4px" onchange="perfChangeCalc(${ci},${ii},this.value)">
          ${Object.keys(CALC_LABEL).map(k=>`<option value="${k}" ${it.calcType===k?"selected":""}>${CALC_LABEL[k]}</option>`).join("")}</select>
          <div id="pfParams_${ci}_${ii}" style="font-size:11.5px;color:#64748b">${perfCalcParamsHtml(ci,ii,it)}</div>
          <div class="hint" id="pfHelp_${ci}_${ii}" style="margin:4px 0 0;color:#475569;line-height:1.45">💡 ${CALC_HELP[it.calcType]||""}</div></td>
        <td><button class="btn sm danger" onclick="perfDelItem(${ci},${ii})">删</button></td></tr>`;
    });
    html+=`</tbody></table></div>
      <button class="btn sm" style="margin-top:6px" onclick="perfAddItem(${ci})">＋ 添加指标项</button></div>`;
  });
  document.getElementById("perfCats").innerHTML=html;
}
function perfApId(a){ return a? (a.staffId!=null?Number(a.staffId):Number(a.userId??0)) : 0; }
function perfAddApprover(){
  const sel=document.getElementById("pfApproverSel"); const sid=Number(sel.value); if(!sid)return;
  if(PERF.draft.approvers.some(a=>perfApId(a)===sid)){ toast("该审批人已添加",false); return; }
  PERF.draft.approvers.push({staffId:sid,name:perfStaffName(sid)}); perfRenderApprovers();
}
function perfDelApprover(i){ PERF.draft.approvers.splice(i,1); perfRenderApprovers(); }
function perfMoveApprover(i,d){ const a=PERF.draft.approvers, j=i+d; if(j<0||j>=a.length)return; [a[i],a[j]]=[a[j],a[i]]; perfRenderApprovers(); }
function perfRenderApprovers(){
  const box=document.getElementById("pfApprovers"); if(!box)return;
  if(!PERF.draft.approvers.length){ box.innerHTML=`<span class="hint">尚未添加审批人</span>`; return; }
  box.innerHTML=PERF.draft.approvers.map((a,i)=>`<span class="tag blue" style="font-size:12.5px;padding:5px 10px;margin:3px">
    ${i+1}级 · ${esc(a.name)}
    <button class="btn sm" style="padding:0 6px;margin-left:5px" onclick="perfMoveApprover(${i},-1)">↑</button>
    <button class="btn sm" style="padding:0 6px" onclick="perfMoveApprover(${i},1)">↓</button>
    <button class="btn sm danger" style="padding:0 6px" onclick="perfDelApprover(${i})">×</button></span>`).join("");
}
function perfUpdateWeightSum(){
  const el=document.getElementById("pfWeightSum"); if(!el)return;
  const tag=document.getElementById("pfWeightTag"), bar=document.getElementById("pfWeightBar");
  const s=perfWeightSum(PERF.draft), ok=Math.abs(s-100)<0.01;
  el.textContent=s;
  el.style.color = ok ? "#16a34a" : "#dc2626";
  if(bar){ bar.style.background = ok ? "#ecfdf5" : "#fef2f2"; bar.style.borderColor = ok ? "#86efac" : "#fca5a5"; }
  if(tag){ tag.textContent = ok ? "✓ 合计正确（=100）" : (s<100?`还差 ${Math.round((100-s)*100)/100} 分`:`超出 ${Math.round((s-100)*100)/100} 分`);
    tag.className = ok?"tag green":"tag red"; }
}
async function perfSave(submit){
  const d=PERF.draft;
  const isAdmin = PERF.me && (PERF.me.isAdmin || PERF.me.role === "admin" || state.user.role === "admin");
  if(!isAdmin && PERF.me && PERF.me.staffId) d.employeeId = Number(PERF.me.staffId);
  if(!d.employeeId){ alert("请选择被考核员工"); return; }
  if(!d.periodStart||!d.periodEnd){ alert("请选择本次考核周期的开始与结束日期"); return; }
  if(d.periodEnd < d.periodStart){ alert("考核周期结束日期不能早于开始日期"); return; }
  if(!d.categories.some(c=>c.items.length)){ alert("请至少添加一个指标项"); return; }
  for(const c of d.categories) for(const it of c.items){ if(!it.content){ alert("存在未填指标内容的行"); return; } }
  if(!d.approvers.length){ alert("请至少添加一级审批人"); return; }
  const accStaff = new Set((PERF.users||[]).filter(u=>u.enabled!==false&&u.staffId).map(u=>String(u.staffId)));
  for(const a of d.approvers){ if(!accStaff.has(String(a.staffId))){ alert("审批人「"+(a.name||"")+"」未绑定启用账号，无法执行审批，请先为其开通账号"); return; } }
  const ws=perfWeightSum(d);
  if(submit&&Math.abs(ws-100)>0.01){ alert(`权重合计须为100，当前${ws}`); return; }
  try{
    const r=await api("/api/performance/plans/save",{body:{...d,submit}});
    toast(submit?"已提交，等待上级确认":"草稿已保存");
    nav("perfMine");
  }catch(e){ alert(e.message); }
}

/* ---------- 列表 ---------- */
const PERF_SCOPE_TAB={mine:"我的考核",approve:"待我审批",report:"待我填报",all:"考核记录管理"};
async function pagePerfList(scope){
  if(scope==="all"&&canPerm("perf_admin")) return pagePerfAdmin();
  PERF.listScope=scope; PERF.back=state.page;
  const c=document.getElementById("content");
  const isAdmin=canPerm("perf_admin");
  c.innerHTML=`<div class="card">
    <div class="row" style="justify-content:space-between">
      <h3 style="margin:0;border:none;padding:0">${PERF_SCOPE_TAB[scope]||"绩效考核"}</h3>
      <div class="row">
        ${scope==="all"&&isAdmin?`<button class="btn sm" onclick="perfGradeSettings()">⚙️ 考评等级设置</button>`:""}
        ${scope==="mine"?`<button class="btn primary" onclick="nav('perfCreate')">＋ 发起考核</button>`:""}
        <button class="btn sm" onclick="pagePerfList('${scope}')">刷新</button>
      </div>
    </div>
    <div id="perfListArea" style="margin-top:12px">加载中…</div>
  </div>`;
  try{
    await perfLoadMeta();
    const d=await api(`/api/performance/plans?scope=${scope}`);
    perfRenderList(d.plans||[],scope);
  }catch(e){ document.getElementById("perfListArea").innerHTML=`<div class="msg err">${esc(e.message)}</div>`; }
}
function perfRenderList(plans,scope){
  const area=document.getElementById("perfListArea");
  if(!plans.length){ area.innerHTML=`<div class="msg info">暂无考核单</div>`; return; }
  let rows="";
  plans.forEach(p=>{
    const stCol=PERF_STATUS_COLOR[p.status]||"gray";
    let ops=`<button class="btn sm" onclick="perfOpenDetail(${p.id})">查看</button>`;
    if(p.status==="draft"&&scope==="mine") ops=`<button class="btn sm primary" onclick="pagePerfEditor(${p.id})">编辑</button>
      <button class="btn sm danger" onclick="perfDeletePlan(${p.id})">删除</button>`;
    else if((p.status==="confirm"||p.status==="approve")&&(scope==="approve")) ops=`<button class="btn sm primary" onclick="perfOpenDetail(${p.id})">去审批</button>`;
    else if(p.status==="report"&&scope==="report") ops=`<button class="btn sm primary" onclick="perfOpenDetail(${p.id})">去填报${p.todoItems?`（${p.todoItems}项）`:""}</button>`;
    else if(p.status==="self"&&scope==="mine") ops=`<button class="btn sm primary" onclick="perfOpenDetail(${p.id})">去自评</button>`;
    if(p.status==="done") ops+=` <button class="btn sm" onclick="download('/api/performance/export/${p.id}','绩效考核表.xlsx')">导出</button>`;
    if(scope==="all"&&canPerm("perf_admin")&&p.status!=="draft") ops+=` <button class="btn sm danger" onclick="perfDeletePlan(${p.id})">删除</button>`;
    const score = p.finalTotal!=null?perfFmt(p.finalTotal):(p.selfTotal!=null?perfFmt(p.selfTotal):"-");
    rows+=`<tr>
      <td>#${p.id}</td><td>${esc(p.employeeName)}<div class="hint" style="margin:0">${esc(p.line||"")} ${esc(p.project||"")}</div></td>
      <td>${esc(p.periodStart||"")} ~ ${esc(p.periodEnd||"")}</td>
      <td><span class="tag ${stCol}">${PERF_STATUS_LABEL[p.status]||p.status}</span></td>
      <td class="num">${perfFmt(p.weightSum)}</td>
      <td class="num">${p.selfTotal!=null?perfFmt(p.selfTotal):"-"}</td>
      <td class="num">${p.approverTotal!=null?perfFmt(p.approverTotal):"-"}</td>
      <td class="num"><b>${score}</b></td>
      <td>${p.grade?`<span class="tag purple">${esc(p.grade)}</span>`:"-"}</td>
      <td>${esc(p.approverName||"-")}</td><td>${esc(p.founderName||"")}</td>
      <td style="white-space:nowrap">${ops}</td></tr>`;
  });
  area.innerHTML=`<div class="table-wrap" style="max-height:calc(100vh - 230px)"><table class="tb">
    <thead><tr><th>单号</th><th>被考核人</th><th>考核周期</th><th>员工状态</th><th>权重</th><th>自评总分</th><th>考核人评分</th><th>最终总分</th><th>等级</th><th>当前审批人</th><th>发起人</th><th>操作</th></tr></thead>
    <tbody>${rows}</tbody></table></div>`;
}

/* ---------- 考核记录管理（管理员） ---------- */
PERF.admin = { tab:"ongoing", quarter:0, kw:"", project:"" };
async function pagePerfAdmin(){
  const c=document.getElementById("content");
  c.innerHTML=`<div class="card">
    <div class="row" style="justify-content:space-between">
      <h3 style="margin:0;border:none;padding:0">考核记录管理</h3>
      <div class="row">
        <button class="btn sm" onclick="perfGradeSettings()">⚙️ 考评等级与占比设置</button>
        <button class="btn sm" onclick="pagePerfAdmin()">刷新</button>
      </div>
    </div>
    <div id="pfAdminArea" style="margin-top:12px">加载中…</div>
  </div>`;
  try{
    await perfLoadMeta();
    const d=await api("/api/performance/plans?scope=all");
    PERF.adminPlans=d.plans||[];
    perfRenderAdmin();
  }catch(e){ document.getElementById("pfAdminArea").innerHTML=`<div class="msg err">${esc(e.message)}</div>`; }
}
function perfAdminSet(k,v){ PERF.admin[k]=v; perfRenderAdmin(); }
function perfRenderAdmin(){
  const area=document.getElementById("pfAdminArea"); if(!area)return;
  const all=PERF.adminPlans||[]; const A=PERF.admin;
  // 各状态数量统计卡片
  const cnt = s=>all.filter(p=>p.status===s).length;
  const doneCnt=all.filter(p=>p.status==="done").length;
  const ongoingCnt=all.length-doneCnt;
  const cards=[
    ["草稿",cnt("draft"),"#64748b"],["待确认指标",cnt("confirm"),"#f59e0b"],["考核进行中",cnt("ongoing"),"#3b82f6"],
    ["待数据填报",cnt("report"),"#f97316"],["待发起人自评",cnt("self"),"#ef4444"],["待逐级审批",cnt("approve"),"#8b5cf6"],
    ["已归档",doneCnt,"#16a34a"]];
  const cardHtml=`<div class="row" style="gap:10px;flex-wrap:wrap;margin-bottom:10px">${cards.map(([t,n,col])=>`
    <div style="flex:1;min-width:96px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05)">
      <div style="font-size:26px;font-weight:900;color:${col};font-variant-numeric:tabular-nums">${n}</div>
      <div style="font-size:12px;color:#64748b;margin-top:2px">${t}</div></div>`).join("")}</div>`;
  // 筛选 + Tab
  const projOpts=["<option value=''>全部项目</option>"].concat([...new Set(all.map(p=>p.project).filter(Boolean))].sort().map(x=>`<option value="${esc(x)}" ${A.project===x?"selected":""}>${esc(x)}</option>`)).join("");
  const qOpts=["<option value='0'>全部季度</option>"].concat([1,2,3,4].map(q=>`<option value="${q}" ${A.quarter===q?"selected":""}>第${q}季度</option>`)).join("");
  const tabs=[["ongoing",`进行中（${ongoingCnt}）`],["done",`已完结（${doneCnt}）`],["rank","项目得分排名"]];
  const tabHtml=`<div class="row" style="gap:8px;margin:10px 0;flex-wrap:wrap;align-items:center">
    ${tabs.map(([k,l])=>`<button class="btn sm ${A.tab===k?"primary":""}" onclick="perfAdminSet('tab','${k}')">${l}</button>`).join("")}
    <span style="flex:1"></span>
    <label style="font-size:13px">季度 <select onchange="perfAdminSet('quarter',this.value)">${qOpts}</select></label>
    <label style="font-size:13px">项目 <select style="max-width:170px" onchange="perfAdminSet('project',this.value)">${projOpts}</select></label>
    <input type="text" placeholder="按姓名/条线/项目搜索…" value="${esc(A.kw)}" style="width:170px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px" oninput="clearTimeout(PERF._kwT);PERF._kwT=setTimeout(()=>perfAdminSet('kw',this.value),300)">
    <button class="btn sm" onclick="perfAdminExport()">📤 批量导出</button>
  </div>`;
  area.innerHTML=cardHtml+tabHtml+`<div id="pfAdminList"></div>`;
  if(A.tab==="rank"){ perfLoadRanking(); return; }
  // 进行中/已完结列表
  let list=all.filter(p=>{
    if(A.tab==="ongoing"&&p.status==="done") return false;
    if(A.tab==="done"&&p.status!=="done") return false;
    if(A.quarter>0&&(p.quarter||0)!==Number(A.quarter)) return false;
    if(A.kw && !((p.employeeName||"").includes(A.kw)||(p.line||"").includes(A.kw)||(p.project||"").includes(A.kw))) return false;
    if(A.project && p.project!==A.project) return false;
    return true;
  }).sort((a,b)=>(b.id||0)-(a.id||0));
  if(!list.length){ document.getElementById("pfAdminList").innerHTML=`<div class="msg info">暂无符合条件的考核单</div>`; return; }
  const rows=list.map(p=>{
    const stCol=PERF_STATUS_COLOR[p.status]||"gray";
    const chain=(p.approvers||[]).map((a,i)=>`${i+1}.${esc(a.name)}${a.state==="approved"?"✔":a.state==="current"?"（待审）":""}`).join(" ");
    let ops=`<button class="btn sm" onclick="perfOpenDetail(${p.id})">查看</button>`;
    if(p.status==="done") ops+=` <button class="btn sm" onclick="download('/api/performance/export/${p.id}','绩效考核表.xlsx')">导出</button>`;
    if(p.status!=="draft") ops+=` <button class="btn sm danger" onclick="perfDeletePlan(${p.id})">删除</button>`;
    return `<tr>
      <td>#${p.id}</td>
      <td>${esc(p.employeeName)}<div class="hint" style="margin:0">${esc(p.project||"")} ${esc(p.line||"")}</div></td>
      <td style="white-space:nowrap">${esc(p.periodStart||"")} ~ ${esc(p.periodEnd||"")}</td>
      <td><span class="tag ${stCol}">${PERF_STATUS_LABEL[p.status]||p.status}</span>
        ${p.rejectOpinion?`<div class="hint" style="margin:0;color:#c2410c" title="${esc(p.rejectOpinion)}">⚠ 有驳回</div>`:""}</td>
      <td class="num">${perfFmt(p.weightSum)}</td>
      <td class="num">${p.selfTotal!=null?perfFmt(p.selfTotal):"-"}</td>
      <td class="num">${p.approverTotal!=null?perfFmt(p.approverTotal):"-"}</td>
      <td class="num"><b>${p.finalTotal!=null?perfFmt(p.finalTotal):"-"}</b></td>
      <td>${p.grade?`<span class="tag purple">${esc(p.grade)}</span>`:"-"}</td>
      <td style="font-size:11.5px;min-width:120px">${chain||"-"}</td>
      <td>${esc(p.founderName||"-")}</td>
      <td style="white-space:nowrap">${ops}</td></tr>`;
  }).join("");
  document.getElementById("pfAdminList").innerHTML=`<div class="table-wrap" style="max-height:calc(100vh - 330px)"><table class="tb">
    <thead><tr><th>单号</th><th>被考核人</th><th>考核周期</th><th>员工状态</th><th>权重</th><th>自评总分</th><th>考核人评分</th><th>最终总分</th><th>等级</th><th>审批链</th><th>发起人</th><th>操作</th></tr></thead>
    <tbody>${rows}</tbody></table>
    <div class="hint" style="margin-top:6px">共 ${list.length} 条记录</div></div>`;
}
async function perfLoadRanking(){
  const box=document.getElementById("pfAdminList"); if(!box)return;
  const A=PERF.admin;
  box.innerHTML=`<div class="msg info">排名加载中…</div>`;
  try{
    const d=await api(`/api/performance/ranking?project=${encodeURIComponent(A.project||"")}&quarter=${Number(A.quarter)||0}`);
    const rows=(d.items||[]).map(r=>`<tr>
      <td class="num"><b>${r.rank}</b></td>
      <td>${esc(r.employeeName)}<div class="hint" style="margin:0">${esc(r.project||"")} ${esc(r.line||"")}</div></td>
      <td style="white-space:nowrap">${esc(r.periodStart||"")} ~ ${esc(r.periodEnd||"")}</td>
      <td class="num">${perfFmt(r.selfTotal)}</td><td class="num">${perfFmt(r.approverTotal)}</td>
      <td class="num"><b>${perfFmt(r.finalTotal)}</b></td>
      <td>${r.grade?`<span class="tag purple">${esc(r.grade)}</span>`:"-"}</td>
      <td><button class="btn sm" onclick="perfOpenDetail(${r.id})">查看</button></td></tr>`).join("");
    box.innerHTML = rows?`<div class="table-wrap" style="max-height:calc(100vh - 330px)"><table class="tb">
      <thead><tr><th>排名</th><th>被考核人</th><th>考核周期</th><th>自评总分</th><th>考核人评分</th><th>最终总分</th><th>等级</th><th>操作</th></tr></thead>
      <tbody>${rows}</tbody></table><div class="hint" style="margin-top:6px">按最终总分从高到低排列${A.project?`（项目：${esc(A.project)}）`:""}，共 ${(d.items||[]).length} 人</div></div>`
      :`<div class="msg info">暂无已归档考核${A.project?`（项目：${esc(A.project)}）`:""}</div>`;
  }catch(e){ box.innerHTML=`<div class="msg err">${esc(e.message)}</div>`; }
}
function perfAdminExport(){
  const A=PERF.admin;
  download(`/api/performance/export_all?tab=${A.tab==="rank"?"all":A.tab}&quarter=${Number(A.quarter)||0}&kw=${encodeURIComponent(A.kw||"")}&project=${encodeURIComponent(A.project||"")}`,"考核记录导出.xlsx");
}

/* ---------- 详情工作台 ---------- */
async function perfOpenDetail(id){
  const c=document.getElementById("content");
  c.innerHTML=`<div id="perfDetail" style="padding:4px">加载中…</div>`;
  try{
    await perfLoadMeta();
    PERF.detailMeta=await api(`/api/performance/plans/${id}`);
    PERF.detail=PERF.detailMeta.plan;
    perfRenderDetail();
  }catch(e){ document.getElementById("perfDetail").innerHTML=`<div class="msg err">${esc(e.message)}</div>`; }
}
function perfSteps(p){
  const idx=PERF_FLOW.indexOf(p.status);
  return `<div class="perf-steps">${PERF_FLOW_NAME.map((n,i)=>{
    const on=i<=idx, cur=i===idx;
    return `<div class="pf-step ${on?"on":""} ${cur?"cur":""}"><span class="pf-dot">${i+1}</span><span class="pf-name">${n}</span></div>${i<PERF_FLOW_NAME.length-1?'<div class="pf-line"></div>':""}`;
  }).join("")}</div>`;
}
function perfRenderDetail(){
  const wrap=document.getElementById("perfDetail");
  const p=PERF.detail, meta=PERF.detailMeta, me=meta.me, isAdmin=meta.isAdmin;
  const stCol=PERF_STATUS_COLOR[p.status]||"gray";
  // 权限/身份判定（审批人/填报人按“人员”匹配当前账号绑定的 staffId，兼容旧账号维度 userId）
  const curAp=(p.approvers||[])[p.currentStep||0];
  const apId=curAp?perfApId(curAp):0;
  const isApprover = curAp&&(apId===me.staffId||apId===me.userId);
  const isFounder = p.founderId===me.userId;
  const myReportItems=[];
  (p.categories||[]).forEach(c=>(c.items||[]).forEach(it=>{ if(it.reporterId===me.staffId||it.reporterId===me.userId) myReportItems.push(it.id); }));
  const canActApproval = isApprover||isAdmin;
  const canReport = isAdmin||myReportItems.length>0;
  const canSelf = isFounder||isAdmin;

  let action="";
  if(p.status==="draft"){
    action=isFounder||isAdmin?`<div class="msg info">单据为草稿状态。<button class="btn sm primary" onclick="pagePerfEditor(${p.id})">前往编辑/提交</button></div>`:"";
  }else if(p.status==="confirm"&&canActApproval){
    action=`<div class="card perf-action"><b>① 请核对指标设置与权重，并确认员工提交的考核周期</b>
      <div class="row" style="margin-top:8px;gap:12px;align-items:center;flex-wrap:wrap">
        <span class="tag blue" style="font-size:14px">考核周期：${esc(p.periodStart||"-")} ~ ${esc(p.periodEnd||"-")}</span>
        <span class="tag gray">权重合计 ${perfWeightSum(p)}</span>
        <span style="font-size:12px;color:#64748b">周期由发起人提交，审核人直接核对确认即可，无需再选择年度/季度。</span>
        <button class="btn success" onclick="perfActConfirm(${p.id})">✔ 确认通过（进入考核周期）</button>
        <button class="btn danger" onclick="perfActReject(${p.id},'reject_confirm')">驳回修改</button>
      </div></div>`;
  }else if(p.status==="ongoing"){
    const canAdvance = isFounder||isAdmin;
    action=`<div class="msg info">考核周期进行中（${p.periodStart} ~ ${p.periodEnd}），周期结束次日将自动转入数据填报。${
      canAdvance ? `<div class="row" style="margin-top:10px;gap:10px;align-items:center"><button class="btn warn" onclick="perfActStartReport(${p.id})">⏭ 提前结束周期，立即转入数据填报</button><span class="hint" style="margin:0">仅管理员/发起人可操作，用于需提前启动填报的特殊情况</span></div>` : ""
    }</div>`;
  }else if(p.status==="report"){
    let repPending=0;
    (p.categories||[]).forEach(c=>(c.items||[]).forEach(it=>{
      if(!it.reporterId) return;
      if(it.reportBy) return;
      repPending++;
    }));
    let a=`<div class="card perf-action"><b>③ 数据填报阶段</b>：各指标填报人填写实际完成值，全部填齐后自动转入发起人自评。
      <div class="row" style="margin-top:8px;gap:10px;align-items:center;flex-wrap:wrap">`;
    if(isFounder||isAdmin){
      a+=`<button class="btn warn" onclick="perfActUrge(${p.id})">📢 一键催办未填报人</button>
        <button class="btn primary" onclick="perfActFinishReport(${p.id},${repPending})">✔ 完成填报，进入发起人自评${repPending?`（还差 ${repPending} 项）`:""}</button>`;
    }
    if(isAdmin) a+=`<span class="hint" style="margin:0">管理员可代填任意项</span>`;
    a+=`</div></div>`; action=a;
  }else if(p.status==="self"&&canSelf){
    action=`<div class="card perf-action"><b>④ 发起人自评</b>：系统已按算分方式自动算出参考分，可逐项调整自评分，完成后提交审批。
      <div class="row end" style="margin-top:8px"><button class="btn primary" onclick="perfActSelfSubmit(${p.id})">提交自评，送上级审批</button></div></div>`;
  }else if(p.status==="approve"&&canActApproval){
    const sw=PERF.detailMeta&&PERF.detailMeta.scoreWeights?PERF.detailMeta.scoreWeights:{self:50,approver:50};
    action=`<div class="card perf-action"><b>⑤ 逐级审批（第 ${p.currentStep+1} / ${p.approvers.length} 级 · 当前：${esc(curAp?curAp.name:"")}）</b>
      <div class="hint" style="margin:4px 0 0">请逐项填写<b>考核人评分</b>，系统自动按「自评${sw.self}% + 考核人${sw.approver}%」算出每项最终分（在最终分列实时预览），如有个别项需要微调可直接修改最终分。</div>
      <div class="row end" style="margin-top:8px">
        <button class="btn danger" onclick="perfActReject(${p.id},'reject_approve')">驳回</button>
        <button class="btn success" onclick="perfActApprove(${p.id})">✔ ${p.currentStep+1>=p.approvers.length?"终审通过并归档":"审批通过，送下一级"}</button></div></div>`;
  }else if(p.status==="done"){
    action=`<div class="msg ok">已归档：最终总分 <b>${perfFmt(p.finalTotal)}</b>，考评等级 <b>${esc(p.grade||"")}</b></div>`;
  }

  // 指标表
  let seq=1, tableRows="";
  (p.categories||[]).forEach((cat,ci)=>{
    const items=cat.items||[];
    items.forEach((it,ii)=>{
      const rid=it.id;
      // 完成情况单元格
      let actual=esc(it.actualText||it.actualValue||"")||"-";
      if(p.status==="report"&&(isAdmin||myReportItems.includes(rid))){
        if(it.calcType==="manual"){
          actual=`<input type="text" style="width:150px" id="at_${rid}" value="${esc(it.actualText||"")}" placeholder="完成情况说明（选填）">
            <button class="btn sm" onclick="perfActReport(${p.id},'${rid}',${isAdmin&&!myReportItems.includes(rid)})">提交</button>`;
        }else{
          actual=`<input type="text" style="width:80px" id="av_${rid}" value="${esc(it.actualValue??"")}" placeholder="实际值">
            <input type="text" style="width:90px" id="at_${rid}" value="${esc(it.actualText||"")}" placeholder="情况说明">
            <button class="btn sm" onclick="perfActReport(${p.id},'${rid}',${isAdmin&&!myReportItems.includes(rid)})">保存</button>`;
        }
      }
      if(it.reportBy) actual+=`<div class="hint" style="margin:0;color:#16a34a">✔ 已提交：${esc(it.reportBy)}</div>`;
      // 自评分
      let self=perfFmt(it.selfScore);
      if(p.status==="self"&&canSelf){
        self=`<input type="number" style="width:64px" id="ss_${rid}" value="${it.selfScore??it.autoScore??""}" step="0.01">`;
      }
      // 考核人评分 + 最终分
      let appr=perfFmt(it.approverScore);
      let fin=perfFmt(it.finalScore);
      if(p.status==="approve"&&canActApproval){
        appr=`<input type="number" style="width:64px" id="as_${rid}" value="${it.approverScore??""}" step="0.01" placeholder="考核分" oninput="perfMixFinal('${rid}')">`;
        fin=`<input type="number" style="width:64px" id="fs_${rid}" value="" step="0.01" placeholder="自动/微调">`;
      }
      const ov=(it.overrides||[]).map(o=>`<div class="hint" style="margin:0;color:#c2410c">改:${perfFmt(o.from)}→${perfFmt(o.to)} ${esc(o.by)}</div>`).join("");
      tableRows+=`<tr>
        <td>${seq++}</td>
        ${ii===0?`<td rowspan="${items.length}" style="vertical-align:top;background:#f8fafc;font-weight:600">${esc(cat.name)}</td>`:""}
        <td style="white-space:normal;min-width:150px">${esc(it.content)}</td>
        <td style="white-space:normal;max-width:260px;font-size:11.5px;color:#64748b">${esc(it.definition)}</td>
        <td class="num">${perfFmt(it.weight)}</td>
        <td style="font-size:11.5px"><span title="${esc(CALC_HELP[it.calcType]||"")}" style="cursor:help;border-bottom:1px dashed #94a3b8">${CALC_LABEL[it.calcType]||it.calcType} ⓘ</span><div class="hint" style="margin:0">${esc(it.sourceDept||"")}<br>填报:${esc(it.reporterName||"-")}${it.reporterId?"":" ·未指派→自动跳过"}</div></td>
        <td style="white-space:normal">${actual}${it.reportBy?`<div class="hint" style="margin:0">${esc(it.reportBy)}</div>`:""}</td>
        <td class="num">${perfFmt(it.autoScore)}</td>
        <td class="num">${self}</td>
        <td class="num">${appr}</td>
        <td class="num">${fin}${ov}</td></tr>`;
    });
  });

  wrap.innerHTML=`
  <div class="row" style="margin-bottom:10px">
    <button class="btn sm" onclick="nav('${PERF.back||"perfMine"}')">← 返回列表</button>
    <h3 style="margin:0;border:none;padding:0">${esc(p.employeeName)}（${esc(p.line||"")}）绩效考核</h3>
    <span class="tag ${stCol}">${PERF_STATUS_LABEL[p.status]||p.status}</span>
    <button class="btn sm" style="margin-left:auto" onclick="download('/api/performance/export/${p.id}','绩效考核表.xlsx')">📤 导出考核表</button>
  </div>
  ${perfSteps(p)}
  <div class="card" style="margin-top:12px">
    <div class="row" style="gap:24px;font-size:13px;color:#475569">
      <span>被考核人：<b>${esc(p.employeeName)}</b></span><span>条线：${esc(p.line||"-")}</span><span>项目：${esc(p.project||"-")}</span>
      <span>考核周期：<b>${esc(p.periodStart||"-")} ~ ${esc(p.periodEnd||"-")}</b>（第${p.quarter||"-"}季度）</span><span>发起人：${esc(p.founderName||"-")}</span>
      <span>审批链：${(p.approvers||[]).map((a,i)=>`${i+1}.${esc(a.name)}${a.state==="approved"?"✔":a.state==="current"?"（待审）":""}`).join(" → ")}</span>
    </div>
    ${p.rejectOpinion?`<div class="msg err" style="margin-top:8px">⚠ 最近一次驳回意见：${esc(p.rejectOpinion)}</div>`:""}
  </div>
  ${action}
  <div class="card">
    <div class="row" style="margin-bottom:8px">
      <h3 style="margin:0;border:none;padding:0">考核指标明细</h3>
      <span class="tag gray">权重合计 ${perfWeightSum(p)}</span>
      <span class="tag blue">自动总分 ${p.autoTotal!=null?perfFmt(p.autoTotal):"-"}</span>
      <span class="tag orange">自评总分 ${p.selfTotal!=null?perfFmt(p.selfTotal):"-"}</span>
      <span class="tag purple">考核人评分 ${p.approverTotal!=null?perfFmt(p.approverTotal):"-"}</span>
      <span class="tag green">最终总分 ${p.finalTotal!=null?perfFmt(p.finalTotal):"-"}${p.grade?` · ${esc(p.grade)}`:""}</span>
      ${PERF.detailMeta&&PERF.detailMeta.scoreWeights?`<span class="hint" style="font-size:11.5px">占比：自评${PERF.detailMeta.scoreWeights.self}% + 考核人${PERF.detailMeta.scoreWeights.approver}%</span>`:""}
    </div>
    <div class="table-wrap" style="max-height:none"><table class="tb">
      <thead><tr><th>序号</th><th>指标类别</th><th>指标内容</th><th>定义/扣分规则</th><th>权重</th><th>算分方式/来源/填报人</th><th>完成情况</th><th>自动分</th><th>自评分</th><th>考核人评分</th><th>最终分</th></tr></thead>
      <tbody>${tableRows}</tbody></table></div>
  </div>
  ${(p.status==="approve"||p.status==="done")&&p.project?`<div class="card" style="margin-top:12px"><h3>同项目绩效得分排名（已归档，供审批/微调参考）</h3><div id="pfRankRef" style="font-size:12.5px;color:#64748b">加载中…</div></div>`:""}
  <div class="card"><h3>流程记录</h3><div class="perf-logs">${(p.logs||[]).slice().reverse().map(l=>`<div class="pf-log"><span class="pf-log-ts">${esc(l.ts)}</span><span class="pf-log-actor">${esc(l.actor)}</span><span>${esc(l.action)}${l.comment?`｜<span style="color:#c2410c">${esc(l.comment)}</span>`:""}</span></div>`).join("")||"<span class='hint'>暂无</span>"}</div></div>`;
  if(p.status==="approve"||p.status==="done") perfLoadRankRef(p);
}
async function perfLoadRankRef(p){
  const box=document.getElementById("pfRankRef"); if(!box)return;
  try{
    const d=await api(`/api/performance/ranking?project=${encodeURIComponent(p.project||"")}&quarter=0`);
    const rows=(d.items||[]).slice(0,20).map(r=>`<tr>
      <td class="num"><b>${r.rank}</b></td><td>${esc(r.employeeName)}</td><td>${esc(r.project)}</td>
      <td class="num">${perfFmt(r.selfTotal)}</td><td class="num">${perfFmt(r.approverTotal)}</td>
      <td class="num"><b>${perfFmt(r.finalTotal)}</b></td><td>${r.grade?`<span class="tag purple">${esc(r.grade)}</span>`:"-"}</td>
      <td>${r.employeeName===p.employeeName?`<span class="tag blue">当前单</span>`:""}</td></tr>`).join("");
    box.innerHTML = rows?`<div class="table-wrap" style="max-height:240px"><table class="tb"><thead><tr><th>排名</th><th>员工</th><th>项目</th><th>自评</th><th>考核人</th><th>最终</th><th>等级</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`:`<span class="hint">该项目暂无已归档考核</span>`;
  }catch(e){ box.innerHTML=`<span class="hint">排名加载失败</span>`; }
}
/* 审批时：填考核人评分自动按占比算该项最终分预览 */
function perfMixFinal(rid){
  const sw=PERF.detailMeta&&PERF.detailMeta.scoreWeights?PERF.detailMeta.scoreWeights:{self:50,approver:50};
  const p=PERF.detail; if(!p)return;
  let self=null;
  outer: for(const c of p.categories) for(const it of c.items){ if(String(it.id)===String(rid)){ self=perfNum(it.selfScore); break outer; } }
  const ae=document.getElementById("as_"+rid), fe=document.getElementById("fs_"+rid);
  if(!ae||!fe)return;
  const av=perfNum(ae.value);
  if(av!==null&&self!==null){ fe.value=Math.round((self*sw.self+av*sw.approver)/100*100)/100; }
  else if(av!==null){ fe.value=av; }
}

/* ---------- 详情操作 ---------- */
async function perfActConfirm(id){
  try{ await api("/api/performance/confirm",{body:{id}}); toast("已确认，进入考核周期"); perfOpenDetail(id); }catch(e){ alert(e.message); }
}
async function perfActStartReport(id){
  if(!confirm("确认提前结束考核周期并立即转入数据填报？\n转入后，各指标填报人即可在「待我填报」中填写实际完成值。")) return;
  try{ await api("/api/performance/start_report",{body:{id}}); toast("已转入数据填报"); perfOpenDetail(id); }catch(e){ alert(e.message); }
}
async function perfActFinishReport(id,pending){
  if(pending&&pending>0 && !confirm(`还有 ${pending} 项指标未填写实际值，确认现在就结束数据填报、进入发起人自评吗？`)) return;
  try{ await api("/api/performance/finish_report",{body:{id}}); toast(pending?"已提前进入发起人自评":"全部填齐，已进入发起人自评"); perfOpenDetail(id); }catch(e){ alert(e.message); }
}
function perfActReject(id,endpoint){
  modal(`<h3>驳回（需填写意见）</h3><textarea id="pfRejectOp" rows="4" style="width:100%" placeholder="请说明驳回原因，将退回发起人"></textarea>
  <div class="row end" style="margin-top:12px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn danger" onclick="perfDoReject(${id},'${endpoint}')">确认驳回</button></div>`);
}
async function perfDoReject(id,endpoint){
  const opinion=document.getElementById("pfRejectOp").value.trim();
  if(!opinion){ alert("请填写驳回意见"); return; }
  try{ await api("/api/performance/"+endpoint,{body:{id,opinion}}); closeModal(); toast("已驳回","false"); perfOpenDetail(id); }catch(e){ alert(e.message); }
}
async function perfActReport(id,itemId,adminFill){
  const avEl=document.getElementById("av_"+itemId); const av=avEl?avEl.value:"";
  const atEl=document.getElementById("at_"+itemId); const at=atEl?atEl.value:"";
  try{ const r=await api(adminFill?"/api/performance/admin_fill":"/api/performance/report",{body:{id,itemId,actualValue:av,actualText:at}});
    toast("已提交"+(r.status==="self"?"，全部指标填报完成，已转发起人自评":"")); perfOpenDetail(id);
  }catch(e){ alert(e.message); }
}
async function perfActUrge(id){
  try{ const r=await api("/api/performance/urge",{body:{id}});
    if(!r.pending.length) toast("已无待填报项"); else toast("已催办："+r.pending.map(x=>x.name).join("、"));
  }catch(e){ alert(e.message); }
}
async function perfActSelfSubmit(id){
  const p=PERF.detail, items=[];
  for(const c of p.categories) for(const it of c.items){ const el=document.getElementById("ss_"+it.id); if(el) items.push({id:it.id,selfScore:el.value}); }
  try{ await api("/api/performance/self_submit",{body:{id,items}}); toast("自评已提交，进入审批"); perfOpenDetail(id); }catch(e){ alert(e.message); }
}
function perfActApprove(id){
  const p=PERF.detail, items=[];
  for(const c of p.categories) for(const it of c.items){
    const asEl=document.getElementById("as_"+it.id), fsEl=document.getElementById("fs_"+it.id);
    const item={id:it.id};
    if(asEl&&asEl.value!=="") item.approverScore=asEl.value;
    if(fsEl&&fsEl.value!=="") item.finalScore=fsEl.value;
    items.push(item);
  }
  PERF._approveItems=items;
  const isLast = p.currentStep+1>=p.approvers.length;
  modal(`<h3>${isLast?"终审通过并归档":"审批通过，送下一级"}</h3>
    <label style="font-size:13px;color:#595959">审批意见（可留空）</label>
    <textarea id="pfApOp" rows="3" style="width:100%;margin-top:4px" placeholder="通过">通过</textarea>
    <div class="row end" style="margin-top:12px"><button class="btn" onclick="closeModal()">取消</button>
    <button class="btn success" onclick="perfDoApprove(${id})">确认通过</button></div>`);
}
async function perfDoApprove(id){
  const items=PERF._approveItems||[];
  const opinion=(document.getElementById("pfApOp").value||"通过").trim();
  closeModal();
  try{ const r=await api("/api/performance/approve",{body:{id,items,opinion}});
    toast(r.status==="done"?`终审通过，等级：${r.grade}`:"已通过，送下一级"); perfOpenDetail(id);
  }catch(e){ alert(e.message); }
}
async function perfDeletePlan(id){
  if(!confirm("确认删除该考核单？此操作不可恢复。"))return;
  try{ await api("/api/performance/delete",{body:{id}}); toast("已删除"); refreshPage(); }catch(e){ alert(e.message); }
}

/* ---------- 等级与评分占比设置 ---------- */
async function perfGradeSettings(){
  let rules=[], weights={self:50,approver:50};
  try{ const d=await api("/api/performance/grade_rules"); rules=d.gradeRules||[]; weights=d.scoreWeights||{self:50,approver:50}; }catch(e){ return alert(e.message); }
  PERF.gradeRules=JSON.parse(JSON.stringify(rules));
  PERF.scoreWeights={self:Number(weights.self||50), approver:Number(weights.approver||50)};
  perfGradeRender();
}
function perfGradeRender(){
  modal(`<h3>考评等级与评分占比设置</h3>
  <div style="margin:8px 0 12px;padding:12px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px">
    <b style="font-size:14px">最终总分 = 自评总分 × 自评占比 + 考核人评分总分 × 考核人占比</b>
    <div class="row" style="margin-top:8px;gap:16px">
      <label style="font-size:13px">自评占比 % <input type="number" id="pfWSelf" min="0" max="100" value="${PERF.scoreWeights.self}" oninput="PERF.scoreWeights.self=Number(this.value)||0" style="width:70px"></label>
      <label style="font-size:13px">考核人评分占比 % <input type="number" id="pfWAppr" min="0" max="100" value="${PERF.scoreWeights.approver}" oninput="PERF.scoreWeights.approver=Number(this.value)||0" style="width:70px"></label>
      <span class="hint">两者合计必须等于 100</span>
    </div>
  </div>
  <div class="row"><b style="font-size:14px">考评等级分数线</b></div><div id="pfGradeBox" style="margin-top:6px"></div>
  <div class="row" style="margin-top:8px"><button class="btn sm" onclick="perfGradeAdd()">＋ 增加等级</button></div>
  <div class="hint">按最低分数线从高到低匹配，如 ≥90=优秀、80~89.99=良好。最终总分落在哪个区间即取对应等级。</div>
  <div class="row end" style="margin-top:12px"><button class="btn" onclick="closeModal()">取消</button>
  <button class="btn primary" onclick="perfGradeSave()">保存设置</button></div>`,620);
  perfGradeDraw();
}
function perfGradeAdd(){ PERF.gradeRules.push({min:0,label:""}); perfGradeDraw(); }
function perfGradeDel(i){ PERF.gradeRules.splice(i,1); perfGradeDraw(); }
function perfGradeSet(i,k,v){ PERF.gradeRules[i][k]=k==="min"?Number(v):v; }
function perfGradeDraw(){
  const box=document.getElementById("pfGradeBox"); if(!box)return;
  box.innerHTML=PERF.gradeRules.map((r,i)=>`<div class="row" style="margin-bottom:6px">
    ≥ <input type="number" style="width:90px" value="${r.min}" oninput="perfGradeSet(${i},'min',this.value)"> 分
    等级名 <input type="text" style="width:140px" value="${esc(r.label)}" oninput="perfGradeSet(${i},'label',this.value)">
    <button class="btn sm danger" onclick="perfGradeDel(${i})">删</button></div>`).join("");
}
async function perfGradeSave(){
  if(!PERF.gradeRules.length){ alert("至少保留一个等级"); return; }
  if(PERF.gradeRules.some(r=>r.label==="")){ alert("等级名不能为空"); return; }
  const w=PERF.scoreWeights||{};
  if(Math.abs((Number(w.self)||0)+(Number(w.approver)||0)-100)>0.01){ alert("自评占比与考核人评分占比合计必须等于100"); return; }
  try{ await api("/api/performance/grade_rules",{body:{gradeRules:PERF.gradeRules, scoreWeights:{self:Number(w.self)||0, approver:Number(w.approver)||0}}}); closeModal(); toast("设置已保存"); refreshPage(); }catch(e){ alert(e.message); }
}




/* ================= 审批中心 ================= */
let _appTab = "approve";
let _appCreateFlow = null;
let _appStaffOpts = [];

async function pageApprovalCenter() {
  const c = document.getElementById("content");
  const nav = (group, items) => `<div style="font-size:11px;color:#9ca3af;padding:10px 16px 4px;font-weight:600">${group}</div>` +
    items.map(t => `<div class="app-nav ${_appTab===t[0]?"on":""}" data-tab="${t[0]}" onclick="appTab('${t[0]}')">
      <span>${t[1]}</span>${t[2]?`<span class="app-nav-badge">${t[2]}</span>`:""}</div>`).join("");
  c.innerHTML = `<div class="card" style="display:flex;gap:0;padding:0;overflow:hidden">
    <div style="width:172px;flex-shrink:0;background:#f8fafc;border-right:1px solid #eef0f3;padding:8px 0 16px">
      <div style="padding:12px 16px 6px;font-size:14px;font-weight:700;color:#111827">📋 审批中心</div>
      ${nav("我的工作", [["todo","我的待办"],["approve","待我审批"],["mine","我的审批"]])}
      ${nav("发起与办理", [["start","发起审批"],["onboard","入职办理"]])}
      ${nav("流程中心", [["flowmgt","流程管理"],["query","查询流程"]])}
    </div>
    <div style="flex:1;min-width:0;padding:16px 20px 20px"><div id="appArea">加载中...</div></div>
  </div>
  <style>
    .app-nav{display:flex;align-items:center;justify-content:space-between;padding:8px 16px;margin:1px 8px;border-radius:8px;font-size:13px;color:#374151;cursor:pointer}
    .app-nav:hover{background:#eef2ff}
    .app-nav.on{background:#eef2ff;color:#2563eb;font-weight:600}
    .app-nav-badge{min-width:17px;height:17px;line-height:17px;text-align:center;border-radius:9px;background:#dc2626;color:#fff;font-size:11px;padding:0 5px}
  </style>`;
  await appTab(_appTab);
}
async function appTab(t) {
  _appTab = t;
  const area = document.getElementById("appArea");
  if (!area) return;
  area.innerHTML = "加载中...";
  document.querySelectorAll(".app-nav").forEach(b => b.classList.toggle("on", b.getAttribute("data-tab") === t));
  try {
    if (t === "todo") await appListTodo(area);
    else if (t === "approve") await appListApprove(area);
    else if (t === "start") await appListFlows(area);
    else if (t === "mine") await appListMine(area);
    else if (t === "flowmgt") await appListFlowMgt(area);
    else if (t === "query") await appQueryFlow(area);
    else await appListOnboard(area);
  } catch (e) { area.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}

/* ---- 我的待办 / 发送 / 查询 ---- */
let _appDraftId = 0;
let _todoTab = "draft";
async function appListTodo(el) {
  el.innerHTML = `<div style="display:flex;gap:10px;margin-bottom:12px">
      <button class="btn sm ${_todoTab === "draft" ? "primary" : ""}" onclick="appTodoTab('draft')">📝 草稿（保存未提交）</button>
      <button class="btn sm ${_todoTab === "inbox" ? "primary" : ""}" onclick="appTodoTab('inbox')">📥 收件（他人发送）</button>
    </div>
    <div id="appTodoList">加载中...</div>`;
  await appTodoTab(_todoTab);
}
async function appTodoTab(tab) {
  _todoTab = tab;
  const el = document.getElementById("appTodoList");
  if (!el) return;
  el.innerHTML = "加载中...";
  if (tab === "draft") await appDraftList(el);
  else await appInboxList(el);
}
async function appDraftList(el) {
  const d = await api("/api/approval/drafts");
  if (!d.items.length) { el.innerHTML = '<div class="msg info">暂无草稿。发起审批时可点「保存草稿」暂存</div>'; return; }
  el.innerHTML = `<div class="table-wrap"><table class="tb"><thead><tr>
    <th>流程号</th><th>流程</th><th>标题</th><th>项目</th><th>更新时间</th><th>操作</th></tr></thead><tbody>
    ${d.items.map(it => `<tr>
      <td><b style="color:#2563eb;font-size:12.5px">${esc(it.flow_no || "-")}</b></td>
      <td>${esc(it.flow_key === "hire_approval" ? "录用审批" : it.flow_key === "regular_approval" ? "转正审批" : it.flow_key === "resign_approval" ? "离职审批" : it.flow_key)}</td>
      <td>${esc(it.title || "-")}</td><td>${esc(it.project || "-")}</td>
      <td>${esc((it.time || "").replace("T", " "))}</td>
      <td><button class="btn sm primary" onclick="appEditDraft(${it.id})">继续编辑</button>
          <button class="btn sm success" style="margin-left:6px" onclick="appDraftSubmit(${it.id})">提交</button>
          <button class="btn sm" style="margin-left:6px;color:#dc2626;border-color:#f3c1c2" onclick="appDraftDelete(${it.id})">删除</button></td>
    </tr>`).join("")}
  </tbody></table></div>`;
}
async function appEditDraft(id) {
  try {
    const d = await api("/api/approval/drafts/" + id);
    await appStartFlow(d.draft.flow_key, d.draft);
  } catch (e) { toast(e.message, false); }
}
async function appDraftSubmit(id) {
  if (!confirm("确认提交该草稿进入审批流程？")) return;
  try {
    const d = await api("/api/approval/draft_submit", { body: { id } });
    toast("已提交，流程号 " + (d.flow_no || ""));
    appTab("todo");
  } catch (e) { toast(e.message, false); }
}
async function appDraftDelete(id) {
  if (!confirm("确认删除该草稿？")) return;
  try {
    await api("/api/approval/draft_delete", { body: { id } });
    toast("草稿已删除");
    appTab("todo");
  } catch (e) { toast(e.message, false); }
}
async function appInboxList(el) {
  const d = await api("/api/approval/inbox");
  if (!d.items.length) { el.innerHTML = '<div class="msg info">暂无收件。已完结流程可被他人发送给您查看</div>'; return; }
  el.innerHTML = `<div class="table-wrap"><table class="tb"><thead><tr>
    <th>流程号</th><th>流程</th><th>标题</th><th>发送人</th><th>发送时间</th><th>操作</th></tr></thead><tbody>
    ${d.items.map(it => `<tr>
      <td><b style="color:#2563eb;font-size:12.5px">${esc(it.flow_no || "-")}</b></td>
      <td>${esc(it.flow_name || "-")}</td><td>${esc(it.title || "-")}</td>
      <td>${esc(it.from_name)}</td><td>${esc((it.time || "").replace("T", " "))}</td>
      <td><button class="btn sm primary" onclick="appOpenDetailReadonly(${it.instance_id})">查看</button>
          <button class="btn sm" style="margin-left:6px;color:#dc2626;border-color:#f3c1c2" onclick="appInboxDelete(${it.id})">删除</button></td>
    </tr>`).join("")}
  </tbody></table></div>`;
}
async function appInboxDelete(id) {
  if (!confirm("确认删除该收件？（不影响原流程）")) return;
  try {
    await api("/api/approval/inbox_delete", { body: { id } });
    toast("收件已删除");
    appTab("todo");
  } catch (e) { toast(e.message, false); }
}
async function appOpenDetailReadonly(id) {
  try {
    const d = await api("/api/approval/" + id);
    renderAppDetailModal(d.instance, d.me, true);
  } catch (e) { toast(e.message, false); }
}
let _sendTargets = [];
async function appSendPanel(id) {
  try {
    if (!_sendTargets.length) {
      const t = await api("/api/approval/send_targets");
      _sendTargets = t.items || [];
    }
    const groups = {};
    (_sendTargets || []).forEach(s => { (groups[s.project || "未分组"] = groups[s.project || "未分组"] || []).push(s); });
    const names = Object.keys(groups).sort();
    modal(`<div style="width:680px;max-width:96vw">
      <div style="font-size:16px;font-weight:600;margin-bottom:4px">📤 发送流程（审批单 #${id}）</div>
      <div style="font-size:12.5px;color:#6b7280;margin-bottom:12px">从人员档案中选择接收人，自动匹配其系统账号；接收人在「我的待办-收件」中查看（只读）</div>
      <div style="max-height:380px;overflow:auto;border:1px solid #eef0f3;border-radius:10px">
        ${names.length ? names.map(g => `<div style="background:#f8fafc;padding:7px 14px;font-size:12px;color:#6b7280;font-weight:600;border-bottom:1px solid #eef0f3">${esc(g)}</div>` +
          groups[g].map(s => `<label style="display:flex;align-items:center;gap:8px;padding:8px 14px;font-size:13px;border-bottom:1px solid #f5f7fa;cursor:pointer">
            <input type="radio" name="send_staff" value="${s.id}">
            <span style="font-weight:500">${esc(s.name)}</span>
            <span style="color:#9ca3af;font-size:12px">${esc(s.position || "-")}</span>
            <span style="margin-left:auto;color:#2563eb;font-size:12px">账号：${esc(s.account)}</span>
          </label>`).join("")).join("") : '<div style="padding:20px;color:#9ca3af;text-align:center">暂无已开通账号的人员</div>'}
      </div>
      <div class="row" style="margin-top:14px">
        <button class="btn primary" onclick="appDoSend(${id})">确认发送</button>
        <button class="btn" onclick="closeModal()">取消</button>
      </div>
    </div>`, 720);
  } catch (e) { toast(e.message, false); }
}
async function appDoSend(id) {
  const sel = document.querySelector('input[name="send_staff"]:checked');
  if (!sel) { toast("请选择接收人", false); return; }
  try {
    await api("/api/approval/send", { body: { id, staff_id: parseInt(sel.value, 10) } });
    closeModal();
    toast("已发送");
  } catch (e) { toast(e.message, false); }
}
async function appQueryFlow(el) {
  el.innerHTML = `<div class="page-sub" style="margin-bottom:10px">按流程号/关键字/类型/状态/日期组合查询全部流程（管理员可查全部，其他账号仅本人发起）</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;background:#f8fafc;border:1px solid #eef0f3;border-radius:10px;padding:12px 14px">
      <label style="font-size:12.5px">流程号<input type="text" id="q_flow_no" placeholder="如 LS20260904-001" style="width:170px"></label>
      <label style="font-size:12.5px">关键字<input type="text" id="q_kw" placeholder="标题/发起人/流程名" style="width:160px"></label>
      <label style="font-size:12.5px">流程类型<select id="q_key" style="width:120px"><option value="">全部</option>
        <option value="hire_approval">录用审批</option><option value="regular_approval">转正审批</option><option value="resign_approval">离职审批</option></select></label>
      <label style="font-size:12.5px">状态<select id="q_status" style="width:110px"><option value="">全部</option>
        <option value="pending">审批中</option><option value="approved">已通过</option><option value="rejected">已驳回</option><option value="withdrawn,voided">已废弃</option></select></label>
      <label style="font-size:12.5px">开始日期<input type="date" id="q_from"></label>
      <label style="font-size:12.5px">结束日期<input type="date" id="q_to"></label>
      <button class="btn primary" onclick="appDoQuery()">查询</button>
      <button class="btn" onclick="appClearQuery()">重置</button>
    </div>
    <div id="appQueryList"><div class="msg info">输入条件后点击「查询」</div></div>`;
}
async function appDoQuery() {
  const el = document.getElementById("appQueryList");
  if (!el) return;
  const p = new URLSearchParams();
  const flowNo = document.getElementById("q_flow_no").value.trim();
  const kw = document.getElementById("q_kw").value.trim();
  const key = document.getElementById("q_key").value;
  const status = document.getElementById("q_status").value;
  const from = document.getElementById("q_from").value;
  const to = document.getElementById("q_to").value;
  if (flowNo) p.set("flow_no", flowNo);
  if (kw) p.set("keyword", kw);
  if (key) p.set("flow_key", key);
  if (status) p.set("status", status);
  if (from) p.set("date_from", from);
  if (to) p.set("date_to", to);
  el.innerHTML = "查询中...";
  try {
    const d = await api("/api/approval/search?" + p.toString());
    if (!d.items.length) { el.innerHTML = '<div class="msg info">未查询到符合条件的流程</div>'; return; }
    const stMap = { pending: ["审批中", "#f59e0b"], approved: ["已通过", "#16a34a"], rejected: ["已驳回", "#dc2626"], withdrawn: ["已撤回", "#6b7280"], voided: ["已作废", "#9ca3af"] };
    el.innerHTML = `<div class="table-wrap"><table class="tb"><thead><tr>
      <th>流程号</th><th>流程</th><th>标题</th><th>员工状态</th><th>进度</th><th>发起人</th><th>时间</th><th>操作</th></tr></thead><tbody>
      ${d.items.map(it => { const st = stMap[it.status] || ["未知", "#6b7280"];
        return `<tr><td><b style="color:#2563eb;font-size:12.5px">${esc(it.flow_no || "-")}</b></td>
        <td>${esc(it.flow_name)}</td><td>${esc(it.title)}</td>
        <td><span class="tag" style="color:${st[1]};border-color:${st[1]}">${st[0]}</span></td>
        <td>${it.current_index}/${it.total_nodes}</td><td>${esc(it.applicant)}</td>
        <td>${esc((it.time || "").replace("T", " "))}</td>
        <td><button class="btn sm primary" onclick="appOpenDetail(${it.id})">详情</button></td></tr>`; }).join("")}
    </tbody></table></div>`;
  } catch (e) { el.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function appClearQuery() {
  ["q_flow_no", "q_kw", "q_from", "q_to"].forEach(id => { const x = document.getElementById(id); if (x) x.value = ""; });
  ["q_key", "q_status"].forEach(id => { const x = document.getElementById(id); if (x) x.value = ""; });
  appDoQuery();
}

/* ---- 发起审批 ---- *//* ---- 发起审批 ---- */
async function appListFlows(el) {
  const d = await api("/api/approval/flows_public");
  if (!d.flows.length) { el.innerHTML = '<div class="msg info">暂无可用审批流程，请联系管理员在系统设置→审批权责设置中配置</div>'; return; }
  const order = ["hire_approval", "regular_approval", "resign_approval"];
  const ico = { hire_approval: "📋", regular_approval: "✅", resign_approval: "🚪" };
  const desc = { hire_approval: "新员工录用审批，通过后进入入职办理", regular_approval: "试用期满转正审批，通过后更新档案转正日期", resign_approval: "员工离职审批，通过后人员档案自动转离职" };
  const flows = d.flows.slice().sort((a, b) => (order.indexOf(a.flow_key) - order.indexOf(b.flow_key)) || (a.id - b.id));
  el.innerHTML = `<div class="page-sub" style="margin-bottom:12px">选择流程类型，发起对应审批</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
    ${flows.map(f => `<div class="home-app" style="width:250px;cursor:pointer;border:1px solid #eef0f3" onclick="appStartFlow('${f.flow_key}')">
      <div class="ha-head"><div class="ha-ico" style="background:#8b5cf6">${ico[f.flow_key]||"📋"}</div><div class="ha-name">${esc(f.name)}</div></div>
      <div class="ha-desc" style="min-height:36px">${esc(desc[f.flow_key] || (f.form_schema.length + " 个表单字段"))}</div>
      <div class="ha-go" style="color:#8b5cf6">去发起 →</div>
    </div>`).join("")}
  </div>`;
}
async function appStartFlow(fk, draftData) {
  const d = await api("/api/approval/flows_public");
  const f = (d.flows || []).find(x => x.flow_key === fk);
  if (!f) { toast("流程不存在", false); return; }
  if (!ORG_TREE) { try { await loadOrgTree(); } catch (e) {} }
  if (!_appStaffOpts.length) {
    try { const s = await api("/api/org/staff-options"); _appStaffOpts = s.staff || []; } catch (e) { _appStaffOpts = []; }
  }
  window._appStaffMap = {};
  (_appStaffOpts || []).forEach(s => { window._appStaffMap[s.id] = s; });
  _appCreateFlow = f;
  window._appStaffFilter = "";
  _appDraftId = draftData ? (draftData.id || 0) : 0;
  const area = document.getElementById("appArea");
  const schema = f.form_schema || [];
  const byGroup = g => schema.filter(fld => fld.group === g);
  const secCard = (title, icon, html) => `<div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:14px">
    <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px">
      <span style="width:4px;height:15px;background:#2563eb;border-radius:2px;margin-right:8px"></span>${icon} ${title}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:2px 20px">${html}</div></div>`;
  const personFlds = byGroup("person"), jobFlds = byGroup("job"), salFlds = byGroup("salary"), matFlds = byGroup("material");
  const uname = (state.user && (state.user.name || state.user.username)) || "";
  const uproj = (state.user && state.user.project_name) || "";
  const uacc = (state.user && state.user.username) || "";
  area.innerHTML = `<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
      <button class="btn sm" onclick="appTab('start')">← 返回流程列表</button>
      <b style="font-size:15px">${esc(f.name)}</b>
      <span class="tag blue">发起审批</span>
      ${draftData ? `<span class="tag" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa">草稿：${esc(draftData.flow_no || "")}</span>` : ""}</div>
    <div style="max-width:900px">
      <div style="display:flex;align-items:center;gap:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:9px 16px;margin-bottom:14px;font-size:13px;flex-wrap:wrap">
        <span style="color:#1d4ed8;font-weight:600">🧑 发起人：${esc(uname)}</span>
        <span style="color:#6b7280;font-size:12px">账号 ${esc(uacc)} · ${esc(uproj)}</span>
        <span style="margin-left:auto;font-size:11.5px;color:#2563eb;background:#dbeafe;border-radius:10px;padding:1px 10px">自动获取，无需填写</span>
      </div>
      ${personFlds.length ? secCard("人员信息", "👤", personFlds.map(fld => appFieldHtml(fld)).join("")) : ""}
      ${jobFlds.length ? secCard("入职信息", "📋", jobFlds.map(fld => appFieldHtml(fld)).join("")) : ""}
      ${salFlds.length ? secCard("薪酬信息", "💰", salFlds.map(fld => appFieldHtml(fld)).join("")) : ""}
      ${matFlds.length ? `<div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:14px">
        <div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:6px">
          <span style="width:4px;height:15px;background:#6366f1;border-radius:2px;margin-right:8px"></span>📎 材料清单（材料须上传完成后提交）</div>
        <div style="font-size:12px;color:#9ca3af;margin-bottom:8px">支持 pdf/jpg/png，单个不超过 5MB</div>
        ${matFlds.map(fld => appFieldHtml(fld)).join("")}</div>` : ""}
      <div class="row" style="margin-top:4px;padding-bottom:16px">
        <button class="btn primary" onclick="appSubmitForm('${f.flow_key}')">提交审批</button>
        <button class="btn" onclick="appSaveDraft()">💾 保存草稿</button>
        <button class="btn" onclick="appTab('start')">取消</button>
      </div>
    </div>`;
  schema.filter(x => x.type === "attachment").forEach(x => {
    const inp = document.getElementById("af_" + x.key);
    if (inp) inp.addEventListener("change", ev => appOnAttachChange(ev, x.key));
  });
  appBindPerf();
  appCalcPerf();
  if (draftData && draftData.form_data) {
    const fd = draftData.form_data;
    schema.forEach(fld => {
      const key = fld.key;
      if (fld.type === "multi") {
        setTimeout(() => { document.querySelectorAll(`input[name="af_${key}"]`).forEach(cb => { if ((fd[key] || []).includes(cb.value)) cb.checked = true; }); }, 0);
      } else if (fld.type === "person") {
        const hid = document.getElementById("af_" + key + "_name");
        if (hid) hid.value = fd[key] || "";
        const sel = document.getElementById("af_" + key);
        if (sel && fd[key + "_id"]) sel.value = fd[key + "_id"];
      } else if (fld.type === "attachment") {
        const h = document.getElementById("af_" + key + "_val");
        if (h && fd[key]) { h.value = fd[key]; const tag = document.getElementById("af_" + key + "_tag"); if (tag) { tag.style.background = "#e7f7ef"; tag.style.color = "#15803d"; tag.textContent = "已上传"; } }
      } else {
        const el = document.getElementById("af_" + key);
        if (el) el.value = (fd[key] != null ? fd[key] : "");
      }
    });
    appCalcPerf();
  }
}
function appBindPerf() {
  ["af_fixed_monthly", "af_base_salary"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener("input", appCalcPerf);
  });
}
function appCalcPerf() {
  if (!_appCreateFlow) return;
  const schema = _appCreateFlow.form_schema || [];
  const fv = parseFloat((document.getElementById("af_fixed_monthly") || {}).value || 0) || 0;
  const bv = parseFloat((document.getElementById("af_base_salary") || {}).value || 0) || 0;
  const perf = fv - bv;
  schema.filter(x => x.type === "perf").forEach(x => {
    const el = document.getElementById("af_" + x.key);
    if (el) el.innerHTML = perf > 0 ? (perf.toFixed(0) + " 元") : "—";
  });
}
// 按项目取组织架构下已设立的部门（末级部门名，去重）；proj 为空返回空（必须先选项目）
function appDeptNameList(proj) {
  const out = []; const seen = new Set();
  if (!proj) return out;
  for (const n of orgLeaves()) {
    const pn = orgProjectOf(n.id);
    if (!pn || pn.name !== proj) continue;
    const name = (n.path || n.name || "").split("/").filter(Boolean).pop() || n.name;
    if (name && !seen.has(name)) { seen.add(name); out.push(name); }
  }
  return out;
}
function appDeptOptionsHtml(proj) {
  const list = appDeptNameList(proj);
  return `<option value="">${proj ? "请选择部门" : "请先选择项目"}</option>` + list.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join("");
}
function appFieldHtml(f) {
  const key = f.key, req = f.required ? '<span style="color:#dc2626">*</span>' : "";
  const ro = f.readonly ? ' <span style="color:#9ca3af;font-size:11px;font-weight:400">(自动带出)</span>' : "";
  const label = `<div style="font-size:13px;color:#374151;font-weight:600;margin-bottom:3px">${esc(f.label || key)} ${req}${ro}</div>`;
  const type = f.type || "text";
  if (type === "textarea") return `<div style="margin-bottom:12px">${label}<textarea id="af_${key}" rows="2" style="width:100%"></textarea></div>`;
  if (type === "select" || type === "radio") {
    const opts = (f.options || []).map(o => typeof o === "string" ? o : (o.value || o.label || "")).map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join("");
    return `<div style="margin-bottom:12px">${label}<select id="af_${key}" ${f.readonly ? "disabled" : ""} style="min-width:200px;${f.readonly ? "background:#f3f4f6;" : ""}"><option value="">请选择</option>${opts}</select></div>`;
  }
  if (type === "multi") {
    const opts = (f.options || []).map(o => typeof o === "string" ? o : (o.value || o.label || "")).map(v =>
      `<label style="margin-right:12px;font-size:13px"><input type="checkbox" name="af_${key}" value="${esc(v)}"> ${esc(v)}</label>`).join("");
    return `<div style="margin-bottom:12px">${label}<div>${opts}</div></div>`;
  }
  if (type === "person") {
    const hasProj = (_appCreateFlow && _appCreateFlow.form_schema || []).some(x => x.type === "project");
    const filter = window._appStaffFilter || "";
    const list = hasProj ? (filter ? _appStaffOpts.filter(s => s.project === filter) : []) : _appStaffOpts;
    const opts = list.map(s => `<option value="${esc(s.id)}" data-name="${esc(s.name)}">${esc(s.name)}（${esc(s.project)}${s.position ? "·" + esc(s.position) : ""}）</option>`).join("");
    const disabled = hasProj && !filter;
    return `<div style="margin-bottom:12px">${label}
      <select id="af_${key}" data-person="1" ${disabled ? "disabled" : ""} style="min-width:220px" onchange="appPersonSync('${key}')">
        <option value="">${disabled ? "请先选择项目" : "请选择人员"}</option>
        ${opts}
      </select>
      <input type="hidden" id="af_${key}_name" value="">
      <div style="font-size:11px;color:#9ca3af;margin-top:2px">${disabled ? "请先选择上方项目，再选择本项目人员" : "选择人员后自动带出档案信息"}</div></div>`;
  }
  if (type === "project") {
    const opts = (state.projects || []).map(p => typeof p === "string" ? p : (p.name || p.value || "")).map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join("");
    const hasPerson = (_appCreateFlow && _appCreateFlow.form_schema || []).some(x => x.type === "person");
    const hint = hasPerson ? '<div style="font-size:11px;color:#9ca3af;margin-top:2px">选择项目后，下方人员列表将仅显示该项目人员</div>' : "";
    return `<div style="margin-bottom:12px">${label}<select id="af_${key}" style="min-width:200px" onchange="appProjectChanged(this)"><option value="">请选择项目</option>${opts}</select>${hint}</div>`;
  }
  if (type === "department") {
    const proj = window._appStaffFilter || "";
    const disabled = !proj;
    return `<div style="margin-bottom:12px">${label}
      <select id="af_${key}" data-dept="1" ${disabled ? "disabled" : ""} style="min-width:200px">${appDeptOptionsHtml(proj)}</select>
      <div style="font-size:11px;color:#9ca3af;margin-top:2px">${disabled ? "请先选择上方项目，再选择该项目下已设立的部门" : "仅显示所选项目下已设立的部门"}</div></div>`;
  }
  if (type === "perf") return `<div style="margin-bottom:12px">${label}<div id="af_${key}" style="font-size:15px;font-weight:700;color:#dc2626;padding-top:2px">—</div><div style="font-size:11px;color:#9ca3af">= 核定月薪 − 基本工资（自动计算）</div></div>`;
  if (type === "attachment") return `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 2px;border-bottom:1px dashed #f0f2f6">
      <div style="min-width:0;padding-right:8px">
        <div style="font-size:13.5px;font-weight:500;color:#1f2937">${esc(f.label || key)} ${req}</div>
        <div style="font-size:11.5px;color:#9ca3af;margin-top:2px">${esc(f.hint || "支持 pdf/jpg/png")}</div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
        <span id="af_${key}_tag" style="font-size:11.5px;padding:2px 10px;border-radius:14px;background:#f3f4f6;color:#9ca3af;white-space:nowrap">未上传</span>
        <label style="display:inline-block;cursor:pointer;font-size:11.5px;color:#2563eb;border:1px solid #2563eb;padding:2px 10px;border-radius:14px;background:#fff;white-space:nowrap">上传
          <input type="file" id="af_${key}" style="display:none">
        </label>
      </div>
      <input type="hidden" id="af_${key}_val">
    </div>`;
  if (type === "date") return `<div style="margin-bottom:12px">${label}<input type="date" id="af_${key}" ${f.readonly ? "disabled" : ""} style="${f.readonly ? "background:#f3f4f6;" : ""}"></div>`;
  if (type === "number" || type === "amount") return `<div style="margin-bottom:12px">${label}<input type="number" step="0.01" id="af_${key}" ${f.readonly ? "disabled" : ""} style="min-width:180px;${f.readonly ? "background:#f3f4f6;" : ""}"></div>`;
  return `<div style="margin-bottom:12px">${label}<input type="text" id="af_${key}" style="width:100%;max-width:360px"${f.placeholder ? ` placeholder="${esc(f.placeholder)}"` : ""}></div>`;
}
async function appOnAttachChange(ev, key) {
  const file = ev.target.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append("file", file);
  try {
    const d = await api("/api/approval/upload", { form: fd, method: "POST" });
    const hv = document.getElementById("af_" + key + "_val");
    if (hv) hv.value = d.url;
    const tag = document.getElementById("af_" + key + "_tag");
    if (tag) { tag.style.background = "#e7f7ef"; tag.style.color = "#15803d"; tag.textContent = "已上传"; }
    toast("附件已上传");
  } catch (e) { toast(e.message, false); }
}
function appPersonSync(key) {
  const sel = document.getElementById("af_" + key);
  const hid = document.getElementById("af_" + key + "_name");
  if (!sel || !hid) return;
  if (!sel.value) {
    hid.value = "";
    const schema = (_appCreateFlow && _appCreateFlow.form_schema) || [];
    schema.forEach(fld => {
      if (fld.auto && fld.type !== "person") { const t = document.getElementById("af_" + fld.key); if (t) t.value = ""; }
    });
    appCalcPerf();
    return;
  }
  const s = (window._appStaffMap || {})[sel.value];
  if (!s) { hid.value = sel.selectedOptions[0] ? (sel.selectedOptions[0].getAttribute("data-name") || "") : ""; return; }
  hid.value = s.name || "";
  const schema = (_appCreateFlow && _appCreateFlow.form_schema) || [];
  schema.forEach(fld => {
    if (!fld.auto || fld.type === "person") return;
    const target = document.getElementById("af_" + fld.key);
    if (!target) return;
    let v = (s[fld.auto] !== undefined && s[fld.auto] !== null) ? s[fld.auto] : "";
    if (fld.auto === "name") v = s.name || "";
    target.value = v;
  });
  // 选中人员后同步回填项目（如已存在项目字段且未选/不匹配）
  schema.forEach(fld => {
    if (fld.type === "project") {
      const psel = document.getElementById("af_" + fld.key);
      if (psel && s.project) psel.value = s.project;
    }
  });
  appCalcPerf();
}
function appProjectChanged(sel) {
  const proj = (sel && sel.value) || "";
  window._appStaffFilter = proj;
  document.querySelectorAll("#appArea select[data-person]").forEach(psel => {
    const id = psel.id;
    const opts = proj ? _appStaffOpts.filter(s => s.project === proj).map(s => `<option value="${esc(s.id)}" data-name="${esc(s.name)}">${esc(s.name)}（${esc(s.project)}${s.position ? "·" + esc(s.position) : ""}）</option>`).join("") : "";
    psel.innerHTML = `<option value="">${proj ? "请选择人员" : "请先选择项目"}</option>` + opts;
    psel.disabled = !proj;
    const hid = document.getElementById(id + "_name");
    if (hid) hid.value = "";
    const key = id.replace("af_", "");
    appPersonSync(key);
  });
  // 同步重建所有"部门(按项目联动)"下拉
  document.querySelectorAll("#appArea select[data-dept]").forEach(dsel => {
    const prev = dsel.value;
    dsel.innerHTML = appDeptOptionsHtml(proj);
    dsel.disabled = !proj;
    if (prev && [...dsel.options].some(o => o.value === prev)) dsel.value = prev; else dsel.value = "";
  });
}
function appPersonInput(key) {
  const inp = document.getElementById("af_" + key + "_input");
  const hid = document.getElementById("af_" + key + "_name");
  if (inp && hid) hid.value = inp.value.trim();
}
async function appSubmitForm(fk) {
  const f = _appCreateFlow;
  if (!f) return;
  const { form, missing } = appCollectForm(f);
  if (missing.length) { toast("请填写：" + missing.join("、"), false); return; }
  try {
    const draftId = _appDraftId || 0;
    if (draftId) {
      await api("/api/approval/draft_submit", { body: { id: draftId } });
    } else {
      await api("/api/approval/create", { body: { flow_key: fk, form_data: form } });
    }
    toast("审批单已提交，等待审批");
    _appCreateFlow = null; _appDraftId = 0;
    appTab("mine");
  } catch (e) { toast(e.message, false); }
}
async function appSaveDraft() {
  const f = _appCreateFlow;
  if (!f) return;
  const { form } = appCollectForm(f, true);
  try {
    const d = await api("/api/approval/draft_save", { body: { draft_id: _appDraftId || 0, flow_key: f.flow_key, form_data: form } });
    _appDraftId = d.draft_id;
    toast(d.flow_no ? ("草稿已保存，流程号 " + d.flow_no) : "草稿已保存");
  } catch (e) { toast(e.message, false); }
}
function appCollectForm(f, skipRequired) {
  const form = {};
  const missing = [];
  for (const fld of f.form_schema) {
    const key = fld.key;
    if (fld.type === "perf") continue;
    let val = "";
    if (fld.type === "multi") val = [...document.querySelectorAll(`input[name="af_${key}"]:checked`)].map(i => i.value);
    else if (fld.type === "person") {
      const hid = document.getElementById("af_" + key + "_name");
      const sel = document.getElementById("af_" + key);
      val = hid ? hid.value : (sel && sel.selectedOptions[0] ? (sel.selectedOptions[0].getAttribute("data-name") || "") : "");
      if (sel && sel.value) form[key + "_id"] = sel.value;
    }
    else if (fld.type === "attachment") {
      const hv = document.getElementById("af_" + key + "_val");
      val = hv ? hv.value : "";
    }
    else val = document.getElementById("af_" + key) ? document.getElementById("af_" + key).value : "";
    form[key] = val;
    if (!skipRequired && fld.required && (val === "" || (Array.isArray(val) && !val.length))) missing.push(fld.label || key);
  }
  return { form, missing };
}

async function appListApprove(el) {
  const d = await api("/api/approval/list?scope=approve");
  if (!d.items.length) { el.innerHTML = '<div class="msg info">暂无待您审批的单据</div>'; return; }
  el.innerHTML = appInstTable(d.items, "approve");
}
async function appListMine(el) {
  el.innerHTML = `<div class="row" style="margin-bottom:10px">
      <button class="btn sm ${_appMineScope === "mine" ? "primary" : ""}" onclick="appMineScope('mine')">我发起的</button>
      <button class="btn sm ${_appMineScope === "done" ? "primary" : ""}" onclick="appMineScope('done')">已办/已结束</button>
    </div><div id="appMineList">加载中...</div>`;
  await appMineScope(_appMineScope || "mine");
}
let _appMineScope = "mine";
async function appMineScope(scope) {
  _appMineScope = scope;
  const el = document.getElementById("appMineList");
  if (!el) return;
  el.innerHTML = "加载中...";
  const d = await api("/api/approval/list?scope=" + scope);
  if (!d.items.length) { el.innerHTML = '<div class="msg info">暂无记录</div>'; return; }
  el.innerHTML = appInstTable(d.items, "mine");
}
let _appFlowScope = "all";
async function appListFlowMgt(el) {
  el.innerHTML = `<div class="row" style="margin-bottom:10px">
      ${[["all","全部流程"],["pending","已发起"],["done","已完结"],["void","已废弃"]].map(s =>
        `<button class="btn sm ${_appFlowScope===s[0]?"primary":""}" onclick="appFlowScope('${s[0]}')">${s[1]}</button>`).join("")}
    </div>
    <div class="hint" style="margin-bottom:10px">流程管理展示系统内全部审批流程（管理员）。已发起=进行中；已完结=已通过+已驳回；已废弃=已撤回+已作废。</div>
    <div id="appFlowList">加载中...</div>`;
  await appFlowScope(_appFlowScope || "all");
}
async function appFlowScope(scope) {
  _appFlowScope = scope;
  const el = document.getElementById("appFlowList");
  if (!el) return;
  el.innerHTML = "加载中...";
  let q = "scope=all";
  if (scope === "pending") q = "scope=all&status=pending";
  else if (scope === "done") q = "scope=all&status=approved,rejected";
  else if (scope === "void") q = "scope=all&status=withdrawn,voided";
  try {
    const d = await api("/api/approval/list?" + q);
    if (!d.items.length) { el.innerHTML = '<div class="msg info">暂无记录</div>'; return; }
    el.innerHTML = appInstTable(d.items, "flowmgt");
  } catch (e) { el.innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
}
function appInstTable(items, mode) {
  const stMap = { pending: ["审批中", "#f59e0b"], approved: ["已通过", "#16a34a"], rejected: ["已驳回", "#dc2626"], withdrawn: ["已撤回", "#6b7280"], voided: ["已作废", "#9ca3af"] };
  const canSend = it => mode === "flowmgt" && (it.status === "approved" || it.status === "rejected");
  return `<div class="table-wrap"><table class="tb"><thead><tr>
    <th>流程号</th><th>流程</th><th>标题</th><th>员工状态</th><th>进度</th><th>发起人</th><th>时间</th><th>操作</th></tr></thead><tbody>
    ${items.map(it => {
      const st = stMap[it.status] || ["未知", "#6b7280"];
      const op = `<button class="btn sm primary" onclick="appOpenDetail(${it.id})">详情</button>` +
        (canSend(it) ? `<button class="btn sm" style="margin-left:6px" onclick="appSendPanel(${it.id})">📤 发送</button>` : "");
      return `<tr><td><b style="color:#2563eb;font-size:12.5px">${esc(it.flow_no || "-")}</b></td>
        <td>${esc(it.flow_name)}</td><td>${esc(it.title)}</td>
        <td><span class="tag" style="color:${st[1]};border-color:${st[1]}">${st[0]}</span></td>
        <td>${it.current_index}/${it.total_nodes}</td>
        <td>${esc(it.applicant)}</td><td>${esc((it.time || "").replace("T", " "))}</td>
        <td>${op}</td></tr>`;
    }).join("")}
  </tbody></table></div>`;
}

/* ---- 详情与审批操作 ---- */
async function appOpenDetail(id) {
  try {
    const d = await api("/api/approval/" + id);
    renderAppDetailModal(d.instance, d.me);
  } catch (e) { toast(e.message, false); }
}
function appValHtml(fld, val) {
  if (fld.type === "attachment" && val) return `<a href="${esc(val)}" target="_blank" style="color:#2563eb">查看附件 →</a>`;
  if (Array.isArray(val)) return esc(val.join("、"));
  return esc(val === "" || val == null ? "-" : val);
}
function renderAppDetailModal(inst, me) {
  const stMap = { pending: ["审批中", "#d97706"], approved: ["已通过", "#15803d"], rejected: ["已驳回", "#dc2626"], withdrawn: ["已撤回", "#6b7280"], voided: ["已作废", "#9ca3af"] };
  const st = stMap[inst.status] || ["未知", "#6b7280"];
  const schema = inst.form_schema || [];
  const byGroup = g => schema.filter(f => f.group === g);
  const personFlds = byGroup("person"), jobFlds = byGroup("job"), salFlds = byGroup("salary"), matFlds = byGroup("material");
  const fd = inst.form_data || {};
  const perfV = (parseFloat(fd.fixed_monthly) || 0) - (parseFloat(fd.base_salary) || 0);
  const fItem = (lb, v) => `<div style="display:flex;min-width:0"><div style="width:86px;color:#8a93a3;font-size:12.5px;padding-top:2px;flex-shrink:0">${lb}</div><div style="font-size:13.5px;color:#111827;font-weight:500;word-break:break-all">${v}</div></div>`;
  const gridHtml = flds => flds.length ? `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px 20px;background:#fafbfc;border:1px solid #eef1f5;border-radius:10px;padding:14px 18px;margin-bottom:12px">${flds.map(fld => {
    if (fld.type === "perf") return fItem(fld.label || fld.key, perfV > 0 ? `<span style="color:#dc2626;font-weight:700">${perfV.toFixed(0)} 元</span>` : "—");
    return fItem(fld.label || fld.key, appValHtml(fld, fd[fld.key]));
  }).join("")}</div>` : "";
  const secTitle = (bar, icon, title) => `<div style="display:flex;align-items:center;font-size:14px;font-weight:600;color:#111827;margin-bottom:12px"><span style="width:4px;height:15px;background:${bar};border-radius:2px;margin-right:8px"></span>${icon} ${title}</div>`;
  const stepsHtml = `<div class="appsteps">${(inst.node_chain || []).map((nd, i) => {
    const done = i < (inst.current_index || 0) || inst.status === "approved";
    const isCur = i === (inst.current_index || 0) && inst.status === "pending";
    const who = (nd.approvers || []).map(a => a.name).join("、");
    return `<div class="appstep ${done ? "done" : (isCur ? "cur" : "")}">
      <div class="dot">${done ? "✓" : (isCur ? "●" : (i + 1))}</div>
      <div style="font-size:12px;color:${done ? "#15803d" : (isCur ? "#b45309" : "#6b7280")};margin-top:7px;font-weight:${isCur ? "600" : "500"};white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(nd.name)}</div>
      <div style="font-size:10.5px;color:#9ca3af;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(who)}</div></div>`;
  }).join("")}</div>`;
  const matHtml = matFlds.length ? matFlds.map(fld => {
    const v = fd[fld.key];
    const fname = v ? String(v).split("/").pop() : "";
    return `<div style="display:flex;justify-content:space-between;align-items:center;padding:11px 4px;border-bottom:1px solid #f0f2f6">
      <div style="min-width:0;padding-right:8px"><div style="font-size:13.5px;font-weight:500;color:#1f2937">${esc(fld.label || fld.key)}</div>
      <div style="font-size:11.5px;color:#9ca3af;margin-top:2px">${esc(fld.hint || "")}</div></div>
      <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:10px">
        ${v ? `<span style="font-size:11.5px;padding:2px 10px;border-radius:14px;background:#e7f7ef;color:#15803d;white-space:nowrap">已上传</span><a href="${esc(v)}" target="_blank" style="font-size:11.5px;color:#2563eb;white-space:nowrap">${esc(fname || "查看附件")} →</a>` : `<span style="font-size:11.5px;padding:2px 10px;border-radius:14px;background:#fff7ed;color:#c2410c;white-space:nowrap">未上传</span>`}
      </div></div>`;
  }).join("") : '<div style="color:#9ca3af;font-size:12.5px;padding:8px 4px">本流程无需上传材料</div>';
  const recHtml = (() => {
    const items = [];
    (inst.node_chain || []).forEach((nd, i) => {
      (nd.approvers || []).forEach(a => {
        const nodeOps = (inst.opinions || {})[(nd.nodeId !== undefined && nd.nodeId !== null ? nd.nodeId : i)] || [];
        const op = nodeOps.find(o => o.name === a.name);
        items.push({ node: nd.name, name: a.name, state: a.state, op });
      });
    });
    if (!items.length) return '<div style="color:#9ca3af;font-size:12.5px">暂无审批记录</div>';
    return items.map(it => {
      const pass = it.state === "approved";
      const t = it.op && it.op.time ? String(it.op.time).replace("T", " ").slice(0, 16) : "";
      return `<div style="position:relative;padding:0 0 16px 20px;margin-left:5px;border-left:2px solid #e5e9f0">
        <div style="position:absolute;left:-5px;top:4px;width:12px;height:12px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px ${pass ? "#b5e6cc" : "#c7d2fe"};background:${pass ? "#16a34a" : "#6366f1"}"></div>
        <div style="font-size:11px;color:#9ca3af;margin-bottom:2px">${esc(it.node)} · ${esc(it.name)}</div>
        <div style="font-size:12.5px"><b style="color:#1f2937">${esc(it.name)}</b> <span style="color:${pass ? "#15803d" : "#2563eb"};font-weight:600">${pass ? "同意" : "待审批"}</span>${t ? ' <span style="color:#9ca3af;font-size:11px">· ' + t + "</span>" : ""}</div>
        ${it.op && it.op.opinion ? `<div style="color:#6b7280;font-size:12px;margin-top:3px;background:#fff;padding:5px 9px;border-radius:6px;border:1px solid #eef1f5;display:inline-block">${esc(it.op.opinion)}</div>` : ""}
      </div>`;
    }).join("");
  })();
  const isCurApprover = inst.status === "pending" && (inst.node_chain[inst.current_index] || {}).approvers && (inst.node_chain[inst.current_index].approvers).some(a => a.accountId === me.id && a.state !== "approved");
  const isApplicant = inst.applicant_id === me.id;
  const canWithdraw = isApplicant && inst.status === "pending" && !(inst.node_chain[inst.current_index] || {}).approvers.some(a => a.state === "approved");
  modal(`<style>
    .appsteps{display:flex;padding:6px 4px 12px}
    .appstep{flex:1;text-align:center;position:relative;min-width:0;padding:0 4px}
    .appstep .dot{width:26px;height:26px;border-radius:50%;margin:0 auto;line-height:24px;font-size:13px;color:#fff;position:relative;z-index:2;box-shadow:0 0 0 2px #d8dee9;background:#e5e9f0}
    .appstep::before{content:"";position:absolute;top:13px;left:-50%;width:100%;height:2px;background:#e5e9f0;z-index:1}
    .appstep:first-child::before{display:none}
    .appstep.done .dot{background:#16a34a;box-shadow:0 0 0 2px #b5e6cc}
    .appstep.cur .dot{background:#f59e0b;box-shadow:0 0 0 2px #fcd9a8}
    .appstep.done::before,.appstep.cur::before{background:#b5e6cc}
  </style>
  <div style="min-width:760px;max-width:920px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <b style="font-size:16px;color:#111827">${esc(inst.title)}</b>
      <span class="tag" style="color:${st[1]};border-color:${st[1]}">${st[0]}</span></div>
    <div style="font-size:12px;color:#9ca3af;margin-bottom:4px">流程号：<b style="color:#2563eb">${esc(inst.flow_no || "-")}</b> · 流程：${esc(inst.flow_name)} · ${esc(inst.project || "")}</div>
    <div style="font-size:12px;color:#9ca3af;margin-bottom:12px">🧑 发起人：${esc(inst.applicant)} · 发起时间：${esc((inst.time || "").replace("T", " "))}</div>
    <div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:10px 18px;margin-bottom:14px">${stepsHtml}</div>
    ${personFlds.length ? `<div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:12px">${secTitle("#2563eb", "👤", "人员信息")}${gridHtml(personFlds)}</div>` : ""}
    ${jobFlds.length ? `<div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:12px">${secTitle("#2563eb", "📋", "入职信息")}${gridHtml(jobFlds)}</div>` : ""}
    ${salFlds.length ? `<div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px;margin-bottom:12px">${secTitle("#2563eb", "💰", "薪酬信息")}${gridHtml(salFlds)}</div>` : ""}
    <div style="display:flex;gap:18px;margin-bottom:14px;flex-wrap:wrap">
      <div style="flex:1;min-width:300px;background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px">${secTitle("#6366f1", "📎", "材料清单")}<div>${matHtml}</div></div>
      <div style="flex:1;min-width:300px;background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 18px">${secTitle("#6366f1", "📝", "审批记录")}<div>${recHtml}</div></div>
    </div>
    ${isCurApprover ? `<div style="margin-top:4px;border-top:1px solid #eef0f3;padding-top:12px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
      <div style="flex:1;min-width:220px"><div style="font-size:13px;font-weight:600;margin-bottom:6px">您的审批意见</div>
      <textarea id="appOpinion" rows="2" placeholder="填写审批意见（驳回时必填）" style="width:100%"></textarea></div>
      <div class="row" style="margin:0">
        <button class="btn success" onclick="appAct(${inst.id},'approve')">✓ 通过</button>
        <button class="btn" style="background:#fff;color:#dc2626;border-color:#f3c1c2" onclick="appAct(${inst.id},'reject')">✕ 驳回</button>
      </div></div>` : ""}
    ${isApplicant && (inst.status === "pending" || inst.status === "rejected") ? `<div class="row" style="margin-top:14px;border-top:1px solid #eef0f3;padding-top:12px">
      ${inst.status === "pending" && canWithdraw ? `<button class="btn warn" onclick="appVoidOr('${inst.id}','withdraw')">↩ 撤回</button>` : ""}
      ${inst.status === "rejected" ? `<button class="btn primary" onclick="appReopen(${inst.id})">✎ 修改并重新提交</button>` : ""}
      <button class="btn" onclick="appVoidOr('${inst.id}','void')">🗑 作废</button>
    </div>` : ""}
    ${(inst.status === "approved" || inst.status === "rejected") ? `<div class="row" style="margin-top:14px;border-top:1px solid #eef0f3;padding-top:12px">
      <button class="btn" style="background:#fff;color:#7c3aed;border-color:#c4b5fd" onclick="appSendPanel(${inst.id})">📤 发送给人员查看</button>
      <span style="font-size:12px;color:#9ca3af">可将此已完结流程发送给人员（自动匹配账号），对方在我的待办-收件中查看</span>
    </div>` : ""}
  </div>`, 900);
}
async function appAct(id, act) {
  const opinion = document.getElementById("appOpinion") ? document.getElementById("appOpinion").value.trim() : "";
  try {
    const d = await api("/api/approval/" + act, { body: { id, opinion } });
    closeModal();
    toast(act === "approve" ? "已同意" : "已驳回");
    loadUnread();
    appTab(_appTab);
  } catch (e) { toast(e.message, false); }
}
async function appVoidOr(id, act) {
  try {
    await api("/api/approval/" + act, { body: { id } });
    closeModal();
    toast(act === "withdraw" ? "已撤回" : "已作废");
    appTab(_appTab);
  } catch (e) { toast(e.message, false); }
}
async function appReopen(id) {
  try {
    const d = await api("/api/approval/" + id);
    const inst = d.instance;
    if (!_appStaffOpts.length) {
      try { const s = await api("/api/org/staff-options"); _appStaffOpts = s.staff || []; } catch (e) { _appStaffOpts = []; }
    }
    _appCreateFlow = { flow_key: inst.flow_key, name: inst.flow_name, form_schema: inst.form_schema };
    closeModal();
    const area = document.getElementById("appArea");
    area.innerHTML = `<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <button class="btn sm" onclick="appTab('mine')">← 返回</button>
        <b style="font-size:14px">修改并重新提交：${esc(inst.title)}</b></div>
      <div class="app-form" id="appForm"></div>
      <div class="row" style="margin-top:16px">
        <button class="btn primary" onclick="appResubmit(${id})">提交审批</button>
        <button class="btn" onclick="appTab('mine')">取消</button></div>`;
    area.querySelector("#appForm").innerHTML = inst.form_schema.map(fld => {
      const html = appFieldHtml(fld);
      const key = fld.key, val = inst.form_data[key];
      if (fld.type === "multi") { setTimeout(() => { document.querySelectorAll(`input[name="af_${key}"]`).forEach(cb => { if ((val || []).includes(cb.value)) cb.checked = true; }); }, 0); return html; }
      const holder = document.createElement("div"); holder.innerHTML = html;
      const el = holder.querySelector("#af_" + key);
      if (el) el.value = val || "";
      if (fld.type === "attachment") { const h = holder.querySelector("#af_" + key + "_val"); if (h) h.value = val || ""; }
      return holder.innerHTML;
    }).join("");
    inst.form_schema.filter(x => x.type === "attachment").forEach(x => {
      const inp = document.getElementById("af_" + x.key);
      if (inp) inp.addEventListener("change", ev => appOnAttachChange(ev, x.key));
    });
  } catch (e) { toast(e.message, false); }
}
async function appResubmit(id) {
  const f = _appCreateFlow;
  if (!f) return;
  const form = {}; const missing = [];
  for (const fld of f.form_schema) {
    const key = fld.key;
    let val = "";
    if (fld.type === "multi") val = [...document.querySelectorAll(`input[name="af_${key}"]:checked`)].map(i => i.value);
    else if (fld.type === "person") {
      const sel = document.getElementById("af_" + key);
      const hid = document.getElementById("af_" + key + "_name");
      val = hid ? hid.value : (sel && sel.value ? (sel.selectedOptions[0] ? sel.selectedOptions[0].getAttribute("data-name") || "" : "") : "");
      if (sel && sel.value && sel.value !== "__custom__") form[key + "_id"] = sel.value;
    }
    else if (fld.type === "attachment") {
      const hv = document.getElementById("af_" + key + "_val");
      val = hv ? hv.value : "";
    }
    else val = document.getElementById("af_" + key) ? document.getElementById("af_" + key).value : "";
    form[key] = val;
    if (fld.required && (val === "" || (Array.isArray(val) && !val.length))) missing.push(fld.label || key);
  }
  if (missing.length) { toast("请填写：" + missing.join("、"), false); return; }
  try {
    await api("/api/approval/resubmit", { body: { id, form_data: form } });
    toast("已重新提交");
    _appCreateFlow = null;
    appTab("mine");
  } catch (e) { toast(e.message, false); }
}

/* ---- 入职办理 ---- */
async function appListOnboard(el) {
  if (state.user.role !== "admin") { el.innerHTML = '<div class="msg info">入职办理由人力（管理员）负责</div>'; return; }
  const d = await api("/api/approval/onboard/list?status=pending");
  if (!d.items.length) { el.innerHTML = '<div class="msg info">暂无待办理的入职清单</div>'; return; }
  el.innerHTML = `<div class="table-wrap"><table class="tb"><thead><tr>
    <th>ID</th><th>员工</th><th>项目</th><th>办理进度</th><th>创建时间</th><th>操作</th></tr></thead><tbody>
    ${d.items.map(o => `<tr><td>#${o.id}</td><td>${esc(o.staff_name)}</td><td>${esc(o.project)}</td>
      <td>${o.done_count}/${o.total_count}</td><td>${esc((o.time || "").replace("T", " "))}</td>
      <td><button class="btn sm primary" onclick="appOnboardModal(${o.id})">办理</button></td></tr>`).join("")}
  </tbody></table></div>`;
}
async function appOnboardModal(id) {
  if (!ORG_TREE) { try { await loadOrgTree(); } catch (e) {} }
  const d = await api("/api/approval/onboard/" + id);
  const o = d.item;
  const fd = o.form_data || {};
  const sch = o.form_schema || [];
  const labelOf = k => { const f = sch.find(x => x.key === k); return f ? f.label : k; };
  const infoKeys = ["name", "project", "position", "department", "gender", "level", "phone", "id_card", "education", "ethnicity", "emergency_contact", "emergency_phone", "recruit_channel", "hire_date", "regular_date", "fixed_monthly", "base_salary"];
  const infoRows = infoKeys.filter(k => fd[k] !== "" && fd[k] != null).map(k => `<div style="flex:1 1 200px;min-width:180px;padding:8px 12px;background:#f8fafc;border-radius:8px">
      <div style="font-size:11.5px;color:#6b7280">${esc(labelOf(k))}</div>
      <div style="font-size:13.5px;font-weight:600;color:#1f2937;margin-top:2px">${esc(String(fd[k]))}</div></div>`).join("");
  const curDept = (o.extra && o.extra.department) || fd.department || "";
  let deptOpts = appDeptNameList(o.project);
  if (curDept && !deptOpts.includes(curDept)) deptOpts = [curDept, ...deptOpts];
  const deptOptsHtml = `<option value="">请选择部门</option>` + deptOpts.map(v => `<option value="${esc(v)}" ${v === curDept ? "selected" : ""}>${esc(v)}</option>`).join("");
  const att = sch.filter(x => x.type === "attachment");
  const attHtml = att.length ? att.map(f => {
    const v = fd[f.key];
    return `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 0;border-bottom:1px dashed #eef0f3;font-size:13px">
      <span style="font-weight:600">${esc(f.label || f.key)}</span>
      ${v ? `<span class="tag" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0">已上传</span><span style="font-size:12px;color:#6b7280">${esc(String(v))}</span>
      <label style="margin-left:auto;font-size:12.5px;color:#374151"><input type="checkbox" id="onb_verify_${esc(f.key)}" ${(o.extra && o.extra["verify_" + f.key]) ? "checked" : ""}> 已核验原件</label>`
        : `<span class="tag" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa">未上传</span>`}
    </div>`;
  }).join("") : '<div style="font-size:12.5px;color:#6b7280">该流程无附件材料</div>';
  modal(`<div style="width:880px;max-width:96vw">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
      <b style="font-size:16px">入职办理清单</b>
      <span class="tag blue">${esc(o.staff_name)}</span>
      <span style="font-size:12.5px;color:#6b7280">${esc(o.project)} · 创建于 ${esc((o.time || "").replace("T", " "))}</span>
    </div>
    <div class="app-sec-title">👤 员工信息（自动引用录用审批，如需修改请走人员档案编辑）</div>
    <div style="display:flex;flex-wrap:wrap;gap:8px">${infoRows}</div>
    <div class="app-sec-title" style="margin-top:14px">📋 办理清单（全部勾选后才可完成入职）</div>
    <div id="onbItems">${(o.items || []).map((it, i) => `<label style="display:flex;align-items:center;gap:8px;padding:7px 0;font-size:13px;border-bottom:1px dashed #eef0f3">
      <input type="checkbox" id="onb_${i}" ${it.done ? "checked" : ""} onchange="onbToggle(${i})"> ${esc(it.label)}</label>`).join("")}</div>
    <div class="app-sec-title" style="margin-top:14px">🪪 证件核验（录用时已上传材料自动带入，此处仅核验原件，无需重复上传）</div>
    ${attHtml}
    <div class="app-sec-title" style="margin-top:14px">📝 补录资料（建档到人事档案，与工资条/查询联动）</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <label style="font-size:12.5px">部门<select id="onb_dept" style="width:150px">${deptOptsHtml}</select></label>
      <label style="font-size:12.5px">证件号码<input type="text" id="onb_id_card" value="${esc((o.extra && o.extra.id_card) || fd.id_card || "")}" style="width:180px"></label>
      <label style="font-size:12.5px">银行卡号<input type="text" id="onb_bank" value="${esc((o.extra && o.extra.bank_card) || "")}" style="width:180px"></label>
      <label style="font-size:12.5px">试用期至<input type="date" id="onb_regular" value="${esc((o.extra && o.extra.regular_date) || "")}"></label>
      <label style="font-size:12.5px">劳动合同开始日期<input type="date" id="onb_contract_start" value="${esc((o.extra && o.extra.contract_start) || "")}"></label>
      <label style="font-size:12.5px">劳动合同结束日期<input type="date" id="onb_contract_end" value="${esc((o.extra && o.extra.contract_end) || "")}"></label>
    </div>
    <div style="margin-top:12px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12.5px;color:#1d4ed8">
      💡 开通系统账号将默认使用「普通员工」权限，初始密码 123456，由管理员在账号管理中后续调整。
    </div>
    <div class="row" style="margin-top:16px;border-top:1px solid #eef0f3;padding-top:12px">
      <button class="btn" onclick="appOnboardSave(${o.id},false)">保存办理进度</button>
      <label style="font-size:12.5px;color:#374151"><input type="checkbox" id="onb_open_account" ${o.form_data.open_account ? "checked" : ""}> 开通系统账号（初始密码123456）</label>
      <button class="btn success" onclick="appOnboardSave(${o.id},true)">✓ 完成入职（建档）</button>
    </div>
  </div>`, 900);
}
function onbToggle() {
  // checkbox 状态由浏览器原生自动维护，无需手动翻转；保留函数避免 onchange 报错
}
async function appOnboardSave(id, done) {
  const items = [];
  let i = 0;
  while (document.getElementById("onb_" + i)) {
    const cb = document.getElementById("onb_" + i);
    const label = cb.parentElement.textContent.trim();
    items.push({ key: "item" + i, label, done: cb.checked });
    i++;
  }
  const extra = {
    department: (document.getElementById("onb_dept") ? document.getElementById("onb_dept").value.trim() : ""),
    id_card: document.getElementById("onb_id_card").value.trim(),
    bank_card: document.getElementById("onb_bank").value.trim(),
    regular_date: document.getElementById("onb_regular").value || "",
    contract_start: document.getElementById("onb_contract_start").value || "",
    contract_end: document.getElementById("onb_contract_end").value || "",
  };
  document.querySelectorAll("[id^='onb_verify_']").forEach(cb => { extra["verify_" + cb.id.replace("onb_verify_", "")] = cb.checked; });
  const openAccount = document.getElementById("onb_open_account") ? document.getElementById("onb_open_account").checked : false;
  try {
    await api("/api/approval/onboard/save", { body: { id, items, extra } });
    if (done) {
      const r = await api("/api/approval/onboard/complete", { body: { id, open_account: openAccount } });
      closeModal();
      if (r.result && r.result.error) { toast(r.result.error, false); }
      else { toast("入职办理完成，已建档" + (r.result.account ? "并开通账号：" + r.result.account.username : "")); }
      appTab("onboard");
    } else {
      closeModal();
      toast("办理进度已保存");
    }
  } catch (e) { toast(e.message, false); }
}

/* ================= 审批权责设置（表单设计器 + 审批流设计器） ================= */
let _appFlows = [];
let _appFlow = null;
let _appFlowUsers = [];
let _appFlowRoles = [];
let _appFlowPositions = [];
let _appFlowNodes = [];
let _appFlowTab = "form";
let _appTreeSeq = 1000;
const APP_GROUPS = [
  { key: "person", name: "人员信息", icon: "👤" },
  { key: "job", name: "入职信息", icon: "📋" },
  { key: "salary", name: "薪酬信息", icon: "💰" },
  { key: "material", name: "材料清单", icon: "📎" }
];
const APP_FTYPE = { text: "单行文本", textarea: "多行文本", number: "数字", amount: "金额", date: "日期", select: "下拉选择", radio: "单选", multi: "多选", person: "选择人员", project: "选择项目", department: "部门(按项目联动)", attachment: "附件上传", perf: "绩效工资(自动)" };

async function settingsApprovalFlow(el) {
  el.innerHTML = `<div style="display:flex;gap:16px;flex-wrap:wrap">
      <div style="width:220px;flex:none">
        <div class="row" style="margin-bottom:8px"><button class="btn success sm" onclick="appFlowNew()">＋ 新建流程</button></div>
        <div id="afFlowList">加载中...</div>
      </div>
      <div style="flex:1;min-width:640px" id="afEditor">选择左侧流程进行编辑</div>
    </div>`;
  await appFlowLoad();
}
async function appFlowLoad() {
  const d = await api("/api/approval/flows");
  _appFlows = d.flows;
  const list = document.getElementById("afFlowList");
  if (!list) return;
  list.innerHTML = _appFlows.map(f => `<div class="rt-tab${_appFlow && _appFlow.flow_key === f.flow_key ? " active" : ""}" style="margin:0 0 6px;width:100%;text-align:left" onclick="appFlowEdit('${f.flow_key}')">
      ${esc(f.name)}${f.enabled ? "" : "（停用）"}</div>`).join("");
}
function appFlowNew() {
  _appFlow = { id: 0, flow_key: "", name: "", enabled: true, form_schema: [], flow_config: { nodes: {}, tree: { type: "chain", items: [] } } };
  _appFlowNodes = [];
  _appFlowTab = "form";
  appFlowRenderEditor();
}
function legacyToTree(f) {
  const cfg = f.flow_config || {};
  const items = [];
  const def = cfg.default && cfg.default.length ? cfg.default : _appFlowNodes.map(n => n.id);
  def.forEach(nid => { items.push({ id: "t" + (++_appTreeSeq), type: "approve", nodeId: nid }); });
  (cfg.branches || []).forEach(b => {
    const paths = [
      { id: "p" + (++_appTreeSeq), label: "命中条件", default: false, cond: { field: b.field || "", op: b.op || "eq", value: b.value || "" }, items: (b.nodes || []).map(nid => ({ id: "t" + (++_appTreeSeq), type: "approve", nodeId: nid })) },
      { id: "p" + (++_appTreeSeq), label: "默认", default: true, items: [] }
    ];
    items.push({ id: "t" + (++_appTreeSeq), type: "branch", cond: { field: b.field || "", op: b.op || "eq", value: b.value || "" }, paths });
  });
  return { type: "chain", items };
}
async function appFlowEdit(fk) {
  if (!_appFlowPositions.length) {
    try { const p = await api("/api/approval/positions"); _appFlowPositions = p.positions || []; } catch (e) { _appFlowPositions = []; }
  }
  if (!_appFlowUsers.length) {
    try { const u = await api("/api/users"); _appFlowUsers = u.users || []; } catch (e) { _appFlowUsers = []; }
  }
  const f = _appFlows.find(x => x.flow_key === fk);
  if (!f) return;
  _appFlow = JSON.parse(JSON.stringify(f));
  _appFlowNodes = Object.entries(_appFlow.flow_config.nodes || {}).map(([id, n]) => ({ id, name: n.name, mode: n.mode, approvers: n.approvers || [] }));
  if (!_appFlow.flow_config.tree) _appFlow.flow_config.tree = legacyToTree(_appFlow);
  _appFlowTab = "form";
  appFlowRenderEditor();
  const list = document.getElementById("afFlowList");
  if (list) list.innerHTML = _appFlows.map(x => `<div class="rt-tab${x.flow_key === fk ? " active" : ""}" style="margin:0 0 6px;width:100%;text-align:left" onclick="appFlowEdit('${x.flow_key}')">${esc(x.name)}${x.enabled ? "" : "（停用）"}</div>`).join("");
}
/* ---------------- 编辑器外壳 + Tab ---------------- */
function appFlowRenderEditor() {
  const ed = document.getElementById("afEditor");
  if (!ed || !_appFlow) return;
  const F = _appFlow;
  ed.innerHTML = `
    <div style="background:#fff;border:1px solid #eef0f3;border-radius:12px;padding:14px 16px;margin-bottom:12px">
      <div class="row" style="margin-bottom:6px;flex-wrap:wrap">
        <label class="fld" style="min-width:220px">流程名称 <input type="text" id="afName" value="${esc(F.name)}"></label>
        <label class="fld" style="min-width:150px"><input type="checkbox" id="afEnabled" ${F.enabled ? "checked" : ""}> 启用该流程</label>
        <div style="flex:1"></div>
        ${F.id ? `<button class="btn sm" onclick="appFlowToggle()">${F.enabled ? "停用" : "启用"}</button>` : ""}
        ${F.id ? `<button class="btn sm danger" onclick="appFlowDelete()">删除流程</button>` : ""}
      </div>
      <div style="font-size:12px;color:#9ca3af">表单字段与审批流保存后对所有账号生效。内部字段标识由系统自动生成，无需填写英文。</div>
    </div>
    <div style="display:flex;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-bottom:10px;background:#fff">
      <div class="af-tab${_appFlowTab === "form" ? " active" : ""}" onclick="appFlowTab('form')">📝 表单设计</div>
      <div class="af-tab${_appFlowTab === "flow" ? " active" : ""}" onclick="appFlowTab('flow')">🔄 审批流设计</div>
    </div>
    <div id="afTabBody"></div>
    <div class="row" style="margin-top:14px">
      <button class="btn primary" onclick="appFlowSave()">💾 保存流程配置</button>
      <button class="btn" onclick="appFlowRenderEditor()">取消</button>
    </div>`;
  appFlowTab(_appFlowTab);
}
function appFlowTab(t) {
  _appFlowTab = t;
  document.querySelectorAll(".af-tab").forEach(x => x.classList.toggle("active", x.textContent.includes(t === "form" ? "表单设计" : "审批流")));
  const body = document.getElementById("afTabBody");
  if (!body) return;
  body.innerHTML = t === "form" ? appFormDesignHtml() : appFlowDesignHtml();
}
/* ---------------- Tab1：表单设计（所见即所得） ---------------- */
function appFormDesignHtml() {
  const F = _appFlow;
  const panel = APP_GROUPS.map(g => `<div style="margin-bottom:14px">
      <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:7px">${g.icon} ${g.name}${g.key === "material" ? '<span style="font-weight:400;font-size:11px;color:#9ca3af">（仅附件上传）</span>' : ""}</div>
      <button class="btn sm" style="width:100%;border:1.5px dashed #bfdbfe;color:#2563eb;background:#fff" onclick="appFldModal(-1,'${g.key}')">＋ 添加字段</button>
    </div>`).join("");
  const groupsHtml = APP_GROUPS.map(g => {
    const flds = F.form_schema.map((f, i) => ({ f, i })).filter(x => (x.f.group || "person") === g.key);
    const fldsHtml = flds.map(({ f, i }) => appFldPreviewHtml(f, i)).join("");
    return `<div class="app-sec-title" style="margin-top:${g.key === "person" ? 0 : 14}px">${g.icon} ${g.name}</div>${fldsHtml || '<div style="color:#c0c6cf;font-size:12.5px;padding:8px 4px">暂无字段，点左侧「＋ 添加字段」添加</div>'}`;
  }).join("");
  return `<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
      <div style="width:230px;flex:none;background:#fafbfc;border:1px solid #eef0f3;border-radius:10px;padding:14px">
        <div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:4px">字段添加面板</div>
        <div style="font-size:11.5px;color:#9ca3af;margin-bottom:10px">选择要加入的分组，点击「＋ 添加字段」；在右侧预览区可编辑/删除/移动字段。</div>
        ${panel}
      </div>
      <div style="flex:1;min-width:380px">
        <div style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:#111827;margin-bottom:8px">发起表单预览<span style="font-size:11.5px;color:#9ca3af;font-weight:400">（与发起审批界面一致，鼠标悬停字段出现操作按钮）</span></div>
        <div style="border:1.5px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden">
          <div style="padding:10px 16px;font-size:14px;font-weight:700;color:#1f2937;border-bottom:1px solid #eee;background:#f8fafc">${esc(F.name)} · 发起表单预览</div>
          ${groupsHtml}
        </div>
      </div>
    </div>`;
}
function appFldPreviewHtml(f, idx) {
  const req = f.required ? '<span style="color:#dc2626">*</span>' : "";
  const isAttach = f.type === "attachment";
  const ctl = isAttach ? '<span style="color:#9ca3af">未上传 · 上传</span>' :
    (f.type === "select" || f.type === "radio" || f.type === "multi" || f.type === "project" || f.type === "department" || f.type === "person") ? '<span style="color:#9ca3af">请选择 ▾</span>' :
    (f.type === "date") ? '<span style="color:#9ca3af">请选择日期</span>' :
    (f.type === "amount" || f.type === "number") ? '<span style="color:#9ca3af">请输入金额/数字</span>' :
    '<span style="color:#9ca3af">请输入</span>';
  return `<div class="fp-row" style="display:flex;align-items:center;gap:10px;padding:9px 16px;border-top:1px solid #f7f7f7;position:relative">
      <span style="width:120px;flex:none;font-size:13px;color:#374151">${req}${esc(f.label || "")}${f.hint ? `<div style="font-size:11px;color:#9ca3af;font-weight:400">${esc(f.hint)}</div>` : ""}</span>
      <span style="flex:1;height:28px;border:1px solid #eef0f3;border-radius:6px;background:${isAttach ? "#fafafa" : "#fff"};display:flex;align-items:center;padding:0 10px;font-size:12.5px">${ctl}</span>
      <span style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:2px;display:none;white-space:nowrap" class="fp-opts">
        <button class="btn sm" style="border:none" onclick="appFldModal(${idx})">编辑</button>
        <button class="btn sm" style="border:none" onclick="appFldMove(${idx},-1)">↑</button>
        <button class="btn sm" style="border:none" onclick="appFldMove(${idx},1)">↓</button>
        <button class="btn sm" style="border:none;color:#dc2626" onclick="appFldDel(${idx})">删除</button>
      </span>
    </div>`;
}
/* ---------------- 字段编辑（去英文 key） ---------------- */
let _fldIdx = -1;
let _fldGroup = "person";
function appFldModal(idx, group) {
  _fldIdx = idx;
  if (group) _fldGroup = group;
  const f = idx >= 0 ? _appFlow.form_schema[idx] : { key: "", label: "", type: "text", group: _fldGroup, required: false, options: [], hint: "" };
  const typeMap = { text: "单行文本", textarea: "多行文本", number: "数字", amount: "金额", date: "日期", select: "下拉选择", radio: "单选", multi: "多选", person: "选择人员", project: "选择项目", department: "部门(按项目联动)", attachment: "附件上传" };
  const typeOptions = Object.entries(typeMap).map(([v, l]) => `<option value="${v}" ${f.type === v ? "selected" : ""}>${l}</option>`).join("");
  const grpSel = APP_GROUPS.map(g => `<option value="${g.key}" ${f.group === g.key ? "selected" : ""}>${g.name}</option>`).join("");
  modal(`<div style="width:460px">
    <b style="font-size:14px">${idx >= 0 ? "编辑字段" : "添加字段"}</b>
    <div style="display:grid;gap:10px;margin-top:12px">
      <label class="fld">所属分组 <select id="fm_group">${grpSel}</select></label>
      <label class="fld">字段名称（显示在表单上的中文名） <input type="text" id="fm_label" value="${esc(f.label)}" placeholder="如：入职渠道"></label>
      <label class="fld">类型 <select id="fm_type">${typeOptions}</select></label>
      <label class="fld">填写提示（可选，显示在字段下方的小字说明） <input type="text" id="fm_hint" value="${esc(f.hint || "")}" placeholder="如：18位，将校验合法性"></label>
      <label class="fld"><input type="checkbox" id="fm_req" ${f.required ? "checked" : ""}> 必填</label>
      <label class="fld" style="align-items:flex-start">选项（下拉/单选/多选用，逗号分隔）
        <textarea id="fm_opts" rows="2" style="width:100%">${esc((f.options || []).map(o => typeof o === "string" ? o : (o.value || o.label || "")).join(","))}</textarea></label>
    </div>
    <div style="font-size:11.5px;color:#9ca3af;margin-top:8px">系统会自动生成内部标识，您在界面上只需填写中文名称即可。</div>
    <div class="row" style="margin-top:14px"><button class="btn primary" onclick="appFldSave()">保存</button><button class="btn" onclick="closeModal()">取消</button></div>
  </div>`);
}
function appFldSave() {
  const label = document.getElementById("fm_label").value.trim();
  const type = document.getElementById("fm_type").value;
  const group = document.getElementById("fm_group").value;
  const required = document.getElementById("fm_req").checked;
  const hint = document.getElementById("fm_hint").value.trim();
  const opts = document.getElementById("fm_opts").value.split(/[,，]/).map(s => s.trim()).filter(Boolean);
  if (!label) { toast("请填写字段名称", false); return; }
  const f = _fldIdx >= 0 ? _appFlow.form_schema[_fldIdx] : { key: "f_" + Date.now().toString().slice(-6), group };
  f.label = label; f.type = type; f.required = required; f.options = opts; f.hint = hint || undefined; f.group = group;
  if (_fldIdx < 0) _appFlow.form_schema.push(f);
  closeModal();
  appFlowTab("form");
}
function appFldDel(i) { _appFlow.form_schema.splice(i, 1); appFlowTab("form"); }
function appFldMove(i, dir) {
  const j = i + dir;
  if (j < 0 || j >= _appFlow.form_schema.length) return;
  const arr = _appFlow.form_schema; const t = arr[i]; arr[i] = arr[j]; arr[j] = t;
  appFlowTab("form");
}
/* ---------------- Tab2：审批流设计（钉钉风格树状） ---------------- */
function appFlowDesignHtml() {
  const tree = _appFlow.flow_config.tree || { type: "chain", items: [] };
  const nodeDef = id => _appFlowNodes.find(n => n.id === id);
  const ruleText = n => {
    if (!n || !(n.approvers || []).length) return "未配置审批人";
    return n.approvers.map(r => {
      if (r.type === "role") return "旧版角色配置（" + (r.role || "?") + "）";
      if (r.type === "position") {
        const pos = _appFlowPositions.find(p => p.position === r.position) ? r.position : (r.position || "未选岗位");
        return "指定岗位·" + pos + "（" + (r.scope === "global" ? "物业总部" : "发起人所在项目") + "）";
      }
      if (r.type === "leader") return "发起人直属上级";
      return "指定账号×" + (r.accountIds || []).length;
    }).join("、");
  };
  const nodeCard = n => {
    const nd = nodeDef(n.nodeId);
    const all = nd && nd.mode === "all";
    return `<div class="flow-node-card${all ? " all" : ""}">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="width:28px;height:28px;border-radius:50%;background:${all ? "#fdeeee" : "#eef4ff"};color:${all ? "#e24f4f" : "#3b82f6"};display:flex;align-items:center;justify-content:center;font-size:14px;flex:none">👤</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:13.5px;font-weight:700;color:#1f2937">${nd ? esc(nd.name) : "节点已删除"}</div>
          <div style="font-size:11.5px;color:#6b7280;margin-top:2px">${ruleText(nd)}</div>
        </div>
        <span style="font-size:11px;padding:2px 9px;border-radius:10px;background:${all ? "#fee2e2" : "#eef2ff"};color:${all ? "#dc2626" : "#4f46e5"};flex:none">${all ? "会签" : "任一通过"}</span>
      </div>
      <div style="margin-top:7px;display:flex;gap:5px">
        <button class="btn sm" onclick="appNodeModal('${n.nodeId}')">编辑</button>
        <button class="btn sm" onclick="appNodeDel('${n.nodeId}')">删除</button>
      </div>
    </div>`;
  };
  const addBtn = refId => `<div style="margin:2px 0 2px 30px;position:relative">
      <button class="flow-add-btn" onclick="event.stopPropagation();appFlowAddMenu(event,'${refId}')"><span style="font-size:15px;font-weight:700">＋</span> 添加</button>
      <div class="flow-add-menu" id="fam_${refId}" style="display:none">
        <div class="mi" onclick="appNodeAddAfter('${refId}')">👤 审批人</div>
        <div class="mi" onclick="appBranchAddAfter('${refId}')">🔀 条件分支</div>
      </div>
    </div>`;
  const branchCondText = cond => {
    const fld = _appFlow.form_schema.find(x => x.key === cond.field);
    const opTxt = { eq: "等于", neq: "不等于", contains: "包含", gt: "大于", lt: "小于", gte: "大于等于", lte: "小于等于", earlier: "早于", later: "晚于", empty: "为空", notempty: "不为空" };
    return `${esc(fld ? fld.label : cond.field || "?")} ${opTxt[cond.op] || cond.op} ${esc(cond.value)}`;
  };
  const renderTree = tree => {
    if (!tree) return "";
    if (tree.type === "branch") return renderBranch(tree);
    return (tree.items || []).map(item => renderItem(item)).join('<div style="margin-left:26px;width:2px;height:20px;background:#c9d7f0"></div>');
  };
  const renderBranch = b => {
    const cond = b.cond || {};
    return `<div style="position:relative;margin:8px 0;padding-left:26px;border-left:2px solid #86efac">
      <div style="display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:6px 14px;font-size:13px;color:#15803d;font-weight:600;margin-bottom:10px">
        🔀 条件分支：${branchCondText(cond)}
        <button class="btn sm" style="margin-left:auto" onclick="appBranchModal('${b.id}')">编辑条件</button>
        <button class="btn sm" style="color:#dc2626" onclick="appBranchDel('${b.id}')">删</button>
      </div>
      <div style="display:flex;align-items:flex-start;gap:10px">
        ${(b.paths || []).map(p => `<div style="flex:1;min-width:0;border-top:2px solid #86efac;position:relative">
          <div style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #bbf7d0;color:#15803d;font-size:12px;padding:3px 11px;border-radius:13px;margin:2px 0 8px">${p.default ? "🔸 默认" : "✅ " + (p.label || "命中")}</div>
          <div>${renderChain(p)}</div>
          ${addBtn(p.id)}
        </div>`).join("")}
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-top:8px">
        <button class="btn sm" style="color:#15803d" onclick="appPathAdd('${b.id}')">＋ 添加分支条件</button>
      </div>
    </div>`;
  };
  const renderChain = p => (p.items || []).map(item => renderItem(item)).join('<div style="margin-left:26px;width:2px;height:20px;background:#c9d7f0"></div>');
  const renderItem = item => {
    if (item.type === "branch" || item.paths) return renderBranch(item);
    return `<div style="margin-left:26px;padding-left:0"><div class="flow-node-wrap">${nodeCard(item)}</div>${addBtn(item.id)}</div>`;
  };
  return `<div style="background:#fafbfc;border:1px solid #eef0f3;border-radius:12px;padding:20px 22px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span style="width:12px;height:12px;border-radius:50%;background:#10b981"></span><span style="font-size:13px;color:#374151;font-weight:600">发起人（自动获取账号）</span></div>
      <div style="margin-left:26px;width:2px;height:20px;background:#c9d7f0"></div>
      ${renderTree(tree)}
      <div style="display:flex;align-items:center;gap:8px;margin-left:26px;margin-top:8px"><span style="width:12px;height:12px;border-radius:50%;background:#d1d5db"></span><span style="font-size:13px;color:#4b5563;font-weight:600">结束</span></div>
      <div style="font-size:11.5px;color:#9ca3af;margin-top:14px;line-height:1.8;background:#fff;border:1px dashed #e5e7eb;border-radius:8px;padding:8px 12px">
        新增入口：每个节点正下方「＋ 添加」→ 选择 <b>审批人</b>（此步之后加审批节点）/ <b>条件分支</b>（此处分叉）/ <b>抄送人</b>（此步之后抄送）。条件分支每条子链末尾也有「＋ 添加」可继续延伸或嵌套分支。
      </div>
    </div>`;
}
function appFlowAddMenu(e, refId) {
  document.querySelectorAll(".flow-add-menu").forEach(m => m.style.display = "none");
  const el = document.getElementById("fam_" + refId);
  if (el) el.style.display = "block";
}
function findTreeItem(tree, id) {
  if (!tree || !tree.items) return null;
  for (let i = 0; i < tree.items.length; i++) {
    const it = tree.items[i];
    if (it.id === id) return { chain: tree.items, index: i };
    if (it.type === "branch" || it.paths) {
      for (let j = 0; j < (it.paths || []).length; j++) {
        const p = it.paths[j];
        if (p.id === id) return { chain: p.items, index: p.items.length };
        const r = findTreeItem(p, id);
        if (r) return r;
      }
    }
  }
  return null;
}
function insertAfter(refId, newItem) {
  const tree = _appFlow.flow_config.tree;
  const loc = findTreeItem(tree, refId);
  if (loc) loc.chain.splice(loc.index + 1, 0, newItem);
  else tree.items.push(newItem);
}
function removeItemById(tree, id) {
  if (!tree) return false;
  const items = tree.items || [];
  for (let i = 0; i < items.length; i++) {
    if (items[i].id === id) { items.splice(i, 1); return true; }
    if (items[i].type === "branch" || items[i].paths) {
      for (const p of (items[i].paths || [])) { if (removeItemById(p, id)) return true; }
    }
  }
  return false;
}
function findNodeRefId(tree, nodeId) {
  if (!tree) return null;
  for (const it of (tree.items || [])) {
    if (it.type === "approve" && it.nodeId === nodeId) return it.id;
    if (it.type === "branch" || it.paths) { for (const p of (it.paths || [])) { const r = findNodeRefId(p, nodeId); if (r) return r; } }
  }
  return null;
}
function appNodeAddAfter(refId) {
  const nid = "n" + (++_appTreeSeq);
  _appFlowNodes.push({ id: nid, name: "审批节点" + _appFlowNodes.length, mode: "any", approvers: [{ type: "position", position: "", scope: "project", accountIds: [], fallback_accounts: [] }] });
  insertAfter(refId, { id: "t" + (++_appTreeSeq), type: "approve", nodeId: nid });
  appFlowTab("flow");
  appNodeModal(nid);
}
function appBranchAddAfter(refId) {
  const bitem = { id: "t" + (++_appTreeSeq), type: "branch", cond: { field: "", op: "eq", value: "" }, paths: [
    { id: "p" + (++_appTreeSeq), label: "命中条件", default: false, cond: { field: "", op: "eq", value: "" }, items: [] },
    { id: "p" + (++_appTreeSeq), label: "默认", default: true, items: [] }
  ] };
  insertAfter(refId, bitem);
  appFlowTab("flow");
}
function appNodeDel(nodeId) {
  if (!confirm("确定删除该审批节点？")) return;
  const tree = _appFlow.flow_config.tree;
  const ref = findNodeRefId(tree, nodeId);
  if (ref) removeItemById(tree, ref);
  _appFlowNodes = _appFlowNodes.filter(n => n.id !== nodeId);
  appFlowTab("flow");
}
function appBranchDel(branchId) {
  if (!confirm("确定删除该条件分支？")) return;
  removeItemById(_appFlow.flow_config.tree, branchId);
  appFlowTab("flow");
}
function appPathAdd(branchId) {
  const b = findBranchById(branchId);
  if (!b) return;
  b.paths.push({ id: "p" + (++_appTreeSeq), label: "条件" + (b.paths.length), default: false, cond: { field: "", op: "eq", value: "" }, items: [] });
  appFlowTab("flow");
}
function findBranchById(branchId) {
  const walk = t => {
    if (!t) return null;
    for (const it of (t.items || [])) {
      if (it.id === branchId) return it;
      if (it.type === "branch" || it.paths) { for (const p of (it.paths || [])) { const r = walk(p); if (r) return r; } }
    }
    return null;
  };
  return walk(_appFlow.flow_config.tree);
}
/* ---------------- 节点编辑 ---------------- */
function appNodeModal(nid) {
  const n = _appFlowNodes.find(x => x.id === nid);
  if (!n) return;
  (n.approvers || []).forEach(r => {
    if (r.type === "role") {
      r.type = "position"; r.position = ""; r.scope = r.role === "admin" ? "global" : "project";
      r.accountIds = []; r.fallback_accounts = [];
    }
  });
  modal(`<div style="width:560px">
    <b style="font-size:14px">编辑审批节点：${esc(n.name)}</b>
    <div style="display:grid;gap:10px;margin-top:12px">
      <label class="fld">节点名称 <input type="text" id="nn_name" value="${esc(n.name)}"></label>
      <label class="fld">通过规则
        <select id="nn_mode">
          <option value="any" ${n.mode !== "all" ? "selected" : ""}>任一审批人通过即可</option>
          <option value="all" ${n.mode === "all" ? "selected" : ""}>会签（全部审批人通过）</option>
        </select></label>
    </div>
    <div style="font-size:12.5px;font-weight:600;margin:12px 0 6px">审批人规则</div>
    <div id="nnRules">${(n.approvers || []).map((r, i) => appRuleRow(nid, i, r)).join("") || '<div style="color:#9ca3af;font-size:12px">尚未配置审批人</div>'}</div>
    <button class="btn success sm" style="margin-top:8px" onclick="appRuleAdd('${nid}')">＋ 添加审批人规则</button>
    <div class="row" style="margin-top:14px"><button class="btn primary" onclick="appNodeSave('${nid}')">保存节点</button><button class="btn" onclick="closeModal()">取消</button></div>
  </div>`, 600);
}
function appRuleRow(nid, i, r) {
  const typeSel = `<select id="nr_${nid}_${i}_type" onchange="appRuleType('${nid}',${i})">
    <option value="position" ${r.type === "position" ? "selected" : ""}>指定岗位</option>
    <option value="leader" ${r.type === "leader" ? "selected" : ""}>发起人的直属上级</option>
    <option value="accounts" ${r.type === "accounts" ? "selected" : ""}>指定账号</option></select>`;
  let param = "";
  if (r.type === "position") {
    param = `<div style="font-size:12.5px;color:#374151">选择岗位（来自花名册）：
        <select id="nr_${nid}_${i}_pos" style="width:100%;margin-top:4px"><option value="">请选择岗位</option>${_appFlowPositions.map(x => `<option value="${esc(x.position)}" ${r.position === x.position ? "selected" : ""}>${esc(x.position)}（${x.count}人）</option>`).join("")}</select></div>
      <div style="margin-top:6px;font-size:12px;color:#6b7280"><b>匹配范围：</b>
        <label style="margin-right:12px"><input type="radio" name="nr_${nid}_${i}_scope" value="project" ${r.scope !== "global" ? "checked" : ""}> 发起人所在项目</label>
        <label><input type="radio" name="nr_${nid}_${i}_scope" value="global" ${r.scope === "global" ? "checked" : ""}> 物业总部（全局）</label></div>`;
  } else if (r.type === "leader") {
    const ids = (r.fallback_accounts || []).map(Number);
    param = `<div style="font-size:12px;color:#6b7280">若发起人无直属上级，则退给：</div>
      <select id="nr_${nid}_${i}_fbs" multiple size="3" style="width:100%">${_appFlowUsers.filter(u => u.enabled).map(u => `<option value="${u.id}" ${ids.includes(Number(u.id)) ? "selected" : ""}>${esc(u.name || u.username)}</option>`).join("")}</select>`;
  } else {
    const ids = (r.accountIds || []).map(Number);
    param = `<select id="nr_${nid}_${i}_accs" multiple size="4" style="width:100%">${_appFlowUsers.filter(u => u.enabled).map(u => `<option value="${u.id}" ${ids.includes(Number(u.id)) ? "selected" : ""}>${esc(u.name || u.username)}${u.project ? "·" + esc(u.project) : ""}</option>`).join("")}</select><div style="font-size:11px;color:#6b7280">按住 Ctrl 多选；仅显示启用账号</div>`;
  }
  return `<div style="border:1px solid #eef0f3;border-radius:8px;padding:8px;margin-bottom:6px">
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">${typeSel}<button class="btn sm danger" onclick="appRuleDel('${nid}',${i})">删</button></div>
    <div>${param}</div></div>`;
}
function appRuleAdd(nid) {
  const n = _appFlowNodes.find(x => x.id === nid);
  if (!n) return;
  (n.approvers || (n.approvers = [])).push({ type: "position", position: "", scope: "project", accountIds: [], fallback_accounts: [] });
  appNodeModal(nid);
}
function appRuleDel(nid, i) {
  const n = _appFlowNodes.find(x => x.id === nid);
  if (n) n.approvers.splice(i, 1);
  appNodeModal(nid);
}
function appRuleType(nid, i) {
  const n = _appFlowNodes.find(x => x.id === nid);
  if (n) n.approvers[i].type = document.getElementById("nr_" + nid + "_" + i + "_type").value;
  appNodeModal(nid);
}
function appNodeSave(nid) {
  const n = _appFlowNodes.find(x => x.id === nid);
  if (!n) return;
  n.name = document.getElementById("nn_name").value.trim() || n.name;
  n.mode = document.getElementById("nn_mode").value;
  const rules = [];
  (n.approvers || []).forEach((r, i) => {
    const r2 = { type: r.type, position: "", role: "", accountIds: [], fallback_accounts: [] };
    if (r.type === "position") {
      r2.position = document.getElementById("nr_" + nid + "_" + i + "_pos").value;
      r2.scope = (document.querySelector('input[name="nr_' + nid + '_' + i + '_scope"]:checked') || {}).value || "project";
    }
    if (r.type === "leader") r2.fallback_accounts = [...document.getElementById("nr_" + nid + "_" + i + "_fbs").selectedOptions].map(o => Number(o.value));
    if (r.type === "accounts") r2.accountIds = [...document.getElementById("nr_" + nid + "_" + i + "_accs").selectedOptions].map(o => Number(o.value));
    rules.push(r2);
  });
  n.approvers = rules;
  closeModal();
  appFlowTab("flow");
}
/* ---------------- 分支编辑 ---------------- */
function appBranchModal(branchId) {
  const b = findBranchById(branchId);
  if (!b) return;
  const fldOpts = _appFlow.form_schema.map(f => `<option value="${esc(f.key)}" ${b.cond.field === f.key ? "selected" : ""}>${esc(f.label || f.key)}</option>`).join("");
  const pathsHtml = (b.paths || []).map((p, i) => `<div style="border:1px solid #bbf7d0;background:#f0fdf4;border-radius:8px;padding:8px 10px;margin-bottom:6px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <b style="font-size:12.5px;color:#15803d">${p.default ? "🔸 默认分支" : "✅ 条件分支"}</b>
        ${p.default ? "" : `<input type="text" id="bp_${i}_label" value="${esc(p.label || "")}" placeholder="条件标签" style="border:1px solid #d1fae5;border-radius:6px;padding:4px 8px;font-size:12.5px;width:120px">`}
        <span style="flex:1"></span>
        ${p.default ? "" : `<button class="btn sm danger" onclick="appPathDel('${branchId}',${i})">删</button>`}
      </div>
      ${p.default ? "" : `<div style="display:grid;gap:6px;grid-template-columns:1fr 90px 1fr">
        <select id="bp_${i}_field"><option value="">条件字段</option>${fldOpts}</select>
        <select id="bp_${i}_op">
          ${[["eq","等于"],["neq","不等于"],["contains","包含"],["gt","大于"],["lt","小于"],["gte","大于等于"],["lte","小于等于"],["empty","为空"],["notempty","不为空"]].map(o => `<option value="${o[0]}" ${(p.cond && p.cond.op) === o[0] ? "selected" : ""}>${o[1]}</option>`).join("")}
        </select>
        <input type="text" id="bp_${i}_value" value="${esc((p.cond && p.cond.value) || "")}" placeholder="条件值" style="border:1px solid #d1fae5;border-radius:6px;padding:4px 8px;font-size:12.5px"></div>`}
    </div>`).join("");
  modal(`<div style="width:520px">
    <b style="font-size:14px">编辑条件分支</b>
    <div class="hint" style="margin:8px 0;font-size:12px;color:#6b7280">分支依据发起人填写的表单字段。命中某条件则走该分支，全部未命中则走「默认分支」。分支内可继续添加审批节点或嵌套分支。</div>
    <div style="font-weight:600;font-size:12.5px;margin:6px 0">分支条件</div>
    ${pathsHtml}
    <div class="row" style="margin-top:12px"><button class="btn primary" onclick="appBranchSave('${branchId}')">保存</button><button class="btn" onclick="closeModal()">取消</button></div>
  </div>`, 560);
}
function appBranchSave(branchId) {
  const b = findBranchById(branchId);
  if (!b) return;
  (b.paths || []).forEach((p, i) => {
    if (p.default) return;
    p.label = (document.getElementById("bp_" + i + "_label") || {}).value || p.label;
    const field = (document.getElementById("bp_" + i + "_field") || {}).value || "";
    const op = (document.getElementById("bp_" + i + "_op") || {}).value || "eq";
    const value = (document.getElementById("bp_" + i + "_value") || {}).value || "";
    p.cond = { field, op, value };
  });
  b.cond = (b.paths.find(p => !p.default) || {}).cond || { field: "", op: "eq", value: "" };
  closeModal();
  appFlowTab("flow");
}
function appPathDel(branchId, i) {
  const b = findBranchById(branchId);
  if (b && b.paths[i] && !b.paths[i].default) b.paths.splice(i, 1);
  appBranchModal(branchId);
}
/* ---------------- 保存 ---------------- */
function collectNodeIds(tree, set) {
  if (!tree) return;
  for (const it of (tree.items || [])) {
    if (it.type === "approve") set.add(it.nodeId);
    if (it.type === "branch" || it.paths) { for (const p of (it.paths || [])) collectNodeIds(p, set); }
  }
}
async function appFlowSave() {
  if (!_appFlow) return;
  const name = document.getElementById("afName").value.trim();
  const enabled = document.getElementById("afEnabled").checked;
  if (!name) { toast("请填写流程名称", false); return; }
  if (!_appFlow.flow_key) _appFlow.flow_key = "flow_" + Date.now().toString().slice(-8);
  if (!_appFlow.form_schema.length) { toast("请至少添加一个表单字段", false); return; }
  const used = new Set();
  collectNodeIds(_appFlow.flow_config.tree, used);
  _appFlowNodes = _appFlowNodes.filter(n => used.has(n.id));
  if (!_appFlowNodes.length) { toast("请至少添加一个审批节点", false); return; }
  const nodes = {};
  _appFlowNodes.forEach(n => { nodes[n.id] = { name: n.name, mode: n.mode, approvers: n.approvers }; });
  _appFlow.flow_config.nodes = nodes;
  try {
    const body = { id: _appFlow.id, flow_key: _appFlow.flow_key, name, form_schema: _appFlow.form_schema, flow_config: _appFlow.flow_config, enabled };
    await api("/api/approval/flow_save", { body });
    toast("流程配置已保存");
    await appFlowLoad();
    await appFlowEdit(_appFlow.flow_key);
  } catch (e) { toast(e.message, false); }
}
async function appFlowToggle() {
  await api("/api/approval/flow_toggle", { body: { id: _appFlow.id } });
  toast("已切换启用状态");
  await appFlowLoad();
  await appFlowEdit(_appFlow.flow_key);
}
async function appFlowDelete() {
  if (!confirm("确定删除该流程？有审批单的流程无法删除。")) return;
  try {
    await api("/api/approval/flow_delete", { body: { id: _appFlow.id } });
    toast("流程已删除");
    _appFlow = null;
    const el = document.getElementById("afEditor");
    if (el) el.innerHTML = "选择左侧流程进行编辑";
    await appFlowLoad();
  } catch (e) { toast(e.message, false); }
}

/* ================ 启动入口 ================
 * 刷新/重开页面时若本地仍保留有效会话令牌则自动恢复登录态，避免每次刷新都要重新登录；
 * 令牌缺失或已失效时停留在登录页（bootstrap 内部失败会清除令牌并显示登录页）。 */
(function autoBoot() {
  async function dingtalkSyncNow() {
  if (!confirm("立即从钉钉全量同步组织架构和人员？")) return;

  // 进度弹窗
  const overlay = document.createElement("div");
  overlay.id = "syncOverlay";
  overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center";
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:14px;padding:32px 40px;min-width:340px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:16px;font-weight:600;color:#333;margin-bottom:18px">钉钉数据同步中</div>
      <div class="sync-spinner" style="width:44px;height:44px;border:4px solid #e8e8e8;border-top-color:#1890ff;border-radius:50%;animation:syncSpin .8s linear infinite;margin:0 auto 18px"></div>
      <div id="syncStage" style="font-size:14px;color:#888;margin-bottom:6px">正在连接钉钉...</div>
      <div id="syncTimer" style="font-size:13px;color:#bbb">已用时 0s</div>
    </div>
  `;
  document.body.appendChild(overlay);

  // 注入动画
  if (!document.getElementById('syncSpinStyle')) {
    const style = document.createElement('style');
    style.id = 'syncSpinStyle';
    style.textContent = '@keyframes syncSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
  }

  // 阶段提示轮播
  const stages = ['正在连接钉钉...', '正在拉取部门树...', '正在同步组织架构...', '正在拉取在职人员...', '正在同步人员数据...', '正在拉取离职名单...', '正在同步花名册...', '即将完成...'];
  let stageIdx = 0;
  const stageEl = () => document.getElementById('syncStage');
  const timerEl = () => document.getElementById('syncTimer');
  const startTs = Date.now();
  const stageTimer = setInterval(() => {
    if (stageEl()) stageEl().textContent = stages[stageIdx % stages.length];
    stageIdx++;
    if (timerEl()) timerEl().textContent = '已用时 ' + Math.round((Date.now() - startTs) / 1000) + 's';
  }, 4000);

  try {
    const r = await api("/api/dingtalk/sync-now", { method: "POST" });
    clearInterval(stageTimer);
    overlay.remove();
    const rep = r.report || {};
    const elapsed = r.elapsed || rep.elapsed || 0;
    showSyncResult(rep, elapsed);
  } catch (e) {
    clearInterval(stageTimer);
    overlay.remove();
    showSyncError(e.message);
  }
}

function showSyncResult(rep, elapsed) {
  const overlay = document.createElement("div");
  overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center";
  const rows = [
    ['钉钉部门数', rep.dingtalk_depts ?? 0],
    ['钉钉在职人员', rep.dingtalk_users ?? 0],
    ['钉钉离职人员', rep.dingtalk_dismissed ?? 0],
    ['新增人员', rep.new ?? 0],
    ['更新人员', rep.updated ?? 0],
    ['标记离职', rep.offboard ?? 0],
    ['新增离职', rep.offboard_new ?? 0],
    ['花名册同步', rep.roster ?? 0],
  ];
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="text-align:center;margin-bottom:20px">
        <div style="font-size:20px;font-weight:700;color:#52c41a">同步完成</div>
        <div style="font-size:13px;color:#999;margin-top:4px">耗时 ${elapsed} 秒</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        ${rows.map(([k, v]) => `<tr><td style="padding:6px 0;color:#666">${k}</td><td style="padding:6px 0;text-align:right;font-weight:600;color:#333">${v}</td></tr>`).join('')}
      </table>
      <div style="text-align:center;margin-top:22px">
        <button id="syncOkBtn" class="btn success" style="padding:8px 36px;font-size:15px;border-radius:8px;border:none;background:#1890ff;color:#fff;cursor:pointer">确定</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  document.getElementById('syncOkBtn').onclick = () => { overlay.remove(); refreshPage(); };
}

function showSyncError(msg) {
  const overlay = document.createElement("div");
  overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center";
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:320px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:20px;font-weight:700;color:#ff4d4f;margin-bottom:12px">同步失败</div>
      <div style="font-size:14px;color:#666;margin-bottom:22px;word-break:break-all">${msg}</div>
      <button onclick="this.closest('div[style]').parentElement.remove()" class="btn" style="padding:8px 36px;font-size:15px;border-radius:8px;border:1px solid #d9d9d9;background:#fff;cursor:pointer">关闭</button>
    </div>
  `;
  document.body.appendChild(overlay);
}
window.dingtalkSyncNow = dingtalkSyncNow;

if (TOKEN) { bootstrap(); }
  else { try { showLogin(); } catch (e) {} }
})();
