<template>
  <div>
    <div class="card">
      <h3>发起调薪 / 转正定薪</h3>
      <div class="row">
        <input type="text" v-model="kw" placeholder="输入姓名搜索人员" style="width:180px" @keydown.enter="searchGo" />
        <button class="btn" @click="searchGo">查找</button>
      </div>
      <div style="margin-top:10px">
        <div v-if="pickErr" class="msg err">{{ pickErr }}</div>
        <template v-else>
          <span
            v-for="s in picks" :key="s.id"
            :class="s.bound ? 'tag' : 'tag blue'"
            :style="s.bound ? 'margin:3px;background:#e5e7eb;color:#6b7280;cursor:not-allowed' : 'cursor:pointer;margin:3px'"
            :title="s.bound ? '该人员由钉钉花名册管理，调薪/转正定薪请在钉钉发起' : ''"
            @click="s.bound ? blockedPick(s) : pick(s)"
          >{{ s.name }}（{{ s.project }}·{{ s.status }}）{{ s.bound ? ' 🔒钉钉' : '' }}</span>
        </template>
      </div>
      <div v-if="sel" style="margin-top:10px">
        <div v-if="sel.bound" class="msg err">
          🔒 <b>{{ sel.name }}</b> 已绑定钉钉，薪资以钉钉花名册为唯一权威源。请在钉钉发起调薪/转正定薪，同步后自动生效，系统内不再受理调薪。
        </div>
        <template v-else>
        <div class="msg info">已选择：<b>{{ sel.name }}</b>（{{ sel.project }}，{{ sel.status }}）　现有薪资：固定 {{ moneyOrDash(sel.fixed) }} / 基本 {{ moneyOrDash(sel.base) }}</div>
        <div class="form-grid" style="max-width:680px">
          <label>变更类型<select v-model="fType"><option>调薪</option><option>转正</option></select></label>
          <label>生效日期（按此日期拆分当月工资）<input type="date" v-model="fDate" /></label>
          <label>调整后固定月薪<input type="number" v-model="fFixed" /></label>
          <label>调整后基本工资<input type="number" v-model="fBase" /></label>
          <label class="full">备注<input type="text" v-model="fNote" placeholder="如：年度调薪/试用期转正" /></label>
        </div>
        <div class="hint">月中生效自动拆分：生效日前按原薪资、当日起按新薪资，各段按出勤折算；试用期段无绩效。记录永久存档可追溯。</div>
        <div class="row" style="margin-top:10px">
          <button class="btn primary" @click="submit">提交调薪</button>
        </div>
        </template>
      </div>
    </div>
    <div class="card">
      <h3>全量调薪历史流水</h3>
      <div class="row" style="margin-bottom:10px">
        <label class="fld">按项目 <select v-model="projF" @change="loadList">
          <option value="">全部</option><option v-for="p in auth.projects" :key="p">{{ p }}</option>
        </select></label>
      </div>
      <div v-if="listErr" class="msg err">{{ listErr }}</div>
      <div v-else-if="!list.length" class="msg info">暂无调薪记录。</div>
      <div v-else class="table-wrap">
        <table class="tb">
          <thead><tr><th>时间</th><th>操作人</th><th>姓名</th><th>项目</th><th>类型</th><th>生效日期</th><th>原固定/基本</th><th>新固定/基本</th><th>固定增减</th><th>备注</th></tr></thead>
          <tbody>
            <tr v-for="(a, i) in list" :key="i">
              <td>{{ a.ts }}</td><td>{{ a.by }}</td><td>{{ a.name }}</td><td>{{ a.project }}</td>
              <td><span :class="a.type === '转正' ? 'tag purple' : 'tag blue'">{{ a.type }}</span></td>
              <td>{{ a.effective_date }}</td>
              <td class="num">{{ moneyOrDash(a.old_fixed) }} / {{ moneyOrDash(a.old_base) }}</td>
              <td class="num">{{ moneyOrDash(a.new_fixed) }} / {{ moneyOrDash(a.new_base) }}</td>
              <td class="num" :style="{ color: a.delta_fixed >= 0 ? '#16a34a' : '#dc2626' }">{{ a.delta_fixed >= 0 ? '增 ' : '减 ' }}{{ moneyOrDash(Math.abs(a.delta_fixed)) }}</td>
              <td>{{ a.note }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { moneyOrDash } from '@/utils/format'

const auth = useAuthStore()
const kw = ref('')
const pickErr = ref('')
const picks = ref([])
const sel = ref(null)
const fType = ref('调薪')
const fDate = ref('')
const fFixed = ref('')
const fBase = ref('')
const fNote = ref('')
const projF = ref('')
const list = ref([])
const listErr = ref('')

async function searchGo() {
  pickErr.value = ''
  try {
    const data = await api(`/api/staff?kw=${encodeURIComponent(kw.value.trim())}`)
    if (!(data.staff || []).length) { picks.value = []; pickErr.value = '未找到匹配人员'; return }
    picks.value = data.staff.slice(0, 12).map((s) => ({
      id: s.id, name: s.name, project: s.project, status: s.status, fixed: s.fixed_monthly, base: s.base_salary,
      bound: !!s.dingtalk_bound,
    }))
  } catch (e) { pickErr.value = e.message }
}

function pick(s) {
  sel.value = s
  fType.value = '调薪'
  fDate.value = ''
  fFixed.value = s.fixed
  fBase.value = s.base
  fNote.value = ''
}

function blockedPick(s) {
  // 绑定人员置灰标签被点击时仍允许选中（展示权威源说明），但不出现调薪表单
  sel.value = s
  toast(`${s.name} 由钉钉花名册管理，调薪请在钉钉发起`)
}

async function submit() {
  const s = sel.value
  if (s.bound) return
  try {
    await api('/api/salary_adjust', { body: { staff_id: s.id, type: fType.value, effective_date: fDate.value, fixed_monthly: fFixed.value, base_salary: fBase.value, note: fNote.value } })
    toast('调薪已记录')
    sel.value = null
    picks.value = []
    loadList()
  } catch (e) { alert(e.message) }
}

async function loadList() {
  listErr.value = ''
  try {
    const data = await api('/api/salary_adjusts')
    let l = (data.adjusts || []).slice().reverse()
    if (projF.value) l = l.filter((a) => a.project === projF.value)
    list.value = l
  } catch (e) { listErr.value = e.message }
}

onMounted(loadList)
</script>
