(function(){'use strict';
/* =====================================================================
   GestiaHR · Módulo «Tiempo» (asistencia, horarios, turnos, vacaciones,
   ausencias, horas extra, bolsa de horas y hojas de tiempo). Prefijo ti_
   ===================================================================== */
let TT=null;                                   // tenant en siembra (init corre sin sesión)
const D=()=>TT||T();
const C=k=>D()[k]||[];
const G=(c,id)=>C(c).find(x=>x.id===id);
const CF=()=>D().ti_cfg||{};
const U=(k,d)=>ui[k]===undefined||ui[k]===null||ui[k]===''?d:ui[k];
const pad=n=>String(n).padStart(2,'0');
const hm=s=>{if(!s)return null;const a=String(s).split(':');return(+a[0])*60+(+a[1])};
const mh=m=>{m=((Math.round(m)%1440)+1440)%1440;return pad(Math.floor(m/60))+':'+pad(m%60)};
const hh=m=>{m=Math.round(m||0);const s=m<0?'−':'';m=Math.abs(m);return s+Math.floor(m/60)+' h'+(m%60?' '+pad(m%60):'')};
const nf=v=>String(Math.round(v*100)/100).replace('.',',');
const nowHM=()=>{const d=new Date();return pad(d.getHours())+':'+pad(d.getMinutes())};
const nowMin=()=>hm(nowHM());
const dow=s=>new Date(s+'T12:00:00').getDay();
const DN=['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
const DL=['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
const MES=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const monday=s=>addDays(s,-((dow(s)+6)%7));
const dShort=s=>DN[dow(s)]+' '+s.slice(8,10)+'/'+s.slice(5,7);
const cap=s=>s.charAt(0).toUpperCase()+s.slice(1);
const dLong=s=>cap(DL[dow(s)])+' '+(+s.slice(8,10))+' de '+MES[+s.slice(5,7)-1];
const range=(a,b)=>{const o=[];for(let d=a;d<=b&&o.length<400;d=addDays(d,1))o.push(d);return o};
const addYears=(s,n)=>(+s.slice(0,4)+n)+s.slice(4);
const pl=(n,s,p)=>n+' '+(n===1?s:(p||s+'s'));
const byName=(a,b)=>nm(a).localeCompare(nm(b),'es');
const rl=id=>G('relaciones',id);
const vis=()=>visible().filter(r=>r.estado==='Activo');
const kp=(v,l,c)=>`<div class="kpi"><b class="num"${c?` style="color:var(--${c})"`:''}>${v}</b><span>${l}</span></div>`;
const selC=(key,opts,all,val)=>`<select class="in" data-c="${key}" style="width:auto;max-width:100%" aria-label="${esc(all||key)}">${all?`<option value="">${esc(all)}</option>`:''}${opts.map(o=>{const a=Array.isArray(o)?o:[o,o];return `<option value="${esc(a[0])}"${String(val??'')===String(a[0])?' selected':''}>${esc(a[1])}</option>`}).join('')}</select>`;
const dateC=(key,val,lbl)=>`<input type="date" class="in" data-c="${key}" value="${esc(val)}" style="width:auto" aria-label="${esc(lbl)}">`;
const tabsBar=(key,list)=>`<div class="tabs" style="padding:0 8px">${list.map(t=>`<button class="tab ${ui[key]===t[0]?'on':''}" data-a="ti_tab" data-k="${key}" data-t="${t[0]}">${t[1]}${t[2]?` <span class="pill warn" style="padding:0 7px">${t[2]}</span>`:''}</button>`).join('')}</div>`;
const pickTab=(key,list)=>{if(!list.some(t=>t[0]===ui[key]))ui[key]=list.length?list[0][0]:'';return ui[key]};
const pager=(key,total,ps)=>{const pages=Math.max(1,Math.ceil(total/ps));if(U(key,1)>pages)ui[key]=pages;const p=U(key,1);return{p,pages,html:`<div class="ph" style="border-top:1px solid var(--line);border-bottom:0"><span class="muted num">${pl(total,'registro')} · página ${p} de ${pages}</span><div class="row"><button class="btn s" data-a="ti_pg" data-k="${key}" data-d="-1"${p<=1?' disabled':''}>Anterior</button><button class="btn s" data-a="ti_pg" data-k="${key}" data-d="1"${p>=pages?' disabled':''}>Siguiente</button></div></div>`}};
function csvModal(title,head,rows,ent){const q=v=>'"'+String(v??'').replace(/"/g,'""')+'"';const txt=[head.map(q).join(','),...rows.map(r=>r.map(q).join(','))].join('\n');
 log('Exportación',ent,rows.length+' filas','','','CSV');save();
 openModal(title,`<div style="display:flex;flex-direction:column;gap:10px"><p class="muted">Respeta tus filtros y tu alcance. Copia el contenido y pégalo en Excel o guárdalo como archivo .csv.</p><textarea class="in mono" id="csvt" rows="10" readonly>${esc(txt)}</textarea></div>`,'<button class="btn" data-a="closeM">Cerrar</button><button class="btn p" data-a="copyCsv">Copiar</button>')}

/* ---------- estilos propios (solo variables del núcleo) ---------- */
const css=document.createElement('style');css.textContent=`
.ti-clock{font-family:var(--f-display);font-size:46px;font-weight:700;line-height:1;letter-spacing:-.02em}
.ti-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:10px}
.ti-step{border:1px solid var(--line);border-radius:9px;padding:9px 12px;background:var(--surface2);min-width:0}
.ti-step span{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);display:block}
.ti-step b{display:block;font-family:var(--f-mono);font-size:17px}
.ti-step.on{border-color:var(--accent);background:var(--accent-soft)}
.btn.ti-big{padding:12px 20px;font-size:15px}
tr.ti-x td{color:var(--muted)}tr.ti-x .ti-s{text-decoration:line-through}
.ti-plw{overflow-x:auto;-webkit-overflow-scrolling:touch}
.ti-pl{border-collapse:separate;border-spacing:0;min-width:900px}
.ti-pl th,.ti-pl td{border-bottom:1px solid var(--line);border-left:1px solid var(--line);padding:5px;vertical-align:top}
.ti-pl th{text-align:center}.ti-pl th.hoy{color:var(--accent)}
.ti-pl td.ti-cell{min-width:104px;height:58px}.ti-pl td.ti-ed{cursor:pointer}.ti-pl td.ti-ed:hover{background:var(--surface2)}
.ti-pl td.ti-over{background:var(--accent-soft);outline:2px dashed var(--accent);outline-offset:-2px}
.ti-pl .ti-pp{position:sticky;left:0;background:var(--surface);z-index:1;min-width:150px;max-width:190px;border-left:0;vertical-align:middle;text-align:left}
.ti-pl td.ti-off{background:var(--surface2)}
.ti-chip{display:block;border-radius:6px;padding:3px 7px;font-size:12px;font-weight:600;line-height:1.25;border:1px solid transparent;user-select:none}
.ti-chip[draggable=true]{cursor:grab}
.ti-chip small{display:block;font-weight:500;font-family:var(--f-mono);font-size:10.5px}
.ti-c0{background:var(--accent-soft);color:var(--accent)}.ti-c1{background:var(--ok-soft);color:var(--ok)}.ti-c2{background:var(--warn-soft);color:var(--warn)}.ti-c3{background:var(--crit-soft);color:var(--crit)}.ti-c4{background:var(--surface2);color:var(--muted);border-color:var(--line)}
.ti-pal{display:flex;flex-wrap:wrap;gap:8px;align-items:stretch}
.ti-pal .ti-chip{cursor:pointer;font:inherit;font-size:12px;font-weight:600;text-align:left}
.ti-pal .ti-chip.sel{outline:2px solid var(--accent);outline-offset:2px}
.ti-def{color:var(--muted);font-size:11.5px;display:block}
.ti-warn{color:var(--crit);font-size:11.5px;font-weight:600;display:block;margin-top:2px}
.ti-calw{overflow-x:auto}
.ti-cal{border-collapse:separate;border-spacing:0;width:auto;min-width:100%}
.ti-cal th,.ti-cal td{padding:0;border-bottom:1px solid var(--line);text-align:center;font-size:11px}
.ti-cal th.d,.ti-cal td.d{width:27px;min-width:27px;height:32px;letter-spacing:0;padding:2px 0}
.ti-cal th.d b{display:block;font-size:12px;color:var(--ink)}
.ti-cal .wk{background:var(--surface2)}.ti-cal .hoy{box-shadow:inset 0 2px 0 var(--accent)}
.ti-cal td.sol{background:var(--crit-soft)}
.ti-cal .n{position:sticky;left:0;background:var(--surface);text-align:left;padding:6px 10px;min-width:150px;max-width:190px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px;z-index:1}
.ti-cal i{display:block;height:16px;margin:0 1px;border-radius:3px}
.ti-v{background:var(--accent)}.ti-a{background:var(--warn)}.ti-p{border:1.5px dashed var(--accent)}.ti-q{border:1.5px dashed var(--warn)}
.ti-leg{display:inline-block;width:18px;height:12px;border-radius:3px;vertical-align:middle;margin-right:5px}
.ti-keys{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;max-width:250px;margin:0 auto}
.ti-keys .btn{padding:13px 0;font-size:18px}
.ti-pin{display:flex;gap:10px;justify-content:center;margin:14px 0}
.ti-pin i{width:14px;height:14px;border-radius:50%;border:2px solid var(--muted)}.ti-pin i.on{background:var(--accent);border-color:var(--accent)}
.ti-hin{width:60px;padding:5px 6px;text-align:right}
.ti-day{border:1px solid var(--line);border-radius:9px;padding:10px 12px;background:var(--surface);min-width:0}
.ti-day.hoy{border-color:var(--accent)}
.ti-week{display:grid;grid-template-columns:repeat(auto-fit,minmax(128px,1fr));gap:10px}
tr .ti-nw,.ti-t td.num,.ti-t td.mono,.ti-t th{white-space:nowrap}
.ti-sub>.ph{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.ti-t td:first-child .who{min-width:190px}
.ti-doc{border:1px solid var(--line);border-radius:8px;padding:18px;background:var(--surface2)}
@media (max-width:820px){.ti-clock{font-size:38px}.ti-pl .ti-pp,.ti-cal .n{min-width:118px;max-width:130px}}
`;document.head.appendChild(css);

/* =====================================================================
   DOMINIO: horarios, jornada efectiva, feriados
   ===================================================================== */
const DEFH={id:'',nombre:'Horario general',horasSem:40,horasDia:8,entrada:'09:00',salida:'18:00',refMin:60,tolMin:10,dias:[1,2,3,4,5]};
const METS=[['Web','Marcación desde el navegador con la sesión del colaborador.'],['App','Aplicación móvil con geocerca y selfie de verificación.'],['Kiosko','Equipo compartido en la sede: la persona se identifica con código QR o PIN.'],['Cuadrilla','El supervisor marca la asistencia de su equipo en campo.'],['Biométrico','Reloj de huella o rostro conectado por integración.']];
function easter(y){const a=y%19,b=Math.floor(y/100),c=y%100,d=Math.floor(b/4),e=b%4,f=Math.floor((b+8)/25),g=Math.floor((b-f+1)/3),h=(19*a+b-d-g+15)%30,i=Math.floor(c/4),k=c%4,l=(32+2*e+2*i-h-k)%7,m=Math.floor((a+11*h+22*l)/451),mo=Math.floor((h+l-7*m+114)/31),da=((h+l-7*m+114)%31)+1;return y+'-'+pad(mo)+'-'+pad(da)}
function ferPeru(y){const p=easter(y);return[[y+'-01-01','Año Nuevo'],[addDays(p,-3),'Jueves Santo'],[addDays(p,-2),'Viernes Santo'],[y+'-05-01','Día del Trabajo'],[y+'-06-07','Batalla de Arica y Día de la Bandera'],[y+'-06-29','San Pedro y San Pablo'],[y+'-07-23','Día de la Fuerza Aérea del Perú'],[y+'-07-28','Fiestas Patrias'],[y+'-07-29','Fiestas Patrias'],[y+'-08-06','Batalla de Junín'],[y+'-08-30','Santa Rosa de Lima'],[y+'-10-08','Combate de Angamos'],[y+'-11-01','Día de Todos los Santos'],[y+'-12-08','Inmaculada Concepción'],[y+'-12-09','Batalla de Ayacucho'],[y+'-12-25','Navidad']]}
function polDe(rel){const P=C('ti_politicas').filter(p=>p.activo!==false);return[P.find(p=>p.ambito==='Sede'&&p.refId===rel.sedeId),P.find(p=>p.ambito==='Empresa'&&p.refId===rel.empresaId)]}
function polVal(rel,k){for(const p of polDe(rel))if(p&&p[k]!==''&&p[k]!=null)return p[k];return CF()[k]}
function horarioSrc(rel,f){const a=C('ti_asigHorario').filter(x=>x.relId===rel.id&&x.desde<=f).sort((x,y)=>y.desde.localeCompare(x.desde)||(y.n||0)-(x.n||0))[0];
 if(a&&G('ti_horarios',a.horarioId))return{h:G('ti_horarios',a.horarioId),src:'Individual, desde '+fd(a.desde)};
 const [ps,pe]=polDe(rel);
 if(ps&&ps.horarioId&&G('ti_horarios',ps.horarioId))return{h:G('ti_horarios',ps.horarioId),src:'Política de sede'};
 if(pe&&pe.horarioId&&G('ti_horarios',pe.horarioId))return{h:G('ti_horarios',pe.horarioId),src:'Política de empresa'};
 const g=G('ti_horarios',CF().horarioId)||C('ti_horarios').find(h=>h.activo!==false);
 return{h:g||DEFH,src:'Política general'}}
const horarioOf=(rel,f)=>horarioSrc(rel,f).h;
function feriadoDe(rel,f){const e=G('empresas',rel.empresaId),p=e?e.pais:'Perú';return C('feriados').find(x=>x.fecha===f&&(x.pais||'Perú')===p)||null}
const turnoOf=(relId,f)=>C('ti_turnos').find(x=>x.relId===relId&&x.fecha===f);
function tSpan(t){const ini=hm(t.entrada);let fin=hm(t.salida2||t.salida);if(fin<=ini)fin+=1440;let br=+t.refMin||0;if(t.entrada2&&t.salida2)br+=Math.max(0,hm(t.entrada2)-hm(t.salida));return{ini,fin,br,min:Math.max(0,fin-ini-br)}}
const tHoras=t=>t.libre?'Día libre':t.entrada+'–'+t.salida+(t.entrada2&&t.salida2?' / '+t.entrada2+'–'+t.salida2:'');
function jornada(rel,f){const h=horarioOf(rel,f),tu=turnoOf(rel.id,f),fe=feriadoDe(rel,f);
 if(tu){const t=G('ti_turnoTipos',tu.tipoId);if(t){if(t.libre)return{lab:false,libre:true,turno:t,nombre:t.nombre,tolMin:+h.tolMin||0,fer:fe,h};
   const s=tSpan(t);return{lab:true,turno:t,nombre:t.nombre,entrada:t.entrada,salida:t.salida2||t.salida,refMin:s.br,min:s.min,tolMin:+h.tolMin||0,fer:fe,noct:s.fin>1440,h}}}
 const lab=h.dias.map(Number).includes(dow(f))&&!fe,ini=hm(h.entrada),fin=hm(h.salida);
 return{lab,nombre:h.nombre,entrada:h.entrada,salida:h.salida,refMin:+h.refMin||0,min:(+h.horasDia||8)*60,tolMin:+h.tolMin||0,fer:fe,noct:fin<=ini,h}}
const jTxt=j=>j.lab?j.entrada+'–'+j.salida:(j.fer&&!j.libre?'Feriado':'Descanso');

/* ---------- ausencias y vacaciones vigentes en una fecha ---------- */
const AP=['Aprobada'];
const vacEn=(relId,f,es)=>C('vacSolicitudes').find(v=>v.relId===relId&&v.desde<=f&&v.hasta>=f&&(es||AP).includes(v.estado));
const ausEn=(relId,f,es)=>C('ausencias').find(v=>v.relId===relId&&v.desde<=f&&v.hasta>=f&&(es||AP).includes(v.estado));
const tipoAus=a=>G('ti_ausTipos',a.tipoId)||C('ti_ausTipos').find(t=>t.nombre===a.tipo)||{};

/* ---------- marcaciones ---------- */
const marcDe=(relId,f)=>C('marcaciones').find(m=>m.relId===relId&&m.fecha===f&&m.estado==='Vigente');
function calc(m){const rel=rl(m.relId);m.tardanzaMin=0;m.heMin=0;m.nocMin=0;m.horasMin=0;if(!rel)return m;
 const j=jornada(rel,m.fecha),cf=CF(),e=hm(m.entrada);let s=hm(m.salida);if(e==null)return m;
 if(j.lab&&!m.tardJust){const d=e-hm(j.entrada);if(d>j.tolMin&&d<720)m.tardanzaMin=d}
 if(s!=null){if(s<e)s+=1440;const br=s-e,ri=hm(m.refIni),rf=hm(m.refFin);let ref=0;
  if(ri!=null&&rf!=null)ref=Math.max(0,(rf<ri?rf+1440:rf)-ri);else if(br>=360)ref=j.lab?j.refMin:60;
  m.horasMin=Math.max(0,br-ref);
  const he=j.lab?m.horasMin-j.min:m.horasMin;m.heMin=he>=(+cf.heMinimo||15)?he:0;
  const ni=hm(cf.nocIni||'22:00'),nfi=hm(cf.nocFin||'06:00'),ov=(a,b,c,d)=>Math.max(0,Math.min(b,d)-Math.max(a,c));
  m.nocMin=ov(e,s,0,nfi)+ov(e,s,ni,nfi+1440)+ov(e,s,ni+1440,nfi+2880)}
 return m}
function estadoDia(rel,f){const j=jornada(rel,f),m=marcDe(rel.id,f);
 if(f<rel.ingreso||(rel.cese&&f>rel.cese))return{k:'na',t:'—',c:'',j,m};
 if(m){const td=m.tardanzaMin>0?' · tardanza '+m.tardanzaMin+' min':'';
  if(!m.salida){if(f===TODAY||(f===addDays(TODAY,-1)&&j.noct))return{k:'curso',t:(m.refIni&&!m.refFin?'En refrigerio':'En jornada')+td,c:td?'warn':'acc',j,m};
   return{k:'incompleta',t:'Sin marca de salida',c:'warn',j,m}}
  if(m.tardanzaMin>0)return{k:'tardanza',t:'Tardanza '+m.tardanzaMin+' min',c:'warn',j,m};
  return{k:'ok',t:m.tardJust?'Tardanza justificada':(j.lab?'Puntual':'Día no laborable'),c:'ok',j,m}}
 const v=vacEn(rel.id,f);if(v)return{k:'vac',t:'Vacaciones',c:'acc',j,ref:v};
 const a=ausEn(rel.id,f);if(a)return{k:'aus',t:a.tipo,c:'acc',j,ref:a};
 if(!j.lab)return{k:j.fer&&!j.libre?'feriado':'descanso',t:j.fer&&!j.libre?'Feriado':'Descanso',c:'',j};
 if(f>TODAY)return{k:'futuro',t:'Programado',c:'',j};
 if(f===TODAY)return nowMin()>hm(j.entrada)+j.tolMin?{k:'sinmarcar',t:'Sin marcar',c:'warn',j}:{k:'porIngresar',t:'Por ingresar',c:'',j};
 return{k:'falta',t:'Falta',c:'crit',j}}
function abierta(relId){const m=marcDe(relId,TODAY);if(m)return m;const y=addDays(TODAY,-1),my=marcDe(relId,y);
 if(my&&!my.salida){const r=rl(relId);if(r&&jornada(r,y).noct)return my}return null}
const ML={entrada:'Entrada',refIni:'Inicio de refrigerio',refFin:'Fin de refrigerio',salida:'Salida'};
const MB={entrada:'Marcar entrada',refIni:'Iniciar refrigerio',refFin:'Terminar refrigerio',salida:'Marcar salida'};
function sigMarca(m){if(!m)return['entrada'];if(m.salida)return[];if(m.refIni&&!m.refFin)return['refFin'];if(!m.refIni)return['refIni','salida'];return['salida']}
function metodosDe(sedeId){const t=D();t.ti_metodos=t.ti_metodos||[];return METS.map(x=>{let m=t.ti_metodos.find(y=>y.sedeId===sedeId&&y.metodo===x[0]);
 if(!m){m={id:nid('mt'),sedeId,metodo:x[0],activo:x[0]==='Web',radio:150,selfie:'Sí',pin:'Sí',equipo:'',conexion:''};t.ti_metodos.push(m)}return m})}
const metodoOn=(sedeId,k)=>!!(metodosDe(sedeId).find(m=>m.metodo===k)||{}).activo;
function marcar(rel,tipo,metodo,extra){const t=T();let m=abierta(rel.id);const h=nowHM();
 if(!sigMarca(m).includes(tipo))return 'Esa marcación no corresponde en este momento.';
 if(!m){m={id:nid('mk'),relId:rel.id,fecha:TODAY,entrada:'',salida:'',refIni:'',refFin:'',metodo,tardanzaMin:0,heMin:0,nocMin:0,horasMin:0,estado:'Vigente',eventos:[]};t.marcaciones.push(m)}
 m[tipo]=h;m.eventos=m.eventos||[];m.eventos.push({t:tipo,h,ts:new Date().toISOString(),metodo,por:me().nombre,...(extra||{})});calc(m);
 log('Marcación de '+ML[tipo].toLowerCase(),'Asistencia',nm(rel)+' · '+fd(m.fecha),rel.id,'',h+' · '+metodo);
 emit('attendance.marked',{rel,marcacion:m,tipo});return ''}

/* ---------- vacaciones: saldo y récord por periodo ---------- */
function vacSaldo(rel){const dA=+polVal(rel,'vacDias')||30,fin=rel.cese||TODAY,al=+CF().vacAlertaDias||60;let n=0;while(n<60&&addYears(rel.ingreso,n+1)<=fin)n++;
 const gen=n*dA,truncas=Math.round(dA*Math.max(0,days(addYears(rel.ingreso,n),fin))/365*10)/10;let tom=0,prog=0,tram=0;
 C('ti_vacAjustes').filter(a=>a.relId===rel.id).forEach(a=>tom+=+a.dias||0);
 const uso=(d,h)=>{if(h<=TODAY)tom+=days(d,h)+1;else if(d>TODAY)prog+=days(d,h)+1;else{tom+=days(d,TODAY)+1;prog+=days(TODAY,h)}};
 C('vacSolicitudes').filter(v=>v.relId===rel.id).forEach(v=>{if(v.estado==='Aprobada')uso(v.desde,v.hasta);else if(v.estado==='Pendiente')tram+=+v.dias});
 C('ausencias').filter(a=>a.relId===rel.id&&tipoAus(a).descuentaVac).forEach(a=>{if(a.estado==='Aprobada')uso(a.desde,a.hasta);else if(a.estado==='Pendiente')tram+=+a.dias});
 let rt=tom,rp=prog,venc=0,porV=0,prox='';const per=[];
 for(let i=1;i<=n;i++){const goz=Math.min(dA,Math.max(0,rt));rt-=goz;const pr=Math.min(dA-goz,Math.max(0,rp));rp-=pr;const pend=dA-goz,saldo=pend-pr,limite=addYears(rel.ingreso,i+1);let estado='Pendiente';
  if(pend<=0)estado='Gozado';else if(limite<=TODAY){estado='Vencido';venc+=pend}else if(days(TODAY,limite)<=al&&saldo>0){estado='Por vencer';porV+=saldo;if(!prox)prox=limite}else if(saldo<=0)estado='Programado';
  per.push({n:i,desde:addYears(rel.ingreso,i-1),hasta:addDays(addYears(rel.ingreso,i),-1),gen:dA,goz,pr,saldo,limite,estado})}
 const disp=gen-tom-prog,sinCarga=n>=2&&tom===0&&prog===0&&!C('ti_vacAjustes').some(a=>a.relId===rel.id);return{dA,n,gen,tom,prog,tram,disp,solic:disp-tram,venc,porV,prox,truncas,per,sinCarga}}
const saldoBolsa=relId=>Math.round(C('ti_bolsa').filter(b=>b.relId===relId).reduce((s,b)=>s+(+b.horas||0),0)*100)/100;

/* ---------- conflictos de turnos ---------- */
function conflictos(rels,desde,hasta){const out=[],minR=(+CF().descansoMinH||12)*60;
 rels.forEach(r=>{range(desde,hasta).forEach(f=>{const tu=turnoOf(r.id,f);if(!tu)return;const t=G('ti_turnoTipos',tu.tipoId);if(!t||t.libre)return;
  const v=vacEn(r.id,f),a=!v&&ausEn(r.id,f);
  if(v)out.push({relId:r.id,fecha:f,t:'Turno asignado durante sus vacaciones aprobadas'});
  else if(a&&!tipoAus(a).presencial)out.push({relId:r.id,fecha:f,t:'Turno asignado durante «'+a.tipo+'» aprobada'});
  const y=addDays(f,-1),jy=jornada(r,y),j=jornada(r,f);
  if(jy.lab&&j.lab&&!vacEn(r.id,y)){const fy=hm(jy.salida)<=hm(jy.entrada)?hm(jy.salida)+1440:hm(jy.salida),rest=1440+hm(j.entrada)-fy;
   if(rest<minR)out.push({relId:r.id,fecha:f,t:'Solo '+hh(Math.max(0,rest))+' de descanso tras el turno anterior (mínimo '+(minR/60)+' h)'})}})});
 return out}

/* =====================================================================
   SOLICITUDES: un solo motor de estados para vacaciones, ausencias,
   horas extra, correcciones/incidencias y hojas de tiempo
   ===================================================================== */
const jefeUser=rel=>{const j=rel.jefeId&&rl(rel.jefeId);return j&&j.estado==='Activo'?T().usuarios.find(u=>u.activo&&u.personaId===j.personaId)||null:null};
const etapaIni=rel=>{const j=rel.jefeId&&rl(rel.jefeId);return j&&j.estado==='Activo'?'Jefe':'RRHH'};
const hist=(s,accion,nota)=>{(s.hist=s.hist||[]).push({ts:new Date().toISOString(),quien:me().nombre,accion,nota:nota||''})};
const hojaTot=s=>(s.lineas||[]).reduce((a,l)=>a+l.h.reduce((x,y)=>x+(+y||0),0),0);
const corHoras=s=>s.tipo==='Tardanza justificada'?'':[s.entrada&&'entrada '+s.entrada,s.refIni&&'refrigerio '+s.refIni+(s.refFin?'–'+s.refFin:''),s.salida&&'salida '+s.salida].filter(Boolean).join(' · ');
const K={
 vac:{col:'vacSolicitudes',pre:'vs',lbl:()=>'Vacaciones',ent:'Vacaciones',route:'ti_vacaciones',tk:'ti_vTab',
  tit:s=>'Vacaciones · '+pl(s.dias,'día'),det:s=>fd(s.desde)+' al '+fd(s.hasta),
  rrhh:s=>polVal(rl(s.relId),'vacValidaRRHH')==='Sí',
  ok:s=>emit('leave.approved',{rel:rl(s.relId),tipo:'Vacaciones',solicitud:s,desde:s.desde,hasta:s.hasta,dias:s.dias,conGoce:true})},
 aus:{col:'ausencias',pre:'au',lbl:()=>'Permisos',ent:'Ausencias y permisos',route:'ti_ausencias',tk:'ti_uTab',
  tit:s=>s.tipo+' · '+pl(s.dias,'día'),det:s=>s.desde===s.hasta?fd(s.desde):fd(s.desde)+' al '+fd(s.hasta),
  rrhh:s=>!!tipoAus(s).validaRRHH,
  chk:s=>{const t=tipoAus(s);if(t.usaBolsa){const h=s.dias*(+horarioOf(rl(s.relId),s.desde).horasDia||8);if(saldoBolsa(s.relId)<h)return 'La bolsa de horas de '+relName(s.relId)+' ('+nf(saldoBolsa(s.relId))+' h) no cubre las '+nf(h)+' h de este descanso.'}return ''},
  ok:s=>{const t=tipoAus(s),r=rl(s.relId);if(t.usaBolsa){const h=s.dias*(+horarioOf(r,s.desde).horasDia||8);T().ti_bolsa.push({id:nid('bh'),relId:s.relId,fecha:TODAY,horas:-h,concepto:'Descanso compensatorio del '+fd(s.desde)+(s.dias>1?' al '+fd(s.hasta):''),refId:s.id,por:me().nombre});s.bolsaH=h}
   emit('leave.approved',{rel:r,tipo:s.tipo,solicitud:s,desde:s.desde,hasta:s.hasta,dias:s.dias,conGoce:!!t.goce})},
  undo:s=>{if(s.bolsaH){T().ti_bolsa.push({id:nid('bh'),relId:s.relId,fecha:TODAY,horas:s.bolsaH,concepto:'Devolución por anulación de descanso compensatorio',refId:s.id,por:me().nombre});s.bolsaH=0}}},
 he:{col:'ti_heSolicitudes',pre:'he',lbl:()=>'Horas extra',ent:'Horas extra',route:'ti_extras',tk:'ti_eTab',
  tit:s=>'Horas extra · '+nf(s.horas)+' h',det:s=>fd(s.fecha)+' · '+s.compensacion,rrhh:()=>false,
  ok:s=>emit('overtime.approved',{rel:rl(s.relId),solicitud:s}),
  undo:s=>{if(s.acreditada){T().ti_bolsa.push({id:nid('bh'),relId:s.relId,fecha:TODAY,horas:-s.horasAcred,concepto:'Reverso por anulación de horas extra del '+fd(s.fecha),refId:s.id,por:me().nombre});s.acreditada=false}}},
 cor:{col:'ti_correcciones',pre:'co',lbl:s=>s.tipo==='Corrección de marcación'?'Correcciones':'Incidencias',ent:'Asistencia',route:'ti_asistencia',tk:'ti_aTab',
  tit:s=>s.tipo,det:s=>fd(s.fecha)+(corHoras(s)?' · '+corHoras(s):''),rrhh:()=>false,ok:s=>aplicarCor(s)},
 hoja:{col:'ti_hojas',pre:'hj',lbl:()=>'Hojas de tiempo',ent:'Hoja de tiempo',route:'ti_hojas',tk:'ti_hTab',tsol:'apr',
  tit:s=>'Hoja de tiempo · '+nf(hojaTot(s))+' h',det:s=>'Semana del '+fd(s.semana)+' al '+fd(addDays(s.semana,6)),rrhh:()=>false}};
const findReq=(k,id)=>C(K[k].col).find(x=>x.id===id);
function puede(k,s){const r=rl(s.relId),mine=myRel();if(!r||s.estado!=='Pendiente')return false;if(mine&&mine.id===r.id)return false;
 if(can('tiempo.validar'))return scoped().includes(r);
 return s.etapa==='Jefe'&&can('tiempo.aprobar')&&!!mine&&r.jefeId===mine.id}
function meToca(k,s){if(!puede(k,s))return false;const r=rl(s.relId),mine=myRel();
 if(s.etapa==='Jefe'){if(mine&&r.jefeId===mine.id&&can('tiempo.aprobar'))return true;return can('tiempo.validar')&&!jefeUser(r)}
 return can('tiempo.validar')}
function puedeAnular(k,s){if(k==='cor'||k==='hoja')return false;const r=rl(s.relId);if(!r)return false;const ini=s.desde||s.fecha;
 if(s.estado==='Pendiente')return own(r)||can('tiempo.administrar');
 if(s.estado==='Aprobada'){if((s.hasta||s.fecha)<TODAY)return false;return can('tiempo.administrar')||(own(r)&&ini>TODAY)}return false}
const bandeja=k=>C(K[k].col).filter(s=>s.estado==='Pendiente'&&meToca(k,s));
const nBandeja=ks=>ks.reduce((n,k)=>n+bandeja(k).length,0);
function etapaTxt(s){const r=rl(s.relId);if(s.etapa==='RRHH')return 'Validación de RRHH';const ju=r&&jefeUser(r);return r&&r.jefeId?'Jefe directo: '+relName(r.jefeId)+(ju?'':' (sin usuario: resuelve RRHH)'):'RRHH'}
function stReq(k,s){if(s.estado==='Pendiente')return pill(k==='hoja'?'Enviada':'Pendiente','warn')+`<div class="muted" style="font-size:12px;margin-top:2px">${esc(etapaTxt(s))}</div>`;
 if(s.estado==='Aprobada')return pill('Aprobada','ok');if(s.estado==='Rechazada')return pill('Rechazada','crit')+(s.motivoRechazo?`<div class="muted" style="font-size:12px;margin-top:2px;max-width:240px">${esc(s.motivoRechazo)}</div>`:'');
 return pill(s.estado,'')}
function reqActs(k,s){let h='';if(puede(k,s))h+=`<button class="btn s p" data-a="ti_${k}Aprobar" data-id="${s.id}">Aprobar</button> <button class="btn s d" data-a="ti_${k}Rechazar" data-id="${s.id}">Rechazar</button> `;
 if(puedeAnular(k,s))h+=`<button class="btn s" data-a="ti_anular" data-k="${k}" data-id="${s.id}">Anular</button>`;return h}
function reqTable(k,items,o){o=o||{};if(!items.length)return '';const d=K[k];
 return `<div class="tw"><table class="ti-t"><thead><tr>${o.sinQuien?'':'<th>Colaborador</th>'}<th>Solicitud</th><th>Fechas</th><th>Estado</th><th>Solicitada</th><th></th></tr></thead><tbody>${items.map(s=>{const r=rl(s.relId);
  return `<tr class="click" data-a="ti_det" data-k="${k}" data-id="${s.id}">${o.sinQuien?'':`<td>${r?whoCell(r):'—'}</td>`}<td><b style="white-space:nowrap">${esc(d.tit(s))}</b>${s.motivo||s.comentario?`<div class="muted" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.motivo||s.comentario)}</div>`:''}</td><td class="num" style="white-space:nowrap">${esc(d.det(s))}</td><td>${stReq(k,s)}</td><td class="num muted">${fd(s.creada)}</td><td style="text-align:right;white-space:nowrap">${reqActs(k,s)}</td></tr>`}).join('')}</tbody></table></div>`}
const ordReq=a=>a.slice().sort((x,y)=>(x.estado==='Pendiente'?0:1)-(y.estado==='Pendiente'?0:1)||String(y.creada).localeCompare(String(x.creada))||String(y.id).localeCompare(String(x.id)));
function aprobar(k,id){const d=K[k],s=findReq(k,id);closeM();if(!s||s.estado!=='Pendiente'){toast('Esta solicitud ya fue resuelta');return render()}
 if(!puede(k,s))return toast('No te corresponde aprobar esta solicitud');
 const er=d.chk&&d.chk(s);if(er)return toast(er);
 const r=rl(s.relId),val=can('tiempo.validar');
 if(s.etapa==='Jefe'&&!val&&d.rrhh(s)){s.etapa='RRHH';hist(s,'Aprobó como jefe directo');log('Aprobación del jefe directo',d.ent,nm(r)+' · '+d.tit(s),r.id,'Pendiente (jefe)','Pendiente (validación de RRHH)');toast('Aprobada. Pasa a validación de RRHH')}
 else{s.estado='Aprobada';hist(s,s.etapa==='RRHH'?'Validó como RRHH':(val&&d.rrhh(s)?'Aprobó y validó como RRHH':'Aprobó'));s.etapa='';s.resuelta=TODAY;s.resueltaPor=me().nombre;if(d.ok)d.ok(s);
  log('Aprobación',d.ent,nm(r)+' · '+d.tit(s),r.id,'Pendiente','Aprobada · '+d.det(s));toast(k==='cor'?'Aprobada: se creó un registro nuevo y el original quedó trazado':'Solicitud aprobada')}
 save();render()}
function rechazar(k,id){const d=K[k],s=findReq(k,id);if(!s||s.estado!=='Pendiente'){toast('Esta solicitud ya fue resuelta');return}
 if(!puede(k,s))return toast('No te corresponde resolver esta solicitud');const r=rl(s.relId);
 openForm(()=>({title:'Rechazar · '+d.tit(s),ok:'Rechazar solicitud',note:`<b>${esc(nm(r))}</b> · ${esc(d.det(s))}<br>El motivo se muestra a la persona que la solicitó.`,
  fields:[{k:'motivo',l:'Motivo del rechazo',type:'textarea',req:1,full:1}],
  submit:o=>{if(s.estado!=='Pendiente')return 'Esta solicitud ya fue resuelta.';s.estado='Rechazada';s.etapa='';s.motivoRechazo=o.motivo;s.resuelta=TODAY;s.resueltaPor=me().nombre;hist(s,'Rechazó',o.motivo);
   log('Rechazo',d.ent,nm(r)+' · '+d.tit(s),r.id,'Pendiente','Rechazada · '+o.motivo);toast('Solicitud rechazada')}}),{})}
function anular(k,id){const d=K[k],s=findReq(k,id);if(!s||!puedeAnular(k,s))return toast('Esta solicitud ya no se puede anular');const r=rl(s.relId),ap=s.estado==='Aprobada';
 openForm(()=>({title:'Anular · '+d.tit(s),ok:'Anular solicitud',note:`<b>${esc(nm(r))}</b> · ${esc(d.det(s))}<br>${ap?'Estaba aprobada: al anularla los días u horas vuelven al saldo y sale del calendario.':'Aún no fue aprobada: se retira de la bandeja de quien debía resolverla.'}`,
  fields:[{k:'motivo',l:'Motivo de la anulación',type:'textarea',req:1,full:1}],
  submit:o=>{const antes=s.estado;if(ap&&d.undo)d.undo(s);s.estado='Anulada';s.etapa='';s.motivoAnulacion=o.motivo;hist(s,'Anuló',o.motivo);
   log('Anulación',d.ent,nm(r)+' · '+d.tit(s),r.id,antes,'Anulada · '+o.motivo);emit('leave.cancelled',{rel:r,solicitud:s,tipo:d.ent});toast('Solicitud anulada')}}),{})}
function detalle(k,id){const d=K[k],s=findReq(k,id);if(!s)return;const r=rl(s.relId);if(!r||!(scoped().includes(r)||own(r)))return toast('Esta solicitud está fuera de tu alcance');
 const dd=(l,v)=>v===''||v==null?'':`<div><dt>${l}</dt><dd>${v}</dd></div>`;let extra='';
 if(k==='vac'){const sv=vacSaldo(r);extra=dd('Días calendario',s.dias)+dd('Saldo disponible hoy',pl(sv.disp,'día'))+dd('Comentario',esc(s.comentario||''))}
 if(k==='aus'){const t=tipoAus(s);extra=dd('Tipo',esc(s.tipo))+dd('Días',s.dias)+dd('Goce de haber',t.goce?'Con goce':'Sin goce')+dd('Sustento',s.sustento?esc(s.sustento)+' <span class="muted">(adjunto simulado)</span>':(t.sustento?'<span class="pill warn">Falta sustento</span>':'No requiere'))+dd('Motivo',esc(s.motivo||''))}
 if(k==='he'){const m=marcDe(s.relId,s.fecha);extra=dd('Fecha',fd(s.fecha))+dd('Horas autorizadas',nf(s.horas)+' h')+dd('Compensación',esc(s.compensacion))+dd('Horas extra marcadas ese día',m?hh(m.heMin):'Sin marcación')+(s.compensacion==='Bolsa de horas'?dd('Bolsa de horas',s.acreditada?'Acreditadas '+nf(s.horasAcred)+' h':'Aún sin acreditar'):'')+dd('Motivo',esc(s.motivo||''))}
 if(k==='cor'){const m=s.origenId?G('marcaciones',s.origenId):marcDe(s.relId,s.fecha);extra=dd('Fecha',fd(s.fecha))+dd('Registro original',m?esc((m.entrada||'—')+' a '+(m.salida||'sin salida')):'Sin marcación')+dd('Se solicita',esc(corHoras(s)||'Considerar la tardanza como justificada'))+dd('Motivo',esc(s.motivo||''))}
 if(k==='hoja'){extra=dd('Semana',fd(s.semana)+' al '+fd(addDays(s.semana,6)))+dd('Total',nf(hojaTot(s))+' h')+`<div style="grid-column:1/-1"><dt>Detalle</dt><dd>${(s.lineas||[]).map(l=>{const p=G('ti_proyectos',l.proyectoId);return esc((p?p.codigo+' · '+p.nombre:'Proyecto')+': '+nf(l.h.reduce((a,b)=>a+(+b||0),0))+' h')}).join('<br>')}</dd></div>`}
 const body=`<div style="display:flex;flex-direction:column;gap:16px"><div class="row" style="justify-content:space-between">${whoCell(r)}<div>${stReq(k,s)}</div></div><dl>${dd('Solicitud',esc(d.tit(s)))}${dd('Fechas',esc(d.det(s)))}${extra}${s.motivoAnulacion?dd('Motivo de anulación',esc(s.motivoAnulacion)):''}</dl>
 <div><h3 style="margin-bottom:10px">Recorrido de la solicitud</h3><ul class="tl">${(s.hist||[]).map(h=>`<li><div class="row"><b>${esc(h.quien)}</b><span class="mono muted">${fd(h.ts.slice(0,10))} ${h.ts.slice(11,16)}</span></div><div>${esc(h.accion)}${h.nota?' · <span class="muted">'+esc(h.nota)+'</span>':''}</div></li>`).join('')||'<li>Sin movimientos registrados.</li>'}${s.estado==='Pendiente'?`<li><span class="muted">En espera · ${esc(etapaTxt(s))}</span></li>`:''}</ul></div></div>`;
 openModal(d.tit(s),body,`<button class="btn" data-a="closeM">Cerrar</button>${reqActs(k,s)}`)}

/* ---------- corrección aprobada: registro nuevo, original trazado ---------- */
function aplicarCor(s){const t=T(),old=marcDe(s.relId,s.fecha);
 const n={id:nid('mk'),relId:s.relId,fecha:s.fecha,entrada:'',salida:'',refIni:'',refFin:'',metodo:'Regularización',tardanzaMin:0,heMin:0,nocMin:0,horasMin:0,estado:'Vigente',eventos:[],origenId:'',correccionId:s.id,corregido:true};
 if(old){Object.assign(n,{entrada:old.entrada,salida:old.salida,refIni:old.refIni,refFin:old.refFin,metodo:old.metodo,origenId:old.id,eventos:(old.eventos||[]).slice(),tardJust:!!old.tardJust});old.estado='Reemplazada';old.reemplazadaPor=n.id}
 if(s.tipo==='Tardanza justificada')n.tardJust=true;else['entrada','refIni','refFin','salida'].forEach(x=>{if(s[x])n[x]=s[x]});
 n.eventos.push({t:'correccion',h:nowHM(),ts:new Date().toISOString(),metodo:s.tipo,por:me().nombre,nota:s.motivo});
 t.marcaciones.push(n);calc(n);s.nuevoId=n.id;s.origenId=old?old.id:''}

/* ---------- formularios de solicitud ---------- */
function solapes(r,desde,hasta,exId){const u=uniOf(r),out=[];
 actRels().forEach(x=>{if(x.id===r.id)return;const xu=uniOf(x);if(!((u&&xu&&u.id===xu.id)||(r.jefeId&&x.jefeId===r.jefeId)||x.jefeId===r.id||r.jefeId===x.id))return;
  const v=C('vacSolicitudes').find(s=>s.id!==exId&&s.relId===x.id&&['Aprobada','Pendiente'].includes(s.estado)&&s.desde<=hasta&&s.hasta>=desde)||C('ausencias').find(s=>s.relId===x.id&&s.estado==='Aprobada'&&!tipoAus(s).presencial&&s.desde<=hasta&&s.hasta>=desde);
  if(v)out.push(nm(x)+' ('+fd(v.desde)+' al '+fd(v.hasta)+(v.estado==='Pendiente'?', en trámite':'')+')')});return out}
const actRels=()=>T().relaciones.filter(r=>r.estado==='Activo');
const cruza=(relId,desde,hasta,exId)=>C('vacSolicitudes').find(s=>s.id!==exId&&s.relId===relId&&['Aprobada','Pendiente'].includes(s.estado)&&s.desde<=hasta&&s.hasta>=desde)||C('ausencias').find(s=>s.id!==exId&&s.relId===relId&&['Aprobada','Pendiente'].includes(s.estado)&&s.desde<=hasta&&s.hasta>=desde);
const paraQuien=(adm)=>adm?vis().sort(byName).map(r=>[r.id,nm(r)]):[];
function destino(r){const e=etapaIni(r);return e==='RRHH'?'Recursos Humanos':relName(r.jefeId)+(jefeUser(r)?'':' (sin usuario: la verá RRHH)')}
function formVac(pre){const mine=myRel(),adm=can('tiempo.administrar');if(!mine&&!adm)return toast('Tu usuario no está vinculado a una ficha de colaborador');
 openForm(v=>{const r=rl(v.relId)||mine,sv=r?vacSaldo(r):null,d=v.desde&&v.hasta&&v.hasta>=v.desde?days(v.desde,v.hasta)+1:0;
  let note=r?`Saldo de <b>${esc(nm(r))}</b>: <b>${sv.disp}</b> días disponibles${sv.tram?` (${sv.tram} ya en trámite)`:''}. Se cuentan días calendario.`:'Elige a la persona.';
  if(d&&r){note+=`<br>Solicitas <b>${pl(d,'día')}</b>; quedarían <b>${sv.solic-d}</b>.`;const so=solapes(r,v.desde,v.hasta);if(so.length)note+=`<br><b>Coincide con:</b> ${esc(so.join('; '))}. Puedes enviarla igual; quien aprueba lo verá.`}
  const regul=adm&&r&&!own(r)&&can('tiempo.validar');
  return{title:'Solicitar vacaciones',ok:v.modo==='Aprobada'?'Registrar':'Enviar solicitud',note,
  fields:[...(adm?[{k:'relId',l:'Colaborador',opts:paraQuien(adm),req:1,dyn:1,keep:1}]:[]),{k:'desde',l:'Primer día',type:'date',req:1,dyn:1},{k:'hasta',l:'Último día',type:'date',req:1,dyn:1},
   ...(regul?[{k:'modo',l:'Trámite',opts:[['Pendiente','Enviar a aprobación'],['Aprobada','Registrar como aprobada (regularización)']],req:1,keep:1,dyn:1}]:[]),{k:'comentario',l:'Comentario para quien aprueba',type:'textarea',full:1}],
  submit:o=>{if(!r)return 'Elige a la persona.';if(o.hasta<o.desde)return 'El último día no puede ser anterior al primero.';const dir=regul&&o.modo==='Aprobada';
   if(!adm&&o.desde<TODAY)return 'No puedes solicitar vacaciones con fecha pasada. Pide a RRHH que las regularice.';
   const ant=+CF().vacAnticipacion||0;if(!adm&&days(TODAY,o.desde)<ant)return 'Las vacaciones se piden con al menos '+pl(ant,'día')+' de anticipación.';
   if(o.desde<r.ingreso)return 'La fecha es anterior al ingreso de la persona.';
   const x=cruza(r.id,o.desde,o.hasta);if(x)return 'Se cruza con otra solicitud '+x.estado.toLowerCase()+' del '+fd(x.desde)+' al '+fd(x.hasta)+'.';
   const n=days(o.desde,o.hasta)+1;if(n>sv.solic)return 'Solo hay '+pl(Math.max(0,sv.solic),'día')+' para solicitar (disponibles '+sv.disp+', en trámite '+sv.tram+').';
   const s={id:nid('vs'),relId:r.id,desde:o.desde,hasta:o.hasta,dias:n,estado:dir?'Aprobada':'Pendiente',etapa:dir?'':etapaIni(r),comentario:o.comentario||'',creada:TODAY,hist:[]};
   hist(s,own(r)?'Solicitó vacaciones':'Registró la solicitud en nombre de '+nm(r),o.comentario);T().vacSolicitudes.push(s);
   if(dir){hist(s,'Registró como aprobada (regularización)');s.resuelta=TODAY;s.resueltaPor=me().nombre;K.vac.ok(s)}
   log(dir?'Registro de vacaciones':'Solicitud de vacaciones','Vacaciones',nm(r)+' · '+pl(n,'día'),r.id,'',K.vac.det(s));toast(dir?'Vacaciones registradas':'Solicitud enviada a '+destino(r))}}},{relId:pre||(mine?mine.id:''),modo:'Pendiente'})}
function formAus(pre,tipoPre){const mine=myRel(),adm=can('tiempo.administrar');if(!mine&&!adm)return toast('Tu usuario no está vinculado a una ficha de colaborador');
 const tipos=C('ti_ausTipos').filter(t=>t.activo!==false);if(!tipos.length)return toast('Aún no hay tipos de ausencia configurados');
 openForm(v=>{const r=rl(v.relId)||mine,t=G('ti_ausTipos',v.tipoId)||tipos[0],d=v.desde&&v.hasta&&v.hasta>=v.desde?days(v.desde,v.hasta)+1:0;
  const rules=[t.goce?'Con goce de haber':'Sin goce de haber',t.sustento?'requiere sustento':'no requiere sustento',t.maxDias?'máximo '+pl(+t.maxDias,'día')+' por solicitud':'sin tope de días',t.descuentaVac?'descuenta del saldo de vacaciones':'',t.usaBolsa?'se descuenta de la bolsa de horas':'',t.validaRRHH?'la valida RRHH después del jefe':''].filter(Boolean);
  let note=`<b>${esc(t.nombre)}:</b> ${esc(rules.join(' · '))}.`;if(t.usaBolsa&&r)note+=`<br>Bolsa de horas de ${esc(nm(r))}: <b>${nf(saldoBolsa(r.id))} h</b>.`;if(d)note+=`<br>Solicitas <b>${pl(d,'día')}</b>.`;
  const regul=adm&&r&&!own(r)&&can('tiempo.validar');
  return{title:'Solicitar permiso o registrar ausencia',ok:v.modo==='Aprobada'?'Registrar':'Enviar solicitud',note,
  fields:[...(adm?[{k:'relId',l:'Colaborador',opts:paraQuien(adm),req:1,dyn:1,keep:1}]:[]),{k:'tipoId',l:'Tipo',opts:tipos.map(x=>[x.id,x.nombre]),req:1,keep:1,dyn:1},{k:'desde',l:'Desde',type:'date',req:1,dyn:1},{k:'hasta',l:'Hasta',type:'date',req:1,dyn:1},
   ...(t.sustento?[{k:'sustento',l:'Sustento: nombre del documento (adjunto simulado)',req:1,full:1}]:[]),
   ...(regul?[{k:'modo',l:'Trámite',opts:[['Pendiente','Enviar a aprobación'],['Aprobada','Registrar como aprobada']],req:1,keep:1,dyn:1}]:[]),{k:'motivo',l:'Motivo',type:'textarea',req:1,full:1}],
  submit:o=>{if(!r)return 'Elige a la persona.';if(o.hasta<o.desde)return 'La fecha final no puede ser anterior a la inicial.';const n=days(o.desde,o.hasta)+1,dir=regul&&o.modo==='Aprobada';
   if(t.maxDias&&n>+t.maxDias)return '«'+t.nombre+'» admite como máximo '+pl(+t.maxDias,'día')+' por solicitud.';
   if(o.desde<r.ingreso)return 'La fecha es anterior al ingreso de la persona.';
   const x=cruza(r.id,o.desde,o.hasta);if(x)return 'Se cruza con otra solicitud '+x.estado.toLowerCase()+' del '+fd(x.desde)+' al '+fd(x.hasta)+'.';
   if(t.descuentaVac&&n>vacSaldo(r).solic)return 'Este tipo descuenta de vacaciones y solo hay '+pl(Math.max(0,vacSaldo(r).solic),'día')+' disponibles.';
   const s={id:nid('au'),relId:r.id,tipoId:t.id,tipo:t.nombre,desde:o.desde,hasta:o.hasta,dias:n,estado:'Pendiente',etapa:etapaIni(r),motivo:o.motivo,sustento:t.sustento?o.sustento:'',creada:TODAY,hist:[]};
   const er=K.aus.chk(s);if(er)return er;
   hist(s,own(r)?'Solicitó '+t.nombre.toLowerCase():'Registró la solicitud en nombre de '+nm(r),o.motivo);T().ausencias.push(s);
   if(dir){s.estado='Aprobada';s.etapa='';hist(s,'Registró como aprobada');s.resuelta=TODAY;s.resueltaPor=me().nombre;K.aus.ok(s)}
   log(dir?'Registro de ausencia':'Solicitud de permiso','Ausencias y permisos',nm(r)+' · '+t.nombre,r.id,'',K.aus.det(s)+' · '+pl(n,'día'));toast(dir?'Ausencia registrada':'Solicitud enviada a '+destino(r))}}},{relId:pre||(mine?mine.id:''),tipoId:tipoPre||tipos[0].id,modo:'Pendiente'})}
function formHE(){const mine=myRel(),adm=can('tiempo.administrar');if(!mine&&!adm)return toast('Tu usuario no está vinculado a una ficha de colaborador');
 openForm(v=>{const r=rl(v.relId)||mine,tope=+CF().heTopeDia||4;
  return{title:'Solicitar autorización de horas extra',ok:'Enviar solicitud',note:`Las horas extra se autorizan <b>antes</b> de trabajarlas. Tope por día: ${tope} h. Si eliges bolsa de horas, se acreditan cuando la marcación confirme el tiempo trabajado y luego se compensan con descanso.`,
  fields:[...(adm?[{k:'relId',l:'Colaborador',opts:paraQuien(adm),req:1,keep:1}]:[]),{k:'fecha',l:'Fecha',type:'date',req:1},{k:'horas',l:'Horas',type:'number',req:1},{k:'compensacion',l:'Compensación',opts:['Pago con sobretasa','Bolsa de horas'],req:1,keep:1},{k:'motivo',l:'Motivo',type:'textarea',req:1,full:1}],
  submit:o=>{const rr=rl(o.relId)||mine;if(!rr)return 'Elige a la persona.';const h=Number(o.horas);if(!(h>0))return 'Indica cuántas horas necesitas.';if(h>tope)return 'El tope es de '+tope+' horas extra por día.';
   if(!adm&&o.fecha<TODAY)return 'La autorización es previa: elige hoy o una fecha futura.';
   if(C('ti_heSolicitudes').some(x=>x.relId===rr.id&&x.fecha===o.fecha&&['Pendiente','Aprobada'].includes(x.estado)))return 'Ya existe una solicitud de horas extra para esa fecha.';
   const s={id:nid('he'),relId:rr.id,fecha:o.fecha,horas:h,compensacion:o.compensacion,motivo:o.motivo,estado:'Pendiente',etapa:etapaIni(rr),creada:TODAY,hist:[]};hist(s,own(rr)?'Solicitó horas extra':'Registró la solicitud en nombre de '+nm(rr),o.motivo);T().ti_heSolicitudes.push(s);
   log('Solicitud de horas extra','Horas extra',nm(rr)+' · '+nf(h)+' h',rr.id,'',fd(o.fecha)+' · '+o.compensacion);toast('Solicitud enviada a '+destino(rr))}}},{relId:mine?mine.id:'',fecha:TODAY,horas:2,compensacion:'Pago con sobretasa'})}
const CT=['Olvidé marcar','Corrección de marcación','Tardanza justificada'];
function formCor(pre){pre=pre||{};const mine=myRel(),adm=can('tiempo.administrar');if(!mine&&!adm)return toast('Tu usuario no está vinculado a una ficha de colaborador');
 openForm(v=>{const r=rl(v.relId)||mine,m=r&&v.fecha?marcDe(r.id,v.fecha):null,tj=v.tipo==='Tardanza justificada';
  let note='El registro de asistencia no se modifica. Si se aprueba, se crea un <b>registro nuevo</b> y el original queda tachado, con quién lo pidió, quién lo aprobó y el motivo.';
  if(r&&v.fecha)note+='<br>Registro actual del '+fd(v.fecha)+': <b>'+(m?esc((m.entrada||'sin entrada')+' a '+(m.salida||'sin salida'))+(m.tardanzaMin?' · tardanza '+m.tardanzaMin+' min':''):'sin marcación')+'</b>.';
  return{title:'Reportar incidencia de asistencia',ok:'Enviar solicitud',note,
  fields:[...(adm?[{k:'relId',l:'Colaborador',opts:paraQuien(adm),req:1,keep:1,dyn:1}]:[]),{k:'tipo',l:'Qué pasó',opts:CT,req:1,keep:1,dyn:1},{k:'fecha',l:'Fecha',type:'date',req:1,dyn:1},
   ...(tj?[]:[{k:'entrada',l:'Entrada correcta',type:'time'},{k:'salida',l:'Salida correcta',type:'time'},{k:'refIni',l:'Inicio de refrigerio',type:'time'},{k:'refFin',l:'Fin de refrigerio',type:'time'}]),{k:'motivo',l:'Motivo',type:'textarea',req:1,full:1}],
  submit:o=>{if(!r)return 'Elige a la persona.';if(o.fecha>TODAY)return 'La fecha no puede ser futura.';if(o.fecha<r.ingreso)return 'La fecha es anterior al ingreso de la persona.';const mm=marcDe(r.id,o.fecha);
   if(C('ti_correcciones').some(x=>x.relId===r.id&&x.fecha===o.fecha&&x.estado==='Pendiente'))return 'Ya hay una solicitud pendiente para ese día.';
   if(o.tipo==='Tardanza justificada'){if(!mm||!(mm.tardanzaMin>0))return 'Ese día no tiene una tardanza registrada.'}
   else{if(!o.entrada&&!o.salida&&!o.refIni&&!o.refFin)return 'Indica al menos una hora correcta.';
    if(o.tipo==='Corrección de marcación'&&!mm)return 'Ese día no tiene marcación. Elige «Olvidé marcar».';
    if(!mm&&!o.entrada)return 'Ese día no tiene marcación: indica la hora de entrada.';
    if((o.refIni&&!o.refFin&&!(mm&&mm.refFin))||(o.refFin&&!o.refIni&&!(mm&&mm.refIni)))return 'Indica el inicio y el fin del refrigerio.'}
   const s={id:nid('co'),relId:r.id,tipo:o.tipo,fecha:o.fecha,entrada:tj?'':o.entrada||'',salida:tj?'':o.salida||'',refIni:tj?'':o.refIni||'',refFin:tj?'':o.refFin||'',motivo:o.motivo,estado:'Pendiente',etapa:etapaIni(r),creada:TODAY,hist:[]};
   hist(s,own(r)?'Reportó la incidencia':'Registró la incidencia en nombre de '+nm(r),o.motivo);T().ti_correcciones.push(s);
   log('Solicitud de corrección de asistencia','Asistencia',nm(r)+' · '+fd(o.fecha),r.id,mm?(mm.entrada||'—')+' a '+(mm.salida||'—'):'Sin marcación',o.tipo+(corHoras(s)?' · '+corHoras(s):''));toast('Solicitud enviada a '+destino(r))}}},
  {relId:pre.rel||(mine?mine.id:''),tipo:pre.tipo||CT[0],fecha:pre.fecha||addDays(TODAY,-1)})}

/* =====================================================================
   VISTAS · ASISTENCIA
   ===================================================================== */
const ESTS=['Pendiente','Aprobada','Rechazada','Anulada'];
function solPanel(k,o){o=o||{};const sc=scoped(),fk='ti_f_'+k,est=U(fk,'');
 let it=C(K[k].col).filter(s=>{const r=rl(s.relId);return r&&sc.includes(r)&&(!ui.emp||r.empresaId===ui.emp)&&(!o.f||o.f(s))});
 const np=it.filter(s=>s.estado==='Pendiente').length,tot=it.length;it=ordReq(it.filter(s=>!est||s.estado===est));const ps=12,pg=pager('ti_pg_'+k,it.length,ps);
 return `<div class="ph"><div class="row">${selC(fk,o.ests||ESTS,'Todos los estados',est)}<span class="muted">${np?pl(np,'pendiente'):'Nada pendiente'}</span></div><div class="row">${o.btn||''}</div></div>
 ${it.length?reqTable(k,it.slice((pg.p-1)*ps,pg.p*ps),{sinQuien:role().alcance==='propio'})+pg.html:emptyState(tot?'Ninguna solicitud con ese estado':(o.et||'Aún no hay solicitudes'),tot?'Cambia el filtro para ver las demás.':(o.ed||''),tot?'':(o.eb||''))}`}
function clockPanel(rel){const m=abierta(rel.id),f=m?m.fecha:TODAY,j=jornada(rel,f),sig=sigMarca(m),e=estadoDia(rel,f),web=metodoOn(rel.sedeId,'Web')&&can('tiempo.asistencia.marcar');
 const steps=['entrada','refIni','refFin','salida'].map(k=>`<div class="ti-step ${m&&m[k]?'on':''}"><span>${ML[k]}</span><b>${m&&m[k]?m[k]:'—:—'}</b></div>`).join('');
 const btns=!web?'<span class="pill warn">La marcación web no está habilitada para tu sede. Usa el método indicado por tu empresa.</span>':sig.length?sig.map((k,i)=>`<button class="btn ${sig.length===1||i===0?'p':''} ti-big" data-a="ti_marcar" data-t="${k}">${MB[k]}</button>`).join(''):'<span class="pill ok">Jornada de hoy completa</span>';
 return `<div class="pb" style="display:flex;flex-direction:column;gap:14px"><div class="row" style="justify-content:space-between;align-items:flex-end"><div><div class="muted">${dLong(TODAY)}</div><div class="ti-clock num" data-ti-clock>${nowHM()}</div></div>${pill(e.t,e.c)}</div>
 <div class="muted">${j.lab?esc(j.nombre)+' · '+j.entrada+'–'+j.salida+' · tolerancia '+j.tolMin+' min':'Hoy no tienes jornada programada'}${m&&m.salida?` · trabajado <b>${hh(m.horasMin)}</b>${m.heMin?' · extra '+hh(m.heMin):''}`:''}</div>
 <div class="ti-steps">${steps}</div><div class="row">${btns}</div></div>`}
function dayCells(e){const m=e.m;return `<td class="mono">${m&&m.entrada?m.entrada:'—'}</td><td class="mono">${m&&m.refIni?m.refIni+'–'+(m.refFin||'…'):'—'}</td><td class="mono">${m&&m.salida?m.salida:'—'}</td><td class="num">${m&&m.salida?hh(m.horasMin):'—'}</td>`}
const corPend=(relId,f)=>C('ti_correcciones').find(x=>x.relId===relId&&x.fecha===f&&x.estado==='Pendiente');
function vMia(){const r=myRel();if(!r)return `<div class="panel">${emptyState('Tu usuario no está vinculado a una ficha','Pide a Recursos Humanos que vincule tu usuario con tu ficha de colaborador para marcar asistencia.')}</div>`;
 const wk=range(monday(TODAY),TODAY),hs=wk.reduce((s,f)=>{const m=marcDe(r.id,f);return s+(m?m.horasMin:0)},0),h=horarioOf(r,TODAY);
 const m0=TODAY.slice(0,8)+'01',ms=range(m0,TODAY).map(f=>estadoDia(r,f)),tard=ms.filter(e=>e.m&&e.m.tardanzaMin>0).length,fal=ms.filter(e=>e.k==='falta').length,he=ms.reduce((s,e)=>s+(e.m?e.m.heMin:0),0);
 const ds=range(addDays(TODAY,-13),TODAY).reverse().filter(f=>f>=r.ingreso);
 return `<div class="panel"><div class="ph"><h2>Mi jornada de hoy</h2><span class="muted">Método: web · hora del sistema</span></div>${clockPanel(r)}</div>
 <div class="kpis">${kp(hh(hs),'Trabajado esta semana · jornada de '+h.horasSem+' h')}${kp(tard,'Tardanzas en '+MES[+TODAY.slice(5,7)-1],tard?'warn':'')}${kp(fal,'Faltas en '+MES[+TODAY.slice(5,7)-1],fal?'crit':'')}${kp(hh(he),'Horas extra del mes')}</div>
 <div class="panel"><div class="ph"><h2>Mis últimos 14 días</h2><span class="muted">El registro no se edita: si algo está mal, repórtalo.</span></div><div class="tw"><table class="ti-t"><thead><tr><th>Fecha</th><th>Jornada</th><th>Entrada</th><th>Refrigerio</th><th>Salida</th><th>Horas</th><th>Estado</th><th></th></tr></thead><tbody>${ds.map(f=>{const e=estadoDia(r,f),cp=corPend(r.id,f);
  const tp=e.k==='tardanza'?CT[2]:e.k==='ok'||e.k==='curso'?CT[1]:CT[0],show=['falta','incompleta','tardanza','ok','sinmarcar'].includes(e.k);
  return `<tr><td style="white-space:nowrap">${dShort(f)}</td><td class="muted">${esc(jTxt(e.j))}</td>${dayCells(e)}<td>${pill(e.t,e.c)}</td><td style="text-align:right">${cp?pill('En revisión','warn'):show?`<button class="btn s" data-a="ti_cor" data-fecha="${f}" data-tipo="${tp}">Reportar</button>`:''}</td></tr>`}).join('')}</tbody></table></div></div>`}
function vHoy(){const f=U('ti_fecha',TODAY),sede=U('ti_bSede',''),fe=U('ti_bEst','');
 let rows=vis().filter(r=>(!sede||r.sedeId===sede)&&f>=r.ingreso).sort(byName).map(r=>({r,e:estadoDia(r,f)}));
 const grp=x=>['ok','tardanza','curso','incompleta'].includes(x.e.k)?'presentes':['falta','sinmarcar'].includes(x.e.k)?'faltas':['vac','aus'].includes(x.e.k)?'justificados':['descanso','feriado'].includes(x.e.k)?'descanso':'espera';
 const n=g=>rows.filter(x=>grp(x)===g).length,tard=rows.filter(x=>x.e.m&&x.e.m.tardanzaMin>0).length,prog=rows.filter(x=>x.e.j.lab&&!['vac','aus'].includes(x.e.k)).length;
 const all=rows.length;if(fe)rows=rows.filter(x=>fe==='tardanza'?x.e.m&&x.e.m.tardanzaMin>0:grp(x)===fe);
 return `<div class="ph"><div class="row">${dateC('ti_fecha',f,'Fecha')}${selC('ti_bSede',opt('sedes'),'Todas las sedes',sede)}${selC('ti_bEst',[['presentes','Presentes'],['tardanza','Con tardanza'],['faltas','Faltas o sin marcar'],['justificados','Ausentes justificados'],['descanso','En descanso'],['espera','Por ingresar']],'Todos los estados',fe)}</div><span class="muted">${dLong(f)}${f===TODAY?' · '+nowHM():''}</span></div>
 <div class="kpis" style="border:0;border-bottom:1px solid var(--line);border-radius:0">${kp(n('presentes')+' <span class="muted" style="font-size:15px">/ '+prog+'</span>','Presentes de los programados')}${kp(tard,'Tardanzas',tard?'warn':'')}${kp(n('faltas'),f===TODAY?'Sin marcar a esta hora':'Faltas',n('faltas')?'crit':'')}${kp(n('justificados'),'Ausentes justificados')}</div>
 ${rows.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Colaborador</th><th>Jornada</th><th>Entrada</th><th>Refrigerio</th><th>Salida</th><th>Horas</th><th>Estado</th><th>Método</th></tr></thead><tbody>${rows.map(x=>`<tr class="click" data-a="ti_verReg" data-id="${x.r.id}"><td>${whoCell(x.r)}</td><td class="muted" style="white-space:nowrap">${esc(jTxt(x.e.j))}</td>${dayCells(x.e)}<td>${pill(x.e.t,x.e.c)}</td><td class="muted">${x.e.m?esc(x.e.m.metodo)+(x.e.m.corregido?' · corregido':''):'—'}</td></tr>`).join('')}</tbody></table></div>`
 :emptyState(all?'Nadie coincide con los filtros':'No hay personas en tu alcance para esta fecha',all?'Quita el filtro de sede o de estado.':'Revisa la fecha o el filtro de empresa de la barra superior.','<button class="btn" data-a="ti_clr" data-k="ti_fecha,ti_bSede,ti_bEst">Limpiar filtros</button>')}`}
function regRows(){const d0=U('ti_rDesde',addDays(TODAY,-13)),d1=U('ti_rHasta',TODAY),rid=U('ti_rRel',''),fe=U('ti_rEst',''),rep=!!ui.ti_rRep;
 const rs=vis().filter(r=>!rid||r.id===rid).sort(byName),ds=d1>=d0?range(d0,d1).reverse():[],out=[];
 ds.forEach(f=>rs.forEach(r=>{if(f<r.ingreso)return;const e=estadoDia(r,f);
  if(!e.m&&['descanso','feriado','na','futuro','porIngresar'].includes(e.k))return;
  const ok=!fe||(fe==='tardanza'?e.m&&e.m.tardanzaMin>0:fe==='he'?e.m&&e.m.heMin>0:fe==='noc'?e.m&&e.m.nocMin>0:fe==='falta'?['falta','sinmarcar'].includes(e.k):fe==='incompleta'?e.k==='incompleta':fe==='just'?['vac','aus'].includes(e.k):fe==='corr'?e.m&&e.m.corregido:true);
  if(ok)out.push({r,f,e,m:e.m});
  if(rep&&(!fe||fe==='corr'))C('marcaciones').filter(m=>m.relId===r.id&&m.fecha===f&&m.estado!=='Vigente').forEach(m=>out.push({r,f,m,x:true,e:{t:'Reemplazado',c:'',j:e.j}}))}));
 return{rows:out,d0,d1,rid,fe,rep}}
function vReg(){const R=regRows(),rows=R.rows,ps=15,pg=pager('ti_pgReg',rows.length,ps),vg=rows.filter(x=>!x.x);
 const tot=k=>vg.reduce((s,x)=>s+(x.m?x.m[k]||0:0),0),nt=vg.filter(x=>x.m&&x.m.tardanzaMin>0).length,nfal=vg.filter(x=>x.e.k==='falta').length;
 return `<div class="ph"><div class="row">${selC('ti_rRel',vis().sort(byName).map(r=>[r.id,nm(r)]),'Todos los colaboradores',R.rid)}${dateC('ti_rDesde',R.d0,'Desde')}${dateC('ti_rHasta',R.d1,'Hasta')}
 ${selC('ti_rEst',[['tardanza','Con tardanza'],['falta','Faltas'],['incompleta','Sin marca de salida'],['he','Con horas extra'],['noc','Con trabajo nocturno'],['just','Ausencias justificadas'],['corr','Corregidos']],'Todo',R.fe)}
 <label class="row" style="gap:6px"><input type="checkbox" class="chk" data-chk="ti_rRep"${R.rep?' checked':''}>Ver registros reemplazados</label></div></div>
 <div class="kpis" style="border:0;border-bottom:1px solid var(--line);border-radius:0">${kp(hh(tot('horasMin')),'Horas trabajadas')}${kp(nt+' <span class="muted" style="font-size:15px">· '+tot('tardanzaMin')+' min</span>','Tardanzas',nt?'warn':'')}${kp(nfal,'Faltas',nfal?'crit':'')}${kp(hh(tot('heMin')),'Horas extra')}${kp(hh(tot('nocMin')),'Trabajo nocturno')}</div>
 ${rows.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Fecha</th><th>Colaborador</th><th>Jornada</th><th>Entrada</th><th>Refrigerio</th><th>Salida</th><th>Horas</th><th>Extra</th><th>Nocturno</th><th>Estado</th><th>Método</th><th></th></tr></thead><tbody>${rows.slice((pg.p-1)*ps,pg.p*ps).map(x=>{const m=x.m;
  return `<tr class="${x.x?'ti-x':''}"><td style="white-space:nowrap" class="num">${dShort(x.f)}</td><td class="ti-nw"><b>${esc(nm(x.r))}</b></td><td class="muted" style="white-space:nowrap">${esc(jTxt(x.e.j))}</td><td class="mono ti-s">${m&&m.entrada?m.entrada:'—'}</td><td class="mono ti-s">${m&&m.refIni?m.refIni+'–'+(m.refFin||'…'):'—'}</td><td class="mono ti-s">${m&&m.salida?m.salida:'—'}</td><td class="num ti-s">${m&&m.salida?hh(m.horasMin):'—'}</td><td class="num ti-s">${m&&m.heMin?hh(m.heMin):'—'}</td><td class="num ti-s">${m&&m.nocMin?hh(m.nocMin):'—'}</td><td>${pill(x.e.t,x.e.c)}</td><td class="muted">${m?esc(m.metodo)+(m.corregido?' · corregido':''):'—'}</td><td style="text-align:right;white-space:nowrap">${m?`<button class="btn s" data-a="ti_traza" data-id="${m.id}">Trazabilidad</button>`:''}</td></tr>`}).join('')}</tbody></table></div>${pg.html}`
 :emptyState('No hay registros en ese rango','Amplía las fechas o quita los filtros. Los días de descanso y feriados sin marcación no se listan.','<button class="btn" data-a="ti_clr" data-k="ti_rRel,ti_rDesde,ti_rHasta,ti_rEst">Limpiar filtros</button>')}`}
function traza(id){const m=G('marcaciones',id);if(!m)return;const r=rl(m.relId);if(!r||!scoped().includes(r))return toast('Fuera de tu alcance');
 const chain=[];let x=m;while(x&&x.origenId&&chain.length<20){x=G('marcaciones',x.origenId);if(x)chain.unshift(x)}chain.push(m);x=m;while(x&&x.reemplazadaPor&&chain.length<40){x=G('marcaciones',x.reemplazadaPor);if(x)chain.push(x)}
 const co=id2=>C('ti_correcciones').find(c=>c.nuevoId===id2);
 openModal('Trazabilidad · '+nm(r)+' · '+fd(m.fecha),`<div style="display:flex;flex-direction:column;gap:16px"><div class="note">El registro de asistencia es inalterable (DS 004-2006-TR): ninguna marcación se edita ni se borra. Cada corrección aprobada crea un registro nuevo y deja el anterior a la vista.</div>
 <div class="tw"><table class="ti-t"><thead><tr><th>Versión</th><th>Entrada</th><th>Refrigerio</th><th>Salida</th><th>Horas</th><th>Estado</th></tr></thead><tbody>${chain.map((c,i)=>`<tr class="${c.estado==='Vigente'?'':'ti-x'}"><td>${i+1}${c.id===m.id?' ·':''}</td><td class="mono ti-s">${c.entrada||'—'}</td><td class="mono ti-s">${c.refIni?c.refIni+'–'+(c.refFin||'…'):'—'}</td><td class="mono ti-s">${c.salida||'—'}</td><td class="num ti-s">${c.salida?hh(c.horasMin):'—'}</td><td>${c.estado==='Vigente'?pill('Vigente','ok'):pill('Reemplazado','')}${co(c.id)?`<div class="muted" style="font-size:12px">Por «${esc(co(c.id).tipo)}» · aprobó ${esc(co(c.id).resueltaPor||'')}</div>`:''}</td></tr>`).join('')}</tbody></table></div>
 <div><h3 style="margin-bottom:10px">Eventos del registro</h3><ul class="tl">${(m.eventos||[]).map(ev=>`<li><div class="row"><b>${esc(ev.t==='correccion'?'Corrección aprobada':ML[ev.t]||ev.t)}</b><span class="mono">${esc(ev.h)}</span></div><div class="muted">${esc(ev.metodo)}${ev.por?' · '+esc(ev.por):''}${ev.det?' · '+esc(ev.det):''}${ev.nota?' · '+esc(ev.nota):''}</div></li>`).join('')||'<li class="muted">Registro migrado: sin eventos detallados.</li>'}</ul></div></div>`,null,true)}
/* cuadrilla */
function supRel(){const mine=myRel(),subs=id=>T().relaciones.filter(x=>x.jefeId===id&&x.estado==='Activo');
 if(can('tiempo.administrar')){const sups=vis().filter(r=>subs(r.id).length).sort(byName);const cur=sups.find(r=>r.id===ui.ti_cqSup)||(mine&&sups.find(r=>r.id===mine.id))||sups[0];return{sup:cur,sups,team:cur?subs(cur.id).sort(byName):[]}}
 return{sup:mine,sups:[],team:mine?subs(mine.id).sort(byName):[]}}
function vCuad(){const S=supRel();if(!S.sup||!S.team.length)return emptyState('No tienes personas a cargo','La marcación por cuadrilla es para supervisores: marcan la asistencia de sus reportes directos cuando el equipo trabaja en campo o sin acceso a un equipo propio.');
 const off=!metodoOn(S.sup.sedeId,'Cuadrilla');
 return `<div class="ph"><div class="row">${S.sups.length?selC('ti_cqSup',S.sups.map(r=>[r.id,nm(r)+' ('+T().relaciones.filter(x=>x.jefeId===r.id&&x.estado==='Activo').length+')']),'',S.sup.id):`<b>Equipo de ${esc(nm(S.sup))}</b>`}<span class="muted">Hora de registro: <b class="mono" data-ti-clock>${nowHM()}</b></span></div></div>
 <div class="pb" style="padding-bottom:0"><div class="note">Marca a varias personas a la vez. Cada registro guarda quién lo marcó y que el método fue «Cuadrilla».${off?' <b>En la sede de este supervisor el método está desactivado</b>: actívalo en «Métodos».':''}</div></div>
 <div class="tw"><table class="ti-t"><thead><tr><th style="width:34px"><input type="checkbox" class="chk" data-ti-all aria-label="Seleccionar a todos"></th><th>Colaborador</th><th>Jornada de hoy</th><th>Entrada</th><th>Salida</th><th>Estado</th><th>Siguiente marcación</th></tr></thead><tbody>${S.team.map(r=>{const e=estadoDia(r,TODAY),m=abierta(r.id),sg=sigMarca(m),aus=['vac','aus'].includes(e.k);
  return `<tr><td><input type="checkbox" class="chk" data-ti-cq="${r.id}"${aus||!sg.length?' disabled':''} aria-label="Seleccionar a ${esc(nm(r))}"></td><td>${whoCell(r)}</td><td class="muted">${esc(jTxt(e.j))}</td><td class="mono">${m&&m.entrada||'—'}</td><td class="mono">${m&&m.salida||'—'}</td><td>${pill(e.t,e.c)}</td><td class="muted">${aus?'No corresponde':sg.length?sg.map(k=>ML[k]).join(' o '):'Jornada completa'}</td></tr>`}).join('')}</tbody></table></div>
 <div class="ph" style="border-top:1px solid var(--line);border-bottom:0"><span class="muted">Selecciona personas y elige la marcación.</span><div class="row">${['entrada','refIni','refFin','salida'].map(k=>`<button class="btn ${k==='entrada'?'p':''}"${off?' disabled':''} data-a="ti_cuad" data-t="${k}">${MB[k]}</button>`).join('')}</div></div>`}
/* kiosko */
const pinDe=r=>String(per(r).doc||'').replace(/\D/g,'').slice(-4).padStart(4,'0');
function vKio(){const sedes=opt('sedes',s=>!ui.emp||s.empresaId===ui.emp),sede=sedes.some(s=>s[0]===ui.ti_kSede)?ui.ti_kSede:(sedes[0]||[''])[0];
 if(!sede)return emptyState('Aún no hay sedes','El kiosko se abre por sede. Crea primero una sede en Estructura.');
 const on=metodoOn(sede,'Kiosko'),ps=T().relaciones.filter(r=>r.estado==='Activo'&&r.sedeId===sede).sort(byName);
 return `<div class="ph"><div class="row">${selC('ti_kSede',sedes,'',sede)}<span class="muted">Hora: <b class="mono" data-ti-clock>${nowHM()}</b></span></div>${on?pill('Kiosko activo en esta sede','ok'):pill('Kiosko desactivado en esta sede','warn')}</div>
 <div class="pb" style="display:flex;flex-direction:column;gap:14px"><div class="note">Modo kiosko: pantalla compartida en la entrada de la sede. Cada persona toca su nombre (o muestra su código QR) y confirma con su PIN. <b>PIN de demostración: los 4 últimos dígitos de su documento.</b></div>
 ${!on?emptyState('Activa el kiosko para esta sede','Hazlo en la pestaña «Métodos». Mientras tanto nadie puede marcar desde aquí.'):ps.length?`<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">${ps.map(r=>{const e=estadoDia(r,TODAY);return `<button class="node" style="justify-content:space-between;width:100%" data-a="ti_kSel" data-id="${r.id}">${whoCell(r)}${pill(e.k==='curso'?'Dentro':e.k==='ok'||e.k==='tardanza'?'Salió':['vac','aus'].includes(e.k)?'Ausente':'Fuera',e.k==='curso'?'ok':'')}</button>`}).join('')}</div>`:emptyState('Nadie trabaja en esta sede','Cuando haya colaboradores activos en la sede, aparecerán aquí.')}</div>`}
function kioModal(){const r=rl(ui.ti_kRel);if(!r)return;const pin=ui.ti_kPin||'',ok=ui.ti_kOk,m=abierta(r.id),sig=sigMarca(m);
 const body=ok?`<div style="display:flex;flex-direction:column;gap:14px;align-items:center;text-align:center">${whoCell(r)}<div class="ti-clock num" data-ti-clock>${nowHM()}</div>${sig.length?`<div class="row" style="justify-content:center">${sig.map(k=>`<button class="btn p ti-big" data-a="ti_kMark" data-t="${k}">${MB[k]}</button>`).join('')}</div>`:'<span class="pill ok">Ya completó su jornada de hoy</span>'}</div>`
 :`<div style="text-align:center">${whoCell(r)}<p class="muted" style="margin-top:10px">Ingresa tu PIN de 4 dígitos</p><div class="ti-pin">${[0,1,2,3].map(i=>`<i class="${i<pin.length?'on':''}"></i>`).join('')}</div>${ui.ti_kErr?`<div class="err" style="margin-bottom:12px">${esc(ui.ti_kErr)}</div>`:''}
 <div class="ti-keys">${[1,2,3,4,5,6,7,8,9].map(n=>`<button class="btn" data-a="ti_kDig" data-d="${n}">${n}</button>`).join('')}<button class="btn ghost" data-a="ti_kDig" data-d="x" aria-label="Borrar">⌫</button><button class="btn" data-a="ti_kDig" data-d="0">0</button><span></span></div></div>`;
 openModal('Kiosko · '+(get('sedes',r.sedeId)||{}).nombre,body,'<button class="btn" data-a="closeM">Cancelar</button>')}
/* métodos por sede */
function vMet(){const sedes=T().sedes.filter(s=>s.activo!==false&&(!ui.emp||s.empresaId===ui.emp));if(!sedes.length)return emptyState('Aún no hay sedes','Los métodos de marcación se configuran por sede. Crea primero una sede en Estructura.');
 return `<div class="pb" style="display:flex;flex-direction:column;gap:16px"><div class="note">Cada sede decide con qué métodos se puede marcar. Web, kiosko con PIN y cuadrilla funcionan en este prototipo; la app móvil y el reloj biométrico se muestran con su configuración y una lectura simulada.</div>
 ${sedes.map(s=>`<div><h3 style="margin-bottom:8px">${esc(s.nombre)} <span class="muted" style="font-weight:500">· ${esc((get('empresas',s.empresaId)||{}).nombre||'')}</span></h3><div class="tw"><table class="ti-t"><thead><tr><th>Método</th><th>Estado</th><th>Configuración</th><th></th></tr></thead><tbody>${metodosDe(s.id).map(m=>{const d=METS.find(x=>x[0]===m.metodo)[1];
  const cf=m.metodo==='App'?'Geocerca de '+m.radio+' m · selfie: '+m.selfie:m.metodo==='Kiosko'?'PIN obligatorio: '+m.pin:m.metodo==='Biométrico'?(m.equipo?'Equipo: '+m.equipo+' · '+(m.conexion||'sin probar'):'Sin equipo conectado'):m.metodo==='Cuadrilla'?'Solo reportes directos del supervisor':'Sesión del colaborador';
  return `<tr><td><b>${m.metodo}</b><div class="muted" style="max-width:340px">${d}</div></td><td>${m.activo?pill('Activo','ok'):pill('Inactivo','')}</td><td class="muted">${esc(cf)}</td><td style="text-align:right;white-space:nowrap">${m.metodo==='Biométrico'&&m.activo?`<button class="btn s" data-a="ti_bioSim" data-id="${m.id}">Simular lectura</button> `:''}<button class="btn s" data-a="ti_metEdit" data-id="${m.id}">Configurar</button></td></tr>`}).join('')}</tbody></table></div></div>`).join('')}</div>`}
function vAsistencia(){const mine=myRel(),eq=role().alcance!=='propio'&&can('tiempo.asistencia.ver'),S=can('tiempo.cuadrilla')?supRel():null,nc=nBandeja(['cor']);
 const tabs=[...(mine&&can('tiempo.asistencia.marcar')?[['mia','Mi asistencia']]:[]),...(eq?[['hoy','Tablero del día'],['reg','Registro']]:[]),['sol','Correcciones e incidencias',nc],...(S&&S.team.length?[['cua','Cuadrilla']]:[]),...(can('tiempo.kiosko')?[['kio','Kiosko']]:[]),...(can('tiempo.configurar')?[['met','Métodos']]:[])];
 const t=pickTab('ti_aTab',tabs);
 const acts=(t==='reg'&&can('datos.exportar')?'<button class="btn" data-a="ti_regCsv">Exportar CSV</button>':'')+(mine||can('tiempo.administrar')?'<button class="btn p" data-a="ti_cor">Reportar incidencia</button>':'');
 const body=t==='mia'?null:t==='hoy'?vHoy():t==='reg'?vReg():t==='cua'?vCuad():t==='kio'?vKio():t==='met'?vMet():
  solPanel('cor',{et:'Sin correcciones ni incidencias',ed:'Cuando alguien olvide marcar, pida corregir una hora o justifique una tardanza, la solicitud aparecerá aquí para aprobarla.',eb:mine?'<button class="btn p" data-a="ti_cor">Reportar incidencia</button>':''});
 return pageHead('Tiempo','Asistencia',acts,eq?'Registro inalterable: las correcciones se piden, se aprueban y quedan trazadas.':'Marca tu jornada y revisa tu registro.')+
 (t==='mia'?`<div class="tabs">${tabs.map(x=>`<button class="tab ${t===x[0]?'on':''}" data-a="ti_tab" data-k="ti_aTab" data-t="${x[0]}">${x[1]}${x[2]?` <span class="pill warn" style="padding:0 7px">${x[2]}</span>`:''}</button>`).join('')}</div>${vMia()}`:`<div class="panel">${tabsBar('ti_aTab',tabs)}${body}</div>`)}

/* =====================================================================
   VISTAS · TURNOS, HORARIOS, POLÍTICAS Y FERIADOS
   ===================================================================== */
const COLN=['Principal','Verde','Ámbar','Rojo','Neutro'];
const tipChip=(t,drag,extra)=>`<span class="ti-chip ti-c${+t.color||0}"${drag?` draggable="true" data-ti-drag="${drag}"`:''}>${esc(t.nombre)}<small>${esc(tHoras(t))}</small>${extra||''}</span>`;
function planRels(){const sede=U('ti_pSede',''),solo=!!ui.ti_pSolo;return vis().filter(r=>(!sede||r.sedeId===sede)&&(!solo||C('ti_turnos').some(t=>t.relId===r.id))).sort(byName)}
function setTurno(relId,f,tipoId,quiet){const t=T(),i=t.ti_turnos.findIndex(x=>x.relId===relId&&x.fecha===f),antes=i>=0?(G('ti_turnoTipos',t.ti_turnos[i].tipoId)||{}).nombre:'';
 if(i>=0)t.ti_turnos.splice(i,1);if(tipoId)t.ti_turnos.push({id:nid('tu'),relId,fecha:f,tipoId});
 const m=marcDe(relId,f);if(m)calc(m);
 if(!quiet)log(tipoId?'Asignación de turno':'Retiro de turno','Turnos',relName(relId)+' · '+fd(f),relId,antes||'Horario habitual',tipoId?(G('ti_turnoTipos',tipoId)||{}).nombre:'Horario habitual')}
function avisoTurno(relId,f){const r=rl(relId),c=conflictos([r],f,addDays(f,1)).filter(x=>x.relId===relId);if(c.length)toast('Aviso: '+c[0].t.toLowerCase());}
function vPlan(){const adm=can('tiempo.turnos.administrar'),sem=U('ti_sem',monday(TODAY)),ds=range(sem,addDays(sem,6)),rs=planRels(),tipos=C('ti_turnoTipos').filter(t=>t.activo!==false);
 const cf=conflictos(rs,sem,addDays(sem,6)),cmap={};cf.forEach(c=>{(cmap[c.relId+'|'+c.fecha]=cmap[c.relId+'|'+c.fecha]||[]).push(c.t)});
 const pin=ui.ti_pincel||'';
 const head=`<div class="ph"><div class="row"><button class="btn s" data-a="ti_semMov" data-d="-7" aria-label="Semana anterior">◀</button><b class="num" style="white-space:nowrap">${fd(sem)} – ${fd(addDays(sem,6))}</b><button class="btn s" data-a="ti_semMov" data-d="7" aria-label="Semana siguiente">▶</button><button class="btn s" data-a="ti_semMov" data-d="0">Esta semana</button>
 ${selC('ti_pSede',opt('sedes'),'Todas las sedes',U('ti_pSede',''))}<label class="row" style="gap:6px"><input type="checkbox" class="chk" data-chk="ti_pSolo"${ui.ti_pSolo?' checked':''}>Solo personal con turnos</label></div>
 ${adm?`<div class="row"><button class="btn s" data-a="ti_copiaSem">Copiar semana anterior</button><button class="btn s d" data-a="ti_limpiaSem">Limpiar semana</button><button class="btn s p" data-a="ti_masivo">Asignación masiva</button></div>`:''}</div>`;
 if(!tipos.length)return head+emptyState('Aún no hay tipos de turno','Crea los turnos de tu operación (mañana, tarde, noche, partido) y luego asígnalos en esta grilla.',can('tiempo.configurar')?'<button class="btn p" data-a="ti_tipoNew">Nuevo tipo de turno</button><button class="btn" data-a="ti_plantilla">Cargar plantilla Perú</button>':'');
 const pal=adm?`<div class="pb" style="border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:8px"><div class="ti-pal">${tipos.map(t=>`<button class="ti-chip ti-c${+t.color||0} ${pin===t.id?'sel':''}" draggable="true" data-ti-drag="tipo:${t.id}" data-a="ti_pincel" data-id="${t.id}">${esc(t.nombre)}<small>${esc(tHoras(t))}</small></button>`).join('')}<button class="ti-chip ti-c4 ${pin==='__del'?'sel':''}" data-a="ti_pincel" data-id="__del">Quitar turno<small>vuelve a su horario</small></button></div>
 <span class="muted">${pin?'<b>Modo pincel:</b> toca las celdas para '+(pin==='__del'?'quitar el turno':'asignar «'+esc((G('ti_turnoTipos',pin)||{}).nombre||'')+'»')+'. Toca de nuevo el turno para soltar el pincel.':'Arrastra un turno a una celda, o tócalo para usarlo como pincel (práctico en el celular). También puedes mover un turno ya asignado arrastrándolo a otra celda.'}</span></div>`:'';
 const grid=rs.length?`<div class="ti-plw"><table class="ti-pl"><thead><tr><th class="ti-pp">Colaborador</th>${ds.map(d=>`<th class="${d===TODAY?'hoy':''}">${dShort(d)}</th>`).join('')}</tr></thead><tbody>${rs.map(r=>`<tr><td class="ti-pp"><b style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(nm(r))}</b><span class="muted" style="font-size:12px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc((get('sedes',r.sedeId)||{}).nombre||'')}</span></td>${ds.map(d=>{const tu=turnoOf(r.id,d),t=tu&&G('ti_turnoTipos',tu.tipoId),j=jornada(r,d),v=vacEn(r.id,d),a=!v&&ausEn(r.id,d),w=cmap[r.id+'|'+d];
   return `<td class="ti-cell ${adm?'ti-ed':''} ${!t&&!j.lab?'ti-off':''}" ${adm?`data-ti-cell="${r.id}|${d}" data-a="ti_cell" data-rel="${r.id}" data-f="${d}"`:''}>${t?tipChip(t,adm?'cell:'+r.id+'|'+d:''):`<span class="ti-def">${j.lab?j.entrada+'–'+j.salida:(j.fer?'Feriado':'Descanso')}</span>`}${v?'<span class="pill acc" style="font-size:11px;margin-top:3px">Vacaciones</span>':a?`<span class="pill acc" style="font-size:11px;margin-top:3px;max-width:100px;overflow:hidden;text-overflow:ellipsis">${esc(a.tipo)}</span>`:''}${w?`<span class="ti-warn" title="${esc(w.join(' · '))}">▲ Conflicto</span>`:''}</td>`}).join('')}</tr>`).join('')}</tbody></table></div>`
  :emptyState('Nadie coincide con el filtro','Quita el filtro de sede o desmarca «Solo personal con turnos» para planificar a más personas.','<button class="btn" data-a="ti_clr" data-k="ti_pSede,ti_pSolo">Ver a todos</button>');
 const cl=cf.length?`<div class="pb" style="border-top:1px solid var(--line)"><h3 style="margin-bottom:6px">Conflictos de la semana <span class="pill crit">${cf.length}</span></h3>${cf.map(c=>`<div class="alert"><span class="dot crit"></span><div><b>${esc(relName(c.relId))} · ${dShort(c.fecha)}</b><div class="muted">${esc(c.t)}</div></div></div>`).join('')}</div>`:(rs.length?'<div class="pb muted" style="border-top:1px solid var(--line)">Sin conflictos esta semana: se respetan el descanso mínimo entre turnos y las vacaciones aprobadas.</div>':'');
 return head+pal+grid+cl}
function vMiHorario(){const r=myRel();if(!r)return emptyState('Tu usuario no está vinculado a una ficha','Pide a Recursos Humanos que vincule tu usuario con tu ficha de colaborador.');
 const hs=horarioSrc(r,TODAY),h=hs.h,sem=monday(TODAY),fers=C('feriados').filter(f=>f.fecha>=TODAY&&feriadoDe(r,f.fecha)).sort((a,b)=>a.fecha.localeCompare(b.fecha)).slice(0,4);
 const wk=s=>`<div class="ti-week">${range(s,addDays(s,6)).map(d=>{const j=jornada(r,d),v=vacEn(r.id,d),a=!v&&ausEn(r.id,d);return `<div class="ti-day ${d===TODAY?'hoy':''}"><span class="muted" style="font-size:12px">${dShort(d)}</span><b style="display:block">${v?'Vacaciones':a?esc(a.tipo):j.lab?j.entrada+'–'+j.salida:(j.fer&&!j.libre?'Feriado':'Descanso')}</b><span class="muted" style="font-size:12px">${j.turno?'Turno '+esc(j.turno.nombre):j.fer?esc(j.fer.nombre):j.lab?'Horario habitual':''}</span></div>`}).join('')}</div>`;
 return `<div class="pb" style="display:flex;flex-direction:column;gap:18px"><dl><div><dt>Horario</dt><dd>${esc(h.nombre)}</dd></div><div><dt>Jornada</dt><dd>${h.horasSem} h semanales · ${h.horasDia} h diarias</dd></div><div><dt>Hora de entrada y salida</dt><dd class="mono">${h.entrada} – ${h.salida}</dd></div><div><dt>Refrigerio</dt><dd>${h.refMin} min</dd></div><div><dt>Tolerancia de ingreso</dt><dd>${h.tolMin} min</dd></div><div><dt>Días</dt><dd>${h.dias.map(d=>DN[d]).join(', ')}</dd></div><div><dt>Asignado por</dt><dd>${esc(hs.src)}</dd></div></dl>
 <div><h3 style="margin-bottom:8px">Esta semana</h3>${wk(sem)}</div><div><h3 style="margin-bottom:8px">Próxima semana</h3>${wk(addDays(sem,7))}</div>
 <div><h3 style="margin-bottom:8px">Próximos feriados</h3>${fers.length?fers.map(f=>`<div class="row" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)"><span>${esc(f.nombre)}</span><span class="mono">${dShort(f.fecha)}/${f.fecha.slice(0,4)}</span></div>`).join(''):'<span class="muted">No hay feriados próximos registrados.</span>'}</div></div>`}
function formTipo(id){const it=id?G('ti_turnoTipos',id):null;
 openForm(v=>{const lib=v.clase==='Descanso',par=v.clase==='Partido';return{title:(it?'Editar':'Nuevo')+' tipo de turno',note:'El «rotativo» no es un horario fijo: se arma alternando turnos por semana desde «Asignación masiva».',
  fields:[{k:'nombre',l:'Nombre',req:1},{k:'clase',l:'Clase',opts:['Mañana','Tarde','Noche','Partido','Rotativo','Descanso'],req:1,keep:1,dyn:1},...(lib?[]:[{k:'entrada',l:par?'Entrada (primer tramo)':'Entrada',type:'time',req:1},{k:'salida',l:par?'Salida (primer tramo)':'Salida',type:'time',req:1},...(par?[{k:'entrada2',l:'Entrada (segundo tramo)',type:'time',req:1},{k:'salida2',l:'Salida (segundo tramo)',type:'time',req:1}]:[]),{k:'refMin',l:'Refrigerio (minutos)',type:'number'}]),{k:'color',l:'Color en la grilla',opts:COLN.map((c,i)=>[i,c]),req:1,keep:1}],
  submit:o=>{if(C('ti_turnoTipos').some(x=>x.id!==id&&x.activo!==false&&x.nombre.toLowerCase()===o.nombre.toLowerCase()))return 'Ya existe un tipo de turno con ese nombre.';
   const n={nombre:o.nombre,clase:o.clase,libre:lib,entrada:lib?'':o.entrada,salida:lib?'':o.salida,entrada2:par?o.entrada2:'',salida2:par?o.salida2:'',refMin:lib?0:Number(o.refMin)||0,color:Number(o.color)||0};
   if(!lib){if(par&&!(hm(o.entrada)<hm(o.salida)&&hm(o.salida)<hm(o.entrada2)&&hm(o.entrada2)<hm(o.salida2)))return 'En un turno partido los tramos deben ir en orden y sin cruzarse.';const s=tSpan(n);if(s.min<=0)return 'El refrigerio no puede cubrir todo el turno.';if(s.min>720)return 'Un turno no puede superar las 12 horas efectivas.'}
   if(it){const a=it.nombre+' '+tHoras(it);Object.assign(it,n);log('Edición','Tipos de turno',it.nombre,it.id,a,it.nombre+' '+tHoras(it));C('marcaciones').forEach(m=>{const tu=turnoOf(m.relId,m.fecha);if(tu&&tu.tipoId===it.id&&m.estado==='Vigente')calc(m)})}
   else{const x={id:nid('tt'),activo:true,...n};T().ti_turnoTipos.push(x);log('Alta','Tipos de turno',x.nombre,x.id,'',tHoras(x))}toast('Tipo de turno guardado')}}},it?{...it}:{clase:'Mañana',entrada:'06:00',salida:'14:00',refMin:45,color:0})}
function vTipos(){const adm=can('tiempo.configurar'),its=C('ti_turnoTipos').filter(t=>ui.ti_arch?t.activo===false:t.activo!==false);
 return `<div class="ph"><label class="row" style="gap:6px"><input type="checkbox" class="chk" data-chk="ti_arch"${ui.ti_arch?' checked':''}>Ver archivados</label>${adm?'<button class="btn p" data-a="ti_tipoNew">Nuevo tipo de turno</button>':''}</div>
 ${its.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Turno</th><th>Clase</th><th>Horas efectivas</th><th>Refrigerio</th><th>Asignaciones futuras</th><th></th></tr></thead><tbody>${its.map(t=>{const s=t.libre?null:tSpan(t),n=C('ti_turnos').filter(x=>x.tipoId===t.id&&x.fecha>=TODAY).length;
  return `<tr><td style="min-width:150px">${tipChip(t)}</td><td>${esc(t.clase)}${s&&s.fin>1440?' <span class="pill">Cruza medianoche</span>':''}</td><td class="num">${s?hh(s.min):'—'}</td><td class="num">${s?(+t.refMin||0)+' min':'—'}</td><td class="num">${n}</td><td style="text-align:right;white-space:nowrap">${adm?(t.activo===false?`<button class="btn s" data-a="ti_tipoArch" data-id="${t.id}">Restaurar</button>`:`<button class="btn s" data-a="ti_tipoEdit" data-id="${t.id}">Editar</button> <button class="btn s d" data-a="ti_tipoArch" data-id="${t.id}">Archivar</button>`):''}</td></tr>`}).join('')}</tbody></table></div>`
 :emptyState(ui.ti_arch?'No hay tipos archivados':'Aún no hay tipos de turno',ui.ti_arch?'Lo que archives aparecerá aquí y podrás restaurarlo.':'Define los turnos de tu operación para usarlos en el planificador.',adm&&!ui.ti_arch?'<button class="btn p" data-a="ti_tipoNew">Nuevo tipo de turno</button><button class="btn" data-a="ti_plantilla">Cargar plantilla Perú</button>':'')}`}
function formHorario(id){const it=id?G('ti_horarios',id):null;
 openForm(()=>({title:(it?'Editar':'Nuevo')+' horario',note:'Las horas diarias y la jornada semanal se calculan solas a partir de la entrada, la salida, el refrigerio y los días. La jornada máxima legal en Perú es de 48 horas semanales.',
  fields:[{k:'nombre',l:'Nombre',req:1,full:1},{k:'entrada',l:'Entrada',type:'time',req:1},{k:'salida',l:'Salida',type:'time',req:1},{k:'refMin',l:'Refrigerio (minutos, no cuenta como jornada)',type:'number',req:1},{k:'tolMin',l:'Tolerancia de ingreso (minutos)',type:'number',req:1},{k:'dias',l:'Días de trabajo',type:'checks',opts:[1,2,3,4,5,6,0].map(d=>[String(d),DN[d]]),req:1,full:1}],
  submit:o=>{if(C('ti_horarios').some(x=>x.id!==id&&x.activo!==false&&x.nombre.toLowerCase()===o.nombre.toLowerCase()))return 'Ya existe un horario con ese nombre.';
   let span=hm(o.salida)-hm(o.entrada);if(span<=0)span+=1440;const hd=(span-Number(o.refMin))/60,dias=o.dias.map(Number).sort((a,b)=>((a+6)%7)-((b+6)%7));
   if(hd<=0)return 'El refrigerio no puede cubrir toda la jornada.';if(hd>12)return 'La jornada diaria no puede superar las 12 horas.';const hsm=Math.round(hd*dias.length*100)/100;
   if(hsm>48)return 'Este horario suma '+nf(hsm)+' h semanales y supera la jornada máxima de 48 h. Reduce días u horas.';
   const n={nombre:o.nombre,entrada:o.entrada,salida:o.salida,refMin:Number(o.refMin),tolMin:Number(o.tolMin),dias,horasDia:Math.round(hd*100)/100,horasSem:hsm};
   if(it){const a=it.entrada+'–'+it.salida+' · '+it.horasSem+' h';Object.assign(it,n);log('Edición','Horarios',it.nombre,it.id,a,it.entrada+'–'+it.salida+' · '+it.horasSem+' h');C('marcaciones').forEach(m=>{if(m.estado==='Vigente'&&m.fecha>=addDays(TODAY,-1))calc(m)})}
   else{const x={id:nid('ho'),activo:true,...n};T().ti_horarios.push(x);if(!CF().horarioId)T().ti_cfg.horarioId=x.id;log('Alta','Horarios',x.nombre,x.id,'',x.entrada+'–'+x.salida+' · '+x.horasSem+' h')}toast('Horario guardado')}}),it?{...it,dias:it.dias.map(String)}:{entrada:'09:00',salida:'18:00',refMin:60,tolMin:10,dias:['1','2','3','4','5']})}
const nHor=id=>actRels().filter(r=>horarioOf(r,TODAY).id===id).length;
function vHorarios(){const adm=can('tiempo.configurar'),its=C('ti_horarios').filter(h=>ui.ti_arch?h.activo===false:h.activo!==false);
 return `<div class="ph"><label class="row" style="gap:6px"><input type="checkbox" class="chk" data-chk="ti_arch"${ui.ti_arch?' checked':''}>Ver archivados</label>${adm?'<button class="btn p" data-a="ti_horNew">Nuevo horario</button>':''}</div>
 ${its.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Horario</th><th>Entrada – salida</th><th>Días</th><th>Diarias</th><th>Semanal</th><th>Refrigerio</th><th>Tolerancia</th><th>Personas</th><th></th></tr></thead><tbody>${its.map(h=>`<tr><td><b>${esc(h.nombre)}</b>${CF().horarioId===h.id?' <span class="pill acc">General</span>':''}</td><td class="mono" style="white-space:nowrap">${h.entrada} – ${h.salida}</td><td style="white-space:nowrap">${h.dias.map(d=>DN[d]).join(' ')}</td><td class="num">${nf(h.horasDia)} h</td><td class="num">${nf(h.horasSem)} h</td><td class="num">${h.refMin} min</td><td class="num">${h.tolMin} min</td><td class="num">${nHor(h.id)}</td><td style="text-align:right;white-space:nowrap">${adm?(h.activo===false?`<button class="btn s" data-a="ti_horArch" data-id="${h.id}">Restaurar</button>`:`<button class="btn s" data-a="ti_horEdit" data-id="${h.id}">Editar</button> <button class="btn s d" data-a="ti_horArch" data-id="${h.id}">Archivar</button>`):''}</td></tr>`).join('')}</tbody></table></div>`
 :emptyState(ui.ti_arch?'No hay horarios archivados':'Aún no hay horarios',ui.ti_arch?'Lo que archives aparecerá aquí.':'Mientras no exista uno, la asistencia se calcula con un horario general de lunes a viernes, 09:00 a 18:00.',adm&&!ui.ti_arch?'<button class="btn p" data-a="ti_horNew">Nuevo horario</button><button class="btn" data-a="ti_plantilla">Cargar plantilla Perú</button>':'')}`}
function formAsig(ids){const hs=C('ti_horarios').filter(h=>h.activo!==false).map(h=>[h.id,h.nombre+' · '+h.entrada+'–'+h.salida]);if(!hs.length)return toast('Primero crea un horario');const multi=!ids;
 openForm(()=>({title:multi?'Asignar horario a varias personas':'Cambiar horario · '+relName(ids[0]),ok:'Asignar',note:'La asignación tiene fecha de vigencia: el horario anterior se conserva para calcular la asistencia de los días previos.',
  fields:[...(multi?[{k:'rels',l:'Personas',type:'checks',opts:vis().sort(byName).map(r=>[r.id,nm(r)]),req:1,full:1}]:[]),{k:'horarioId',l:'Horario',opts:hs,req:1,keep:1},{k:'desde',l:'Vigente desde',type:'date',req:1}],
  submit:o=>{const list=multi?o.rels:ids;list.forEach(id=>{const r=rl(id),a=horarioOf(r,o.desde).nombre;T().ti_asigHorario.push({id:nid('ah'),n:db.seq,relId:id,horarioId:o.horarioId,desde:o.desde,por:me().nombre});
    log('Asignación de horario','Horarios',nm(r),id,a,G('ti_horarios',o.horarioId).nombre+' desde '+fd(o.desde))});toast(pl(list.length,'persona')+' con nuevo horario')}}),{desde:TODAY,horarioId:hs[0][0],rels:[]})}
function vAsig(){const rs=vis().sort(byName),ps=12,pg=pager('ti_pgAsig',rs.length,ps);
 return `<div class="ph"><span class="muted">El horario se hereda de la política (general, empresa o sede) salvo que se asigne uno individual.</span><button class="btn p" data-a="ti_asigMas">Asignar a varias personas</button></div>
 ${rs.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Colaborador</th><th>Sede</th><th>Horario vigente</th><th>Entrada – salida</th><th>Origen</th><th></th></tr></thead><tbody>${rs.slice((pg.p-1)*ps,pg.p*ps).map(r=>{const s=horarioSrc(r,TODAY);return `<tr><td>${whoCell(r)}</td><td class="muted">${esc((get('sedes',r.sedeId)||{}).nombre||'')}</td><td><b>${esc(s.h.nombre)}</b></td><td class="mono" style="white-space:nowrap">${s.h.entrada} – ${s.h.salida}</td><td>${pill(s.src,s.src.startsWith('Individual')?'acc':'')}</td><td style="text-align:right"><button class="btn s" data-a="ti_asigEdit" data-id="${r.id}">Cambiar</button></td></tr>`}).join('')}</tbody></table></div>${pg.html}`:emptyState('No hay colaboradores en tu alcance','Cuando registres colaboradores podrás asignarles horario aquí.')}`}
const CFL=[['horarioId','Horario general'],['vacDias','Días de vacaciones por año'],['vacValidaRRHH','Vacaciones: RRHH valida después del jefe'],['vacAnticipacion','Vacaciones: días mínimos de anticipación'],['vacAlertaDias','Alerta de vacaciones por vencer (días antes)'],['nocIni','Inicio del horario nocturno'],['nocFin','Fin del horario nocturno'],['heMinimo','Minutos mínimos para contar horas extra'],['heTopeDia','Tope de horas extra por día'],['descansoMinH','Descanso mínimo entre turnos (horas)']];
function formCfg(){const cf=T().ti_cfg;openForm(()=>({title:'Política general de tiempo',note:'Aplica a toda la organización salvo donde exista una política de empresa o de sede. Los valores legales son parámetros: cámbialos cuando cambie la norma o el convenio.',
 fields:[{k:'horarioId',l:CFL[0][1],opts:C('ti_horarios').filter(h=>h.activo!==false).map(h=>[h.id,h.nombre])},{k:'vacDias',l:CFL[1][1],type:'number',req:1},{k:'vacValidaRRHH',l:CFL[2][1],opts:['Sí','No'],req:1,keep:1},{k:'vacAnticipacion',l:CFL[3][1],type:'number',req:1},{k:'vacAlertaDias',l:CFL[4][1],type:'number',req:1},{k:'nocIni',l:CFL[5][1],type:'time',req:1},{k:'nocFin',l:CFL[6][1],type:'time',req:1},{k:'heMinimo',l:CFL[7][1],type:'number',req:1},{k:'heTopeDia',l:CFL[8][1],type:'number',req:1},{k:'descansoMinH',l:CFL[9][1],type:'number',req:1}],
 submit:o=>{const ch=[];CFL.forEach(([k])=>{const nv=['horarioId','vacValidaRRHH','nocIni','nocFin'].includes(k)?o[k]:Number(o[k]);if(String(cf[k])!==String(nv)){ch.push(CFL.find(x=>x[0]===k)[1]+': '+cf[k]+' → '+nv);cf[k]=nv}});
  if(!ch.length)return 'No hay cambios para guardar.';C('marcaciones').forEach(m=>{if(m.estado==='Vigente'&&m.fecha>=addDays(TODAY,-1))calc(m)});log('Cambio de política general','Políticas de tiempo','General','','',ch.join(' · '));toast('Política actualizada')}}),{...cf})}
function formPol(id){const it=id?G('ti_politicas',id):null,hs=C('ti_horarios').filter(h=>h.activo!==false).map(h=>[h.id,h.nombre]);
 openForm(v=>({title:(it?'Editar':'Nueva')+' política por empresa o sede',note:'Deja un campo vacío para heredar el valor de la política general. La política de sede tiene prioridad sobre la de empresa.',
  fields:[{k:'ambito',l:'Aplica a',opts:['Empresa','Sede'],req:1,keep:1,dyn:1},{k:'refId',l:v.ambito==='Sede'?'Sede':'Empresa',opts:v.ambito==='Sede'?opt('sedes'):opt('empresas'),req:1},{k:'horarioId',l:'Horario',opts:hs},{k:'vacDias',l:'Días de vacaciones por año',type:'number'},{k:'vacValidaRRHH',l:'RRHH valida vacaciones',opts:['Sí','No']}],
  submit:o=>{if(!get(o.ambito==='Sede'?'sedes':'empresas',o.refId))return 'Elige la '+o.ambito.toLowerCase()+'.';if(C('ti_politicas').some(p=>p.id!==id&&p.activo!==false&&p.ambito===o.ambito&&p.refId===o.refId))return 'Ya existe una política para esa '+o.ambito.toLowerCase()+'. Edítala.';
   if(!o.horarioId&&o.vacDias===''&&!o.vacValidaRRHH)return 'Define al menos un valor distinto de la política general.';
   const n={ambito:o.ambito,refId:o.refId,horarioId:o.horarioId||'',vacDias:o.vacDias===''?'':Number(o.vacDias),vacValidaRRHH:o.vacValidaRRHH||''},nom=(get(o.ambito==='Sede'?'sedes':'empresas',o.refId)||{}).nombre;
   if(it){Object.assign(it,n);log('Edición','Políticas de tiempo',nom,it.id,'',polTxt(it))}else{const x={id:nid('po'),activo:true,...n};T().ti_politicas.push(x);log('Alta','Políticas de tiempo',nom,x.id,'',polTxt(x))}toast('Política guardada')}}),it?{...it}:{ambito:'Empresa'})}
const polTxt=p=>[p.horarioId&&'Horario: '+((G('ti_horarios',p.horarioId)||{}).nombre||'—'),p.vacDias!==''&&p.vacDias!=null&&'Vacaciones: '+p.vacDias+' días por año',p.vacValidaRRHH&&'RRHH valida vacaciones: '+p.vacValidaRRHH].filter(Boolean).join(' · ');
function vPol(){const cf=CF(),ps=C('ti_politicas').filter(p=>p.activo!==false),val=k=>k==='horarioId'?esc((G('ti_horarios',cf[k])||{}).nombre||'Sin definir'):['nocIni','nocFin','vacValidaRRHH'].includes(k)?esc(cf[k]):esc(cf[k]);
 return `<div class="pb" style="display:flex;flex-direction:column;gap:20px"><div><div class="row" style="justify-content:space-between;margin-bottom:12px"><h3>Política general</h3><button class="btn s" data-a="ti_cfgEdit">Editar</button></div><dl>${CFL.map(([k,l])=>`<div><dt>${l}</dt><dd>${val(k)}</dd></div>`).join('')}</dl></div>
 <div><div class="row" style="justify-content:space-between;margin-bottom:8px"><h3>Políticas por empresa y sede</h3><button class="btn s p" data-a="ti_polNew">Nueva política</button></div>
 ${ps.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Aplica a</th><th>Qué cambia respecto a la general</th><th>Personas</th><th></th></tr></thead><tbody>${ps.map(p=>{const o=get(p.ambito==='Sede'?'sedes':'empresas',p.refId);return `<tr><td>${pill(p.ambito,'acc')} <b>${esc(o?o.nombre:'—')}</b></td><td>${esc(polTxt(p))}</td><td class="num">${actRels().filter(r=>(p.ambito==='Sede'?r.sedeId:r.empresaId)===p.refId).length}</td><td style="text-align:right;white-space:nowrap"><button class="btn s" data-a="ti_polEdit" data-id="${p.id}">Editar</button> <button class="btn s d" data-a="ti_polDel" data-id="${p.id}">Quitar</button></td></tr>`}).join('')}</tbody></table></div>`:'<p class="muted">Toda la organización usa la política general. Crea una política si alguna empresa o sede trabaja con otro horario u otras reglas de vacaciones.</p>'}</div></div>`}
function formFer(id){const it=id?G('feriados',id):null,paises=[...new Set(T().empresas.map(e=>e.pais).concat(['Perú']))];
 openForm(()=>({title:(it?'Editar':'Nuevo')+' feriado',note:'En un feriado no se espera marcación y no se cuenta como falta. Aplica a las empresas del país elegido.',fields:[{k:'fecha',l:'Fecha',type:'date',req:1},{k:'pais',l:'País',opts:paises,req:1,keep:1},{k:'nombre',l:'Nombre',req:1,full:1}],
  submit:o=>{if(C('feriados').some(f=>f.id!==id&&f.fecha===o.fecha&&(f.pais||'Perú')===o.pais))return 'Ya hay un feriado registrado en esa fecha para ese país.';
   if(it){const a=fd(it.fecha)+' · '+it.nombre;Object.assign(it,{fecha:o.fecha,nombre:o.nombre,pais:o.pais});log('Edición','Feriados',it.nombre,it.id,a,fd(it.fecha)+' · '+it.nombre)}else{const x={id:nid('fe'),fecha:o.fecha,nombre:o.nombre,pais:o.pais};T().feriados.push(x);log('Alta','Feriados',x.nombre,x.id,'',fd(x.fecha))}ui.ti_fAnio=o.fecha.slice(0,4);toast('Feriado guardado')}}),it?{pais:'Perú',...it}:{pais:'Perú',fecha:U('ti_fAnio',TODAY.slice(0,4))+'-01-01'})}
function vFer(){const adm=can('tiempo.configurar'),y=U('ti_fAnio',TODAY.slice(0,4)),ys=[...new Set(C('feriados').map(f=>f.fecha.slice(0,4)).concat([TODAY.slice(0,4),String(+TODAY.slice(0,4)+1)]))].sort(),its=C('feriados').filter(f=>f.fecha.slice(0,4)===y).sort((a,b)=>a.fecha.localeCompare(b.fecha));
 return `<div class="ph"><div class="row">${selC('ti_fAnio',ys,'',y)}<span class="muted">${pl(its.length,'feriado')} en ${y}</span></div>${adm?`<div class="row"><button class="btn" data-a="ti_ferCarga">Cargar calendario Perú ${y}</button><button class="btn p" data-a="ti_ferNew">Nuevo feriado</button></div>`:''}</div>
 ${its.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Fecha</th><th>Día</th><th>Feriado</th><th>País</th><th></th></tr></thead><tbody>${its.map(f=>`<tr><td class="num">${fd(f.fecha)}</td><td style="text-transform:capitalize">${DL[dow(f.fecha)]}</td><td><b>${esc(f.nombre)}</b>${f.fecha>=TODAY&&days(TODAY,f.fecha)<=30?' <span class="pill acc">Próximo</span>':''}</td><td>${esc(f.pais||'Perú')}</td><td style="text-align:right;white-space:nowrap">${adm?`<button class="btn s" data-a="ti_ferEdit" data-id="${f.id}">Editar</button> <button class="btn s d" data-a="ti_ferDel" data-id="${f.id}">Eliminar</button>`:''}</td></tr>`).join('')}</tbody></table></div>`
 :emptyState('Sin feriados en '+y,'Carga el calendario oficial de Perú y ajústalo si tu empresa tiene días no laborables propios.',adm?`<button class="btn p" data-a="ti_ferCarga">Cargar calendario Perú ${y}</button>`:'')}`}
function vTurnos(){const eq=role().alcance!=='propio',adm=can('tiempo.turnos.administrar'),cfg=can('tiempo.configurar');
 const tabs=[...(eq?[['plan','Planificador']]:[]),...(myRel()?[['mi','Mi horario']]:[]),...(eq?[['tipos','Tipos de turno'],['hor','Horarios']]:[]),...(adm&&eq?[['asig','Asignación']]:[]),...(cfg?[['pol','Políticas']]:[]),['fer','Feriados']];
 const t=pickTab('ti_tTab',tabs);
 return pageHead('Tiempo','Turnos y horarios','',eq?'Jornadas, turnos por semana, políticas por empresa o sede y calendario de feriados.':'Tu horario, tus turnos y los feriados del año.')+
 `<div class="panel">${tabsBar('ti_tTab',tabs)}${t==='plan'?vPlan():t==='mi'?vMiHorario():t==='tipos'?vTipos():t==='hor'?vHorarios():t==='asig'?vAsig():t==='pol'?vPol():vFer()}</div>`}

/* =====================================================================
   VISTAS · VACACIONES
   ===================================================================== */
const PST={Gozado:'ok',Programado:'acc',Pendiente:'','Por vencer':'warn',Vencido:'crit'};
function saldoKpis(sv){return `<div class="kpis">${kp(sv.disp,'Disponibles',sv.disp<0?'crit':'accent')}${kp(sv.gen,'Generadas')}${kp(sv.tom,'Tomadas')}${kp(sv.prog,'Programadas')}${kp(sv.tram,'En trámite')}${kp(sv.venc,'Vencidas',sv.venc?'crit':'')}</div>`}
function recordTabla(sv){return sv.per.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Periodo</th><th>Generadas</th><th>Gozadas</th><th>Programadas</th><th>Saldo</th><th>Gozar hasta</th><th>Estado</th></tr></thead><tbody>${sv.per.slice().reverse().map(p=>`<tr><td class="num" style="white-space:nowrap">${fd(p.desde)} – ${fd(p.hasta)}</td><td class="num">${p.gen}</td><td class="num">${p.goz}</td><td class="num">${p.pr}</td><td class="num"><b>${p.saldo}</b></td><td class="num">${fd(p.limite)}</td><td>${pill(p.estado,PST[p.estado])}</td></tr>`).join('')}</tbody></table></div>`
 :'<p class="muted" style="padding:0 16px 16px">Aún no cumple su primer año de servicios: el derecho a vacaciones se genera al completar cada año.</p>'}
function vacAviso(sv){return sv.venc?`<div class="err" style="font-weight:500"><b>${pl(sv.venc,'día')} con plazo de goce vencido.</b> Si no se toman, corresponde la indemnización vacacional. Programa el descanso cuanto antes.</div>`:sv.porV?`<div class="note"><b>${pl(sv.porV,'día')} por vencer el ${fd(sv.prox)}.</b> Prográmalos antes de esa fecha para evitar la indemnización vacacional.</div>`:''}
function vMisVac(){const r=myRel();if(!r)return emptyState('Tu usuario no está vinculado a una ficha','Pide a Recursos Humanos que vincule tu usuario con tu ficha de colaborador.');
 const sv=vacSaldo(r),its=ordReq(C('vacSolicitudes').filter(s=>s.relId===r.id));
 return `<div class="pb" style="display:flex;flex-direction:column;gap:16px">${vacAviso(sv)}${saldoKpis(sv)}<p class="muted">Generas ${sv.dA} días por cada año completo desde tu ingreso (${fd(r.ingreso)}). Llevas ${nf(sv.truncas)} días acumulados del periodo en curso, que podrás usar al cumplir el año.</p></div>
 <div class="ph" style="border-top:1px solid var(--line)"><h2>Mis solicitudes</h2></div>${its.length?reqTable('vac',its,{sinQuien:1}):emptyState('Aún no has pedido vacaciones','Elige tus fechas; tu jefe directo recibirá la solicitud y verás aquí cada paso.','<button class="btn p" data-a="ti_vacNew">Solicitar vacaciones</button>')}
 <div class="ph" style="border-top:1px solid var(--line)"><h2>Récord vacacional</h2></div>${recordTabla(sv)}`}
function vSaldos(){const rs=vis().sort(byName).map(r=>({r,sv:vacSaldo(r)})),f=U('ti_sFil',''),adm=can('tiempo.administrar');
 const rows=rs.filter(x=>!f||(f==='venc'?x.sv.venc>0:f==='porv'?x.sv.porV>0:f==='alto'?x.sv.disp>=30:x.sv.disp<=0)),ps=12,pg=pager('ti_pgSal',rows.length,ps);
 const tv=rs.reduce((s,x)=>s+x.sv.venc,0),tp=rs.reduce((s,x)=>s+x.sv.porV,0),td=rs.reduce((s,x)=>s+Math.max(0,x.sv.disp),0);
 return `<div class="kpis" style="border:0;border-bottom:1px solid var(--line);border-radius:0">${kp(td,'Días disponibles acumulados')}${kp(tv,'Días con plazo vencido',tv?'crit':'')}${kp(tp,'Días por vencer',tp?'warn':'')}${kp(rs.filter(x=>x.sv.venc>0).length,'Personas con riesgo de indemnización',tv?'crit':'')}</div>
 <div class="ph"><div class="row">${selC('ti_sFil',[['venc','Con días vencidos'],['porv','Con días por vencer'],['alto','Con 30 días o más'],['cero','Sin saldo']],'Todos',f)}</div></div>
 ${rows.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Colaborador</th><th>Ingreso</th><th>Generadas</th><th>Tomadas</th><th>Programadas</th><th>En trámite</th><th>Vencidas</th><th>Disponibles</th><th></th></tr></thead><tbody>${rows.slice((pg.p-1)*ps,pg.p*ps).map(x=>`<tr class="click" data-a="ti_record" data-id="${x.r.id}"><td>${whoCell(x.r)}</td><td class="num">${fd(x.r.ingreso)}</td><td class="num">${x.sv.gen}</td><td class="num">${x.sv.tom}</td><td class="num">${x.sv.prog}</td><td class="num">${x.sv.tram}</td><td class="num">${x.sv.venc?pill(x.sv.venc,'crit'):x.sv.porV?pill(x.sv.porV+' por vencer','warn'):'0'}</td><td class="num"><b>${x.sv.disp}</b></td><td style="text-align:right;white-space:nowrap">${adm?`<button class="btn s" data-a="ti_vacAjuste" data-id="${x.r.id}">Ajustar</button>`:''}</td></tr>`).join('')}</tbody></table></div>${pg.html}`
 :emptyState(rs.length?'Nadie coincide con el filtro':'No hay colaboradores en tu alcance',rs.length?'Prueba con otro filtro.':'Los saldos se calculan solos desde la fecha de ingreso de cada persona.',rs.length?'<button class="btn" data-a="ti_clr" data-k="ti_sFil">Ver a todos</button>':'')}`}
function recordModal(id){const r=rl(id);if(!r||!(scoped().includes(r)||own(r)))return;const sv=vacSaldo(r),aj=C('ti_vacAjustes').filter(a=>a.relId===r.id);
 openModal('Récord vacacional · '+nm(r),`<div style="display:flex;flex-direction:column;gap:14px">${vacAviso(sv)}${saldoKpis(sv)}<p class="muted">Ingreso: ${fd(r.ingreso)} · ${sv.dA} días por año · ${nf(sv.truncas)} días acumulados del periodo en curso.</p>${recordTabla(sv)}
 ${aj.length?`<div><h3 style="margin-bottom:6px">Ajustes de saldo</h3>${aj.map(a=>`<div class="row" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)"><span>${esc(a.motivo)} <span class="muted">· ${fd(a.fecha)} · ${esc(a.por||'')}</span></span><b class="num">${a.dias>0?'−':'+'}${Math.abs(a.dias)} días</b></div>`).join('')}</div>`:''}</div>`,`<button class="btn" data-a="closeM">Cerrar</button>${scoped().includes(r)?`<button class="btn p" data-a="ficha" data-id="${r.id}">Abrir ficha</button>`:''}`,true)}
function calData(){const mes=U('ti_mes',TODAY.slice(0,7)),y=+mes.slice(0,4),m=+mes.slice(5,7),n=new Date(y,m,0).getDate(),ds=[];for(let i=1;i<=n;i++)ds.push(mes+'-'+pad(i));
 const mine=myRel(),sc=vis(),propio=role().alcance==='propio';let rs=sc.slice();
 if(mine&&role().alcance!=='todos')actRels().forEach(x=>{if(!rs.includes(x)&&((mine.jefeId&&x.jefeId===mine.jefeId)||x.id===mine.jefeId))rs.push(x)});
 const fu=U('ti_cUni','');if(fu)rs=rs.filter(r=>(uniOf(r)||{}).id===fu);
 return{mes,y,m,ds,rs:rs.sort(byName),sc,propio}}
function vCal(){const K2=calData(),{ds,rs,sc}=K2,solo=!!ui.ti_cSolo;
 const cell=(r,d)=>{const full=sc.includes(r),v=vacEn(r.id,d,['Aprobada','Pendiente']),a=!v&&ausEn(r.id,d,['Aprobada','Pendiente']);if(v&&(full||v.estado==='Aprobada'))return{c:v.estado==='Aprobada'?'ti-v':'ti-p',t:full?'Vacaciones'+(v.estado==='Pendiente'?' (en trámite)':''):'Ausente',out:1};
  if(a&&!tipoAus(a).presencial&&(full||a.estado==='Aprobada'))return{c:full?(a.estado==='Aprobada'?'ti-a':'ti-q'):'ti-v',t:full?a.tipo+(a.estado==='Pendiente'?' (en trámite)':''):'Ausente',out:1};return null};
 const mat=rs.map(r=>({r,c:ds.map(d=>cell(r,d))})).filter(x=>!solo||x.c.some(Boolean));
 const sol={},grupos={};ds.forEach((d,i)=>{const by={};mat.forEach(x=>{if(x.c[i]){const u=uniOf(x.r),k=u?u.id:'-';(by[k]=by[k]||[]).push(nm(x.r))}});Object.entries(by).forEach(([u,ns])=>{if(ns.length>1){sol[d+'|'+u]=1;const key=u+'|'+ns.join(' y ');const g=grupos[key];if(g&&g.hasta===addDays(d,-1))g.hasta=d;else if(!g)grupos[key]={u,ns,desde:d,hasta:d};else grupos[key+'|'+d]={u,ns,desde:d,hasta:d}}})});
 const fer=d=>C('feriados').some(f=>f.fecha===d&&(f.pais||'Perú')==='Perú'),wk=d=>[0,6].includes(dow(d))||fer(d),gl=Object.values(grupos);
 return `<div class="ph"><div class="row"><button class="btn s" data-a="ti_mesMov" data-d="-1" aria-label="Mes anterior">◀</button><b style="text-transform:capitalize;white-space:nowrap">${MES[K2.m-1]} ${K2.y}</b><button class="btn s" data-a="ti_mesMov" data-d="1" aria-label="Mes siguiente">▶</button><button class="btn s" data-a="ti_mesMov" data-d="0">Este mes</button>
 ${role().alcance==='todos'?selC('ti_cUni',opt('unidades'),'Todas las unidades',U('ti_cUni','')):''}<label class="row" style="gap:6px"><input type="checkbox" class="chk" data-chk="ti_cSolo"${solo?' checked':''}>Solo quienes se ausentan</label></div>
 <div class="row muted" style="font-size:12px;gap:12px"><span><i class="ti-leg ti-v"></i>Vacaciones</span><span><i class="ti-leg ti-a"></i>Otra ausencia</span><span><i class="ti-leg ti-p"></i>En trámite</span><span><i class="ti-leg" style="background:var(--crit-soft)"></i>Solapamiento</span></div></div>
 ${mat.length?`<div class="ti-calw"><table class="ti-cal"><thead><tr><th class="n">Colaborador</th>${ds.map(d=>`<th class="d ${wk(d)?'wk':''} ${d===TODAY?'hoy':''}">${DN[dow(d)][0]}<b class="num">${+d.slice(8)}</b></th>`).join('')}</tr></thead><tbody>${mat.map(x=>`<tr><td class="n" title="${esc(nm(x.r))}">${esc(nm(x.r))}</td>${x.c.map((c,i)=>`<td class="d ${wk(ds[i])?'wk':''} ${c&&sol[ds[i]+'|'+((uniOf(x.r)||{}).id||'-')]?'sol':''}"${c?` title="${esc(c.t)}"`:''}>${c?`<i class="${c.c}"></i>`:''}</td>`).join('')}</tr>`).join('')}
 <tr><td class="n muted">Ausentes por día</td>${ds.map((d,i)=>{const n=mat.filter(x=>x.c[i]).length;return `<td class="d num ${wk(d)?'wk':''}">${n||''}</td>`}).join('')}</tr></tbody></table></div>
 <div class="pb" style="border-top:1px solid var(--line)">${gl.length?`<h3 style="margin-bottom:6px">Solapamientos en el mismo equipo <span class="pill crit">${gl.length}</span></h3>${gl.map(g=>`<div class="alert"><span class="dot crit"></span><div><b>${esc(g.ns.join(' y '))}</b><div class="muted">${esc((get('unidades',g.u)||{}).nombre||'Mismo equipo')} · coinciden ${g.desde===g.hasta?'el '+fd(g.desde):'del '+fd(g.desde)+' al '+fd(g.hasta)}. Revisa la cobertura antes de aprobar lo que esté en trámite.</div></div></div>`).join('')}`:'<span class="muted">Sin solapamientos este mes: no hay dos personas del mismo equipo ausentes el mismo día.</span>'}</div>`
 :emptyState('Nadie se ausenta en '+MES[K2.m-1],'Cuando haya vacaciones o permisos en el mes, aparecerán aquí como barras por día.',solo?'<button class="btn" data-a="ti_clr" data-k="ti_cSolo,ti_cUni">Ver a todo el equipo</button>':'')}`}
function vVacaciones(){const mine=myRel(),eq=role().alcance!=='propio'&&can('tiempo.vacaciones.ver'),nv=nBandeja(['vac']);
 const tabs=[...(mine?[['mis','Mis vacaciones']]:[]),...(eq?[['sol','Solicitudes',nv],['sal','Saldos']]:[]),['cal','Calendario del equipo']],t=pickTab('ti_vTab',tabs);
 const acts=(t==='sal'&&can('datos.exportar')?'<button class="btn" data-a="ti_salCsv">Exportar saldos</button>':'')+((mine&&can('tiempo.solicitar'))||can('tiempo.administrar')?'<button class="btn p" data-a="ti_vacNew">Solicitar vacaciones</button>':'');
 return pageHead('Tiempo','Vacaciones',acts,eq?'Saldos calculados desde la fecha de ingreso, solicitudes con aprobación y calendario del equipo.':'Tu saldo, tus solicitudes y quién más se ausenta en tu equipo.')+
 `<div class="panel">${tabsBar('ti_vTab',tabs)}${t==='mis'?vMisVac():t==='sol'?solPanel('vac',{et:'Aún no hay solicitudes de vacaciones',ed:'Cuando alguien de tu alcance pida vacaciones, la verás aquí con su saldo y los cruces con su equipo.'}):t==='sal'?vSaldos():vCal()}</div>`}

/* =====================================================================
   VISTAS · AUSENCIAS Y PERMISOS
   ===================================================================== */
function formAusTipo(id){const it=id?G('ti_ausTipos',id):null,sn=['Sí','No'],b=v=>v==='Sí',s=v=>v?'Sí':'No';
 openForm(()=>({title:(it?'Editar':'Nuevo')+' tipo de ausencia',note:'Cada tipo lleva sus propias reglas. Se aplican al solicitar y al aprobar.',
  fields:[{k:'nombre',l:'Nombre',req:1,full:1},{k:'goce',l:'Con goce de haber',opts:sn,req:1,keep:1},{k:'sustento',l:'Requiere sustento',opts:sn,req:1,keep:1},{k:'maxDias',l:'Máximo de días por solicitud (0 = sin tope)',type:'number'},{k:'descuentaVac',l:'Descuenta del saldo de vacaciones',opts:sn,req:1,keep:1},{k:'usaBolsa',l:'Se descuenta de la bolsa de horas',opts:sn,req:1,keep:1},{k:'validaRRHH',l:'RRHH valida después del jefe',opts:sn,req:1,keep:1},{k:'presencial',l:'La persona sí trabaja ese día (p. ej. remoto)',opts:sn,req:1,keep:1}],
  submit:o=>{if(C('ti_ausTipos').some(x=>x.id!==id&&x.activo!==false&&x.nombre.toLowerCase()===o.nombre.toLowerCase()))return 'Ya existe un tipo con ese nombre.';if(b(o.descuentaVac)&&b(o.usaBolsa))return 'Un tipo no puede descontar de vacaciones y de la bolsa de horas a la vez.';
   const n={nombre:o.nombre,goce:b(o.goce),sustento:b(o.sustento),maxDias:Number(o.maxDias)||0,descuentaVac:b(o.descuentaVac),usaBolsa:b(o.usaBolsa),validaRRHH:b(o.validaRRHH),presencial:b(o.presencial)};
   if(it){const a=ausReglas(it);Object.assign(it,n);C('ausencias').forEach(x=>{if(x.tipoId===it.id)x.tipo=it.nombre});log('Edición','Tipos de ausencia',it.nombre,it.id,a,ausReglas(it))}else{const x={id:nid('at'),activo:true,...n};T().ti_ausTipos.push(x);log('Alta','Tipos de ausencia',x.nombre,x.id,'',ausReglas(x))}toast('Tipo guardado')}}),
  it?{nombre:it.nombre,maxDias:it.maxDias,goce:s(it.goce),sustento:s(it.sustento),descuentaVac:s(it.descuentaVac),usaBolsa:s(it.usaBolsa),validaRRHH:s(it.validaRRHH),presencial:s(it.presencial)}:{goce:'Sí',sustento:'No',maxDias:0,descuentaVac:'No',usaBolsa:'No',validaRRHH:'No',presencial:'No'})}
const ausReglas=t=>[t.goce?'Con goce':'Sin goce',t.sustento?'con sustento':'sin sustento',t.maxDias?'máx. '+t.maxDias+' días':'sin tope',t.descuentaVac&&'descuenta vacaciones',t.usaBolsa&&'usa bolsa de horas',t.validaRRHH&&'valida RRHH'].filter(Boolean).join(' · ');
function vAusTipos(){const adm=can('tiempo.configurar'),its=C('ti_ausTipos').filter(t=>ui.ti_arch?t.activo===false:t.activo!==false),y=v=>v?pill('Sí','ok'):'<span class="muted">No</span>';
 return `<div class="ph"><label class="row" style="gap:6px"><input type="checkbox" class="chk" data-chk="ti_arch"${ui.ti_arch?' checked':''}>Ver archivados</label>${adm?'<button class="btn p" data-a="ti_atNew">Nuevo tipo</button>':''}</div>
 ${its.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Tipo</th><th>Goce de haber</th><th>Sustento</th><th>Tope</th><th>Descuenta vacaciones</th><th>Bolsa de horas</th><th>Valida RRHH</th><th>Uso este año</th><th></th></tr></thead><tbody>${its.map(t=>`<tr><td><b>${esc(t.nombre)}</b>${t.presencial?'<div class="muted">La persona trabaja ese día</div>':''}</td><td>${t.goce?pill('Con goce','ok'):pill('Sin goce','warn')}</td><td>${y(t.sustento)}</td><td class="num">${t.maxDias?pl(+t.maxDias,'día'):'Sin tope'}</td><td>${y(t.descuentaVac)}</td><td>${y(t.usaBolsa)}</td><td>${y(t.validaRRHH)}</td><td class="num">${C('ausencias').filter(a=>a.tipoId===t.id&&a.estado==='Aprobada'&&a.desde.slice(0,4)===TODAY.slice(0,4)).reduce((s,a)=>s+a.dias,0)} días</td><td style="text-align:right;white-space:nowrap">${adm?(t.activo===false?`<button class="btn s" data-a="ti_atArch" data-id="${t.id}">Restaurar</button>`:`<button class="btn s" data-a="ti_atEdit" data-id="${t.id}">Editar</button> <button class="btn s d" data-a="ti_atArch" data-id="${t.id}">Archivar</button>`):''}</td></tr>`).join('')}</tbody></table></div>`
 :emptyState(ui.ti_arch?'No hay tipos archivados':'Aún no hay tipos de ausencia',ui.ti_arch?'Lo que archives aparecerá aquí.':'Define los permisos y licencias que maneja tu empresa, cada uno con sus reglas.',adm&&!ui.ti_arch?'<button class="btn p" data-a="ti_atNew">Nuevo tipo</button><button class="btn" data-a="ti_plantilla">Cargar plantilla Perú</button>':'')}`}
function vMisAus(){const r=myRel();if(!r)return emptyState('Tu usuario no está vinculado a una ficha','Pide a Recursos Humanos que vincule tu usuario con tu ficha de colaborador.');
 const its=ordReq(C('ausencias').filter(s=>s.relId===r.id)),y=TODAY.slice(0,4),ap=its.filter(s=>s.estado==='Aprobada'&&s.desde.slice(0,4)===y),by={};ap.forEach(s=>by[s.tipo]=(by[s.tipo]||0)+s.dias);
 return `<div class="kpis" style="border:0;border-bottom:1px solid var(--line);border-radius:0">${kp(ap.reduce((s,a)=>s+a.dias,0),'Días de permiso o licencia en '+y)}${kp(its.filter(s=>s.estado==='Pendiente').length,'Solicitudes en trámite')}${kp(nf(saldoBolsa(r.id))+' h','Bolsa de horas para compensar')}</div>
 ${Object.keys(by).length?`<div class="pb row" style="border-bottom:1px solid var(--line)">${Object.entries(by).map(([k,v])=>pill(k+': '+pl(v,'día'),'')).join('')}</div>`:''}
 ${its.length?reqTable('aus',its,{sinQuien:1}):emptyState('Aún no has pedido permisos','Descanso médico, permiso personal, trabajo remoto, compensación de horas y más: cada tipo indica si necesita sustento.','<button class="btn p" data-a="ti_ausNew">Solicitar permiso</button>')}`}
function vAusencias(){const mine=myRel(),eq=role().alcance!=='propio'&&can('tiempo.vacaciones.ver'),n=nBandeja(['aus']);
 const tabs=[...(mine?[['mis','Mis permisos']]:[]),...(eq?[['sol','Solicitudes',n]]:[]),...(eq||can('tiempo.configurar')?[['tipos','Tipos y reglas']]:[])],t=pickTab('ti_uTab',tabs);
 return pageHead('Tiempo','Ausencias y permisos',(mine&&can('tiempo.solicitar'))||can('tiempo.administrar')?`<button class="btn p" data-a="ti_ausNew">${can('tiempo.administrar')?'Solicitar o registrar':'Solicitar permiso'}</button>`:'','Descansos médicos, licencias, permisos, compensaciones y trabajo remoto, cada tipo con sus reglas.')+
 (tabs.length?`<div class="panel">${tabsBar('ti_uTab',tabs)}${t==='mis'?vMisAus():t==='sol'?solPanel('aus',{et:'Aún no hay solicitudes de permiso',ed:'Las solicitudes de tu alcance aparecerán aquí con su sustento y el paso en que se encuentran.'}):vAusTipos()}</div>`:`<div class="panel">${emptyState('Sin acceso a esta sección','Tu rol no incluye permisos sobre ausencias.')}</div>`)}

/* =====================================================================
   VISTAS · HORAS EXTRA Y BOLSA DE HORAS
   ===================================================================== */
function porAcreditar(){const sc=scoped();return C('ti_heSolicitudes').filter(s=>s.estado==='Aprobada'&&s.compensacion==='Bolsa de horas'&&!s.acreditada&&s.fecha<=TODAY&&sc.includes(rl(s.relId))).map(s=>{const m=marcDe(s.relId,s.fecha),w=m?Math.floor(m.heMin/15)*0.25:0;return{s,m,h:Math.min(s.horas,w)}})}
function bolsaMovs(relId){const ms=C('ti_bolsa').filter(b=>!relId||b.relId===relId).slice().reverse();return ms}
function vBolsa(){const adm=can('tiempo.administrar'),rs=vis().sort(byName).map(r=>({r,s:saldoBolsa(r.id),n:C('ti_bolsa').filter(b=>b.relId===r.id).length})).filter(x=>x.n),pa=porAcreditar(),sel=U('ti_bRel',''),sc=scoped();
 const movs=bolsaMovs(sel).filter(b=>sc.includes(rl(b.relId))),ps=10,pg=pager('ti_pgBol',movs.length,ps);
 return `<div class="kpis" style="border:0;border-bottom:1px solid var(--line);border-radius:0">${kp(nf(rs.reduce((s,x)=>s+x.s,0))+' h','Horas acumuladas en bolsa')}${kp(rs.filter(x=>x.s>0).length,'Personas con saldo a favor')}${kp(pa.length,'Autorizaciones por acreditar',pa.length?'warn':'')}</div>
 ${pa.length?`<div class="pb" style="border-bottom:1px solid var(--line)"><h3 style="margin-bottom:8px">Por acreditar</h3><p class="muted" style="margin-bottom:8px">Horas extra autorizadas con compensación en bolsa. Se acredita lo realmente trabajado según la marcación, hasta el tope autorizado.</p>${pa.map(x=>`<div class="row" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line)"><div style="min-width:0"><b>${esc(relName(x.s.relId))}</b> · ${fd(x.s.fecha)}<div class="muted">Autorizadas ${nf(x.s.horas)} h · marcadas ${x.m?hh(x.m.heMin):'sin marcación'}</div></div>${adm||puedeAcred(x.s)?(x.h>0?`<button class="btn s p" data-a="ti_acred" data-id="${x.s.id}">Acreditar ${nf(x.h)} h</button>`:pill('Sin horas extra marcadas','warn')):''}</div>`).join('')}</div>`:''}
 <div class="grid g2" style="gap:0"><div class="ti-sub" style="border-right:1px solid var(--line);min-width:0"><div class="ph"><h3>Saldos</h3>${adm?'<button class="btn s" data-a="ti_bolsaAj">Registrar ajuste</button>':''}</div>${rs.length?`<div class="tw"><table class="ti-t"><tbody>${rs.map(x=>`<tr class="click" data-a="ti_set" data-k="ti_bRel" data-v="${sel===x.r.id?'':x.r.id}" style="${sel===x.r.id?'background:var(--accent-soft)':''}"><td>${whoCell(x.r)}</td><td class="num" style="text-align:right;white-space:nowrap"><b>${nf(x.s)} h</b></td></tr>`).join('')}</tbody></table></div>`:emptyState('Nadie tiene bolsa de horas todavía','Se alimenta cuando se acreditan horas extra autorizadas con compensación en bolsa.')}</div>
 <div class="ti-sub" style="min-width:0"><div class="ph"><h3>Movimientos${sel?' · '+esc(relName(sel)):''}</h3>${sel?'<button class="btn s" data-a="ti_set" data-k="ti_bRel" data-v="">Ver todos</button>':''}</div>${movs.length?`<div class="tw"><table class="ti-t"><tbody>${movs.slice((pg.p-1)*ps,pg.p*ps).map(b=>`<tr><td class="num muted" style="white-space:nowrap">${fd(b.fecha)}</td><td>${sel?'':'<b>'+esc(relName(b.relId))+'</b><br>'}${esc(b.concepto)}</td><td class="num" style="text-align:right;white-space:nowrap;color:var(--${b.horas<0?'crit':'ok'})"><b>${b.horas>0?'+':'−'}${nf(Math.abs(b.horas))} h</b></td></tr>`).join('')}</tbody></table></div>${pg.html}`:emptyState('Sin movimientos','Aquí verás cada acreditación, compensación y ajuste.')}</div></div>`}
const puedeAcred=s=>{const r=rl(s.relId),mine=myRel();return !!(mine&&r&&r.jefeId===mine.id&&can('tiempo.aprobar'))};
function vMisHE(){const r=myRel();if(!r)return emptyState('Tu usuario no está vinculado a una ficha','Pide a Recursos Humanos que vincule tu usuario con tu ficha de colaborador.');
 const its=ordReq(C('ti_heSolicitudes').filter(s=>s.relId===r.id)),m0=TODAY.slice(0,8)+'01',he=range(m0,TODAY).reduce((s,f)=>{const m=marcDe(r.id,f);return s+(m?m.heMin:0)},0),movs=bolsaMovs(r.id);
 return `<div class="kpis" style="border:0;border-bottom:1px solid var(--line);border-radius:0">${kp(nf(saldoBolsa(r.id))+' h','Mi bolsa de horas')}${kp(hh(he),'Horas extra marcadas este mes')}${kp(its.filter(s=>s.estado==='Pendiente').length,'Solicitudes en trámite')}</div>
 <div class="pb row" style="border-bottom:1px solid var(--line);justify-content:space-between"><span class="muted">Usa tu bolsa pidiendo un descanso compensatorio: se descuenta al aprobarse.</span><button class="btn s"${saldoBolsa(r.id)>0?'':' disabled'} data-a="ti_compensar">Pedir descanso compensatorio</button></div>
 ${its.length?reqTable('he',its,{sinQuien:1}):emptyState('Aún no has pedido horas extra','Las horas extra se autorizan antes de trabajarlas. Indica la fecha, cuántas horas y si prefieres pago o bolsa de horas.','<button class="btn p" data-a="ti_heNew">Solicitar horas extra</button>')}
 ${movs.length?`<div class="ph" style="border-top:1px solid var(--line)"><h3>Movimientos de mi bolsa</h3></div><div class="tw"><table class="ti-t"><tbody>${movs.map(b=>`<tr><td class="num muted" style="white-space:nowrap">${fd(b.fecha)}</td><td>${esc(b.concepto)}</td><td class="num" style="text-align:right;white-space:nowrap;color:var(--${b.horas<0?'crit':'ok'})"><b>${b.horas>0?'+':'−'}${nf(Math.abs(b.horas))} h</b></td></tr>`).join('')}</tbody></table></div>`:''}`}
function vExtras(){const mine=myRel(),eq=role().alcance!=='propio'&&can('tiempo.vacaciones.ver'),n=nBandeja(['he']);
 const tabs=[...(mine?[['mis','Mis horas extra']]:[]),...(eq?[['sol','Solicitudes',n],['bolsa','Bolsa de horas',porAcreditar().length]]:[])],t=pickTab('ti_eTab',tabs);
 return pageHead('Tiempo','Horas extra y bolsa de horas',(mine&&can('tiempo.solicitar'))||can('tiempo.administrar')?'<button class="btn p" data-a="ti_heNew">Solicitar horas extra</button>':'','Autorización previa, acreditación según lo marcado y compensación con descanso.')+
 (tabs.length?`<div class="panel">${tabsBar('ti_eTab',tabs)}${t==='mis'?vMisHE():t==='sol'?solPanel('he',{et:'Aún no hay solicitudes de horas extra',ed:'Las horas extra deben autorizarse antes de trabajarse. Aquí verás las de tu alcance.'}):vBolsa()}</div>`:`<div class="panel">${emptyState('Sin acceso a esta sección','Tu rol no incluye permisos sobre horas extra.')}</div>`)}

/* =====================================================================
   VISTAS · HOJAS DE TIEMPO POR PROYECTO
   ===================================================================== */
const hojaDe=(relId,sem)=>C('ti_hojas').find(h=>h.relId===relId&&h.semana===sem);
const HST={Borrador:'',Pendiente:'warn',Aprobada:'ok',Rechazada:'crit'};
function vMiHoja(){const r=myRel();if(!r)return emptyState('Tu usuario no está vinculado a una ficha','Pide a Recursos Humanos que vincule tu usuario con tu ficha de colaborador.');
 const sem=U('ti_hSem',monday(TODAY)),ds=range(sem,addDays(sem,6)),h=hojaDe(r.id,sem),ed=!h||['Borrador','Rechazada'].includes(h.estado),ps=C('ti_proyectos').filter(p=>p.activo!==false),lin=h?h.lineas:[];
 const libres=ps.filter(p=>!lin.some(l=>l.proyectoId===p.id)),asis=ds.reduce((s,f)=>{const m=marcDe(r.id,f);return s+(m?m.horasMin:0)},0),tot=h?hojaTot(h):0,td=ds.map((d,i)=>lin.reduce((s,l)=>s+(+l.h[i]||0),0));
 const nav=`<div class="ph"><div class="row"><button class="btn s" data-a="ti_hSemMov" data-d="-7" aria-label="Semana anterior">◀</button><b class="num" style="white-space:nowrap">${fd(sem)} – ${fd(addDays(sem,6))}</b><button class="btn s" data-a="ti_hSemMov" data-d="7" aria-label="Semana siguiente">▶</button><button class="btn s" data-a="ti_hSemMov" data-d="0">Esta semana</button>${pill(h?(h.estado==='Pendiente'?'Enviada':h.estado):'Sin registrar',h?HST[h.estado]:'')}</div>
 <div class="row">${ed&&libres.length?`${selC('ti_hAdd',libres.map(p=>[p.id,p.codigo+' · '+p.nombre]),'Agregar proyecto…','')}`:''}${ed&&!lin.length&&hojaDe(r.id,addDays(sem,-7))?'<button class="btn s" data-a="ti_hojaCopia">Copiar proyectos de la semana anterior</button>':''}</div></div>`;
 if(!ps.length)return nav+emptyState('Aún no hay proyectos','Recursos Humanos o el responsable debe crear los proyectos a los que se imputan horas.',can('tiempo.hojas.administrar')?'<button class="btn p" data-a="ti_proyNew">Nuevo proyecto</button>':'');
 if(!lin.length)return nav+emptyState('Semana sin horas registradas','Agrega los proyectos en los que trabajaste y reparte tus horas por día. Al terminar, envíala a tu jefe.',ed?'<span class="muted">Usa «Agregar proyecto…» arriba.</span>':'');
 return nav+(h.estado==='Rechazada'?`<div class="pb" style="padding-bottom:0"><div class="err" style="font-weight:500"><b>Rechazada:</b> ${esc((h.motivoRechazo||'').replace(/[.\s]+$/,''))}. Corrígela y vuelve a enviarla.</div></div>`:'')+
 `<div class="tw"><table class="ti-t"><thead><tr><th style="min-width:180px">Proyecto</th>${ds.map(d=>`<th style="text-align:right">${dShort(d)}</th>`).join('')}<th style="text-align:right">Total</th><th></th></tr></thead><tbody>${lin.map((l,li)=>{const p=G('ti_proyectos',l.proyectoId)||{};return `<tr><td><b>${esc(p.nombre||'—')}</b><div class="muted mono">${esc(p.codigo||'')}</div></td>${ds.map((d,i)=>`<td style="text-align:right">${ed?`<input class="in ti-hin num" type="number" min="0" max="24" step="0.5" id="ti_h_${li}_${i}" data-ti-h="${h.id}|${li}|${i}" value="${l.h[i]||''}" aria-label="Horas ${esc(p.nombre||'')} ${dShort(d)}">`:`<span class="num">${l.h[i]?nf(l.h[i]):'—'}</span>`}</td>`).join('')}<td class="num" style="text-align:right"><b id="ti_ht_r${li}">${nf(l.h.reduce((a,b)=>a+(+b||0),0))}</b></td><td style="text-align:right">${ed?`<button class="btn s d" data-a="ti_hojaDel" data-i="${li}" aria-label="Quitar proyecto">Quitar</button>`:''}</td></tr>`}).join('')}
 <tr><td><b>Total por día</b></td>${td.map((v,i)=>`<td class="num" style="text-align:right"><b id="ti_ht_d${i}">${v?nf(v):'—'}</b></td>`).join('')}<td class="num" style="text-align:right"><b id="ti_ht_t">${nf(tot)} h</b></td><td></td></tr></tbody></table></div>
 <div class="ph" style="border-top:1px solid var(--line);border-bottom:0"><span class="muted">Asistencia marcada en la semana: <b>${hh(asis)}</b>${Math.abs(asis/60-tot)>2&&asis?' · difiere de lo imputado en '+nf(Math.abs(Math.round((asis/60-tot)*10)/10))+' h':''}</span><div class="row">${h.estado==='Pendiente'?'<button class="btn" data-a="ti_hojaRetirar">Retirar envío</button>':''}${ed?`<button class="btn p" data-a="ti_hojaEnviar">${h.estado==='Rechazada'?'Volver a enviar':'Enviar a aprobación'}</button>`:''}</div></div>`}
function costoHora(r){const h=horarioOf(r,TODAY);return r.salario/((+h.horasSem||40)*52/12)}
function vHojaRes(){const d0=U('ti_hDesde',addDays(monday(TODAY),-28)),d1=U('ti_hHasta',addDays(monday(TODAY),6)),sc=scoped(),soloAp=U('ti_hEst','Aprobada'),cost=can('colaborador.salario.ver');
 const hs=C('ti_hojas').filter(h=>h.semana>=monday(d0)&&h.semana<=d1&&sc.includes(rl(h.relId))&&(soloAp==='todas'?h.estado!=='Borrador':h.estado==='Aprobada')),by={};
 hs.forEach(h=>h.lineas.forEach(l=>{const t=l.h.reduce((a,b)=>a+(+b||0),0);if(!t)return;const x=by[l.proyectoId]=by[l.proyectoId]||{h:0,per:{},c:0};x.h+=t;x.per[h.relId]=(x.per[h.relId]||0)+t;const r=rl(h.relId);if(cur(r)==='PEN')x.c+=t*costoHora(r)}));
 const rows=Object.entries(by).map(([id,x])=>({p:G('ti_proyectos',id)||{nombre:'Proyecto eliminado',codigo:''},...x})).sort((a,b)=>b.h-a.h),mx=Math.max(1,...rows.map(x=>x.h)),tot=rows.reduce((s,x)=>s+x.h,0);
 return `<div class="ph"><div class="row">${dateC('ti_hDesde',d0,'Desde')}${dateC('ti_hHasta',d1,'Hasta')}${selC('ti_hEst',[['Aprobada','Solo hojas aprobadas'],['todas','Aprobadas y enviadas']],'',soloAp)}</div><span class="muted">${nf(tot)} h en ${pl(rows.length,'proyecto')}</span></div>
 ${rows.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Proyecto</th><th style="min-width:160px">Horas</th><th>Personas</th>${cost?'<th>Costo estimado</th>':''}<th>Participación</th></tr></thead><tbody>${rows.map(x=>`<tr><td><b>${esc(x.p.nombre)}</b><div class="muted mono">${esc(x.p.codigo||'')}${x.p.cliente?' · '+esc(x.p.cliente):''}</div></td><td><div class="row" style="flex-wrap:nowrap"><b class="num" style="min-width:52px">${nf(x.h)} h</b><div class="bar" style="flex:1"><i style="width:${x.h/mx*100}%"></i></div></div></td><td>${Object.entries(x.per).sort((a,b)=>b[1]-a[1]).map(([id,h])=>`<div style="white-space:nowrap">${esc(relName(id))} <span class="muted num">${nf(h)} h</span></div>`).join('')}</td>${cost?`<td class="num">${money(x.c,'PEN')}</td>`:''}<td class="num">${Math.round(x.h/tot*100)} %</td></tr>`).join('')}</tbody></table></div>${cost?'<div class="pb muted" style="border-top:1px solid var(--line)">Costo estimado = horas × (sueldo básico ÷ horas mensuales de su jornada). Solo personal con sueldo en soles; no incluye cargas sociales.</div>':''}`
 :emptyState('Sin horas en ese rango','Solo se suman las hojas aprobadas. Amplía las fechas o incluye las enviadas.','<button class="btn" data-a="ti_clr" data-k="ti_hDesde,ti_hHasta,ti_hEst">Restablecer filtros</button>')}`}
function formProy(id){const it=id?G('ti_proyectos',id):null;
 openForm(()=>({title:(it?'Editar':'Nuevo')+' proyecto',fields:[{k:'codigo',l:'Código',req:1},{k:'nombre',l:'Nombre',req:1},{k:'cliente',l:'Cliente o área'},{k:'ccId',l:'Centro de costo',opts:opt('ccs')}],
  submit:o=>{if(C('ti_proyectos').some(x=>x.id!==id&&x.codigo.toLowerCase()===o.codigo.toLowerCase()))return 'Ya existe un proyecto con ese código.';
   if(it){const a=it.codigo+' · '+it.nombre;Object.assign(it,{codigo:o.codigo,nombre:o.nombre,cliente:o.cliente||'',ccId:o.ccId||''});log('Edición','Proyectos',it.nombre,it.id,a,it.codigo+' · '+it.nombre)}else{const x={id:nid('pr'),activo:true,codigo:o.codigo,nombre:o.nombre,cliente:o.cliente||'',ccId:o.ccId||''};T().ti_proyectos.push(x);log('Alta','Proyectos',x.nombre,x.id,'',x.codigo)}toast('Proyecto guardado')}}),it?{...it}:{})}
function vProys(){const adm=can('tiempo.hojas.administrar'),its=C('ti_proyectos').filter(p=>ui.ti_arch?p.activo===false:p.activo!==false);
 return `<div class="ph"><label class="row" style="gap:6px"><input type="checkbox" class="chk" data-chk="ti_arch"${ui.ti_arch?' checked':''}>Ver archivados</label>${adm?'<button class="btn p" data-a="ti_proyNew">Nuevo proyecto</button>':''}</div>
 ${its.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Código</th><th>Proyecto</th><th>Cliente o área</th><th>Centro de costo</th><th>Horas aprobadas</th><th></th></tr></thead><tbody>${its.map(p=>{const c=get('ccs',p.ccId),h=C('ti_hojas').filter(x=>x.estado==='Aprobada').reduce((s,x)=>s+x.lineas.filter(l=>l.proyectoId===p.id).reduce((a,l)=>a+l.h.reduce((y,z)=>y+(+z||0),0),0),0);
  return `<tr><td class="mono">${esc(p.codigo)}</td><td><b>${esc(p.nombre)}</b></td><td>${esc(p.cliente||'—')}</td><td class="muted">${c?esc(c.codigo+' · '+c.nombre):'—'}</td><td class="num">${nf(h)} h</td><td style="text-align:right;white-space:nowrap">${adm?(p.activo===false?`<button class="btn s" data-a="ti_proyArch" data-id="${p.id}">Restaurar</button>`:`<button class="btn s" data-a="ti_proyEdit" data-id="${p.id}">Editar</button> <button class="btn s d" data-a="ti_proyArch" data-id="${p.id}">Archivar</button>`):''}</td></tr>`}).join('')}</tbody></table></div>`
 :emptyState(ui.ti_arch?'No hay proyectos archivados':'Aún no hay proyectos',ui.ti_arch?'Lo que archives aparecerá aquí.':'Crea los proyectos o frentes de trabajo a los que tu equipo imputa horas.',adm&&!ui.ti_arch?'<button class="btn p" data-a="ti_proyNew">Nuevo proyecto</button>':'')}`}
function vHojas(){const mine=myRel(),eq=role().alcance!=='propio',n=nBandeja(['hoja']);
 const tabs=[...(mine&&can('tiempo.hojas.registrar')?[['mi','Mi semana']]:[]),...(eq?[['apr','Hojas del equipo',n],['res','Resumen por proyecto']]:[]),...(eq||can('tiempo.hojas.administrar')?[['proy','Proyectos']]:[])],t=pickTab('ti_hTab',tabs);
 return pageHead('Tiempo','Hojas de tiempo','','Horas por proyecto: registro semanal, aprobación del jefe y resumen para costeo.')+
 (tabs.length?`<div class="panel">${tabsBar('ti_hTab',tabs)}${t==='mi'?vMiHoja():t==='apr'?solPanel('hoja',{f:s=>s.estado!=='Borrador',ests:[['Pendiente','Enviadas'],['Aprobada','Aprobadas'],['Rechazada','Rechazadas']],et:'Aún no hay hojas enviadas',ed:'Cuando tu equipo envíe su semana, la verás aquí para aprobarla.'}):t==='res'?vHojaRes():vProys()}</div>`:`<div class="panel">${emptyState('Sin acceso a esta sección','Tu rol no incluye el registro de hojas de tiempo.')}</div>`)}

/* =====================================================================
   INTEGRACIÓN CON EL NÚCLEO: ficha, inicio, alertas y pendientes
   ===================================================================== */
function fichaAsis(r){const ds=range(addDays(TODAY,-29),TODAY).filter(f=>f>=r.ingreso&&(!r.cese||f<=r.cese)),es=ds.map(f=>({f,e:estadoDia(r,f)})),hs=horarioSrc(r,TODAY);
 const tr=es.filter(x=>x.e.m&&x.e.m.salida),tard=es.filter(x=>x.e.m&&x.e.m.tardanzaMin>0),fal=es.filter(x=>x.e.k==='falta'),sum=k=>es.reduce((s,x)=>s+(x.e.m?x.e.m[k]||0:0),0);
 const last=es.slice(-14).reverse().filter(x=>x.e.m||!['descanso','feriado'].includes(x.e.k));
 return `<div style="display:flex;flex-direction:column;gap:18px"><dl><div><dt>Horario vigente</dt><dd>${esc(hs.h.nombre)} · <span class="mono">${hs.h.entrada}–${hs.h.salida}</span></dd></div><div><dt>Origen</dt><dd>${esc(hs.src)}</dd></div><div><dt>Tolerancia</dt><dd>${hs.h.tolMin} min</dd></div><div><dt>Bolsa de horas</dt><dd>${nf(saldoBolsa(r.id))} h</dd></div></dl>
 <div class="kpis">${kp(tr.length,'Días trabajados (30 días)')}${kp(hh(sum('horasMin')),'Horas trabajadas')}${kp(tard.length,'Tardanzas',tard.length?'warn':'')}${kp(fal.length,'Faltas',fal.length?'crit':'')}${kp(hh(sum('heMin')),'Horas extra')}</div>
 <div><div class="row" style="justify-content:space-between;margin-bottom:8px"><h3>Últimos 14 días</h3>${role().alcance!=='propio'&&can('tiempo.asistencia.ver')&&r.estado==='Activo'?`<button class="btn s" data-a="ti_verReg" data-id="${r.id}">Ver registro completo</button>`:''}</div>
 ${last.length?`<div class="tw"><table class="ti-t"><thead><tr><th>Fecha</th><th>Jornada</th><th>Entrada</th><th>Refrigerio</th><th>Salida</th><th>Horas</th><th>Estado</th></tr></thead><tbody>${last.map(x=>`<tr><td style="white-space:nowrap">${dShort(x.f)}</td><td class="muted">${esc(jTxt(x.e.j))}</td>${dayCells(x.e)}<td>${pill(x.e.t,x.e.c)}</td></tr>`).join('')}</tbody></table></div>`:'<p class="muted">Sin días laborables ni marcaciones en las últimas dos semanas.</p>'}</div></div>`}
function fichaVac(r){const sv=vacSaldo(r),adm=can('tiempo.administrar')&&r.estado==='Activo',vs=ordReq(C('vacSolicitudes').filter(s=>s.relId===r.id)),as=ordReq(C('ausencias').filter(s=>s.relId===r.id));
 return `<div style="display:flex;flex-direction:column;gap:18px">${vacAviso(sv)}<div class="row" style="justify-content:space-between"><h3>Saldo de vacaciones</h3><div class="row">${adm?`<button class="btn s" data-a="ti_vacAjuste" data-id="${r.id}">Ajustar saldo</button><button class="btn s" data-a="ti_ausNew" data-id="${r.id}">Registrar ausencia</button><button class="btn s p" data-a="ti_vacNew" data-id="${r.id}">Registrar vacaciones</button>`:own(r)&&can('tiempo.solicitar')&&r.estado==='Activo'?'<button class="btn s p" data-a="ti_vacNew">Solicitar vacaciones</button>':''}</div></div>
 ${saldoKpis(sv)}<p class="muted">${sv.dA} días por año completo desde el ${fd(r.ingreso)} · ${nf(sv.truncas)} días acumulados del periodo en curso.</p>
 <div><h3 style="margin-bottom:8px">Récord vacacional</h3><div class="panel">${recordTabla(sv)||''}</div></div>
 <div><h3 style="margin-bottom:8px">Solicitudes de vacaciones</h3>${vs.length?`<div class="panel">${reqTable('vac',vs,{sinQuien:1})}</div>`:'<p class="muted">Sin solicitudes de vacaciones registradas.</p>'}</div>
 <div><h3 style="margin-bottom:8px">Ausencias y permisos</h3>${as.length?`<div class="panel">${reqTable('aus',as,{sinQuien:1})}</div>`:'<p class="muted">Sin ausencias ni permisos registrados.</p>'}</div></div>`}
const KS=['vac','aus','he','cor','hoja'];
function pend(){const out=[];KS.forEach(k=>bandeja(k).forEach(s=>{const d=K[k],r=rl(s.relId);out.push({id:s.id,tipo:d.lbl(s),t:nm(r)+' · '+d.tit(s),d:d.det(s)+(s.motivo||s.comentario?' · '+(s.motivo||s.comentario):'')+(s.etapa==='RRHH'?' · validación de RRHH':''),relId:s.relId,aprobar:{a:'ti_'+k+'Aprobar',id:s.id},rechazar:{a:'ti_'+k+'Rechazar',id:s.id},abrir:{r:d.route}})}));return out}
function ausentesHoy(rs){return rs.map(r=>({r,v:vacEn(r.id,TODAY),a:ausEn(r.id,TODAY)})).filter(x=>x.v||(x.a&&!tipoAus(x.a).presencial))}
function home(){const mine=myRel(),al=role().alcance;let h='';
 if(mine&&can('tiempo.asistencia.marcar')){const sv=vacSaldo(mine),prox=C('vacSolicitudes').filter(v=>v.relId===mine.id&&['Aprobada','Pendiente'].includes(v.estado)&&v.hasta>=TODAY).sort((a,b)=>a.desde.localeCompare(b.desde))[0];
  h+=`<div class="grid g2"><div class="panel"><div class="ph"><h2>Mi jornada de hoy</h2><button class="btn s" data-a="ti_ir" data-r="ti_asistencia" data-k="ti_aTab" data-t="mia">Mi asistencia</button></div>${clockPanel(mine)}</div>
  <div class="panel"><div class="ph"><h2>Mis vacaciones</h2><button class="btn s" data-a="ti_ir" data-r="ti_vacaciones" data-k="ti_vTab" data-t="mis">Ver detalle</button></div><div class="pb" style="display:flex;flex-direction:column;gap:12px"><div class="row" style="align-items:flex-end;gap:14px"><div><div class="ti-clock num">${sv.disp}</div><span class="muted">días disponibles</span></div><div class="muted">${sv.prog} programados · ${sv.tram} en trámite${sv.venc?' · <b style="color:var(--crit)">'+sv.venc+' vencidos</b>':''}</div></div>
  ${vacAviso(sv)}${prox?`<div class="note">${prox.estado==='Aprobada'?'Próximas vacaciones':'Solicitud en trámite'}: <b>${fd(prox.desde)} al ${fd(prox.hasta)}</b> (${pl(prox.dias,'día')})</div>`:''}
  <div class="row">${can('tiempo.solicitar')?'<button class="btn p" data-a="ti_vacNew">Solicitar vacaciones</button><button class="btn" data-a="ti_ausNew">Pedir permiso</button>':''}</div></div></div></div>`}
 const pe=pend();
 if(pe.length)h+=`<div class="panel"><div class="ph"><h2>Solicitudes de tiempo por aprobar</h2><span class="pill warn">${pe.length}</span></div><div class="pb" style="padding-block:6px">${pe.slice(0,6).map(p=>`<div class="alert" style="align-items:center"><div style="flex:1;min-width:0"><b>${esc(p.t)}</b><div class="muted">${esc(p.tipo)} · ${esc(p.d)}</div></div><div class="row" style="flex-wrap:nowrap"><button class="btn s p" data-a="${p.aprobar.a}" data-id="${p.id}">Aprobar</button><button class="btn s d" data-a="${p.rechazar.a}" data-id="${p.id}">Rechazar</button></div></div>`).join('')}${pe.length>6?`<div class="muted" style="padding:8px 0">Y ${pe.length-6} más en las pantallas del grupo «Tiempo».</div>`:''}</div></div>`;
 if(al!=='propio'&&can('tiempo.asistencia.ver')){const rs=vis(),au=ausentesHoy(rs),es=rs.map(r=>estadoDia(r,TODAY)),pres=es.filter(e=>e.m).length,sin=es.filter(e=>e.k==='sinmarcar').length,tard=es.filter(e=>e.m&&e.m.tardanzaMin>0).length;
  h+=`<div class="grid g2"><div class="panel"><div class="ph"><h2>Asistencia de hoy</h2><button class="btn s" data-a="ti_ir" data-r="ti_asistencia" data-k="ti_aTab" data-t="hoy">Abrir tablero</button></div><div class="kpis" style="border:0;border-radius:0 0 10px 10px">${kp(pres,'Marcaron entrada')}${kp(tard,'Tardanzas',tard?'warn':'')}${kp(sin,'Sin marcar',sin?'crit':'')}</div></div>
  <div class="panel"><div class="ph"><h2>Ausentes hoy</h2><span class="pill ${au.length?'acc':'ok'}">${au.length}</span></div><div class="pb" style="display:flex;flex-direction:column;gap:10px">${au.length?au.map(x=>{const s=x.v||x.a;return `<div class="row" style="justify-content:space-between">${whoCell(x.r)}<span style="text-align:right">${pill(x.v?'Vacaciones':x.a.tipo,'acc')}<div class="muted" style="font-size:12px">hasta el ${fd(s.hasta)}</div></span></div>`}).join(''):'<span class="muted">Todo el equipo está disponible hoy: nadie tiene vacaciones ni permisos.</span>'}</div></div></div>`}
 return h}
function alertas(rels){const a=[],rs=rels.filter(r=>r.estado==='Activo');
 let sinC=0;if(can('tiempo.vacaciones.ver'))rs.forEach(r=>{const sv=vacSaldo(r);
  if(sv.sinCarga)sinC++;else if(sv.venc)a.push({c:'crit',t:`${nm(r)} tiene ${pl(sv.venc,'día')} de vacaciones con plazo vencido`,d:'Pasó el año que la ley da para gozarlas. Si no descansa, corresponde pagar la indemnización vacacional: programa esos días ya.',rel:r.id});
  else if(sv.porV)a.push({t:`A ${nm(r)} le vencen ${pl(sv.porV,'día')} de vacaciones el ${fd(sv.prox)}`,d:`Faltan ${days(TODAY,sv.prox)} días para el límite de goce. Acuerda las fechas con la persona para evitar la indemnización vacacional.`,rel:r.id})});
 if(sinC)a.push({t:`Falta cargar el saldo inicial de vacaciones de ${pl(sinC,'persona')}`,d:'Tienen más de dos años de servicio y ningún día de vacaciones registrado, así que todo su récord figura como pendiente. Carga los días ya gozados en Vacaciones › Saldos › Ajustar para que las alertas de vencimiento sean confiables.'});
 if(can('tiempo.asistencia.ver')&&role().alcance!=='propio'){const ds=range(addDays(TODAY,-14),addDays(TODAY,-1));
  rs.forEach(r=>{const f=ds.filter(d=>estadoDia(r,d).k==='falta');if(f.length)a.push({c:f.length>1?'crit':'',t:`${nm(r)}: ${pl(f.length,'falta')} sin justificar`,d:`No marcó ni tiene permiso el ${f.map(x=>dShort(x)).join(', ')}. Pide el sustento o registra la ausencia; si no, se descontará en planilla.`,rel:r.id})});
  const nc=bandeja('cor').length;if(nc)a.push({t:`${pl(nc,'corrección','correcciones')} de asistencia por resolver`,d:'El registro original no cambia hasta que se aprueben. Resuélvelas antes del cierre de planilla en Asistencia › Correcciones e incidencias.'})}
 return a}

/* =====================================================================
   ACCIONES
   ===================================================================== */
const inScope=id=>{const r=rl(id);return r&&scoped().includes(r)?r:null};
function archTog(col,id,ent,block){const it=G(col,id);if(!it)return;if(it.activo!==false&&block){const m=block(it);if(m)return openModal('No se puede archivar',`<p>${esc(m)}</p>`,'<button class="btn p" data-a="closeM">Entendido</button>')}
 if(it.activo===false){it.activo=true;log('Restauración',ent,it.nombre,it.id,'Archivado','Activo');save();toast('Restaurado');return render()}
 confirmBox('Archivar «'+it.nombre+'»','Dejará de ofrecerse en los formularios. Lo ya registrado se conserva y podrás restaurarlo desde «Ver archivados».','Archivar',()=>{it.activo=false;log('Archivado',ent,it.nombre,it.id,'Activo','Archivado');toast('Archivado')},true)}
function ensureHoja(r,sem){let h=hojaDe(r.id,sem);if(!h){h={id:nid('hj'),relId:r.id,semana:sem,lineas:[],estado:'Borrador',etapa:'',creada:TODAY,hist:[]};T().ti_hojas.push(h)}return h}
const ACT={
 ti_tab:d=>{ui[d.k]=d.t;ui.ti_arch=false;render()},
 ti_pg:d=>{ui[d.k]=U(d.k,1)+(+d.d);render()},
 ti_clr:d=>{d.k.split(',').forEach(k=>delete ui[k]);render()},
 ti_set:d=>{ui[d.k]=d.v;render()},
 ti_ir:d=>{ui.navOpen='Tiempo';go(d.r,d.k?{[d.k]:d.t}:{})},
 ti_marcar:d=>{const r=myRel();if(!r||!can('tiempo.asistencia.marcar'))return toast('Tu usuario no puede marcar asistencia');if(!metodoOn(r.sedeId,'Web'))return toast('La marcación web no está habilitada en tu sede');
  const er=marcar(r,d.t,'Web');if(er)return toast(er);save();render();const m=abierta(r.id);toast(ML[d.t]+' registrada a las '+m[d.t]+(d.t==='entrada'&&m.tardanzaMin?' · tardanza de '+m.tardanzaMin+' min':''))},
 ti_cor:d=>formCor({fecha:d.fecha,tipo:d.tipo}),
 ti_verReg:d=>{ui.navOpen='Tiempo';go('ti_asistencia',{ti_aTab:'reg',ti_rRel:d.id,ti_rDesde:addDays(TODAY,-29),ti_rHasta:TODAY,ti_rEst:'',ti_pgReg:1})},
 ti_traza:d=>traza(d.id),
 ti_regCsv:()=>{const R=regRows();csvModal('Exportar registro de asistencia',['Fecha','Colaborador','Código','Jornada','Entrada','Inicio refrigerio','Fin refrigerio','Salida','Horas','Tardanza (min)','Horas extra (min)','Nocturno (min)','Estado','Método','Vigencia'],R.rows.map(x=>{const m=x.m||{};return[x.f,nm(x.r),x.r.codigo,jTxt(x.e.j),m.entrada||'',m.refIni||'',m.refFin||'',m.salida||'',m.salida?nf(m.horasMin/60):'',m.tardanzaMin||0,m.heMin||0,m.nocMin||0,x.e.t,m.metodo||'',x.x?'Reemplazado':'Vigente']}),'Asistencia')},
 ti_cuad:d=>{if(!can('tiempo.cuadrilla'))return;const S=supRel(),ids=[...document.querySelectorAll('[data-ti-cq]:checked')].map(e=>e.dataset.tiCq);if(!ids.length)return toast('Selecciona al menos a una persona');
  let ok=0,no=0;ids.forEach(id=>{const r=S.team.find(x=>x.id===id);if(!r)return;const er=marcar(r,d.t,'Cuadrilla',{det:'Marcado por '+(S.sup&&!own(S.sup)?me().nombre+' en nombre de '+nm(S.sup):'su supervisor')});er?no++:ok++});
  save();render();toast(ok?ML[d.t]+' marcada a '+pl(ok,'persona')+(no?' · '+no+' omitida(s) porque no les corresponde':''):'A ninguna de las personas elegidas le corresponde esa marcación')},
 ti_kSel:d=>{if(!can('tiempo.kiosko'))return;Object.assign(ui,{ti_kRel:d.id,ti_kPin:'',ti_kOk:false,ti_kErr:''});kioModal()},
 ti_kDig:d=>{const r=rl(ui.ti_kRel);if(!r)return;if(d.d==='x')ui.ti_kPin=(ui.ti_kPin||'').slice(0,-1);else ui.ti_kPin=((ui.ti_kPin||'')+d.d).slice(0,4);ui.ti_kErr='';
  if(ui.ti_kPin.length===4){if(ui.ti_kPin===pinDe(r))ui.ti_kOk=true;else{ui.ti_kErr='PIN incorrecto. Inténtalo otra vez.';ui.ti_kPin='';log('PIN incorrecto en kiosko','Asistencia',nm(r),r.id,'','Intento fallido');save()}}kioModal()},
 ti_kMark:d=>{const r=rl(ui.ti_kRel);if(!r||!ui.ti_kOk)return;const er=marcar(r,d.t,'Kiosko',{det:'PIN verificado'});closeM();ui.ti_kOk=false;ui.ti_kPin='';if(er)return toast(er);save();render();toast(per(r).nombres+': '+ML[d.t].toLowerCase()+' registrada a las '+nowHM())},
 ti_metEdit:d=>{const m=G('ti_metodos',d.id);if(!m||!can('tiempo.configurar'))return;const s=get('sedes',m.sedeId)||{};
  openForm(()=>({title:m.metodo+' · '+s.nombre,note:METS.find(x=>x[0]===m.metodo)[1],fields:[{k:'activo',l:'Estado',opts:[['1','Activo'],['0','Inactivo']],req:1,keep:1},...(m.metodo==='App'?[{k:'radio',l:'Radio de la geocerca (metros)',type:'number',req:1},{k:'selfie',l:'Exigir selfie',opts:['Sí','No'],req:1,keep:1}]:m.metodo==='Kiosko'?[{k:'pin',l:'PIN obligatorio',opts:['Sí','No'],req:1,keep:1}]:m.metodo==='Biométrico'?[{k:'equipo',l:'Equipo conectado (marca, modelo o ubicación)',full:1}]:[])],
   submit:o=>{if(m.metodo==='Biométrico'&&o.activo==='1'&&!o.equipo)return 'Indica qué equipo está conectado.';const a=m.activo?'Activo':'Inactivo';m.activo=o.activo==='1';if(o.radio!==undefined)m.radio=Number(o.radio);if(o.selfie)m.selfie=o.selfie;if(o.pin)m.pin=o.pin;if(o.equipo!==undefined){m.equipo=o.equipo;m.conexion=m.activo&&o.equipo?'conexión simulada':''}
    log('Configuración de método de marcación','Asistencia',m.metodo+' · '+s.nombre,m.id,a,m.activo?'Activo':'Inactivo');toast('Método actualizado')}}),{activo:m.activo?'1':'0',radio:m.radio,selfie:m.selfie,pin:m.pin,equipo:m.equipo})},
 ti_bioSim:d=>{const m=G('ti_metodos',d.id);if(!m||!can('tiempo.configurar'))return;let n=0;T().relaciones.filter(r=>r.estado==='Activo'&&r.sedeId===m.sedeId).forEach(r=>{const e=estadoDia(r,TODAY);if(['sinmarcar','porIngresar'].includes(e.k)&&!marcar(r,'entrada','Biométrico',{det:'Lectura simulada del reloj'}))n++});
  save();render();toast(n?'Lectura simulada: '+pl(n,'entrada')+' importada(s) del reloj':'Nadie de esta sede tiene pendiente marcar entrada hoy')},
 ti_det:d=>detalle(d.k,d.id),ti_anular:d=>{closeM();anular(d.k,d.id)},
 ti_vacNew:d=>formVac(d.id),ti_ausNew:d=>formAus(d.id),ti_heNew:()=>formHE(),
 ti_compensar:()=>{const t=C('ti_ausTipos').find(x=>x.usaBolsa&&x.activo!==false);if(!t)return toast('No hay un tipo de ausencia que use la bolsa de horas');formAus('',t.id)},
 ti_record:d=>recordModal(d.id),
 ti_vacAjuste:d=>{const r=inScope(d.id);if(!r||!can('tiempo.administrar'))return;const sv=vacSaldo(r);
  openForm(()=>({title:'Ajustar saldo de vacaciones · '+nm(r),ok:'Registrar ajuste',note:`Saldo actual: <b>${sv.disp}</b> días disponibles. Usa el ajuste para cargar días gozados antes de usar el sistema o corregir un saldo. Queda en el récord con su motivo.`,
   fields:[{k:'sentido',l:'Tipo de ajuste',opts:[['1','Descontar días (ya gozados)'],['-1','Agregar días a favor']],req:1,keep:1},{k:'dias',l:'Días',type:'number',req:1},{k:'motivo',l:'Motivo',type:'textarea',req:1,full:1}],
   submit:o=>{const n=Number(o.dias);if(!(n>0))return 'Indica cuántos días ajustar.';T().ti_vacAjustes.push({id:nid('va'),relId:r.id,dias:n*Number(o.sentido),motivo:o.motivo,fecha:TODAY,por:me().nombre});
    log('Ajuste de saldo de vacaciones','Vacaciones',nm(r),r.id,sv.disp+' días',(sv.disp-n*Number(o.sentido))+' días · '+o.motivo);toast('Saldo ajustado')}}),{sentido:'1'})},
 ti_salCsv:()=>csvModal('Exportar saldos de vacaciones',['Colaborador','Código','Ingreso','Generadas','Tomadas','Programadas','En trámite','Vencidas','Por vencer','Disponibles'],vis().sort(byName).map(r=>{const s=vacSaldo(r);return[nm(r),r.codigo,r.ingreso,s.gen,s.tom,s.prog,s.tram,s.venc,s.porV,s.disp]}),'Vacaciones'),
 ti_mesMov:d=>{const m=U('ti_mes',TODAY.slice(0,7));if(d.d==='0')ui.ti_mes=TODAY.slice(0,7);else{const x=new Date(+m.slice(0,4),+m.slice(5,7)-1+(+d.d),1);ui.ti_mes=x.getFullYear()+'-'+pad(x.getMonth()+1)}render()},
 /* planificador */
 ti_semMov:d=>{ui.ti_sem=d.d==='0'?monday(TODAY):addDays(U('ti_sem',monday(TODAY)),+d.d);render()},
 ti_pincel:d=>{ui.ti_pincel=ui.ti_pincel===d.id?'':d.id;render()},
 ti_cell:d=>{if(!can('tiempo.turnos.administrar')||!inScope(d.rel))return;const p=ui.ti_pincel;
  if(p){setTurno(d.rel,d.f,p==='__del'?'':p);save();render();if(p!=='__del')avisoTurno(d.rel,d.f);return}
  const cur=turnoOf(d.rel,d.f),r=rl(d.rel),h=horarioOf(r,d.f);
  openForm(()=>({title:'Turno · '+nm(r)+' · '+dShort(d.f),ok:'Guardar',note:'Sin turno asignado, la persona trabaja con su horario habitual: <b>'+esc(h.nombre)+'</b>.',fields:[{k:'tipoId',l:'Turno',opts:[['','Sin turno (horario habitual)'],...C('ti_turnoTipos').filter(t=>t.activo!==false).map(t=>[t.id,t.nombre+' · '+tHoras(t)])],req:0,keep:1}],
   submit:o=>{setTurno(d.rel,d.f,o.tipoId);setTimeout(()=>o.tipoId&&avisoTurno(d.rel,d.f),50)}}),{tipoId:cur?cur.tipoId:''})},
 ti_copiaSem:()=>{if(!can('tiempo.turnos.administrar'))return;const sem=U('ti_sem',monday(TODAY));let n=0;planRels().forEach(r=>range(sem,addDays(sem,6)).forEach(f=>{const p=turnoOf(r.id,addDays(f,-7));if(p&&!turnoOf(r.id,f)){setTurno(r.id,f,p.tipoId,true);n++}}));
  if(!n)return toast('La semana anterior no tiene turnos que copiar a celdas vacías');log('Copia de semana de turnos','Turnos','Semana del '+fd(sem),'','',n+' turnos copiados');save();render();const c=conflictos(planRels(),sem,addDays(sem,6)).length;toast(pl(n,'turno')+' copiado(s)'+(c?' · '+pl(c,'conflicto')+' por revisar':''))},
 ti_limpiaSem:()=>{if(!can('tiempo.turnos.administrar'))return;const sem=U('ti_sem',monday(TODAY)),ids=planRels().map(r=>r.id),n=C('ti_turnos').filter(t=>ids.includes(t.relId)&&t.fecha>=sem&&t.fecha<=addDays(sem,6)).length;if(!n)return toast('Esta semana no tiene turnos asignados');
  confirmBox('Limpiar semana',`Se quitarán <b>${pl(n,'turno')}</b> de las personas visibles en la semana del ${fd(sem)}. Volverán a su horario habitual.`,'Quitar turnos',()=>{T().ti_turnos=T().ti_turnos.filter(t=>!(ids.includes(t.relId)&&t.fecha>=sem&&t.fecha<=addDays(sem,6)));log('Limpieza de semana de turnos','Turnos','Semana del '+fd(sem),'',n+' turnos','0 turnos');toast('Semana limpia')},true)},
 ti_masivo:()=>{if(!can('tiempo.turnos.administrar'))return;const sem=U('ti_sem',monday(TODAY)),tp=C('ti_turnoTipos').filter(t=>t.activo!==false).map(t=>[t.id,t.nombre+' · '+tHoras(t)]),rs=planRels();if(!tp.length||!rs.length)return toast('No hay tipos de turno o personas para asignar');
  openForm(v=>{const rot=v.modo==='Rotación semanal';return{title:'Asignación masiva de turnos',ok:'Asignar',note:rot?'Rotación: cada semana del rango toma el siguiente turno de la secuencia y vuelve a empezar (turno rotativo).':'El mismo turno para todas las personas y días elegidos.',
   fields:[{k:'rels',l:'Personas',type:'checks',opts:rs.map(r=>[r.id,nm(r)]),req:1,full:1},{k:'modo',l:'Modo',opts:['Un mismo turno','Rotación semanal'],req:1,keep:1,dyn:1},...(rot?[{k:'t1',l:'Turno de la semana 1',opts:tp,req:1,keep:1},{k:'t2',l:'Turno de la semana 2',opts:tp,req:1,keep:1},{k:'t3',l:'Turno de la semana 3 (opcional)',opts:tp}]:[{k:'t1',l:'Turno',opts:tp,req:1,keep:1}]),
    {k:'desde',l:'Desde',type:'date',req:1},{k:'hasta',l:'Hasta',type:'date',req:1},{k:'dias',l:'Días de la semana',type:'checks',opts:[1,2,3,4,5,6,0].map(x=>[String(x),DN[x]]),req:1,full:1},{k:'omitir',l:'Días con vacaciones o permisos aprobados',opts:[['1','Omitirlos'],['0','Asignar igual (quedará como conflicto)']],req:1,keep:1}],
   submit:o=>{if(o.hasta<o.desde)return 'La fecha final no puede ser anterior a la inicial.';if(days(o.desde,o.hasta)>62)return 'Asigna como máximo 9 semanas por vez.';const seq=rot?[o.t1,o.t2,o.t3].filter(Boolean):[o.t1],w0=monday(o.desde);let n=0,om=0;
    o.rels.forEach(id=>{if(!inScope(id))return;range(o.desde,o.hasta).forEach(f=>{if(!o.dias.includes(String(dow(f))))return;const a=ausEn(id,f);if(o.omitir==='1'&&(vacEn(id,f)||(a&&!tipoAus(a).presencial))){om++;return}setTurno(id,f,seq[Math.floor(days(w0,monday(f))/7)%seq.length],true);n++})});
    if(!n)return 'No se asignó ningún turno: revisa los días elegidos.';log('Asignación masiva de turnos','Turnos',pl(o.rels.length,'persona')+' · '+fd(o.desde)+' al '+fd(o.hasta),'','',n+' turnos'+(rot?' en rotación':''));ui.ti_sem=w0;toast(pl(n,'turno')+' asignado(s)'+(om?' · '+om+' omitido(s) por ausencias':''))}}},{modo:'Un mismo turno',rels:[],t1:tp[0][0],t2:(tp[1]||tp[0])[0],t3:'',desde:sem,hasta:addDays(sem,6),dias:['1','2','3','4','5','6'],omitir:'1'})},
 ti_tipoNew:()=>{if(can('tiempo.configurar'))formTipo()},ti_tipoEdit:d=>{if(can('tiempo.configurar'))formTipo(d.id)},ti_tipoArch:d=>{if(can('tiempo.configurar'))archTog('ti_turnoTipos',d.id,'Tipos de turno')},
 ti_horNew:()=>{if(can('tiempo.configurar'))formHorario()},ti_horEdit:d=>{if(can('tiempo.configurar'))formHorario(d.id)},ti_horArch:d=>can('tiempo.configurar')&&archTog('ti_horarios',d.id,'Horarios',h=>CF().horarioId===h.id?'«'+h.nombre+'» es el horario de la política general. Elige otro horario general antes de archivarlo.':nHor(h.id)?'«'+h.nombre+'» todavía está asignado a '+pl(nHor(h.id),'persona')+'. Cámbiales el horario primero.':''),
 ti_asigEdit:d=>{if(can('tiempo.turnos.administrar')&&inScope(d.id))formAsig([d.id])},ti_asigMas:()=>{if(can('tiempo.turnos.administrar'))formAsig()},
 ti_cfgEdit:()=>{if(can('tiempo.configurar'))formCfg()},ti_polNew:()=>{if(can('tiempo.configurar'))formPol()},ti_polEdit:d=>{if(can('tiempo.configurar'))formPol(d.id)},
 ti_polDel:d=>{const p=G('ti_politicas',d.id);if(!p||!can('tiempo.configurar'))return;const o=get(p.ambito==='Sede'?'sedes':'empresas',p.refId)||{};confirmBox('Quitar política','«'+esc(o.nombre||'')+'» volverá a usar la política general (o la de su empresa).','Quitar',()=>{p.activo=false;log('Retiro de política','Políticas de tiempo',o.nombre||'',p.id,polTxt(p),'Política general');toast('Política retirada')},true)},
 ti_ferNew:()=>{if(can('tiempo.configurar'))formFer()},ti_ferEdit:d=>{if(can('tiempo.configurar'))formFer(d.id)},
 ti_ferDel:d=>{const f=G('feriados',d.id);if(!f||!can('tiempo.configurar'))return;confirmBox('Eliminar feriado',`«${esc(f.nombre)}» (${fd(f.fecha)}) dejará de considerarse feriado: quien no marque ese día figurará con falta.`,'Eliminar',()=>{T().feriados=T().feriados.filter(x=>x.id!==f.id);log('Eliminación','Feriados',f.nombre,f.id,fd(f.fecha),'');toast('Feriado eliminado')},true)},
 ti_ferCarga:()=>{if(!can('tiempo.configurar'))return;const y=U('ti_fAnio',TODAY.slice(0,4)),n=cargaFer(y,T());if(n)log('Carga de calendario','Feriados','Perú '+y,'','',n+' feriados');save();render();toast(n?pl(n,'feriado')+' de Perú '+y+' agregado(s)':'El calendario de Perú '+y+' ya estaba completo')},
 ti_plantilla:()=>{if(!can('tiempo.configurar'))return;const n=baseCat(T());log('Carga de plantilla Perú','Tiempo','Horarios, turnos, tipos de ausencia y feriados','','',n+' registros');save();render();toast(n?'Plantilla Perú cargada: '+n+' registros':'La plantilla ya estaba cargada')},
 ti_atNew:()=>{if(can('tiempo.configurar'))formAusTipo()},ti_atEdit:d=>{if(can('tiempo.configurar'))formAusTipo(d.id)},ti_atArch:d=>{if(can('tiempo.configurar'))archTog('ti_ausTipos',d.id,'Tipos de ausencia',t=>C('ausencias').some(a=>a.tipoId===t.id&&a.estado==='Pendiente')?'Hay solicitudes pendientes de «'+t.nombre+'». Resuélvelas antes de archivar el tipo.':'')},
 /* bolsa de horas */
 ti_acred:d=>{const x=porAcreditar().find(p=>p.s.id===d.id);if(!x||!(can('tiempo.administrar')||puedeAcred(x.s))||!(x.h>0))return;const s=x.s;s.acreditada=true;s.horasAcred=x.h;hist(s,'Acreditó '+nf(x.h)+' h en la bolsa de horas');
  T().ti_bolsa.push({id:nid('bh'),relId:s.relId,fecha:TODAY,horas:x.h,concepto:'Horas extra del '+fd(s.fecha)+' (autorizadas '+nf(s.horas)+' h)',refId:s.id,por:me().nombre});log('Acreditación en bolsa de horas','Horas extra',relName(s.relId),s.relId,'',nf(x.h)+' h del '+fd(s.fecha));save();render();toast(nf(x.h)+' h acreditadas a '+relName(s.relId))},
 ti_bolsaAj:()=>{if(!can('tiempo.administrar'))return;openForm(()=>({title:'Ajuste de bolsa de horas',ok:'Registrar',fields:[{k:'relId',l:'Colaborador',opts:paraQuien(true),req:1},{k:'sentido',l:'Tipo',opts:[['1','Agregar horas'],['-1','Descontar horas']],req:1,keep:1},{k:'horas',l:'Horas',type:'number',req:1},{k:'concepto',l:'Concepto',req:1,full:1}],
  submit:o=>{const h=Number(o.horas)*Number(o.sentido);if(!h)return 'Indica cuántas horas ajustar.';if(saldoBolsa(o.relId)+h<0)return 'El saldo no puede quedar negativo (hoy tiene '+nf(saldoBolsa(o.relId))+' h).';T().ti_bolsa.push({id:nid('bh'),relId:o.relId,fecha:TODAY,horas:h,concepto:'Ajuste: '+o.concepto,por:me().nombre});log('Ajuste de bolsa de horas','Horas extra',relName(o.relId),o.relId,'',(h>0?'+':'')+nf(h)+' h · '+o.concepto);ui.ti_bRel=o.relId;toast('Ajuste registrado')}}),{sentido:'1'})},
 /* hojas de tiempo */
 ti_hSemMov:d=>{ui.ti_hSem=d.d==='0'?monday(TODAY):addDays(U('ti_hSem',monday(TODAY)),+d.d);render()},
 ti_hojaDel:d=>{const r=myRel(),h=r&&hojaDe(r.id,U('ti_hSem',monday(TODAY)));if(!h||!['Borrador','Rechazada'].includes(h.estado))return;h.lineas.splice(+d.i,1);save();render()},
 ti_hojaCopia:()=>{const r=myRel();if(!r)return;const sem=U('ti_hSem',monday(TODAY)),p=hojaDe(r.id,addDays(sem,-7));if(!p)return;const h=ensureHoja(r,sem);p.lineas.forEach(l=>{if(!h.lineas.some(x=>x.proyectoId===l.proyectoId)&&(G('ti_proyectos',l.proyectoId)||{}).activo!==false)h.lineas.push({proyectoId:l.proyectoId,h:[0,0,0,0,0,0,0]})});save();render();toast('Proyectos copiados: completa tus horas')},
 ti_hojaEnviar:()=>{const r=myRel(),h=r&&hojaDe(r.id,U('ti_hSem',monday(TODAY)));if(!h||!['Borrador','Rechazada'].includes(h.estado))return;if(!(hojaTot(h)>0))return toast('Registra al menos una hora antes de enviar');
  for(let i=0;i<7;i++)if(h.lineas.reduce((s,l)=>s+(+l.h[i]||0),0)>24)return toast('El '+dShort(addDays(h.semana,i))+' suma más de 24 horas');
  h.lineas=h.lineas.filter(l=>l.h.some(x=>+x>0));h.estado='Pendiente';h.etapa=etapaIni(r);h.creada=TODAY;h.motivoRechazo='';hist(h,'Envió la hoja de tiempo');log('Envío de hoja de tiempo','Hoja de tiempo',nm(r)+' · semana del '+fd(h.semana),r.id,'Borrador','Enviada · '+nf(hojaTot(h))+' h');save();render();toast('Hoja enviada a '+destino(r))},
 ti_hojaRetirar:()=>{const r=myRel(),h=r&&hojaDe(r.id,U('ti_hSem',monday(TODAY)));if(!h||h.estado!=='Pendiente')return;h.estado='Borrador';h.etapa='';hist(h,'Retiró el envío');log('Retiro de hoja de tiempo','Hoja de tiempo',nm(r)+' · semana del '+fd(h.semana),r.id,'Enviada','Borrador');save();render();toast('Envío retirado: puedes editarla')},
 ti_proyNew:()=>{if(can('tiempo.hojas.administrar'))formProy()},ti_proyEdit:d=>{if(can('tiempo.hojas.administrar'))formProy(d.id)},ti_proyArch:d=>{if(can('tiempo.hojas.administrar'))archTog('ti_proyectos',d.id,'Proyectos')}};
KS.forEach(k=>{ACT['ti_'+k+'Aprobar']=d=>aprobar(k,d.id);ACT['ti_'+k+'Rechazar']=d=>{closeM();rechazar(k,d.id)}});

/* ---------- eventos propios: arrastrar y soltar, horas, selección ---------- */
const cellOf=e=>e.target&&e.target.closest?e.target.closest('[data-ti-cell]'):null;
document.addEventListener('dragstart',e=>{const el=e.target&&e.target.closest?e.target.closest('[data-ti-drag]'):null;if(!el)return;e.dataTransfer.setData('text/plain',el.dataset.tiDrag);e.dataTransfer.effectAllowed='copyMove'});
document.addEventListener('dragover',e=>{const c=cellOf(e);if(!c)return;e.preventDefault();c.classList.add('ti-over')});
document.addEventListener('dragleave',e=>{const c=cellOf(e);if(c&&!c.contains(e.relatedTarget))c.classList.remove('ti-over')});
document.addEventListener('drop',e=>{const c=cellOf(e);if(!c)return;e.preventDefault();c.classList.remove('ti-over');if(!session||session.saas||!can('tiempo.turnos.administrar'))return;
 const src=e.dataTransfer.getData('text/plain')||'',[rel,f]=c.dataset.tiCell.split('|');if(!inScope(rel))return;
 if(src.startsWith('tipo:'))setTurno(rel,f,src.slice(5));
 else if(src.startsWith('cell:')){const [r0,f0]=src.slice(5).split('|'),t0=turnoOf(r0,f0);if(!t0||(r0===rel&&f0===f))return;setTurno(rel,f,t0.tipoId);if(!e.ctrlKey)setTurno(r0,f0,'')}
 else return;save();render();avisoTurno(rel,f)});
document.addEventListener('change',e=>{const d=e.target.dataset||{};
 if(d.tiH){const [hid,li,di]=d.tiH.split('|'),h=G('ti_hojas',hid);if(!h||!['Borrador','Rechazada'].includes(h.estado)||!own(rl(h.relId)))return;let v=Math.round(Math.max(0,Math.min(24,Number(e.target.value)||0))*4)/4;h.lineas[+li].h[+di]=v;save();e.target.value=v||'';
  /* se actualizan los totales en el sitio, sin volver a dibujar: así no se pierde el foco ni el clic siguiente */
  const set=(id,t)=>{const el=document.getElementById(id);if(el)el.textContent=t};set('ti_ht_r'+li,nf(h.lineas[+li].h.reduce((a,b)=>a+(+b||0),0)));const dv=h.lineas.reduce((a,l)=>a+(+l.h[+di]||0),0);set('ti_ht_d'+di,dv?nf(dv):'—');set('ti_ht_t',nf(hojaTot(h))+' h')}
 else if(d.c==='ti_hAdd'&&ui.ti_hAdd){const r=myRel();if(r){const h=ensureHoja(r,U('ti_hSem',monday(TODAY)));if(['Borrador','Rechazada'].includes(h.estado)&&!h.lineas.some(l=>l.proyectoId===ui.ti_hAdd))h.lineas.push({proyectoId:ui.ti_hAdd,h:[0,0,0,0,0,0,0]})}ui.ti_hAdd='';save();render()}
 else if('tiAll' in d)document.querySelectorAll('[data-ti-cq]:not(:disabled)').forEach(x=>x.checked=e.target.checked)});
setInterval(()=>{const h=nowHM();document.querySelectorAll('[data-ti-clock]').forEach(e=>{if(e.textContent!==h)e.textContent=h})},15000);
on('employee.terminated',p=>{const r=p&&p.rel;if(!r)return;let n=0;KS.forEach(k=>C(K[k].col).forEach(s=>{if(s.relId===r.id&&s.estado==='Pendiente'){s.estado=k==='hoja'?'Borrador':'Anulada';s.etapa='';hist(s,'Anulada automáticamente por cese del colaborador');n++}}));
 const t=T();t.ti_turnos=t.ti_turnos.filter(x=>!(x.relId===r.id&&x.fecha>(r.cese||TODAY)));if(n)log('Anulación automática por cese','Tiempo',nm(r),r.id,n+' solicitudes pendientes','Anuladas')});

/* =====================================================================
   DATOS: catálogos base (plantilla Perú) y demo
   ===================================================================== */
function cargaFer(y,t){let n=0;ferPeru(+y).forEach(f=>{if(!t.feriados.some(x=>x.fecha===f[0]&&(x.pais||'Perú')==='Perú')){t.feriados.push({id:'fe'+f[0].replace(/-/g,''),fecha:f[0],nombre:f[1],pais:'Perú'});n++}});return n}
function baseCat(t){let n=0;const L=[1,2,3,4,5];
 if(!t.ti_horarios.length){t.ti_horarios.push({id:'ho1',nombre:'Oficina · lunes a viernes',entrada:'09:00',salida:'18:00',refMin:60,tolMin:10,dias:L,horasDia:8,horasSem:40,activo:true},{id:'ho2',nombre:'Operaciones · lunes a sábado',entrada:'07:00',salida:'16:00',refMin:60,tolMin:5,dias:[1,2,3,4,5,6],horasDia:8,horasSem:48,activo:true},{id:'ho3',nombre:'Comercial regiones',entrada:'08:30',salida:'17:30',refMin:60,tolMin:15,dias:L,horasDia:8,horasSem:40,activo:true},{id:'ho4',nombre:'Medio tiempo · mañanas',entrada:'09:00',salida:'13:00',refMin:0,tolMin:10,dias:L,horasDia:4,horasSem:20,activo:true});n+=4}
 if(!t.ti_cfg.horarioId)t.ti_cfg.horarioId=t.ti_horarios[0].id;
 if(!t.ti_turnoTipos.length){t.ti_turnoTipos.push({id:'tt1',nombre:'Mañana',clase:'Mañana',entrada:'06:00',salida:'14:00',refMin:45,color:1,activo:true},{id:'tt2',nombre:'Tarde',clase:'Tarde',entrada:'14:00',salida:'22:00',refMin:45,color:2,activo:true},{id:'tt3',nombre:'Noche',clase:'Noche',entrada:'22:00',salida:'06:00',refMin:45,color:3,activo:true},{id:'tt4',nombre:'Partido',clase:'Partido',entrada:'09:00',salida:'13:00',entrada2:'16:00',salida2:'20:00',refMin:0,color:0,activo:true},{id:'tt5',nombre:'Guardia 12 h',clase:'Rotativo',entrada:'07:00',salida:'19:00',refMin:60,color:0,activo:true},{id:'tt6',nombre:'Descanso',clase:'Descanso',libre:true,entrada:'',salida:'',refMin:0,color:4,activo:true});n+=6}
 if(!t.ti_ausTipos.length){[['at1','Descanso médico',1,1,20,0,0,1,0],['at2','Licencia por paternidad',1,1,10,0,0,1,0],['at3','Licencia por maternidad',1,1,98,0,0,1,0],['at4','Permiso personal',0,0,3,0,0,0,0],['at5','Compensación de horas',1,0,5,0,1,0,0],['at6','Trabajo remoto',1,0,5,0,0,0,1],['at7','Licencia sindical',1,1,30,0,0,1,0],['at8','Licencia por fallecimiento de familiar',1,1,5,0,0,1,0],['at9','Permiso a cuenta de vacaciones',1,0,7,1,0,0,0],['at10','Otros',0,0,0,0,0,1,0]]
  .forEach(a=>t.ti_ausTipos.push({id:a[0],nombre:a[1],goce:!!a[2],sustento:!!a[3],maxDias:a[4],descuentaVac:!!a[5],usaBolsa:!!a[6],validaRRHH:!!a[7],presencial:!!a[8],activo:true}));n+=10}
 n+=cargaFer(TODAY.slice(0,4),t);return n}
function seedDemo(t){const A=n=>addDays(TODAY,n),R=id=>t.relaciones.find(r=>r.id===id),N=id=>{const r=R(id),p=t.personas.find(x=>x.id===r.personaId);return p.nombres+' '+p.apellidos};
 let sd=11;const rnd=()=>(sd=(sd*9301+49297)%233280)/233280,ri=(a,b)=>a+Math.floor(rnd()*(b-a+1));
 const H=(ago,quien,accion,nota)=>({ts:A(-ago)+'T'+pad(ri(9,17))+':'+pad(ri(0,59))+':00',quien,accion,nota:nota||''});
 const jefeN=id=>{const r=R(id);return r.jefeId?N(r.jefeId):'Carmen Huamán Rojas'},RH='Carmen Huamán Rojas';
 cargaFer(+TODAY.slice(0,4)+1,t);if(TODAY.slice(5,7)<'03')cargaFer(+TODAY.slice(0,4)-1,t);
 t.ti_politicas.push({id:'po1',ambito:'Empresa',refId:'e2',horarioId:'ho2',vacDias:'',vacValidaRRHH:'',activo:true},{id:'po2',ambito:'Sede',refId:'s2',horarioId:'ho3',vacDias:'',vacValidaRRHH:'',activo:true},{id:'po3',ambito:'Empresa',refId:'e3',horarioId:'',vacDias:15,vacValidaRRHH:'No',activo:true});
 [['s1',['Web','Kiosko','Cuadrilla','Biométrico']],['s2',['Web','App']],['s3',['Web','Kiosko','Cuadrilla']],['s4',['Web','App']]].forEach(([s,ons])=>metodosDe(s).forEach(m=>{m.activo=ons.includes(m.metodo);if(m.metodo==='Biométrico'&&m.activo){m.equipo='Reloj de huella · recepción';m.conexion='conexión simulada'}}));
 /* turnos del almacén: rotación semanal de Pedro y turno fijo de Elena */
 const mon=monday(TODAY);
 for(let w=-3;w<=1;w++){const wk=addDays(mon,7*w),rot=['tt1','tt2','tt3'][((Math.floor(days('2026-01-05',wk)/7)%3)+3)%3];
  for(let i=0;i<7;i++){const f=addDays(wk,i);t.ti_turnos.push({id:nid('tu'),relId:'rl17',fecha:f,tipoId:i===6?'tt6':rot});
   if(w<=0&&i<6)t.ti_turnos.push({id:nid('tu'),relId:'rl16',fecha:f,tipoId:w===0&&i===4?'tt2':'tt1'})}}
 /* vacaciones */
 const V=(id,rel,d,h,est,et,com,hi)=>t.vacSolicitudes.push({id,relId:rel,desde:A(d),hasta:A(h),dias:h-d+1,estado:est,etapa:et||'',comentario:com||'',creada:hi[0].ts.slice(0,10),hist:hi,...(est==='Rechazada'?{motivoRechazo:hi[hi.length-1].nota}:{}),...(est==='Anulada'?{motivoAnulacion:hi[hi.length-1].nota}:{})});
 V('vs1','rl14',-45,-41,'Aprobada','','Viaje familiar a Huancayo',[H(60,N('rl14'),'Solicitó vacaciones','Viaje familiar a Huancayo'),H(58,N('rl12'),'Aprobó como jefe directo'),H(57,RH,'Validó como RRHH')]);
 V('vs2','rl14',-10,-8,'Rechazada','','',[H(20,N('rl14'),'Solicitó vacaciones'),H(19,N('rl12'),'Rechazó','Coincide con el cierre del sprint; reprograma para la semana siguiente.')]);
 V('vs3','rl13',20,26,'Pendiente','Jefe','Semana de descanso',[H(1,N('rl13'),'Solicitó vacaciones','Semana de descanso')]);
 V('vs4','rl7',12,16,'Pendiente','Jefe','',[H(2,N('rl7'),'Solicitó vacaciones')]);
 V('vs5','rl11',12,18,'Pendiente','RRHH','Matrimonio de mi hermana en Trujillo',[H(3,N('rl11'),'Solicitó vacaciones','Matrimonio de mi hermana en Trujillo'),H(2,N('rl8'),'Aprobó como jefe directo')]);
 V('vs6','rl4',30,36,'Pendiente','Jefe','',[H(1,N('rl4'),'Solicitó vacaciones')]);
 V('vs7','rl12',40,46,'Pendiente','Jefe','Después del lanzamiento',[H(2,N('rl12'),'Solicitó vacaciones','Después del lanzamiento')]);
 V('vs8','rl9',-2,4,'Aprobada','','',[H(25,N('rl9'),'Solicitó vacaciones'),H(24,N('rl8'),'Aprobó como jefe directo'),H(23,RH,'Validó como RRHH')]);
 V('vs9','rl17',4,10,'Aprobada','','',[H(18,N('rl17'),'Solicitó vacaciones'),H(17,N('rl16'),'Aprobó como jefe directo'),H(15,RH,'Validó como RRHH')]);
 V('vs10','rl2',-25,-19,'Aprobada','','',[H(50,N('rl2'),'Solicitó vacaciones'),H(49,N('rl1'),'Aprobó como jefe directo'),H(48,RH,'Validó como RRHH')]);
 V('vs11','rl6',14,20,'Aprobada','','',[H(12,N('rl6'),'Solicitó vacaciones'),H(11,N('rl2'),'Aprobó y validó')]);
 V('vs12','rl16',15,21,'Aprobada','','',[H(9,N('rl16'),'Solicitó vacaciones'),H(8,N('rl15'),'Aprobó como jefe directo'),H(7,RH,'Validó como RRHH')]);
 V('vs13','rl18',25,29,'Anulada','','',[H(14,N('rl18'),'Solicitó vacaciones'),H(10,N('rl18'),'Anuló','Cambio de fecha del viaje')]);
 /* ausencias y permisos */
 const lastLab=(id,ago)=>{const r=R(id);for(let d=ago;d<ago+12;d++){const f=A(-d);if(jornada(r,f).lab&&!vacEn(id,f)&&!ausEn(id,f,['Aprobada','Pendiente']))return f}return A(-ago)};
 const AU=(id,rel,tp,d,h,est,et,mot,sus,hi,ex)=>t.ausencias.push({id,relId:rel,tipoId:tp,tipo:t.ti_ausTipos.find(x=>x.id===tp).nombre,desde:d,hasta:h,dias:days(d,h)+1,estado:est,etapa:et||'',motivo:mot,sustento:sus||'',creada:hi[0].ts.slice(0,10),hist:hi,...(ex||{})});
 AU('au1','rl11','at1',A(-12),A(-11),'Aprobada','','Cuadro gripal con descanso indicado','CITT-00231.pdf',[H(12,N('rl11'),'Solicitó descanso médico','Cuadro gripal con descanso indicado'),H(11,N('rl8'),'Aprobó como jefe directo'),H(11,RH,'Validó como RRHH')]);
 AU('au2','rl5','at6',A(0),A(0),'Aprobada','','Cierre de planilla desde casa','',[H(2,N('rl5'),'Solicitó trabajo remoto','Cierre de planilla desde casa'),H(1,RH,'Aprobó')]);
 AU('au3','rl14','at4',A(5),A(5),'Pendiente','Jefe','Trámite personal en RENIEC','',[H(1,N('rl14'),'Solicitó permiso personal','Trámite personal en RENIEC')]);
 AU('au4','rl4','at2',A(-9),A(0),'Aprobada','','Nacimiento de su hijo','acta-de-nacimiento.pdf',[H(10,N('rl4'),'Solicitó licencia por paternidad','Nacimiento de su hijo'),H(10,RH,'Aprobó y validó como RRHH')]);
 const F1=lastLab('rl10',6),FC=lastLab('rl16',8);
 AU('au5','rl10','at1',F1,F1,'Pendiente','Jefe','Atención por emergencia; presento el certificado','CITT-00247.pdf',[H(4,N('rl10'),'Solicitó descanso médico','Atención por emergencia; presento el certificado')]);
 AU('au6','rl16','at5',FC,FC,'Aprobada','','Compensación por inventario del mes pasado','',[H(12,N('rl16'),'Solicitó compensación de horas','Compensación por inventario del mes pasado'),H(11,N('rl15'),'Aprobó')],{bolsaH:8});
 /* saldos iniciales de vacaciones (días gozados antes de usar el sistema) */
 const resid={rl15:36,rl12:12,rl18:9,rl1:21,rl17:3};
 t.relaciones.filter(r=>r.estado==='Activo'&&r.id!=='rl14').forEach(r=>{const sv=vacSaldo(r),aj=sv.gen-sv.tom-sv.prog-(resid[r.id]!==undefined?resid[r.id]:ri(5,22));if(aj>0)t.ti_vacAjustes.push({id:nid('va'),relId:r.id,dias:aj,motivo:'Días gozados antes de la puesta en marcha (saldo inicial migrado)',fecha:A(-90),por:'Carga inicial'})});
 /* marcaciones de las últimas 4 semanas */
 const F2=lastLab('rl17',3),F3=lastLab('rl18',9),F4=lastLab('rl13',3),F5=lastLab('rl6',7),F6=lastLab('rl14',2),F7=lastLab('rl7',4),F8=lastLab('rl13',5);
 const falta={['rl10|'+F1]:1,['rl17|'+F2]:1,['rl18|'+F3]:1},prone=['rl10','rl14','rl17'],heP=['rl13','rl7','rl16'],hoyNo=['rl2','rl3','rl12','rl13','rl14'];
 const met=r=>r.id==='rl17'?['Cuadrilla','Marcado por su supervisora Elena Yupanqui Cruz']:r.sedeId==='s3'?['Kiosko','PIN verificado']:r.sedeId==='s2'||r.sedeId==='s4'?['App','Dentro de la geocerca · selfie verificada']:r.id==='rl13'||(+r.id.slice(2))%2?['Web','Sesión del colaborador']:['Biométrico','Reloj de huella · recepción'];
 const mk=(r,f,ent,sal,r1,r2)=>{const [mt,det]=met(r),m={id:nid('mk'),relId:r.id,fecha:f,entrada:ent,salida:sal||'',refIni:r1||'',refFin:r2||'',metodo:mt,tardanzaMin:0,heMin:0,nocMin:0,horasMin:0,estado:'Vigente',eventos:[]};
  [['entrada',ent],['refIni',r1],['refFin',r2],['salida',sal]].forEach(([k,h])=>{if(h)m.eventos.push({t:k,h,ts:f+'T'+h+':00',metodo:mt,por:mt==='Cuadrilla'?'Elena Yupanqui Cruz':N(r.id),det})});t.marcaciones.push(m);calc(m);return m};
 const now=nowMin();
 t.relaciones.filter(r=>r.estado==='Activo').forEach(r=>{for(let d=27;d>=0;d--){const f=A(-d);if(f<r.ingreso)continue;const j=jornada(r,f);if(!j.lab||vacEn(r.id,f)||falta[r.id+'|'+f])continue;const a=ausEn(r.id,f);if(a&&!tipoAus(a).presencial)continue;
   if(d===0&&hoyNo.includes(r.id))continue;
   const e0=hm(j.entrada);let s0=hm(j.salida);if(s0<=e0)s0+=1440;
   const tar=(r.id==='rl14'&&f===F6)||(r.id==='rl7'&&f===F7)||rnd()<(prone.includes(r.id)?.2:.05);
   const ent=e0+(r.id==='rl14'&&f===F6?25:r.id==='rl7'&&f===F7?18:tar?j.tolMin+ri(3,26):ri(-12,Math.min(4,j.tolMin)));
   const conRef=j.refMin>0&&j.refMin<=90&&s0-e0>=360,r1=e0+240+ri(-10,20),r2=r1+j.refMin+ri(-6,5);
   let sal=s0+(r.id==='rl13'&&f===F8?ri(142,150):heP.includes(r.id)&&rnd()<.28?ri(45,125):ri(0,14));
   if(d===0){if(now<ent||j.noct)continue;mk(r,f,mh(ent),now>=sal?mh(sal):'',conRef&&now>=r1?mh(r1):'',conRef&&now>=r2?mh(r2):'');continue}
   if(r.id==='rl13'&&f===F4){mk(r,f,mh(ent),'',conRef?mh(r1):'',conRef?mh(r2):'');continue}
   mk(r,f,mh(ent),mh(sal),conRef?mh(r1):'',conRef?mh(r2):'')}});
 /* correcciones e incidencias */
 const o5=t.marcaciones.find(m=>m.relId==='rl6'&&m.fecha===F5);
 if(o5){const ok=o5.salida;o5.salida='13:02';o5.refIni='';o5.refFin='';o5.eventos=o5.eventos.filter(e=>e.t==='entrada').concat([{t:'salida',h:'13:02',ts:F5+'T13:02:00',metodo:o5.metodo,por:N('rl6')}]);calc(o5);
  const n5={...o5,id:nid('mk'),salida:ok,estado:'Vigente',origenId:o5.id,correccionId:'co1',corregido:true,eventos:o5.eventos.concat([{t:'correccion',h:'10:40',ts:addDays(F5,1)+'T10:40:00',metodo:'Corrección de marcación',por:N('rl2'),nota:'Marqué salida por error al ir a almorzar; salí a las '+ok}])};
  o5.estado='Reemplazada';o5.reemplazadaPor=n5.id;t.marcaciones.push(n5);calc(n5);
  t.ti_correcciones.push({id:'co1',relId:'rl6',tipo:'Corrección de marcación',fecha:F5,entrada:'',salida:ok,refIni:'',refFin:'',motivo:'Marqué salida por error al ir a almorzar; salí a las '+ok,estado:'Aprobada',etapa:'',creada:addDays(F5,1),resuelta:addDays(F5,1),resueltaPor:N('rl2'),origenId:o5.id,nuevoId:n5.id,hist:[{ts:addDays(F5,1)+'T09:05:00',quien:N('rl6'),accion:'Reportó la incidencia',nota:'Marqué salida por error al ir a almorzar'},{ts:addDays(F5,1)+'T10:40:00',quien:N('rl2'),accion:'Aprobó',nota:''}]})}
 t.ti_correcciones.push({id:'co2',relId:'rl13',tipo:'Olvidé marcar',fecha:F4,entrada:'',salida:'18:10',refIni:'',refFin:'',motivo:'Me desconecté sin marcar la salida; terminé a las 18:10',estado:'Pendiente',etapa:'Jefe',creada:addDays(F4,1),hist:[{ts:addDays(F4,1)+'T09:20:00',quien:N('rl13'),accion:'Reportó la incidencia',nota:'Me desconecté sin marcar la salida; terminé a las 18:10'}]},
  {id:'co3',relId:'rl7',tipo:'Tardanza justificada',fecha:F7,entrada:'',salida:'',refIni:'',refFin:'',motivo:'Cita médica de control a primera hora; entregué la constancia',estado:'Pendiente',etapa:'Jefe',creada:addDays(F7,1),hist:[{ts:addDays(F7,1)+'T08:50:00',quien:N('rl7'),accion:'Reportó la incidencia',nota:'Cita médica de control a primera hora; entregué la constancia'}]});
 /* horas extra y bolsa */
 const HE=(id,rel,f,h,comp,est,mot,hi,ex)=>t.ti_heSolicitudes.push({id,relId:rel,fecha:f,horas:h,compensacion:comp,motivo:mot,estado:est,etapa:est==='Pendiente'?'Jefe':'',creada:hi[0].ts.slice(0,10),hist:hi,...(ex||{})});
 HE('he1','rl13',F8,2,'Bolsa de horas','Aprobada','Migración de base de datos fuera de horario',[H(7,N('rl13'),'Solicitó horas extra','Migración de base de datos fuera de horario'),H(6,N('rl12'),'Aprobó')]);
 HE('he2','rl14',A(1),2,'Pago con sobretasa','Pendiente','Pase a producción del portal de clientes',[H(0,N('rl14'),'Solicitó horas extra','Pase a producción del portal de clientes')]);
 HE('he3','rl7',A(-10),3,'Pago con sobretasa','Aprobada','Cierre contable mensual',[H(12,N('rl7'),'Solicitó horas extra','Cierre contable mensual'),H(11,N('rl6'),'Aprobó')]);
 HE('he4','rl16',A(-15),4,'Bolsa de horas','Aprobada','Inventario general del almacén',[H(17,N('rl16'),'Solicitó horas extra','Inventario general del almacén'),H(16,N('rl15'),'Aprobó'),H(14,RH,'Acreditó 4 h en la bolsa de horas')],{acreditada:true,horasAcred:4});
 const B=(rel,ago,h,c,ref)=>t.ti_bolsa.push({id:nid('bh'),relId:rel,fecha:A(-ago),horas:h,concepto:c,refId:ref||'',por:RH});
 B('rl16',40,6,'Saldo inicial migrado');B('rl16',14,4,'Horas extra del '+fd(A(-15))+' (autorizadas 4 h)','he4');B('rl16',11,-8,'Descanso compensatorio del '+fd(FC),'au6');
 B('rl13',40,9.5,'Saldo inicial migrado');B('rl7',40,3,'Saldo inicial migrado');B('rl17',40,5,'Saldo inicial migrado');
 /* proyectos y hojas de tiempo */
 t.ti_proyectos.push({id:'pr1',codigo:'INT-01',nombre:'Operación interna y soporte',cliente:'Grupo Andino',ccId:'c3',activo:true},{id:'pr2',codigo:'CLI-101',nombre:'Portal de clientes',cliente:'Banco Altiplano',ccId:'c3',activo:true},{id:'pr3',codigo:'CLI-102',nombre:'App de reparto',cliente:'Andina Logística',ccId:'c4',activo:true},{id:'pr4',codigo:'MKT-07',nombre:'Campaña de fin de año',cliente:'Comercial',ccId:'c2',activo:true});
 const HJ=(id,rel,w,est,lin,hi)=>t.ti_hojas.push({id,relId:rel,semana:addDays(mon,7*w),estado:est,etapa:est==='Pendiente'?'Jefe':'',creada:hi.length?hi[0].ts.slice(0,10):TODAY,lineas:lin.map(l=>({proyectoId:l[0],h:l[1].concat([0,0,0,0,0,0,0]).slice(0,7)})),hist:hi});
 const dw=(dow(TODAY)+6)%7;
 HJ('hj1','rl13',-1,'Pendiente',[['pr2',[8,8,6,8,7]],['pr1',[0,0,2,0,1]]],[H(dw+1,N('rl13'),'Envió la hoja de tiempo')]);
 HJ('hj2','rl13',-2,'Aprobada',[['pr2',[8,8,8,8,8]]],[H(dw+8,N('rl13'),'Envió la hoja de tiempo'),H(dw+7,N('rl12'),'Aprobó')]);
 HJ('hj3','rl14',-1,'Aprobada',[['pr2',[6,6,6,6,6]],['pr3',[2,2,2,2,2]]],[H(dw+2,N('rl14'),'Envió la hoja de tiempo'),H(dw+1,N('rl12'),'Aprobó')]);
 HJ('hj4','rl14',-2,'Aprobada',[['pr3',[8,8,8,8,8]]],[H(dw+9,N('rl14'),'Envió la hoja de tiempo'),H(dw+8,N('rl12'),'Aprobó')]);
 HJ('hj5','rl11',-1,'Aprobada',[['pr4',[4,4,4,4,4]],['pr1',[4,4,4,4,4]]],[H(dw+2,N('rl11'),'Envió la hoja de tiempo'),H(dw+1,RH,'Aprobó')]);
 HJ('hj6','rl14',0,'Borrador',[['pr2',[0,1,2,3,4].map(i=>i<dw?6:0)],['pr3',[0,1,2,3,4].map(i=>i<dw?2:0)]],[]);
 HJ('hj7','rl12',-1,'Aprobada',[['pr1',[3,3,3,3,3]],['pr2',[5,5,5,5,5]]],[H(dw+2,N('rl12'),'Envió la hoja de tiempo'),H(dw+1,N('rl2'),'Aprobó')])}

/* =====================================================================
   REGISTRO DEL MÓDULO
   ===================================================================== */
let lastU='';
const sync=fn=>function(){const u=session?session.tenantId+'|'+session.userId:'';if(u!==lastU){lastU=u;Object.keys(ui).forEach(k=>{if(k.startsWith('ti_'))delete ui[k]})}return fn.apply(this,arguments)};
const P=['tiempo.asistencia.ver','tiempo.asistencia.marcar','tiempo.cuadrilla','tiempo.kiosko','tiempo.solicitar','tiempo.vacaciones.ver','tiempo.aprobar','tiempo.validar','tiempo.administrar','tiempo.turnos.ver','tiempo.turnos.administrar','tiempo.hojas.registrar','tiempo.hojas.administrar','tiempo.configurar'];
const verSol=()=>can('tiempo.vacaciones.ver')||can('tiempo.solicitar');
registerModule({
 id:'tiempo',nombre:'Tiempo y asistencia',siempre:false,
 perms:[[P[0],'Tiempo','Ver la asistencia de las personas de su alcance'],[P[1],'Tiempo','Marcar su propia asistencia y reportar incidencias'],[P[2],'Tiempo','Marcar la asistencia de su equipo (cuadrilla)'],[P[3],'Tiempo','Abrir el modo kiosko de marcación de una sede'],[P[4],'Tiempo','Solicitar vacaciones, permisos y horas extra'],[P[5],'Tiempo','Ver saldos y solicitudes de vacaciones, permisos y horas extra de su alcance'],[P[6],'Tiempo','Aprobar las solicitudes de sus reportes directos'],[P[7],'Tiempo','Validar como RRHH y resolver cuando el jefe no tiene usuario'],[P[8],'Tiempo','Registrar solicitudes por otros, ajustar saldos y bolsa de horas'],[P[9],'Tiempo','Ver horarios, turnos y feriados'],[P[10],'Tiempo','Planificar turnos y asignar horarios'],[P[11],'Tiempo','Registrar su hoja de tiempo por proyecto'],[P[12],'Tiempo','Administrar proyectos y ver el resumen de horas'],[P[13],'Tiempo','Configurar horarios, políticas, feriados, tipos de ausencia y métodos de marcación']],
 rolePerms:{r_rrhh:P.slice(),r_mgr:[P[0],P[1],P[2],P[4],P[5],P[6],P[9],P[10],P[11]],r_emp:[P[1],P[4],P[9],P[11]],r_pay:[P[0],P[1],P[4],P[5],P[6],P[9],P[11],P[12]],r_aud:[P[0],P[5],P[9]]},
 nav:[{grupo:'Tiempo',items:[
  ['ti_asistencia','Asistencia',()=>can(P[0])||can(P[1]),()=>nBandeja(['cor'])],
  ['ti_turnos','Turnos y horarios',()=>can(P[9])],
  ['ti_vacaciones','Vacaciones',verSol,()=>nBandeja(['vac'])],
  ['ti_ausencias','Ausencias y permisos',verSol,()=>nBandeja(['aus'])],
  ['ti_extras','Horas extra y bolsa',verSol,()=>nBandeja(['he'])],
  ['ti_hojas','Hojas de tiempo',()=>can(P[11])||can(P[12]),()=>nBandeja(['hoja'])]]}],
 views:{ti_asistencia:sync(vAsistencia),ti_turnos:sync(vTurnos),ti_vacaciones:sync(vVacaciones),ti_ausencias:sync(vAusencias),ti_extras:sync(vExtras),ti_hojas:sync(vHojas)},
 actions:ACT,
 init(t,tid){['marcaciones','vacSolicitudes','ausencias','feriados','ti_horarios','ti_asigHorario','ti_politicas','ti_metodos','ti_turnoTipos','ti_turnos','ti_ausTipos','ti_vacAjustes','ti_correcciones','ti_heSolicitudes','ti_bolsa','ti_proyectos','ti_hojas'].forEach(k=>t[k]=t[k]||[]);
  t.ti_cfg=t.ti_cfg||{horarioId:'',vacDias:30,vacValidaRRHH:'Sí',vacAnticipacion:2,vacAlertaDias:60,nocIni:'22:00',nocFin:'06:00',heMinimo:15,heTopeDia:4,descansoMinH:12};
  if(tid==='t1'){TT=t;try{baseCat(t);seedDemo(t)}finally{TT=null}}},
 fichaTabs:[['ti_asis','Asistencia',fichaAsis,r=>can(P[0])||own(r)],['ti_vac','Vacaciones',fichaVac,r=>can(P[5])||own(r)]],
 alerts:alertas,home:sync(home),pend,
 /* funciones que reutiliza el módulo de complementos (kiosko a pantalla completa, WhatsApp) */
 api:{marcar,abierta,sigMarca,pinDe,metodoOn,estadoDia,vacSaldo,ML,MB}});
})();
