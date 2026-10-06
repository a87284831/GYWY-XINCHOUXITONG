<template>
  <div class="card">
    <h3>导出模式</h3>
    <div class="row">
      <label class="fld">核算月份 <input type="month" v-model="ui.month" /></label>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="btn primary" @click="go('summary')">① 汇总导出（整体汇总Sheet+各项目明细Sheet）</button>
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
    <div class="row" style="margin-top:12px">
      <label class="fld">绩效专项-项目 <select v-model="expPerfProj">
        <option value="">全部项目</option><option v-for="p in auth.projects" :key="p">{{ p }}</option>
      </select></label>
      <button class="btn" @click="go('perf')">③ 绩效专项导出</button>
    </div>
    <div class="hint">所有导出均为Excel文件，文件名自动携带核算月份与项目名称；表结构与核算结果明细完全一致（34列）。</div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { toast } from '@/utils/toast'
import { visibleProjectExportTypes, projectExportTarget } from './exportLogic'

const auth = useAuthStore()
const ui = useUiStore()
const isAdmin = computed(() => auth.user && auth.user.role === 'admin')
// 分项目导出排除物业总部（复刻旧版 filter）
const projList = computed(() => auth.projects.filter((p) => p !== '物业总部'))

const expProj = ref('')
const expType = ref('staff')
const expPerfProj = ref('')

const exportTypes = computed(() => visibleProjectExportTypes(isAdmin.value))

onMounted(() => {
  expProj.value = projList.value[0] || ''
})

function go(mode) {
  const ym = ui.month
  if (mode === 'summary') {
    download(`/api/export/summary?ym=${ym}`, `工资汇总_${ym}.xlsx`)
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
  } else if (mode === 'perf') {
    const p = expPerfProj.value
    download(`/api/export/performance?ym=${ym}&project=${encodeURIComponent(p)}`, `绩效明细_${ym}${p ? '_' + p : ''}.xlsx`)
  } else if (mode === 'hqAll') {
    download(`/api/export/hq-staff?ym=${ym}`, `总部人员工资表_${ym}.xlsx`)
  } else if (mode === 'caseAll') {
    download(`/api/export/case-staff?ym=${ym}`, `案场人员工资表_${ym}.xlsx`)
  }
}
</script>
