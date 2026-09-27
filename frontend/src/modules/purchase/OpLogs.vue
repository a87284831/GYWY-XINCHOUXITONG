<script setup>
// 操作日志（复刻旧 #/oplogs：动作筛选 + 关键词 + 日期范围 + 分页；admin-only）
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'

const ACTIONS = [
  '确认', '取消确认', '批量确认', '退回修改', '项目退回', '预算设置', '微调',
  '导入', '批量导入历史', '新增', '更新', '删除', '定稿', '提交', '撤销提交',
  '登录', '退出登录', '登录失败', '清单外映射', '导出报价', '年度导出',
  '商品库批量导入', '窗口设置', '系统设置', '预算超支',
]

const action = ref('')
const keyword = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const qs = '?page=' + page.value + '&page_size=' + pageSize.value +
      (action.value ? '&action=' + encodeURIComponent(action.value) : '') +
      (keyword.value.trim() ? '&keyword=' + encodeURIComponent(keyword.value.trim()) : '') +
      (dateFrom.value ? '&date_from=' + dateFrom.value : '') +
      (dateTo.value ? '&date_to=' + dateTo.value : '')
    const res = await api('/api/purchase/oplogs' + qs)
    rows.value = res.data || []
    total.value = res.total || 0
    page.value = res.page || page.value
    pageSize.value = res.page_size || pageSize.value
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

function query() {
  page.value = 1
  load()
}

onMounted(load)
</script>

<template>
  <div>
    <div style="font-size:18px;font-weight:700;">操作日志</div>
    <div style="display:flex;gap:8px;margin:12px 0;flex-wrap:wrap;align-items:center;">
      <el-select v-model="action" style="width:150px" placeholder="全部动作" @change="query">
        <el-option value="" label="全部动作" />
        <el-option v-for="a in ACTIONS" :key="a" :value="a" :label="a" />
      </el-select>
      <el-input v-model="keyword" placeholder="搜索用户名/详情" style="width:220px" clearable @keyup.enter="query" />
      <el-date-picker v-model="dateFrom" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" style="width:140px" />
      <el-date-picker v-model="dateTo" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" style="width:140px" />
      <el-button type="primary" @click="query">查询</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border size="small" style="width:100%">
      <el-table-column prop="created_at" label="时间" width="160" />
      <el-table-column prop="username" label="操作人" min-width="120" />
      <el-table-column label="角色" width="80" align="center">
        <template #default="{ row }">
          <el-tag size="small" :type="row.role === 'admin' ? 'warning' : 'info'">{{ row.role === 'admin' ? '招采' : '员工' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="动作" width="120" align="center">
        <template #default="{ row }">
          <el-tag size="small" effect="plain">{{ row.action }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="detail" label="详情" min-width="300" show-overflow-tooltip />
    </el-table>

    <div style="margin-top:10px;">
      <el-pagination v-model:current-page="page" :page-size="pageSize" :total="total" layout="total, prev, pager, next" @current-change="load" />
    </div>
  </div>
</template>
