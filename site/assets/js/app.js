const API = './api';
let lang = 'fr';
let projectsCache = null;
let _altchaPayload = '';

const SKILLS_GROUP_THRESHOLD = 5;

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
  const allTabs   = ['about', 'experiences', 'creations', 'formations', 'contact'];

  for (const tab of allTabs) {
    tabLoaded[tab] = true;
    if (tab === activeTab) {
      const panel = document.getElementById(`tab-${tab}`);
      /* Skeleton visible sur l'onglet actif seulement (les autres sont cachés).
       * Même logique que switchTab : pas de --rendering pendant le skeleton. */
      if (panel) panel.innerHTML = getSkeletonHtml(tab);
      loadTab(tab)
        .then(() => {
          if (panel) {
            panel.classList.add('tab-panel--rendering');
            requestAnimationFrame(() =>
              requestAnimationFrame(() => panel.classList.remove('tab-panel--rendering'))
            );
          }
        })
        .catch(() => {
          if (panel) panel.innerHTML = `<p class="tab-error">${I18n.t('common.load_error')}</p>`;
        });
    } else {
      loadTab(tab); // silencieux — l'onglet est caché (display:none), pas de skeleton
    }
  }

  // Mise à jour du lien CV pour la nouvelle langue
  updateCvLink(lang);

  // Re-fetch du profil pour mettre à jour le titre traduit dans le drawer
  const profileOnLangChange = await get('profile.php');
  if (profileOnLangChange) updateDrawerProfile(profileOnLangChange);
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
    /* Skeleton visible immédiatement — PAS de --rendering qui masquerait le skeleton.
     * Quand le contenu réel arrive, on fait un micro-fondu : on cache brièvement
     * (opacity:0 peint sur un frame via double-rAF) puis on laisse la transition
     * CSS ramener à opacity:1. */
    if (panel) panel.innerHTML = getSkeletonHtml(name);
    loadTab(name)
      .then(() => {
        if (panel) {
          panel.classList.add('tab-panel--rendering');          // opacity:0 sans transition
          requestAnimationFrame(() =>                           // frame 1 : peint opacity:0
            requestAnimationFrame(() =>                         // frame 2 : retire la classe
              panel.classList.remove('tab-panel--rendering')   //           → fade-in 180ms
            )
          );
        }
      })
      .catch(() => {
        if (panel) panel.innerHTML = `<p class="tab-error">${I18n.t('common.load_error')}</p>`;
      });
  }
}

/** Délègue à AppUtils.getSkeletonHtml — fonction pure testable. */
function getSkeletonHtml(tab) { return AppUtils.getSkeletonHtml(tab); }

