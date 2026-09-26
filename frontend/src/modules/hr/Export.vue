<template>
  <div class="card">
    <h3>导出模式</h3>
    <div class="row">
      <label class="fld">核算月份 <input type="month" v-model="ui.month" /></label>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="btn primary" @click="go('summary')">① 汇总导出（整体汇总Sheet+各项目明细Sheet）</button>
      <button class="btn primary" @click="go('projectsAll')">② 分项目导出（全选，一键多文件打包）</button>
    </div>
    <div class="row" style="margin-top:12px">
      <label class="fld">分项目导出-选择项目 <select v-model="expProj">
        <option v-for="p in projList" :key="p">{{ p }}</option>
      </select></label>
      <button class="btn" @click="go('project')">导出该项目工资表</button>
      <button v-if="isAdmin" class="btn" @click="go('projectMgrs')">导出该项目管理人员表</button>
    </div>
    <div class="card" style="margin-top:12px;padding:10px 14px">
      <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <b>案场人员工资表</b>
        <label class="fld" style="margin:0">选择项目 <select v-model="expCaseProj">
          <option value="">全部项目</option><option v-for="p in auth.projects" :key="p">{{ p }}</option>
        </select></label>
        <button class="btn" @click="go('projectCase')">导出该项目案场人员表</button>
        <button v-if="isAdmin" class="btn" @click="go('caseAll')">导出全部案场人员表</button>
        <span class="hint" style="margin:0;display:inline">案场人员由总部单独核算（人员档案中勾选"是否案场人员"），各项目可查看/导出本项目案场人员工资。</span>
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

const auth = useAuthStore()
const ui = useUiStore()
const isAdmin = computed(() => auth.user && auth.user.role === 'admin')
// 分项目导出排除物业总部（复刻旧版 filter）
const projList = computed(() => auth.projects.filter((p) => p !== '物业总部'))

const expProj = ref('')
const expCaseProj = ref('')
const expPerfProj = ref('')

onMounted(() => {
  expProj.value = projList.value[0] || ''
})

function go(mode) {
  const ym = ui.month
  if (mode === 'summary') {
    download(`/api/export/summary?ym=${ym}`, `工资汇总_${ym}.xlsx`)
  } else if (mode === 'projectsAll') {
    download(`/api/export/projects_all?ym=${ym}`, `全部项目工资表_${ym}.zip`)
  } else if (mode === 'project') {
    let p = expProj.value
    if (!p) p = auth.projects[0] || ''
    download(`/api/export/project?ym=${ym}&project=${encodeURIComponent(p)}`, `${p}_${ym}工资表.xlsx`)
  } else if (mode === 'perf') {
    const p = expPerfProj.value
    download(`/api/export/performance?ym=${ym}&project=${encodeURIComponent(p)}`, `绩效明细_${ym}${p ? '_' + p : ''}.xlsx`)
  } else if (mode === 'projectMgrs') {
    const p = expProj.value || auth.projects[0] || ''
    download(`/api/export/project-managers?ym=${ym}&project=${encodeURIComponent(p)}`, `${p}_管理人员工资表_${ym}.xlsx`)
  } else if (mode === 'hqAll') {
    download(`/api/export/hq-staff?ym=${ym}`, `总部人员工资表_${ym}.xlsx`)
  } else if (mode === 'projectCase') {
    const p = expCaseProj.value
    if (!p) return toast('请先选择项目', false)
    download(`/api/export/project-case?ym=${ym}&project=${encodeURIComponent(p)}`, `${p}_案场人员工资表_${ym}.xlsx`)
  } else if (mode === 'caseAll') {
    download(`/api/export/case-staff?ym=${ym}`, `案场人员工资表_${ym}.xlsx`)
  }
}
</script>
