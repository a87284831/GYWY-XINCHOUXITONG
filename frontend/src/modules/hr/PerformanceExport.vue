<template>
  <div>
    <div class="card">
      <h3>绩效导出</h3>
      <div class="pe-grid">
        <label class="pe-field">
          <span>核算月份</span>
          <input type="month" v-model="ym" />
        </label>
        <label class="pe-field">
          <span>项目范围</span>
          <select v-model="project" :disabled="isProjectScope">
            <option v-if="!isProjectScope" value="all">全部项目</option>
            <option v-for="p in auth.projects" :key="p" :value="p">{{ p }}</option>
          </select>
        </label>
        <label class="pe-field">
          <span>绩效发放法</span>
          <select v-model="method">
            <option v-for="m in PERF_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </label>
        <label class="pe-field">
          <span>导出方式</span>
          <select v-model="mode">
            <option v-for="m in PERF_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </label>
      </div>

      <div class="pe-field" style="margin-top:12px">
        <span>人员类别（可多选，每类一张 Sheet）</span>
        <div class="checkbox-list">
          <label v-for="c in PERF_CATS" :key="c.value">
            <input type="checkbox" :checked="cats.includes(c.value)" @change="toggle(c.value)" />
            {{ c.label }}
          </label>
        </div>
      </div>

      <div class="hint">
        {{ methodHint(method) }}
        <template v-if="preview && preview.quarter">
          ｜季度口径：{{ preview.quarter }}（结算月 {{ preview.ym }}，季度数据取季度末月工资行）。
        </template>
      </div>
      <div v-if="quarterWarn" class="pe-alert danger" style="margin-top:8px">
        当前月份 {{ ym }} 不是季度末月（1/4/7/10 月）；所选发放法/类别需要季度数据，
        请切换季度末月或改用「统一按月度绩效法」。
      </div>

      <div class="row" style="margin-top:12px;gap:8px">
        <button class="btn primary" :disabled="!canExport" @click="doExport">导出 Excel</button>
        <button class="btn" :disabled="loading" @click="refreshPreview">刷新预览</button>
        <span class="hint" style="margin:0">{{ loading ? '正在读取核算数据…' : '导出前请先核对下方提示与表样。' }}</span>
      </div>
    </div>

    <div class="card">
      <h3>数据提示（未锁定 / 未核算 / 异常）</h3>
      <div v-if="errorMsg" class="pe-alert danger">{{ errorMsg }}</div>
      <div v-else-if="!preview" class="hint">暂无预览数据。</div>
      <div v-else class="pe-alerts">
        <div v-for="(w, i) in preview.warnings" :key="i" class="pe-alert" :class="w.level">{{ w.text }}</div>
      </div>
    </div>

    <div class="card">
      <h3>将生成的工作表</h3>
      <div v-if="!preview || !preview.sheets.length" class="hint">暂无工作表。</div>
      <div v-else class="pe-sheets">
        <div
          v-for="(s, i) in preview.sheets"
          :key="s.title"
          class="pe-sheet"
          :class="{ active: i === activeSheet }"
          @click="activeSheet = i"
        >
          <div class="pe-sheet-top">
            <b>{{ s.title }}</b>
            <span class="tag blue">{{ s.count }} 行</span>
          </div>
          <div class="hint" style="margin-top:4px">
            发放法：{{ s.method_label }}｜列数：{{ s.columns.length }}
          </div>
          <div class="pe-chips">
            <span v-for="c in s.columns.slice(0, 12)" :key="c.label" class="pe-chip">{{ c.label }}</span>
            <span v-if="s.columns.length > 12" class="pe-chip">…</span>
          </div>
        </div>
        <div v-if="mode !== 'split'" class="pe-sheet">
          <div class="pe-sheet-top">
            <b>汇总</b>
            <span class="tag green">按勾选类别</span>
          </div>
          <div class="hint" style="margin-top:4px">类别 → 项目 → 类别小计 → 总计</div>
          <div class="pe-chips">
            <span v-for="c in ['人员类别', '项目', '发放法', '人数', '固定月薪合计', '标准绩效工资', '本期应发绩效工资', '季度兑现', '月度发放', '半年度兑现']" :key="c" class="pe-chip">{{ c }}</span>
          </div>
        </div>
        <div v-if="mode === 'book'" class="pe-sheet">
          <div class="pe-sheet-top">
            <b>说明</b>
            <span class="tag gray">口径与取数</span>
          </div>
          <div class="hint" style="margin-top:4px">周期归属 / 系数来源 / 计算公式 / 数据提示</div>
          <div class="pe-chips">
            <span v-for="c in ['列名', '取值来源', '口径说明', '异常清单', '出勤系数>1 清单']" :key="c" class="pe-chip">{{ c }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>表样预览</h3>
      <div v-if="!active" class="hint">暂无表样。</div>
      <template v-else>
        <div class="row" style="gap:8px;margin-bottom:8px">
          <label class="fld">工作表
            <select v-model.number="activeSheet">
              <option v-for="(s, i) in preview.sheets" :key="s.title" :value="i">{{ s.title }}</option>
            </select>
          </label>
          <span class="hint" style="margin:0">示意前 {{ active.sample.length }} 行；导出文件包含全部数据与小计行。</span>
        </div>
        <div class="table-wrap" style="max-height:360px">
          <table class="tb">
            <thead>
              <tr>
                <th v-for="c in active.columns" :key="c.label">{{ c.label }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, ri) in active.sample" :key="ri">
                <td v-for="(c, ci) in active.columns" :key="ci" :class="{ num: c.type === 'money' }">
                  {{ fmtCell(row[ci], c) }}
                </td>
              </tr>
              <tr v-if="!active.sample.length">
                <td :colspan="active.columns.length" class="hint">该 Sheet 本期无数据。</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <div class="card">
      <h3>当前发放周期配置</h3>
      <div v-if="!preview" class="hint">加载预览后显示。</div>
      <div v-else class="pe-kv-list">
        <div v-for="c in preview.config" :key="c.label" class="pe-kv">
          <span>{{ c.label }}</span><b>{{ c.value }}</b>
        </div>
      </div>
      <div class="hint">
        发放法跟随「系统设置 → 绩效发放周期」的 pay_rules；系数 0 与 1.0 均按实际值计算，出勤系数不封顶并保留两位小数。
        项目账号仅能导出本项目基层/案场，且需总部核定（归档）后；管理人员与总部人员仅总部账号可见。
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import {
  PERF_CATS, PERF_METHODS, PERF_MODES, DEFAULT_CATS,
  latestQuarterEndMonth, isQuarterEndMonth, needsQuarterEnd, toggleCat,
  perfPreviewUrl, perfExportTarget, methodHint,
} from './performanceExportLogic'

const auth = useAuthStore()
const isProjectScope = computed(() => auth.user && auth.user.scope === 'project')

const ym = ref(latestQuarterEndMonth())
const cats = ref([...DEFAULT_CATS])
const method = ref('follow')
const mode = ref('book')
const project = ref(isProjectScope.value ? (auth.user.project || auth.projects[0] || '') : 'all')

const preview = ref(null)
const loading = ref(false)
const errorMsg = ref('')
const activeSheet = ref(0)
let requestSeq = 0

const quarterWarn = computed(() => needsQuarterEnd(method.value, cats.value) && !isQuarterEndMonth(ym.value))
const canExport = computed(() => cats.value.length > 0 && !loading.value)
const active = computed(() => {
  const sheets = preview.value && preview.value.sheets
  return sheets && sheets[activeSheet.value] ? sheets[activeSheet.value] : null
})

watch(isProjectScope, (val) => {
  if (val) project.value = auth.user.project || auth.projects[0] || ''
})
watch([ym, cats, project, method], () => { refreshPreview() }, { deep: true })
onMounted(refreshPreview)

function toggle(cat) {
  cats.value = toggleCat(cats.value, cat)
}

async function refreshPreview() {
  if (!cats.value.length) {
    preview.value = null
    errorMsg.value = '请至少勾选一类人员类别。'
    return
  }
  const seq = ++requestSeq
  loading.value = true
  errorMsg.value = ''
  try {
    const data = await api(perfPreviewUrl({
      ym: ym.value, cats: cats.value, project: project.value, method: method.value,
    }))
    if (seq !== requestSeq) return
    preview.value = data
    activeSheet.value = 0
  } catch (e) {
    if (seq !== requestSeq) return
    preview.value = null
    errorMsg.value = e.message || '预览失败'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

function doExport() {
  if (!cats.value.length) {
    toast('请至少勾选一类人员类别')
    return
  }
  const target = perfExportTarget({
    ym: ym.value, cats: cats.value, project: project.value, method: method.value, mode: mode.value,
  })
  download(target.url, target.filename)
}

function fmtCell(value, col) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value !== 'number') return String(value)
  if (col.type === 'ratio') return value.toFixed(2)
  if (col.type === 'money') {
    return value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  }
  return String(value)
}
</script>

<style scoped>
.pe-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.pe-field { display: flex; flex-direction: column; gap: 5px; font-size: 12px; color: var(--text-2); }
.pe-field select, .pe-field input { width: 100%; }
.pe-alerts { display: flex; flex-direction: column; gap: 8px; }
.pe-alert { padding: 9px 12px; border-radius: 7px; font-size: 12.5px; line-height: 1.6; border: 1px solid transparent; }
.pe-alert.info { background: var(--info-bg); border-color: #c2d8ff; color: var(--brand-700); }
.pe-alert.ok { background: var(--success-bg); border-color: #c4efcd; color: #0e8a22; }
.pe-alert.warn { background: var(--warning-bg); border-color: #f6dfae; color: #8a5a00; }
.pe-alert.danger { background: var(--danger-bg); border-color: #fbc9c9; color: #c42020; }
.pe-sheets { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.pe-sheet { border: 1px solid var(--border); border-radius: 8px; padding: 12px; cursor: pointer; background: var(--bg-card); transition: border-color .15s, box-shadow .15s; }
.pe-sheet:hover { border-color: var(--brand-600); }
.pe-sheet.active { border-color: var(--brand-600); box-shadow: 0 0 0 3px rgba(22, 93, 255, .08); }
.pe-sheet-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.pe-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
.pe-chip { font-size: 11px; background: var(--bg-subtle); border: 1px solid var(--divider); border-radius: 5px; padding: 1px 6px; color: var(--text-2); }
.pe-kv-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 6px 20px; }
.pe-kv { display: flex; justify-content: space-between; gap: 10px; font-size: 12.5px; color: var(--text-2); padding: 5px 0; border-bottom: 1px dashed var(--divider); }
.pe-kv b { color: var(--text-1); font-weight: 600; text-align: right; }
</style>
