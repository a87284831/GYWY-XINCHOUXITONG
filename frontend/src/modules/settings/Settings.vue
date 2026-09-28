<template>
  <div class="card">
    <h3>系统设置</h3>
    <div class="settings-wrap">
      <div class="settings-side">
        <div v-for="t in tabs" :key="t.key" class="settings-tab" :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</div>
      </div>
      <div class="settings-content">
        <TabPerm v-if="tab === 'perm'" />
        <TabApprovalFlow v-else-if="tab === 'approvalFlow'" />
        <SalarySettings v-else-if="tab === 'salarySettings'" />
        <TabCompany v-else-if="tab === 'company'" />
        <TabSecurity v-else-if="tab === 'security'" />
        <TabBackup v-else-if="tab === 'backup'" />
        <TabLogs v-else-if="tab === 'logs'" />
        <div v-else class="hint" style="padding:24px;text-align:center">加载中...</div>
      </div>
    </div>
  </div>
</template>

<script setup>
// 系统设置中心 — 复刻 pageSettings/renderSettingsTabs/settingsGo（app.js:3791-3820）
// props.tab 为初始值（/settings 与 /users→'perm'、/logs→'logs'、/backup→'backup'）；tab 切换仅组件内状态，不同步 URL
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { SETTINGS_TABS, visibleTabs } from './settingsLogic'
import TabPerm from './TabPerm.vue'
import TabApprovalFlow from './TabApprovalFlow.vue'
import SalarySettings from './SalarySettings.vue'
import TabCompany from './TabCompany.vue'
import TabSecurity from './TabSecurity.vue'
import TabBackup from './TabBackup.vue'
import TabLogs from './TabLogs.vue'

const props = defineProps({ tab: { type: String, default: 'perm' } })
const auth = useAuthStore()
const tabs = visibleTabs(SETTINGS_TABS, (p) => auth.can(p))
const tab = ref(props.tab)
</script>
