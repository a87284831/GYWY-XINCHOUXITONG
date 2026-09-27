<script setup>
// 预算管理（复刻旧 #/budget：按项目×月设置预算 + 全年速览 + 全年模板导入）
import { ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { money } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'
import { budgetStats, budgetRowState, budgetYears, hasBudgetMonth } from './adminLogic'

const auth = useAuthStore()
const now = new Date()
const month = ref(now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0'))
const rows = ref([])
const loading = ref(false)
const saving = ref(false)
const importVisible = ref(false)
const importYear = ref(now.getFullYear())
const importFile = ref(null)
const importName = ref('')
const importing = ref(false)
const fileInput = ref(null)
const budgetMonths = ref([])

const years = budgetYears(now)
const year = computed(() => String(month.value).slice(0, 4))
const stats = computed(() => budgetStats(rows.value))

async function loadMonthsMarks() {
  try {
    const d = await api('/api/purchase/budgets/months?year=' + year.value)
    budgetMonths.value = (d.data && d.data.months) || []
  } catch (e) {
    budgetMonths.value = []
  }
}

function pickMonth(n) {
  month.value = year.value + '-' + String(n).padStart(2, '0')
  loadRows()
}

async function loadRows() {
  loading.value = true
  try {
    const d = await api('/api/purchase/budgets?month=' + month.value)
    rows.value = d.data.rows
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

async function saveMonth() {
  saving.value = true
  try {
    await api('/api/purchase/budgets', {
      body: {
        month: month.value,
        user_id: auth.user && auth.user.id ? auth.user.id : null,
        rows: rows.value.map((r) => ({ project_id: r.project_id, amount: Number(r.budget) || 0 })),
      },
    })
    toast('本月预算已保存')
    await loadRows()
    loadMonthsMarks()
  } catch (e) {
    toast(e.message, false)
  } finally {
    saving.value = false
  }
}

function downloadTemplate() {
  const y = importYear.value || now.getFullYear()
  download('/api/purchase/budgets/template?year=' + y, '广盈物业_' + y + '年度采购预算导入模板.xlsx')
}

function onFileChange(e) {
  const f = e.target.files[0]
  if (f) {
    importFile.value = f
    importName.value = f.name
  }
}

async function doImport() {
  if (!importFile.value) {
    toast('请选择模板文件', false)
    return
  }
  const fd = new FormData()
  fd.append('year', importYear.value)
  fd.append('file', importFile.value)
  fd.append('user_id', (auth.user && auth.user.id) || 0)
  importing.value = true
  try {
    const d = await api('/api/purchase/budgets/import', { method: 'POST', form: fd })
    toast(d.msg || '导入完成')
    importVisible.value = false
    importFile.value = null
    importName.value = ''
    if (fileInput.value) fileInput.value.value = ''
    await loadRows()
    loadMonthsMarks()
  } catch (e) {
    toast(e.message, false)
  } finally {
    importing.value = false
  }
}

onMounted(() => {
  loadRows()
  loadMonthsMarks()
})
</script>

<template>
  <div>
    <div style="font-size:18px;font-weight:700;">预算管理</div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0 12px;">
      <span style="color:#999;font-size:12px;">按项目 × 月设置月度采购预算（各条线合计总金额）</span>
      <el-date-picker v-model="month" type="month" value-format="YYYY-MM" :clearable="false"
        style="width:130px" @change="loadRows" />
      <el-button size="small" type="primary" plain :loading="loading" @click="loadRows">刷新</el-button>
      <span style="width:1px;height:18px;background:#e0e6ef;"></span>
      <el-button size="small" @click="downloadTemplate">下载全年导入模板</el-button>
      <el-button size="small" type="primary" @click="importVisible = true">导入全年预算</el-button>
    </div>

    <!-- 全年速览 -->
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;background:#f7fafd;border:1px solid #eef0f5;border-radius:10px;padding:10px 14px;margin-bottom:12px;">
      <span style="font-size:12px;color:#666;white-space:nowrap;">全年速览：</span>
      <div v-for="n in 12" :key="n" @click="pickMonth(n)"
        :style="{
          cursor:'pointer', padding:'5px 11px', borderRadius:'8px', fontSize:'12px',
          border:'1px solid #e0e6ef', background:'#fff', userSelect:'none',
          ...(n === Number(month.slice(5))
            ? { background:'#2b5a9e', color:'#fff', borderColor:'#2b5a9e', fontWeight:600 }
            : hasBudgetMonth(budgetMonths, Number(year), n)
              ? { background:'#e8f5e9', color:'#16a34a', borderColor:'#b7e3bb' }
              : {}),
        }">
        {{ n }}月<span v-if="hasBudgetMonth(budgetMonths, Number(year), n)"> ✓</span>
      </div>
      <span style="font-size:11px;color:#999;margin-left:4px;">✓ = 该月已设置预算，点击可直接切换填写</span>
    </div>

    <!-- KPI -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
      <div style="flex:'1 1 170px';min-width:150px;padding:'10px 14px';background:#fff;border:'1px solid #eef0f5';border-radius:10px;">
        <div style="font-size:12px;color:#6B7280;">{{ month }} 预算合计</div>
        <div style="font-size:19px;font-weight:600;color:#2b5a9e;margin-top:4px;">¥{{ money(stats.budget) }}</div>
        <div style="font-size:11px;color:#999;margin-top:2px;">已填写 {{ stats.filledCount }}/{{ rows.length }} 项目</div>
      </div>
      <div style="flex:'1 1 170px';min-width:150px;padding:'10px 14px';background:#fff;border:'1px solid #eef0f5';border-radius:10px;">
        <div style="font-size:12px;color:#6B7280;">{{ month }} 已导入实际</div>
        <div style="font-size:19px;font-weight:600;color:#1A1B1C;margin-top:4px;">¥{{ money(stats.actual) }}</div>
        <div style="font-size:11px;color:#16a34a;margin-top:2px;">
          {{ stats.remain >= 0 ? '剩余 ¥' + money(stats.remain) : '超支 ¥' + money(Math.abs(stats.remain)) }}
        </div>
      </div>
      <div style="flex:'1 1 170px';min-width:150px;padding:'10px 14px';background:#fff;border:'1px solid #eef0f5';border-radius:10px;">
        <div style="font-size:12px;color:#6B7280;">综合执行率</div>
        <div style="font-size:19px;font-weight:600;color:#1A1B1C;margin-top:4px;">{{ stats.rate }}%</div>
        <div style="font-size:11px;color:#2b5a9e;margin-top:2px;">{{ stats.rate > 100 ? '超预算' : stats.budget > 0 ? '预算内' : '未设预算' }}</div>
      </div>
      <div style="flex:'1 1 170px';min-width:150px;padding:'10px 14px';background:#fff;border:'1px solid #eef0f5';border-radius:10px;">
        <div style="font-size:12px;color:#6B7280;">超预算项目</div>
        <div style="font-size:19px;font-weight:600;color:#e64545;margin-top:4px;">{{ stats.overCount }} 个</div>
        <div style="font-size:11px;color:#999;margin-top:2px;">{{ stats.overCount > 0 ? '需关注调整' : '全部预算内' }}</div>
      </div>
    </div>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom:14px"
      title="预算用于对比：导出填价导入后，系统按项目汇总已导入金额与预算对比，超预算将红色标记，可一键退回填报人调整删除后重新提交。也可下载「全年导入模板」一次填写全年每项目每月预算后上传导入。" />

    <el-table v-loading="loading" :data="rows" border stripe
      :header-cell-style="{ background:'#eef4fb', color:'#2b5a9e', fontWeight:600 }">
      <el-table-column prop="project_name" label="项目" min-width="150">
        <template #default="{ row }"><b style="color:#2b5a9e">{{ row.project_name }}</b></template>
      </el-table-column>
      <el-table-column label="月度预算（元）" width="180">
        <template #default="{ row }">
          <el-input-number v-model="row.budget" :min="0" :precision="2" :controls="false"
            style="width:100%" placeholder="0.00" />
        </template>
      </el-table-column>
      <el-table-column label="已导入金额（元）" width="150" align="right">
        <template #default="{ row }">
          <span :style="{ color: row.over ? '#e64545' : row.actual > 0 ? '#2b5a9e' : '#ccc', fontWeight: row.over ? 600 : 400 }">
            {{ money(row.actual) }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="差值（元）" width="140" align="right">
        <template #default="{ row }">
          <span v-if="row.budget > 0" :style="{ color: row.diff < 0 ? '#e64545' : '#666', fontWeight: row.diff < 0 ? 600 : 400 }">
            {{ row.diff >= 0 ? '+' : '' }}{{ money(row.diff) }}
          </span>
          <span v-else style="color:#ccc">--</span>
        </template>
      </el-table-column>
      <el-table-column label="预算执行率" width="160" align="right">
        <template #default="{ row }">
          <div v-if="row.budget > 0" style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
            <div style="flex:1;max-width:80px;height:10px;background:#e0e6ef;border-radius:5px;overflow:hidden;">
              <div :style="{ height:'10px', width:Math.min(row.rate, 100) + '%', background: row.rate > 100 ? '#e64545' : '#2b5a9e', borderRadius:'5px' }"></div>
            </div>
            <span :style="{ color: row.rate > 100 ? '#e64545' : row.actual > 0 ? '#2b5a9e' : '#ccc', fontWeight: row.rate > 100 ? 600 : 400 }">{{ row.rate }}%</span>
          </div>
          <span v-else style="color:#ccc">--</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="100" align="center">
        <template #default="{ row }">
          <el-tag v-if="budgetRowState(row) === 'over'" type="danger" size="small" effect="dark">超预算</el-tag>
          <el-tag v-else-if="budgetRowState(row) === 'within'" type="success" size="small">预算内</el-tag>
          <el-tag v-else-if="budgetRowState(row) === 'noimport'" type="info" size="small">未导入</el-tag>
          <el-tag v-else type="info" size="small" effect="plain">未设预算</el-tag>
        </template>
      </el-table-column>
    </el-table>

    <div style="margin-top:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:#f7fafd;border-radius:8px;padding:10px 14px;">
      <div style="font-size:13px;color:#555;"> 合计预算：<b style="color:#2b5a9e">¥{{ money(stats.budget) }}</b></div>
      <div style="font-size:13px;color:#555;"> 合计已导入：<b :style="{ color: stats.actual > stats.budget ? '#e64545' : '#2b5a9e' }">¥{{ money(stats.actual) }}</b></div>
      <div v-if="stats.budget > 0" style="font-size:13px;color:#555;">
        合计预算执行率：<b :style="{ color: stats.rate > 100 ? '#e64545' : '#2b5a9e' }">{{ stats.rate }}%</b>
        <el-tag :type="stats.rate > 100 ? 'danger' : 'success'" size="small" style="margin-left:6px">
          {{ stats.rate > 100 ? '超预算' : '预算内' }}
        </el-tag>
      </div>
      <div style="flex:1 1 auto;"></div>
      <el-button type="primary" :loading="saving" @click="saveMonth">保存本月预算</el-button>
    </div>

    <!-- 导入全年预算 -->
    <el-dialog v-model="importVisible" title="导入全年预算" width="520px">
      <el-form label-width="90px">
        <el-form-item label="预算年份">
          <el-select v-model="importYear" style="width:200px">
            <el-option v-for="y in years" :key="y" :label="y + ' 年'" :value="y" />
          </el-select>
        </el-form-item>
        <el-form-item label="模板文件">
          <input ref="fileInput" type="file" accept=".xlsx,.xls" style="display:none" @change="onFileChange" />
          <div style="display:flex;gap:8px;align-items:center;">
            <el-input v-model="importName" placeholder="选择下载的全年预算模板" readonly style="width:240px"
              @click="fileInput && fileInput.click()" />
            <el-button size="small" @click="downloadTemplate">还没模板？先下载</el-button>
          </div>
        </el-form-item>
      </el-form>
      <el-alert type="info" :closable="false" show-icon
        title="模板含全年 12 个月 × 全部项目，Excel 中填写金额后保存上传；空白单元格保持未设置，某项目整行留空表示清空该年该项目预算。" />
      <template #footer>
        <el-button @click="importVisible = false">取消</el-button>
        <el-button type="primary" :loading="importing" :disabled="!importFile" @click="doImport">导入</el-button>
      </template>
    </el-dialog>
  </div>
</template>
