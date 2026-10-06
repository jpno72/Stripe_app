(() => {
 'use strict';const element=document.getElementById('demographics-data');if(!element)return;const report=JSON.parse(element.textContent);
 const colors=['#2167b0','#119681','#735ab1','#eea34e','#5d859b','#dd7384','#487d4c','#8b7281','#438d9a','#b09041','#565c9c'];
 for(const key of ['age','youth','cities','zips']) {
   const canvas=document.getElementById('demographic-'+key);const note=document.getElementById('demographic-'+key+'-empty');
   if(!report.available[key]||typeof Chart==='undefined'){canvas.parentElement.hidden=true;note.hidden=false;if(typeof Chart==='undefined')note.textContent='Chart library is unavailable.';continue;}
   const rows=report[key];const type=key==='youth'?'doughnut':'bar';
   new Chart(canvas,{type,data:{labels:rows.map(row=>row.label),datasets:[{label:'Members',data:rows.map(row=>Number(row.count)),backgroundColor:colors}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:key==='cities'||key==='zips'?'y':'x',plugins:{legend:{display:type==='doughnut'}},scales:type==='bar'?(key==='cities'||key==='zips'?{x:{beginAtZero:true,ticks:{precision:0}}}:{y:{beginAtZero:true,ticks:{precision:0}}}):{}}});
 }
 const url=new URL('/api/demographics-geography.php',location.origin);Object.entries(report.filters).forEach(([key,value])=>url.searchParams.set(key,value));const table=document.getElementById('demographic-geography');
 if(typeof DataTable!=='undefined')new DataTable(table,{processing:true,serverSide:true,pageLength:25,order:[[2,'desc']],ajax:url.toString(),columnDefs:[{targets:4,orderable:false}],language:{emptyTable:'No geographic records match these filters.'}});
 else fetch(url.toString()).then(response=>response.json()).then(result=>{if(result.error)throw new Error(result.error);const body=table.querySelector('tbody');if(!result.data.length)body.innerHTML='<tr><td colspan="5">No geographic records match these filters.</td></tr>';else result.data.forEach(row=>{const tr=document.createElement('tr');row.forEach(cell=>{const td=document.createElement('td');td.innerHTML=cell;tr.appendChild(td);});body.appendChild(tr);});}).catch(()=>{table.querySelector('tbody').innerHTML='<tr><td colspan="5">Geographic data is unavailable.</td></tr>';});
})();
