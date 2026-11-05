// 审批中心纯逻辑测试（复刻 pageApprovalCenter 群，app.js:5756-6650 + 审批流树操作 6940-7165）
import { describe, it, expect } from 'vitest'
import {
  flowNameOf, sortFlows, deptNameList, perfText, collectForm,
  stepList, approvalRecords, sendGroups, buildQuery,
  findTreeItem, insertAfter, removeItemById, findNodeRefId, findBranchById,
  collectNodeIds, legacyToTree,
} from './approvalLogic'

describe('flowNameOf', () => {
  it('内置流程名映射，未知 key 原样返回', () => {
    expect(flowNameOf('hire_approval')).toBe('录用审批')
    expect(flowNameOf('regular_approval')).toBe('转正审批')
    expect(flowNameOf('resign_approval')).toBe('离职审批')
    expect(flowNameOf('custom_x')).toBe('custom_x')
  })
})

describe('sortFlows', () => {
  it('内置顺序优先（未知 key indexOf=-1 排最前，忠实旧版），其次按 id', () => {
    const flows = [
      { flow_key: 'resign_approval', id: 3 },
      { flow_key: 'other', id: 9 },
      { flow_key: 'hire_approval', id: 2 },
      { flow_key: 'hire_approval', id: 1 },
      { flow_key: 'regular_approval', id: 5 },
    ]
    expect(sortFlows(flows).map((f) => f.flow_key + f.id)).toEqual([
      'other9', 'hire_approval1', 'hire_approval2', 'regular_approval5', 'resign_approval3',
    ])
  })
})

describe('deptNameList', () => {
  const leaves = [
    { id: 11, name: '一部', path: 'A项目/物业处/一部' },
    { id: 12, name: '二部', path: 'A项目/物业处/二部' },
    { id: 13, name: '一部', path: 'B项目/工程处/一部' }, // 跨项目同名需去重过滤
    { id: 14, name: '保洁组', path: 'C项目/保洁组' },
  ]
  const projNameOf = (n) => (n.id === 11 || n.id === 12 ? 'A项目' : n.id === 13 ? 'B项目' : 'C项目')
  it('proj 为空返回空数组（必须先选项目）', () => {
    expect(deptNameList('', leaves, projNameOf)).toEqual([])
  })
  it('取所选项目下末级部门名并去重', () => {
    expect(deptNameList('A项目', leaves, projNameOf)).toEqual(['一部', '二部'])
    expect(deptNameList('B项目', leaves, projNameOf)).toEqual(['一部'])
  })
})

describe('perfText', () => {
  it('核定月薪−基本工资，>0 显示整数元，否则 —', () => {
    expect(perfText('6000', '3000')).toBe('3000 元')
    expect(perfText(5000.5, 3000)).toBe('2001 元')
    expect(perfText(3000, 3000)).toBe('—')
    expect(perfText('', '')).toBe('—')
  })
})

describe('collectForm', () => {
  const schema = [
    { key: 'name', label: '姓名', required: true, type: 'text' },
    { key: 'gender', label: '性别', type: 'select' },
    { key: 'skills', label: '技能', type: 'multi', required: true },
    { key: 'person', label: '人员', type: 'person' },
    { key: 'attach', label: '附件', type: 'attachment' },
    { key: 'perf', label: '绩效', type: 'perf' },
  ]
  it('收集各类型值；person 附带 _id；perf 跳过', () => {
    const values = {
      name: '张三', gender: '男', skills: ['a', 'b'],
      person: '张三', person_id: '7', attach: '/f/x.pdf', perf: 'ignored',
    }
    const { form, missing } = collectForm(schema, values)
    expect(form).toEqual({
      name: '张三', gender: '男', skills: ['a', 'b'], person: '张三', person_id: '7', attach: '/f/x.pdf',
    })
    expect(missing).toEqual([])
  })
  it('必填缺失收集 label', () => {
    const { missing } = collectForm(schema, {})
    expect(missing).toEqual(['姓名', '技能'])
  })
  it('multi 空数组视为缺失；skipRequired 跳过校验', () => {
    const values = { name: 'x', skills: [] }
    expect(collectForm(schema, values).missing).toEqual(['技能'])
    expect(collectForm(schema, values, true).missing).toEqual([])
  })
})

describe('stepList / approvalRecords', () => {
  const chain = [
    { name: '项目经理', approvers: [{ name: '李四', state: 'approved' }] },
    { name: '人事', approvers: [{ name: '王五', state: 'pending' }, { name: '赵六', state: 'pending' }] },
    { name: '总经理', approvers: [] },
  ]
  it('步骤条状态：done=已过或已通过, cur=当前且 pending', () => {
    const steps = stepList(chain, 1, 'pending')
    expect(steps.map((s) => [s.done, s.isCur])).toEqual([[true, false], [false, true], [false, false]])
    expect(stepList(chain, 0, 'approved').every((s) => s.done)).toBe(true)
    expect(stepList(chain, 0, 'pending')[0].who).toBe('李四')
  })
  it('审批记录按节点×审批人展开，取同人意见', () => {
    const recs = approvalRecords(chain, { 1: [{ name: '王五', opinion: '同意', time: '2026-01-02T10:00' }] })
    expect(recs).toHaveLength(3)
    expect(recs[1].op.opinion).toBe('同意')
    expect(recs[2].op).toBeUndefined()
  })
})

