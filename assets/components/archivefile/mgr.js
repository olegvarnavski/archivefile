(function () {
  'use strict';
  function start() {
    var root = document.getElementById('archivefile-app');
    if (!root) return;
    var config = window.ArchiveFileConfig, sources = [], current = 0, busy = false;
    var detail = document.getElementById('fa-detail'), expanded = null;
    var form = document.getElementById('fa-form');
    var baseline = '';
    function values() {
      var data = {};
      Array.prototype.forEach.call(form.elements,function(el){if(el.name)data[el.name]=el.type==='checkbox'?el.checked:el.value;});
      return data;
    }
    function dirty() {return !detail.hidden && baseline !== JSON.stringify(values());}
    function canLeave() {return !dirty() || window.confirm('Есть несохранённые настройки. Закрыть без сохранения?');}
    window.addEventListener('beforeunload',function(e){if(dirty()){e.preventDefault();e.returnValue='';}});
    var message = document.getElementById('fa-message');
    function notify(text, error) { message.textContent = text; message.className = error ? 'fa-error' : 'fa-ok'; }
    function api(action, data) {
      var body = new URLSearchParams(data || {}); body.set('action', action); body.set('csrf', config.csrf);
      return fetch(config.connector, {method: 'POST', credentials: 'same-origin', body: body}).then(function (r) {
        return r.json().catch(function(){throw new Error('Сервер вернул некорректный ответ (HTTP '+r.status+'). Проверьте журнал MODX и PHP.');});
      }).then(function (r) { if (!r.success) throw new Error(r.message || 'Ошибка сервера'); return r.data; });
    }
    function cell(row, value, cls) { var td = document.createElement('td'); td.textContent = value === null || value === undefined ? '—' : value; if (cls) td.className = cls; row.appendChild(td); return td; }
    function button(text, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = text; b.onclick = fn; return b; }
    function decorateIcon(el,label,glyph) {
      var codes={'⚙':'\uf013','↻':'\uf021','⧉':'\uf0c5','×':'\uf1f8','↓':'\uf019'};
      el.textContent='';el.title=label;el.setAttribute('aria-label',label);el.className='fa-icon'+(glyph==='×'?' fa-danger':'');
      var mark=document.createElement('span');mark.className='fa-glyph';mark.setAttribute('aria-hidden','true');mark.textContent=codes[glyph]||glyph;el.appendChild(mark);
      return el;
    }
    function icon(label, glyph, fn) {return decorateIcon(button('',fn),label,glyph);}
    function markSelected(id) {
      root.querySelectorAll('#fa-sources>tr[data-source-id]').forEach(function(row){var selected=Number(row.getAttribute('data-source-id'))===Number(id);row.classList.toggle('fa-selected',selected);var trigger=row.querySelector('button');if(trigger){trigger.setAttribute('aria-expanded',selected?'true':'false');trigger.setAttribute('aria-controls','fa-detail');}});
    }
    function closeDetail() {
      detail.hidden = true; root.appendChild(detail);
      if (expanded && expanded.parentNode) expanded.parentNode.removeChild(expanded);
      expanded = null; current = 0;markSelected(0);
    }
    function refresh(id) {
      if (busy) return; setBusy(true); notify('Скачивание и проверка файла…');
      api('fetch',{id:id}).then(load).then(function(){if(current===Number(id))return loadCopies(id);})
        .then(function(){notify('Файл сохранён. В архиве оставлены последние 3 копии.');})
        .catch(function(e){notify(e.message,true);return load().catch(function(){});}).finally(function(){setBusy(false);});
    }
    function removeSource(s) {
      if(busy || !window.confirm('Удалить «'+s.name+'», все его копии и постоянный файл?'))return;
      setBusy(true);api('delete',{id:s.id}).then(function(){if(current===Number(s.id))closeDetail();return load();})
        .then(function(){notify('Источник и архив удалены');}).catch(function(e){notify(e.message,true);}).finally(function(){setBusy(false);});
    }
    function date(value) { if (!value) return '—'; var d = new Date(value); return isNaN(d.getTime()) ? value : d.toLocaleString('ru-RU', {timeZone: 'Europe/Moscow'}); }
    function copy(url) {
      function fallback() {
        var t = document.createElement('textarea'); t.value = url; root.appendChild(t); t.select();
        var ok = document.execCommand('copy'); t.remove();
        if (ok) notify('Ссылка скопирована'); else window.prompt('Скопируйте ссылку:', url);
      }
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(function () { notify('Ссылка скопирована'); }).catch(fallback);
      else fallback();
    }
    function setBusy(value) { busy = value; root.querySelectorAll('button').forEach(function (b) { b.disabled = value; }); }
    function xmlData() {
      return {offer_element:form.elements.offer_element.value,offer_attribute:form.elements.offer_attribute.value,date_element:form.elements.date_element.value,
        container_path:form.elements.container_path.value,split_enabled:form.elements.split_enabled.checked?1:0,split_limit:form.elements.split_limit.value};
    }
    function showParts(s) {
      var rows=s&&s.parts||[],tbody=document.getElementById('fa-parts');tbody.textContent='';document.getElementById('fa-parts-block').hidden=!rows.length;
      rows.forEach(function(p,i){var tr=document.createElement('tr');cell(tr,p.filename);cell(tr,p.offers_count);cell(tr,(Number(p.size_bytes)/1024).toFixed(1)+' КБ');
        var td=cell(tr,''),actions=document.createElement('div');actions.className='fa-actions';td.appendChild(actions);
        var a=document.createElement('a');a.href=p.url;decorateIcon(a,'Скачать часть','↓');a.target='_blank';a.rel='noopener';actions.appendChild(a);
        actions.appendChild(icon('Копировать ссылку на часть','⧉',function(){copy(p.url);}));tbody.appendChild(tr);});
    }
    function load() {
      var draft = !detail.hidden ? values() : null, previousBaseline = baseline;
      return api('list').then(function (data) {
        sources = data.sources;
        document.getElementById('fa-cron').textContent = data.cron;
        document.getElementById('fa-cron').setAttribute('data-ready','1');
        if(!busy) document.getElementById('fa-copy-cron').disabled=false;
        var monitor = data.monitor || {stale:true,hours:36,last:null}, lastRun = monitor.last;
        var status = document.getElementById('fa-monitor');
        status.textContent = lastRun ? 'Последний запуск cron: '+date(lastRun.started_at)+' · '+(lastRun.status==='running'?'Выполняется':lastRun.status==='ok'?'Успешно':'Ошибка')+' · '+(lastRun.message||'') : 'Cron ещё не запускался. Ручное скачивание не считается запуском cron.';
        if(monitor.stale) status.textContent += ' · Внимание: нет запуска за последние '+monitor.hours+' ч.';
        status.className = monitor.stale || lastRun && lastRun.status==='error' ? 'fa-warning' : 'fa-ok';
        var journal=document.getElementById('fa-events');journal.textContent='';
        (data.events||[]).forEach(function(e){
          var tr=document.createElement('tr');cell(tr,date(e.started_at));cell(tr,e.source_name||'Все источники');
          cell(tr,({cron:'Cron',daily:'По расписанию',manual:'Вручную'})[e.kind]||e.kind);
          cell(tr,({ok:'Успешно',error:'Ошибка',running:'Выполняется',skipped:'Пропущено'})[e.status]||e.status,e.status==='error'?'fa-error':'');
          cell(tr,e.duration_ms===null?'—':(Number(e.duration_ms)/1000).toFixed(2)+' с');
          cell(tr,e.size_bytes===null?'—':(Number(e.size_bytes)/1024).toFixed(1)+' КБ');cell(tr,e.offers_count);
          cell(tr,e.warning||e.message||'—',e.warning?'fa-warning':'');journal.appendChild(tr);
        });
        if(!(data.events||[]).length){var empty=document.createElement('tr');cell(empty,'Запусков пока нет').colSpan=8;journal.appendChild(empty);}

        root.appendChild(detail); expanded = null;
        var tbody = document.getElementById('fa-sources'); tbody.textContent = '';
        sources.forEach(function (s) {
          var row = document.createElement('tr'), last = s.latest || {};
          cell(row, s.name,'fa-name'); cell(row, date(!s.xml_options||s.xml_options.date_element ? last.generation_date : null),'fa-feed-date'); cell(row, last.offers_count); cell(row, date(s.last_success));
          cell(row, s.last_error || s.warning || (!s.enabled || s.enabled === '0' ? 'Отключено' : s.last_success ? 'Успешно' : 'Ещё не скачивалось'), s.last_error ? 'fa-error' : s.warning ? 'fa-warning' : 'fa-ok');
          row.setAttribute('data-source-id',s.id);
          var td=cell(row,''), actions=document.createElement('div');actions.className='fa-actions';td.appendChild(actions);
          actions.appendChild(icon('Настройки и последние 3 копии','⚙',function(){if(!busy&&canLeave()){if(current===Number(s.id)&&!detail.hidden)closeDetail();else open(s);}}));
          actions.appendChild(icon('Принудительно скачать новую копию','↻',function(){refresh(s.id);}));
          if(s.latest){var a=document.createElement('a');a.href=s.latest.url;decorateIcon(a,'Скачать последнюю копию','↓');a.target='_blank';a.rel='noopener';actions.appendChild(a);}
          actions.appendChild(icon('Копировать постоянную ссылку','⧉',function(){copy(s.latest_url);}));
          actions.appendChild(icon('Удалить источник и архив','×',function(){removeSource(s);}));
          tbody.appendChild(row);
        });
        var showDate=sources.some(function(s){return s.latest&&s.latest.generation_date&&(!s.xml_options||s.xml_options.date_element);});
        document.getElementById('fa-sources').parentNode.querySelectorAll('.fa-feed-date').forEach(function(el){el.hidden=!showDate;});
        markSelected(current);
        if(current){var selected=sources.find(function(s){return Number(s.id)===current;});if(selected)open(selected);else closeDetail();}
        if(draft && Number(draft.id)===current) {
          if(!current) open(null);
          Object.keys(draft).forEach(function(k){var el=form.elements[k];if(el){if(el.type==='checkbox')el.checked=draft[k];else el.value=draft[k];}});
          baseline=previousBaseline;
          document.getElementById('fa-xml-options').hidden=form.elements.format.value!=='xml';
        }
        if (!sources.length) { var tr = document.createElement('tr'); cell(tr, 'Добавьте первую ссылку').colSpan = 6; tbody.appendChild(tr); }
      });
    }
    function loadCopies(id) {
      return api('copies', {id: id}).then(function (rows) {
        if (Number(form.elements.id.value) !== Number(id)) return;
        var tbody = document.getElementById('fa-copies'); tbody.textContent = '';
        rows.forEach(function (c, i) {
          var tr = document.createElement('tr'); cell(tr, date(c.downloaded_at)); cell(tr, date(c.generation_date),'fa-feed-date');
          var count = c.offers_count;
          if (count !== null && rows[i + 1] && rows[i + 1].offers_count !== null) {
            var delta = Number(count) - Number(rows[i + 1].offers_count); count = count + ' (' + (delta > 0 ? '+' : '') + delta + ')';
          }
          cell(tr, count); cell(tr, (Number(c.size_bytes) / 1024).toLocaleString('ru-RU', {maximumFractionDigits: 1}) + ' КБ');
          var td = cell(tr, ''), actions = document.createElement('div'); actions.className = 'fa-actions';
          var a = document.createElement('a'); a.href = c.url; decorateIcon(a,'Скачать копию','↓'); a.target = '_blank'; a.rel = 'noopener'; actions.appendChild(a);
          actions.appendChild(icon('Копировать ссылку на копию','⧉',function(){copy(c.url);})); td.appendChild(actions); tbody.appendChild(tr);
        });
        var showDate=form.elements.date_element.value!==''&&rows.some(function(c){return !!c.generation_date;});
        document.getElementById('fa-copies').parentNode.querySelectorAll('.fa-feed-date').forEach(function(el){el.hidden=!showDate;});
        if (!rows.length) { var tr = document.createElement('tr'); cell(tr, 'Сохранённых копий пока нет').colSpan = 5; tbody.appendChild(tr); }
      });
    }
    function open(s) {
      current = s ? Number(s.id) : 0;
      root.appendChild(detail);
      if(expanded && expanded.parentNode)expanded.parentNode.removeChild(expanded);expanded=null;
      if(s){
        var row=root.querySelector('tr[data-source-id="'+Number(s.id)+'"]');
        if(!row){closeDetail();return;}
        expanded=document.createElement('tr');expanded.id='fa-expanded';
        var td=document.createElement('td');td.colSpan=6;expanded.appendChild(td);
        row.parentNode.insertBefore(expanded,row.nextSibling);td.appendChild(detail);
      }else{message.parentNode.insertBefore(detail,message.nextSibling);}
      detail.hidden = false;markSelected(current);
      document.getElementById('fa-title').textContent = s ? 'Настройки источника — '+s.name : 'Настройки нового источника';
      form.elements.id.value = current; form.elements.name.value = s ? s.name : ''; form.elements.url.value = s ? s.url : '';
      form.elements.format.value = s ? s.format : 'xml'; form.elements.enabled.checked = s ? Number(s.enabled) === 1 : true;
      document.getElementById('fa-fetch').hidden = !current;
      document.getElementById('fa-latest').hidden = !current;
      document.getElementById('fa-latest-url').value = s ? s.latest_url : '';
      document.getElementById('fa-copies').textContent = '';
      if (current) loadCopies(current).catch(function (e) { notify(e.message, true); });
      var o=s&&s.xml_options||{offer_element:'offer',offer_attribute:'internal-id',date_element:'generation-date',container_path:'',split_enabled:0,split_limit:1000};
      ['offer_element','offer_attribute','date_element','container_path','split_limit'].forEach(function(k){form.elements[k].value=o[k];});form.elements.split_enabled.checked=!!Number(o.split_enabled);
      document.getElementById('fa-xml-options').hidden=form.elements.format.value!=='xml';document.getElementById('fa-preview-result').textContent='';showParts(s);
      baseline=JSON.stringify(values());
      form.elements.name.focus();
    }
    document.getElementById('fa-copy-cron').onclick=function(){var command=document.getElementById('fa-cron');if(command.getAttribute('data-ready')==='1')copy(command.textContent);};
    document.getElementById('fa-add').onclick = function () { if(canLeave())open(null); };
    document.getElementById('fa-close').onclick = function () { if(canLeave())closeDetail(); };
    document.getElementById('fa-copy-latest').onclick = function () { copy(document.getElementById('fa-latest-url').value); };
    form.onsubmit = function (e) {
      e.preventDefault(); if (busy) return; setBusy(true);
      api('save', Object.assign(xmlData(),{id: form.elements.id.value, name: form.elements.name.value, url: form.elements.url.value, format: form.elements.format.value, enabled: form.elements.enabled.checked ? 1 : 0}))
        .then(function (r) { return load().then(function () { open(sources.find(function (s) { return Number(s.id) === Number(r.id); })); notify('Настройки сохранены'); }); })
        .catch(function (e) { notify(e.message, true); }).finally(function () { setBusy(false); });
    };
    form.elements.format.onchange=function(){document.getElementById('fa-xml-options').hidden=this.value!=='xml';};
    document.getElementById('fa-preview').onclick=function(){if(busy)return;setBusy(true);var result=document.getElementById('fa-preview-result');result.textContent='Проверка…';
      api('preview',Object.assign(xmlData(),{url:form.elements.url.value})).then(function(r){result.textContent='Предложений: '+r.offers_count+'\nДата: '+(r.generation_date||'не найдена — будет скрыта')+'\nЧастей: '+r.parts_count+'\n'+(r.valid?'Проверка пройдена':'Нет предложений — загрузка будет отклонена')+'\n\nПример:\n'+(r.sample||'не найден');}).catch(function(e){result.textContent=e.message;}).finally(function(){setBusy(false);});};
    document.getElementById('fa-fetch').onclick = function(){if(current)refresh(current);};
    function resize(){root.style.height=Math.max(280,window.innerHeight-root.getBoundingClientRect().top-12)+'px';}
    resize();window.addEventListener('resize',resize);
    load().catch(function (e) { notify(e.message, true); });
  }
  if (window.Ext) Ext.onReady(start); else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
}());
