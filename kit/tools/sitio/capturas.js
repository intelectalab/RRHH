// Regenera las capturas del sitio (img/*.jpg, 1600 × 1002) desde el prototipo. Uso, desde la raíz: node kit/tools/sitio/capturas.js "$PWD/kit/prototipo/GestiaHR_prototipo.html" "$PWD/kit/tools/sitio/img"
const {chromium}=require('playwright');const [,,proto,out]=process.argv;
(async()=>{const b=await chromium.launch();
async function sess(perfIdx,shots){const p=await b.newPage({viewport:{width:1600,height:1002}});
await p.goto('file://'+proto);await p.addStyleTag({content:'body{margin:0}'});await p.waitForTimeout(500);const bs=await p.$$('button[data-a=lperf]');await bs[perfIdx].click();await p.waitForTimeout(300);
const s=await p.$('.lg-form button.p');if(s)await s.click();await p.waitForTimeout(800);
for(const [r,f] of shots){await p.evaluate(r=>{const e=document.querySelector('[data-r="'+r+'"]');if(e)e.click();},r);await p.waitForTimeout(900);await p.screenshot({path:out+'/'+f,type:'jpeg',quality:85});}
await p.close()}
await sess(0,[['inicio','inicio.jpg'],['colaboradores','colaboradores.jpg'],['no_periodos','nomina.jpg'],['ti_vacaciones','vacaciones.jpg']]);
await sess(4,[['inicio','portal.jpg']]);await b.close()})()
