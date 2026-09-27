<script setup>
// 导出报价 / 导入存档（复刻旧 #/export-import：月度导出、年度导出、月度导入+预算对比退回、批量历史导入、存档月份列表）
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { exportMonthName, exportYearName, overBudgetReason, batchSummary } from './systemLogic'

const now = new Date()
const exportMonth = ref(now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0'))
const importMonth = ref(exportMonth.value)
const exporting = ref(false)
const importing = ref(false)
const importFile = ref(null)
const importName = ref('')
const fileInput = ref(null)
const archiveRows = ref([])
const archiveLoading = ref(false)

const years = []
for (let y = now.getFullYear() - 2; y <= now.getFullYear() + 1; y++) years.push(y)
const exportYear = ref(now.getFullYear())
const yearExporting = ref(false)

const batchInput = ref(null)
const batchFiles = ref([])
const batchNames = ref('')
const batchLoading = ref(false)
const batchResults = ref([])

const budgetVisible = ref(false)
const overRows = ref([])
const returnLoading = ref(false)

function money2(n) {
  return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 4000)
}

async function exportMonthXlsx() {
  exporting.value = true
  try {
    const blob = await api('/api/purchase/export?month=' + exportMonth.value)
    saveBlob(blob, exportMonthName(exportMonth.value))
    toast('导出成功，请在下载文件中填写单价')
  } catch (e) {
    toast(e.message || '导出失败', false)
  } finally {
    exporting.value = false
  }
}

async function exportYearXlsx() {
  yearExporting.value = true
  try {
    const blob = await api('/api/purchase/export/year?year=' + exportYear.value)
    saveBlob(blob, exportYearName(exportYear.value))
    toast('当年全部采购明细已导出')
  } catch (e) {
    toast(e.message || '导出失败', false)
  } finally {
    yearExporting.value = false
  }
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
    toast('请选择填价后的Excel文件', false)
    return
  }
  const fd = new FormData()
  fd.append('month', importMonth.value)
  fd.append('file', importFile.value)
  importing.value = true
  try {
    const d = await api('/api/purchase/import', { method: 'POST', form: fd })
    toast(d.msg || '导入完成')
    if (d.unbound && d.unbound.length > 0) {
      const names = d.unbound.slice(0, 5).map((s) => s.name).join('、') + (d.unbound.length > 5 ? '…' : '')
      toast(`本次导入有 ${d.unbound.length} 种商品未绑定商品库（${names}），可在「标准商品库」查看未绑定统计`, false)
    }
    importFile.value = null
    importName.value = ''
    if (fileInput.value) fileInput.value.value = ''
    loadArchive()
    openBudgetCompare(importMonth.value)
  } catch (e) {
    toast(e.message, false)
  } finally {
    importing.value = false
  }
}

async function openBudgetCompare(month) {
  try {
    const d = await api('/api/purchase/budgets/compare?month=' + month)
    const rows = (d.data && d.data.rows) || []
    overRows.value = rows.filter((r) => r.over)
    budgetVisible.value = true
  } catch (e) {
    /* 预算对比失败不阻塞 */
  }
}

async function returnOverBudget() {
  returnLoading.value = true
  try {
    let count = 0
    for (const r of overRows.value) {
      await api('/api/purchase/admin/return-project', {
        body: {
          month: importMonth.value,
          project_id: r.project_id,
          reason: overBudgetReason(r.actual, r.budget),
        },
      })
      count += 1
    }
    toast(`已退回 ${count} 个超预算项目给填报人修改`)
    budgetVisible.value = false
  } catch (e) {
    toast(e.message, false)
  } finally {
    returnLoading.value = false
  }
}

function onBatchChange(e) {
  batchFiles.value = Array.from(e.target.files || [])
  batchNames.value = batchFiles.value.map((f) => f.name).join('；')
}

async function doBatchImport() {
  if (!batchFiles.value.length) return
  batchLoading.value = true
  batchResults.value = []
  try {
    const fd = new FormData()
    // PHP 多文件上传要求同名字段带 []（旧版用 'files' 会被 PHP 折叠为单文件，批量导入实际必现 500——既存生产 bug 修正）
    batchFiles.value.forEach((f) => fd.append('files[]', f))
    const d = await api('/api/purchase/import/batch', { method: 'POST', form: fd })
    batchResults.value = d.files || []
    const { success, fail } = batchSummary(batchResults.value)
    toast(`批量导入完成：成功 ${success} 个文件，失败 ${fail} 个`)
    loadArchive()
  } catch (e) {
    toast(e.message, false)
  } finally {
    batchLoading.value = false
  }
}

