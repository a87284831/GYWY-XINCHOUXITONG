// 报表中心共享状态 — 复刻旧版全局 REPORT（app.js:2178）：year/ym/annual 跨报表页保留
import { defineStore } from 'pinia'

export const useReportStore = defineStore('report', {
  state: () => {
    const d = new Date()
    return {
      year: d.getFullYear(),
      ym: d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'),
      annual: false,
    }
  },
})
