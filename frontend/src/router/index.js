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
