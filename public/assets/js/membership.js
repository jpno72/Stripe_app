(() => {
 'use strict';
 const element=document.getElementById('membership-data');if(!element)return;
 const data=JSON.parse(element.textContent);
 const colors=['#2167b0','#119681','#735ab1','#eea34e','#5d859b','#dd7384'];
 function empty(id,message) {document.getElementById(id).closest('.membership-chart').hidden=true;const note=document.getElementById(id+'-empty');note.hidden=false;if(message)note.textContent=message;}
 function chart(id,rows,type) {
   if(typeof Chart==='undefined'){empty(id,'Chart library is unavailable.');return;}
   if(!rows.length){empty(id);return;}
   new Chart(document.getElementById(id),{type,data:{labels:rows.map(row=>row.label),datasets:[{label:'Subscriptions',data:rows.map(row=>Number(row.count)),backgroundColor:colors}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:type==='doughnut'}},scales:type==='bar'?{y:{beginAtZero:true,ticks:{precision:0}}}:{}}});
 }
 chart('membership-mix',data.mix,'doughnut');chart('membership-statuses',data.statuses,'bar');
 const months=[...new Set([...data.events.map(row=>row.month),...data.cancellations.map(row=>row.month)])].sort();
 if(typeof Chart==='undefined')empty('membership-events','Chart library is unavailable.');
 else if(!months.length)empty('membership-events');
 else {
   const starts=new Map(data.events.map(row=>[row.month,Number(row.starts)]));
   const cancellations=new Map(data.cancellations.map(row=>[row.month,Number(row.count)]));
   new Chart(document.getElementById('membership-events'),{type:'bar',data:{labels:months.map(month=>month.slice(0,7)),datasets:[{label:'Recorded new members',data:months.map(month=>starts.get(month)||0),backgroundColor:colors[0]},{label:'Recorded canceled subscriptions',data:months.map(month=>cancellations.get(month)||0),backgroundColor:colors[3]}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
 }
 const table=document.getElementById('membership-members');const url=new URL('/api/membership-members.php',location.origin);
 Object.entries(data.filters).forEach(([key,value])=>url.searchParams.set(key,value));
 if(typeof DataTable!=='undefined')new DataTable(table,{processing:true,serverSide:true,pageLength:25,order:[[1,'asc']],ajax:url.toString(),language:{emptyTable:'No members match these filters.'}});
 else fetch(url.toString()).then(response=>response.json()).then(result=>{
   if(result.error)throw new Error(result.error);
   const body=table.querySelector('tbody');
   if(!result.data.length)body.innerHTML='<tr><td colspan="9">No members match these filters.</td></tr>';
   else result.data.forEach(row=>{const tr=document.createElement('tr');row.forEach(cell=>{const td=document.createElement('td');td.innerHTML=cell;tr.appendChild(td);});body.appendChild(tr);});
 }).catch(()=>{table.querySelector('tbody').innerHTML='<tr><td colspan="9">Member data is unavailable.</td></tr>';});
})();
