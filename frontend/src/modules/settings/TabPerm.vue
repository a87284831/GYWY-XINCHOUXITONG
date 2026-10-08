<template>
  <div class="perm-layout">
    <div class="perm-side">
      <div class="perm-side-head"><span>角色列表</span><button class="btn primary sm" @click="roleTypeNew">＋ 新建</button></div>
      <div class="perm-role-list">
        <div v-if="loadErr" style="padding:14px" class="msg err">{{ loadErr }}</div>
        <div v-for="r in roles" :key="r.id" class="perm-role-item" :class="{ active: roleTypeId === r.id }" @click="selectRoleType(r.id)">
          <div class="perm-role-ico" :style="{ background: roleColor(r.id) }">{{ roleIcon(r.id, r.name) }}</div>
          <div class="perm-role-info">
            <div class="perm-role-name">{{ r.name }}<span v-if="r.builtin" class="perm-builtin">内置</span></div>
            <div class="perm-role-meta">{{ (roleCounts[r.id] || 0) }} 个账号</div>
          </div>
          <span v-if="!r.builtin" class="perm-role-del" title="删除" @click.stop="roleTypeDel(r.id)">×</span>
        </div>
      </div>
    </div>
    <div class="perm-main">
      <template v-if="curRole">
        <div class="perm-role-head">
          <div class="perm-role-head-info">
            <div class="perm-role-ico lg" :style="{ background: roleColor(curRole.id) }">{{ roleIcon(curRole.id, curRole.name) }}</div>
            <div>
              <div class="perm-role-title">{{ curRole.name }} <span v-if="curRole.builtin" class="perm-builtin">内置</span></div>
              <div class="perm-role-desc">{{ curRole.id === 'admin' ? '拥有全部权限，数据范围固定为全部项目，不可修改' : '自定义该角色的模块权限与数据范围，保存后对使用此角色的账号立即生效' }} · {{ (roleCounts[curRole.id] || 0) }} 个账号使用此类型</div>
            </div>
          </div>
          <button v-if="curRole.id !== 'admin'" class="btn primary sm" @click="roleTypeSave">保存此角色</button>
        </div>
        <div class="perm-tabs">
          <div class="perm-tab" :class="{ active: permTab === 'perm' }" @click="permTab = 'perm'">权限配置</div>
          <div class="perm-tab" :class="{ active: permTab === 'members' }" @click="permTab = 'members'">成员管理（{{ (roleCounts[curRole.id] || 0) }}）</div>
        </div>
        <!-- 权限配置 -->
        <div v-if="permTab === 'perm'" style="margin-top:14px">
          <div class="perm-scope"><span style="font-weight:600;color:#1f2937">数据范围</span>
            <label><input type="radio" value="all" v-model="draftScope" :disabled="isAdmin"> 全部项目（all）</label>
            <label><input type="radio" value="project" v-model="draftScope" :disabled="isAdmin"> 仅本项目（project）</label>
          </div>
          <div class="rt-tree" style="max-height:400px">
            <div v-for="m in auth.appModules" :key="m.name" class="rt-mod" :class="{ on: modChecked(m).checked > 0, open: openMods.has(m.name) }">
              <div class="rt-mod-head" @click="toggleOpen(m.name)">
                <input type="checkbox" class="rt-mod-cb" :checked="isAdmin || modChecked(m).all" :disabled="isAdmin" :indeterminate="!isAdmin && modChecked(m).some" @click.stop @change="toggleModAll(m, $event.target.checked)">
                <span class="mpr-ico" :style="{ background: m.color }">{{ m.icon }}</span>
                <span class="rt-mod-name">{{ m.name }}</span>
                <span class="rt-mod-count">{{ modChecked(m).checked }}/{{ modChecked(m).total }}</span>
                <span class="rt-arrow">▸</span>
              </div>
              <div class="rt-mod-sub">
                <label v-for="p in m.perms" :key="p[0]" class="rt-func">
                  <input type="checkbox" class="rt-func-cb" :value="p[0]" :checked="isAdmin || draftPerms.includes(p[0])" :disabled="isAdmin" @change="onFuncCheck(p[0], $event.target.checked)"> {{ p[1] }}</label>
              </div>
            </div>
          </div>
          <div class="hint" style="margin-top:10px">{{ isAdmin ? '超级管理员固定拥有全部权限，不可修改。' : '勾选模块 = 全选该模块权限点，支持半选状态；保存后对所有使用此角色的账号立即生效。' }}</div>
        </div>
        <!-- 成员管理 -->
        <div v-else style="margin-top:14px">
          <div class="row" style="margin-bottom:8px;justify-content:space-between">
            <span class="hint" style="margin:0">该角色下的账号（{{ roleUsers.length }}）—— 审批入职办理开通的账号也会出现在这里</span>
            <button class="btn success sm" @click="userEdit(null)">＋ 新增账号</button>
          </div>
          <div v-if="roleUsers.length" class="table-wrap"><table class="tb">
            <thead><tr><th>用户名</th><th>姓名</th><th>绑定人员</th><th>账号类型</th><th>绑定项目</th><th>员工状态</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="u in roleUsers" :key="u.id">
                <td>{{ u.username }}</td><td>{{ u.name }}</td>
                <td><template v-if="u.staff_id">{{ u.staff_name || '已关联' }}<span v-if="u.staff_deleted" class="tag red" style="margin-left:4px">已离职</span></template><span v-else class="tag gray">未绑定人员</span></td>
                <td><select class="role-quick-sel" :value="u.role" @change="quickSetRole(u, $event.target.value)">
                  <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select></td>
                <td>{{ u.project || '-' }}</td>
                <td><span v-if="u.enabled" class="tag green">启用</span><span v-else class="tag gray">停用</span></td>
                <td>
                  <button class="btn sm" @click="userEdit(u)">编辑</button>
                  <button class="btn sm" :class="u.enabled ? 'danger' : 'success'" @click="userToggleStatus(u.id, !u.enabled)">{{ u.enabled ? '停用' : '启用' }}</button>
                </td>
              </tr>
            </tbody>
          </table></div>
          <div v-else class="hint" style="padding:22px;text-align:center;color:#9ca3af">该角色下暂无账号，点击右上角"新增账号"创建</div>
        </div>
      </template>
      <div v-else class="hint" style="padding:24px;text-align:center">请选择左侧角色查看详情</div>
    </div>

    <Modal :title="(um.isNew ? '新增账号' : '编辑账号 — ' + um.username)" :show="userModal" :width="620" @close="userModal = false">
      <div class="form-grid">
        <label>用户名<input type="text" v-model="um.username" :readonly="!um.isNew" :style="!um.isNew ? 'background:#f5f5f5' : ''"></label>
        <label>姓名<input type="text" v-model="um.name" readonly style="background:#f5f5f5"></label>
        <label class="full">绑定人员（选人后自动带姓名与项目；支持搜索或按项目/部门筛选）
          <div style="display:flex;gap:6px;margin-bottom:6px">
            <input type="text" v-model="staffKw" placeholder="输入姓名/岗位关键词" style="flex:2;min-width:0">
            <select v-model="staffProj" @change="staffDept = ''" style="flex:1.2;min-width:0">
              <option value="">全部项目</option>
              <option v-for="p in staffProjects" :key="p" :value="p">{{ p }}</option>
            </select>
            <select v-model="staffDept" style="flex:1.2;min-width:0">
              <option value="">全部部门</option>
              <option v-for="d in staffDepts" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
          <select v-model="um.staff_id" @change="onUserStaffChange">
            <option value="">{{ um.isAdmin ? '（admin 内置账号，可不绑定）' : '— 请选择在职人员（一人一号，当前可选 ' + staffOpts.length + ' 人）—' }}</option>
            <option v-for="x in staffOpts" :key="x.id" :value="String(x.id)">{{ x.name }}（{{ x.project }}{{ x.dept_path ? '/' + x.dept_path : '' }}）</option>
          </select>
        </label>
        <label>账号类型<select v-model="um.role" @change="onUserRoleChange">
          <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select></label>
        <label v-show="umShowProj">绑定项目<select v-model="um.project"><option value="">-</option><option v-for="p in auth.projects" :key="p" :value="p">{{ p }}</option></select></label>
        <label>账号状态<select v-model="um.enabledStr"><option value="1">启用</option><option value="0">停用</option></select></label>
        <label class="full">{{ um.isNew ? '初始密码（至少8位）' : '重置密码（留空则不修改）' }}<input type="password" v-model="um.password"></label>
      </div>
      <div class="hint" style="margin-top:8px">账号必须绑定具体人员（admin 超管可豁免）：一人一号，绑定人员离职/拉黑时账号自动停用、复职恢复。账号类型的功能权限在上方"账号类型"区域配置，选择类型后自动应用。</div>
      <template #foot>
        <button class="btn" @click="userModal = false">取消</button>
        <button class="btn primary" @click="userSave">保存</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
