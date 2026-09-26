import { defineStore } from 'pinia'

// 全局核算月份（复刻旧版 state.month：跨页面共享，monthInput 修改后全站生效）
function curMonth() {
  const d = new Date()
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
}

export const useUiStore = defineStore('ui', {
  state: () => ({ month: curMonth() }),
})
