<template>
  <div class="card">
    <h3>导出模式</h3>
    <div class="row">
      <label class="fld">核算月份 <input type="month" v-model="ui.month" /></label>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="btn primary" @click="go('summary')">① 工资全量数据包（汇总报表 + 基层/管理/案场/总部全部明细 Sheet）</button>
      <button class="btn primary" @click="go('projectsAll')">② 全部项目批量导出（基层工资表一键打包）</button>
    </div>
    <div class="row" style="margin-top:12px">
      <label class="fld">分项目导出-选择项目 <select v-model="expProj">
        <option v-for="p in projList" :key="p">{{ p }}</option>
      </select></label>
      <label class="fld">人员类型 <select v-model="expType">
        <option v-for="t in exportTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
      </select></label>
      <button class="btn primary" @click="go('projectTyped')">导出该项目所选类型工资表</button>
    </div>
    <div class="card" style="margin-top:12px;padding:10px 14px">
      <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <b>管理人员工资表（全公司）</b>
        <button v-if="isAdmin" class="btn" @click="go('mgrAll')">导出全部管理人员（工资表 + 汇总）</button>
        <span class="hint" style="margin:0;display:inline">所有项目管理人员一张 Excel：Sheet1「管理人员工资表」为全员明细，Sheet2「管理人员汇总」按项目汇总人数/应发/五险一金/个税/实发；口径与"工资全量数据包"一致，仅总部可导出。</span>
      </div>
    </div>
    <div class="card" style="margin-top:12px;padding:10px 14px">
      <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <b>案场人员工资表（全公司）</b>
        <button v-if="isAdmin" class="btn" @click="go('caseAll')">导出全部案场人员表</button>
        <span class="hint" style="margin:0;display:inline">案场人员由总部单独核算（人员档案中勾选"是否案场人员"）；各项目可用上方"分项目导出→案场人员"导出本项目案场人员工资。</span>
      </div>
    </div>
    <div class="card" style="margin-top:12px;padding:10px 14px">
      <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <b>总部人员工资表</b>
        <button v-if="isAdmin" class="btn" @click="go('hqAll')">导出总部人员工资表（物业总部）</button>
        <span class="hint" style="margin:0;display:inline">物业总部所有人员由总部单独核算（总部人员核算），仅总部可导出。</span>
      </div>
    </div>
    <div class="card" style="margin-top:12px;padding:10px 14px">
      <h3>④ 考勤导出</h3>
      <div class="row" style="gap:12px;flex-wrap:wrap;align-items:center">
        <label class="fld">核算月份 <input type="month" v-model="ui.month" /></label>
        <label class="fld">项目 <select v-model="attProject" :disabled="isProjectScope">
          <option v-if="!isProjectScope" value="all">全部项目</option>
          <option v-for="p in attProjList" :key="p" :value="p">{{ p }}</option>
        </select></label>
        <label class="fld">人员类型 <select v-model="attPersonType">
          <option v-for="t in ATTENDANCE_EXPORT_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select></label>
      </div>
      <div class="row" style="margin-top:10px;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn primary" @click="attExport('merged')">导出考勤（合并一张表）</button>
        <button class="btn primary" @click="attExport('bundle')">导出考勤全量数据包（每项目一张 Sheet）</button>
      </div>
      <span class="hint" style="display:block;margin-top:8px">按「项目 + 人员类型」组合筛选；合并表在全部项目时含"项目"列，单项目不含；全量数据包固定按全部项目打包、每项目一张 Sheet（项目账号仅本项目）。只导出已锁定月份：存在未锁定项目时会先提示并列出未导出的项目。</span>
    </div>
    <div class="hint">工资表类导出均为 Excel 文件，文件名自动携带核算月份与项目名称，表结构与核算结果明细完全一致（39列，末列为季度绩效说明）；绩效工资专项表已迁移至「薪资模块 → 绩效导出」。</div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { download, api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { toast } from '@/utils/toast'
import {
  visibleProjectExportTypes, projectExportTarget,
  attendanceExportTarget, ATTENDANCE_EXPORT_TYPES,
} from './exportLogic'

const auth = useAuthStore()
const ui = useUiStore()
const isAdmin = computed(() => auth.user && auth.user.role === 'admin')
const isProjectScope = computed(() => auth.user && auth.user.scope === 'project')
// 分项目导出排除物业总部（复刻旧版 filter）
const projList = computed(() => auth.projects.filter((p) => p !== '物业总部'))
// 考勤导出按真实项目档案全量展示（含总部载体项目），不套用工资导出的排除规则
const attProjList = computed(() => auth.projects)

const expProj = ref('')
const expType = ref('staff')
const attProject = ref('all')
const attPersonType = ref('all')

const exportTypes = computed(() => visibleProjectExportTypes(isAdmin.value))

onMounted(() => {
  expProj.value = projList.value[0] || ''
  if (isProjectScope.value && auth.user?.project) attProject.value = auth.user.project
})

function go(mode) {
  const ym = ui.month
  if (mode === 'summary') {
    download(`/api/export/summary?ym=${ym}`, `工资全量数据包_${ym}.xlsx`)
  } else if (mode === 'projectsAll') {
    download(`/api/export/projects_all?ym=${ym}`, `全部项目工资表_${ym}.zip`)
  } else if (mode === 'projectTyped') {
    const p = expProj.value
    if (!p) { toast('请先选择项目', false); return }
    try {
      const target = projectExportTarget(expType.value, ym, p, isAdmin.value)
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

// 考勤导出：先 dry_run 拿未锁定项目名单并显式提示，确认后再下载（不静默跳过）
async function attExport(mode) {
  let target
  try {
    target = attendanceExportTarget({
      ym: ui.month,
      project: attProject.value,
      personType: attPersonType.value,
      mode,
      isProjectScope: isProjectScope.value,
    })
  } catch (e) {
    toast(e.message || '导出参数错误', false)
    return
  }
  try {
    const info = await api(target.previewUrl)
    const skipped = info.skipped_unlocked || []
    const exported = info.exported || []
    if (!exported.length) {
      toast(skipped.length ? `本月考勤未锁定，无法导出：${skipped.join('、')}` : '没有符合条件的已锁定考勤数据', false)
      return
    }
    if (skipped.length) {
      const msg = `以下项目本月考勤未锁定，将不导出：\n${skipped.join('、')}\n\n是否继续导出其余项目？`
      if (!confirm(msg)) return
    }
    download(target.url, target.filename)
  } catch (e) {
    toast(e.message || '导出失败', false)
  }
}
</script>
