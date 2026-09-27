<script setup>
// 填报进度确认（复刻旧 #/overview：按项目总览 / 按条线明细，单条与批量确认·退回·微调·删除，月度归档）
import { ref, computed, onMounted } from 'vue'
import { ElMessageBox } from 'element-plus'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { FILL_LINES } from './fillLogic'
import { monthShort, lineTagType } from './adminLogic'
import OverviewItems from './OverviewItems.vue'

const now = new Date()
const month = ref(now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0'))
const mode = ref('project') // project | line
const rows = ref([])
const lines = ref(FILL_LINES)
const loading = ref(false)
const archived = ref(false)
const summary = ref(null)

// 按条线视图
const lineFilter = ref('')
const lineItems = ref([])
const lineLoading = ref(false)

// 项目明细抽屉
const drawerVisible = ref(false)
const drawerRow = ref(null)
const drawerTitle = ref('')
const drawerAll = ref([])
const drawerLine = ref('')
const drawerShown = computed(() =>
  drawerLine.value ? drawerAll.value.filter((r) => r.line === drawerLine.value) : drawerAll.value
)
const drawerPending = computed(() => drawerShown.value.filter((r) => r.status !== 'confirmed').length)
const drawerReturnable = computed(() =>
  drawerShown.value.filter((r) => r.status === 'submitted' || r.status === 'confirmed').length
)

// 退回弹窗
const retVisible = ref(false)
const retMode = ref('item') // item | project
const retRow = ref(null)
const retReason = ref('')
const retSaving = ref(false)

// 微调弹窗
const editVisible = ref(false)
const editRow = ref(null)
const editSaving = ref(false)

function money2(n) {
  return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function load() {
  loading.value = true
  try {
    const d = await api('/api/purchase/admin/overview?month=' + month.value)
    rows.value = d.data.rows
    lines.value = d.data.lines
    summary.value = d.data.summary
    archived.value = d.data.archived
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

async function doArchive() {
  try {
    await ElMessageBox.confirm(
      '归档后本月将锁定为最终版：员工只读，管理员不能再确认/退回/微调/删除，报价也不能再导入（导出不受影响）。确认归档？',
      '归档确认',
      { type: 'warning', confirmButtonText: '确认归档', cancelButtonText: '再想想' }
    )
  } catch (e) {
    return
  }
  try {
    const d = await api('/api/purchase/admin/archive', { body: { month: month.value } })
    toast(d.msg || '本月已归档')
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function doUnarchive() {
  try {
    await ElMessageBox.confirm(
      '撤销归档后本月恢复可编辑（可再次归档）。确认撤销？',
      '撤销归档',
      { type: 'warning', confirmButtonText: '撤销归档', cancelButtonText: '取消' }
    )
  } catch (e) {
    return
  }
  try {
    const d = await api('/api/purchase/admin/unarchive', { body: { month: month.value } })
    toast(d.msg || '已撤销归档')
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function removeItem(row) {
  try {
    await ElMessageBox.confirm(
      `删除「${row.item_name}」（${row.project_name} · ${row.line}）？\n该操作不可恢复；若本月已导入存档价格，将同步删除对应存档行。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }
    )
  } catch (e) {
    return
  }
  try {
    await api('/api/purchase/fill/items/' + row.id, { method: 'DELETE' })
    toast('已删除')
    await reloadContext()
  } catch (e) {
    toast(e.message, false)
  }
}

function onModeChange(v) {
  if (v === 'line' && !lineFilter.value) {
    lineFilter.value = lines.value[0] || '环境'
    loadLine()
  }
}

async function loadLine() {
  lineLoading.value = true
  try {
    const d = await api('/api/purchase/fill/items?month=' + month.value + '&line=' + encodeURIComponent(lineFilter.value))
    lineItems.value = d.data
  } catch (e) {
    toast(e.message, false)
  } finally {
    lineLoading.value = false
  }
}

async function openProject(row) {
  drawerRow.value = row
  drawerLine.value = ''
  drawerTitle.value = `${row.project_name} · ${month.value} 全部条线明细`
  drawerVisible.value = true
  await fetchDrawer()
}

async function openProjectLine(row, ln) {
  await openProject(row)
  drawerLine.value = ln
  drawerTitle.value = `${row.project_name} · ${month.value} ${ln}条线明细`
}

async function fetchDrawer() {
  const d = await api('/api/purchase/fill/items?month=' + month.value + '&project_id=' + drawerRow.value.project_id)
  drawerAll.value = d.data
}

// 写操作后按所在视图刷新（条线视图或抽屉），并刷新总览
async function reloadContext() {
  if (mode.value === 'line') {
    await loadLine()
  } else if (drawerVisible.value) {
    await fetchDrawer()
  }
  load()
}

async function confirmDrawerLine() {
  try {
    const d = await api('/api/purchase/admin/confirm-batch', {
      body: { month: month.value, project_id: drawerRow.value.project_id, line: drawerLine.value },
    })
    toast(d.msg || `${drawerRow.value.project_name} · ${drawerLine.value}条线已确认`)
    await fetchDrawer()
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function confirmItem(row) {
  try {
    await api('/api/purchase/admin/items/' + row.id + '/confirm', { method: 'POST' })
    toast('已确认')
    await reloadContext()
  } catch (e) {
    toast(e.message, false)
  }
}

async function unconfirmItem(row) {
  try {
    await api('/api/purchase/admin/items/' + row.id + '/unconfirm', { method: 'POST' })
    toast('已取消确认（员工可再次修改）')
    await reloadContext()
  } catch (e) {
    toast(e.message, false)
  }
}

function openReturnItem(row) {
  retMode.value = 'item'
  retRow.value = row
  retReason.value = ''
  retVisible.value = true
}

function openReturnProject(row) {
  retMode.value = 'project'
  retRow.value = row
  retReason.value = ''
  retVisible.value = true
}

async function submitReturn() {
  if (!retReason.value.trim()) {
    toast('请填写退回原因（填报人将看到）', false)
    return
  }
  retSaving.value = true
  try {
    if (retMode.value === 'project') {
      await api('/api/purchase/admin/return-project', {
        body: { month: month.value, project_id: retRow.value.project_id, reason: retReason.value.trim() },
      })
      toast(`已退回「${retRow.value.project_name}」全部待确认记录给填报人修改`)
    } else {
      await api('/api/purchase/admin/items/' + retRow.value.id + '/return', {
        body: { reason: retReason.value.trim() },
      })
      toast(`已退回「${retRow.value.item_name}」给填报人修改`)
    }
    retVisible.value = false
    await reloadContext()
  } catch (e) {
    toast(e.message, false)
  } finally {
    retSaving.value = false
  }
}

async function confirmLine(row, ln) {
  try {
    const d = await api('/api/purchase/admin/confirm-batch', {
      body: { month: month.value, project_id: row.project_id, line: ln },
    })
    toast(d.msg || `${row.project_name} · ${ln}条线已确认`)
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function confirmAllProject(row) {
  try {
    const d = await api('/api/purchase/admin/confirm-batch', {
      body: { month: month.value, project_id: row.project_id },
    })
    toast(d.msg || `${row.project_name} 全部条目已确认`)
    if (drawerVisible.value) await fetchDrawer()
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function batchConfirmPage() {
  const pending = lineItems.value.filter((r) => r.status !== 'confirmed')
  if (!pending.length) return
  const groups = {}
  pending.forEach((r) => {
    ;(groups[r.project_id] = groups[r.project_id] || []).push(r)
  })
  let msg = ''
  try {
    for (const pid of Object.keys(groups)) {
      const d = await api('/api/purchase/admin/confirm-batch', {
        body: { month: month.value, project_id: Number(pid), line: lineFilter.value },
      })
      msg = d.msg || msg
    }
    toast(msg || `已批量确认 ${pending.length} 条（${lineFilter.value}条线）`)
    await loadLine()
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

function openEdit(row) {
  editRow.value = { ...row }
  editVisible.value = true
}

async function saveEdit() {
  const c = editRow.value
  if (!c.quantity || c.quantity <= 0) {
    toast('数量必须大于 0', false)
    return
  }
  editSaving.value = true
  try {
    await api('/api/purchase/fill/items/' + c.id, {
      method: 'PUT',
      body: {
        month: month.value,
        line: c.line,
        item_name: c.item_name,
        brand: c.brand,
        spec: c.spec,
        unit: c.unit,
        quantity: c.quantity,
        stock: c.stock,
        reason: c.reason,
        use_location: c.use_location,
        status: c.status,
      },
    })
    toast('微调已保存')
    editVisible.value = false
    await reloadContext()
  } catch (e) {
    toast(e.message, false)
  } finally {
    editSaving.value = false
  }
}

const pagePendingCount = computed(() => lineItems.value.filter((r) => r.status !== 'confirmed').length)

onMounted(load)
</script>

<template>
  <div>
    <div style="padding:14px 16px;background:#fff;border-radius:10px;margin-bottom:12px;">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:18px;font-weight:700;">填报进度确认</span>
        <el-date-picker v-model="month" type="month" value-format="YYYY-MM" :clearable="false"
          style="width:130px" @change="load" />
        <el-radio-group v-model="mode" size="small" @change="onModeChange">
          <el-radio-button value="project">按项目</el-radio-button>
          <el-radio-button value="line">按条线</el-radio-button>
        </el-radio-group>
        <el-button size="small" type="primary" plain :loading="loading" @click="load">刷新</el-button>
      </div>
    </div>

    <!-- 状态横幅 -->
    <el-alert v-if="archived" type="success" :closable="false" show-icon style="margin-bottom:14px"
      title="本月已归档锁定（最终版）：员工只读，确认/退回/微调/删除/报价导入均不可用，导出不受影响">
      <div style="margin-top:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="color:#67c23a">✓ 最终版已锁定</span>
        <el-button size="small" plain type="warning" @click="doUnarchive">撤销归档（恢复编辑）</el-button>
      </div>
    </el-alert>
    <el-alert v-else-if="summary && summary.total_items > 0 && summary.pending === 0 && summary.returned === 0"
      type="info" :closable="false" show-icon style="margin-bottom:14px"
      :title="`本月 ${summary.total_items} 条已全部确认，可归档为最终版（归档后所有修改/导入锁定，仅可导出）`">
      <div style="margin-top:6px;">
        <el-button size="small" type="success" @click="doArchive">归档确认（最终版锁定）</el-button>
      </div>
    </el-alert>
    <div v-else-if="summary && summary.total_items > 0 && summary.pending > 0" style="margin-bottom:14px;color:#999;font-size:12px;">
      提示：本月还有 {{ summary.pending }} 条未确认（含草稿/已提交/已退回），全部确认后方可「归档确认」锁定最终版。
    </div>

    <el-alert v-if="summary && summary.unfilled_count > 0" type="warning" :closable="false" show-icon
      style="margin-bottom:14px"
      :title="`${summary.unfilled_count} 个项目尚未填报：${summary.unfilled.map((t) => t.project_name).join('、')}（填报窗口每月20-23日）`" />

    <div v-if="summary && summary.total_items > 0" style="margin-bottom:14px;color:#555;font-size:13px;">
      本月共填报 <b style="color:#2b5a9e">{{ summary.total_items }}</b> 条 · 已确认 <b style="color:#67c23a">{{ summary.confirmed }}</b> 条 ·
      <span v-if="summary.returned > 0" style="color:#e64545">已退回 <b>{{ summary.returned }}</b> 条 ·</span>
      待确认 <b style="color:#e6a23c">{{ summary.pending }}</b> 条
    </div>

    <!-- 按项目 -->
    <template v-if="mode === 'project'">
      <el-table v-loading="loading" :data="rows" border stripe>
        <el-table-column label="项目" min-width="140" fixed="left">
          <template #default="{ row }">
            <el-link type="primary" :underline="false" @click="openProject(row)"><b>{{ row.project_name }}</b></el-link>
          </template>
        </el-table-column>
        <el-table-column v-for="ln in lines" :key="ln" :label="ln" min-width="130">
          <template #default="{ row }">
            <template v-if="row.lines[ln] && row.lines[ln].total > 0">
              <div style="font-size:12px;line-height:1.7">
                <el-tag size="small" :type="lineTagType(row.lines[ln])">{{ row.lines[ln].total }} 条</el-tag>
                <span style="margin-left:6px;color:#999">已确认 {{ row.lines[ln].confirmed }}/{{ row.lines[ln].total }}</span>
                <div style="margin-top:4px">
                  <el-button size="small" link type="primary" @click="openProjectLine(row, ln)">查看明细</el-button>
                  <el-button v-if="row.lines[ln].confirmed < row.lines[ln].total" size="small" link type="success"
                    @click="confirmLine(row, ln)">确认该条线</el-button>
                </div>
              </div>
            </template>
            <span v-else style="color:#ccc">未填报</span>
          </template>
        </el-table-column>
        <el-table-column label="合计" width="130" fixed="right">
          <template #default="{ row }">
            <div style="font-size:13px">
              <b>{{ row.total }}</b> 条
              <div v-if="row.total > 0" style="color:#67c23a;font-size:12px">已确认 {{ row.confirmed }}</div>
              <div v-if="row.total > 0 && row.confirmed < row.total" style="color:#e6a23c;font-size:12px">
                待确认 {{ row.total - row.confirmed }}
              </div>
              <div v-if="row.returned > 0"
                style="margin-top:3px;font-size:12px;border-top:1px dashed #e0e6ef;padding-top:3px;color:#e64545">
                已退回 {{ row.returned }} 条
              </div>
              <div v-if="row.budget > 0 || row.amount > 0"
                style="margin-top:3px;font-size:12px;border-top:1px dashed #e0e6ef;padding-top:3px">
                <div>预算 ¥{{ money2(row.budget) }}</div>
                <div :style="row.over ? 'color:#e64545;font-weight:600' : 'color:#2b5a9e'"> 已导入 ¥{{ money2(row.amount) }}</div>
                <div v-if="row.budget > 0" style="margin-top:2px">
                  <el-tag :type="row.over ? 'danger' : 'success'" size="small" effect="dark" style="margin-right:4px">
                    {{ row.over ? '超支' : '预算内' }}
                  </el-tag>
                  <span :style="row.over ? 'color:#e64545;font-weight:600' : 'color:#666'">{{ row.rate }}%</span>
                </div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="最后填报" width="132" fixed="right">
          <template #default="{ row }">
            <span v-if="row.last_fill" style="color:#666;font-size:12px">{{ monthShort(row.last_fill) }}</span>
            <span v-else style="color:#ccc;font-size:12px">未填报</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.total > 0 && row.confirmed < row.total && !archived" size="small" type="success" plain
              @click="confirmAllProject(row)">全部确认</el-button>
            <el-button v-if="row.total > 0 && row.confirmed > 0 && !archived" size="small" type="danger" plain
              @click="openReturnProject(row)">退回</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="summary && summary.total_items > 0"
        style="margin-top:12px;padding:12px 16px;background:#f7f9fc;border:1px solid #e4ebf5;border-radius:8px;font-size:13px;color:#333;display:flex;gap:26px;flex-wrap:wrap;align-items:center">
        <b style="color:#1e3a5f">月度合计</b>
        <span>共 <b style="color:#2b5a9e">{{ summary.total_items }}</b> 条</span>
        <span>已确认 <b style="color:#67c23a">{{ summary.confirmed }}</b> 条</span>
        <span v-if="summary.returned > 0">已退回 <b style="color:#e64545">{{ summary.returned }}</b> 条</span>
        <span>待确认 <b style="color:#e6a23c">{{ summary.pending }}</b> 条</span>
        <span style="color:#2b5a9e">预算 ¥{{ money2(summary.total_budget) }}</span>
        <span :style="summary.total_rate > 100 ? 'color:#e64545;font-weight:600' : 'color:#2b5a9e'">
          已导入 ¥{{ money2(summary.total_amount) }}
        </span>
        <span v-if="summary.total_budget > 0">
          <el-tag :type="summary.total_rate > 100 ? 'danger' : 'success'" size="small" effect="dark" style="margin-right:4px">
            {{ summary.total_rate > 100 ? '超支' : '预算内' }}
          </el-tag>
          执行率 {{ summary.total_rate }}%
        </span>
      </div>
      <div style="padding:8px 2px;color:#999;font-size:12px">
        点击项目名称查看该项目全部条线明细（含微调、单条确认/取消确认/退回）；预算在「预算管理」中按项目×月设置，超预算项将红色高亮
      </div>
    </template>

    <!-- 按条线 -->
    <template v-else>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
        <span style="color:#666;font-size:13px">条线：</span>
        <el-select v-model="lineFilter" style="width:140px" @change="loadLine">
          <el-option v-for="ln in lines" :key="ln" :label="ln" :value="ln" />
        </el-select>
        <span style="color:#999;font-size:12px">查看所有项目 · {{ lineFilter || '—' }}条线 的填报明细</span>
        <span style="flex:1 1 auto"></span>
        <el-button v-if="!archived && pagePendingCount > 0" size="small" type="success" plain @click="batchConfirmPage">
          批量确认本页（{{ pagePendingCount }} 条）
        </el-button>
      </div>
      <OverviewItems :items="lineItems" :loading="lineLoading" :archived="archived"
        @confirm="confirmItem" @unconfirm="unconfirmItem" @edit="openEdit"
        @return-item="openReturnItem" @remove="removeItem" />
    </template>

    <!-- 项目明细抽屉 -->
    <el-drawer v-model="drawerVisible" :title="drawerTitle" size="80%">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
        <span style="color:#666;font-size:13px">条线筛选：</span>
        <el-select v-model="drawerLine" style="width:130px">
          <el-option label="全部条线" value="" />
          <el-option v-for="ln in lines" :key="ln" :label="ln" :value="ln" />
        </el-select>
        <span style="color:#999;font-size:12px">共 {{ drawerShown.length }} 条，待确认 {{ drawerPending }} 条</span>
        <span style="flex:1 1 auto"></span>
        <el-button v-if="!archived && drawerRow && drawerRow.lines" size="small" type="success" plain
          :disabled="!drawerLine" @click="confirmDrawerLine">确认{{ drawerLine }}条线</el-button>
        <el-button v-if="!archived" size="small" type="success" @click="confirmAllProject(drawerRow)">全部确认</el-button>
        <el-button v-if="!archived && drawerReturnable > 0" size="small" type="danger" plain
          @click="openReturnProject(drawerRow)">全部退回</el-button>
      </div>
      <OverviewItems :items="drawerShown" :archived="archived"
        @confirm="confirmItem" @unconfirm="unconfirmItem" @edit="openEdit"
        @return-item="openReturnItem" @remove="removeItem" />
      <template #footer>
        <el-button @click="drawerVisible = false">关闭</el-button>
      </template>
    </el-drawer>

    <!-- 退回弹窗 -->
    <el-dialog v-model="retVisible" title="退回修改" width="480px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom:12px"
        :title="retMode === 'project'
          ? `将退回「${retRow ? retRow.project_name : ''}」${month} 全部已提交/已确认的记录，填报人修改后需重新提交`
          : `将退回「${retRow ? retRow.item_name : ''}」（${retRow ? retRow.project_name : ''}），填报人修改后需重新提交`" />
      <el-form label-width="90px">
        <el-form-item label="退回原因" required>
          <el-input v-model="retReason" type="textarea" :rows="3" maxlength="200" show-word-limit
            placeholder="请填写具体原因，填报人将在其界面看到并修改（如：数量过多，请按实际需求调整；规格选错请更换）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="retVisible = false">取消</el-button>
        <el-button type="danger" :loading="retSaving" @click="submitReturn">确认退回</el-button>
      </template>
    </el-dialog>

    <!-- 微调弹窗 -->
    <el-dialog v-model="editVisible" title="微调填报内容（招采）" width="480px">
      <el-form v-if="editRow" label-width="90px">
        <el-form-item label="商品">
          <b>{{ editRow.item_name }}</b>
          <span style="color:#999;margin-left:8px">{{ editRow.spec }} · {{ editRow.unit }}</span>
        </el-form-item>
        <el-form-item label="数量">
          <el-input-number v-model="editRow.quantity" :min="0" :precision="2" style="width:100%" />
        </el-form-item>
        <el-form-item label="库存">
          <el-input-number v-model="editRow.stock" :min="0" :precision="2" style="width:100%" />
        </el-form-item>
        <el-form-item label="申购原因">
          <el-input v-model="editRow.reason" />
        </el-form-item>
        <el-form-item v-if="editRow.line === '工程'" label="使用位置">
          <el-input v-model="editRow.use_location" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="editSaving" @click="saveEdit">保存微调</el-button>
      </template>
    </el-dialog>
  </div>
</template>