async function loadTab(name) {
  switch (name) {
    case 'about':       return renderAbout();
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
              ${exp.skills?.length ? renderSkillChips(exp.skills) : ''}
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

  attachSkillChipEvents(panel);

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
    ${renderSkillChips(p.skills)}
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

  /* Tooltip + modal skill sur les chips de la modale projet */
  attachSkillChipEvents(document.getElementById('proj-modal-body'), { stopProp: true });

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
  const data  = formations ?? [];

  const byCategory = AppUtils.groupSkillsByCategory(skills ?? []);

  /* Plage de dates globale (min start → max end) */
  const years = data.flatMap(f => {
    const ys = [];
    if (f.start_date) ys.push(new Date(f.start_date).getFullYear());
    if (f.end_date)   ys.push(new Date(f.end_date).getFullYear());
    return ys;
  }).filter(y => !isNaN(y));
  const minYear  = years.length ? Math.min(...years) : null;
  const maxYear  = years.length ? Math.max(...years) : null;
  const dateRange = (minYear && maxYear && minYear !== maxYear)
    ? `${minYear} → ${maxYear}`
    : (minYear ? String(minYear) : '');

  /* Toutes les certifications aplaties (pour le bloc droit) */
  const allCerts = data.flatMap(f => f.certifications ?? []);

  panel.innerHTML = `
    <!-- Breadcrumb + plage dates -->
    <div class="formations-top-header">
      <span class="formations-breadcrumb">/03 · ${t('formations.breadcrumb')} · ${data.length} ${t('formations.diplomas_label')}</span>
      ${dateRange ? `<span class="formations-date-range">${dateRange}</span>` : ''}
    </div>

    <div class="formations-grid">

      <!-- En-tête (pleine largeur) -->
      <div class="formations-header">
        <h2 class="formations-display-title">${t('formations.display_title')}</h2>
        <p class="formations-subtitle">${t('formations.subtitle')}</p>
      </div>

      <!-- Colonne gauche : cartes de formation -->
      <div class="formations-list">
        ${data.map((f, i) => {
          const sy      = f.start_date ? new Date(f.start_date).getFullYear() : '';
          const ey      = f.end_date   ? new Date(f.end_date).getFullYear()   : t('common.present');
          const dateStr = sy ? `${sy}<br>${ey}` : '';
          return `
          <div class="formation-card">
            <div class="formation-card__date">${dateStr}</div>
            <div class="formation-card__content">
              <div class="formation-card__title">${escapeHtml(f.title)}</div>
              <div class="formation-card__school">${escapeHtml(f.school)}${f.level ? ` · ${escapeHtml(f.level)}` : ''}</div>
              ${f.skills?.length ? `<div class="formation-card__sep"></div>${renderSkillChips(f.skills)}` : ''}
            </div>
          </div>`;
        }).join('')}
      </div>

      <!-- Colonne droite : compétences + certifications -->
      <div class="formations-right">

        <!-- Skills -->
        <div class="skills-sidebar">
          <h3 class="skills-sidebar__title">${t('formations.skills_title')}</h3>
          ${Object.entries(byCategory).map(([cat, items]) => `
            <div class="skills-sidebar__category">
              <div class="skills-sidebar__category-name">${cat}</div>
              ${chips(items)}
            </div>
          `).join('')}
        </div>

        <!-- Certifications -->
        ${allCerts.length ? `
        <div class="certs-block">
          <h3 class="certs-block__title">${t('formations.certifications_title')}</h3>
          ${allCerts.map(c => `
            <div class="cert-item">
              <span class="cert-item__name">${escapeHtml(c.name)}</span>
              ${c.year ? `<span class="cert-item__year">${escapeHtml(String(c.year))}</span>` : ''}
            </div>`).join('')}
        </div>` : ''}

      </div>
    </div>

  `;
  applyI18n();

  attachSkillChipEvents(panel);
}

/* ── CONTACT — rendu d'un lien en rangée ────────────────────── */
function renderContactLink(link) {
  // Sécurité : n'autoriser que https:// et mailto: en href
  const safeUrl = link.url.startsWith('https://') || link.url.startsWith('mailto:')
    ? link.url : '#';
  const isEmail = safeUrl.startsWith('mailto:');
  const display = escapeHtml(safeUrl.replace('mailto:', ''));

  // Même logique que le drawer : image si URL/chemin, sinon emoji/texte
  const iconHtml = _isIconUrl(link.icon)
    ? `<img src="${escapeHtml(link.icon)}" alt="${escapeHtml(link.platform ?? '')}" width="24" height="24" style="object-fit:contain">`
    : escapeHtml(link.icon ?? '');

  return `
    <a class="contact-link-row" href="${escapeHtml(safeUrl)}"
       ${!isEmail ? 'target="_blank" rel="noopener noreferrer"' : ''}>
      <span class="contact-link-row__icon">${iconHtml}</span>
      <span class="contact-link-row__body">
        <span class="contact-link-row__label">${escapeHtml(link.platform ?? '')}</span>
        <span class="contact-link-row__value">${display}</span>
      </span>
      <span class="contact-link-row__arrow">↗</span>
    </a>`;
}

/* ── CONTACT ───────────────────────────────────────────────── */
async function renderContact() {
  const profile = await get('profile.php');
  const panel   = document.getElementById('tab-contact');

  /* Liens : depuis profile.links ou fallback email/phone */
  const links = profile?.links ?? [];
  const linksHtml = (links.length
    ? links
    : [
        profile?.email ? { url: `mailto:${profile.email}`, icon: '@',  platform: 'Email' }     : null,
        profile?.phone ? { url: `tel:${profile.phone}`,    icon: '☎', platform: 'Téléphone' } : null,
      ].filter(Boolean)
  ).map(renderContactLink).join('');

  panel.innerHTML = `
    <!-- Breadcrumb header -->
    <div class="contact-top-header">
      <span class="contact-breadcrumb">/04 · ${t('contact.breadcrumb')}</span>
      <span class="contact-reply-time">${t('contact.avg_reply')}</span>
    </div>

    <div class="contact-layout">

      <!-- Gauche row 1 : titre + tagline -->
      <div class="contact-title-area">
        <h2 class="contact-display-title">${t('contact.display_title_1')}<br>${t('contact.display_title_2')}</h2>
        <p class="contact-tagline">${t('contact.tagline_available')}${t('contact.tagline_suffix')}</p>
      </div>

      <!-- Gauche row 2 : liens + open to work -->
      <div class="contact-links-area">
        ${linksHtml}
        <div class="open-to-work">
          <div class="open-to-work__status">
            <span class="open-to-work__dot"></span>
            ${t('contact.open_to_work')}
          </div>
          <span class="open-to-work__info">${t('contact.availability_info')}</span>
        </div>
      </div>

      <!-- Droite (span 2 rows) : formulaire encadré -->
      <div class="contact-form-wrapper">
        <div class="contact-form-box">
          <div class="contact-form-header">
            <h3 class="contact-form-title">${t('contact.form.quick_note_title')}</h3>
            <p class="contact-form-subtitle">${t('contact.form_subtitle')}</p>
          </div>
          <form class="contact-form" id="contact-form" onsubmit="sendContact(event)">
            <div class="form-row">
              <div class="form-group">
                <label data-i18n="contact.form.name_label"></label>
                <input type="text"  name="name"  required data-i18n-placeholder="contact.form.name_placeholder">
              </div>
              <div class="form-group">
                <label data-i18n="contact.form.email_label"></label>
                <input type="email" name="email" required data-i18n-placeholder="contact.form.email_placeholder">
              </div>
            </div>
            <div class="form-group">
              <label data-i18n="contact.form.subject_label"></label>
              <input type="text" name="subject" maxlength="255" data-i18n-placeholder="contact.form.subject_placeholder">
            </div>
            <div class="form-group">
              <label data-i18n="contact.form.message_label"></label>
              <textarea name="message" required data-i18n-placeholder="contact.form.message_placeholder"></textarea>
            </div>
            <div class="form-group">
              <altcha-widget challengeurl="${new URL('./api/altcha.php', location.href).href}" auto="onload" hidefooter></altcha-widget>
            </div>
            <div class="contact-form-actions">
              <button type="submit" class="btn btn--primary btn--sm" data-i18n="contact.form.submit"></button>
            </div>
          </form>
        </div>
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
  const formData = new FormData(e.target);
  const data = Object.fromEntries(formData);
  data.subject = (formData.get('subject') ?? '').trim();
  data.altcha  = _altchaPayload;
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

/* ── ABOUT ─────────────────────────────────────────────────── */
async function renderAbout() {
  const [profile, skillsData] = await Promise.all([
    get('profile.php'),
    get(`skills.php?lang=${lang}`),
  ]);
  const panel = document.getElementById('tab-about');

  const bio      = escapeHtml(profile?.bio      ?? '');
  const location = escapeHtml(profile?.location ?? '');
  const status   = escapeHtml(profile?.status   ?? '');

  // Grouper par catégorie, compter les skills, garder le top 5
  const catMap = {};
  for (const s of (skillsData ?? [])) {
    const cat = s.category ?? '';
    if (!cat) continue;
    if (!catMap[cat]) {
      catMap[cat] = { category_description: s.category_description ?? '', category_color: s.category_color ?? null, items: [] };
    }
    catMap[cat].items.push(s);
  }
  const categories = Object.entries(catMap)
    .sort(([, a], [, b]) => b.items.length - a.items.length)
    .slice(0, 5)
    .map(([cat, { category_description, category_color, items }]) => ({
      name: cat, description: category_description, color: category_color, items,
    }));

  // Toggle : ON → photo_url (même que drawer) ; OFF → about_photo_url
  const useDrawerPhoto = profile?.about_use_drawer_photo !== false
                      && profile?.about_use_drawer_photo !== 0;
  const aboutPhoto = useDrawerPhoto ? profile?.photo_url : profile?.about_photo_url;
  const isLarge    = !useDrawerPhoto && !!aboutPhoto;

  const coverHtml = profile?.cover_url
    ? `<div class="about__cover" style="background-image:url('${escapeHtml(profile.cover_url)}')"></div>`
    : '';
  const photoHtml = aboutPhoto
    ? `<img src="${escapeHtml(aboutPhoto)}" alt="${escapeHtml(profile?.name ?? '')}" class="about__photo${isLarge ? ' about__photo--large' : ''}">`
    : '';

  panel.innerHTML = `
    <div class="about">

      ${coverHtml}

      <div class="about__hero">
        ${photoHtml}
        <div class="about__identity">
          <h1 class="about__name">${escapeHtml(profile?.name ?? '')}</h1>
          <p class="about__title">${escapeHtml(profile?.title ?? '')}</p>
          ${location ? `<p class="about__location">📍 ${location}</p>` : ''}
          ${status   ? `<p class="about__status">${status}</p>` : ''}
        </div>
      </div>

      <div class="about__bio">
        ${bio
          ? `<p>${bio}</p>`
          : `<p class="about__bio--empty">${t('about.no_bio')}</p>`}
      </div>

      ${categories.length > 0 ? `
        <div class="about__skills">
          <h2 class="about__section-title">${t('about.skills_label')}</h2>
          <div class="chips-list">${categories.map(c => _chipCategory(c.name, c.description, c.color, c.items)).join('')}</div>
        </div>` : ''}

    </div>
  `;
  applyI18n();

  attachSkillChipEvents(panel);
}

/* ── HELPERS ───────────────────────────────────────────────── */

/** Délègue à AppUtils pour garder la logique centralisée et testable. */
function escapeHtml(str) { return AppUtils.escapeHtml(str); }

/* ── CHIP PRIMITIVES ───────────────────────────────────────── */

/** Rend un chip skill individuel avec toutes les données interactives. */
function _chipSkill(s) {
  if (typeof s === 'string') return `<span class="chip">${escapeHtml(s)}</span>`;
  const raw        = s?.category_color ?? s?.color ?? null;
  const color      = (typeof raw === 'string' && /^#[0-9a-fA-F]{6}$/.test(raw)) ? raw : null;
  const classes    = ['chip'];
  const hasDetail  = s.category || s.category_description || s.skill_description || Array.isArray(s.skills_list);
  if (hasDetail) classes.push('chip--hoverable');
  if (color)     classes.push('chip--colored');
  const style      = color ? ` style="--chip-color:${color}"` : '';
  const dataSkills = Array.isArray(s.skills_list) && s.skills_list.length
    ? ` data-skills="${escapeHtml(s.skills_list.join('|'))}"` : '';
  return `<span class="${classes.join(' ')}"${style}
    data-skill="${escapeHtml(s.name ?? '')}"
    data-category="${escapeHtml(s.category ?? '')}"
    data-description="${escapeHtml(s.category_description ?? '')}"
    data-skill-description="${escapeHtml(s.skill_description ?? '')}"
    data-color="${escapeHtml(raw ?? '')}"${dataSkills}>${escapeHtml(s.name ?? '')}</span>`;
}

/** Rend un chip de catégorie (groupé) avec tooltip + données modal JSON. */
function _chipCategory(cat, description, color, items) {
  const safeColor  = (typeof color === 'string' && /^#[0-9a-fA-F]{6}$/.test(color)) ? color : null;
  const colorStyle = safeColor ? ` style="--chip-color:${safeColor}"` : '';
  const colorClass = safeColor ? ' chip--colored' : '';
  const skillsJson = escapeHtml(JSON.stringify(
    items.map(s => ({
      name:              s.name,
      category:          s.category ?? cat,
      category_color:    safeColor ?? '',
      skill_description: s.skill_description ?? '',
    }))
  ));
  return `<button class="chip chip--category chip--hoverable${colorClass}"
    data-category="${escapeHtml(cat)}"
    data-description="${escapeHtml(description)}"
    data-color="${escapeHtml(color ?? '')}"
    data-skills="${escapeHtml(items.map(s => s.name).join('|'))}"
    data-skills-json="${skillsJson}"
    ${colorStyle}>${escapeHtml(cat)}<span class="chip__count">${items.length}</span></button>`;
}

/* ── CHIP COMPOSANTS ───────────────────────────────────────── */

/** Rend une liste plate de chips individuels (usage générique). */
function chips(items) {
  if (!items?.length) return '';
  return `<div class="chips-list">${items.map(s => _chipSkill(s)).join('')}</div>`;
}

/**
 * Rend un ensemble de skills avec regroupement par catégorie adaptatif :
 * - catégorie avec ≥ SKILLS_GROUP_THRESHOLD skills → puce de catégorie
 * - catégorie avec < SKILLS_GROUP_THRESHOLD skills → chips individuels
 * Utilisé dans les expériences et les formations.
 */
function renderSkillChips(skills) {
  if (!skills?.length) return '';
  const groups = {};
  const order  = [];
  skills.forEach(s => {
    const key = s.category ?? '';
    if (!groups[key]) {
      groups[key] = { description: s.category_description ?? '', color: s.category_color ?? null, items: [] };
      order.push(key);
    }
    groups[key].items.push(s);
  });
  const html = order.map(cat => {
    const { description, color, items } = groups[cat];
    return items.length >= SKILLS_GROUP_THRESHOLD
      ? _chipCategory(cat, description, color, items)
      : items.map(s => _chipSkill(s)).join('');
  }).join('');
  return `<div class="chips-list">${html}</div>`;
}

/* ── SKILL CHIP EVENTS (composant réutilisable) ────────────── */
/**
 * Attache tooltip + modal sur tous les chips hoverable d'un container.
 *  - .chip--hoverable:not(.chip--category) → modal skill
 *  - .chip--category.chip--hoverable       → modal catégorie
 * @param {Element} container
 * @param {Object}  [opts]
 * @param {boolean} [opts.stopProp=false]  stopPropagation au clic
 *                                          (chips imbriqués dans une modale parente)
 */
function attachSkillChipEvents(container, { stopProp = false } = {}) {
  container.querySelectorAll('.chip--hoverable:not(.chip--category)').forEach(chip => {
    chip.addEventListener('mouseenter', showSkillTooltip);
    chip.addEventListener('mouseleave', hideSkillTooltip);
    chip.addEventListener('click', e => {
      if (stopProp) e.stopPropagation();
      hideSkillTooltip();
      openSkillModal('skill', {
        skill:            chip.dataset.skill,
        category:         chip.dataset.category,
        skillDescription: chip.dataset.skillDescription,
      });
    });
  });
  container.querySelectorAll('.chip--category.chip--hoverable').forEach(btn => {
    btn.addEventListener('mouseenter', showSkillTooltip);
    btn.addEventListener('mouseleave', hideSkillTooltip);
    btn.addEventListener('click', e => {
      if (stopProp) e.stopPropagation();
      hideSkillTooltip();
      let skills;
      try { skills = JSON.parse(btn.dataset.skillsJson ?? ''); } catch { skills = null; }
      if (!Array.isArray(skills)) {
        skills = (btn.dataset.skills ?? '').split('|').filter(Boolean)
          .map(n => ({ name: n, category_color: btn.dataset.color ?? '' }));
      }
      openSkillModal('category', {
        category:    btn.dataset.category,
        description: btn.dataset.description,
        skills,
      });
    });
  });
}

/* ── SKILL TOOLTIP (singleton) ─────────────────────────────── */
function _getSkillTooltip() {
  let el = document.getElementById('skill-tooltip');
  if (!el) {
    el = document.createElement('div');
    el.id = 'skill-tooltip';
    el.className = 'skill-tooltip hidden';
    document.body.appendChild(el);
  }
  return el;
}

function showSkillTooltip(e) {
  const target     = e.currentTarget;
  const skillsRaw  = target.dataset.skills || '';        // présent sur chip catégorie
  const skillDesc  = target.dataset.skillDescription || ''; // présent sur chip skill individuel
  const cat        = target.dataset.category || target.dataset.skill || '';
  const catDesc    = target.dataset.description || '';

  const tip = _getSkillTooltip();
  let content = '';

  if (skillsRaw) {
    // Chip catégorie → nom catégorie + liste de skills
    if (cat) content += `<strong>${escapeHtml(cat)}</strong>`;
    const names = skillsRaw.split('|').filter(Boolean);
    content += `<div class="skill-tooltip__skills">${
      names.map(n => `<span>${escapeHtml(n)}</span>`).join('')
    }</div>`;
  } else {
    // Chip skill individuel → catégorie en header + description de la techno
    if (cat) content += `<strong>${escapeHtml(cat)}</strong>`;
    if (skillDesc) content += `<br><span>${escapeHtml(skillDesc)}</span>`;
  }

  if (!content) return;
  tip.innerHTML = content;
  tip.classList.remove('hidden');
  const rect = target.getBoundingClientRect();
  tip.style.left = `${rect.left}px`;
  tip.style.top  = `${rect.bottom + 6}px`;
}

function hideSkillTooltip() {
  _getSkillTooltip().classList.add('hidden');
}

/* ── SKILL MODAL (singleton global, indépendant du panel) ─── */
function _getSkillModal() {
  let overlay = document.getElementById('skill-modal');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id        = 'skill-modal';
    overlay.className = 'modal-overlay hidden';
    overlay.addEventListener('click', closeSkillModal);
    const body    = document.createElement('div');
    body.id        = 'skill-modal-body';
    body.className = 'modal';
    overlay.appendChild(body);
    document.body.appendChild(overlay);
  }
  return overlay;
}

function openSkillModal(type, data) {
  const overlay  = _getSkillModal();
  const body     = overlay.querySelector('#skill-modal-body');
  const closeBtn = `<button class="modal__close-btn" onclick="document.getElementById('skill-modal').classList.add('hidden')">${t('formations.skill_modal_close')}</button>`;
  if (type === 'skill') {
    body.innerHTML = `
      <div class="modal__category">${escapeHtml(data.category ?? '')}</div>
      <h2 class="modal__title">${escapeHtml(data.skill ?? '')}</h2>
      ${data.skillDescription ? `<p class="modal__description">${escapeHtml(data.skillDescription)}</p>` : ''}
      ${closeBtn}`;
  } else {
    // type === 'category'
    body.innerHTML = `
      <div class="modal__category">${t('formations.category_skills')}</div>
      <h2 class="modal__title">${escapeHtml(data.category ?? '')}</h2>
      ${data.description ? `<p class="modal__description">${escapeHtml(data.description)}</p>` : ''}
      <div class="skill-modal__chips">${chips(data.skills ?? [])}</div>
      ${closeBtn}`;
    attachSkillChipEvents(body);
  }
  overlay.classList.remove('hidden');
}

function closeSkillModal(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.add('hidden');
  }
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
  const saved  = localStorage.getItem('theme');
  const theme  = saved
    ? saved
    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.dataset.theme = theme;
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
 * Met à jour le titre du profil dans le drawer selon la langue active.
 * Appelé à l'init et à chaque changement de langue.
 */
function updateDrawerProfile(profile) {
  document.querySelectorAll('.profile-title')
    .forEach(el => { el.textContent = profile.title ?? ''; });
}

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

/** Détecte si une valeur d'icône est une URL/chemin vers une image. */
function _isIconUrl(v) {
  return typeof v === 'string' && (v.startsWith('http') || v.startsWith('/'));
}

function renderSocialLinks(links) {
  const container = document.getElementById('social-links');
  if (!container) return;
  container.innerHTML = '';
  (links ?? []).forEach(link => {
    const a = document.createElement('a');
    a.href      = link.url;
    a.className = 'social-link';
    a.title     = link.platform;
    if (!link.url.startsWith('mailto:')) {
      a.target = '_blank';
      a.rel    = 'noopener noreferrer';
    }

    if (_isIconUrl(link.icon)) {
      /* Icône image (fichier uploadé ou URL externe) */
      const img = document.createElement('img');
      img.src              = link.icon;
      img.alt              = link.platform;
      img.width            = 24;
      img.height           = 24;
      img.style.objectFit  = 'contain';
      a.appendChild(img);
    } else if (link.icon) {
      /* Emoji ou texte court — textContent, jamais innerHTML */
      a.textContent = link.icon;
    }

    container.appendChild(a);
  });
}

/* ── LANG DETECTION ────────────────────────────────────────── */
/**
 * Retourne la langue à utiliser à l'initialisation.
 * Priorité : préférence sauvegardée en localStorage → langue du navigateur → 'fr'.
 * Ne persiste rien : l'utilisateur n'a pas encore exprimé de choix explicite.
 */
function _detectLang() {
  const saved = localStorage.getItem('lang');
  if (saved) return saved;
  // navigator.language peut valoir 'en-GB', 'fr-FR', 'en', 'fr', …
  const browserLang = (navigator.language || '').toLowerCase().slice(0, 2);
  return browserLang === 'en' ? 'en' : 'fr';
}

/* ── INIT ──────────────────────────────────────────────────── */
async function init() {
  initTheme();   /* ← en premier pour éviter le flash */

  /* ① Listeners enregistrés AVANT tout await
   *   → drawer et onglets cliquables dès le premier frame, même pendant le chargement */
  document.querySelectorAll('.lang-toggle__btn')
    .forEach(b => b.addEventListener('click', () => loadLang(b.dataset.lang)));
  document.querySelectorAll('.theme-toggle')
    .forEach(b => b.addEventListener('click', toggleTheme));
  document.querySelectorAll('[data-tab]')
    .forEach(b => b.addEventListener('click', () => switchTab(b.dataset.tab)));

  /* ② Onglet initial actif dans le DOM + skeleton visible AVANT le premier await
   *   → loadLang détectera '[data-tab].active' = 'experiences' et lui appliquera le bon flow
   *   → L'utilisateur voit le skeleton dès le premier paint (skeleton = HTML statique, pas besoin d'i18n) */
  const initialTab   = 'experiences';
  const initialPanel = document.getElementById(`tab-${initialTab}`);
  document.querySelectorAll('[data-tab]')
    .forEach(b => b.classList.toggle('active', b.dataset.tab === initialTab));
  if (initialPanel) {
    initialPanel.classList.add('active');
    initialPanel.innerHTML = getSkeletonHtml(initialTab);
  }

  /* ③ Chargement i18n + pré-render tous les onglets
   *   loadLang voit 'experiences' comme onglet actif → skeleton + loadTab pour lui,
   *   loadTab silencieux pour les autres */
  await loadLang(_detectLang());

  /* ④ Profil (en séquentiel pour afficher le nom/avatar dès qu'il arrive) */
  const profile = await get('profile.php');
  if (profile) {
    document.querySelectorAll('.profile-name')
      .forEach(el => { el.textContent = profile.name ?? ''; });
    updateDrawerProfile(profile);
    document.querySelectorAll('.profile-avatar').forEach(img => {
      if (profile.photo_url) img.src = profile.photo_url;
    });
    renderSocialLinks(profile.links);
  }

  updateCvLink(lang);
  /* switchTab('experiences') supprimé : loadLang s'en charge via la détection de l'onglet actif */
}

document.addEventListener('DOMContentLoaded', init);
