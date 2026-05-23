const API = './api';
let lang = 'fr';
let i18n = {};
let projectsCache = null;
let _altchaPayload = '';

/* ── I18N ──────────────────────────────────────────────────── */
async function loadLang(newLang) {
  const res = await fetch(`./i18n/${newLang}.json`);
  i18n = await res.json();
  lang = newLang;
  applyI18n();
  document.querySelectorAll('.lang-toggle__btn')
    .forEach(b => b.classList.toggle('active', b.dataset.lang === lang));

  // Refetch l'onglet actif avec la nouvelle locale
  projectsCache = null;
  const activeTab = document.querySelector('[data-tab].active')?.dataset.tab;
  if (activeTab) {
    delete tabLoaded[activeTab];
    tabLoaded[activeTab] = true;
    loadTab(activeTab);
  }
}

function t(key) {
  return key.split('.').reduce((o, k) => o?.[k], i18n) ?? key;
}

function applyI18n() {
  document.querySelectorAll('[data-i18n]')
    .forEach(el => { el.textContent = t(el.dataset.i18n); });
  document.querySelectorAll('[data-i18n-placeholder]')
    .forEach(el => { el.placeholder = t(el.dataset.i18nPlaceholder); });
}

/* ── TAB NAVIGATION ────────────────────────────────────────── */
const tabLoaded = {};

function switchTab(name) {
  document.querySelectorAll('.tab-panel')
    .forEach(p => p.classList.remove('active'));
  document.querySelectorAll('[data-tab]')
    .forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  const panel = document.getElementById(`tab-${name}`);
  if (panel) panel.classList.add('active');
  if (!tabLoaded[name]) { tabLoaded[name] = true; loadTab(name); }
}

async function loadTab(name) {
  switch (name) {
    case 'experiences': return renderExperiences();
    case 'creations':   return renderProjects();
    case 'formations':  return renderFormations();
    case 'contact':     return renderContact();
  }
}

/* ── API FETCH ─────────────────────────────────────────────── */
async function get(endpoint) {
  try {
    const sep = endpoint.includes('?') ? '&' : '?';
    const res = await fetch(`${API}/${endpoint}${sep}lang=${lang}`);
    if (!res.ok) throw new Error(res.statusText);
    return res.json();
  } catch { return null; }
}

