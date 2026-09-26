// 财务元数据（复刻旧财务应用 init() 的 meta 加载：is_admin/role/项目绑定/类别枚举）
import { reactive } from 'vue'
import { api } from '@/api/client'

export const FIN = reactive({
  loaded: false,
  categories: {},
  paymentTypes: {},
  projects: [],
  isAdmin: false,
  role: '',
  projectName: '',
  projectId: 0,
})

export async function ensureMeta() {
  if (FIN.loaded) return FIN
  const d = await api('/api/finance/meta')
  FIN.categories = d.categories || {}
  FIN.paymentTypes = d.payment_types || {}
  FIN.projects = d.projects || []
  FIN.isAdmin = !!d.is_admin
  FIN.role = d.role || ''
  FIN.projectName = d.project_name || ''
  FIN.projectId = d.project_id || 0
  FIN.loaded = true
  return FIN
}
