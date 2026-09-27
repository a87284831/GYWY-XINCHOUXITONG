<script setup>
// 填报明细表（按条线视图与项目抽屉共用；复刻旧 AdminOverview 中两份相同列定义）
import { monthShort, statusMeta } from './adminLogic'

defineProps({
  items: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  archived: { type: Boolean, default: false },
})
const emit = defineEmits(['confirm', 'unconfirm', 'edit', 'return-item', 'remove'])
</script>

<template>
  <el-table v-loading="loading" :data="items" border stripe size="small"
    :header-cell-style="{ background:'#eef4fb', color:'#2b5a9e', fontWeight:600 }">
    <el-table-column type="index" label="#" width="46" align="center" />
    <el-table-column prop="project_name" label="项目" min-width="130">
      <template #default="{ row }"><b style="color:#2b5a9e">{{ row.project_name }}</b></template>
    </el-table-column>
    <el-table-column prop="item_name" label="商品名称" min-width="120" />
    <el-table-column prop="spec" label="规格" min-width="100" />
    <el-table-column prop="brand" label="品牌" width="90" />
    <el-table-column prop="unit" label="单位" width="56" align="center" />
    <el-table-column prop="quantity" label="数量" width="66" align="right" />
    <el-table-column prop="stock" label="库存" width="66" align="right" />
    <el-table-column prop="reason" label="申购原因" min-width="110" show-overflow-tooltip />
    <el-table-column prop="use_location" label="使用位置" width="90" show-overflow-tooltip />
    <el-table-column label="清单外" width="66" align="center">
      <template #default="{ row }">
        <el-tag v-if="row.is_custom == 1" size="small" type="warning">清单外</el-tag>
      </template>
    </el-table-column>
    <el-table-column label="状态" width="92" align="center">
      <template #default="{ row }">
        <el-tag v-if="row.status === 'returned'" size="small" type="danger" effect="dark">
          已退回
          <el-tooltip v-if="row.return_reason" :content="row.return_reason" placement="top">
            <span style="margin-left:3px;cursor:help;text-decoration:underline dotted;">?</span>
          </el-tooltip>
        </el-tag>
        <el-tag v-else size="small" :type="statusMeta(row.status).type">{{ statusMeta(row.status).text }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column label="重新提交时间" width="120" align="center">
      <template #default="{ row }">
        <span v-if="row.resubmitted_at" style="color:#e6a23c;font-size:12px">{{ monthShort(row.resubmitted_at) }}</span>
        <span v-else style="color:#ccc">—</span>
      </template>
    </el-table-column>
    <el-table-column label="操作" width="230" align="center" fixed="right">
      <template #default="{ row }">
        <el-button v-if="row.status === 'submitted' && !archived" size="small" link type="success"
          @click="emit('confirm', row)">确认</el-button>
        <el-button v-if="row.status === 'returned'" size="small" link type="info" disabled>待员工重新提交</el-button>
        <el-button v-else-if="row.status === 'confirmed'" size="small" link type="warning"
          @click="emit('unconfirm', row)">取消确认</el-button>
        <el-button v-if="row.status !== 'confirmed' && !archived" size="small" link type="primary"
          @click="emit('edit', row)">微调</el-button>
        <el-button v-if="(row.status === 'submitted' || row.status === 'confirmed') && !archived"
          size="small" link type="danger" @click="emit('return-item', row)">退回</el-button>
        <el-button v-if="row.status !== 'confirmed' && !archived" size="small" link type="danger"
          @click="emit('remove', row)">删除</el-button>
      </template>
    </el-table-column>
  </el-table>
</template>
