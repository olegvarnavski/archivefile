const {JSDOM}=require('jsdom');
const fs=require('fs'),path=require('path');
const base=path.resolve(__dirname,'..');
const template=fs.readFileSync(base+'/core/components/archivefile/templates/home.html','utf8');
const dom=new JSDOM('<ul><li id="archivefile">Archive File menu</li></ul>'+template,{url:'https://example.org/manager/',runScripts:'outside-only'});
const w=dom.window,d=w.document;w.ArchiveFileConfig={connector:'/connector',csrf:'test'};
let sources=[{id:1,name:'Каталог',url:'https://example.org/feed.xml',format:'xml',enabled:1,latest:{offers_count:42},xml_options:{offer_element:'item',offer_attribute:'',date_element:'',container_path:'catalog/items',split_enabled:1,split_limit:1000},parts:[{filename:'part-001.xml',offers_count:1000,size_bytes:12000,url:'/parts/part-001.xml'}],latest_url:'https://example.org/assets/archivefile/source-1.xml'}],calls=[];
let copied='';w.confirm=()=>true;d.execCommand=()=>{copied=d.querySelector('textarea').value;return true;};
w.fetch=async(url,opt)=>{let p=opt.body,a=p.get('action');calls.push(a);let data;
if(a==='list')data={sources,cron:'/usr/bin/php7.4 /home/user/site/public_html/core/components/archivefile/cron.php',monitor:{stale:true,hours:36,last:null},events:[{id:1,source_name:'Каталог',kind:'manual',status:'error',started_at:'2026-10-13T03:00:00+03:00',duration_ms:123,size_bytes:null,offers_count:0,message:'XML без предложений',warning:null}]};
if(a==='copies')data=Array.from({length:3},(_,i)=>({id:i,offers_count:42-i,size_bytes:1024,url:'/copy/'+i}));
if(a==='preview')data={offers_count:2500,generation_date:null,sample:'<item/>',parts_count:3,valid:true};
if(a==='fetch')data={id:6};
if(a==='delete'){sources=[];data={deleted:true};}
if(a==='save'){sources.push({id:2,name:p.get('name'),url:p.get('url'),format:'xml',enabled:1,latest_url:'/latest'});data={id:2};}
return {json:async()=>({success:true,data})};};
const check=(v,s)=>{if(!v)throw Error(s);console.log('PASS '+s);};
async function wait(fn){for(let i=0;i<100;i++){if(fn())return;await new Promise(r=>setTimeout(r,5));}throw Error('timeout');}
(async()=>{w.eval(fs.readFileSync(base+'/assets/components/archivefile/mgr.js','utf8'));await wait(()=>d.querySelector('button[aria-label="Настройки и последние 3 копии"]'));

d.querySelector('button[aria-label="Настройки и последние 3 копии"]').click();await wait(()=>d.querySelectorAll('#fa-copies tr').length===3);
check(d.querySelector('#fa-expanded #fa-detail'),'details inside source table');
d.querySelector('#fa-copy-cron').click();check(copied==='/usr/bin/php7.4 /home/user/site/public_html/core/components/archivefile/cron.php','copy cron preserves unquoted command');
check(d.querySelector('tr[data-source-id="1"]').classList.contains('fa-selected'),'expanded source highlighted');
check(d.querySelector('button[aria-controls="fa-detail"]').getAttribute('aria-expanded')==='true','settings button exposes expanded state');
check(d.querySelector('button[aria-label="Удалить источник и архив"] .fa-glyph[aria-hidden="true"]'),'manager icon has accessible label');
check(d.querySelector('#fa-monitor').textContent.includes('36'),'cron overdue warning visible');
check(d.querySelector('#fa-events').textContent.includes('XML без предложений'),'journal shows failure reason');
check(d.querySelector('#archivefile').textContent==='Archive File menu' && !d.querySelector('#archivefile #fa-detail'),'MODX menu ID collision does not move panel into menu');
check(d.querySelector('#fa-title').textContent.includes('Настройки источника'),'settings heading');
check(d.querySelector('#fa-detail').textContent.includes('Последние 3 копии'),'history heading');
check(d.querySelectorAll('#fa-copies a[aria-label]').length===3,'accessible download icons');
check(d.querySelector('#archivefile-app').style.height.endsWith('px'),'scroll viewport height');
check(d.querySelector('#fa-form').elements.offer_element.value==='item' && d.querySelector('#fa-form').elements.offer_attribute.value==='','custom XML settings loaded');
check(d.querySelector('#fa-parts a').getAttribute('href')==='/parts/part-001.xml','part download link displayed');
check(Array.from(d.querySelectorAll('#fa-copies .fa-feed-date')).every(el=>el.hidden),'feed date disabled when absent');
d.querySelector('#fa-preview').click();await wait(()=>d.querySelector('#fa-preview-result').textContent.includes('2500')&&!d.querySelector('#fa-preview').disabled);
check(d.querySelector('#fa-preview-result').textContent.includes('Частей: 3'),'preview displays part estimate');

d.querySelector('button[aria-label="Принудительно скачать новую копию"]').click();await wait(()=>calls.includes('fetch')&&!d.querySelector('#fa-add').disabled);
check(d.querySelector('#fa-expanded #fa-detail'),'expanded source survives refresh');
for(let i=0;i<3;i++) {
 const panel=d.querySelector('#fa-detail');panel.parentNode.removeChild(panel);
 const before=calls.filter(a=>a==='fetch').length;
 d.querySelector('button[aria-label="Принудительно скачать новую копию"]').click();
 await wait(()=>calls.filter(a=>a==='fetch').length>before&&!d.querySelector('#fa-add').disabled);
 check(d.querySelector('#fa-expanded #fa-detail'),'detached settings recovered after refresh '+i);
}

d.querySelector('button[aria-label="Удалить источник и архив"]').click();await wait(()=>!sources.length&&!d.querySelector('#fa-add').disabled);
check(!d.querySelector('tr[data-source-id]'),'delete removes source row');
d.querySelector('#fa-add').click();d.querySelector('#fa-form').elements.name.value='Другой файл';d.querySelector('#fa-form').elements.url.value='https://example.org/file.xml';d.querySelector('#fa-form').dispatchEvent(new w.Event('submit',{cancelable:true}));await wait(()=>sources.length&&!d.querySelector('#fa-add').disabled);check(d.querySelector('#fa-expanded #fa-detail'),'new source saved and expanded');
})().catch(e=>{console.error(e);process.exit(1);});