// 权限管理 — 复刻 settingsPerm/permTreeHtml/renderMembers/userEdit 群（app.js:3825-4031, 4175-4231）
import { ref, reactive, computed, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { roleIcon, roleColor } from './settingsLogic'
import { staffOptions } from '@/modules/oa/staffOptions'
import Modal from '@/components/Modal.vue'

const auth = useAuthStore()
const roles = ref([])
const roleTypeId = ref(null)
const permTab = ref('perm')
const usersList = ref([])
const roleCounts = ref({})
const loadErr = ref('')
const draftPerms = ref([])
const draftScope = ref('all')
const openMods = ref(new Set())
const userModal = ref(false)
const staffList = ref([])
const staffKw = ref('')
const staffProj = ref('')
const staffDept = ref('')
const um = reactive({ isNew: false, isAdmin: false, id: 0, username: '', name: '', role: 'project', project: '', staff_id: '', enabledStr: '1', password: '' })

const curRole = computed(() => roles.value.find((x) => x.id === roleTypeId.value) || null)
const isAdmin = computed(() => curRole.value && curRole.value.id === 'admin')
const roleUsers = computed(() => usersList.value.filter((u) => u.role === roleTypeId.value))
const umShowProj = computed(() => {
  const r = roles.value.find((x) => x.id === um.role)
  return !!(r && r.scope === 'project')
})

// 绑定人员筛选：搜索姓名/岗位 + 项目/部门级联（前端本地过滤，数据已全量在手）
const staffProjects = computed(() => [...new Set(staffList.value.map((x) => x.project).filter(Boolean))].sort())
const staffDepts = computed(() => {
  const pool = staffProj.value ? staffList.value.filter((x) => x.project === staffProj.value) : staffList.value
  return [...new Set(pool.map((x) => x.dept_path).filter(Boolean))].sort()
})
const staffFiltered = computed(() => {
  const kw = staffKw.value.trim().toLowerCase()
  return staffList.value.filter((x) => {
    if (staffProj.value && x.project !== staffProj.value) return false
    if (staffDept.value && x.dept_path !== staffDept.value) return false
    if (kw && !((x.name || '').toLowerCase().includes(kw) || (x.position || '').toLowerCase().includes(kw))) return false
    return true
  })
})
// 编辑时已绑定人员不在过滤结果里也要出现在选项中，避免回显丢值
const staffOpts = computed(() => {
  const list = staffFiltered.value
  if (um.staff_id && !list.some((x) => String(x.id) === String(um.staff_id))) {
    const cur = staffList.value.find((x) => String(x.id) === String(um.staff_id))
    if (cur) return [cur, ...list]
  }
  return list
})

function modChecked(m) {
  const mperms = (m.perms || []).map((p) => p[0])
  const checked = isAdmin.value ? mperms.length : mperms.filter((p) => draftPerms.value.includes(p)).length
  const all = mperms.length > 0 && checked === mperms.length
  return { checked, total: mperms.length, all, some: checked > 0 && !all }
}
function toggleOpen(name) {
  const s = new Set(openMods.value)
  s.has(name) ? s.delete(name) : s.add(name)
  openMods.value = s
}
function toggleModAll(m, checked) {
  const mperms = (m.perms || []).map((p) => p[0])
  const rest = draftPerms.value.filter((p) => !mperms.includes(p))
  draftPerms.value = checked ? [...rest, ...mperms] : rest
}
function onFuncCheck(code, checked) {
  const has = draftPerms.value.includes(code)
  if (checked && !has) draftPerms.value = [...draftPerms.value, code]
  if (!checked && has) draftPerms.value = draftPerms.value.filter((p) => p !== code)
}
function initDraft() {
  const r = curRole.value
  draftPerms.value = [...((r && r.perms) || [])]
  draftScope.value = (r && r.scope) || 'all'
}
function selectRoleType(id) {
  roleTypeId.value = id
  permTab.value = 'perm'
  initDraft()
}
function syncAuthRoles(list) { roles.value = list; auth.roles = list }

async function roleTypeSave() {
  const r = curRole.value
  const perms = [...draftPerms.value]
  const scope = draftScope.value || 'all'
  const next = roles.value.map((x) => (x.id === r.id ? Object.assign({}, x, { perms, scope }) : x))
  try {
    await api('/api/roles/save', { body: { roles: next } })
    syncAuthRoles(next)
    initDraft()
    toast('账号类型权限已保存')
  } catch (e) { alert(e.message) }
}

function roleTypeNew() {
  const name = prompt('请输入新账号类型名称：')
  if (!name) return
  const id = 'custom_' + Date.now()
  roles.value.push({ id, name: name.trim(), builtin: false, scope: 'all', perms: [] })
  selectRoleType(id)
}

async function roleTypeDel(id) {
  if (!confirm('确认删除该账号类型？使用此类型的账号将自动转为只读账号。')) return
  const next = roles.value.filter((r) => r.id !== id)
  try {
    const resp = await api('/api/roles/save', { body: { roles: next } })
    syncAuthRoles(resp.roles)
    roleTypeId.value = resp.roles[0] ? resp.roles[0].id : 'viewer'
    permTab.value = 'perm'
    initDraft()
    loadUsersList()
    toast('已删除')
  } catch (e) { alert(e.message) }
}

async function loadUsersList() {
  try {
    const data = await api('/api/users')
    usersList.value = data.users
    const counts = {}
    data.users.forEach((u) => { counts[u.role] = (counts[u.role] || 0) + 1 })
    roleCounts.value = counts
  } catch (e) { console.error(e) }
}

async function userToggleStatus(id, enabled) {
  try {
    await api('/api/users/status', { body: { id, enabled } })
    toast(enabled ? '已启用' : '已停用')
    loadUsersList()
  } catch (e) { alert(e.message) }
}

async function quickSetRole(u, roleId) {
  const rdef = roles.value.find((r) => r.id === roleId)
  try {
    await api('/api/users/save', { body: { user: { id: u.id, role: roleId, name: u.name, username: u.username, project: u.project || '' } } })
    toast('已设为「' + rdef.name + '」')
    loadUsersList()
  } catch (e) { alert(e.message); loadUsersList() }
}

function userEdit(u) {
  const isNew = !u
  const v = u || { username: '', name: '', role: 'project', project: '', enabled: true }
  um.isNew = isNew
  um.isAdmin = v.role === 'admin' || v.username === 'admin'
  um.id = v.id || 0
  um.username = v.username || ''
  um.name = v.name || ''
  um.role = v.role || 'project'
  um.project = v.project || ''
  um.staff_id = v.staff_id != null ? String(v.staff_id) : ''
  um.enabledStr = v.enabled ? '1' : '0'
  um.password = ''
  staffKw.value = ''
  staffProj.value = ''
  staffDept.value = ''
  userModal.value = true
  staffOptions().then((s) => { staffList.value = s })
}
function onUserStaffChange() {
  const s = staffList.value.find((x) => String(x.id) === String(um.staff_id))
  if (s) um.name = s.name
}
async function userSave() {
  const rdef = roles.value.find((r) => r.id === um.role)
  const project = rdef && rdef.scope === 'project' ? um.project : ''
  const staffId = um.staff_id ? Number(um.staff_id) : null
  try {
    await api('/api/users/save', { body: { user: { id: um.id || null, username: um.username.trim(), name: um.name.trim(), role: um.role, project, staff_id: staffId, enabled: um.enabledStr === '1', password: um.password } } })
    userModal.value = false
    toast('已保存')
    loadUsersList()
  } catch (e) { alert(e.message) }
}

onMounted(async () => {
  loadErr.value = ''
  try {
    const data = await api('/api/roles')
    syncAuthRoles(data.roles)
    if (!roleTypeId.value || !data.roles.find((r) => r.id === roleTypeId.value)) roleTypeId.value = data.roles[0] ? data.roles[0].id : 'viewer'
    initDraft()
    await loadUsersList()
  } catch (e) { loadErr.value = e.message }
})
</script>
