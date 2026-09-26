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