/* ── EXPERIENCES ───────────────────────────────────────────── */
async function renderExperiences() {
  const data  = await get('experiences.php');
  const panel = document.getElementById('tab-experiences');

  const totalYears = data
    ? Math.round(data.reduce((acc, e) => {
        const s   = new Date(e.start_date);
        const end = e.end_date ? new Date(e.end_date) : new Date();
        return acc + (end - s) / 31536000000;
      }, 0))
    : 0;

  const topSkills = data
    ? [...new Set(data.flatMap(e => e.skills ?? []).map(s => s.name))].slice(0, 3).join(' / ')
    : '—';

  /* Catégorie de filtre selon le type de contrat */
  function filterCat(type) {
    const t = (type ?? '').toLowerCase();
    if (t === 'cdi' || t === 'cdd')          return 'fulltime';
    if (t === 'stage' || t === 'alternance') return 'internship';
    return 'other';
  }

  panel.innerHTML = `
    <div class="exp-section">

      <!-- Breadcrumb + filtres -->
      <div class="exp-header">
        <span class="exp-breadcrumb">/01 · EXPERIENCE</span>
        <div class="exp-filters">
          <button class="exp-filter active" data-filter="all">${t('experiences.filter_all')}</button>
          <button class="exp-filter" data-filter="fulltime">${t('experiences.filter_fulltime')}</button>
          <button class="exp-filter" data-filter="internship">${t('experiences.filter_internship')}</button>
        </div>
      </div>

      <!-- Titre + annotation + sous-titre -->
      <div class="exp-title-area">
        <div class="exp-title-row">
          <h2 class="exp-title">${t('experiences.display_title')}</h2>
        </div>
        <p class="exp-subtitle">
          ${data?.length ?? 0} ${t('experiences.roles_label')} · ${totalYears}+ ${t('experiences.years_label')} · ${topSkills || '—'}.<br>
          ${t('experiences.hover_hint')}
        </p>
      </div>

      <!-- Highlights strip -->
      <div class="exp-highlights">
        <div class="exp-highlight-cell">
          <div class="exp-highlight-label">YEARS</div>
          <div class="exp-highlight-value">${totalYears}+</div>
        </div>
        <div class="exp-highlight-cell">
          <div class="exp-highlight-label">ROLES</div>
          <div class="exp-highlight-value">${data?.length ?? 0}</div>
        </div>
        <div class="exp-highlight-cell">
          <div class="exp-highlight-label">MAIN STACK</div>
          <div class="exp-highlight-value exp-highlight-value--stack">${topSkills || '—'}</div>
        </div>
        <div class="exp-highlight-cell">
          <div class="exp-highlight-label">STATUS</div>
          <div class="exp-highlight-value">
            <span class="exp-status-dot"></span> ${t('common.open_status')}
          </div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="exp-timeline">
        ${(data ?? []).map((exp, i) => {
          const isCurrent = !exp.end_date;
          const cat       = filterCat(exp.type);
          const co        = exp.company  ? `[ ${exp.company} ]`   : '';
          const loc       = exp.location ? ` · ${exp.location}`   : '';
          const typ       = exp.type     ? ` · ${exp.type}`       : '';
          return `
          <div class="timeline-row ${isCurrent ? 'current' : ''}" data-filter-cat="${cat}">
            <div class="timeline-year">${periodDisplay(exp.start_date, exp.end_date)}</div>
            <div class="timeline-detail">
              <div class="timeline-role ${isCurrent ? 'current' : ''}">${exp.role}</div>
              <div class="timeline-company">${co}${loc}${typ}</div>
              ${exp.description ? `<p class="timeline-desc">${exp.description}</p>` : ''}
              ${exp.skills?.length ? `<div class="chips-list">${exp.skills.map(s => `<span class="chip">${s.name ?? s}</span>`).join('')}</div>` : ''}
            </div>
          </div>`;
        }).join('')}
      </div>

    </div>
  `;

  /* Filtres */
  panel.querySelectorAll('.exp-filter').forEach(btn => {
    btn.addEventListener('click', () => {
      const f = btn.dataset.filter;
      panel.querySelectorAll('.exp-filter')
        .forEach(b => b.classList.toggle('active', b === btn));
      panel.querySelectorAll('.timeline-row').forEach(row => {
        row.style.display =
          f === 'all' || row.dataset.filterCat === f ? '' : 'none';
      });
    });
  });

  applyI18n();
}

/* ── PROJECTS ──────────────────────────────────────────────── */
async function renderProjects() {
  const data = await get('projects.php');
  projectsCache = data;
  const panel = document.getElementById('tab-creations');

  panel.innerHTML = `
    <h2 class="section-title" data-i18n="creations.title"></h2>
    <div class="projects-grid">
      ${(data ?? []).map(p => `
        <div class="project-card" onclick="openModal(${p.id})">
          <div class="project-card__thumb">
            ${p.photo_url
              ? `<img src="${p.photo_url}" alt="${p.name}">`
              : `<span>${p.name[0]}</span>`}
            ${p.is_favorite ? `<span class="project-card__favorite" title="Favori">★</span>` : ''}
          </div>
          <div class="project-card__body">
            <div class="project-card__name">${p.name}</div>
            ${chips((p.skills ?? []).slice(0, 3))}
          </div>
        </div>
      `).join('')}
    </div>
    <div class="modal-overlay hidden" id="proj-modal" onclick="closeModal(event)">
      <div class="modal" id="proj-modal-body"></div>
    </div>
  `;
  applyI18n();
}

