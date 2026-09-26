<template>
  <div class="card">
    <h3>签约方维护</h3>
    <div class="row"><button class="btn primary sm" @click="openEdit(null)">＋ 新增签约方</button></div>
    <div class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr><th>名称</th><th>业务类型</th><th>联系人</th><th>电话</th><th>备注</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-if="loadErr"><td colspan="6" class="msg err">{{ loadErr }}</td></tr>
        <tr v-else-if="!partners.length"><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px">暂无签约方，点击上方按钮新增</td></tr>
        <tr v-for="p in partners" :key="p.id">
          <td><b>{{ p.name }}</b></td><td><span class="tag blue">{{ typeMap[p.type] || p.type }}</span></td>
          <td>{{ p.contact || '' }}</td><td>{{ p.phone || '' }}</td><td>{{ p.remark || '-' }}</td>
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
      <div><label>联系人</label><input v-model="fm.contact" style="width:100%"></div>
      <div><label>联系电话</label><input v-model="fm.phone" style="width:100%"></div>
      <div style="grid-column:1/3"><label>备注</label><textarea v-model="fm.remark" rows="2" style="width:100%"></textarea></div>
    </div>
  </MaintModal>
</template>

<script setup>
// 签约方维护 — 复刻 pageMaintPartners 群（app.js:3108-3174）
import { reactive, ref, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import MaintModal from './MaintModal.vue'

const typeMap = { fire: '仅消防', elevator: '仅电梯', both: '消防+电梯' }
const partners = ref([])
const loadErr = ref('')
const modalShow = ref(false)
const editId = ref(null)
const fm = reactive({ name: '', type: 'both', contact: '', phone: '', remark: '' })

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
  fm.remark = row ? (row.remark ?? '') : ''
  modalShow.value = true
}

async function save() {
  const payload = {
    name: fm.name.trim(), type: fm.type,
    contact: fm.contact.trim(), phone: fm.phone.trim(),
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

onMounted(load)
</script>
