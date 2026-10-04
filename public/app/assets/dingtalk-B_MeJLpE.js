import{e as c}from"./index-CIaIsauv.js";function p(r){const t=r||{},i=Array.isArray(t.roster_field_error_items)?t.roster_field_error_items:[],e=Number(t.roster_field_errors??i.length)||0,o=Number(t.roster_missing??0)||0,d=[["钉钉部门数",t.dingtalk_depts??0],["钉钉在职人员",t.dingtalk_users??0],["钉钉离职人员",t.dingtalk_dismissed??0],["新增人员",t.new??0],["更新人员",t.updated??0],["标记离职",t.offboard??0],["新增离职",t.offboard_new??0],["花名册同步",t.roster??0],["花名册字段异常",e],["花名册缺失",o]],s=i.slice(0,10),a=i.length>s.length?i.length-s.length:0;return{hasWarning:e>0||o>0,title:e>0?`同步完成（${e} 项花名册字段异常，已跳过保留原值）`:"同步完成",rows:d,warningLines:s.map(n=>`· ${n.name}：${n.detail}`),warningTruncated:a,missingCount:o}}async function y(r){if(!window.confirm("立即从钉钉全量同步组织架构和人员？"))return;const t=document.createElement("div");if(t.id="syncOverlay",t.style.cssText="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center",t.innerHTML=`
    <div style="background:#fff;border-radius:14px;padding:32px 40px;min-width:340px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:16px;font-weight:600;color:#333;margin-bottom:18px">钉钉数据同步中</div>
      <div class="sync-spinner" style="width:44px;height:44px;border:4px solid #e8e8e8;border-top-color:#1890ff;border-radius:50%;animation:syncSpin .8s linear infinite;margin:0 auto 18px"></div>
      <div class="sync-stage" style="font-size:14px;color:#888;margin-bottom:6px">正在连接钉钉...</div>
      <div class="sync-timer" style="font-size:13px;color:#bbb">已用时 0s</div>
    </div>
  `,document.body.appendChild(t),!document.getElementById("syncSpinStyle")){const n=document.createElement("style");n.id="syncSpinStyle",n.textContent="@keyframes syncSpin{to{transform:rotate(360deg)}}",document.head.appendChild(n)}const i=["正在连接钉钉...","正在拉取部门树...","正在同步组织架构...","正在拉取在职人员...","正在同步人员数据...","正在拉取离职名单...","正在同步花名册...","即将完成..."];let e=0;const o=()=>t.querySelector(".sync-stage"),d=()=>t.querySelector(".sync-timer"),s=Date.now(),a=setInterval(()=>{o()&&(o().textContent=i[e%i.length]),e++,d()&&(d().textContent="已用时 "+Math.round((Date.now()-s)/1e3)+"s")},4e3);try{const n=await c("/api/dingtalk/sync-now",{method:"POST"});clearInterval(a),t.remove();const l=n.report||{};x(l,n.elapsed||l.elapsed||0,r)}catch(n){clearInterval(a),t.remove(),g(n.message)}}function x(r,t,i){const e=p(r),o=document.createElement("div");o.style.cssText="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center";const d=e.hasWarning?"#d97706":"#52c41a",s=e.warningLines.length?`
    <div style="margin-top:16px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;color:#92400e;max-height:200px;overflow-y:auto">
      <div style="font-weight:600;margin-bottom:6px">以下人员的花名册薪资字段不是有效数字，本次未覆盖其本地薪资（保留原值），其余字段和其他人员均已正常同步。请在钉钉智能人事修正后重新同步：</div>
      ${e.warningLines.map(n=>`<div style="padding:2px 0;word-break:break-all">${n}</div>`).join("")}
      ${e.warningTruncated?`<div style="padding-top:4px;color:#b45309">……等 ${e.warningTruncated} 项未显示</div>`:""}
    </div>`:"",a=e.missingCount?`
    <div style="margin-top:10px;font-size:12px;color:#b45309">另有 ${e.missingCount} 名在职人员在钉钉花名册无档案返回（多半未办理智能人事入职登记），花名册字段未同步。</div>`:"";o.innerHTML=`
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:380px;max-width:560px;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="text-align:center;margin-bottom:20px">
        <div style="font-size:20px;font-weight:700;color:${d}">${e.title}</div>
        <div style="font-size:13px;color:#999;margin-top:4px">耗时 ${t} 秒</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        ${e.rows.map(([n,l])=>`<tr><td style="padding:6px 0;color:#666">${n}</td><td style="padding:6px 0;text-align:right;font-weight:600;color:${n.includes("异常")&&l>0?"#d97706":"#333"}">${l}</td></tr>`).join("")}
      </table>
      ${s}
      ${a}
      <div style="text-align:center;margin-top:22px">
        <button class="sync-ok-btn" style="padding:8px 36px;font-size:15px;border-radius:8px;border:none;background:#1890ff;color:#fff;cursor:pointer">确定</button>
      </div>
    </div>
  `,document.body.appendChild(o),o.querySelector(".sync-ok-btn").onclick=()=>{o.remove(),i&&i()}}function g(r){const t=document.createElement("div");t.style.cssText="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center",t.innerHTML=`
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:320px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:20px;font-weight:700;color:#ff4d4f;margin-bottom:12px">同步失败</div>
      <div style="font-size:14px;color:#666;margin-bottom:22px;word-break:break-all"></div>
      <button style="padding:8px 36px;font-size:15px;border-radius:8px;border:1px solid #d9d9d9;background:#fff;cursor:pointer">关闭</button>
    </div>
  `,t.querySelector('div[style*="word-break"]').textContent=r,document.body.appendChild(t),t.querySelector("button").onclick=()=>t.remove()}export{y as d};
