/**
 * AppUtils — Fonctions pures testables
 *
 * Aucun appel API, aucune manipulation DOM.
 * Ces fonctions prennent des données et retournent des données.
 * Accessibles via window.AppUtils (global).
 */
const AppUtils = {

  /* ── Pagination ──────────────────────────────────────────── */

  /**
   * Découpe un tableau en une page.
   * @param {Array}  items
   * @param {number} page     — numéro de page (1-based)
   * @param {number} perPage  — éléments par page
   * @returns {{ items: Array, totalPages: number }}
   */
  paginateItems(items, page, perPage) {
    if (!items.length || perPage <= 0) return { items: [], totalPages: 0 };
    const totalPages = Math.ceil(items.length / perPage);
    const start = (page - 1) * perPage;
    return { items: items.slice(start, start + perPage), totalPages };
  },

  /**
   * Construit le tableau de numéros de page à afficher, avec ellipsis ('…').
   * @param {number} current — page courante (1-based)
   * @param {number} total   — nombre total de pages
   * @returns {Array<number|string>}
   */
  buildPaginationPages(current, total) {
    const pages = [];
    if (total <= 7) {
      for (let i = 1; i <= total; i++) pages.push(i);
      return pages;
    }
    const set = new Set(
      [1, total, current - 1, current, current + 1]
        .filter(x => x >= 1 && x <= total)
    );
    const sorted = [...set].sort((a, b) => a - b);
    let prev = 0;
    for (const n of sorted) {
      if (n - prev > 1) pages.push('…');
      pages.push(n);
      prev = n;
    }
    return pages;
  },

  /* ── Expériences ─────────────────────────────────────────── */

  /**
   * Calcule les métriques clés d'un tableau d'expériences.
   * @param {Array} experiences
   * @returns {{ totalYears: number, topSkills: string[], uniqueTypes: {key:string,label:string}[], hasOpenStatus: boolean }}
   */
  computeHighlights(experiences) {
    const totalYears = Math.round(
      experiences.reduce((acc, e) => {
        const s   = new Date(e.start_date);
        const end = e.end_date ? new Date(e.end_date) : new Date();
        return acc + (end - s) / 31536000000;
      }, 0)
    );

    const topSkills = [...new Set(
      experiences.flatMap(e => e.skills ?? []).map(s => s.name)
    )].slice(0, 3);

    const uniqueTypes = [
      ...new Map(
        experiences
          .filter(e => e.type_key?.trim())
          .map(e => [e.type_key, e.type ?? e.type_key])
      ).entries()
    ].map(([key, label]) => ({ key, label }));

    const hasOpenStatus = experiences.some(e => !e.end_date);

    return { totalYears, topSkills, uniqueTypes, hasOpenStatus };
  },

  /**
   * Filtre les expériences par type.
   * @param {Array}  experiences
   * @param {string} typeKey — 'all' retourne tout; sinon filtre sur type_key
   * @returns {Array}
   */
  filterByType(experiences, typeKey) {
    if (typeKey === 'all') return experiences;
    return experiences.filter(e => e.type_key === typeKey);
  },

  /* ── Formations ──────────────────────────────────────────── */

  /**
   * Groupe un tableau de skills par leur propriété `category`.
   * @param {Array} skills
   * @returns {Object.<string, Array>}
   */
  groupSkillsByCategory(skills) {
    const byCategory = {};
    skills.forEach(s => {
      (byCategory[s.category] ??= []).push(s);
    });
    return byCategory;
  },

  /* ── Utilitaires ─────────────────────────────────────────── */

  /**
   * Encode les caractères HTML spéciaux (protection XSS).
   * @param {string} str
   * @returns {string}
   */
  escapeHtml(str) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(str ?? '').replace(/[&<>"']/g, c => map[c]);
  },

  /**
   * Échappe le HTML (XSS) puis convertit les retours à la ligne en <br>.
   * Accepte aussi les balises <br> explicitement tapées dans l'admin.
   * À utiliser pour les champs texte long (description, bio…).
   * @param {string} str
   * @returns {string}
   */
  renderText(str) {
    return AppUtils.escapeHtml(str)
      .replace(/\n/g, '<br>')
      .replace(/&lt;br\s*\/?&gt;/gi, '<br>');
  },

  /**
   * Formate une période (années uniquement, ex: "2022 — 2024" ou "2024 — Présent").
   * @param {string}      start        — date ISO
   * @param {string|null} end          — date ISO ou null/undefined
   * @param {string}      presentLabel — libellé selon la langue active
   * @returns {string}
   */
  formatPeriod(start, end, presentLabel = 'Present') {
    if (!start) return '';
    const sy = new Date(start).getFullYear();
    if (!end)  return `${sy} — ${presentLabel}`;
    const ey = new Date(end).getFullYear();
    return sy === ey ? String(sy) : `${sy} — ${ey}`;
  },

  /**
   * Valide les données du formulaire de contact.
   * @param {{ name: string, email: string, message: string }} data
   * @returns {{ valid: boolean, errors: string[] }}
   */
  validateContactForm(data) {
    const errors = [];
    if (!data.name || !String(data.name).trim()) {
      errors.push('name');
    }
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!data.email || !emailRe.test(String(data.email).trim())) {
      errors.push('email');
    }
    if (!data.message || !String(data.message).trim()) {
      errors.push('message');
    }
    return { valid: errors.length === 0, errors };
  },

  /* ── Skeleton ────────────────────────────────────────────── */

  /**
   * Retourne le HTML de skeleton (placeholder animé) pour un onglet donné.
   * HTML entièrement statique — aucune donnée utilisateur, aucun risque XSS.
   * @param {string} tab — nom de l'onglet ('experiences'|'creations'|'formations'|'contact')
   * @returns {string}
   */
  getSkeletonHtml(tab) {
    const card = (lines = 3) => `
      <div class="skeleton-card">
        <div class="skeleton-block skeleton-title"></div>
        ${Array(lines).fill('<div class="skeleton-block skeleton-line"></div>').join('')}
        <div style="margin-top:12px">
          ${Array(3).fill('<div class="skeleton-block skeleton-chip"></div>').join('')}
        </div>
      </div>`;

    switch (tab) {
      case 'experiences':
        return Array(3).fill(card(4)).join('');
      case 'creations':
        return `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
          ${Array(6).fill(card(2)).join('')}
        </div>`;
      case 'formations':
        return `<div style="display:flex;gap:24px">
          <div style="flex:2">${Array(2).fill(card(3)).join('')}</div>
          <div style="flex:1">${card(5)}</div>
        </div>`;
      case 'contact':
        return card(4);
      default:
        return card();
    }
  },
};
