'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const node=document.getElementById('directory-data');if(!node)return;
 const filters=JSON.parse(node.textContent);const url=new URL('/api/people.php',location.origin);
 Object.entries(filters).forEach(([key,value])=>url.searchParams.set(key==='search'?'q':key,value));
 if(typeof DataTable==='undefined'){document.getElementById('directory-error').hidden=false;return;}
 new DataTable('#people-directory',{processing:true,serverSide:true,searching:false,pageLength:25,lengthMenu:[25,50,100,250],pagingType:'full_numbers',order:[[2,'asc']],ajax:{url:url.toString(),error:()=>{document.getElementById('directory-error').hidden=false;}},language:{emptyTable:'No people match these filters.'}});
});
