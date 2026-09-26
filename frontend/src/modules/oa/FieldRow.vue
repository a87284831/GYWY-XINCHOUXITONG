<template>
  <!-- textarea -->
  <div v-if="f.type === 'textarea'">
    <Lbl :f="f" />
    <textarea v-model="values[f.key]" rows="2" style="width:100%"></textarea>
  </div>

  <!-- select / radio -->
  <div v-else-if="f.type === 'select' || f.type === 'radio'">
    <Lbl :f="f" />
    <select v-model="values[f.key]" :disabled="f.readonly" style="min-width:200px" :style="f.readonly ? 'background:#f3f4f6;' : ''">
      <option value="">请选择</option>
      <option v-for="o in opts(f)" :key="o" :value="o">{{ o }}</option>
    </select>
  </div>

  <!-- multi -->
  <div v-else-if="f.type === 'multi'">
    <Lbl :f="f" />
    <div>
      <label v-for="o in opts(f)" :key="o" style="margin-right:12px;font-size:13px">
        <input v-model="values[f.key]" type="checkbox" :value="o"> {{ o }}
      </label>
    </div>
  </div>

  <!-- person -->
  <div v-else-if="f.type === 'person'">
    <Lbl :f="f" />
    <select v-model="sel[f.key]" :disabled="hasProject && !projVal" style="min-width:220px" @change="$emit('person-change', f.key)">
      <option value="">{{ hasProject && !projVal ? '请先选择项目' : '请选择人员' }}</option>
      <option v-for="s in personList" :key="s.id" :value="String(s.id)">{{ s.name }}（{{ s.project }}{{ s.position ? '·' + s.position : '' }}）</option>
    </select>
    <input type="hidden" v-model="values[f.key]">
    <div style="font-size:11px;color:#9ca3af;margin-top:2px">{{ hasProject && !projVal ? '请先选择上方项目，再选择本项目人员' : '选择人员后自动带出档案信息' }}</div>
  </div>

  <!-- project -->
  <div v-else-if="f.type === 'project'">
    <Lbl :f="f" />
    <select v-model="values[f.key]" style="min-width:200px" @change="$emit('project-change')">
      <option value="">请选择项目</option>
      <option v-for="p in projects" :key="p" :value="p">{{ p }}</option>
    </select>
    <div v-if="hasPerson" style="font-size:11px;color:#9ca3af;margin-top:2px">选择项目后，下方人员列表将仅显示该项目人员</div>
  </div>

  <!-- department -->
  <div v-else-if="f.type === 'department'">
    <Lbl :f="f" />
    <select v-model="values[f.key]" :disabled="!projVal" style="min-width:200px">
      <option value="">{{ projVal ? '请选择部门' : '请先选择项目' }}</option>
      <option v-for="v in deptList" :key="v" :value="v">{{ v }}</option>
    </select>
    <div style="font-size:11px;color:#9ca3af;margin-top:2px">{{ !projVal ? '请先选择上方项目，再选择该项目下已设立的部门' : '仅显示所选项目下已设立的部门' }}</div>
  </div>

  <!-- perf（自动计算） -->
  <div v-else-if="f.type === 'perf'">
    <Lbl :f="f" />
    <div style="font-size:15px;font-weight:700;color:#dc2626;padding-top:2px">{{ perfDisplay }}</div>
    <div style="font-size:11px;color:#9ca3af">= 核定月薪 − 基本工资（自动计算）</div>
  </div>

  <!-- attachment -->
  <div v-else-if="f.type === 'attachment'" style="display:flex;justify-content:space-between;align-items:center;padding:10px 2px;border-bottom:1px dashed #f0f2f6">
    <div style="min-width:0;padding-right:8px">
      <div style="font-size:13.5px;font-weight:500;color:#1f2937">{{ f.label || f.key }} <span v-if="f.required" style="color:#dc2626">*</span></div>
      <div style="font-size:11.5px;color:#9ca3af;margin-top:2px">{{ f.hint || '支持 pdf/jpg/png' }}</div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
      <span :style="'font-size:11.5px;padding:2px 10px;border-radius:14px;white-space:nowrap;' + (values[f.key] ? 'background:#e7f7ef;color:#15803d' : 'background:#f3f4f6;color:#9ca3af')">{{ values[f.key] ? '已上传' : '未上传' }}</span>
      <label style="display:inline-block;cursor:pointer;font-size:11.5px;color:#2563eb;border:1px solid #2563eb;padding:2px 10px;border-radius:14px;background:#fff;white-space:nowrap">上传
        <input type="file" style="display:none" @change="$emit('attach', $event, f.key)">
      </label>
    </div>
    <input type="hidden" v-model="values[f.key]">
  </div>

  <!-- date -->
  <div v-else-if="f.type === 'date'">
    <Lbl :f="f" />
    <input v-model="values[f.key]" type="date" :disabled="f.readonly" :style="f.readonly ? 'background:#f3f4f6;' : ''">
  </div>

  <!-- number / amount -->
  <div v-else-if="f.type === 'number' || f.type === 'amount'">
    <Lbl :f="f" />
    <input v-model="values[f.key]" type="number" step="0.01" :disabled="f.readonly" style="min-width:180px" :style="f.readonly ? 'background:#f3f4f6;' : ''">
  </div>

  <!-- 默认单行文本 -->
  <div v-else>
    <Lbl :f="f" />
    <input v-model="values[f.key]" type="text" style="width:100%;max-width:360px">
  </div>
</template>

<script setup>
// 单字段渲染 — 复刻 appFieldHtml（app.js:6100-6159）
import { computed, defineComponent, h } from 'vue'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  f: { type: Object, required: true },
  values: { type: Object, required: true },
  sel: { type: Object, required: true },
  personList: { type: Array, default: () => [] },
  deptList: { type: Array, default: () => [] },
  hasProject: Boolean,
  hasPerson: Boolean,
  projVal: { type: String, default: '' },
  perfDisplay: { type: String, default: '—' },
})
defineEmits(['person-change', 'project-change', 'attach'])

const auth = useAuthStore()
const projects = computed(() => auth.projects || [])

function opts(f) {
  return (f.options || []).map((o) => (typeof o === 'string' ? o : (o.value || o.label || '')))
}
// 字段标签（必填星号 / 自动带出标记）
const Lbl = defineComponent({
  props: { f: { type: Object, required: true } },
  setup(p) {
    return () => h('div', { style: 'font-size:13px;color:#374151;font-weight:600;margin-bottom:3px' }, [
      String(p.f.label || p.f.key) + ' ',
      p.f.required ? h('span', { style: 'color:#dc2626' }, '*') : null,
      p.f.readonly ? h('span', { style: 'color:#9ca3af;font-size:11px;font-weight:400' }, ' (自动带出)') : null,
    ])
  },
})
</script>
