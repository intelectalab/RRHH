/*
 * app.js — Comportamiento de la interfaz (sin dependencias salvo Bootstrap local).
 * Todo usa delegación de eventos, así funciona también en los paneles cargados después.
 *
 *  · Avisos (toasts) con «Deshacer»            · Paneles laterales/ventanas: [data-panel]
 *  · Formularios sin recarga: form[data-ajax]   · Filtros con recarga parcial: form[data-filtros], a[data-parcial]
 *  · Tooltips: [data-tip]                       · Buscador global (tecla /)
 *  · Filas con enlace y menú contextual         · Pestañas de ficha, asistente por pasos, selección masiva
 */
(() => {
  'use strict';
  const A = window.AYNI || {};
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const sinMov = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pedir = (url, op = {}) => fetch(url, { credentials: 'same-origin', ...op, headers: { 'X-Requested-With': 'fetch', ...(op.headers || {}) } });

  /* ================= Avisos ================= */
  const avisos = $('#avisos');
  function aviso(msg, tipo = 'ok', deshacer = null, ms = 5000) {
    if (!avisos) return;
    const el = document.createElement('div');
    el.className = 'aviso ' + (tipo === 'er' ? 'er' : '');
    el.setAttribute('role', tipo === 'er' ? 'alert' : 'status');
    const ic = tipo === 'er' ? 'warning-circle' : tipo === 'in' ? 'info' : 'check-circle';
    el.innerHTML = `<i class="ph ph-${ic}" aria-hidden="true"></i><span>${esc(msg)}</span>` +
      (deshacer ? '<button type="button">Deshacer</button>' : '') + '<span class="barra"></span>';
    avisos.appendChild(el);
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('in')));
    let resto = deshacer ? 8000 : ms, inicio = Date.now(), t;
    const barra = $('.barra', el);
    const correr = () => {
      inicio = Date.now(); t = setTimeout(cerrar, resto);
      if (!sinMov) { barra.style.transition = `transform ${resto}ms linear`; barra.style.transform = 'scaleX(0)'; }
    };
    const pausar = () => {
      clearTimeout(t); resto -= Date.now() - inicio;
      const k = getComputedStyle(barra).transform; barra.style.transition = 'none'; barra.style.transform = k;
    };
    function cerrar() { el.classList.add('out'); setTimeout(() => el.remove(), 160); }
    el.addEventListener('mouseenter', pausar); el.addEventListener('mouseleave', correr);
    if (deshacer) $('button', el).addEventListener('click', async () => {
      clearTimeout(t); $('button', el).disabled = true;
      const fd = new FormData(); fd.append('csrf', A.csrf); fd.append('token', deshacer.token);
      const r = await pedir('index.php?accion=deshacer', { method: 'POST', body: fd }).then(x => x.json()).catch(() => null);
      cerrar();
      if (r && r.ok) navegar(location.href, false); else aviso((r && r.error) || 'No se pudo deshacer.', 'er');
    });
    barra.style.transform = 'scaleX(1)';
    correr();
    return el;
  }
  window.aynAviso = aviso;
  (A.avisos || []).forEach((a, i) => setTimeout(() => aviso(a.msg, a.tipo, a.deshacer), 120 + i * 80));

  document.addEventListener('click', e => {
    const a = e.target.closest('[data-aviso]');
    if (a) { e.preventDefault(); aviso(a.dataset.aviso, 'in'); }
  });

  /* ================= Tooltips ================= */
  const tip = $('#tip'); let tipT, tipEl = null, ultimo = 0;
  function mostrarTip(el) {
    const txt = el.getAttribute('data-tip'); if (!txt || !tip) return;
    tip.textContent = txt; tip.hidden = false;
    const r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
    let x = r.left + r.width / 2 - w / 2, y = r.top - h - 8;
    if (y < 4) y = r.bottom + 8;
    x = Math.max(6, Math.min(x, innerWidth - w - 6));
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
    tip.classList.toggle('instantaneo', Date.now() - ultimo < 400 || sinMov);
    requestAnimationFrame(() => tip.classList.add('on'));
  }
  function ocultarTip() { clearTimeout(tipT); if (tipEl) ultimo = Date.now(); tipEl = null; if (tip) { tip.classList.remove('on'); tip.hidden = true; } }
  document.addEventListener('mouseover', e => {
    const el = e.target.closest && e.target.closest('[data-tip]');
    if (el === tipEl) return;
    ocultarTip(); if (!el) return;
    tipEl = el; tipT = setTimeout(() => mostrarTip(el), Date.now() - ultimo < 400 ? 0 : 280);
  });
  document.addEventListener('focusin', e => { const el = e.target.closest('[data-tip]'); if (el) { tipEl = el; mostrarTip(el); } });
  document.addEventListener('focusout', ocultarTip);
  addEventListener('scroll', ocultarTip, true);

  /* ================= Paneles (offcanvas / modal) ================= */
  const raiz = $('#panel-raiz');
  async function abrirPanel(qs) {
    let esq = null;
    const t = setTimeout(() => {
      esq = document.createElement('div'); esq.className = 'panel-cargando'; esq.setAttribute('aria-busy', 'true');
      esq.innerHTML = '<span class="esq" style="width:40%;height:18px"></span><span class="esq mt-4"></span><span class="esq mt-2" style="width:80%"></span><span class="esq mt-4"></span><span class="esq mt-2" style="width:60%"></span>';
      document.body.appendChild(esq);
    }, 140);
    let html = '';
    try { html = await pedir('index.php?' + qs).then(r => r.text()); } catch (_) { }
    clearTimeout(t); if (esq) esq.remove();
    if (!html.trim()) { aviso('No tiene acceso a esta acción o el registro ya no existe.', 'er'); return; }
    raiz.innerHTML = html;
    const el = raiz.firstElementChild;
    $$('[data-alterna]', el).forEach(alternar);
    const inst = el.classList.contains('modal') ? new bootstrap.Modal(el) : new bootstrap.Offcanvas(el);
    el.addEventListener(el.classList.contains('modal') ? 'hidden.bs.modal' : 'hidden.bs.offcanvas', () => { inst.dispose(); el.remove(); });
    el.addEventListener(el.classList.contains('modal') ? 'shown.bs.modal' : 'shown.bs.offcanvas', () => {
      const f = $('input:not([type=hidden]):not([disabled]),select,textarea', $('.modal-body,.offcanvas-body', el) || el); if (f) f.focus();
    });
    inst.show();
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-panel]');
    if (!b) return;
    e.preventDefault();
    const dd = b.closest('.dropdown-menu'); if (dd) { const tg = dd.previousElementSibling; if (tg && bootstrap.Dropdown.getInstance(tg)) bootstrap.Dropdown.getInstance(tg).hide(); }
    cerrarMenuCtx();
    abrirPanel(b.dataset.panel);
  });

  /* Bloques que se muestran según un combo (p. ej., tipo de movimiento). */
  function alternar(sel) {
    const f = sel.closest('form') || document;
    $$(sel.dataset.alterna, f).forEach(b => {
      const on = b.dataset.valor === sel.value; b.hidden = !on;
      $$('input,select,textarea', b).forEach(i => i.disabled = !on);
    });
  }
  document.addEventListener('change', e => { if (e.target.matches('[data-alterna]')) alternar(e.target); });

  /* ================= Formularios sin recarga ================= */
  function limpiarErrores(f) {
    $$('.is-invalid', f).forEach(i => i.classList.remove('is-invalid'));
    $$('[data-error]', f).forEach(d => d.textContent = '');
    const pe = $('.panel-err', f); if (pe) pe.innerHTML = '';
  }
  function mostrarErrores(f, r) {
    const errs = r.errores || {}; let primero = null;
    Object.entries(errs).forEach(([k, m]) => {
      const d = $(`[data-error="${k}"]`, f); if (d) d.textContent = m;
      const i = f.querySelector(`[name="${k}"]`); if (i) { i.classList.add('is-invalid'); primero = primero || i; }
    });
    const pe = $('.panel-err', f);
    if (r.error && pe) pe.innerHTML = `<div class="alert alert-danger py-2 mb-3">${esc(r.error)}</div>`;
    else if (r.error && !Object.keys(errs).length) aviso(r.error, 'er');
    if (primero) { const p = primero.closest('.paso'); if (p && f._irPaso) f._irPaso(+p.dataset.paso); primero.focus(); }
    else if (pe) pe.scrollIntoView({ block: 'nearest' });
  }
  document.addEventListener('submit', async e => {
    const f = e.target;
    if (!f.matches('form[data-ajax]')) return;
    e.preventDefault();
    if (f.dataset.wizard !== undefined && f._validarPaso && !f._validarPaso(true)) return;
    const fd = new FormData(f, e.submitter || undefined);
    const bts = $$('button:not([type=button])', f); bts.forEach(b => b.disabled = true);
    const b = e.submitter; const txt = b && b.innerHTML;
    if (b && f.dataset.auto === undefined) b.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + b.innerHTML;
    limpiarErrores(f);
    let r;
    try { r = await pedir(f.action, { method: 'POST', body: fd }).then(x => x.json()); }
    catch (_) { r = { ok: false, error: 'No se pudo completar la acción. Revise su conexión e intente de nuevo.' }; }
    bts.forEach(x => x.disabled = false); if (b) b.innerHTML = txt;
    if (!r.ok) { mostrarErrores(f, r); return; }
    const panel = f.closest('.offcanvas,.modal') || f.querySelector(':scope.offcanvas,:scope.modal') || (f.matches('.offcanvas') ? f : null);
    if (r.aviso) {
      aviso(r.aviso.msg, r.aviso.tipo, r.aviso.deshacer);
      if (panel) cerrarPanel(panel);
      if (f.dataset.quitar) quitar(f.closest(f.dataset.quitar));
      return;
    }
    if (panel) cerrarPanel(panel);
    if (r.ir) navegar(r.ir, true); else location.reload();
  });
  function cerrarPanel(p) {
    const i = bootstrap.Offcanvas.getInstance(p) || bootstrap.Modal.getInstance(p); if (i) i.hide();
  }
  function quitar(el) {
    if (!el) return;
    el.style.maxHeight = el.offsetHeight + 'px';
    requestAnimationFrame(() => { el.classList.add('sale'); });
    setTimeout(() => {
      const lista = el.parentElement; el.remove();
      $$('[data-contador]').forEach(c => { const n = Math.max(0, (+c.textContent || 0) - 1); c.textContent = n; if (!n && c.dataset.contador === 'ocultar') c.hidden = true; });
      if (lista && !lista.querySelector('[data-item]')) { const v = $('[data-vacio-al-final]'); if (v) v.hidden = false; }
    }, sinMov ? 0 : 280);
  }
  /* Interruptores que se guardan solos (perfiles). */
  document.addEventListener('change', e => {
    const f = e.target.closest('form[data-auto]');
    if (f && !e.target.matches('[data-no-auto]')) f.requestSubmit();
  });

  /* ================= Campos ================= */
  document.addEventListener('input', e => {
    const i = e.target;
    if (i.matches('[data-fecha]')) {
      const d = i.value.replace(/\D/g, '').slice(0, 8);
      i.value = d.length > 4 ? `${d.slice(0, 2)}/${d.slice(2, 4)}/${d.slice(4)}` : d.length > 2 ? `${d.slice(0, 2)}/${d.slice(2)}` : d;
    }
    if (i.classList.contains('is-invalid')) { i.classList.remove('is-invalid'); const d = i.closest('form') && $(`[data-error="${i.name}"]`, i.closest('form')); if (d) d.textContent = ''; }
  });
  document.addEventListener('change', e => {
    const i = e.target;
    if (i.type === 'file' && i.closest('.adj')) { const b = $('b', i.closest('.adj')); if (b) b.textContent = i.files[0] ? i.files[0].name : 'Seleccionar archivo'; }
  });
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-ver-clave]'); if (!b) return;
    const i = $(b.dataset.verClave); const ver = i.type === 'password'; i.type = ver ? 'text' : 'password';
    b.innerHTML = `<i class="ph ph-${ver ? 'eye-slash' : 'eye'}" aria-hidden="true"></i>`;
  });

  /* ================= Filas con enlace + menú contextual ================= */
  document.addEventListener('click', e => {
    const tr = e.target.closest('tr[data-href]');
    if (!tr || e.target.closest('a,button,input,label,select,.dropdown-menu')) return;
    if (e.ctrlKey || e.metaKey) open(tr.dataset.href, '_blank');
    else {
      let u;
      try { u = new URL(tr.dataset.href, location.href); } catch (_) { location.href = tr.dataset.href; return; }
      if (u.origin === location.origin && u.pathname.endsWith('index.php') && u.searchParams.has('p') && !u.searchParams.has('exportar') && !u.searchParams.has('accion')) {
        navegar(u.href, true);
      } else {
        location.href = tr.dataset.href;
      }
    }
  });
  let menuCtx = null;
  function cerrarMenuCtx() { if (menuCtx) { menuCtx.remove(); menuCtx = null; } }
  document.addEventListener('contextmenu', e => {
    const tr = e.target.closest('[data-menu]');
    if (!tr || e.target.closest('input,textarea,a[href]:not(.nm)')) return;
    const src = $('.dropdown-menu', tr); if (!src) return;
    e.preventDefault(); cerrarMenuCtx();
    menuCtx = src.cloneNode(true); menuCtx.classList.add('menu-ctx', 'show'); menuCtx.classList.remove('dropdown-menu-end');
    menuCtx.setAttribute('role', 'menu');
    document.body.appendChild(menuCtx);
    const w = menuCtx.offsetWidth, h = menuCtx.offsetHeight;
    menuCtx.style.left = Math.min(e.clientX, innerWidth - w - 8) + 'px';
    menuCtx.style.top = Math.min(e.clientY, innerHeight - h - 8) + 'px';
    const f = $('a,button', menuCtx); if (f) f.focus({ preventScroll: true });
  });
  document.addEventListener('click', e => { if (menuCtx && !menuCtx.contains(e.target)) cerrarMenuCtx(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarMenuCtx();
    if (menuCtx && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault(); const it = $$('a,button', menuCtx); const i = it.indexOf(document.activeElement);
      it[(i + (e.key === 'ArrowDown' ? 1 : -1) + it.length) % it.length].focus();
    }
  });
  addEventListener('scroll', cerrarMenuCtx, true);

  /* ================= Filtros con recarga parcial ================= */
  const ESQ_FILAS = n => '<div class="p-3" aria-busy="true">' + Array.from({ length: n }, (_, i) =>
    `<div class="d-flex align-items-center gap-3 py-2"><span class="esq av-esq"></span><span style="flex:1"><span class="esq" style="width:${30 + (i * 17) % 35}%"></span><span class="esq mt-2" style="width:${20 + (i * 11) % 25}%;height:9px"></span></span><span class="esq" style="width:12%"></span><span class="esq" style="width:9%"></span></div>`).join('') + '</div>';
  let ctrlParcial = null;
  async function cargarParcial(url, empujar = false) {
    const cont = $('#resultados'); if (!cont) { location.href = url; return; }
    if (ctrlParcial) ctrlParcial.abort();
    ctrlParcial = new AbortController();
    const alto = cont.offsetHeight;
    const t = setTimeout(() => { cont.style.minHeight = alto + 'px'; cont.innerHTML = ESQ_FILAS(Math.max(3, Math.min(10, Math.round(alto / 56)))); }, 120);
    try {
      const html = await pedir(url, { signal: ctrlParcial.signal }).then(r => r.text());
      clearTimeout(t);
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const nuevo = $('#resultados', doc); if (!nuevo) { location.href = url; return; }
      cont.innerHTML = nuevo.innerHTML; cont.style.minHeight = '';
      $$('[data-sincronizar]').forEach(el => { const n = doc.getElementById(el.id); if (n) el.innerHTML = n.innerHTML; });
      history[empujar ? 'pushState' : 'replaceState'](null, '', url);
      /* Los filtros reflejan la dirección cargada (vista, orden, modo…). */
      const prm = new URL(url, location.href).searchParams;
      $$('form[data-filtros]').forEach(f => $$('[name]', f).forEach(el => { if (el !== document.activeElement && el.name !== 'p') el.value = prm.get(el.name) ?? ''; }));
      if (!sinMov) cont.animate([{ opacity: .4 }, { opacity: 1 }], { duration: 160, easing: 'ease-out' });
    } catch (err) { if (err.name !== 'AbortError') { clearTimeout(t); location.href = url; } }
  }
  function urlFiltros(f) {
    const p = new URLSearchParams(new FormData(f));
    [...p.keys()].forEach(k => { if (p.get(k) === '') p.delete(k); });
    return 'index.php?' + p.toString();
  }
  let tFiltro;
  document.addEventListener('input', e => {
    const f = e.target.closest('form[data-filtros]'); if (!f || e.target.type !== 'search' && e.target.type !== 'text') return;
    clearTimeout(tFiltro); tFiltro = setTimeout(() => cargarParcial(urlFiltros(f)), 260);
  });
  document.addEventListener('change', e => {
    const f = e.target.closest('form[data-filtros]'); if (!f || e.target.type === 'text' || e.target.type === 'search') return;
    cargarParcial(urlFiltros(f));
  });
  document.addEventListener('submit', e => { if (e.target.matches('form[data-filtros]')) { e.preventDefault(); cargarParcial(urlFiltros(e.target)); } });
  document.addEventListener('click', e => {
    const a = e.target.closest('a[data-parcial]'); if (!a || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    $$('a[data-parcial]', a.parentElement).forEach(x => { if (x.closest('.seg,.chips-vista') === a.closest('.seg,.chips-vista')) x.classList.toggle('on', x === a); });
    cargarParcial(a.href);
  });
  addEventListener('popstate', () => { navegar(location.href, false); });

  /* ================= Selección masiva ================= */
  function actualizarSeleccion(cont) {
    const sel = $$('[data-sel]:checked', cont).map(c => c.value);
    const bar = $('.bulk', cont.closest('.card') || document);
    if (bar) { bar.hidden = !sel.length; const n = $('[data-sel-n]', bar); if (n) n.textContent = sel.length + (sel.length === 1 ? ' seleccionado' : ' seleccionados'); }
    const todo = $('[data-sel-todo]', cont); const tot = $$('[data-sel]', cont).length;
    if (todo) { todo.checked = sel.length && sel.length === tot; todo.indeterminate = sel.length > 0 && sel.length < tot; }
    $$('[data-sel]', cont).forEach(c => { const tr = c.closest('tr,[data-item]'); if (tr) tr.classList.toggle('sel', c.checked); });
    return sel;
  }
  document.addEventListener('change', e => {
    const t = e.target, cont = t.closest('#resultados') || t.closest('[data-seleccion]') || document;
    if (t.matches('[data-sel-todo]')) { $$('[data-sel]', cont).forEach(c => c.checked = t.checked); actualizarSeleccion(cont); }
    else if (t.matches('[data-sel]')) actualizarSeleccion(cont);
  });
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-bulk-panel],[data-bulk-href],[data-bulk-limpiar]'); if (!b) return;
    const cont = $('#resultados') || $('[data-seleccion]') || document;
    const ids = $$('[data-sel]:checked', cont).map(c => c.value);
    if (b.dataset.bulkLimpiar !== undefined) { $$('[data-sel]', cont).forEach(c => c.checked = false); actualizarSeleccion(cont); return; }
    if (!ids.length) return;
    e.preventDefault();
    if (b.dataset.bulkPanel) abrirPanel(b.dataset.bulkPanel + '&ids=' + ids.join(','));
    else location.href = b.dataset.bulkHref + '&ids=' + ids.join(',');
  });
  /* Formularios de lote (p. ej., aprobar seleccionadas): copian los ids marcados. */
  document.addEventListener('submit', e => {
    const f = e.target; if (!f.matches('form[data-lote]')) return;
    const ids = $$('[data-sel]:checked', $(f.dataset.lote) || document).map(c => c.value);
    let h = $('input[name=ids]', f); if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids'; f.appendChild(h); }
    h.value = ids.join(',');
    if (!ids.length) { e.preventDefault(); e.stopImmediatePropagation(); aviso('Seleccione al menos una solicitud.', 'er'); }
  }, true);

  /* ================= Buscador global ================= */
  const bus = $('#buscador'), res = $('#buscador-res');
  if (bus && res) {
    let tB, idx = -1, ctrl;
    const cerrar = () => { res.hidden = true; bus.setAttribute('aria-expanded', 'false'); idx = -1; };
    const marcar = () => { $$('a', res).forEach((a, i) => { a.classList.toggle('sel', i === idx); a.setAttribute('aria-selected', i === idx); if (i === idx) a.scrollIntoView({ block: 'nearest' }); }); };
    bus.addEventListener('input', () => {
      clearTimeout(tB); const q = bus.value.trim();
      if (q.length < 2) { cerrar(); return; }
      tB = setTimeout(async () => {
        if (ctrl) ctrl.abort(); ctrl = new AbortController();
        try {
          const r = await pedir('index.php?api=buscar&q=' + encodeURIComponent(q), { signal: ctrl.signal }).then(x => x.json());
          res.innerHTML = r.length ? r.map(x => `<a href="${esc(x.url)}" role="option">${x.avatar}<span class="min-w-0"><b class="d-block text-truncate">${esc(x.nombre)}</b><span class="sub2 d-block text-truncate">${esc(x.sub)}</span></span></a>`).join('')
            : `<div class="p-3 sub2">No se encontraron colaboradores para «${esc(q)}».</div>`;
          res.hidden = false; bus.setAttribute('aria-expanded', 'true'); idx = r.length ? 0 : -1; marcar();
        } catch (_) { }
      }, 160);
    });
    bus.addEventListener('keydown', e => {
      const it = $$('a', res);
      if (e.key === 'ArrowDown' && it.length) { e.preventDefault(); idx = (idx + 1) % it.length; marcar(); }
      else if (e.key === 'ArrowUp' && it.length) { e.preventDefault(); idx = (idx - 1 + it.length) % it.length; marcar(); }
      else if (e.key === 'Enter' && it[idx]) { e.preventDefault(); const u = it[idx].href; cerrar(); navegar(u, true); }
      else if (e.key === 'Escape') { cerrar(); bus.blur(); }
    });
    bus.addEventListener('focus', () => { if (res.innerHTML && bus.value.trim().length > 1) { res.hidden = false; bus.setAttribute('aria-expanded', 'true'); } });
    document.addEventListener('click', e => { if (!e.target.closest('.gsearch')) cerrar(); });
    document.addEventListener('keydown', e => {
      if (e.key === '/' && !e.target.closest('input,textarea,select,[contenteditable]')) { e.preventDefault(); bus.focus(); bus.select(); }
    });
  }

  /* ================= Pestañas de la ficha (en el navegador) ================= */
  function irPestana(nav, id, foco) {
    $$('[data-tab]', nav).forEach(b => {
      const on = b.dataset.tab === id; b.setAttribute('aria-selected', on); b.tabIndex = on ? 0 : -1;
      const p = document.getElementById('tab-' + b.dataset.tab); if (p) p.hidden = !on;
      if (on && foco) b.focus();
    });
    const u = new URL(location.href); u.searchParams.set('tab', id); history.replaceState(null, '', u);
  }
  document.addEventListener('click', e => { const b = e.target.closest('[data-tabs] [data-tab]'); if (b) irPestana(b.closest('[data-tabs]'), b.dataset.tab); });
  document.addEventListener('click', e => { const a = e.target.closest('[data-ir-tab]'); if (a) { const nav = $('[data-tabs]'); if (nav) { e.preventDefault(); irPestana(nav, a.dataset.irTab); nav.scrollIntoView({ behavior: sinMov ? 'auto' : 'smooth', block: 'start' }); } } });
  document.addEventListener('keydown', e => {
    const b = e.target.closest('[data-tabs] [data-tab]'); if (!b || !['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(e.key)) return;
    const it = $$('[data-tab]', b.closest('[data-tabs]')); let i = it.indexOf(b);
    i = e.key === 'Home' ? 0 : e.key === 'End' ? it.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + it.length) % it.length;
    e.preventDefault(); irPestana(b.closest('[data-tabs]'), it[i].dataset.tab, true);
  });

  /* ================= Asistente por pasos (alta de colaborador) ================= */
  function initWizard(f) {
    if (f._wizardIniciado) return;
    f._wizardIniciado = true;
    const pasos = $$('.paso', f); let actual = 1;
    const ir = n => {
      actual = Math.max(1, Math.min(pasos.length, n));
      pasos.forEach(p => p.hidden = +p.dataset.paso !== actual);
      $$('[data-ir-paso]', f).forEach(li => {
        const k = +li.dataset.irPaso;
        li.classList.toggle('on', k === actual);
        li.classList.toggle('hecho', k < actual);
        li.setAttribute('aria-current', k === actual ? 'step' : 'false');
      });
      $$('[data-no-ultimo]', f).forEach(b => b.hidden = actual === pasos.length);
      $$('[data-solo-ultimo]', f).forEach(b => b.hidden = actual !== pasos.length);
      $$('[data-paso="ant"]', f).forEach(b => b.disabled = actual === 1);
      const pa = pasos[actual - 1];
      if (pa && !sinMov) pa.animate([{ opacity: 0, transform: 'translateX(8px)' }, { opacity: 1, transform: 'none' }], { duration: 200, easing: 'cubic-bezier(.23,1,.32,1)' });
    };
    const validar = (todos) => {
      const ambito = todos ? pasos : [pasos[actual - 1]]; let ok = true, primero = null;
      ambito.forEach(p => $$('[required]', p).forEach(i => {
        if (!i.disabled && !String(i.value).trim()) {
          ok = false; i.classList.add('is-invalid');
          const d = $(`[data-error="${i.name}"]`, f);
          if (d) d.textContent = 'Este dato es obligatorio.';
          primero = primero || i;
        }
      }));
      if (primero) { const p = primero.closest('.paso'); if (p) ir(+p.dataset.paso); primero.focus(); }
      return ok;
    };
    f._irPaso = ir; f._validarPaso = validar;
    f.addEventListener('click', e => {
      const b = e.target.closest('[data-paso]');
      if (b && b.tagName === 'BUTTON') {
        if (b.dataset.paso === 'sig') { if (validar(false)) ir(actual + 1); }
        else ir(actual - 1);
      }
      const li = e.target.closest('[data-ir-paso]');
      if (li) { const k = +li.dataset.irPaso; if (k < actual || validar(false)) ir(k); }
    });
    ir(1);
  }

  /* ================= Cifras que cuentan ================= */
  function initContar(ambito = document) {
    if (!sinMov) $$('[data-contar]', ambito).forEach(el => {
      if (el._contarIniciado) return;
      el._contarIniciado = true;
      const fin = parseFloat(el.dataset.contar); if (!isFinite(fin) || fin < 4) return;
      const dec = (el.dataset.contar.split('.')[1] || '').length, t0 = performance.now(), d = 700;
      const paso = t => {
        const k = Math.min(1, (t - t0) / d), v = fin * (1 - Math.pow(1 - k, 3));
        el.textContent = v.toFixed(dec);
        if (k < 1) requestAnimationFrame(paso);
        else el.textContent = el.dataset.contarTxt || fin.toFixed(dec);
      };
      el.textContent = '0'; requestAnimationFrame(paso);
    });
  }

  /* Combos dependientes: <select data-filtra="#otro"> muestra en #otro solo las opciones con data-padre igual a su valor. */
  function filtrarCombo(sel, inicial) {
    const t = $(sel.dataset.filtra); if (!t) return;
    $$('option[data-padre]', t).forEach(o => { const ok = !sel.value || o.dataset.padre === sel.value; o.hidden = !ok; o.disabled = !ok; });
    if (!inicial && t.selectedOptions[0] && t.selectedOptions[0].disabled) t.value = '';
  }
  document.addEventListener('change', e => { if (e.target.matches('select[data-filtra]')) filtrarCombo(e.target); });

  /* Campos que se muestran según otro valor: data-si="#combo=valor1|valor2" */
  function aplicarSi(ambito = document) {
    $$('[data-si]', ambito).forEach(el => {
      const [sel, vals] = el.dataset.si.split('=');
      const s = $(sel); if (!s) return;
      const on = vals.split('|').includes(s.value);
      el.hidden = !on;
      $$('input,select', el).forEach(i => i.disabled = !on);
    });
  }
  document.addEventListener('change', () => aplicarSi(document));

  /* Función init que reune lo que se ejecuta una vez al cargar */
  function init(raiz = document) {
    $$('form[data-wizard]', raiz).forEach(initWizard);
    $$('select[data-filtra]', raiz).forEach(s => filtrarCombo(s, true));
    aplicarSi(raiz);
    initContar(raiz);
  }
  init(document);

  /* ================= Navegación sin recarga ================= */
  let barraProgreso = null, timerProgreso = null, ctrlNavegar = null;

  function mostrarProgreso() {
    clearTimeout(timerProgreso);
    if (barraProgreso) { barraProgreso.remove(); barraProgreso = null; }
    timerProgreso = setTimeout(() => {
      barraProgreso = document.createElement('div');
      barraProgreso.style.cssText = 'position:fixed;top:0;left:0;height:2px;width:0%;background:var(--pe-500);z-index:99999;pointer-events:none;transition:width 300ms ease-out;';
      document.body.appendChild(barraProgreso);
      requestAnimationFrame(() => { if (barraProgreso) barraProgreso.style.width = '70%'; });
    }, 150);
  }

  function ocultarProgreso() {
    clearTimeout(timerProgreso);
    if (barraProgreso) {
      barraProgreso.style.transition = 'width 100ms ease-out, opacity 150ms ease-out';
      barraProgreso.style.width = '100%';
      setTimeout(() => {
        if (barraProgreso) {
          barraProgreso.style.opacity = '0';
          setTimeout(() => {
            if (barraProgreso) { barraProgreso.remove(); barraProgreso = null; }
          }, 150);
        }
      }, 100);
    }
  }

  function cerrarTodo() {
    ocultarTip();
    cerrarMenuCtx();
    $$('.offcanvas.show, .modal.show').forEach(p => {
      const i = bootstrap.Offcanvas.getInstance(p) || bootstrap.Modal.getInstance(p);
      if (i) i.hide();
      p.classList.remove('show');
    });
    if (raiz) raiz.innerHTML = '';
    $$('.modal-backdrop, .offcanvas-backdrop').forEach(b => b.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    $$('.dropdown-menu.show').forEach(dd => {
      const tg = dd.previousElementSibling;
      if (tg && bootstrap.Dropdown.getInstance(tg)) bootstrap.Dropdown.getInstance(tg).hide();
      dd.classList.remove('show');
    });
  }

  function extraerAyni(doc, html) {
    for (const s of $$('script', doc)) {
      const t = s.textContent || '';
      const m = t.match(/window\.AYNI\s*=\s*(\{[\s\S]*?\});/);
      if (m) {
        try { return JSON.parse(m[1]); } catch (_) {}
      }
    }
    if (html) {
      const m = html.match(/<script>window\.AYNI\s*=\s*(\{[\s\S]*?\});<\/script>/);
      if (m) {
        try { return JSON.parse(m[1]); } catch (_) {}
      }
    }
    return null;
  }

  async function navegar(url, empujar = true) {
    cerrarTodo();

    if (ctrlNavegar) ctrlNavegar.abort();
    ctrlNavegar = new AbortController();

    mostrarProgreso();

    let html;
    try {
      const resp = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'navegar' },
        signal: ctrlNavegar.signal
      });
      html = await resp.text();
    } catch (err) {
      ocultarProgreso();
      if (err.name === 'AbortError') return;
      location.href = url;
      return;
    }

    ocultarProgreso();

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nuevaVista = $('#vista', doc);
    if (!nuevaVista) {
      location.href = url;
      return;
    }

    const vistaActual = $('#vista');
    if (!vistaActual) {
      location.href = url;
      return;
    }

    vistaActual.innerHTML = nuevaVista.innerHTML;
    if (doc.title) document.title = doc.title;
    if (empujar) history.pushState(null, '', url);

    window.scrollTo(0, 0);
    const contenido = $('#contenido');
    if (contenido) contenido.focus({ preventScroll: true });

    if (!sinMov) {
      vistaActual.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 150, easing: 'ease-out' });
    }

    const datosAyni = extraerAyni(doc, html);
    if (datosAyni) {
      if (datosAyni.csrf) {
        A.csrf = datosAyni.csrf;
        if (window.AYNI) window.AYNI.csrf = datosAyni.csrf;
        $$('input[name=csrf]').forEach(i => { i.value = datosAyni.csrf; });
      }
      if (datosAyni.pagina) {
        A.pagina = datosAyni.pagina;
        if (window.AYNI) window.AYNI.pagina = datosAyni.pagina;
        document.body.dataset.pagina = datosAyni.pagina;
        const btnAyuda = $('button[data-panel*="panel=ayuda"]');
        if (btnAyuda) btnAyuda.dataset.panel = `panel=ayuda&de=${datosAyni.pagina}`;
      }
      if (Array.isArray(datosAyni.avisos) && datosAyni.avisos.length) {
        datosAyni.avisos.forEach((a, i) => setTimeout(() => aviso(a.msg, a.tipo, a.deshacer), 120 + i * 80));
      }
    }

    init(vistaActual);
  }

  document.addEventListener('click', e => {
    if (e.defaultPrevented) return;
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a');
    if (!a || !a.href) return;
    if (a.target && a.target !== '' && a.target !== '_self') return;
    if (a.hasAttribute('data-parcial') || a.closest('[data-parcial]')) return;
    if (a.hasAttribute('data-panel') || a.closest('[data-panel]')) return;
    if (a.closest('.dropdown-menu') && (a.hasAttribute('data-panel') || a.closest('[data-panel]'))) return;
    if (a.matches('[data-bulk-panel],[data-bulk-href],[data-bulk-limpiar]') || [...a.attributes].some(att => att.name.startsWith('data-bulk-'))) return;

    let u;
    try { u = new URL(a.href, location.href); } catch (_) { return; }
    if (u.origin !== location.origin) return;
    if (u.searchParams.has('exportar') || u.searchParams.has('accion')) return;
    if (!u.pathname.endsWith('index.php') || !u.searchParams.has('p')) return;

    e.preventDefault();
    navegar(u.href, true);
  });

  /* ================= Varios ================= */
  /* Plegables con recuerdo de estado por usuario (p. ej., «Puntuales»). */
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-plegar]'); if (!b) return;
    const t = $(b.dataset.plegar); if (!t) return;
    const abrir = t.hidden; t.hidden = !abrir; b.setAttribute('aria-expanded', abrir);
    const i = $('.ph', b); if (i) i.style.transform = abrir ? 'rotate(180deg)' : '';
    if (abrir && !sinMov) t.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 160 });
  });
  /* Imprimir. */
  document.addEventListener('click', e => { if (e.target.closest('[data-imprimir]')) print(); });
})();
