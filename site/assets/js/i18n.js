/**
 * Module I18n — gestion des traductions
 *
 * Exposé en tant que global `I18n` (IIFE) pour être utilisé
 * par app.js et chargeable indépendamment dans les tests.
 *
 * API publique :
 *   I18n.t(key)                          → résolution de clé
 *   I18n.applyI18n(root?)                → mise à jour du DOM
 *   I18n.loadLang(lang, basePath?)       → chargement async + localStorage
 *   I18n.setTranslations(obj, lang?)     → injection directe (tests)
 *   I18n.getLang()                       → langue active
 */
const I18n = (() => {
  let _translations = {};
  let _lang = 'fr';

  /* ── t(key) ──────────────────────────────────────────────────
   * Résout une clé pointée (ex : 'nav.experiences').
   * Retourne la clé brute si la traduction est absente.
   */
  function t(key) {
    return key.split('.').reduce((o, k) => o?.[k], _translations) ?? key;
  }

  /* ── applyI18n(root?) ────────────────────────────────────────
   * Met à jour tous les éléments data-i18n et
   * data-i18n-placeholder dans le sous-arbre `root`.
   */
  function applyI18n(root = document) {
    root.querySelectorAll('[data-i18n]')
      .forEach(el => { el.textContent = t(el.dataset.i18n); });
    root.querySelectorAll('[data-i18n-placeholder]')
      .forEach(el => { el.placeholder = t(el.dataset.i18nPlaceholder); });
  }

  /* ── loadLang(lang, basePath?) ───────────────────────────────
   * Charge le fichier JSON correspondant, met à jour
   * _translations / _lang / localStorage puis applique l'i18n.
   * Retourne les traductions chargées (utile pour les tests).
   */
  async function loadLang(newLang, basePath = './i18n') {
    const res = await fetch(`${basePath}/${newLang}.json`);
    if (!res.ok) {
      throw new Error(`I18n : impossible de charger ${newLang}.json (HTTP ${res.status})`);
    }
    const data = await res.json();
    _translations = data;
    _lang = newLang;
    localStorage.setItem('lang', newLang);
    applyI18n();
    return data;
  }

  /* ── setTranslations(obj, lang?) ─────────────────────────────
   * Injecte directement un objet de traductions.
   * Utilisé dans les tests pour éviter un fetch réseau.
   */
  function setTranslations(translations, lang) {
    _translations = translations;
    if (lang) _lang = lang;
  }

  /* ── getLang() ───────────────────────────────────────────────
   * Retourne la langue actuellement chargée.
   */
  function getLang() {
    return _lang;
  }

  return { t, applyI18n, loadLang, setTranslations, getLang };
})();
