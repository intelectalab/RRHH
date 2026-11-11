// Valida con mermaid.parse cada bloque ```mermaid de los .md de una carpeta (recursivo).
// Uso: npm install mermaid@11.4.1 playwright && npx playwright install chromium && node kit/tools/verificar_mermaid.js kit/docs
const {chromium}=require('playwright');const fs=require('fs');const path=require('path');
const root=process.argv[2];const blocks=[];
(function walk(d){for(const f of fs.readdirSync(d)){const p=path.join(d,f);if(fs.statSync(p).isDirectory())walk(p);else if(f.endsWith('.md')){
 const t=fs.readFileSync(p,'utf8');const re=/```mermaid\n([\s\S]*?)```/g;let m;while((m=re.exec(t)))blocks.push({file:path.relative(root,p),line:t.slice(0,m.index).split('\n').length,src:m[1]});}}})(root);
(async()=>{const b=await chromium.launch();const pg=await b.newPage();await pg.setContent('<html><body></body></html>');
await pg.addScriptTag({path:require.resolve('mermaid/dist/mermaid.min.js')});
await pg.evaluate(()=>mermaid.initialize({startOnLoad:false}));let bad=0;
for(const x of blocks){const r=await pg.evaluate(async s=>{try{await mermaid.parse(s);return null}catch(e){return String(e.message||e).slice(0,300)}},x.src);
 if(r){bad++;console.log(`${x.file}:${x.line}  ${r.split('\n')[0]}`)}}
console.log(`diagramas ${blocks.length}, con error ${bad}`);await b.close();process.exit(bad?1:0)})();