describe('sendGroups', () => {
  it('按项目分组并按组名排序，空项目归「未分组」', () => {
    const g = sendGroups([
      { id: 2, project: 'B', name: '乙' },
      { id: 1, project: '', name: '丙' },
      { id: 3, project: 'A', name: '甲' },
    ])
    expect(g.map((x) => x.project)).toEqual(['A', 'B', '未分组'])
    expect(g[2].items[0].id).toBe(1)
  })
})

describe('buildQuery', () => {
  it('仅拼非空条件', () => {
    expect(buildQuery({ flow_no: 'LS1', keyword: '', flow_key: 'hire_approval', status: 'pending', date_from: '', date_to: '2026-01-01' }))
      .toBe('flow_no=LS1&flow_key=hire_approval&status=pending&date_to=2026-01-01')
    expect(buildQuery({})).toBe('')
  })
})

describe('审批流树操作（钉钉风格树）', () => {
  const mkTree = () => ({
    type: 'chain',
    items: [
      { id: 't1', type: 'approve', nodeId: 'n1' },
      { id: 't2', type: 'branch', cond: { field: 'f1', op: 'eq', value: 'x' }, paths: [
        { id: 'p1', label: '命中', default: false, items: [{ id: 't3', type: 'approve', nodeId: 'n2' }] },
        { id: 'p2', label: '默认', default: true, items: [] },
      ] },
      { id: 't4', type: 'approve', nodeId: 'n3' },
    ],
  })
  it('findTreeItem 定位顶层与分支链内条目', () => {
    expect(findTreeItem(mkTree(), 't1').index).toBe(0)
    expect(findTreeItem(mkTree(), 't3').chain[0].id).toBe('t3')
    expect(findTreeItem(mkTree(), 'nope')).toBeNull()
  })
  it('insertAfter 顶层与分支插入；找不到追加末尾', () => {
    const tree = mkTree()
    insertAfter(tree, 't1', { id: 't9', type: 'approve', nodeId: 'n9' })
    expect(tree.items[1].id).toBe('t9')
    insertAfter(tree, 'p1', { id: 't10', type: 'approve', nodeId: 'n10' })
    expect(findBranchById(tree, 't2').paths[0].items[1].id).toBe('t10')
    insertAfter(tree, 'ghost', { id: 't11', type: 'approve', nodeId: 'n11' })
    expect(tree.items[tree.items.length - 1].id).toBe('t11')
  })
  it('removeItemById 递归删除并返回是否命中', () => {
    const tree = mkTree()
    expect(removeItemById(tree, 't3')).toBe(true)
    expect(findBranchById(tree, 't2').paths[0].items).toHaveLength(0)
    expect(removeItemById(tree, 'ghost')).toBe(false)
  })
  it('findNodeRefId / collectNodeIds', () => {
    const tree = mkTree()
    expect(findNodeRefId(tree, 'n2')).toBe('t3')
    expect(findNodeRefId(tree, 'ghost')).toBeNull()
    const used = new Set()
    collectNodeIds(tree, used)
    expect([...used].sort()).toEqual(['n1', 'n2', 'n3'])
  })
  it('findBranchById 支持嵌套分支', () => {
    const tree = {
      items: [{ id: 'tb', type: 'branch', paths: [{ id: 'px', items: [{ id: 'tb2', type: 'branch', paths: [] }] }] }],
    }
    expect(findBranchById(tree, 'tb2').id).toBe('tb2')
  })
  it('legacyToTree 把旧 default/branches 配置转树，序号连续递增', () => {
    const r = legacyToTree(
      { default: ['n1', 'n2'], branches: [{ field: 'f1', op: 'eq', value: 'v', nodes: ['n3'] }] },
      ['n1', 'n2', 'n3'],
      1000,
    )
    const items = r.tree.items
    expect(items[0]).toEqual({ id: 't1001', type: 'approve', nodeId: 'n1' })
    expect(items[1].id).toBe('t1002')
    expect(items[2].type).toBe('branch')
    expect(items[2].cond).toEqual({ field: 'f1', op: 'eq', value: 'v' })
    expect(items[2].paths[0].cond).toEqual({ field: 'f1', op: 'eq', value: 'v' })
    expect(items[2].paths[0].items).toEqual([{ id: 't1004', type: 'approve', nodeId: 'n3' }])
    expect(items[2].paths[1]).toEqual({ id: 'p1005', label: '默认', default: true, items: [] })
    expect(r.seq).toBe(1006) // 分支条目自身在 paths 构建后再消耗一个序号（旧版求值顺序）
  })
  it('legacyToTree 无 default 时回退全部节点 id', () => {
    const r = legacyToTree({}, ['n1'], 0)
    expect(r.tree.items[0].nodeId).toBe('n1')
    expect(r.tree.items).toHaveLength(1)
  })
})
