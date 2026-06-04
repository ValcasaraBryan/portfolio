<?php
/* ── Open Graph / SEO meta tags dynamiques ────────────────────────────────
 * Détermine la route depuis l'URL et charge les textes depuis les fichiers
 * i18n/{lang}.json pour injecter des balises OG/Twitter spécifiques par page.
 * Langue : ?lang= (whitelist fr/en, défaut fr).
 */

$base_url  = 'https://bryanvalcasara.com';
$og_image  = $base_url . '/assets/img/og-cover.jpg';

// Langue — whitelist stricte
$lang = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'en' : 'fr';
$og_locale     = $lang === 'en' ? 'en_GB' : 'fr_FR';
$og_locale_alt = $lang === 'en' ? 'fr_FR' : 'en_GB';

// Route — whitelist stricte
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . trim($path, '/');
$allowed_routes = ['about', 'experiences', 'projets', 'formations', 'contact'];
$route_key = ltrim($path, '/');
if (!in_array($route_key, $allowed_routes, true)) {
    $route_key = 'default';
}

// Chargement du fichier i18n
$i18n_file = __DIR__ . '/i18n/' . $lang . '.json';
$i18n = [];
if (is_readable($i18n_file)) {
    $decoded = json_decode(file_get_contents($i18n_file), true);
    if (is_array($decoded)) {
        $i18n = $decoded;
    }
}

// Extraction des meta tags pour la route courante
$meta_section = $i18n['meta'] ?? [];
$meta = $meta_section[$route_key] ?? $meta_section['default'] ?? [
    'title'       => 'Bryan VALCASARA',
    'description' => 'Portfolio de Bryan VALCASARA.',
];
$site_name = $meta_section['site_name'] ?? 'Bryan VALCASARA';

// URL canonique (sans ?lang= pour éviter la duplication de contenu)
$canonical = $base_url . ($path === '/' ? '' : $path);

// Échappement HTML strict
$esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$title       = $esc($meta['title']       ?? 'Bryan VALCASARA');
$description = $esc($meta['description'] ?? '');
$og_url      = $esc($canonical);
$og_img      = $esc($og_image);
$og_loc      = $esc($og_locale);
$og_loc_alt  = $esc($og_locale_alt);
$site_name_e = $esc($site_name);
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- SEO -->
  <title><?= $title ?></title>
  <meta name="description" content="<?= $description ?>">
  <link rel="canonical" href="<?= $og_url ?>">

  <!-- Open Graph -->
  <meta property="og:type"              content="website">
  <meta property="og:site_name"         content="<?= $site_name_e ?>">
  <meta property="og:title"             content="<?= $title ?>">
  <meta property="og:description"       content="<?= $description ?>">
  <meta property="og:url"               content="<?= $og_url ?>">
  <meta property="og:image"             content="<?= $og_img ?>">
  <meta property="og:image:width"       content="1200">
  <meta property="og:image:height"      content="630">
  <meta property="og:locale"            content="<?= $og_loc ?>">
  <meta property="og:locale:alternate"  content="<?= $og_loc_alt ?>">

  <!-- Twitter Card -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:title"       content="<?= $title ?>">
  <meta name="twitter:description" content="<?= $description ?>">
  <meta name="twitter:image"       content="<?= $og_img ?>">

  <link rel="icon" id="site-favicon" href="" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload"
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap"
        as="style"
        onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
  </noscript>
  <!-- Anti-flash : doit précéder le CSS pour éviter le scintillement -->
  <script>
    (function(){
      var saved = localStorage.getItem('theme');
      var theme = saved
        ? saved
        : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      document.documentElement.dataset.theme = theme;
    })();
  </script>
  <link rel="stylesheet" href="./assets/css/main.min.css">
