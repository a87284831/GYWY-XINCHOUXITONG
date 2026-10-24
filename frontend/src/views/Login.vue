<template>
  <div id="loginPage">
    <div class="glow"></div>
    <div class="grid-bg"></div>
    <div class="lb-top"><div class="logo-mark">昊</div><b>昊轩云枢</b></div>
    <div class="lb-mid">
      <h1>一个平台<br><em>管好人与钱</em></h1>
      <p class="sub">薪酬核算 · 采购审批 · 财务台账 · 维保管理<br>组织架构与考勤数据实时同步</p>
    </div>
    <div class="login-side">
      <div class="login-card">
        <div class="welcome">欢迎登录</div>
        <div class="welcome-sub">请使用管理员或员工账号进入</div>
        <div class="fld-label">账号</div>
        <div class="input-wrap"><span class="ico"><Icon name="user" :size="15" /></span><input v-model="username" type="text" placeholder="请输入用户名" autocomplete="off"></div>
        <div class="fld-label">密码</div>
        <div class="input-wrap"><span class="ico"><Icon name="lock" :size="15" /></span><input v-model="password" type="password" placeholder="请输入密码" @keydown.enter="doLogin"></div>
        <button @click="doLogin">登 录</button>
        <div class="login-tip" :style="{ color: tipColor }">{{ tip }}</div>
      </div>
    </div>
    <div class="lb-foot">HAOXUAN CLOUD PLATFORM · 2026<br>原创开发人：李昊轩</div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import Icon from '@/components/Icon.vue'

const router = useRouter()
const auth = useAuthStore()

const username = ref('')
const password = ref('')
const tip = ref('遇到登录问题请联系系统管理员')
const tipColor = ref('')

async function doLogin() {
  const u = username.value.trim()
  const p = password.value
  if (!u || !p) {
    tip.value = '请输入用户名和密码'
    tipColor.value = '#dc2626'
    return
  }
  tip.value = '正在登录...'
  tipColor.value = '#8c8c8c'
  try {
    await auth.login(u, p)
    // 复刻 bootstrap()：拉取用户/权限后进入工作台
    const data = await api('/api/init')
    auth.hydrate(data)
    router.push('/')
  } catch (e) {
    tip.value = e.message
    tipColor.value = '#dc2626'
  }
}
</script>
