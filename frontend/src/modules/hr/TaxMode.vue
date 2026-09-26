<template>
  <div class="card">
    <h3>个税扣除模式设置</h3>
    <div class="msg info">普通模式：每月按 5000 元累计减除费用（常规预扣）。<br />6万扣除模式：年初一次性按全年 6 万元减除费用扣除，累计收入不超过 6 万元的月份不预扣个税（适用于上年度全年收入≤6万且在同一单位的人员；最终以汇算清缴为准）。切换后，当月及后续月份个税按新模式重算。</div>
    <div class="row">
      <label class="fld">项目 <select v-model="proj" @change="load">
        <option value="">全部</option><option v-for="p in auth.projects" :key="p">{{ p }}</option>
      </select></label>
      <input type="text" v-model="kw" placeholder="姓名/职位搜索" @keydown.enter="load" />
      <button class="btn primary" @click="load">查询</button>
      <label class="fld" style="display:inline-flex;align-items:center;width:auto">
        <input type="checkbox" style="width:auto;margin-right:6px" :checked="allChecked" @change="toggleAll" /> 全选
      </label>
      <span class="tag blue">已选 {{ sel.size }} 人</span>
      <button class="btn warn" @click="bulkSet(0)">批量设为普通</button>
      <button class="btn warn" @click="bulkSet(1)">批量设为6万扣除</button>
    </div>
    <div style="margin-top:12px">
      <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
      <template v-else>
        <div class="table-wrap" style="overflow-x:auto">
          <table class="tb" style="min-width:960px">
            <thead><tr><th>选择</th><th>姓名</th><th>项目</th><th>部门</th><th>职位</th><th>当前模式</th><th>切换为</th></tr></thead>
            <tbody>
              <tr v-for="s in staff" :key="s.id">
                <td style="text-align:center"><input type="checkbox" class="tmChk" :checked="sel.has(s.id)" @change="toggle(s.id, $event.target.checked)" /></td>
                <td><b>{{ s.name }}</b></td><td>{{ s.project }}</td><td>{{ s.dept_path || '未分配' }}</td><td>{{ s.position }}</td>
                <td><span :class="Number(s.tax_mode ?? 0) === 1 ? 'tag blue' : 'tag gray'">{{ Number(s.tax_mode ?? 0) === 1 ? '6万扣除' : '普通' }}</span></td>
                <td>
                  <select :value="Number(s.tax_mode ?? 0)" @change="saveOne(s, $event.target.value)">
                    <option value="0">普通模式</option><option value="1">6万扣除</option>
                  </select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="hint">共 {{ staff.length }} 名在职员工。切换为「6万扣除」后，自当年 1 月起按全年 6 万元减除费用累计预扣；普通模式恢复每月 5000 元累计。历史月份如需更正，请在「薪资核算与微调」中重新核算。</div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'

const auth = useAuthStore()
const proj = ref('')
const kw = ref('')
const staff = ref([])
const sel = reactive(new Set())
const loadErr = ref('')

const allChecked = computed(() => staff.value.length > 0 && sel.size === staff.value.length)

async function load() {
  loadErr.value = ''
  try {
    const q = `cat=${encodeURIComponent('在职')}&project=${encodeURIComponent(proj.value)}&kw=${encodeURIComponent(kw.value)}`
    const data = await api('/api/staff?' + q)
    staff.value = data.staff || []
    sel.clear()
  } catch (e) { loadErr.value = e.message }
}

function toggle(id, on) {
  if (on) sel.add(id)
  else sel.delete(id)
}

function toggleAll(e) {
  sel.clear()
  if (e.target.checked) staff.value.forEach((s) => sel.add(s.id))
}

async function saveOne(s, mode) {
  try {
    await api('/api/staff/bulk_tax_mode', { body: { ids: [s.id], mode: parseInt(mode) } })
    toast(`已设置${parseInt(mode) === 1 ? '6万扣除' : '普通'}模式`)
    s.tax_mode = parseInt(mode)
  } catch (e) { alert(e.message) }
}

async function bulkSet(mode) {
  if (!sel.size) return toast('请先勾选人员', false)
  try {
    const r = await api('/api/staff/bulk_tax_mode', { body: { ids: [...sel], mode } })
    toast(`已为 ${r.count} 人设置${mode === 1 ? '6万扣除' : '普通'}模式`)
    sel.clear()
    load()
  } catch (e) { alert(e.message) }
}

onMounted(load)
</script>
