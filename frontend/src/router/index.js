import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/Login.vue'),
    meta: { perm: '', public: true },
  },
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/Home.vue'),
    meta: { perm: '' },
  },
]

const router = createRouter({
  history: createWebHistory('/app/'),
  routes,
})

export default router
