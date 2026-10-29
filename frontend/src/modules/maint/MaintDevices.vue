<template>
  <div class="card">
    <h3>设备台账</h3>
    <div class="row" style="flex-wrap:wrap;gap:8px;align-items:center">
      <button class="btn sm" :class="{ primary: tab === 'fire' }" @click="tab = 'fire'"><Icon name="flame" /> 消防设备</button>
      <button class="btn sm" :class="{ primary: tab === 'elevator' }" @click="tab = 'elevator'"><Icon name="lift" /> 电梯设备</button>
      <span style="width:8px"></span>
      <button class="btn primary sm" @click="openEdit(null)"><Icon name="plus" /> 新增设备</button>
      <button class="btn sm" @click="downloadTemplate"><Icon name="down" /> 下载模板</button>
      <button class="btn sm primary" @click="openImport">批量导入</button>
      <input v-model="fKw" placeholder="关键词(编号/系统类型/备注)" style="width:190px">
      <select v-model="fProject" style="max-width:160px"><option value="">全部项目</option><option v-for="p in projectOpts" :key="p" :value="p">{{ p }}</option></select>
      <select v-model="fStatus" style="width:130px">
        <option value="">全部年检状态</option>
        <option value="normal">正常</option><option value="soon">30天内到期</option>
        <option value="expired">已过期</option><option value="missing">未登记</option>
      </select>
    </div>
    <div class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr>
        <th>项目名称</th><th>设备编号</th>
        <template v-if="isElev"><th>载重(kg)</th><th>层站</th></template>
        <template v-else><th>系统类型</th><th>覆盖面积(㎡)</th></template>
        <th>年检日期</th><th>年检状态</th><th>备注</th><th>操作</th>
      </tr></thead>
      <tbody>
        <tr v-if="loadErr"><td colspan="9" class="msg err">{{ loadErr }}</td></tr>
        <tr v-else-if="!loaded"><td colspan="9">加载中...</td></tr>
        <tr v-else-if="!rows.length"><td colspan="9" style="text-align:center;color:#94a3b8;padding:24px">暂无{{ tabLabel }}设备，点击上方「新增设备」登记</td></tr>
        <tr v-for="r in rows" :key="r.id">
          <td>{{ r.project_name }}</td><td><b>{{ r.device_no }}</b></td>
          <template v-if="isElev"><td>{{ r.load_kg || '-' }}</td><td>{{ r.floors || '-' }}</td></template>
          <template v-else><td>{{ r.system_type || '-' }}</td><td>{{ r.cover_area_sqm || '-' }}</td></template>
          <td>{{ r.inspect_date || '未登记' }}</td>
          <td><span :class="INSPECT_TAG[r.inspect_status] || 'tag gray'">{{ INSPECT_TEXT[r.inspect_status] || r.inspect_status }}</span></td>
          <td>{{ r.remark || '' }}</td>
          <td><button class="btn sm" @click="openEdit(r.id)">编辑</button> <button class="btn sm danger" @click="del(r)">删除</button></td>
        </tr>
      </tbody>
    </table></div>
  </div>

  <MaintModal :title="(editId ? '编辑' : '新增') + tabLabel + '设备'" :show="modalShow" @close="modalShow = false" @save="save">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div><label>所属项目名称 <span style="color:#ef4444">*</span></label><input v-model="fm.project_name" placeholder="如：盈科国际广场"></div>
      <div><label>设备编号 <span style="color:#ef4444">*</span></label><input v-model="fm.device_no" :placeholder="isElev ? '如：DT-01' : '如：XF-01'"></div>
      <template v-if="isElev">
        <div><label>载重（kg）</label><input v-model="fm.load_kg" type="number"></div>
        <div><label>层站</label><input v-model="fm.floors" placeholder="如：18层18站"></div>
      </template>
      <template v-else>
        <div><label>系统类型</label><input v-model="fm.system_type" placeholder="如：火灾自动报警系统"></div>
        <div><label>覆盖面积（㎡）</label><input v-model="fm.cover_area_sqm" type="number"></div>
      </template>
      <div><label>年检日期</label><input v-model="fm.inspect_date" type="date"><div style="font-size:11px;color:#94a3b8;margin-top:2px">30 天内到期或已过期将推送预警</div></div>
      <div style="grid-column:1/3"><label>备注</label><textarea v-model="fm.remark" rows="2"></textarea></div>
    </div>
  </MaintModal>

  <MaintModal :title="tabLabel + '设备批量导入'" :show="importShow" :wide="true" :show-save="false" @close="importShow = false">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <button class="btn sm" @click="downloadTemplate"><Icon name="down" /> 下载导入模板</button>
      <input ref="importFileRef" type="file" accept=".xlsx" style="display:none" @change="onImportFile">
      <button class="btn sm primary" style="cursor:pointer" @click="$refs.importFileRef.click()"><Icon name="file" /> 选择 Excel 文件</button>
      <span style="color:#94a3b8;font-size:12.5px">仅支持 .xlsx；已存在同项目+编号的设备将按本行内容更新</span>
    </div>
    <div v-if="importResult" style="margin-top:12px;padding:10px 12px;border-radius:8px;background:#f8fafc;font-size:13px">
      <b>导入结果</b>：共 {{ importResult.total }} 条，新增 <b style="color:#16a34a">{{ importResult.inserted }}</b> 条，更新 <b style="color:#2563eb">{{ importResult.updated || 0 }}</b> 条
      <span v-if="importResult.errors && importResult.errors.length">，{{ importResult.errors.length }} 条错误</span>
      <span v-else>，全部成功 ✓</span>
    </div>
    <div v-if="importResult?.errors?.length" class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr><th style="width:60px">行号</th><th>错误信息</th></tr></thead>
      <tbody>
        <tr v-for="(e, i) in importResult.errors" :key="i"><td>{{ e.row }}</td><td style="color:#dc2626">{{ e.msg }}</td></tr>
      </tbody>
    </table></div>
  </MaintModal>