function openModal(id) {
  const p = projectsCache?.find(x => x.id === id);
  if (!p) return;
  document.getElementById('proj-modal-body').innerHTML = `
    <h3 class="modal__title">${p.name}</h3>
    ${p.date ? `<span class="period" style="margin-bottom:10px;display:inline-flex">${fmtDate(p.date)}</span>` : ''}
    <p class="modal__description">${p.description ?? ''}</p>
    ${chips(p.skills)}
    <div style="display:flex;flex-direction:column;gap:8px;margin-top:16px">
      ${p.url
        ? `<a href="${p.url}" target="_blank" rel="noopener noreferrer"
              class="btn btn--outline btn--sm" style="width:100%"
              onclick="event.stopPropagation()">${t('creations.website')}</a>`
        : ''}
      ${p.github_url
        ? `<a href="${p.github_url}" target="_blank" rel="noopener noreferrer"
              class="btn btn--outline btn--sm" style="width:100%"
              onclick="event.stopPropagation()">${t('creations.github')}</a>`
        : ''}
      <button class="btn btn--outline btn--sm" style="width:100%"
              onclick="document.getElementById('proj-modal').classList.add('hidden')">
        ${t('creations.close')}
      </button>
    </div>
  `;
  document.getElementById('proj-modal').classList.remove('hidden');
}

function closeModal(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.add('hidden');
  }
}

/* ── FORMATIONS ────────────────────────────────────────────── */
async function renderFormations() {
  const [formations, skills] = await Promise.all([
    get('formations.php'),
    get('skills.php'),
  ]);
  const panel = document.getElementById('tab-formations');

  const byCategory = {};
  (skills ?? []).forEach(s => {
    (byCategory[s.category] ??= []).push(s);
  });

  panel.innerHTML = `
    <h2 class="section-title" data-i18n="formations.title"></h2>
    <div class="formations-layout">
      <div class="formations-list">
        ${(formations ?? []).map(f => `
          <div class="formation-card">
            <div class="formation-card__school">${f.school}</div>
            <div class="formation-card__title">${f.title}</div>
            ${f.level ? `<div class="formation-card__level">${f.level}</div>` : ''}
            <span class="period">${period(f.start_date, f.end_date)}</span>
            ${f.description
              ? `<p style="font-size:0.83rem;color:#ccc;margin-top:10px;line-height:1.65">${f.description}</p>`
              : ''}
            ${chips(f.skills)}
          </div>
        `).join('')}
      </div>
      <div class="skills-sidebar">
        <h3 class="skills-sidebar__title" data-i18n="formations.skills_title"></h3>
        ${Object.entries(byCategory).map(([cat, items]) => `
          <div class="skills-sidebar__category">
            <div class="skills-sidebar__category-name">${cat}</div>
            <div class="chips-list">${items.map(s => `<span class="chip">${s.name}</span>`).join('')}</div>
          </div>
        `).join('')}
      </div>
    </div>
  `;
  applyI18n();
}

/* ── CONTACT ───────────────────────────────────────────────── */
async function renderContact() {
  const profile = await get('profile.php');
  const panel   = document.getElementById('tab-contact');

  panel.innerHTML = `
    <h2 class="section-title" data-i18n="contact.title"></h2>
    <div class="contact-layout">
      <div class="contact-info">
        <p class="contact-info__tagline" data-i18n="contact.tagline"></p>
        <ul class="contact-info__links">
          ${profile?.email ? `<li><a href="mailto:${profile.email}" class="contact-info__link">✉ ${profile.email}</a></li>` : ''}
          ${profile?.phone ? `<li><a href="tel:${profile.phone}"   class="contact-info__link">☎ ${profile.phone}</a></li>` : ''}
        </ul>
        <div class="open-to-work" data-i18n="contact.open_to_work"></div>
      </div>
      <div class="contact-form-wrapper">
        <form class="contact-form" id="contact-form" onsubmit="sendContact(event)">
          <div class="form-group">
            <label data-i18n="contact.form.name_label"></label>
            <input type="text"  name="name"    required data-i18n-placeholder="contact.form.name_placeholder">
          </div>
          <div class="form-group">
            <label data-i18n="contact.form.email_label"></label>
            <input type="email" name="email"   required data-i18n-placeholder="contact.form.email_placeholder">
          </div>
          <div class="form-group">
            <label data-i18n="contact.form.message_label"></label>
            <textarea name="message" required data-i18n-placeholder="contact.form.message_placeholder"></textarea>
          </div>
          <div class="form-group">
            <altcha-widget challengeurl="${new URL('./api/altcha.php', location.href).href}" auto="onload" hidefooter></altcha-widget>
          </div>
          <button type="submit" class="btn btn--primary" data-i18n="contact.form.submit"></button>
        </form>
      </div>
    </div>
  `;
  applyI18n();

  _altchaPayload = '';
  document.getElementById('contact-form')
    ?.querySelector('altcha-widget')
    ?.addEventListener('statechange', (ev) => {
      const payload = ev.detail?.payload ?? ev.detail?.value ?? ev.target?.value ?? '';
      if (payload) _altchaPayload = payload;
    });
}

