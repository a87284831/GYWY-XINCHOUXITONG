<script setup>
// 清单外商品审核（复刻旧 #/customs：归入候选标准商品 / 新建入库 / 忽略确认通过）
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { seedCustomForm, mapPayload, saveCustomPayload } from './adminLogic'

const auth = useAuthStore()
const rows = ref([])
const loading = ref(false)

const editVisible = ref(false)
const editForm = ref({ name:'', brand:'', spec:'', unit:'', line:'', category:'', alias:'', quantity:'', stock:'', use_location:'', remark:'' })
const editRow = ref(null)
const lines = ref([])
const cats = ref([])
const saving = ref(false)

const dupList = ref([])
const dupPick = ref(null)
const dupRow = ref(null)

function uid() {
  return (auth.user && auth.user.id) || 0
}

async function load() {
  loading.value = true
  try {
    const d = await api('/api/purchase/admin/customs')
    rows.value = d.data.map((r) => ({ ...r, _sel: null, _saveAlias: false }))
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

// 直接归入候选商品
async function doMap(row) {
  if (!row._sel) return
  try {
    const d = await api('/api/purchase/admin/customs/map', { body: mapPayload(row, uid()) })
    toast(d.msg || '已归入')
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

// 忽略 = 直接确认通过（不绑定标准商品）
async function doIgnore(row) {
  try {
    await api('/api/purchase/admin/items/' + row.id + '/confirm', { method: 'POST' })
    toast('已忽略（确认通过）')
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function loadCats() {
  if (!editForm.value.line) {
    cats.value = []
    return
  }
  try {
    const d = await api('/api/purchase/products/categories?line=' + encodeURIComponent(editForm.value.line))
    let cs = d.data || []
    if (editForm.value.category && cs.indexOf(editForm.value.category) < 0) {
      cs = [editForm.value.category].concat(cs)
    }
    cats.value = cs
  } catch (e) {
    cats.value = []
  }
}

async function openEdit(row) {
  editRow.value = row
  editForm.value = seedCustomForm(row)
  try {
    const d = await api('/api/purchase/products/lines')
    let ls = d.data || []
    if (row.line && ls.indexOf(row.line) < 0) ls = [row.line].concat(ls)
    lines.value = ls
  } catch (e) {
    toast(e.message, false)
  }
  await loadCats()
  dupList.value = []
  dupPick.value = null
  editVisible.value = true
}

function onLineChange(v) {
  editForm.value.line = v
  editForm.value.category = ''
  loadCats()
}

async function submitEdit() {
  const f = editForm.value
  if (!f.name || !f.line || !f.category) {
    toast('请填写商品名称、条线、分类', false)
    return
  }
  saving.value = true
  try {
    const d = await api('/api/purchase/admin/customs/check-duplicate', {
      body: { name: f.name, line: f.line, category: f.category, brand: f.brand, spec: f.spec },
    })
    if (d.ok && d.data && d.data.length > 0) {
      dupList.value = d.data
      dupPick.value = null
      dupRow.value = editRow.value
      return
    }
    await doSave(editRow.value, 'create', 0)
  } catch (e) {
    toast(e.message, false)
  } finally {
    saving.value = false
  }
}

async function doSave(row, mode, pid) {
  const d = await api('/api/purchase/admin/customs/save', {
    body: saveCustomPayload(editForm.value, row, mode, pid, uid()),
  })
  toast(d.msg || '已保存')
  editVisible.value = false
  dupList.value = []
  dupPick.value = null
  dupRow.value = null
  load()
}

function onSelectChange(row, v) {
  if (v === '__new__') {
    row._sel = null
    openEdit(row)
  } else {
    row._sel = v
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;">
      <span style="font-size:18px;font-weight:700;">清单外商品审核（归入标准商品库）</span>
      <el-button type="primary" style="margin-left:10px" :loading="loading" @click="load">刷新</el-button>
    </div>

    <el-alert type="info" :closable="false" style="margin:12px 0"
      title="项目填报人员填写的清单外商品在此统一审核。可直接从候选商品中选择「归入」，或选择「＋ 新建标准商品入库」编辑全部字段后直接入库；勾选「保存别名」后，项目下次填写相同名称会自动匹配。" />

    <el-table v-loading="loading" :data="rows" border stripe>
      <el-table-column type="index" label="#" width="48" />
      <el-table-column prop="project_name" label="项目" width="130" />
      <el-table-column label="条线" width="70">
        <template #default="{ row }">
          <el-tag size="small" effect="plain">{{ row.line }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="填报内容(可编辑)" min-width="360">
        <template #default="{ row }">
          <el-input v-model="row.item_name" size="small" placeholder="商品名称" style="width:100%" />
          <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;">
            <el-input v-model="row.brand" size="small" placeholder="品牌" style="width:68px" />
            <el-input v-model="row.spec" size="small" placeholder="规格" style="width:86px" />
            <el-input v-model="row.unit" size="small" placeholder="单位" style="width:56px" />
          </div>
          <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;">
            <el-input v-model="row.category" size="small" placeholder="分类(必填)" style="width:100%" />
          </div>
        </template>
      </el-table-column>
      <el-table-column label="归入标准商品" min-width="250">
        <template #default="{ row }">
          <el-select :model-value="row._sel" filterable clearable placeholder="搜索选择标准商品或新建入库"
            style="width:230px" @update:model-value="(v) => onSelectChange(row, v)">
            <el-option label="＋ 新建标准商品入库" value="__new__" />
            <el-option v-for="c in (row.candidates || [])" :key="c.id"
              :label="c.name + '（' + c.spec + '）' + (c.brand ? '·' + c.brand : '')" :value="c.id" />
          </el-select>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="260" fixed="right">
        <template #default="{ row }">
          <el-button size="small" type="success" style="margin-right:8px" @click="openEdit(row)">保存入库</el-button>
          <el-checkbox v-model="row._saveAlias" style="margin-right:8px">保存别名</el-checkbox>
          <el-button size="small" type="primary" :disabled="!row._sel" @click="doMap(row)">归入</el-button>
          <el-button size="small" type="info" @click="doIgnore(row)">忽略</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-empty v-if="!loading && rows.length === 0" description="暂无清单外商品" />

    <!-- 编辑入库（新建/归入标准商品） -->
    <el-dialog v-model="editVisible" title="编辑入库（新建/归入标准商品）" width="520px">
      <el-form label-width="80px">
        <el-form-item label="商品名称" required>
          <el-input v-model="editForm.name" placeholder="必填" />
        </el-form-item>
        <el-form-item label="条线" required>
          <el-select v-model="editForm.line" placeholder="选择条线" style="width:100%" @change="onLineChange">
            <el-option v-for="l in lines" :key="l" :label="l" :value="l" />
          </el-select>
        </el-form-item>
        <el-form-item label="分类" required>
          <el-select v-model="editForm.category" placeholder="选择或输入分类" filterable allow-create style="width:100%">
            <el-option v-for="c in cats" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <el-form-item label="品牌">
          <el-input v-model="editForm.brand" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="规格">
          <el-input v-model="editForm.spec" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="单位">
          <el-input v-model="editForm.unit" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="别名">
          <el-input v-model="editForm.alias" placeholder="员工填写的原名称（可修改，保存后项目可搜索到）" />
        </el-form-item>
        <el-form-item label="现有库存">
          <el-input v-model="editForm.stock" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" placeholder="可修改" />
        </el-form-item>
      </el-form>

      <div v-if="dupList.length" style="margin-top:12px;padding:10px;border-radius:6px;background:#fdf6ec">
        <el-alert type="warning" :closable="false" show-icon title="标准库存在同名商品，请选择处理方式" />
        <el-select v-model="dupPick" placeholder="选择要归入的已有商品" filterable clearable
          style="width:100%;margin-top:8px">
          <el-option v-for="d in dupList" :key="d.id" :label="d.name + '（' + (d.spec || '-') + '）'" :value="d.id" />
        </el-select>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
          <el-button @click="dupList = []; dupPick = null">取消选择</el-button>
          <el-button type="warning" @click="doSave(dupRow, 'create', 0)">仍要新建</el-button>
          <el-button type="primary" :disabled="!dupPick" @click="doSave(dupRow, 'merge', dupPick)">归入已有</el-button>
        </div>
      </div>

      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitEdit">确认入库</el-button>
      </template>
    </el-dialog>
  </div>
</template>
