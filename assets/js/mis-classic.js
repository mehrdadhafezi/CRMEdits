/* Classic MIS uses full page links and the unchanged server-side form handlers. */
(function(){'use strict';
 document.addEventListener('click',async function(e){
  var button=e.target.closest('.sn-mis-setup-run');if(!button||!button.closest('#sn-mis-advanced-panel')||button.dataset.running)return;
  e.preventDefault();var card=button.closest('[data-setup-api]'),text=card.querySelector('.sn-mis-setup-status'),progress=card.querySelector('progress');
  button.dataset.running='1';button.disabled=true;progress.hidden=false;
  try{var data;do{
   var controller=new AbortController(),timer=setTimeout(function(){controller.abort();},15000);
   try{var response=await fetch(card.dataset.setupApi,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'sn_mis_setup',nonce:card.dataset.setupNonce}),signal:controller.signal});var result=await response.json();if(!response.ok||!result.success)throw Error(result.data&&result.data.message||'آماده‌سازی انجام نشد؛ دوباره ادامه دهید.');data=result.data;}finally{clearTimeout(timer);}
   progress.max=Number(data.total||8);progress.value=Number(data.completed);text.textContent=data.message;
   if(!card.isConnected)return;
  }while(!data.ready);
  location.reload();
  }catch(error){text.textContent=error.name==='AbortError'?'پاسخ طول کشید؛ مراحل قبلی محفوظ‌اند. دوباره ادامه دهید.':error.message;button.textContent='ادامه آماده‌سازی';}
  finally{button.disabled=false;delete button.dataset.running;}
 });
})();
