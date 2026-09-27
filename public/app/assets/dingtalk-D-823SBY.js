import{e as l}from"./index-D99kH_9Z.js";async function f(e){if(!window.confirm("立即从钉钉全量同步组织架构和人员？"))return;const t=document.createElement("div");if(t.id="syncOverlay",t.style.cssText="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center",t.innerHTML=`
    <div style="background:#fff;border-radius:14px;padding:32px 40px;min-width:340px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:16px;font-weight:600;color:#333;margin-bottom:18px">钉钉数据同步中</div>
      <div class="sync-spinner" style="width:44px;height:44px;border:4px solid #e8e8e8;border-top-color:#1890ff;border-radius:50%;animation:syncSpin .8s linear infinite;margin:0 auto 18px"></div>
      <div class="sync-stage" style="font-size:14px;color:#888;margin-bottom:6px">正在连接钉钉...</div>
      <div class="sync-timer" style="font-size:13px;color:#bbb">已用时 0s</div>
    </div>
  `,document.body.appendChild(t),!document.getElementById("syncSpinStyle")){const o=document.createElement("style");o.id="syncSpinStyle",o.textContent="@keyframes syncSpin{to{transform:rotate(360deg)}}",document.head.appendChild(o)}const i=["正在连接钉钉...","正在拉取部门树...","正在同步组织架构...","正在拉取在职人员...","正在同步人员数据...","正在拉取离职名单...","正在同步花名册...","即将完成..."];let n=0;const d=()=>t.querySelector(".sync-stage"),r=()=>t.querySelector(".sync-timer"),s=Date.now(),a=setInterval(()=>{d()&&(d().textContent=i[n%i.length]),n++,r()&&(r().textContent="已用时 "+Math.round((Date.now()-s)/1e3)+"s")},4e3);try{const o=await l("/api/dingtalk/sync-now",{method:"POST"});clearInterval(a),t.remove();const c=o.report||{};p(c,o.elapsed||c.elapsed||0,e)}catch(o){clearInterval(a),t.remove(),x(o.message)}}function p(e,t,i){const n=document.createElement("div");n.style.cssText="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center";const d=[["钉钉部门数",e.dingtalk_depts??0],["钉钉在职人员",e.dingtalk_users??0],["钉钉离职人员",e.dingtalk_dismissed??0],["新增人员",e.new??0],["更新人员",e.updated??0],["标记离职",e.offboard??0],["新增离职",e.offboard_new??0],["花名册同步",e.roster??0]];n.innerHTML=`
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="text-align:center;margin-bottom:20px">
        <div style="font-size:20px;font-weight:700;color:#52c41a">同步完成</div>
        <div style="font-size:13px;color:#999;margin-top:4px">耗时 ${t} 秒</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        ${d.map(([r,s])=>`<tr><td style="padding:6px 0;color:#666">${r}</td><td style="padding:6px 0;text-align:right;font-weight:600;color:#333">${s}</td></tr>`).join("")}
      </table>
      <div style="text-align:center;margin-top:22px">
        <button class="sync-ok-btn" style="padding:8px 36px;font-size:15px;border-radius:8px;border:none;background:#1890ff;color:#fff;cursor:pointer">确定</button>
      </div>
    </div>
  `,document.body.appendChild(n),n.querySelector(".sync-ok-btn").onclick=()=>{n.remove(),i&&i()}}function x(e){const t=document.createElement("div");t.style.cssText="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center",t.innerHTML=`
    <div style="background:#fff;border-radius:14px;padding:28px 36px;min-width:320px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25)">
      <div style="font-size:20px;font-weight:700;color:#ff4d4f;margin-bottom:12px">同步失败</div>
      <div style="font-size:14px;color:#666;margin-bottom:22px;word-break:break-all"></div>
      <button style="padding:8px 36px;font-size:15px;border-radius:8px;border:1px solid #d9d9d9;background:#fff;cursor:pointer">关闭</button>
    </div>
  `,t.querySelector('div[style*="word-break"]').textContent=e,document.body.appendChild(t),t.querySelector("button").onclick=()=>t.remove()}export{f as d};
