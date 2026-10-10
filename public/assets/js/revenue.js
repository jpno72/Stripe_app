(() => {
  'use strict';
  const dataElement = document.getElementById('revenue-data');
  if (!dataElement) return;
  const report = JSON.parse(dataElement.textContent);
  const currency = report.currency;
  const unit = currency ? currency.toUpperCase() + ' minor units' : 'minor units';
  const money = value => value === null || value === undefined ? 'Unknown' : Number(value).toLocaleString() + ' ' + unit;
  function empty(canvasId) {
    const canvas = document.getElementById(canvasId);
    canvas.hidden = true;
    document.getElementById(canvasId + '-empty').hidden = false;
  }
  if (typeof Chart !== 'undefined' && currency) {
    const trend = report.trend.filter(row => row.currency === currency);
    if (trend.length) {
      const labels = trend.map(row => row.month_start_local.slice(0, 7));
      new Chart(document.getElementById('revenue-trend'), {
        type: 'line',
        data: {labels, datasets: [
          {label: 'Gross paid volume', data: trend.map(row => row.gross_paid_minor === null ? null : Number(row.gross_paid_minor)), borderColor: '#2167b0', backgroundColor: '#2167b0', tension: .25},
          {label: 'Net after recorded costs', data: trend.map(row => row.net_after_recorded_costs_minor === null ? null : Number(row.net_after_recorded_costs_minor)), borderColor: '#119681', backgroundColor: '#119681', tension: .25, spanGaps: false}
        ]},
        options: {responsive: true, maintainAspectRatio: false, plugins: {tooltip: {callbacks: {label: ctx => ctx.dataset.label + ': ' + money(ctx.raw)}}}, scales: {y: {title: {display: true, text: unit}}}}
      });
    } else empty('revenue-trend');
    const programs = report.programs.filter(row => row.currency === currency);
    if (programs.length) {
      new Chart(document.getElementById('revenue-programs'), {
        type: 'doughnut',
        data: {labels: programs.map(row => row.program_name), datasets: [{data: programs.map(row => Number(row.gross_paid_minor)), backgroundColor: ['#2167b0','#119681','#eea34e','#735ab1','#5d859b','#dd7384','#487d4c','#8b7281','#438d9a','#b09041','#565c9c','#ab6a4c']}]},
        options: {responsive: true, maintainAspectRatio: false, plugins: {tooltip: {callbacks: {label: ctx => ctx.label + ': ' + money(ctx.raw)}}}}
      });
    } else empty('revenue-programs');
    const fees = trend.filter(row => row.stripe_fees_minor !== null);
    if (fees.length) {
      new Chart(document.getElementById('revenue-fees'), {
        type: 'bar',
        data: {labels: fees.map(row => row.month_start_local.slice(0, 7)), datasets: [{label: 'Known Stripe fees', data: fees.map(row => Number(row.stripe_fees_minor)), backgroundColor: '#7795b9'}]},
        options: {responsive: true, maintainAspectRatio: false, plugins: {tooltip: {callbacks: {label: ctx => money(ctx.raw)}}}, scales: {y: {title: {display: true, text: unit}}}}
      });
    } else empty('revenue-fees');
  } else {
    ['revenue-trend','revenue-programs','revenue-fees'].forEach(empty);
  }
  const table = document.getElementById('revenue-transactions');
  const url = new URL('/api/revenue-transactions.php', window.location.origin);
  Object.entries(report.filters).forEach(([key, value]) => url.searchParams.set(key, value));
  if (typeof DataTable !== 'undefined') {
    new DataTable(table, {
      processing: true, serverSide: true, pageLength: 25, order: [[0, 'desc']],
      ajax: url.toString(),
      language: {emptyTable: 'No transactions match these filters.'}
    });
  } else {
    fetch(url.toString()).then(response => response.json()).then(payload => {
      if (payload.error) throw new Error(payload.error);
      const body = table.querySelector('tbody');
      if (!payload.data.length) {
        body.innerHTML = '<tr><td colspan="9">No transactions match these filters.</td></tr>';
      } else {
        payload.data.forEach(row => {
          const tr = document.createElement('tr');
          row.forEach(cell => { const td = document.createElement('td'); td.innerHTML = cell; tr.appendChild(td); });
          body.appendChild(tr);
        });
      }
    }).catch(() => { table.querySelector('tbody').innerHTML = '<tr><td colspan="9">Transaction data is unavailable.</td></tr>'; });
  }
})();
