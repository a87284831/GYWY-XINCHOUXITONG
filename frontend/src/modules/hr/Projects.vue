<template>
  <div class="card">
    <h3>项目档案</h3>
    <div class="row" style="margin-bottom:10px">
      <button class="btn success" @click="edit(null)">＋ 新增项目</button>
    </div>
    <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
    <div v-else class="table-wrap">
      <table class="tb">
        <thead><tr><th>项目名称</th><th>别名</th><th>负责人</th><th>联系电话</th><th>地址</th><th>员工状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="p in projects" :key="p.name">
            <td>{{ p.name }}</td>
            <td>{{ (p.aliases || []).join('、') }}</td>
            <td>{{ p.contact || '' }}</td>
            <td>{{ p.phone || '' }}</td>
            <td>{{ p.address || '' }}</td>
            <td><span :class="on(p) ? 'tag green' : 'tag gray'">{{ on(p) ? '启用' : '停用' }}</span></td>
            <td>
              <button class="btn sm" @click="edit(p)">编辑</button>
              <button :class="on(p) ? 'btn sm warn' : 'btn sm success'" @click="toggle(p)">{{ on(p) ? '停用' : '启用' }}</button>
              <button class="btn sm danger" @click="del(p)">删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="hint">停用后的项目不再出现在核算/人员等下拉选项中，但历史数据保留；名下仍有在职人员的项目不能删除，可改用停用。</div>
  </div>

  <div v-if="showEdit" class="modal-mask" @mousedown.self="showEdit = false">
    <div class="modal" style="width:560px">
      <h3>{{ isNew ? '新增项目' : '编辑项目 — ' + form.name }}</h3>
      <div class="form-grid">
        <label>项目名称<input type="text" v-model="form.name" /></label>
        <label>别名（逗号分隔，用于导入匹配）<input type="text" v-model="form.aliasStr" /></label>
        <label>负责人<input type="text" v-model="form.contact" /></label>
        <label>联系电话<input type="text" v-model="form.phone" /></label>
        <label class="full">地址<input type="text" v-model="form.address" /></label>
        <label class="full">备注<input type="text" v-model="form.note" /></label>
      </div>
      <div class="row end" style="margin-top:14px">
        <button class="btn" @click="showEdit = false">取消</button>
        <button class="btn primary" @click="save">保存</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { loadOrgTree, orgTree } from './orgStore'

const auth = useAuthStore()
const projects = ref([])
const loadErr = ref('')
const showEdit = ref(false)
const isNew = ref(false)
const form = reactive({ _orig: '', name: '', aliasStr: '', contact: '', phone: '', address: '', note: '' })

const on = (p) => (p.status || '启用') === '启用'

async function load() {
  loadErr.value = ''
  try {
    const data = await api('/api/projects')
    projects.value = data.projects || []
  } catch (e) { loadErr.value = e.message }
}

function edit(p) {
  isNew.value = !p
  form._orig = p ? p.name : ''
  form.name = p ? p.name : ''
  form.aliasStr = p ? (p.aliases || []).join(',') : ''
  form.contact = p ? p.contact || '' : ''
  form.phone = p ? p.phone || '' : ''
  form.address = p ? p.address || '' : ''
  form.note = p ? p.note || '' : ''
  showEdit.value = true
}

async function save() {
  const p = {
    name: form.name.trim(),
    aliases: form.aliasStr.split(/[,，]/).map((s) => s.trim()).filter(Boolean),
    contact: form.contact, phone: form.phone, address: form.address, note: form.note,
  }
  if (form._orig) p._orig = form._orig
  try {
    await api('/api/projects/save', { body: { project: p } })
    await auth.refreshProjects()
    showEdit.value = false
    if (orgTree()) await loadOrgTree(true)
    load()
    toast('已保存（项目档案/组织树已同步）')
  } catch (e) { alert(e.message) }
}

async function toggle(p) {
  const next = on(p)
  try {
    await api('/api/projects/save', { body: { project: { name: p.name, _orig: p.name, status: next ? '启用' : '停用' } } })
    await auth.refreshProjects()
    load()
    toast(next ? '已启用' : '已停用')
  } catch (e) { alert(e.message) }
}

async function del(p) {
  if (!window.confirm(`确认删除项目「${p.name}」？名下有人员档案时将无法删除。`)) return
  try {
    await api('/api/projects/save', { body: { action: 'delete', project: { name: p.name } } })
    await auth.refreshProjects()
    load()
    toast('已删除（其他模块项目列表已同步）')
  } catch (e) { alert(e.message) }
}

onMounted(load)
</script>
