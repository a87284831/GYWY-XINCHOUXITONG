import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  base: '/app/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      '/api': {
        // 本地测试后端（协同开发约定）。生产域名 oa.haoxuanyunshu.cn 严禁改动。
        target: 'http://127.0.0.1:8899',
        changeOrigin: true,
        secure: false,
      },
    },
  },
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        manualChunks: {
          'vue-vendor': ['vue', 'vue-router', 'pinia'],
          'element-plus': ['element-plus'],
          'chart-vendor': ['chart.js'],
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
  },
})
