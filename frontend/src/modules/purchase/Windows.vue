<script setup>
// 填报窗口设置 + 价格异常阈值（复刻旧 #/windows：窗口 CRUD/启停、一键生成、阈值设置）
import { ref, onMounted } from 'vue'
import { ElMessageBox } from 'element-plus'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { windowState } from './systemLogic'

const rows = ref([])
const loading = ref(false)
const dialogVisible = ref(false)
const editRow = ref(null)
const saving = ref(false)
const form = ref({ month: '', start_date: '', end_date: '', status: 1 })
const threshold = ref(30)
const thresholdSaving = ref(false)
const genLoading = ref(false)

async function loadSettings() {
  try {
    const d = await api('/api/purchase/settings')
    threshold.value = d.data.price_threshold || 30
  } catch (e) {
    /* 保留默认 */
  }
}

async function saveThreshold() {
  thresholdSaving.value = true
  try {
    const d = await api('/api/purchase/settings', { method: 'PUT', body: { price_threshold: threshold.value } })
    toast(d.msg || '已保存')
  } catch (e) {
    toast(e.message, false)
  } finally {
    thresholdSaving.value = false
  }
}

async function genWindows() {
  try {
    await ElMessageBox.confirm(
      '将按每月 20-23 日自动生成未来 12 个月的填报窗口（已有月份自动跳过），确定生成吗？',
      '一键生成',
      { type: 'info' }
    )
  } catch (e) {
    return
  }
  genLoading.value = true
  try {
    // 注意：POST 但参数走 query（handler 读 $_GET）；默认 from=当月 count=12，旧版不带参数
    const d = await api('/api/purchase/windows/gen', { method: 'POST' })
    toast(d.msg || '生成完成')
    loadWindows()
  } catch (e) {
    if (e && e.message) toast(e.message, false)
  } finally {
    genLoading.value = false
  }
}

async function loadWindows() {
  loading.value = true
  try {
    const d = await api('/api/purchase/windows')
    const today = new Date().toISOString().slice(0, 10)
    rows.value = d.data.map((w) => ({ ...w, _today: today }))
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editRow.value = null
  form.value = { month: '', start_date: '', end_date: '', status: 1 }
  dialogVisible.value = true
}

function openEdit(row) {
  editRow.value = row
  form.value = { month: row.month, start_date: row.start_date, end_date: row.end_date, status: row.status }
  dialogVisible.value = true
}

async function saveWindow() {
  if (!form.value.month || !form.value.start_date || !form.value.end_date) {
    toast('请填写完整', false)
    return
  }
  saving.value = true
  try {
    await api('/api/purchase/windows', { body: form.value })
    toast('已保存')
    dialogVisible.value = false
    loadWindows()
  } catch (e) {
    toast(e.message, false)
  } finally {
    saving.value = false
  }
}

async function toggleStatus(row) {
  try {
    await api('/api/purchase/windows', {
      body: {
        month: row.month,
        start_date: row.start_date,
        end_date: row.end_date,
        status: row.status == 1 ? 0 : 1,
      },
    })
    toast(row.status == 1 ? '已停用' : '已启用')
    loadWindows()
  } catch (e) {
    toast(e.message, false)
  }
}

async function removeWindow(row) {
  try {
    await ElMessageBox.confirm(`确定删除 ${row.month} 的填报窗口吗？`, '删除确认', { type: 'warning' })
  } catch (e) {
    return
  }
  try {
    await api('/api/purchase/windows/' + row.id, { method: 'DELETE' })
    toast('已删除')
    loadWindows()
  } catch (e) {
    if (e.message) toast(e.message, false)
  }
}

function curMeta(row) {
  const st = windowState(row, row._today)
  if (st === 'open') return { text: '进行中', type: 'success' }
  if (st === 'future') return { text: '未开始', type: 'info' }
  return { text: '已截止', type: 'warning' }
}

onMounted(() => {
  loadWindows()
  loadSettings()
})
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
      <span style="font-size:18px;font-weight:700;margin-right:12px;">填报窗口设置</span>
      <span style="font-size:13px;color:#666;margin-right:6px;">价格异常环比阈值</span>
      <el-input-number v-model="threshold" :min="1" :max="200" :step="5" size="small"
        style="width:110px;margin-right:8px" />
      <span style="font-size:13px;color:#999;margin-right:10px;">%</span>
      <el-button size="small" :loading="thresholdSaving" @click="saveThreshold">保存阈值</el-button>
      <el-button :loading="genLoading" style="margin-left:8px" @click="genWindows">✨ 一键生成未来12个月窗口</el-button>
      <el-button type="primary" @click="openCreate">新增窗口</el-button>
    </div>

    <el-alert type="info" :closable="false" style="margin:12px 0"
      title="每月 20-23 日为填报窗口，超出截止时间项目人员无法再填报、修改。系统在窗口开放时自动放行，未到或已过自动拦截。" />

    <el-table v-loading="loading" :data="rows" border stripe>
      <el-table-column prop="month" label="统计月份" width="130" />
      <el-table-column prop="start_date" label="开始日期" width="130" />
      <el-table-column prop="end_date" label="截止日期" width="130" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status == 1 ? 'success' : 'info'">{{ row.status == 1 ? '开放' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="当前" width="90">
        <template #default="{ row }">
          <el-tag size="small" :type="curMeta(row).type">{{ curMeta(row).text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          <el-button link :type="row.status == 1 ? 'warning' : 'success'" size="small" @click="toggleStatus(row)">
            {{ row.status == 1 ? '停用' : '启用' }}
          </el-button>
          <el-button link type="danger" size="small" @click="removeWindow(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="editRow ? '编辑窗口' : '新增窗口'" width="460px">
      <el-form label-width="100px">
        <el-form-item label="统计月份" required>
          <el-date-picker v-model="form.month" type="month" value-format="YYYY-MM" style="width:100%" />
        </el-form-item>
        <el-form-item label="开始日期" required>
          <el-date-picker v-model="form.start_date" type="date" value-format="YYYY-MM-DD"
            style="width:100%" placeholder="如：2026-09-20" />
        </el-form-item>
        <el-form-item label="截止日期" required>
          <el-date-picker v-model="form.end_date" type="date" value-format="YYYY-MM-DD"
            style="width:100%" placeholder="如：2026-09-23" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="开放" inactive-text="停用" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveWindow">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
