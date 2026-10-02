import fs from 'node:fs'; import path from 'node:path';
const ROOT=path.resolve(import.meta.dirname,'..');
const src=fs.readFileSync(path.join(ROOT,'seo','_audit','price_rows.txt'),'utf8');
const groups=[]; let cur=null;
for(const line of src.split('\n')){
  if(line.startsWith('## ')){cur={model:line.slice(3).trim(),rows:[]};groups.push(cur);continue;}
  const c=line.split(' ;; ').map(s=>s.trim());
  if(!cur||c.length<5||!/^\d+$/.test(c[0]))continue;
  const nm=c[1];
  const dm=nm.match(/^(\d{10,12}(?:[-/]\d{1,2}(?:\/\d{1,2})?)?)\s+(.*)$/);
  cur.rows.push({
    draw: dm?dm[1]:'',
    name: dm?dm[2]:nm,
    qty: c[2],
    weight: c[3]?Number(c[3].replace(',','.')):null,
    price: /^[\d\s]+[.,]\d\d$/.test(c[4])?Number(c[4].replace(/\s/g,'').replace(',','.')):null,
  });
}
fs.writeFileSync(path.join(ROOT,'data','price.json'),JSON.stringify({
  updated:'',
  note:'Перенесено со старого сайта (/price/ и /price/drobilki-shchekovye/). Дату актуальности цен нужно уточнить у клиента.',
  groups
},null,2)+'\n','utf8');
console.log('групп:',groups.length,'строк:',groups.reduce((a,g)=>a+g.rows.length,0),
 'с ценой:',groups.reduce((a,g)=>a+g.rows.filter(r=>r.price!=null).length,0));