</head>
<body>
  <div class="layout">

    <!-- Mobile: profile header -->
    <header class="profile-header">
      <img class="profile-header__avatar profile-avatar"
           alt="Bryan VALCASARA"
           width="50" height="50"
           loading="eager">
      <div class="profile-header__info">
        <div class="profile-header__name profile-name">Bryan VALCASARA</div>
        <div class="profile-header__title profile-title"></div>
      </div>
      <div class="profile-header__actions">
        <div class="profile-header__top-row">
          <div class="lang-toggle">
            <button class="lang-toggle__btn active" data-lang="fr">FR</button>
            <button class="lang-toggle__btn"        data-lang="en">EN</button>
          </div>
          <button class="theme-toggle" id="theme-toggle-mobile" title="Mode sombre" aria-label="Basculer le thème">☾</button>
        </div>
        <a href="#" download data-cv-link id="cv-link-mobile"
           class="cv-btn-circle" aria-label="Télécharger CV"
           aria-disabled="true">↓</a>
      </div>
    </header>

    <!-- Desktop: drawer -->
    <aside class="drawer">

      <!-- 1. Identity: name + title -->
      <div class="drawer__identity">
        <div class="drawer__name profile-name"></div>
        <div class="drawer__subtitle profile-title"></div>
      </div>

      <!-- 2. Portrait -->
      <img class="drawer__avatar profile-avatar"
           alt="Bryan VALCASARA"
           width="125" height="125"
           loading="eager">

      <!-- 3. Lang toggle + location + theme -->
      <div class="drawer__lang-row">
        <div class="lang-toggle">
          <button class="lang-toggle__btn active" data-lang="fr">FR</button>
          <button class="lang-toggle__btn"        data-lang="en">EN</button>
        </div>
        <span class="drawer__location" data-i18n="common.location">Paris · open</span>
        <button class="theme-toggle" id="theme-toggle-desktop" title="Mode sombre" aria-label="Basculer le thème">☾</button>
      </div>

      <!-- 4. Nav -->
      <nav class="drawer__nav">
        <button class="drawer__nav-item" data-tab="about">
          <span data-i18n="nav.about">À propos</span>
          <span class="drawer__nav-num">/00</span>
        </button>
        <button class="drawer__nav-item" data-tab="experiences">
          <span data-i18n="nav.experiences">Expériences</span>
          <span class="drawer__nav-num">/01</span>
        </button>
        <button class="drawer__nav-item" data-tab="creations">
          <span data-i18n="nav.creations">Créations</span>
          <span class="drawer__nav-num">/02</span>
        </button>
        <button class="drawer__nav-item" data-tab="formations">
          <span data-i18n="nav.formations">Formations</span>
          <span class="drawer__nav-num">/03</span>
        </button>
        <button class="drawer__nav-item" data-tab="contact">
          <span data-i18n="nav.contact">Contact</span>
          <span class="drawer__nav-num">/04</span>
        </button>
      </nav>

      <!-- 5. Download CV -->
      <a href="#" download data-cv-link id="cv-link-desktop"
         class="drawer__cv-btn" aria-disabled="true">
        ↓ <span data-i18n="common.download_cv">Télécharger CV</span>&nbsp;<em>.pdf</em>
      </a>

      <!-- 6. Divider -->
      <div class="drawer__divider"></div>

      <!-- 7. Footer: copyright + social links -->
      <div class="drawer__footer">
        <span class="drawer__copy">© 2026</span>
        <div class="social-links" id="social-links">
          <!-- injecté dynamiquement par app.js -->
        </div>
      </div>

    </aside>

    <!-- Main content -->
    <main class="main-content">
      <section id="tab-about"       class="tab-panel"></section>
      <section id="tab-experiences" class="tab-panel"></section>
      <section id="tab-creations"   class="tab-panel"></section>
      <section id="tab-formations"  class="tab-panel"></section>
      <section id="tab-contact"     class="tab-panel"></section>
      <section id="tab-404"         class="tab-panel" aria-label="404">
        <div class="not-found">
          <h2 data-i18n="notFound.title"></h2>
          <p data-i18n="notFound.message"></p>
          <button class="btn" data-tab="about" data-i18n="notFound.cta"></button>
        </div>
      </section>
    </main>

    <!-- Mobile: bottom tab bar -->
    <nav class="bottom-tab-bar">
      <button class="bottom-tab-bar__item" data-tab="about">
        <span class="bottom-tab-bar__icon">👤</span>
        <span data-i18n="nav.about"></span>
      </button>
      <button class="bottom-tab-bar__item" data-tab="experiences">
        <span class="bottom-tab-bar__icon">💼</span>
        <span data-i18n="nav.experiences"></span>
      </button>
      <button class="bottom-tab-bar__item" data-tab="creations">
        <span class="bottom-tab-bar__icon">🛠</span>
        <span data-i18n="nav.creations"></span>
      </button>
      <button class="bottom-tab-bar__item" data-tab="formations">
        <span class="bottom-tab-bar__icon">🎓</span>
        <span data-i18n="nav.formations"></span>
      </button>
      <button class="bottom-tab-bar__item" data-tab="contact">
        <span class="bottom-tab-bar__icon">✉</span>
        <span data-i18n="nav.contact"></span>
      </button>
    </nav>

  </div>

  <script async defer type="module" src="https://cdn.jsdelivr.net/gh/altcha-org/altcha/dist/altcha.min.js"></script>
  <script src="./assets/js/bundle.94d032d5.min.js"></script>
</body>
</html>
