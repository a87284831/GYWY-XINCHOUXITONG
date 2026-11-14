<template>
  <div class="card">
    <h3>导出条件</h3>
    <div class="pe-grid">
      <label class="pe-field"><span>核算月份</span><input type="month" v-model="ui.month" /></label>
    </div>
    <div class="row" style="margin-top:10px;gap:8px">
      <span class="tag blue">数据范围：{{ caps.scopeLabel }}</span>
      <span class="hint" style="margin:0">
        {{ isProjectScope
          ? '项目已锁定为本项目，不提供跨项目入口；全公司类报表仅总部账号可导出。'
          : '总部账号可导出全部项目，或在下方「分项目工资表」中任选一个项目。' }}
      </span>
    </div>
  </div>

  <div class="card">
    <h3>工资表导出 <span class="sub">每项参数只作用于本卡片</span></h3>
    <div class="exp-grid">
      <div class="exp-item">
        <div class="exp-item-top">
          <b>工资全量数据包</b>
          <span class="tag blue">{{ isProjectScope ? '3 类 Sheet' : '5 类 Sheet' }}</span>
        </div>
        <div class="exp-desc">
          {{ isProjectScope
            ? '汇总 + 本项目明细（含本项目案场人员）。'
            : '汇总报表 + 各项目明细 + 管理人员 / 案场人员 / 总部人员。' }}
        </div>
        <div class="exp-foot">
          <span class="hint" :title="`工资全量数据包_${ui.month}.xlsx`">工资全量数据包_{{ ui.month }}.xlsx</span>
          <button class="btn primary sm" @click="go('summary')">导出</button>
        </div>
      </div>

      <div class="exp-item">
        <div class="exp-item-top">
          <b>{{ isProjectScope ? '本项目工资表打包' : '全部项目批量导出' }}</b>
          <span class="tag gray">ZIP</span>
        </div>
        <div class="exp-desc">
          {{ isProjectScope
            ? '本项目基层员工工资表，打包为 zip。'
            : '全部启用项目各一张基层工资表（不含管理/案场），自动打包为 zip。' }}
        </div>
        <div class="exp-foot">
          <span class="hint" :title="zipFilename">{{ zipFilename }}</span>
          <button class="btn primary sm" @click="go('projectsAll')">导出</button>
        </div>
      </div>

      <div class="exp-item">
        <div class="exp-item-top">
          <b>分项目工资表</b>
          <span class="tag gray">按项目</span>
        </div>
        <div class="exp-params">
          <label class="pe-field"><span>项目</span>
            <select v-model="expProj" :disabled="isProjectScope">
              <option v-for="p in caps.salaryProjects" :key="p" :value="p">{{ p }}</option>
            </select>
          </label>
          <label class="pe-field"><span>人员类型</span>
            <select v-model="expType">
              <option v-for="t in caps.projectTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </label>
        </div>
        <div class="exp-desc" style="flex:none">项目与人员类型只作用于本项导出。</div>
        <div class="exp-foot">
          <span class="hint" :title="typedFilename">{{ typedFilename }}</span>
          <button class="btn primary sm" @click="go('projectTyped')">导出</button>
        </div>
      </div>

      <template v-if="caps.canExportCompanyWide">
        <div class="exp-item">
          <div class="exp-item-top">
            <b>管理人员工资表（全公司）</b>
            <span class="tag purple">仅总部</span>
          </div>
          <div class="exp-desc">全部项目管理人员：Sheet1 全员明细，Sheet2 按项目汇总人数/应发/五险一金/个税/实发。</div>
          <div class="exp-foot">
            <span class="hint" :title="`管理人员工资表及汇总_${ui.month}.xlsx`">管理人员工资表及汇总_{{ ui.month }}.xlsx</span>
            <button class="btn primary sm" @click="go('mgrAll')">导出</button>
          </div>
        </div>

        <div class="exp-item">
          <div class="exp-item-top">
            <b>案场人员工资表（全公司）</b>
            <span class="tag purple">仅总部</span>
          </div>
          <div class="exp-desc">全部项目案场人员一张表；项目账号请用「分项目工资表 → 案场人员本科目」导出本项目。</div>
          <div class="exp-foot">
            <span class="hint" :title="`案场人员工资表_${ui.month}.xlsx`">案场人员工资表_{{ ui.month }}.xlsx</span>
            <button class="btn primary sm" @click="go('caseAll')">导出</button>
          </div>
        </div>

        <div class="exp-item">
          <div class="exp-item-top">
            <b>总部人员工资表</b>
            <span class="tag purple">仅总部</span>
          </div>
          <div class="exp-desc">物业公司总部全部人员单独核算结果，仅总部可导出。</div>
          <div class="exp-foot">
            <span class="hint" :title="`总部人员工资表_${ui.month}.xlsx`">总部人员工资表_{{ ui.month }}.xlsx</span>
            <button class="btn primary sm" @click="go('hqAll')">导出</button>
          </div>
        </div>
      </template>
    </div>
    <div v-if="!caps.canExportCompanyWide" class="pe-alert info" style="margin-top:12px">
      管理人员、全公司案场人员、总部人员工资表仅总部账号可导出；本项目人员请用上方「分项目工资表」。
    </div>
    <div class="hint">
      工资表类导出均为 Excel，文件名自动携带核算月份与项目；表结构与核算结果明细一致（39 列，末列季度绩效说明）。
      绩效工资专项表已迁移至「薪资模块 → 绩效导出」。
    </div>
  </div>

  <div class="card">
    <h3>考勤导出 <span class="sub">项目 × 人员类型 × 打包方式</span></h3>
    <div class="pe-grid">
      <label class="pe-field"><span>核算月份</span><input type="month" v-model="ui.month" @change="runPrecheck" /></label>
      <label class="pe-field"><span>项目</span>
        <select v-model="attProject" :disabled="isProjectScope" @change="runPrecheck">
          <option v-if="!isProjectScope" value="all">全部项目</option>
          <option v-for="p in caps.attendanceProjects" :key="p" :value="p">{{ p }}</option>
        </select>
      </label>
      <label class="pe-field"><span>人员类型</span>
        <select v-model="attPersonType" @change="runPrecheck">
          <option v-for="t in ATTENDANCE_EXPORT_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </label>
      <label class="pe-field"><span>打包方式</span>
        <select v-model="attMode" @change="runPrecheck">
          <option value="merged">合并一张表（全部项目含项目列）</option>
          <option value="bundle">全量数据包（每项目一张 Sheet）</option>
        </select>
      </label>
    </div>
    <div class="row" style="margin-top:12px;gap:10px">
      <button class="btn primary" @click="attExport">导出考勤</button>
      <button class="btn" @click="runPrecheck">重新预检</button>
      <span v-if="attLoading" class="hint" style="margin:0">预检中…</span>
    </div>
    <div class="pe-alerts" style="margin-top:10px">
      <div class="pe-alert" :class="attState.level">{{ attState.message }}</div>
      <div class="pe-alert info">只导出已锁定月份；未锁定项目不静默跳过，导出前会列出并确认。全量数据包固定按全部项目打包（项目账号仅本项目）。</div>
    </div>
  </div>

  <div class="card">
    <h3>将生成的文件 <span class="sub">点卡片查看下方表样</span></h3>
    <div class="pe-sheets">
      <div
        v-for="f in generatedFiles" :key="f.key" class="pe-sheet"
        :class="{ active: previewKey === f.key }" @click="previewKey = f.key"
      >
        <div class="pe-sheet-top">
          <b class="exp-file">{{ f.name }}</b>
          <span class="tag" :class="f.tagClass">{{ f.tag }}</span>
        </div>
        <div class="pe-chips"><span v-for="s in f.sheets" :key="s" class="pe-chip">{{ s }}</span></div>
      </div>
    </div>
    <div class="hint">文件名为导出时的默认名称；Sheet 组成随当月数据与权限变化（无数据的类别不会生成 Sheet）。</div>
  </div>

  <div class="card">
    <h3>表样预览</h3>
    <div class="hint">列名与导出模板一致；工资类导出暂无实时预览接口，本卡只示意列结构，数据行以导出文件为准。</div>
    <div class="table-wrap" style="max-height:260px;margin-top:8px">
      <table class="tb">
        <thead><tr><th v-for="c in SAMPLE_COLUMNS" :key="c">{{ c }}</th></tr></thead>
        <tbody>
          <tr><td :colspan="SAMPLE_COLUMNS.length" class="hint">示例列名，数据行需导出后查看</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { download, api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { toast } from '@/utils/toast'
import {
  exportCapabilities, projectExportTarget, attendanceExportTarget, ATTENDANCE_EXPORT_TYPES,
} from './exportLogic'

const auth = useAuthStore()
const ui = useUiStore()
const isAdmin = computed(() => !!(auth.user && auth.user.role === 'admin'))
const isProjectScope = computed(() => !!(auth.user && auth.user.scope === 'project'))

// 权限/范围收口：项目清单、可选人员类型、全公司项是否可用，全部来自这一个对象
const caps = computed(() => exportCapabilities({
  isAdmin: isAdmin.value,
  isProjectScope: isProjectScope.value,
  userProject: auth.user?.project || '',
  projects: auth.projects || [],
}))

const expProj = ref('')
const expType = ref('staff')
const attProject = ref('all')
const attPersonType = ref('all')
const attMode = ref('merged')
const attLoading = ref(false)
const attState = ref({ level: 'info', message: '正在预检本月已锁定项目…' })
const previewKey = ref('summary')

const SAMPLE_COLUMNS = ['序号', '项目', '姓名', '岗位', '固定月薪', '基本工资', '实际出勤', '绩效工资', '应发合计', '实发合计']

const zipFilename = computed(() => isProjectScope.value
  ? `${caps.value.project}_工资表_${ui.month}.zip`
  : `全部项目工资表_${ui.month}.zip`)

const typedFilename = computed(() => {
  try {
    return projectExportTarget(expType.value, ui.month, expProj.value, isAdmin.value, isProjectScope.value, caps.value.forcedProject).filename
  } catch (e) {
    return ''
  }
})

const generatedFiles = computed(() => {
  const ym = ui.month
  const files = [
    {
      key: 'summary', name: `工资全量数据包_${ym}.xlsx`,
      tag: isProjectScope.value ? '3 类 Sheet' : '5 类 Sheet', tagClass: 'blue',
      sheets: isProjectScope.value ? ['汇总', '本项目明细', '案场人员'] : ['汇总', '各项目明细', '管理人员', '案场人员', '总部人员'],
    },
    {
      key: 'zip', name: zipFilename.value, tag: 'ZIP', tagClass: 'gray',
      sheets: isProjectScope.value ? ['本项目基层工资表'] : ['各项目基层工资表'],
    },
    {
      key: 'typed', name: typedFilename.value, tag: '按项目', tagClass: 'gray',
      sheets: [expType.value === 'manager' ? '管理人员工资表' : expType.value === 'case' ? '案场人员工资表' : '工资表'],
    },
  ]
  if (caps.value.canExportCompanyWide) {
    files.push(
      { key: 'mgr', name: `管理人员工资表及汇总_${ym}.xlsx`, tag: '2 个 Sheet', tagClass: 'blue', sheets: ['管理人员工资表', '管理人员汇总'] },
      { key: 'case', name: `案场人员工资表_${ym}.xlsx`, tag: '全公司', tagClass: 'blue', sheets: ['案场人员工资表'] },
      { key: 'hq', name: `总部人员工资表_${ym}.xlsx`, tag: '仅总部', tagClass: 'purple', sheets: ['总部人员工资表'] },
    )
  }
  try {
    const att = attendanceExportTarget({
      ym, project: attProject.value, personType: attPersonType.value, mode: attMode.value, isProjectScope: isProjectScope.value,
    })
    files.push({
      key: 'att', name: att.filename, tag: attMode.value === 'bundle' ? '每项目一张 Sheet' : '1 个 Sheet', tagClass: 'blue',
      sheets: [attMode.value === 'bundle' ? '各项目考勤（每项目一 Sheet）' : (attProject.value === 'all' ? '全部项目合并（含项目列）' : '单项目考勤')],
    })
  } catch (e) {
    // 项目账号未绑定项目时没有可导出的考勤文件，不展示假文件名
  }
  return files
})

onMounted(() => {
  expProj.value = caps.value.project
  attProject.value = caps.value.defaultAttendanceProject
  runPrecheck()
})

function go(mode) {
  const ym = ui.month
  if (mode === 'summary') {
    download(`/api/export/summary?ym=${ym}`, `工资全量数据包_${ym}.xlsx`)
  } else if (mode === 'projectsAll') {
    download(`/api/export/projects_all?ym=${ym}`, zipFilename.value)
  } else if (mode === 'projectTyped') {
    try {
      const target = projectExportTarget(
        expType.value, ym, expProj.value, isAdmin.value, isProjectScope.value, caps.value.forcedProject,
      )
      download(target.url, target.filename)
    } catch (e) {
      toast(e.message || '导出失败', false)
    }
  } else if (mode === 'hqAll') {
    download(`/api/export/hq-staff?ym=${ym}`, `总部人员工资表_${ym}.xlsx`)
  } else if (mode === 'caseAll') {
    download(`/api/export/case-staff?ym=${ym}`, `案场人员工资表_${ym}.xlsx`)
  } else if (mode === 'mgrAll') {
    download(`/api/export/managers-bundle?ym=${ym}`, `管理人员工资表及汇总_${ym}.xlsx`)
  }
}

function attTarget() {
  return attendanceExportTarget({
    ym: ui.month,
    project: attProject.value,
    personType: attPersonType.value,
    mode: attMode.value,
    isProjectScope: isProjectScope.value,
  })
}

// 只读预检：拿到已锁定/未锁定项目，直接展示在页面上（不再等到点导出才弹窗）
async function runPrecheck() {
  attLoading.value = true
  try {
    const info = await api(attTarget().previewUrl)
    const exported = info.exported || []
    const skipped = info.skipped_unlocked || []
    if (!exported.length) {
      attState.value = {
        level: 'danger',
        message: skipped.length ? `本月考勤未锁定，无法导出：${skipped.join('、')}` : '没有符合条件的已锁定考勤数据',
      }
    } else if (skipped.length) {
      attState.value = {
        level: 'warn',
        message: `可导出 ${exported.length} 个项目；以下未锁定将跳过：${skipped.join('、')}`,
      }
    } else {
      attState.value = { level: 'ok', message: `${exported.length} 个项目考勤已锁定，可导出` }
    }
    return { exported, skipped }
  } catch (e) {
    attState.value = { level: 'danger', message: e.message || '预检失败，请稍后重试' }
    return null
  } finally {
    attLoading.value = false
  }
}

// 导出前再确认一次（预检结果可能已被其他账号改动），未锁定项目显式提示，不静默跳过
async function attExport() {
  let target
  try {
    target = attTarget()
  } catch (e) {
    toast(e.message || '导出参数错误', false)
    return
  }
  const result = await runPrecheck()
  if (!result) return
  if (!result.exported.length) {
    toast(attState.value.message, false)
    return
  }
  if (result.skipped.length) {
    const msg = `以下项目本月考勤未锁定，将不导出：\n${result.skipped.join('、')}\n\n是否继续导出其余项目？`
    if (!confirm(msg)) return
  }
  download(target.url, target.filename)
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
.exp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.exp-item { display: flex; flex-direction: column; gap: 8px; border: 1px solid var(--border); border-radius: 8px; padding: 14px; background: var(--bg-card); }
.exp-item-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; font-size: 13.5px; }
.exp-params { display: flex; gap: 8px; }
.exp-params .pe-field { flex: 1; }
.exp-desc { font-size: 12px; color: var(--text-3); line-height: 1.65; flex: 1; }
.exp-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; }
.exp-foot .hint { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.exp-file { font-size: 12.5px; word-break: break-all; }
</style>
