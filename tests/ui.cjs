const {JSDOM}=require('jsdom');
const fs=require('fs'),path=require('path');
const base=path.resolve(__dirname,'..');
const template=fs.readFileSync(base+'/core/components/archivefile/templates/home.html','utf8');
const dom=new JSDOM(template,{url:'https://example.org/manager/',runScripts:'outside-only'});
const w=dom.window,d=w.document;w.ArchiveFileConfig={connector:'/connector',csrf:'test'};
let sources=[{id:1,name:'Каталог',url:'https://example.org/feed.xml',format:'xml',enabled:1,latest:{offers_count:42},latest_url:'https://example.org/assets/archivefile/source-1.xml'}],calls=[];
w.confirm=()=>true;d.execCommand=()=>true;
w.fetch=async(url,opt)=>{let p=opt.body,a=p.get('action');calls.push(a);let data;
if(a==='list')data={sources,cron:'php cron.php'};
if(a==='copies')data=Array.from({length:5},(_,i)=>({id:i,offers_count:42-i,size_bytes:1024,url:'/copy/'+i}));
if(a==='fetch')data={id:6};
if(a==='delete'){sources=[];data={deleted:true};}
if(a==='save'){sources.push({id:2,name:p.get('name'),url:p.get('url'),format:'xml',enabled:1,latest_url:'/latest'});data={id:2};}
return {json:async()=>({success:true,data})};};
const check=(v,s)=>{if(!v)throw Error(s);console.log('PASS '+s);};
async function wait(fn){for(let i=0;i<100;i++){if(fn())return;await new Promise(r=>setTimeout(r,5));}throw Error('timeout');}
(async()=>{w.eval(fs.readFileSync(base+'/assets/components/archivefile/mgr.js','utf8'));await wait(()=>d.querySelector('button[aria-label="Настройки и последние 5 копий"]'));

d.querySelector('button[aria-label="Настройки и последние 5 копий"]').click();await wait(()=>d.querySelectorAll('#fa-copies tr').length===5);
check(d.querySelector('#fa-expanded #fa-detail'),'details inside source table');
check(d.querySelector('#fa-title').textContent.includes('Настройки источника'),'settings heading');
check(d.querySelector('#fa-detail').textContent.includes('Последние 5 копий'),'history heading');
check(d.querySelectorAll('#fa-copies a[aria-label]').length===5,'accessible download icons');
check(d.querySelector('#archivefile').style.height.endsWith('px'),'scroll viewport height');
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
