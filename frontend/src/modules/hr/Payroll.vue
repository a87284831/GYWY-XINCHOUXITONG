<template>
  <div class="card">
    <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
      <label class="fld">核算月份 <input type="month" v-model="ui.month" @change="reloadActive" /></label>
      <span style="flex:1"></span>
      <div class="pay-tabs">
        <button class="pay-tab" :class="{ active: payTab === 'emp' }" @click="switchTab('emp')">员工核算</button>
        <button v-if="isAdmin" class="pay-tab" :class="{ active: payTab === 'mgr' }" @click="switchTab('mgr')">管理人员核算</button>
        <button v-if="isAdmin" class="pay-tab" :class="{ active: payTab === 'case' }" @click="switchTab('case')">案场人员核算</button>
        <button v-if="isAdmin" class="pay-tab" :class="{ active: payTab === 'hq' }" @click="switchTab('hq')">总部人员核算</button>
      </div>
    </div>

    <!-- 员工核算 -->
    <div style="margin-top:10px" v-show="payTab === 'emp'">
      <div class="card">
        <h3>{{ isAdmin ? '员工核算操作' : '核算操作' }}</h3>
        <template v-if="isAdmin">
          <div class="row">
            <button class="btn" @click="calcProjects = auth.projects.slice()">全选</button>
            <button class="btn" @click="calcProjects = []">清空</button>
            <button class="btn primary" @click="doCalc">开始核算（覆盖旧数据）</button>
            <button class="btn" @click="downloadHistoryTemplate">下载历史模板</button>
            <button class="btn" @click="histFile.click()">导入历史工资</button>
            <input type="file" ref="histFile" accept=".xlsx,.xls" style="display:none" @change="importHistory" />
            <span v-html="archiveHtml"></span>
          </div>
          <div class="checkbox-list" style="margin-top:10px">
            <label v-for="p in projChecks" :key="p">
              <input type="checkbox" :checked="calcProjects.includes(p)" @change="toggleCalcProj(p, $event.target.checked)" /> {{ p }}
            </label>
          </div>
          <div v-if="calcMsg" v-html="calcMsg"></div>
        </template>
        <div v-else class="msg info">项目账号仅可查看/导出本项目已由总部核定完成的薪资数据，薪资核定与归档权限仅总部管理员拥有。</div>
      </div>
      <div class="card">
        <h3>员工核算结果明细</h3>
        <div v-if="empErr" class="msg err">{{ empErr }}</div>
        <template v-else-if="empRows.length">
          <div v-html="attStatusHtml"></div>
          <div class="row" style="margin-bottom:8px">
            <span class="tag blue">核算时间 {{ empMeta.calc_at || '-' }}</span>
            <span class="tag gray">{{ empRows.length }} 人</span>
            <label class="fld">项目筛选
              <select v-model="payProjFilter" @change="onPayProjChange">
                <option value="">全部项目</option><option v-for="p in auth.projects" :key="p">{{ p }}</option>
              </select></label>
            <label class="fld">部门筛选
              <select v-model="payDeptFilter" @change="paySel = ''">
                <option value="">全部部门</option><option v-for="d in payDepts" :key="d">{{ d }}</option>
              </select></label>
            <button class="btn sm" @click="exportProject">导出当前项目表</button>
          </div>
          <div class="table-wrap">
            <table class="tb" ref="payTable">
              <thead><tr><th v-for="h in PAY_HEADS" :key="h">{{ h }}</th><th>备注</th></tr></thead>
              <tbody>
                <tr
                  v-for="r in empFiltered"
                  :key="r.staff_id"
                  :class="{ 'pay-sel': paySel === r.staff_id, 'row-zero-pay': isZeroPayRow(r) }"
                  @click="paySel = r.staff_id"
                >
                  <td>{{ r.project }}</td><td>{{ r.department || '' }}</td><td>{{ r.position }}</td><td>{{ r.name }}</td>
                  <td><span :class="`tag ${STATUS_TAG[r.status] || 'gray'}`">{{ r.status }}</span></td>
                  <td class="num">{{ money(r.fixed) }}</td><td class="num">{{ money(r.base) }}</td>
                  <td class="num">{{ r.req_att }}</td><td class="num">{{ r.act_att }}</td>
                  <td class="num">{{ r.perf_att }}</td><td class="num">{{ r.coef }}</td>
                  <td class="num">{{ money(r.base_pay) }}</td><td class="num">{{ money(r.perf_pay) }}</td>
                  <td class="num">{{ r.sick_days }}</td><td class="num">{{ money(r.sick_pay) }}</td>
                  <td class="num">{{ money(r.night) }}</td><td class="num">{{ money(r.meal) }}</td>
                  <td class="num">{{ money(r.title_sub) }}</td><td class="num">{{ money(r.reward) }}</td>
                  <td class="num">{{ money(r.welfare) }}</td><td class="num">{{ money(r.punish) }}</td>
                  <td class="num">{{ money(r.late_d) }}</td><td class="num">{{ money(r.miss_d) }}</td>
                  <td class="num">{{ money(r.other_d) }}</td><td class="num">{{ money(r.uniform_d) }}</td>
                  <td class="num" style="font-weight:bold">{{ money(r.gross) }}</td>
                  <td class="num">{{ money(r.soc_total) }}</td><td class="num">{{ money(r.spec_total) }}</td>
                  <td class="num">{{ money(r.actual_tax) }}</td>
                  <td class="num" :class="isZeroPayRow(r) ? 'zero-pay' : 'pay-pos'" :title="isZeroPayRow(r) ? `实发${r.net}元（出勤${r.act_att ?? 0}天）` : ''">{{ money(r.net) }}</td>
                  <td><button class="btn sm" @click.stop="openAdjust(r)">微调</button></td>
                  <td class="remark-cell">{{ r.remark || '' }}</td>
                </tr>
              </tbody>
              <tfoot v-if="empFiltered.length">
                <tr style="background:#f0fdf4;font-weight:bold">
                  <td colspan="11" style="text-align:right">合计（{{ empFiltered.length }}人）：</td>
                  <td class="num">{{ money(tot.sums.base_pay) }}</td>
                  <td class="num">{{ money(tot.sums.perf_pay) }}</td><td></td>
                  <td class="num">{{ money(tot.sums.sick_pay) }}</td>
                  <td class="num">{{ money(tot.sums.night) }}</td><td class="num">{{ money(tot.sums.meal) }}</td>
                  <td class="num">{{ money(tot.sums.title_sub) }}</td><td class="num">{{ money(tot.sums.reward) }}</td>
                  <td class="num">{{ money(tot.sums.welfare) }}</td><td class="num">{{ money(tot.sums.punish) }}</td>
                  <td class="num">{{ money(tot.sums.late_d) }}</td><td class="num">{{ money(tot.sums.miss_d) }}</td>
                  <td class="num">{{ money(tot.sums.other_d) }}</td><td class="num">{{ money(tot.sums.uniform_d) }}</td>
                  <td class="num" style="font-weight:bold">{{ money(tot.sums.gross) }}</td>
                  <td class="num">{{ money(tot.sums.soc_total) }}</td>
                  <td class="num">{{ money(tot.sums.spec_total) }}</td>
                  <td class="num">{{ money(tot.sums.actual_tax) }}</td>
                  <td class="num" style="color:#16a34a;font-weight:bold">{{ money(tot.sums.net) }}</td>
                  <td></td><td></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="hint">点击"微调"可对单人补贴/扣款/社保/个税等字段人工修正（留存操作日志）。归档后禁止修改、重传考勤、重算，仅超管可解锁。微调日志见下方。</div>
          <template v-if="empMeta.logs && empMeta.logs.length">
            <h3 style="margin-top:14px">微调日志（本月）</h3>
            <div class="table-wrap" style="max-height:200px">
              <table class="tb">
                <thead><tr><th>时间</th><th>操作人</th><th>姓名</th><th>字段</th><th>原值</th><th>新值</th><th>原因</th></tr></thead>
                <tbody>
                  <tr v-for="(l, i) in empLogs" :key="i">
                    <td>{{ l.ts }}</td><td>{{ l.by }}</td><td>{{ l.staff }}</td><td>{{ FIELD_CN[l.field] || l.field }}</td>
                    <td class="num">{{ l.old }}</td><td class="num">{{ l.new }}</td><td>{{ l.reason }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </template>
        <template v-else>
          <div v-html="attStatusHtml"></div>
          <div class="msg info">{{ isAdmin ? '该月暂无核算数据。选择项目后点击"开始核算"。' : '该月暂无本项目核算数据。' }}</div>
        </template>
      </div>
    </div>

    <!-- 管理/案场/总部（仅总部管理员） -->
    <div v-for="tp in ['mgr', 'case', 'hq']" :key="tp" v-show="payTab === tp" style="margin-top:10px">
      <div class="card">
        <h3>{{ TP_META[tp].title }}</h3>
        <div class="row">
          <button class="btn primary" @click="doCalcType(tp)">{{ TP_META[tp].calcBtn }}（覆盖旧数据）</button>
          <button class="btn" @click="exportType(tp)">{{ TP_META[tp].exportBtn }}</button>
          <button
            v-if="(tp === 'mgr' || tp === 'hq') && isQuarterEndMonth(ui.month)"
            class="btn warn"
            @click="openCoefDialog()"
          >{{ coefEntryLabel(ui.month) }}</button>
          <span v-html="typeArchiveHtml[tp]"></span>
        </div>
        <div class="hint">{{ TP_META[tp].hint }}</div>
        <div v-if="typeErr[tp]" class="msg err">{{ typeErr[tp] }}</div>
        <div v-if="typeMsg[tp]" v-html="typeMsg[tp]"></div>
        <div style="margin-top:10px">
          <template v-if="typeRows[tp].length">
            <div v-html="attStatusHtml"></div>
            <div class="row" style="margin-bottom:8px">
              <span class="tag blue">{{ typeRows[tp].length }} 名{{ TP_META[tp].noun }}</span>
              <label v-if="tp !== 'hq'" class="fld">项目筛选
                <select v-model="typeProjFilter[tp]" @change="typeSel[tp] = ''">
                  <option value="">全部项目</option><option v-for="p in auth.projects" :key="p">{{ p }}</option>
                </select></label>
            </div>
            <div class="table-wrap" style="overflow-x:auto">
              <table class="tb" :ref="(el) => (typeTableEls[tp] = el)">
                <thead><tr>
                  <th v-for="h in payHeadsData" :key="h">{{ h }}</th>
                  <th v-for="c in perfCols[tp]" :key="c.key" style="color:#64748b">{{ c.label }}</th>
                  <th>操作</th>
                  <th>备注</th>
                </tr></thead>
                <tbody>
                  <tr
                    v-for="r in typeFiltered[tp]"
                    :key="r.staff_id"
                    :class="{ 'pay-sel': typeSel[tp] === r.staff_id, 'row-zero-pay': isZeroPayRow(r) }"
                    @click="typeSel[tp] = r.staff_id"
                  >
                      <td>{{ r.project }}</td>
                      <td>{{ r.department || '' }}</td><td>{{ r.position }}</td><td>{{ r.name }}</td>
                      <td><span :class="`tag ${STATUS_TAG[r.status] || 'gray'}`">{{ r.status }}</span></td>
                      <td class="num">{{ money(r.fixed) }}</td><td class="num">{{ money(r.base) }}</td>
                      <td class="num">{{ r.req_att }}</td><td class="num">{{ r.act_att }}</td>
                      <td class="num">{{ r.perf_att }}</td><td class="num">{{ r.coef }}</td>
                      <td class="num">{{ money(r.base_pay) }}</td><td class="num">{{ money(r.perf_pay) }}</td>
                      <td class="num">{{ r.sick_days }}</td><td class="num">{{ money(r.sick_pay) }}</td>
                      <td class="num">{{ money(r.night) }}</td><td class="num">{{ money(r.meal) }}</td>
                      <td class="num">{{ money(r.title_sub) }}</td><td class="num">{{ money(r.reward) }}</td>
                      <td class="num">{{ money(r.welfare) }}</td><td class="num">{{ money(r.punish) }}</td>
                      <td class="num">{{ money(r.late_d) }}</td><td class="num">{{ money(r.miss_d) }}</td>
                      <td class="num">{{ money(r.other_d) }}</td><td class="num">{{ money(r.uniform_d) }}</td>
                      <td class="num" style="font-weight:bold">{{ money(r.gross) }}</td>
                      <td class="num">{{ money(r.soc_total) }}</td><td class="num">{{ money(r.spec_total) }}</td>
                      <td class="num">{{ money(r.actual_tax) }}</td>
                      <td class="num" :class="isZeroPayRow(r) ? 'zero-pay' : 'pay-pos'" :title="isZeroPayRow(r) ? `实发${r.net}元（出勤${r.act_att ?? 0}天）` : ''">{{ money(r.net) }}</td>
                      <td v-for="c in perfCols[tp]" :key="c.key" class="num" style="color:#64748b">{{ perfAmt(r, c.key) }}</td>
                      <td><button class="btn sm" @click.stop="openAdjust(r)">微调</button></td>
                      <td class="remark-cell">{{ r.remark || '' }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="typeFiltered[tp].length">
                  <tr style="background:#f0fdf4;font-weight:bold">
                    <td colspan="11" style="text-align:right">合计（{{ typeFiltered[tp].length }}人）：</td>
                    <td class="num">{{ money(typeTot[tp].sums.base_pay) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.perf_pay) }}</td><td></td>
                    <td class="num">{{ money(typeTot[tp].sums.sick_pay) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.night) }}</td><td class="num">{{ money(typeTot[tp].sums.meal) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.title_sub) }}</td><td class="num">{{ money(typeTot[tp].sums.reward) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.welfare) }}</td><td class="num">{{ money(typeTot[tp].sums.punish) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.late_d) }}</td><td class="num">{{ money(typeTot[tp].sums.miss_d) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.other_d) }}</td><td class="num">{{ money(typeTot[tp].sums.uniform_d) }}</td>
                    <td class="num" style="font-weight:bold">{{ money(typeTot[tp].sums.gross) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.soc_total) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.spec_total) }}</td>
                    <td class="num">{{ money(typeTot[tp].sums.actual_tax) }}</td>
                    <td class="num" style="color:#16a34a;font-weight:bold">{{ money(typeTot[tp].sums.net) }}</td>
                    <td v-for="c in perfCols[tp]" :key="'t' + c.key"></td>
                    <td></td><td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div class="hint">{{ TP_META[tp].tableHint }}</div>
          </template>
          <div v-else-if="!typeErr[tp]">
            <div v-html="attStatusHtml"></div>
            <div class="msg info">{{ TP_META[tp].empty }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- 微调弹窗（复刻 openAdjust） -->
    <div v-if="adjRow" class="modal-mask" @mousedown.self="closeAdjust">
      <div class="modal" v-disable-wheel style="width:780px;position:relative">
        <div @click="closeAdjust" title="关闭" style="position:absolute;top:10px;right:14px;width:30px;height:30px;line-height:30px;text-align:center;border-radius:50%;background:#f1f3f6;color:#6b7280;font-size:16px;font-weight:600;cursor:pointer;z-index:20;box-shadow:0 0 0 4px #fff">×</div>
        <h3>薪资微调 — {{ adjRow.name }}（{{ adjRow.project }}）</h3>
        <div class="msg info">修改后系统自动重算应发合计、个税累计预扣与实发工资；所有修改留存日志。</div>
        <div class="form-grid">
          <label>应出勤(天)<input type="number" step="0.01" v-model="adjValues.req_att" /></label>
          <label>实际出勤(天)<input type="number" step="0.01" v-model="adjValues.act_att" /></label>
          <label>绩效计薪出勤(天)<input type="number" step="0.01" v-model="adjValues.perf_att" /></label>
          <label>绩效系数<input type="number" step="0.01" v-model="adjValues.coef" /></label>
          <template v-for="g in ADJ_TAIL_GROUPS" :key="g[0]">
            <label v-for="f in g" :key="f">{{ FIELD_CN[f] }}<input type="number" step="0.01" v-model="adjValues[f]" /></label>
          </template>
        </div>
        <div class="form-grid" style="margin-top:10px">
          <label class="full">备注<input type="text" v-model="adjRemark" /></label>
          <label class="full">修改原因（记入日志）<input type="text" v-model="adjReason" placeholder="必填" /></label>
        </div>
        <div class="row end" style="margin-top:14px">
          <button class="btn" @click="closeAdjust">取消</button>
          <button class="btn primary" @click="saveAdjust">保存微调</button>
        </div>
      </div>
    </div>

    <!-- 季度/半年度绩效系数录入弹窗 -->
    <div v-if="coefDialog.show" class="modal-mask" @mousedown.self="closeCoefDialog">
      <div class="modal" style="width:860px;position:relative">
        <div @click="closeCoefDialog" title="关闭" style="position:absolute;top:10px;right:14px;width:30px;height:30px;line-height:30px;text-align:center;border-radius:50%;background:#f1f3f6;color:#6b7280;font-size:16px;font-weight:600;cursor:pointer;z-index:20;box-shadow:0 0 0 4px #fff">×</div>
        <h3>绩效系数录入 — {{ ui.month }}</h3>
        <div class="msg info">
          本季度周期：<b>{{ coefDialog.period }}</b>
          <template v-if="coefDialog.half_period">；半年度周期：<b>{{ coefDialog.half_period }}</b></template>
          。请为以下管理/总部人员录入系数（未录入者该周期绩效按 0 计）。
        </div>
        <div v-if="coefDialog.loading" class="msg info">加载中…</div>
        <div v-else-if="coefDialog.err" class="msg err">{{ coefDialog.err }}</div>
        <template v-else>
          <div v-if="coefDialog.items.length" class="table-wrap" style="max-height:420px">
            <table class="tb">
              <thead>
                <tr>
                  <th>姓名</th><th>项目</th><th>职位</th><th>薪酬档位</th><th>类型</th>
                  <th>季度系数</th><th v-if="coefDialog.half_period">半年度系数</th><th>状态</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="it in coefDialog.items" :key="it.staff_legacy_id">
                  <td>{{ it.name }}</td><td>{{ it.project }}</td><td>{{ it.position || '' }}</td>
                  <td>
                    <span v-if="it.pay_grade">{{ it.pay_grade }}</span>
                    <span v-else class="tag orange">⚠ 未同步</span>
                  </td>
                  <td>{{ it.person_type === 'manager' ? '管理' : '总部' }}</td>
                  <td><input type="number" step="0.01" v-model="it.coef" style="width:90px" /></td>
                  <td v-if="coefDialog.half_period"><input type="number" step="0.01" v-model="it.half_coef" style="width:90px" /></td>
                  <td>
                    <span :class="`tag ${it.coef !== null && it.coef !== '' ? 'green' : 'orange'}`">
                      {{ it.coef !== null && it.coef !== '' ? '已录入' : '未录入' }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="msg info">本季度暂无需录入系数的管理/总部人员。</div>
        </template>
        <div class="row end" style="margin-top:14px">
          <button class="btn" @click="closeCoefDialog">取消</button>
          <button class="btn primary" :disabled="coefDialog.saving || !coefDialog.items.length" @click="saveCoef">
            {{ coefDialog.saving ? '保存中…' : '保存系数' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { money } from '@/utils/format'
import { toast } from '@/utils/toast'
import { initStickyCols } from '@/utils/dom'
import { vDisableWheel } from '@/directives/disableWheel'
import { ADJUST_GROUPS, FIELD_CN, computeAdjustChanges, payTotalRow, payDeptOptions, filterPayRows, isQuarterEndMonth, coefEntryLabel, perfDetailCols, perfDetailCell, isZeroPayRow, groupNotices, archiveBlockerText } from './payrollLogic'

const auth = useAuthStore()
const ui = useUiStore()

const isAdmin = computed(() => auth.user && auth.user.role === 'admin')
const STATUS_TAG = { 正式: 'green', 新聘: 'blue', 转正: 'purple', 试用: 'orange', 离职: 'gray' }
const PAY_HEADS = ['项目', '部门', '职位', '姓名', '员工状态', '固定月薪', '基本工资', '应出勤', '出勤', '绩效计薪', '系数',
  '基本工资(折算)', '绩效工资', '病假天数', '病假工资', '夜班/话费', '餐补', '其他补贴', '奖励', '福利',
  '扣罚', '迟早扣', '缺卡扣', '其他扣', '工装扣', '应发合计', '社保合计', '附加扣除', '本月个税', '实发工资', '操作']
// 管理/案场/总部表：固定数据列（不含末列「操作」，操作列在模板中固定渲染于季度明细列之后，避免列错位）
const payHeadsData = PAY_HEADS.slice(0, -1)
// 微调弹窗字段组（出勤四项之后），顺序复刻 openAdjust
const ADJ_TAIL_GROUPS = ADJUST_GROUPS.slice(1)
const TP_META = {
  mgr: {
    title: '管理人员核算（仅总部 · 所有项目管理人员汇总）', calcBtn: '管理人员核算', exportBtn: '导出管理人员工资表（全部项目）', noun: '管理人员',
    hint: '管理人员（人员档案中勾选"是否管理人员"）不参与项目工资表核算，由这里按月份汇总所有项目管理人员单独核算生成管理人员工资表；仅总部可查看/导出/锁定，项目账号不可见。',
    tableHint: '管理人员工资表仅总部可见；项目账号无法查看/导出。归档锁定后禁止修改、重算，仅超管可解锁。微调操作与项目工资表一致。',
    empty: '该月暂无管理人员核算数据。点击"管理人员核算"生成。',
    export: () => download(`/api/export/managers?ym=${ui.month}`, `管理人员工资表_${ui.month}.xlsx`),
    calcApi: '/api/payroll/calc-managers', listApi: '/api/payroll?ym=&type=manager',
  },
  case: {
    title: '案场人员核算（仅总部 · 所有项目案场人员汇总）', calcBtn: '案场人员核算', exportBtn: '导出案场人员工资表（全部项目）', noun: '案场人员',
    hint: '案场人员（人员档案中勾选"是否案场人员"）不参与项目工资表核算，由这里按月份汇总所有项目案场人员单独核算生成案场人员工资表；各项目可查看/导出本项目案场人员。',
    tableHint: '案场人员工资表各项目可查看/导出本项目数据；归档锁定后禁止修改、重算，仅超管可解锁。微调操作与项目工资表一致。',
    empty: '该月暂无案场人员核算数据。点击"案场人员核算"生成。',
    export: () => download(`/api/export/case-staff?ym=${ui.month}`, `案场人员工资表_${ui.month}.xlsx`),
    calcApi: '/api/payroll/calc-case', listApi: '/api/payroll?ym=&type=case',
  },
  hq: {
    title: '总部人员核算（仅总部 · 物业总部所有人员汇总）', calcBtn: '总部人员核算', exportBtn: '导出总部人员工资表', noun: '总部人员',
    hint: '物业总部所有人员（不区分是否管理人员/案场人员标记）不参与项目工资表与管理/案场人员核算，由这里单独核算生成总部人员工资表；总部考勤表随项目考勤单独上传（项目=物业总部）。仅总部可查看/导出/锁定。',
    tableHint: '总部人员工资表仅总部可见；项目账号无法查看/导出。归档锁定后禁止修改、重算，仅超管可解锁。微调操作与项目工资表一致。',
    empty: '该月暂无总部人员核算数据。请先上传物业总部考勤表，再点击"总部人员核算"生成。',
    export: () => download(`/api/export/hq-staff?ym=${ui.month}`, `总部人员工资表_${ui.month}.xlsx`),
    calcApi: '/api/payroll/calc-hq', listApi: '/api/payroll?ym=&type=hq',
  },
}

const payTab = ref('emp')
const calcProjects = ref(auth.projects.slice())
const calcMsg = ref('')
const histFile = ref(null)
const empRows = ref([])
const empMeta = ref({})
const empErr = ref('')
const empArchived = ref(false)
const payProjFilter = ref('')
const payDeptFilter = ref('')
const paySel = ref('')
const typeRows = reactive({ mgr: [], case: [], hq: [] })
const typeArchived = reactive({ mgr: false, case: false, hq: false })
const typeErr = reactive({ mgr: '', case: '', hq: '' })
const typeMsg = reactive({ mgr: '', case: '', hq: '' })
const typeProjFilter = reactive({ mgr: '', case: '', hq: '' })
const typeSel = reactive({ mgr: '', case: '', hq: '' })
const typeTableEls = {}
const payTable = ref(null)
const attStatus = ref({ projects: [] })

// 微调弹窗
const adjRow = ref(null)
const adjValues = reactive({})
const adjRemark = ref('')
const adjReason = ref('')

// 季度/半年度系数录入弹窗
const coefDialog = reactive({ show: false, loading: false, saving: false, err: '', ym: '', period: '', half_period: null, items: [] })

const projChecks = computed(() => auth.projects.filter((p) => p !== '物业总部'))

const empFiltered = computed(() => filterPayRows(empRows.value, payProjFilter.value, payDeptFilter.value))
const payDepts = computed(() => payDeptOptions(empRows.value, payProjFilter.value))
const tot = computed(() => payTotalRow(empFiltered.value))
const typeFiltered = computed(() => ({
  mgr: typeRows.mgr.filter((r) => !typeProjFilter.mgr || r.project === typeProjFilter.mgr),
  case: typeRows.case.filter((r) => !typeProjFilter.case || r.project === typeProjFilter.case),
  hq: typeRows.hq,
}))
const typeTot = computed(() => ({
  mgr: payTotalRow(typeFiltered.value.mgr),
  case: payTotalRow(typeFiltered.value.case),
  hq: payTotalRow(typeFiltered.value.hq),
}))

// 季度绩效逐月明细：横向列（季度末月且有 perf_detail 的行才有），列头/键与 Excel 导出一致
const perfCols = computed(() => ({
  mgr: perfDetailCols(typeFiltered.value.mgr),
  case: perfDetailCols(typeFiltered.value.case),
  hq: perfDetailCols(typeFiltered.value.hq),
}))
// 明细单元格：无该月明细显示空（与导出一致），有则显示两位小数金额
function perfAmt(r, key) {
  const v = perfDetailCell(r, key)
  return v === null ? '' : money(v)
}
const empLogs = computed(() => (empMeta.value.logs || []).slice(-50).reverse())

const attStatusHtml = computed(() => {
  let s = `<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;padding:5px 10px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0"><span style="font-weight:600;font-size:12px;color:#475569">考勤上传：</span>`
  for (const p of attStatus.value.projects || []) {
    const color = p.uploaded ? '#16a34a' : '#94a3b8'
    const bg = p.uploaded ? '#f0fdf4' : '#f1f5f9'
    const tip = p.uploaded ? `${p.count}人 ${p.uploaded_at} by ${p.uploaded_by}` : '未上传'
    s += `<span title="${tip}" style="color:${color};background:${bg};border:1px solid ${color}33;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:500">${p.project}：${p.uploaded ? '已上传' : '未上传'}</span>`
  }
  return s + `</div>`
})

// 归档按钮（复刻 archiveBtns / *ArchiveBtns 渲染规则）
const archiveHtml = computed(() =>
  empArchived.value
    ? `<span class="tag red">已归档</span> <button class="btn warn sm" data-act="unlock">解锁归档</button>`
    : empRows.value.length
      ? `<button class="btn success sm" data-act="lock">确认归档锁定</button>`
      : ''
)
const typeArchiveHtml = computed(() => {
  const out = {}
  for (const tp of ['mgr', 'case', 'hq']) {
    out[tp] = typeArchived[tp]
      ? `<span class="tag red">已归档锁定</span> <button class="btn warn sm" data-act="unlock">解锁归档</button>`
      : typeRows[tp].length
        ? `<button class="btn success sm" data-act="lock">确认归档锁定</button>`
        : ''
  }
  return out
})

async function loadAttStatus() {
  try {
    attStatus.value = await api(`/api/attendance/status?ym=${ui.month}`)
  } catch (e) { attStatus.value = { projects: [] } }
}

async function loadPayroll() {
  empErr.value = ''
  try {
    const [data, st] = await Promise.all([api(`/api/payroll?ym=${ui.month}`), api(`/api/attendance/status?ym=${ui.month}`)])
    attStatus.value = st
    empRows.value = data.rows || []
    empArchived.value = !!data.archived
    empMeta.value = data
  } catch (e) { empErr.value = e.message }
}

async function loadType(tp) {
  typeErr[tp] = ''
  try {
    const url = TP_META[tp].listApi.replace('ym=', `ym=${ui.month}`)
    const [data, st] = await Promise.all([api(url), api(`/api/attendance/status?ym=${ui.month}`)])
    attStatus.value = st
    typeRows[tp] = data.rows || []
    typeArchived[tp] = !!data.archived
    typeProjFilter[tp] = ''
    typeSel[tp] = ''
    nextTick(() => initStickyCols(typeTableEls[tp], 4))
  } catch (e) { typeErr[tp] = e.message }
}

function switchTab(t) {
  payTab.value = t
  if (t === 'emp') loadPayroll()
  else loadType(t)
}

function reloadActive() {
  if (payTab.value === 'emp') loadPayroll()
  else loadType(payTab.value)
}

watch(() => ui.month, reloadActive)
// 前四列吸附（复刻 initStickyCols）：数据/筛选/tab 变化后重新测量
watch([empFiltered, payTab], () => nextTick(() => initStickyCols(payTable.value, 4)))

function toggleCalcProj(p, on) {
  if (on && !calcProjects.value.includes(p)) calcProjects.value = [...calcProjects.value, p]
  if (!on) calcProjects.value = calcProjects.value.filter((x) => x !== p)
}

function escHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>')
}

async function doCalc() {
  if (!calcProjects.value.length) return toast('请至少选择一个项目', false)
  if (!confirm(`确认核算 ${ui.month}：${calcProjects.value.length} 个项目？同月重复核算将自动覆盖旧数据。`)) return
  try {
    const r = await api('/api/payroll/calc', { body: { ym: ui.month, projects: calcProjects.value } })
    const notices = groupNotices(r.missing || [], r.warnings || [])
    const dangerTxt = notices.danger.length
      ? `🚨 ${notices.danger.length} 项异常：` + notices.danger.map((w) => `${w.name}(${w.project})：${w.reason}`).join('；')
      : ''
    const infoTxt = notices.info.length
      ? `ℹ️ ${notices.info.length} 项提示：` + notices.info.map((w) => `${w.name}(${w.project})：${w.reason}`).join('；')
      : ''
    let msg = `核算完成，共 ${r.count} 人。`
    if (r.skipped && r.skipped.length) msg += '（' + r.skipped.join('、') + '）'
    msg += dangerTxt ? `\n${dangerTxt}` : ''
    msg += infoTxt ? `\n${infoTxt}` : ''
    calcMsg.value = `<div class="msg ${dangerTxt ? 'err' : infoTxt ? 'info' : 'ok'}">${escHtml(msg)}</div>`
    loadPayroll()
  } catch (e) { calcMsg.value = `<div class="msg err">${e.message}</div>` }
}

function downloadHistoryTemplate() {
  const y = ui.month.slice(0, 4)
  download(`/api/payroll/history-template?year=${y}`, `${y}年历史工资导入模板.xlsx`)
}

async function importHistory(e) {
  const f = e.target.files[0]
  if (!f) return
  const form = new FormData()
  form.append('file', f)
  try {
    const r = await api('/api/payroll/import-history', { form })
    let msg = `导入完成：处理 ${r.sheets} 个月份，新增 ${r.inserted} 行，更新 ${r.updated} 行`
    if (r.skipped_before_hire) msg += `，跳过入职前 ${r.skipped_before_hire} 行`
    if (r.errors && r.errors.length) {
      msg += '\n⚠ ' + r.errors.slice(0, 15).join('\n')
      if (r.errors.length > 15) msg += `\n...等共 ${r.errors.length} 条`
    }
    let msgHtml = msg.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>')
    if (r.error_file) {
      msgHtml += `<br><button class="btn" id="dlImpErrBtn" style="margin-top:8px">下载错误报告（${r.errors.length} 条明细）</button>`
    }
    calcMsg.value = `<div class="msg ${r.errors && r.errors.length ? 'info' : 'ok'}">${msgHtml}</div>`
    if (r.error_file) {
      // v-html 内无法绑定事件，渲染后手动挂下载按钮
      nextTick(() => {
        document.getElementById('dlImpErrBtn')?.addEventListener('click', () =>
          download(`/api/payroll/import-history/error-report/${r.error_file}`, '历史工资导入错误报告.xlsx'))
      })
    }
  } catch (err) { calcMsg.value = `<div class="msg err">${err.message}</div>` }
  finally { e.target.value = '' }
}

async function doCalcType(tp) {
  const label = TP_META[tp].calcBtn
  const scope = tp === 'mgr' ? '（所有项目管理人员）' : tp === 'case' ? '（所有项目案场人员）' : '（物业总部所有人员）'
  if (!confirm(`确认核算 ${ui.month} ${label}${scope}？同月重复核算将覆盖旧数据。`)) return
  try {
    const r = await api(TP_META[tp].calcApi, { body: { ym: ui.month } })
    const notices = groupNotices(r.missing || [], r.warnings || [])
    const dangerTxt = notices.danger.length
      ? `🚨 ${notices.danger.length} 项异常：` + notices.danger.map((w) => `${w.name}(${w.project})：${w.reason}`).join('；')
      : ''
    const infoTxt = notices.info.length
      ? `ℹ️ ${notices.info.length} 项提示：` + notices.info.map((w) => `${w.name}(${w.project})：${w.reason}`).join('；')
      : ''
    let msg = `${label}完成，共 ${r.count} 人。`
    if (r.skipped && r.skipped.length) msg += '（' + r.skipped.join('、') + '）'
    msg += dangerTxt ? `\n${dangerTxt}` : ''
    msg += infoTxt ? `\n${infoTxt}` : ''
    typeMsg[tp] = `<div class="msg ${dangerTxt ? 'err' : infoTxt ? 'info' : 'ok'}">${escHtml(msg)}</div>`
    loadType(tp)
    loadPayroll()
  } catch (e) { typeMsg[tp] = `<div class="msg err">${e.message}</div>` }
}

async function postArchive(body) {
  try {
    await api('/api/payroll/archive', { body })
    toast(body.locked ? '已归档锁定' : '已解锁')
  } catch (e) {
    if (e.status === 409 && e.payload && e.payload.need_confirm) {
      if (confirm(archiveBlockerText(e.payload.blockers || []))) {
        try {
          await api('/api/payroll/archive', { body: { ...body, force: true } })
          toast('已归档锁定（已确认忽略异常）')
        } catch (e2) { alert(e2.message); return }
      } else { return }
    } else { alert(e.message); return }
  }
  reloadActive()
}

async function setArchive(locked) {
  postArchive({ ym: ui.month, locked })
}

async function setArchiveType(tp, locked) {
  const scope = tp === 'mgr' ? '管理人员' : tp === 'case' ? '案场人员' : '总部人员'
  if (!confirm(locked ? `确认归档锁定${scope}工资表？锁定后禁止修改/重算，仅超管可解锁。` : '确认解锁归档？')) return
  postArchive({ ym: ui.month, locked, type: tp === 'mgr' ? 'manager' : tp })
}

// 归档按钮通过事件委托响应（v-html 内无法绑定 Vue 事件）
function onCardClick(e) {
  const act = e.target.closest && e.target.closest('[data-act]')
  if (!act) return
  const locked = act.dataset.act === 'lock'
  if (payTab.value === 'emp') setArchive(locked)
  else setArchiveType(payTab.value, locked)
}

function onPayProjChange() {
  if (!payDepts.value.includes(payDeptFilter.value)) payDeptFilter.value = ''
  paySel.value = ''
}

function exportProject() {
  const p = payProjFilter.value || auth.projects[0]
  download(`/api/export/project?ym=${ui.month}&project=${encodeURIComponent(p)}`, `${p}_${ui.month}工资表.xlsx`)
}
function exportType(tp) {
  TP_META[tp].export()
}

function openAdjust(r) {
  adjRow.value = r
  for (const g of ADJUST_GROUPS) for (const f of g) adjValues[f] = r[f] === null || r[f] === undefined ? '' : String(r[f])
  adjRemark.value = r.remark || ''
  adjReason.value = ''
}

function closeAdjust() {
  adjRow.value = null
}

async function saveAdjust() {
  const reason = adjReason.value.trim()
  if (!reason) return toast('请填写修改原因', false)
  const changes = computeAdjustChanges(adjRow.value, adjValues, adjRemark.value)
  if (!Object.keys(changes).length) { closeAdjust(); return toast('没有修改') }
  try {
    await api('/api/payroll/adjust', { body: { ym: ui.month, staff_id: adjRow.value.staff_id, fields: changes, reason } })
    closeAdjust()
    toast('微调已保存，个税已联动重算')
    loadPayroll()
    if (payTab.value !== 'emp') loadType(payTab.value)
  } catch (e) { alert(e.message) }
}

async function openCoefDialog() {
  coefDialog.show = true
  coefDialog.loading = true
  coefDialog.err = ''
  coefDialog.ym = ui.month
  coefDialog.items = []
  try {
    const r = await api(`/api/payroll/period-coef/pending?ym=${ui.month}`)
    if (!r.ok) { coefDialog.err = r.error || r.message || '当前月份不是季度末月，无需录入季度系数'; coefDialog.loading = false; return }
    coefDialog.period = r.period
    coefDialog.half_period = r.half_period || null
    coefDialog.items = (r.items || []).map((it) => ({
      ...it,
      coef: it.coef === null || it.coef === undefined ? '' : String(it.coef),
      half_coef: it.half_coef === null || it.half_coef === undefined ? '' : String(it.half_coef),
    }))
  } catch (e) { coefDialog.err = e.message } finally { coefDialog.loading = false }
}

function closeCoefDialog() {
  coefDialog.show = false
}

async function saveCoef() {
  const items = []
  for (const it of coefDialog.items) {
    if (it.coef !== '' && it.coef !== null) {
      const v = parseFloat(it.coef)
      if (!isNaN(v)) items.push({ staff_legacy_id: it.staff_legacy_id, coef: v })
    }
  }
  const halfItems = []
  if (coefDialog.half_period) {
    for (const it of coefDialog.items) {
      if (it.half_coef !== '' && it.half_coef !== null) {
        const v = parseFloat(it.half_coef)
        if (!isNaN(v)) halfItems.push({ staff_legacy_id: it.staff_legacy_id, coef: v })
      }
    }
  }
  if (!items.length && !halfItems.length) return toast('请至少填写一个系数', false)
  coefDialog.saving = true
  try {
    if (items.length) {
      await api('/api/payroll/period-coef/save', { body: { period_type: 'quarterly', period_key: coefDialog.period, items } })
    }
    if (halfItems.length && coefDialog.half_period) {
      await api('/api/payroll/period-coef/save', { body: { period_type: 'half_year', period_key: coefDialog.half_period, items: halfItems } })
    }
    toast('系数已保存')
    closeCoefDialog()
  } catch (e) { alert(e.message) } finally { coefDialog.saving = false }
}

onMounted(() => {
  document.addEventListener('click', onCardClick)
  calcProjects.value = auth.projects.slice()
  loadPayroll()
})
onBeforeUnmount(() => document.removeEventListener('click', onCardClick))
</script>

<style scoped>
tr.pay-sel td {
  background: #fff6d6 !important;
  color: #1f2937;
}
tr.row-zero-pay td {
  background: #fff7ed;
}
td.zero-pay {
  font-weight: bold;
  color: #c2410c !important;
}
td.pay-pos {
  font-weight: bold;
  color: #16a34a;
}
.remark-cell {
  max-width: 180px;
  white-space: normal;
  word-break: break-all;
  color: #64748b;
  font-size: 12px;
}
</style>
