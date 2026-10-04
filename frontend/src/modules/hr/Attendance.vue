<template>
  <div>
    <div class="card">
      <h3>考勤上传</h3>
      <div class="row">
        <label class="fld">核算月份 <input type="month" v-model="ui.month" @change="onMonthChange" /></label>
        <span v-if="isProj" class="tag blue">本项目：{{ auth.user.project }}</span>
        <label v-else class="fld">项目 <select v-model="attProj">
          <option v-for="p in auth.projects" :key="p">{{ p }}</option>
          <option v-for="g in VIRTUAL_ATT_GROUPS" :key="g.value" :value="g.value">{{ g.label }}（跨项目汇总）</option>
        </select></label>
        <button class="btn" @click="attTemplate">① 下载本月考勤模板</button>
        <input ref="fileEl" type="file" accept=".xlsx" style="display:none" @change="attUpload" />
        <button class="btn primary" @click="fileEl && fileEl.click()">② 上传考勤表</button>
        <button class="btn" :disabled="isVirtual" :title="isVirtual ? '汇总选项无单一考勤块，请选择具体项目查看' : ''" @click="attView">查看已上传数据</button>
        <button class="btn" :disabled="isVirtual" :title="isVirtual ? '锁定按项目执行，请选择具体项目' : ''" @click="attLock">{{ attLocked ? '🔓 解锁考勤' : '🔒 锁定考勤' }}</button>
        <button class="btn success" :disabled="isVirtual" :title="isVirtual ? '导出按项目执行，请选择具体项目' : ''" @click="attExport">导出考勤</button>
        <button v-if="!isProj && !isVirtual" class="btn danger sm" @click="attDelete">删除本项目本月考勤</button>
      </div>
      <div v-if="attMsg" v-html="attMsg"></div>
      <div v-if="isVirtual" class="hint">跨项目汇总表：列出全公司{{ projLabel }}（与当月工资核算同口径，管理人员不含物业总部），<b>下载时自动带出各项目已上传的考勤</b>，可直接查看或修改后回传；上传后按人合并进各自所属项目的考勤块，不影响块内其他人员。查看 / 锁定 / 导出 / 删除请选择具体项目。</div>
      <div v-else class="hint">流程：先下载模板→项目人力按符号填写→上传。上传校验：姓名必须在本项目人员档案中；符号必须在符号库内（超管可在"考勤符号库设置"维护）；同月重复上传按人合并——只更新表中出现的人员，块内其他人数据保留；整表清空请用"删除本项目本月考勤"。</div>
    </div>
    <div class="card">
      <h3>考勤统计预览</h3>
      <div v-if="viewErr" class="msg err">{{ viewErr }}</div>
      <div v-else-if="!viewLoaded" class="msg info">选择项目后点击"查看已上传数据"。</div>
      <div v-else-if="!names.length" class="msg info">{{ curProj }} {{ ui.month }} 暂无考勤数据。</div>
      <template v-else>
        <div class="row" style="margin-bottom:8px">
          <span class="tag blue">{{ curProj }} {{ ui.month }}</span>
          <span class="tag green">{{ names.length }} 人</span>
          <span class="tag gray">上传：{{ view.meta.uploaded_by || '' }} {{ view.meta.uploaded_at || '' }}</span>
          <span v-if="view.locked" class="tag" style="background:#fef2f2;color:#dc2626;border-color:#fecaca">🔒 已锁定</span>
          <span v-else class="tag">未锁定</span>
        </div>
        <div class="table-wrap">
          <table class="tb">
            <thead><tr>
              <th>姓名</th><th>职位</th><th>人员状态</th><th>应出勤</th><th>实出勤</th><th>应出勤(符号统计)</th><th>事假</th><th>病假</th><th>产假</th><th>带薪假</th>
              <th>缺卡</th><th>旷工</th><th>迟到</th><th>早退</th><th>绩效系数</th><th>餐补</th><th>奖励</th><th>扣罚</th>
              <th>养老</th><th>医疗</th><th>失业</th><th>公积金</th><th>大病</th><th>缺卡扣款</th><th>迟早扣款</th><th>其他扣款</th><th>工装扣款</th>
            </tr></thead>
            <tbody>
              <tr v-for="n in names" :key="n" :class="{ 'att-hl': hlName === n }" @click="hlName = hlName === n ? '' : n">
                <td>{{ n }}</td><td>{{ view.rows[n].position }}</td><td>{{ view.rows[n].status || '-' }}</td>
                <td class="num">{{ view.rows[n].req_attend || '-' }}</td>
                <td class="num">{{ view.rows[n].act_attend > 0 ? view.rows[n].act_attend : (view.stats[n] || {}).attend }}</td>
                <td class="num">{{ (view.stats[n] || {}).required }}</td>
                <td class="num">{{ (view.stats[n] || {}).personal }}</td>
                <td class="num">{{ (view.stats[n] || {}).sick }}</td>
                <td class="num">{{ (view.stats[n] || {}).maternity }}</td>
                <td class="num">{{ (view.stats[n] || {}).paid }}</td>
                <td class="num">{{ (view.stats[n] || {}).miss }}</td>
                <td class="num">{{ (view.stats[n] || {}).absent }}</td>
                <td class="num">{{ (view.stats[n] || {}).late }}</td>
                <td class="num">{{ (view.stats[n] || {}).early }}</td>
                <td class="num">{{ fmtEmpty(view.rows[n].coef) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].meal_sub) }}</td><td class="num">{{ moneyOrDash(view.rows[n].reward) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].punish) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].pen) }}</td><td class="num">{{ moneyOrDash(view.rows[n].med) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].une) }}</td><td class="num">{{ moneyOrDash(view.rows[n].house) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].big) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].miss_deduct) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].late_deduct) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].other_deduct) }}</td>
                <td class="num">{{ moneyOrDash(view.rows[n].uniform_deduct) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { moneyOrDash, fmtEmpty } from '@/utils/format'
import { toast } from '@/utils/toast'
import { VIRTUAL_ATT_GROUPS, isVirtualAttGroup, attProjectLabel } from './attendanceGroups'

const auth = useAuthStore()
const ui = useUiStore()

const isProj = computed(() => auth.user && auth.user.role === 'project')
const curProj = computed(() => (isProj.value ? auth.user.project : attProj.value))
const isVirtual = computed(() => isVirtualAttGroup(curProj.value))
const projLabel = computed(() => attProjectLabel(curProj.value))

const attProj = ref(auth.projects[0] || '')
const fileEl = ref(null)
const attMsg = ref('')
const pendingFile = ref(null)
const view = ref({ rows: {}, stats: {}, meta: {} })
const viewLoaded = ref(false)
const viewErr = ref('')
const attLocked = ref(false)
const hlName = ref('')

const names = computed(() => Object.keys(view.value.rows || {}))

async function attTemplate() {
  download(`/api/attendance/template?ym=${ui.month}&project=${encodeURIComponent(curProj.value)}`, `考勤表模板_${projLabel.value}_${ui.month}.xlsx`)
}

// 上传：先 dry_run 校验出预览，再确认正式上传（复刻 attUpload/attUploadConfirm 两段式）
async function attUpload(e) {
  const f = e.target.files[0]
  if (!f) return
  pendingFile.value = f
  attMsg.value = `<div class="msg info">正在校验考勤表...</div>`
  const form = new FormData()
  form.append('ym', ui.month)
  form.append('project', curProj.value)
  form.append('file', f)
  form.append('dry_run', '1')
  try {
    const r = await api('/api/attendance/upload', { form })
    let warnHtml = ''
    if (r.resigned && r.resigned.length) warnHtml += `<div style="color:#d97706;margin-top:6px">⚠ 以下人员本月之前已离职，不参与核算：${r.resigned.slice(0, 10).join('、')}${r.resigned.length > 10 ? '等' : ''}</div>`
    if (r.has_calc) warnHtml += `<div style="color:#dc2626;margin-top:6px">⚠ ${ui.month} 已有${isVirtual.value ? '相关项目' : '该项目'}核算结果，确认上传后需重新核算！</div>`
    if (r.overwrite) warnHtml += `<div style="color:#d97706;margin-top:6px">⚠ 将更新已有的考勤数据（按人合并，块内其他人员保留）！</div>`
    attMsg.value = `<div class="msg ok" style="border-color:#16a34a">
      <div style="font-weight:600;margin-bottom:6px">校验通过：${r.count} 人${r.overwrite ? '（将覆盖旧数据）' : ''}</div>
      <div style="font-size:12px;color:#64748b;max-height:120px;overflow-y:auto">${r.names.join('、')}</div>
      ${warnHtml}
      <div class="row" style="margin-top:10px">
        <button class="btn success" data-act="confirm-upload">③ 确认上传</button>
        <button class="btn" data-act="cancel-upload">取消</button>
      </div>
    </div>`
  } catch (e2) {
    attMsg.value = `<div class="msg err">${e2.message}</div>`
    pendingFile.value = null
  }
}

async function attUploadConfirm() {
  if (!pendingFile.value) return
  const form = new FormData()
  form.append('ym', ui.month)
  form.append('project', curProj.value)
  form.append('file', pendingFile.value)
  attMsg.value = `<div class="msg info">正在正式上传...</div>`
  try {
    const r = await api('/api/attendance/upload', { form })
    attMsg.value = `<div class="msg ok">上传成功：${r.count} 人${r.overwrite ? '（已按人合并旧数据）' : ''}${r.warning ? '<br>⚠ ' + r.warning : ''}</div>`
    pendingFile.value = null
    if (fileEl.value) fileEl.value.value = ''
    // 汇总选项没有单一项目块可预览，只提示成功
    if (!isVirtual.value) attView()
  } catch (e) {
    attMsg.value = `<div class="msg err">${e.message}</div>`
  }
}

async function attView() {
  if (isVirtual.value) return
  viewErr.value = ''
  try {
    const data = await api(`/api/attendance?ym=${ui.month}&project=${encodeURIComponent(curProj.value)}`)
    view.value = data
    viewLoaded.value = true
    attLocked.value = !!data.locked
    hlName.value = ''
  } catch (e) { viewErr.value = e.message }
}

async function attLock() {
  const willLock = !attLocked.value
  if (willLock) {
    if (!confirm(`确认锁定 ${curProj.value} ${ui.month} 考勤为最终版本？\n锁定后不能重传或删除。`)) return
  } else {
    if (!confirm(`确认解锁 ${curProj.value} ${ui.month} 考勤？`)) return
  }
  try {
    await api('/api/attendance/lock', { body: { ym: ui.month, project: curProj.value, locked: willLock } })
    toast(willLock ? '已锁定为最终版本' : '已解锁')
    attView()
  } catch (e) { alert(e.message) }
}

async function attDelete() {
  if (!confirm(`确认删除 ${curProj.value} ${ui.month} 的考勤数据？`)) return
  try {
    await api('/api/attendance/delete', { body: { ym: ui.month, project: curProj.value } })
    toast('已删除')
    attView()
  } catch (e) { alert(e.message) }
}

// 复刻 attExport：prompt 选人员类型 → 直接 fetch 下载（无进度浮层）
async function attExport() {
  const types = [['', '全部人员'], ['管理人员', '管理人员'], ['基层人员', '基层人员'], ['案场人员', '案场人员'], ['总部人员', '总部人员']]
  const sel = prompt('导出哪种人员？\n' + types.map((t, i) => `${i}=${t[1]}`).join('  '), '0')
  if (sel === null) return
  const t = types[parseInt(sel)] || types[0]
  const params = new URLSearchParams({ ym: ui.month, project: curProj.value, staff_type: t[0] })
  try {
    const resp = await fetch('/api/attendance/export?' + params.toString(), { headers: { 'X-Token': auth.token || '' } })
    if (!resp.ok) {
      const e = await resp.json().catch(() => ({ error: '导出失败' }))
      alert(e.error || '导出失败')
      return
    }
    const blob = await resp.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `考勤导出_${curProj.value}_${ui.month}_${t[1]}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) { alert(e.message) }
}

function onDocClick(e) {
  const act = e.target.closest && e.target.closest('[data-act]')
  if (!act) return
  if (act.dataset.act === 'confirm-upload') attUploadConfirm()
  if (act.dataset.act === 'cancel-upload') {
    attMsg.value = ''
    pendingFile.value = null
    if (fileEl.value) fileEl.value.value = ''
  }
}

function onMonthChange() {
  viewLoaded.value = false
  attLocked.value = false
}

watch(() => ui.month, onMonthChange)
// 切换项目/汇总选项时清空旧预览，避免把甲项目数据误当乙项目
watch(curProj, () => { viewLoaded.value = false; viewErr.value = ''; attLocked.value = false })
watch(() => auth.projects, (v) => { if (!isProj.value && !attProj.value && v.length) attProj.value = v[0] })

onMounted(() => {
  document.addEventListener('click', onDocClick)
  if (!isProj.value && !attProj.value && auth.projects.length) attProj.value = auth.projects[0]
})

onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>