async function sendContact(e) {
  e.preventDefault();
  if (!_altchaPayload) {
    notify(t('contact.form.captcha_wait'));
    return;
  }
  const data = Object.fromEntries(new FormData(e.target));
  data.altcha = _altchaPayload;
  try {
    const res = await fetch(`${API}/contact.php`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(data),
    });
    if (res.ok) { e.target.reset(); _altchaPayload = ''; notify(t('contact.form.success')); }
    else notify(t('contact.form.error'));
  } catch { notify(t('contact.form.error')); }
}

/* ── HELPERS ───────────────────────────────────────────────── */
function chips(items) {
  if (!items?.length) return '';
  return `<div class="chips-list">${items.map(s => `<span class="chip">${s.name ?? s}</span>`).join('')}</div>`;
}

/** Affiche "2024 — now", "2022 — 2024" ou "2020" */
function periodDisplay(start, end) {
  if (!start) return '';
  const sy = new Date(start).getFullYear();
  if (!end)  return `${sy} — now`;
  const ey = new Date(end).getFullYear();
  return sy === ey ? String(sy) : `${sy} — ${ey}`;
}

function period(start, end) {
  if (!start) return '';
  return `${fmtDate(start)} – ${end ? fmtDate(end) : t('common.present')}`;
}

function fmtDate(str) {
  if (!str) return '';
  return new Date(str).toLocaleDateString(
    lang === 'fr' ? 'fr-FR' : 'en-US',
    { month: 'short', year: 'numeric' }
  );
}

function notify(msg) {
  let el = document.getElementById('notif');
  if (!el) {
    el = document.createElement('div');
    el.id        = 'notif';
    el.className = 'notification';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.classList.add('visible');
  setTimeout(() => el.classList.remove('visible'), 3000);
}

/* ── THEME ─────────────────────────────────────────────────── */
function initTheme() {
  const saved = localStorage.getItem('theme') || 'light';
  document.documentElement.dataset.theme = saved;
  // les icônes sont gérées via CSS (::before selon data-theme),
  // on met juste à jour le aria-label / title
  _syncThemeButtons();
}

function toggleTheme() {
  const current = document.documentElement.dataset.theme;
  const next    = current === 'dark' ? 'light' : 'dark';
  document.documentElement.dataset.theme = next;
  localStorage.setItem('theme', next);
  _syncThemeButtons();
}

function _syncThemeButtons() {
  const isDark  = document.documentElement.dataset.theme === 'dark';
  const label   = isDark ? 'Passer en mode clair' : 'Passer en mode sombre';
  document.querySelectorAll('.theme-toggle').forEach(btn => {
    btn.title       = label;
    btn.setAttribute('aria-label', label);
  });
}

/* ── INIT ──────────────────────────────────────────────────── */
async function init() {
  initTheme();   /* ← en premier pour éviter le flash */

  await loadLang('fr');

  const profile = await get('profile.php');
  if (profile) {
    document.querySelectorAll('.profile-name')
      .forEach(el => { el.textContent = profile.name ?? ''; });
    document.querySelectorAll('.profile-title')
      .forEach(el => { el.textContent = profile.title ?? ''; });
    document.querySelectorAll('.profile-avatar').forEach(img => {
      if (profile.photo_url) img.src = profile.photo_url;
    });
  }

  document.querySelectorAll('.lang-toggle__btn')
    .forEach(b => b.addEventListener('click', () => loadLang(b.dataset.lang)));

  document.querySelectorAll('.theme-toggle')
    .forEach(b => b.addEventListener('click', toggleTheme));

  document.querySelectorAll('[data-tab]')
    .forEach(b => b.addEventListener('click', () => switchTab(b.dataset.tab)));

  switchTab('experiences');
}

document.addEventListener('DOMContentLoaded', init);
