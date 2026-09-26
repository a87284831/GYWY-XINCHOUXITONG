<template>
  <div id="loginPage">
    <div class="login-brand">
      <h1>昊轩云枢</h1>
      <div class="sub">本地部署 · 数据存储于本机<br>多项目薪资核算 · 数据可视化驾驶舱</div>
      <div class="copy" style="bottom:30px;left:8%;">
        <div style="display:inline-block;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.55);border-radius:12px;padding:10px 20px;font-size:15px;color:#fff;line-height:2;letter-spacing:.5px;box-shadow:0 4px 14px rgba(0,0,0,.18);">
          <div style="font-size:12px;color:#b8c8e8;letter-spacing:2px;margin-bottom:2px;">原创开发</div>
          <b style="font-size:16px;color:#fff;">原创开发人：李昊轩</b><br>
          <b style="font-size:15px;color:#e8f0ff;">技术协作人：谢印良</b>
        </div>
        <div style="margin-top:10px;font-size:12px;color:#b8c8e8;">© 2026 昊轩云枢 v3.0</div>
      </div>
    </div>
    <div class="login-side">
      <div class="login-card">
        <div class="welcome">欢迎登录</div>
        <div class="welcome-sub">请输入您的账号信息</div>
        <div class="input-wrap"><span class="ico">👤</span><input v-model="username" type="text" placeholder="用户名" autocomplete="off"></div>
        <div class="input-wrap"><span class="ico">🔒</span><input v-model="password" type="password" placeholder="密码" @keydown.enter="doLogin"></div>
        <button @click="doLogin">登 录</button>
        <div class="login-tip" :style="{ color: tipColor }">{{ tip }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const username = ref('')
const password = ref('')
const tip = ref('首次使用：admin / admin123')
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
