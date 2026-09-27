<script setup>
// 采购填报·月度列表（复刻旧 #/fill：年份切换 + admin 代填项目 + 12 月卡片）
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { money } from '@/utils/format'
import { monthCardStatus, fillCardAction } from './fillLogic'

const router = useRouter()
const auth = useAuthStore()
const isAdmin = computed(() => !!auth.user && auth.user.role === 'admin')

const year = ref(new Date().getFullYear())
// 代填项目：null=未选（下拉显示占位符而非 0）；记住上次选择，避免每次进入都要重选
const PID_KEY = 'gw_purchase_fill_pid'
let _savedPid = 0
try { _savedPid = Number(localStorage.getItem(PID_KEY)) || 0 } catch (e) { /* 存储不可用忽略 */ }
const projectId = ref(_savedPid || null)
const projects = ref([])
const rows = ref([])
const loading = ref(false)

const today = new Date().toISOString().slice(0, 10)
const curMonth = new Date().toISOString().slice(0, 7)
const years = (() => {
  const y = new Date().getFullYear()
  const out = []
  for (let i = y + 4; i >= y - 3; i--) out.push(i)
  return out
})()

async function loadProjects() {
  if (!isAdmin.value) return
  try {
    projects.value = (await api('/api/purchase/projects/options')).data || []
    // 记住的项目已不存在（停用/删除）时清掉，避免下拉显示数字 id
    if (projectId.value && !projects.value.some((p) => p.id === projectId.value)) projectId.value = null
  } catch (e) { /* 代填列表失败不阻断页面 */ }
}

function onProjectChange(v) {
  try {
    if (v) localStorage.setItem(PID_KEY, String(v))
    else localStorage.removeItem(PID_KEY)
  } catch (e) { /* 存储不可用忽略 */ }
  load()
}

async function load() {
  if (isAdmin.value && !projectId.value) { rows.value = []; return } // 未选代填项目不发请求（后端会报项目缺失）
  loading.value = true
  try {
    const q = '?year=' + year.value + (isAdmin.value && projectId.value ? '&project_id=' + projectId.value : '')
    rows.value = (await api('/api/purchase/my/months' + q)).data.rows || []
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

function enter(r) {
  router.push({ path: '/purchase/fill-form', query: { month: r.month, project_id: projectId.value || '' } })
}

onMounted(async () => {
  await loadProjects()
  load()
})
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
      <div style="font-size:18px;font-weight:700;">采购填报</div>
      <el-select v-model="year" style="width:110px" @change="load">
        <el-option v-for="y in years" :key="y" :value="y" :label="y + ' 年'" />
      </el-select>
      <template v-if="isAdmin">
        <span style="font-size:13px;color:#6b7280;">代填项目：</span>
        <el-select v-model="projectId" style="width:200px" placeholder="选择项目" @change="onProjectChange">
          <el-option v-for="p in projects" :key="p.id" :value="p.id" :label="p.name" />
        </el-select>
        <span v-if="!projectId" style="font-size:12.5px;color:#e6a23c;">请先选择要代填的项目，再进入月份填报</span>
      </template>
    </div>
    <div style="font-size:12.5px;color:#94a3b8;margin:8px 0 14px;">
      选择月份进入填报 · 当前为 {{ curMonth.slice(0, 4) }} 年 {{ Number(curMonth.slice(5)) }} 月：本月开放填报，历史月份只读可查看（往年月份也可查看）
    </div>

    <div v-if="isAdmin && !projectId" class="dash-card" style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">
      请在上方选择「代填项目」后查看/填报该项目的月度需求
    </div>
    <div v-else v-loading="loading" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;">
      <div v-for="r in rows" :key="r.month" class="dash-card" style="padding:14px 16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div style="font-size:16px;font-weight:700;">{{ Number(r.month.slice(5)) }} 月</div>
          <el-tag size="small" :type="monthCardStatus(r, today).type">{{ monthCardStatus(r, today).tag }}</el-tag>
        </div>
        <div style="font-size:12px;color:#94a3b8;margin-top:4px;">{{ monthCardStatus(r, today).window }}</div>
        <div style="font-size:12.5px;color:#475569;margin-top:8px;">
          {{ r.item_count }} 填报 {{ r.submitted_count }} 条 · 已确认 {{ r.confirmed_count }} 条
        </div>
        <div v-if="(r.returned_count || 0) > 0" style="font-size:12.5px;color:#dc2626;margin-top:4px;">
          {{ r.returned_count }} 条被退回，请修改后重新提交
        </div>
        <div v-if="r.return_reason" style="font-size:12px;color:#dc2626;opacity:.85;margin-top:2px;">退回原因：{{ r.return_reason }}</div>
        <div v-if="r.imported" style="font-size:13px;color:#16a34a;margin-top:6px;">采购金额 ¥{{ money(r.amount) }}</div>
        <div style="margin-top:10px;">
          <el-button size="small" :type="fillCardAction(r, curMonth).type" @click="enter(r)">{{ fillCardAction(r, curMonth).text }}</el-button>
        </div>
      </div>
    </div>
  </div>
</template>
