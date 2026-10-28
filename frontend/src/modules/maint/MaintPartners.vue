<template>
  <div class="card">
    <h3>签约方维护</h3>
    <div class="row"><button class="btn primary sm" @click="openEdit(null)">＋ 新增签约方</button>
      <button class="btn sm" @click="downloadTemplate">下载模板</button>
      <button class="btn sm primary" @click="openImport">批量导入</button></div>
    <div class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr><th>名称</th><th>业务类型</th><th>联系人</th><th>电话</th><th>资质有效期</th><th>备注</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-if="loadErr"><td colspan="7" class="msg err">{{ loadErr }}</td></tr>
        <tr v-else-if="!partners.length"><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px">暂无签约方，点击上方按钮新增</td></tr>
        <tr v-for="p in partners" :key="p.id">
          <td><b>{{ p.name }}</b></td><td><span class="tag blue">{{ typeMap[p.type] || p.type }}</span></td>
          <td>{{ p.contact || '' }}</td><td>{{ p.phone || '' }}</td>
          <td>
            <template v-if="p.qualification_expire">
              {{ p.qualification_expire }}
              <span v-if="qualStatus(p.qualification_expire) === 'expired'" class="tag red">已过期</span>
              <span v-else-if="qualStatus(p.qualification_expire) === 'soon'" class="tag orange">30天内到期</span>
            </template>
            <span v-else style="color:#94a3b8">未登记</span>
          </td>
          <td>{{ p.remark || '-' }}</td>
          <td><button class="btn sm" @click="openEdit(p.id)">编辑</button> <button class="btn sm danger" @click="del(p)">删除</button></td>
        </tr>
      </tbody>
    </table></div>
  </div>

  <MaintModal :title="(editId ? '编辑' : '新增') + '签约方'" :show="modalShow" @close="modalShow = false" @save="save">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div style="grid-column:1/3"><label>签约方名称 <span style="color:#ef4444">*</span></label><input v-model="fm.name" placeholder="如：华安消防工程有限公司" style="width:100%"></div>
      <div><label>业务类型</label><select v-model="fm.type" style="width:100%">
        <option value="both">消防+电梯</option>
        <option value="fire">仅消防</option>
        <option value="elevator">仅电梯</option>
      </select></div>
      <div><label>资质有效期至</label><input v-model="fm.qualification_expire" type="date" style="width:100%">
        <div style="font-size:11px;color:#94a3b8;margin-top:2px">30 天内到期或已过期将推送预警（仅总部接收）</div></div>
      <div><label>联系人</label><input v-model="fm.contact" style="width:100%"></div>
      <div><label>联系电话</label><input v-model="fm.phone" style="width:100%"></div>
      <div style="grid-column:1/3"><label>备注</label><textarea v-model="fm.remark" rows="2" style="width:100%"></textarea></div>
    </div>
  </MaintModal>

  <MaintModal title="签约方批量导入" :show="importShow" :wide="true" :show-save="false" @close="importShow = false">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <button class="btn sm" @click="downloadTemplate"><Icon name="down" /> 下载导入模板</button>
      <input ref="importFileRef" type="file" accept=".xlsx" style="display:none" @change="onImportFile">
      <button class="btn sm primary" style="cursor:pointer" @click="$refs.importFileRef.click()"><Icon name="file" /> 选择 Excel 文件</button>
      <span style="color:#94a3b8;font-size:12.5px">仅支持 .xlsx；已存在同名签约方将按本行内容更新</span>
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
// 签约方维护 — 复刻 pageMaintPartners 群（app.js:3108-3174），新增资质有效期与到期状态
import { reactive, ref, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { maintToday, maintAddDays } from './maintLogic'
import MaintModal from './MaintModal.vue'
import Icon from '@/components/Icon.vue'

const typeMap = { fire: '仅消防', elevator: '仅电梯', both: '消防+电梯' }
const partners = ref([])
const loadErr = ref('')
const modalShow = ref(false)
const editId = ref(null)
const fm = reactive({ name: '', type: 'both', contact: '', phone: '', qualification_expire: '', remark: '' })

// 资质状态：expired 已过期 / soon 30 天内 / normal
function qualStatus(d) {
  const t = maintToday()
  if (d < t) return 'expired'
  if (d <= maintAddDays(t, 30)) return 'soon'
  return 'normal'
}

async function load() {
  loadErr.value = ''
  try {
    const r = await api('/api/maintenance/partners')
    partners.value = r.partners || []
  } catch (e) { loadErr.value = e.message }
}

function openEdit(id) {
  editId.value = id
  const row = id ? partners.value.find((p) => p.id === id) : null
  fm.name = row ? (row.name ?? '') : ''
  fm.type = !row || row.type === 'both' ? 'both' : row.type
  fm.contact = row ? (row.contact ?? '') : ''
  fm.phone = row ? (row.phone ?? '') : ''
  fm.qualification_expire = row ? (row.qualification_expire ?? '') : ''
  fm.remark = row ? (row.remark ?? '') : ''
  modalShow.value = true
}

async function save() {
  const payload = {
    name: fm.name.trim(), type: fm.type,
    contact: fm.contact.trim(), phone: fm.phone.trim(),
    qualification_expire: fm.qualification_expire || null,
    remark: fm.remark.trim(),
  }
  if (!payload.name) { toast('请填写签约方名称', false); return }
  try {
    if (editId.value) await api('/api/maintenance/partners/' + editId.value, { method: 'PUT', body: payload })
    else await api('/api/maintenance/partners', { body: payload })
    modalShow.value = false
    toast(editId.value ? '已更新' : '已新增')
    load()
  } catch (e) { toast(e.message || '操作失败', false) }
}

async function del(p) {
  if (!confirm('确定删除该签约方？已关联的合同不受影响，但表单中不再显示此选项。')) return
  try {
    await api('/api/maintenance/partners/' + p.id, { method: 'DELETE' })
    toast('已删除')
    load()
  } catch (e) { toast(e.message, false) }
}

/* ---------- 批量导入 ---------- */
const importShow = ref(false)
const importFileRef = ref(null)
const importResult = ref(null)

function downloadTemplate() { download('/api/maintenance/partners/template', '签约方批量导入模板.xlsx') }
function openImport() { importResult.value = null; importShow.value = true }
async function onImportFile(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const fd = new FormData(); fd.append('file', file)
  try {
    importResult.value = await api('/api/maintenance/partners/import', { method: 'POST', form: fd })
    toast(`导入完成：新增 ${importResult.value.inserted} 条，更新 ${importResult.value.updated || 0} 条`, !!(importResult.value.errors && importResult.value.errors.length))
    load()
  } catch (err) { toast('导入失败：' + err.message, false) }
}

onMounted(load)
</script>
