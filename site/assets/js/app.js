const API = './api';
let lang = 'fr';
let projectsCache = null;
let _altchaPayload = '';

/* ── I18N — délègue au module i18n.js ──────────────────────── */
function t(key)      { return I18n.t(key); }
function applyI18n() { I18n.applyI18n(); }

async function loadLang(newLang) {
  await I18n.loadLang(newLang, './i18n');
  lang = I18n.getLang();

  document.querySelectorAll('.lang-toggle__btn')
    .forEach(b => b.classList.toggle('active', b.dataset.lang === lang));

  projectsCache = null;
  Object.keys(tabLoaded).forEach(k => delete tabLoaded[k]);

  // Re-render de tous les onglets immédiatement :
  // — l'onglet actif avec fondu (visible)
  // — les autres en arrière-plan (cachés) → prêts sans flash au prochain switch
  const activeTab = document.querySelector('[data-tab].active')?.dataset.tab;
  const allTabs   = ['experiences', 'creations', 'formations', 'contact'];

  for (const tab of allTabs) {
    tabLoaded[tab] = true;
    if (tab === activeTab) {
      const panel = document.getElementById(`tab-${tab}`);
      panel?.classList.add('tab-panel--rendering');
      loadTab(tab)
        .then(() => requestAnimationFrame(() => panel?.classList.remove('tab-panel--rendering')))
        .catch(()  => panel?.classList.remove('tab-panel--rendering'));
    } else {
      loadTab(tab); // silencieux — l'onglet est caché (display:none)
    }
  }

  // Mise à jour du lien CV pour la nouvelle langue
  updateCvLink(lang);
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

  if (!tabLoaded[name]) {
    tabLoaded[name] = true;
    /* Masquage instantané → rendu → fondu en entrée */
    panel?.classList.add('tab-panel--rendering');
    loadTab(name)
      .then(() => requestAnimationFrame(() => panel?.classList.remove('tab-panel--rendering')))
      .catch(()  => panel?.classList.remove('tab-panel--rendering'));
  }
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

  /* Types uniques présents dans les données (dédupliqués)
   * Chaque entrée : { key: 'work_study', label: 'Alternance' }
   * - key   → clé enum stable (type_key) — identique quelle que soit la langue active
   * - label → libellé traduit retourné par l'API (type) pour l'affichage du bouton
   */
  const highlights  = data
    ? AppUtils.computeHighlights(data)
    : { totalYears: 0, topSkills: [], uniqueTypes: [], hasOpenStatus: false };
  const totalYears  = highlights.totalYears;
  const topSkills   = highlights.topSkills.join(' / ') || '—';
  const uniqueTypes = highlights.uniqueTypes;

  panel.innerHTML = `
    <div class="exp-section">

      <!-- Breadcrumb + filtres -->
      <div class="exp-header">
        <span class="exp-breadcrumb">/01 · ${t('experiences.breadcrumb')}</span>
        <div class="exp-filters">
          <button class="exp-filter active" data-filter="all">${t('experiences.filter_all')}</button>
          ${uniqueTypes
            .map(f => `<button class="exp-filter" data-filter="${f.key}">${f.label}</button>`)
            .join('')}
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
          <div class="exp-highlight-label">${t('experiences.highlight_years')}</div>
          <div class="exp-highlight-value">${totalYears}+</div>
        </div>
        <div class="exp-highlight-cell">
          <div class="exp-highlight-label">${t('experiences.highlight_roles')}</div>
          <div class="exp-highlight-value">${data?.length ?? 0}</div>
        </div>
        <div class="exp-highlight-cell">
          <div class="exp-highlight-label">${t('experiences.highlight_stack')}</div>
          <div class="exp-highlight-value exp-highlight-value--stack">${topSkills || '—'}</div>
        </div>
        <div class="exp-highlight-cell">
          <div class="exp-highlight-label">${t('experiences.highlight_status')}</div>
          <div class="exp-highlight-value">
            <span class="exp-status-dot"></span> ${t('common.open_status')}
          </div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="exp-timeline">
        ${(data ?? []).map((exp) => {
          const isCurrent = !exp.end_date;
          const typeKey   = exp.type_key ?? '';   // clé enum stable — même valeur que les boutons
          const co        = exp.company  ? `[ ${exp.company} ]`   : '';
          const loc       = exp.location ? ` · ${exp.location}`   : '';
          const typ       = exp.type     ? ` · ${exp.type}`       : '';
          return `
          <div class="timeline-row ${isCurrent ? 'current' : ''}" data-filter-cat="${typeKey}">
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
const PROJ_PER_PAGE = 6;
let _projPage   = 1;
let _projFilter = 'all';

/* Carte standard (page 2+ et vues filtrées) */
function _projCardSmall(p) {
  const yr    = p.date ? new Date(p.date).getFullYear() : '';
  const stack = (p.skills ?? []).slice(0, 3).map(s => s.name ?? s).join(' · ');
  return `
    <div class="proj-card" data-cat="${(p.category ?? '').toLowerCase()}" onclick="openModal(${p.id})">
      <div class="proj-card__media proj-card__media--hatch proj-card__media--ratio">
        ${p.photo_url ? `<img src="${p.photo_url}" alt="${p.name}" class="proj-card__img">` : ''}
        <span class="proj-card__media-label">IMG · 16:9</span>
        ${yr ? `<span class="proj-card__year">${yr}</span>` : ''}
      </div>
      <div class="proj-card__body">
        <div class="proj-card__name">[ ${p.name} ]</div>
        ${p.skills?.length ? `<div class="proj-card__cat-line">${stack}</div>` : ''}
      </div>
    </div>`;
}

/* Numéros de page avec ellipsis — délègue à AppUtils */
function _paginationPages(totalPages) {
  return AppUtils.buildPaginationPages(_projPage, totalPages);
}

/* Rendu du contenu paginé (grille + pagination + footer) */
function _renderProjPage() {
  const data   = projectsCache ?? [];
  const filter = _projFilter;

  /* Pool filtré — correspondance exacte sur la clé normalisée */
  const pool = filter === 'all'
    ? data
    : data.filter(p => (p.category ?? '').trim().toLowerCase() === filter);

  const total      = pool.length;
  const totalPages = Math.max(1, Math.ceil(total / PROJ_PER_PAGE));
  _projPage        = Math.min(_projPage, totalPages);
  const page       = _projPage;

  /* ── Grille ─────────────────────────────────────────────── */
  let gridHtml = '';

  if (filter === 'all' && page === 1) {
    /* Vue featured : 1 featured + 2 side + 3 bottom */
    const featured    = data.find(p => p.is_favorite) ?? data[0] ?? null;
    const rest        = data.filter(p => p !== featured);
    const sideCards   = rest.slice(0, 2);
    const bottomCards = rest.slice(2, 5);

    const yr    = d  => d ? new Date(d).getFullYear() : '';
    const stack = ss => (ss ?? []).slice(0, 3).map(s => s.name ?? s).join(' · ');

    const featuredHtml = featured ? `
      <div class="proj-card proj-card--featured" data-cat="${(featured.category ?? '').toLowerCase()}" onclick="openModal(${featured.id})">
        <div class="proj-card__media proj-card__media--hatch">
          ${featured.photo_url ? `<img src="${featured.photo_url}" alt="${featured.name}" class="proj-card__img">` : ''}
          <span class="proj-card__tag">FEATURED</span>
        </div>
        <div class="proj-card__body proj-card__body--featured">
          <div class="proj-card__name proj-card__name--lg">[ ${featured.name} ]</div>
          ${featured.description ? `<p class="proj-card__pitch">${featured.description.slice(0, 140)}${featured.description.length > 140 ? '…' : ''}</p>` : ''}
          ${featured.skills?.length ? `<p class="proj-card__stack-line">Stack: ${stack(featured.skills)}.</p>` : ''}
        </div>
      </div>` : '';

    const sideHtml = sideCards.map(p => `
      <div class="proj-card proj-card--side" data-cat="${(p.category ?? '').toLowerCase()}" onclick="openModal(${p.id})">
        <div class="proj-card__media proj-card__media--hatch proj-card__media--sm">
          ${p.photo_url ? `<img src="${p.photo_url}" alt="${p.name}" class="proj-card__img">` : ''}
          <span class="proj-card__media-label">IMG · 16:9</span>
          <span class="proj-card__year">${yr(p.date)}</span>
        </div>
        <div class="proj-card__body">
          <div class="proj-card__name">[ ${p.name} ]</div>
          ${p.skills?.length ? `<div class="proj-card__cat-line">${stack(p.skills)}</div>` : ''}
        </div>
      </div>`).join('');

    const bottomHtml = bottomCards.map(p => `
      <div class="proj-card" data-cat="${(p.category ?? '').toLowerCase()}" onclick="openModal(${p.id})">
        <div class="proj-card__media proj-card__media--hatch proj-card__media--ratio">
          ${p.photo_url ? `<img src="${p.photo_url}" alt="${p.name}" class="proj-card__img">` : ''}
          <span class="proj-card__media-label">IMG · 16:9</span>
          <span class="proj-card__year">${yr(p.date)}</span>
        </div>
        <div class="proj-card__body">
          <div class="proj-card__name">[ ${p.name} ]</div>
          ${p.skills?.length ? `<div class="proj-card__cat-line">${stack(p.skills)}</div>` : ''}
        </div>
      </div>`).join('');

    gridHtml = `
      <div class="proj-grid">
        ${(featured || sideCards.length) ? `
        <div class="proj-grid__top">
          ${featuredHtml}
          ${sideCards.length ? `<div class="proj-grid__side">${sideHtml}</div>` : ''}
        </div>` : ''}
        ${bottomCards.length ? `<div class="proj-grid__row">${bottomHtml}</div>` : ''}
      </div>`;

  } else {
    /* Grille standard : n colonnes */
    const { items: slice } = AppUtils.paginateItems(pool, page, PROJ_PER_PAGE);
    gridHtml = `
      <div class="proj-grid">
        <div class="proj-grid__row">${slice.map(_projCardSmall).join('')}</div>
      </div>`;
  }

  /* ── Pagination ──────────────────────────────────────────── */
  let pagHtml = '';
  if (totalPages > 1) {
    const nums = _paginationPages(totalPages).map(n =>
      n === '…'
        ? `<span class="proj-pag-ellipsis">…</span>`
        : `<button class="proj-pag-num${n === page ? ' active' : ''}" onclick="_goPage(${n})">${n}</button>`
    ).join('');
    pagHtml = `
      <div class="proj-pagination">
        <button class="proj-pag-btn" onclick="_goPage(${page - 1})" ${page <= 1 ? 'disabled' : ''}>‹</button>
        <div class="proj-pag-pages">${nums}</div>
        <button class="proj-pag-btn" onclick="_goPage(${page + 1})" ${page >= totalPages ? 'disabled' : ''}>›</button>
      </div>`;
  }

  /* ── Footer "showing" ────────────────────────────────────── */
  const showStart = (filter === 'all' && page === 1) ? 1 : (page - 1) * PROJ_PER_PAGE + 1;
  const showEnd   = (filter === 'all' && page === 1)
    ? Math.min(PROJ_PER_PAGE, data.length)
    : Math.min(page * PROJ_PER_PAGE, total);
  const showTotal = filter === 'all' ? data.length : total;
  const showingStr = t('creations.showing')
    .replace('{start}', showStart)
    .replace('{end}',   showEnd)
    .replace('{total}', showTotal);

  document.getElementById('proj-content').innerHTML = `
    ${gridHtml}
    ${pagHtml}
    <div class="proj-footer">
      <span class="proj-showing">${showingStr}</span>
      <a href="https://github.com/ValcasaraBryan" target="_blank" rel="noopener"
         class="btn btn--outline btn--sm">${t('creations.see_all')}</a>
    </div>
  `;
}

function _goPage(n) {
  const data       = projectsCache ?? [];
  const pool       = _projFilter === 'all'
    ? data
    : data.filter(p => (p.category ?? '').trim().toLowerCase() === _projFilter);
  const totalPages = Math.max(1, Math.ceil(pool.length / PROJ_PER_PAGE));
  if (n < 1 || n > totalPages) return;
  _projPage = n;
  _renderProjPage();
  document.getElementById('proj-content')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function renderProjects() {
  const data = await get('projects.php');
  projectsCache = data;
  _projPage   = 1;
  _projFilter = 'all';
  const panel = document.getElementById('tab-creations');
  const total = data?.length ?? 0;

  panel.innerHTML = `
    <div class="proj-section">

      <!-- Header: breadcrumb + filtres -->
      <div class="proj-header">
        <span class="proj-breadcrumb">/02 · ${t('creations.breadcrumb')} · ${total} ${t('creations.projects_label')}</span>
        <div class="proj-filters">
          <button class="proj-filter active" data-filter="all">${t('creations.filter_all')}</button>
          ${['web', 'opensource', 'side']
            .filter(cat => (data ?? []).some(p =>
              (p.category ?? '').trim().toLowerCase() === cat
            ))
            .map(cat => `<button class="proj-filter" data-filter="${cat}">${t('creations.filter_' + cat)}</button>`)
            .join('')}
        </div>
      </div>

      <!-- Titre -->
      <div class="proj-title-area">
        <h2 class="proj-title">${t('creations.display_title')}</h2>
        <p class="proj-subtitle">${t('creations.subtitle')}</p>
      </div>

      <!-- Contenu paginé -->
      <div id="proj-content"></div>

    </div>
    <!-- Modal -->
    <div class="modal-overlay hidden" id="proj-modal" onclick="closeModal(event)">
      <div class="modal" id="proj-modal-body"></div>
    </div>
  `;

  /* Filtres — reset si le filtre courant n'existe plus dans les données */
  const availableCats = new Set(
    ['all', 'web', 'opensource', 'side'].filter(cat =>
      cat === 'all' || (data ?? []).some(p =>
        (p.category ?? '').trim().toLowerCase() === cat
      )
    )
  );
  if (!availableCats.has(_projFilter)) _projFilter = 'all';

  panel.querySelectorAll('.proj-filter').forEach(btn => {
    btn.addEventListener('click', () => {
      panel.querySelectorAll('.proj-filter').forEach(b => b.classList.toggle('active', b === btn));
      _projFilter = btn.dataset.filter;
      _projPage   = 1;
      _renderProjPage();
    });
  });

  _renderProjPage();
  applyI18n();
}

async function checkUrl(url) {
  try {
    const res  = await fetch(`./api/check-url.php?url=${encodeURIComponent(url)}`);
    const data = await res.json();
    return data.online === true;
  } catch {
    return false;
  }
}

function openModal(id) {
  const p = projectsCache?.find(x => x.id === id);
  if (!p) return;
  const year = p.date ? new Date(p.date).getFullYear() : null;

  document.getElementById('proj-modal-body').innerHTML = `
    <div class="modal-header">
      <h3 class="modal__title">[ ${p.name} ]</h3>
      ${year ? `<span class="modal__meta">${year}</span>` : ''}
    </div>
    ${p.category ? `<p class="modal__category">${p.category}</p>` : ''}
    <p class="modal__description">${p.description ?? ''}</p>
    ${chips(p.skills)}
    <div class="modal__actions">
      ${p.url
        ? `<a id="modal-url-btn" href="${p.url}" target="_blank" rel="noopener noreferrer"
              class="btn btn--outline btn--sm" onclick="event.stopPropagation()">
             ${t('creations.checking')}
           </a>`
        : ''}
      ${p.github_url
        ? `<a href="${p.github_url}" target="_blank" rel="noopener noreferrer"
              class="btn btn--outline btn--sm" onclick="event.stopPropagation()">${t('creations.github')}</a>`
        : ''}
      <button class="btn btn--outline btn--sm"
              onclick="document.getElementById('proj-modal').classList.add('hidden')">
        ${t('creations.close')}
      </button>
    </div>
  `;
  document.getElementById('proj-modal').classList.remove('hidden');

  /* Vérification asynchrone du lien externe */
  if (p.url) {
    checkUrl(p.url).then(online => {
      const btn = document.getElementById('modal-url-btn');
      if (!btn) return;
      if (online) {
        btn.textContent = t('creations.website');
      } else {
        btn.textContent  = t('creations.site_offline');
        btn.removeAttribute('href');
        btn.classList.add('btn--disabled');
        btn.setAttribute('aria-disabled', 'true');
        btn.onclick = e => e.stopPropagation();
      }
    });
  }
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

  const byCategory = AppUtils.groupSkillsByCategory(skills ?? []);

  panel.innerHTML = `
    <h2 class="section-title" data-i18n="formations.title"></h2>
    <div class="formations-layout">
      <div class="formations-list">
        ${(formations ?? []).map(f => `
          <div class="formation-card">
            <div class="formation-card__school">${escapeHtml(f.school)}</div>
            <div class="formation-card__title">${escapeHtml(f.title)}</div>
            ${f.level ? `<div class="formation-card__level">${escapeHtml(f.level)}</div>` : ''}
            <span class="period">${period(f.start_date, f.end_date)}</span>
            ${f.description
              ? `<p style="font-size:0.83rem;color:#ccc;margin-top:10px;line-height:1.65">${escapeHtml(f.description)}</p>`
              : ''}
            ${chips(f.skills)}
            ${(() => {
              const certs = f.certifications ?? [];
              if (!certs.length) return '';
              return `<ul class="formation__certs">
                ${certs.map(c =>
                  `<li class="formation__cert">
                    ${c.year ? `<span class="formation__cert-year">${escapeHtml(String(c.year))}</span> — ` : ''}
                    <span class="formation__cert-name">${escapeHtml(c.name)}</span>
                  </li>`
                ).join('')}
              </ul>`;
            })()}
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

/** Délègue à AppUtils pour garder la logique centralisée et testable. */
function escapeHtml(str) { return AppUtils.escapeHtml(str); }

function chips(items) {
  if (!items?.length) return '';
  return `<div class="chips-list">${items.map(s => `<span class="chip">${s.name ?? s}</span>`).join('')}</div>`;
}

/** Affiche "2024 — now", "2022 — 2024" ou "2020" — délègue à AppUtils */
function periodDisplay(start, end) {
  return AppUtils.formatPeriod(start, end, t('common.present'));
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

/* ── CV LINK ───────────────────────────────────────────────── */
/**
 * Met à jour les liens de téléchargement du CV selon la langue active.
 * Désactive le bouton (aria-disabled + classe) si aucun CV n'est disponible.
 */
async function updateCvLink(currentLang) {
  try {
    const res  = await fetch(`${API}/cv.php?lang=${currentLang}`);
    if (!res.ok) throw new Error(res.statusText);
    const data = await res.json();
    document.querySelectorAll('[data-cv-link]').forEach(a => {
      if (data.exists) {
        a.href = data.url;
        a.removeAttribute('aria-disabled');
        a.classList.remove('btn--disabled');
        a.title = '';
      } else {
        a.href = '#';
        a.setAttribute('aria-disabled', 'true');
        a.classList.add('btn--disabled');
        a.title = t('common.cv_unavailable');
      }
    });
  } catch {
    /* En cas d'erreur réseau, on laisse les liens désactivés */
  }
}

/* ── SOCIAL LINKS ──────────────────────────────────────────── */
function renderSocialLinks(links) {
  const container = document.getElementById('social-links');
  if (!container) return;
  container.innerHTML = '';
  (links ?? []).forEach(link => {
    const a = document.createElement('a');
    a.href       = link.url;
    a.className  = 'social-link';
    a.title      = link.platform;
    a.textContent = link.icon;          // textContent, jamais innerHTML
    if (!link.url.startsWith('mailto:')) {
      a.target = '_blank';
      a.rel    = 'noopener noreferrer';
    }
    container.appendChild(a);
  });
}

/* ── INIT ──────────────────────────────────────────────────── */
async function init() {
  initTheme();   /* ← en premier pour éviter le flash */

  await loadLang(localStorage.getItem('lang') || 'fr');

  const profile = await get('profile.php');
  if (profile) {
    document.querySelectorAll('.profile-name')
      .forEach(el => { el.textContent = profile.name ?? ''; });
    document.querySelectorAll('.profile-title')
      .forEach(el => { el.textContent = profile.title ?? ''; });
    document.querySelectorAll('.profile-avatar').forEach(img => {
      if (profile.photo_url) img.src = profile.photo_url;
    });
    renderSocialLinks(profile.links);
  }

  // Chargement initial du lien CV
  updateCvLink(lang);

  document.querySelectorAll('.lang-toggle__btn')
    .forEach(b => b.addEventListener('click', () => loadLang(b.dataset.lang)));

  document.querySelectorAll('.theme-toggle')
    .forEach(b => b.addEventListener('click', toggleTheme));

  document.querySelectorAll('[data-tab]')
    .forEach(b => b.addEventListener('click', () => switchTab(b.dataset.tab)));

  switchTab('experiences');
}

document.addEventListener('DOMContentLoaded', init);
