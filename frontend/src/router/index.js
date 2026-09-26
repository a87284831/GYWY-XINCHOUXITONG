import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/Login.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    component: () => import('@/layouts/MainLayout.vue'),
    children: [
      {
        path: '',
        name: 'home',
        component: () => import('@/modules/home/Home.vue'),
        meta: { perm: '' },
      },
      {
        path: 'summary',
        name: 'summary',
        component: () => import('@/modules/hr/Summary.vue'),
        meta: { perm: 'payroll' },
      },
      {
        path: 'payroll',
        name: 'payroll',
        component: () => import('@/modules/hr/Payroll.vue'),
        meta: { perm: 'payroll' },
      },
      {
        path: 'taxMode',
        name: 'taxMode',
        component: () => import('@/modules/hr/TaxMode.vue'),
        meta: { perm: 'payroll' },
      },
      {
        path: 'attendance',
        name: 'attendance',
        component: () => import('@/modules/hr/Attendance.vue'),
        meta: { perm: 'attendance' },
      },
      {
        path: 'org',
        name: 'org',
        component: () => import('@/modules/hr/Org.vue'),
        meta: { perm: 'projects' },
      },
      {
        path: 'staff',
        name: 'staff',
        component: () => import('@/modules/hr/Staff.vue'),
        meta: { perm: 'staff' },
      },
      {
        path: 'adjust',
        name: 'adjust',
        component: () => import('@/modules/hr/Adjust.vue'),
        meta: { perm: 'staff' },
      },
      {
        path: 'budget',
        name: 'budget',
        component: () => import('@/modules/hr/Budget.vue'),
        meta: { perm: 'budget' },
      },
      {
        path: 'export',
        name: 'export',
        component: () => import('@/modules/hr/Export.vue'),
        meta: { perm: 'export' },
      },
      {
        path: 'projects',
        name: 'projects',
        component: () => import('@/modules/hr/Projects.vue'),
        meta: { perm: '' },
      },
      {
        path: 'perfCreate',
        name: 'perfCreate',
        component: () => import('@/modules/perf/PerfCreate.vue'),
        meta: { perm: 'perf' },
      },
      {
        path: 'perfApprove',
        name: 'perfApprove',
        component: () => import('@/modules/perf/PerfList.vue'),
        props: { scope: 'approve' },
        meta: { perm: 'perf' },
      },
      {
        path: 'perfReport',
        name: 'perfReport',
        component: () => import('@/modules/perf/PerfList.vue'),
        props: { scope: 'report' },
        meta: { perm: 'perf' },
      },
      {
        path: 'perfMine',
        name: 'perfMine',
        component: () => import('@/modules/perf/PerfList.vue'),
        props: { scope: 'mine' },
        meta: { perm: 'perf' },
      },
      {
        path: 'perfRecords',
        name: 'perfRecords',
        component: () => import('@/modules/perf/PerfRecords.vue'),
        meta: { perm: 'perf_admin' },
      },
      {
        path: 'reportHome',
        name: 'reportHome',
        component: () => import('@/modules/report/ReportHome.vue'),
        meta: { perm: 'hr_report' },
      },
      {
        path: 'hrReport',
        name: 'hrReport',
        component: () => import('@/modules/report/HrReport.vue'),
        meta: { perm: 'hr_report' },
      },
      {
        path: 'salaryReport',
        name: 'salaryReport',
        component: () => import('@/modules/report/SalaryReport.vue'),
        meta: { perm: 'salary_report' },
      },
      {
        path: 'attReport',
        name: 'attReport',
        component: () => import('@/modules/report/AttReport.vue'),
        meta: { perm: 'attendance_report' },
      },
      {
        path: 'fireReport',
        name: 'fireReport',
        component: () => import('@/modules/maint/MaintReport.vue'),
        props: { type: 'fire' },
        meta: { perm: 'maint_fire' },
      },
      {
        path: 'elevReport',
        name: 'elevReport',
        component: () => import('@/modules/maint/MaintReport.vue'),
        props: { type: 'elevator' },
        meta: { perm: 'maint_elev' },
      },
      {
        path: 'maintFire',
        name: 'maintFire',
        component: () => import('@/modules/maint/MaintLedger.vue'),
        props: { type: 'fire' },
        meta: { perm: 'maint_fire' },
      },
      {
        path: 'maintElev',
        name: 'maintElev',
        component: () => import('@/modules/maint/MaintLedger.vue'),
        props: { type: 'elevator' },
        meta: { perm: 'maint_elev' },
      },
      {
        path: 'maintPartners',
        name: 'maintPartners',
        component: () => import('@/modules/maint/MaintPartners.vue'),
        meta: { perm: 'maint_partners' },
      },
      {
        path: 'approvalCenter',
        name: 'approvalCenter',
        component: () => import('@/modules/oa/ApprovalCenter.vue'),
        meta: { perm: '' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/modules/settings/Settings.vue'),
        props: { tab: 'perm' },
        meta: { perm: 'users' },
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('@/modules/settings/Settings.vue'),
        props: { tab: 'perm' },
        meta: { perm: 'users' },
      },
      {
        path: 'logs',
        name: 'logs',
        component: () => import('@/modules/settings/Settings.vue'),
        props: { tab: 'logs' },
        meta: { perm: 'logs' },
      },
      {
        path: 'backup',
        name: 'backup',
        component: () => import('@/modules/settings/Settings.vue'),
        props: { tab: 'backup' },
        meta: { perm: 'backup' },
      },
      {
        path: 'salarySettings',
        name: 'salarySettings',
        component: () => import('@/modules/settings/SalarySettings.vue'),
        meta: { perm: 'rules' },
      },
      {
        path: 'financeDashboard',
        name: 'financeDashboard',
        component: () => import('@/modules/finance/FinanceDashboard.vue'),
        meta: { perm: 'finance_view' },
      },
      {
        path: 'financeLedger',
        name: 'financeLedger',
        component: () => import('@/modules/finance/FinanceLedger.vue'),
        meta: { perm: 'finance_view' },
      },
      {
        path: 'financePayments',
        name: 'financePayments',
        component: () => import('@/modules/finance/FinancePayments.vue'),
        meta: { perm: 'finance_view' },
      },
      {
        path: 'financeSummary',
        name: 'financeSummary',
        component: () => import('@/modules/finance/FinanceSummary.vue'),
        meta: { perm: 'finance_view' },
      },
      {
        path: 'financeImport',
        name: 'financeImport',
        component: () => import('@/modules/finance/FinanceImport.vue'),
        meta: { perm: 'finance_admin' },
      },
      {
        path: 'purchase/dashboard',
        name: 'purchaseDashboard',
        component: () => import('@/modules/purchase/Dashboard.vue'),
        meta: { perm: 'purchase_view' },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory('/app/'),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  auth.restore()
  if (!to.meta.public && !auth.token) return '/login'
  if (to.path === '/login' && auth.token) return '/'
  if (to.meta.perm && !auth.can(to.meta.perm)) return '/'
})

export default router
