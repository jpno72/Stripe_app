'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const input=document.getElementById('association-person');if(!input)return;
 const list=document.getElementById('association-person-options');let timer,controller;
 input.addEventListener('input',()=>{clearTimeout(timer);if(controller)controller.abort();if(input.value.trim().length<2){list.replaceChildren();return;}
  timer=setTimeout(async()=>{controller=new AbortController();try{const url=new URL('/api/people.php',location.origin);url.searchParams.set('q',input.value.trim());url.searchParams.set('length','25');const response=await fetch(url,{signal:controller.signal});if(!response.ok)return;const data=await response.json();const options=[];
   for(const row of data.data){const code=row[0].match(/BMA-[0-9]+/);if(!code)continue;const option=document.createElement('option');option.value=code[0];option.label=new DOMParser().parseFromString(row[1]+' '+row[2],'text/html').body.textContent;options.push(option);}list.replaceChildren(...options);
  }catch(error){if(error.name!=='AbortError')list.replaceChildren();}},250);
 });
});