// 已存档月份列表：旧版先尝试 dashboard/annual?year= 再逐月补 items/projects；失败回退近 12 个月
async function loadArchive() {
  archiveLoading.value = true
  try {
    const a = await api('/api/purchase/dashboard/annual?year=')
    archiveRows.value = a.data.rows.map((r) => ({
      month: r.month, items: 0, amount: r.amount, projects: 0, imported_at: '',
    }))
    for (const r of archiveRows.value) {
      const m = await api('/api/purchase/dashboard/monthly?month=' + r.month)
      r.items = m.data.overview.items
      r.projects = m.data.overview.projects
    }
  } catch (e) {
    archiveRows.value = []
    for (let i = 0; i < 12; i++) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
      const mm = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
      try {
        const r = await api('/api/purchase/dashboard/monthly?month=' + mm)
        if (r.data.overview.items > 0) {
          archiveRows.value.push({
            month: mm,
            items: r.data.overview.items,
            amount: r.data.overview.amount,
            projects: r.data.overview.projects,
            imported_at: '',
          })
        }
      } catch (err) {
        /* 跳过 */
      }
    }
  } finally {
    archiveLoading.value = false
  }
}

onMounted(loadArchive)
</script>

<template>
  <div>
    <!-- 导出月度报价表 -->
    <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
      <div style="font-size:16px;font-weight:700;margin-bottom:10px;">
        导出报价表
        <el-tag type="info" size="small" style="margin-left:8px;">流程：导出无价表 → 填入单价 → 保存 → 导入存档</el-tag>
      </div>
      <el-form inline>
        <el-form-item label="填报月份">
          <el-date-picker v-model="exportMonth" type="month" value-format="YYYY-MM" :clearable="false"
            style="width:160px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="exporting" @click="exportMonthXlsx">⬇ 导出 Excel（含各条线明细）</el-button>
        </el-form-item>
      </el-form>
      <div style="color:#999;font-size:12px;line-height:1.8">
        导出文件包含「月度采购计划汇总收集表」和 5 张条线明细表（环境类 / 绿化类 / 工程类 / 秩序部 / 行政办公类）。
        单价列为<b style="color:#b45309">参考价</b>（系统自动预填该商品最近一次采购单价，黄色底纹，可直接沿用或修改），合计列已按参考价预算；
        请在 Excel 中核对/修改单价后保存，再通过下方导入功能存档。
      </div>
    </div>

    <!-- 导出年度明细 -->
    <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
      <div style="font-size:16px;font-weight:700;margin-bottom:10px;">导出当年全部采购明细</div>
      <el-form inline>
        <el-form-item label="年份">
          <el-select v-model="exportYear" style="width:130px">
            <el-option v-for="y in years" :key="y" :label="y + ' 年'" :value="y" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" plain :loading="yearExporting" @click="exportYearXlsx">⬇ 导出 {{ exportYear }} 年全部采购明细</el-button>
        </el-form-item>
      </el-form>
      <div style="color:#999;font-size:12px;line-height:1.8">
        导出截至当前 {{ exportYear }} 年已存档的全部采购明细（月份/项目/条线/商品/品牌/规格/单位/数量/单价/金额），含合计行与自动筛选，可直接用于年度汇总分析。
      </div>
    </div>

    <!-- 导入最终版存档 -->
    <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
      <div style="font-size:16px;font-weight:700;margin-bottom:10px;">导入最终版存档</div>
      <el-form inline>
        <el-form-item label="对应月份">
          <el-date-picker v-model="importMonth" type="month" value-format="YYYY-MM" :clearable="false"
            style="width:160px" />
        </el-form-item>
        <el-form-item label="填价后的Excel">
          <input ref="fileInput" type="file" accept=".xlsx,.xls" style="display:none" @change="onFileChange" />
          <el-input v-model="importName" placeholder="选择导出的Excel文件" readonly style="width:260px"
            @click="fileInput && fileInput.click()" />
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="importing" :disabled="!importFile" @click="doImport">⬆ 导入存档</el-button>
        </el-form-item>
      </el-form>
      <el-alert type="warning" :closable="false" style="margin-top:8px"
        title="注意：导入将覆盖该月份已有存档数据。导入后驾驶舱、采购频率统计将按存档数据计算；单价将回写填报记录供员工查看。" />
      <el-alert type="info" :closable="false" style="margin-top:8px"
        title="导入后系统自动对比该月各项目预算（预算管理模块设置），超预算项目会弹出提示，可一键退回填报人调整。" />
    </div>

    <!-- 批量导入历史月份 -->
    <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
      <div style="font-size:16px;font-weight:700;margin-bottom:10px;">批量导入历史月份</div>
      <el-form inline>
        <el-form-item label="历史Excel文件（可多选）">
          <input ref="batchInput" type="file" accept=".xlsx,.xls" multiple style="display:none"
            @change="onBatchChange" />
          <el-input v-model="batchNames" placeholder="选择多个文件（每个文件对应一个月份）" readonly
            style="width:340px" @click="batchInput && batchInput.click()" />
        </el-form-item>
        <el-form-item>
          <el-button type="warning" :loading="batchLoading" :disabled="batchFiles.length === 0" @click="doBatchImport">
            📂 批量导入
          </el-button>
        </el-form-item>
      </el-form>
      <el-alert type="info" :closable="false" style="margin-top:8px"
        title="用于一次性灌入历史全年数据：文件名为「2026-01 月度采购计划.xlsx」或「2026年1月.xlsx」格式（文件名需含年份-月份），系统自动识别月份并逐个导入存档。每个文件的格式需与导出格式一致（含各条线sheet）。" />
      <el-table v-if="batchResults.length" :data="batchResults" border size="small" style="margin-top:10px">
        <el-table-column prop="file" label="文件" min-width="200" show-overflow-tooltip />
        <el-table-column prop="month" label="识别月份" width="110" />
        <el-table-column label="结果" min-width="220">
          <template #default="{ row }">
            <el-tag :type="row.ok ? 'success' : 'danger'" size="small">{{ row.ok ? '成功' : '失败' }}</el-tag>
            <span style="margin-left:8px;color:#666;font-size:12px">{{ row.msg }}</span>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 预算对比结果弹窗 -->
    <el-dialog v-model="budgetVisible" title="预算对比结果" width="620px">
      <el-alert v-if="overRows.length === 0" type="success" :closable="false" show-icon
        title="导入完成，本月所有项目均在预算范围内" />
      <template v-else>
        <el-alert type="danger" :closable="false" show-icon style="margin-bottom:12px"
          :title="`以下 ${overRows.length} 个项目已超预算，可一键退回填报人调整数量或删除，修改后重新提交`" />
        <el-table :data="overRows" border size="small">
          <el-table-column prop="project_name" label="项目" min-width="140" />
          <el-table-column label="预算（元）" width="110" align="right">
            <template #default="{ row }">¥{{ money2(row.budget) }}</template>
          </el-table-column>
          <el-table-column label="已导入（元）" width="120" align="right">
            <template #default="{ row }">¥{{ money2(row.actual) }}</template>
          </el-table-column>
          <el-table-column label="超支（元）" width="120" align="right">
            <template #default="{ row }"><b style="color:#e64545">¥{{ money2(row.actual - row.budget) }}</b></template>
          </el-table-column>
        </el-table>
        <div style="margin-top:12px;color:#999;font-size:12px">
          点「退回超预算项目」后，填报人将收到退回原因并可修改/删除带价格的条目；确认无误后点「定稿」归档为最终版。
        </div>
      </template>
      <template #footer>
        <el-button @click="budgetVisible = false">关闭</el-button>
        <el-button v-if="overRows.length > 0" type="danger" :loading="returnLoading" @click="returnOverBudget">
          退回超预算项目
        </el-button>
      </template>
    </el-dialog>

    <!-- 已存档月份 -->
    <div style="background:#fff;border-radius:10px;padding:14px 16px;">
      <div style="font-size:16px;font-weight:700;margin-bottom:10px;">已存档月份</div>
      <el-table v-loading="archiveLoading" :data="archiveRows" border stripe>
        <el-table-column prop="month" label="月份" width="120" />
        <el-table-column prop="items" label="条目数" width="100" align="right" />
        <el-table-column label="合计金额（元）" width="140" align="right">
          <template #default="{ row }">￥{{ Number(row.amount || 0).toLocaleString() }}</template>
        </el-table-column>
        <el-table-column prop="projects" label="覆盖项目数" width="110" align="right" />
        <el-table-column prop="imported_at" label="最近导入时间" width="170" />
      </el-table>
      <el-empty v-if="!archiveLoading && archiveRows.length === 0" description="暂无存档数据" />
    </div>
  </div>
</template>