</template>

<script setup>
// 设备台账：消防/电梯设备登记 + 年检状态预警展示（后端 maintenance_devices）
import { reactive, ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import MaintModal from './MaintModal.vue'
import Icon from '@/components/Icon.vue'

const INSPECT_TEXT = { normal: '正常', soon: '30天内到期', expired: '已过期', missing: '未登记' }
const INSPECT_TAG = { normal: 'tag green', soon: 'tag orange', expired: 'tag red', missing: 'tag gray' }

const tab = ref('fire')
const isElev = computed(() => tab.value === 'elevator')
const tabLabel = computed(() => (isElev.value ? '电梯' : '消防'))

const all = ref([])
const loaded = ref(false)
const loadErr = ref('')
const fKw = ref('')
const fProject = ref('')
const fStatus = ref('')

const rows = computed(() => {
  let list = all.value.filter((d) => d.type === tab.value)
  const kw = fKw.value.trim().toLowerCase()
  if (kw) list = list.filter((d) => ((d.device_no || '') + (d.system_type || '') + (d.remark || '') + (d.project_name || '')).toLowerCase().includes(kw))
  if (fProject.value) list = list.filter((d) => d.project_name === fProject.value)
  if (fStatus.value) list = list.filter((d) => d.inspect_status === fStatus.value)
  return list
})
const projectOpts = computed(() => [...new Set(all.value.filter((d) => d.type === tab.value).map((d) => d.project_name).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'zh')))

async function load() {
  loadErr.value = ''
  try {
    const r = await api('/api/maintenance/devices')
    all.value = r.devices || []
  } catch (e) { loadErr.value = e.message }
  loaded.value = true
}

/* ---------- 新增/编辑 ---------- */
const modalShow = ref(false)
const editId = ref(null)
const fm = reactive({ project_name: '', device_no: '', load_kg: '', floors: '', system_type: '', cover_area_sqm: '', inspect_date: '', remark: '' })

function openEdit(id) {
  editId.value = id
  const row = id ? all.value.find((d) => d.id === id) : null
  fm.project_name = row ? (row.project_name ?? '') : ''
  fm.device_no = row ? (row.device_no ?? '') : ''
  fm.load_kg = row && row.load_kg != null ? row.load_kg : ''
  fm.floors = row ? (row.floors ?? '') : ''
  fm.system_type = row ? (row.system_type ?? '') : ''
  fm.cover_area_sqm = row && row.cover_area_sqm != null ? row.cover_area_sqm : ''
  fm.inspect_date = row ? (row.inspect_date ?? '') : ''
  fm.remark = row ? (row.remark ?? '') : ''
  modalShow.value = true
}

async function save() {
  const payload = {
    type: tab.value, project_name: fm.project_name.trim(), device_no: fm.device_no.trim(),
    load_kg: Number(fm.load_kg) || 0, floors: fm.floors.trim(),
    system_type: fm.system_type.trim(), cover_area_sqm: Number(fm.cover_area_sqm) || 0,
    inspect_date: fm.inspect_date || null, remark: fm.remark.trim(),
  }
  try {
    if (editId.value) await api('/api/maintenance/devices/' + editId.value, { method: 'PUT', body: payload })
    else await api('/api/maintenance/devices', { body: payload })
    modalShow.value = false
    toast('已保存')
    load()
  } catch (e) { toast('保存失败：' + e.message, false) }
}

async function del(r) {
  if (!confirm(`确认删除设备「${r.project_name} / ${r.device_no}」？`)) return
  try {
    await api('/api/maintenance/devices/' + r.id, { method: 'DELETE' })
    toast('已删除')
    load()
  } catch (e) { toast('删除失败：' + e.message, false) }
}

onMounted(load)

/* ---------- 批量导入 ---------- */
const importShow = ref(false)
const importFileRef = ref(null)
const importResult = ref(null)

function downloadTemplate() { download(`/api/maintenance/devices/template?type=${tab.value}`, tabLabel.value + '设备批量导入模板.xlsx') }
function openImport() { importResult.value = null; importShow.value = true }
async function onImportFile(e) {
  const file = e.target.files[0]; e.target.value = ''; if (!file) return
  const fd = new FormData(); fd.append('file', file); fd.append('type', tab.value)
  try {
    importResult.value = await api('/api/maintenance/devices/import', { method: 'POST', form: fd })
    toast(`导入完成：新增 ${importResult.value.inserted} 条，更新 ${importResult.value.updated || 0} 条`, !!(importResult.value.errors && importResult.value.errors.length))
    load()
  } catch (err) { toast('导入失败：' + err.message, false) }
}
</script>
