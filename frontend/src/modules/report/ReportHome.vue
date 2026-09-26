<template>
  <div class="card">
    <h3>报表总览</h3>
    <div class="msg info" style="margin-bottom:14px">点击下方报表卡片进入对应分析报表；各报表可按时段筛选并按项目/部门下钻。</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px">
      <div v-for="x in cards" :key="x.k" class="rpt-home-card" :style="{ borderTop: '4px solid ' + x.c }" @click="$router.push('/' + x.k)">
        <div class="rpt-home-ico" :style="{ background: x.c + '22' }">{{ x.i }}</div>
        <div class="rpt-home-name" :style="{ color: x.c }">{{ x.t }}</div>
        <div class="rpt-home-desc">{{ x.d }}</div>
        <div class="rpt-home-go" :style="{ color: x.c }">进入报表 →</div>
      </div>
    </div>
    <div class="hint" style="margin-top:14px">报表查看权限：管理员可在「系统设置 → 权限」中为各账号类型勾选报表权限；项目账号默认仅可见本项目数据。</div>
  </div>
</template>

<script setup>
// 报表总览 — 复刻 pageReportHome（app.js:2259-2277）
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const cards = computed(() => {
  const list = []
  if (auth.can('hr_report')) list.push({ k: 'hrReport', i: '👥', t: '人力资源报表', d: '在职结构 · 人员趋势 · 年龄/司龄/学历/籍贯 · 入离职', c: '#2563eb' })
  if (auth.can('salary_report')) list.push({ k: 'salaryReport', i: '💰', t: '薪酬报表', d: '应发/实发 · 薪资构成 · 五险一金与个税 · 预算执行率 · 绩效工资', c: '#16a34a' })
  if (auth.can('attendance_report')) list.push({ k: 'attReport', i: '📅', t: '考勤报表', d: '出勤率 · 迟到/早退/缺卡/旷工/请假 · 异常趋势 · 异常Top10', c: '#f59e0b' })
  return list
})
</script>
