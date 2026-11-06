<?php
// Cookie consent (Customize → Cookie Consent). Google Consent Mode v2: ads/analytics storage starts "denied"
// until the visitor accepts; a saved choice is applied before any Google tag runs. Banner markup is in footer.php.
if (!function_exists('th_on') || !th_on('cc_enabled')) return;
?>
<script id="edu-consent-boot">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}(function(){var c=null;try{c=JSON.parse(localStorage.getItem("edu_consent")||"null")}catch(e){}var eea=<?= th('cc_region') === 'eea' ? 'true' : 'false' ?>,tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||""}catch(e){}window.__eduConsentApplies=!eea||/^(Europe\/|Atlantic\/(Reykjavik|Canary|Madeira|Azores|Faroe))/.test(tz);var g=function(v){return v?"granted":"denied"};if(!window.__eduConsentApplies){gtag("consent","default",{ad_storage:"granted",ad_user_data:"granted",ad_personalization:"granted",analytics_storage:"granted"});return}if(c&&c.exp>Date.now()){gtag("consent","default",{ad_storage:g(c.ads),ad_user_data:g(c.ads),ad_personalization:g(c.pers),analytics_storage:g(c.an),functionality_storage:"granted",security_storage:"granted"})}else{gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",functionality_storage:"granted",security_storage:"granted",wait_for_update:500})}gtag("set","ads_data_redaction",true)})();</script>
<style>
.edu-cc{position:fixed;z-index:2147483600;font:14px/1.5 var(--edu-font-body,system-ui,sans-serif);color:#1f2937}
.edu-cc-box{background:#fff;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.22);padding:18px 20px}
.edu-cc--bottom{left:0;right:0;bottom:0}.edu-cc--bottom .edu-cc-box{border-radius:14px 14px 0 0;max-width:1100px;margin:0 auto;display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.edu-cc--left{left:16px;bottom:16px;max-width:400px}
.edu-cc--center{inset:0;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.45);padding:16px}.edu-cc--center .edu-cc-box{max-width:480px}
.edu-cc-text{flex:1 1 360px}.edu-cc h2{margin:0 0 4px;font-size:16px;font-weight:700;color:#111827}.edu-cc p{margin:0;color:#4b5563}
.edu-cc a{color:var(--gc-accent,#0c6878);text-decoration:underline!important}
.edu-cc-btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.edu-cc--bottom .edu-cc-btns{margin-top:0}
.edu-cc button{border:1px solid var(--gc-accent,#0c6878);border-radius:8px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer;background:#fff;color:var(--gc-accent,#0c6878)}
.edu-cc button.edu-cc-yes{background:var(--gc-accent,#0c6878);color:#fff}
.edu-cc-opts{display:none;margin-top:12px;border-top:1px solid #e5e7eb;padding-top:10px;width:100%}
.edu-cc.is-custom .edu-cc-opts{display:block}
.edu-cc-opt{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f1f5f9}.edu-cc-opt small{display:block;color:#6b7280}
.edu-cc-fab{position:fixed;left:14px;bottom:14px;z-index:2147483500;width:44px;height:44px;border-radius:50%;border:0;background:var(--gc-accent,#0c6878);color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.25);cursor:pointer;display:flex;align-items:center;justify-content:center}
.edu-cc-fab svg{width:22px;height:22px}
</style>
