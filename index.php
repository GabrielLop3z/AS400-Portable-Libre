<?php
if (PHP_VERSION_ID < 80000 && file_exists(__DIR__ . '/vendor74/autoload.php')) { require_once __DIR__ . '/vendor74/autoload.php'; } else { require_once __DIR__ . '/vendor/autoload.php'; }
session_start();

// --- MANEJO DE CIERRE DE SESIÓN (Prioridad Alta) ---
$requestLogout = (isset($_POST['action']) && $_POST['action'] === 'logout') || (isset($_GET['action']) && $_GET['action'] === 'logout');
if ($requestLogout) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: index.php?logout_success=' . time());
    exit;
}

$isLoggedIn = isset($_SESSION['as400_session']);
$assetVer = '1.8.21';
$appVersion = '1.8.21';
$appVersionFile = __DIR__ . '/version.json';
if (file_exists($appVersionFile)) {
    $appVersionData = json_decode(file_get_contents($appVersionFile), true);
    $appVersion = $appVersionData['version'] ?? $appVersion;
}

// --- TEMAS Y PLANTILLAS PDF (configuracion centralizada en /config) ---
$themesData = [];
$themesDefault = __DIR__ . '/config/themes.default.json';
$themesFile = __DIR__ . '/config/themes.json';
if (!file_exists($themesFile)) {
    // Primera ejecucion / descarga fresca: inicializar con los temas de fabrica.
    if (file_exists($themesDefault)) {
        if (!is_dir(__DIR__ . '/config')) mkdir(__DIR__ . '/config', 0777, true);
        @copy($themesDefault, $themesFile);
    }
}
if (file_exists($themesFile)) {
    $themesData = json_decode(file_get_contents($themesFile), true) ?: [];
}
// Garantiza temas y colores visibles siempre: si config/themes.json falta,
// esta vacio o corrupto (p. ej. sin permiso de escritura en /config), usa
// directamente la copia de fabrica.
if (empty($themesData) && file_exists($themesDefault)) {
    $themesData = json_decode(file_get_contents($themesDefault), true) ?: [];
}
// Kits de pantalla de inicio (estilos de login intercambiables).
$loginKits = [];
$loginKitsFile = __DIR__ . '/config/login_kits.json';
if (file_exists($loginKitsFile)) {
    $loginKits = json_decode(file_get_contents($loginKitsFile), true) ?: [];
}
if (!is_array($loginKits) || !isset($loginKits['kits']) || !is_array($loginKits['kits']) || empty($loginKits['kits'])) {
    $loginKits = ['default' => 'terminal', 'kits' => ['terminal' => ['name' => 'Terminal 5250', 'description' => 'Terminal CRT estilo 5250 (clásico)']]];
}
$pdfTemplates = [];
$pdfTemplatesFile = __DIR__ . '/config/pdf_templates.json';
if (file_exists($pdfTemplatesFile)) {
    $pdfTemplates = json_decode(file_get_contents($pdfTemplatesFile), true) ?: [];
}

function themeMenuButton($key, $t) {
    $accent = htmlspecialchars((string)($t['accent'] ?? '#ffffff'), ENT_QUOTES, 'UTF-8');
    $name = strtoupper(htmlspecialchars((string)($t['name'] ?? $key), ENT_QUOTES, 'UTF-8'));
    return '<button onclick="applyAppTheme(\'' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '\')" class="w-full text-left px-5 py-3 text-[15px] font-bold text-gray-400 hover:bg-white/5 hover:text-[' . $accent . '] flex items-center gap-3 transition-all"><span class="w-2.5 h-2.5 rounded-full shadow-[0_0_8px_' . $accent . ']" style="background:' . $accent . '"></span> ' . $name . '</button>';
}

function loginKitButton($key, $k) {
    $name = strtoupper(htmlspecialchars((string)($k['name'] ?? $key), ENT_QUOTES, 'UTF-8'));
    $desc = htmlspecialchars((string)($k['description'] ?? ''), ENT_QUOTES, 'UTF-8');
    return '<button data-kit="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" onclick="applyLoginKit(\'' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '\')" class="w-full text-left px-5 py-3 text-[15px] font-bold text-gray-400 hover:bg-white/5 hover:text-[var(--accent)] flex items-start gap-3 transition-all"><span class="kit-swatch" data-swatch="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"></span><span class="flex-1"><span class="block">' . $name . '</span><span class="block text-xs font-normal text-gray-500 mt-0.5">' . $desc . '</span></span></button>';
}

$pdfTemplateEntries = [];
foreach ($pdfTemplates as $ptKey => $pt) {
    $pdfTemplateEntries[$ptKey] = $pt['name'] ?? ucfirst((string)$ptKey);
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['password'] ?? '';
    
    if (!empty($user) && !empty($pass)) {
        $_SESSION['as400_session'] = [
            'ip' => $_POST['ip'],
            'user_id' => $user,
            'password' => $pass,
            'logged_at' => date('Y-m-d H:i:s')
        ];
        header('Location: index.php');
        exit;
    } else {
        // Podríamos redirigir con un error si fuera necesario
        header('Location: index.php?error=empty');
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="es" data-login-kit="<?= htmlspecialchars($loginKits['default'] ?? 'premium', ENT_QUOTES) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spool | Explorador</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700;800&display=swap" rel="stylesheet">
    <!-- PWA deshabilitado temporalmente para resolver conflictos de red -->
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(registrations => {
                for (let registration of registrations) {
                    registration.unregister().then(() => console.log('PWA: Service Worker desactivado.'));
                }
            });
        }
    </script>
    <!-- <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#00f3ff"> -->

    <script src="assets/tailwindcss.js?v=<?= $assetVer ?>"></script>
    <script src="assets/sweetalert2.all.min.js?v=<?= $assetVer ?>"></script>
    <script src="assets/chart.js?v=<?= $assetVer ?>"></script>
    <script src="assets/jszip.min.js?v=<?= $assetVer ?>"></script>
    <link href="assets/fonts.css?v=<?= $assetVer ?>" rel="stylesheet">
    <?php
        // Genera CSS de temas desde config/themes.json (fuente unica de la verdad)
        $defaultThemeKey = 'grafito';
        if (!isset($themesData[$defaultThemeKey])) $defaultThemeKey = is_array($themesData) ? (array_key_first($themesData) ?? '') : '';
        // Variables de terminal CRT por tema (login + intro): texto fosforo,
        // brillo, fondo. En temas claros se oscurece el acento para contraste.
        // Oscurece un color hex mezclandolo hacia negro.
        function shadeHex($hex, $mixBlack = 0.55) {
            $c = ltrim(trim((string)$hex), '#');
            if (strlen($c) === 3) $c = $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
            if (!preg_match('/^[0-9a-fA-F]{6}$/', $c)) return $hex;
            $r = (int)round(hexdec(substr($c,0,2)) * (1 - $mixBlack));
            $g = (int)round(hexdec(substr($c,2,2)) * (1 - $mixBlack));
            $b = (int)round(hexdec(substr($c,4,2)) * (1 - $mixBlack));
            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }
        function termVars($t) {
            $isLight = !empty($t['isLight']);
            $accent = $t['accent'] ?? '#a3aab3';
            $accentRgb = $t['accentRgb'] ?? '163, 170, 179';
            if ($isLight) {
                return [
                    'term-text' => shadeHex($accent, 0.5),
                    'term-text-dim' => 'rgba(' . $accentRgb . ', 0.5)',
                    'term-glow' => 'rgba(' . $accentRgb . ', 0.30)',
                    'term-crt-bg' => '#fdfbfd'
                ];
            }
            return [
                'term-text' => $accent,
                'term-text-dim' => 'rgba(' . $accentRgb . ', 0.5)',
                'term-glow' => 'rgba(' . $accentRgb . ', 0.35)',
                'term-crt-bg' => '#050f0a'
            ];
        }
        // Normaliza un color hex a "r, g, b"; si ya es "r, g, b" lo deja igual.
        function themeRgb($hex, $currentRgb = '') {
            $hex = trim((string)$hex);
            if (preg_match('/^\s*[\d]+\s*,\s*[\d]+\s*,\s*[\d]+\s*$/', $hex)) return $hex;
            if ($hex !== '' && $hex[0] === '#') {
                $c = ltrim($hex, '#');
                if (strlen($c) === 3) $c = $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
                if (preg_match('/^[0-9a-fA-F]{6}$/', $c)) {
                    return hexdec(substr($c,0,2)) . ', ' . hexdec(substr($c,2,2)) . ', ' . hexdec(substr($c,4,2));
                }
            }
            return $currentRgb === '' ? $hex : $currentRgb;
        }
        // Construye las variables CSS de un tema (reutilizable para :root y [data-theme]).
        function themeVars($t) {
            $tv = termVars($t);
            $font = $t['fontFamily'] ?? 'Arial';
            return ' --bg-main:' . ($t['bgMain'] ?? '#0b0c0e') . '; --bg-panel:' . ($t['bgPanel'] ?? '#141519') . '; --bg-panel-rgb:' . themeRgb($t['bgPanel'] ?? '', ($t['bgPanelRgb'] ?? '')) . '; --bg-darker:' . ($t['bgDarker'] ?? '#07080a') . ';'
                . ' --border-color:' . ($t['border'] ?? '#232529') . '; --text-main:' . ($t['textMain'] ?? '#f2f3f5') . '; --text-muted:' . ($t['textMuted'] ?? '#9aa0a8') . ';'
                . ' --accent:' . ($t['accent'] ?? '#a3aab3') . '; --accent-rgb:' . themeRgb($t['accent'] ?? '', ($t['accentRgb'] ?? '')) . ';'
                . ' --font-family-ui:' . $font . ';'
                . ' --term-text:' . $tv['term-text'] . '; --term-text-dim:' . $tv['term-text-dim'] . '; --term-glow:' . $tv['term-glow'] . '; --term-crt-bg:' . $tv['term-crt-bg'] . ';';
        }
        $themeCssBlocks = [];
        if ($defaultThemeKey !== '' && isset($themesData[$defaultThemeKey])) {
            $themeCssBlocks[] = ':root { ' . themeVars($themesData[$defaultThemeKey]) . ' }';
        }
        foreach ($themesData as $key => $t) {
            if ($key === $defaultThemeKey) continue;
            $themeCssBlocks[] = '[data-theme="' . htmlspecialchars((string)$key, ENT_QUOTES) . '"] { ' . themeVars($t) . ' }';
        }
    ?>
    <style id="theme-css"><?= implode("\n", $themeCssBlocks) ?></style>
    <link href="assets/theme.css?v=<?= $assetVer ?>" rel="stylesheet">
    <link href="assets/login-kits.css?v=<?= $assetVer ?>" rel="stylesheet">
    <link href="assets/polish.css?v=<?= $assetVer ?>" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        accent: 'rgb(var(--accent-rgb) / <alpha-value>)',
                    },
                    fontSize: {
                        'xxs': '0.75rem',
                        'premium': '0.85rem',
                        'label': '0.8rem',
                    },
                    boxShadow: {
                        'premium': '0 8px 32px 0 rgba(0, 0, 0, 0.4)',
                        'accent': '0 4px 12px rgba(var(--accent-rgb), 0.25)',
                    }
                }
            }
        }
    </script>
    <style>


        /* Custom scrollbar for tables */
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.1);
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.12);
            border-radius: 10px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--accent);
        }
        
        /* Modern Editor Animations */
        @keyframes subtle-pulse {
            0% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.02); opacity: 1; }
            100% { transform: scale(1); opacity: 0.8; }
        }
        .pulse-accent { animation: subtle-pulse 2s infinite ease-in-out; }
        
        .shadow-accent-glow {
            box-shadow: 0 0 20px rgba(var(--accent-rgb), 0.2);
        }
        
        .editor-btn-transition {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        
        tr.active-spool td {
            background-color: rgba(var(--accent-rgb), 0.15) !important;
            color: var(--accent) !important;
            box-shadow: inset 4px 0 0 var(--accent);
        }

        table {
            border-spacing: 0;
            border-collapse: separate;
        }

        /* Modern Scrollbar for all panels */
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scroll::-webkit-scrollbar-track { background: rgba(0,0,0,0.05); }
        .custom-scroll::-webkit-scrollbar-thumb { background: #2d333b; border-radius: 10px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: var(--accent); }
        
        /* Unified Animation System */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes logoPulse {
            0%, 100% { transform: scale(1); filter: brightness(1); }
            50% { transform: scale(1.05); filter: brightness(1.2) drop-shadow(0 0 15px rgba(var(--accent-rgb), 0.3)); }
        }
        @keyframes shimmer {
            0% { transform: translateX(-150%) rotate(45deg); }
            100% { transform: translateX(150%) rotate(45deg); }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .animate-fade-in-up { animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .animate-logo { animation: logoPulse 4s infinite ease-in-out; }
        
        .premium-hover { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .premium-hover:hover { transform: translateY(-2px); filter: brightness(1.1); }
        
        .glass-panel {
            background: rgba(var(--bg-panel-rgb), 0.7);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        /* ============================================================
           TERMINAL AS/400 — LOGIN + INTRO (CRT / 5250)
           ============================================================ */
        .crt-overlay {
            position: absolute; inset: 0; pointer-events: none; z-index: 2;
            background:
                repeating-linear-gradient(0deg, rgba(255,255,255,0.03) 0px, rgba(255,255,255,0.03) 1px, transparent 1px, transparent 3px),
                radial-gradient(ellipse at center, transparent 55%, rgba(0,0,0,0.38) 100%);
            animation: crt-flicker 8s infinite steps(1);
        }
        .theme-light .crt-overlay {
            background:
                repeating-linear-gradient(0deg, rgba(120,20,60,0.045) 0px, rgba(120,20,60,0.045) 1px, transparent 1px, transparent 3px),
                radial-gradient(ellipse at center, transparent 62%, rgba(120,20,60,0.12) 100%);
        }
        @keyframes crt-flicker {
            0%, 100% { opacity: 1; } 92% { opacity: 1; }
            93% { opacity: 0.92; } 94% { opacity: 1; }
            97% { opacity: 0.95; }
        }
        .term-blink { animation: term-blink 1s steps(2, start) infinite; }
        @keyframes term-blink { 0% { opacity: 1; } 50% { opacity: 0; } }

        .term-window {
            position: relative; z-index: 10; width: min(94vw, 1080px); min-height: 70vh;
            display: flex; flex-direction: column;
            background: var(--term-crt-bg);
            border: 1px solid var(--term-text-dim);
            border-radius: 14px;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.6), 0 0 80px var(--term-glow), inset 0 0 40px rgba(0,0,0,0.25);
            font-family: 'JetBrains Mono', monospace;
            color: var(--term-text);
            overflow: hidden;
            animation: term-in 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .theme-light .term-window {
            box-shadow: 0 0 0 1px var(--border-color), 0 18px 60px rgba(120,20,60,0.16), inset 0 0 30px rgba(120,20,60,0.03);
        }
        @keyframes term-in {
            from { opacity: 0; transform: translateY(16px) scale(0.985); }
            to { opacity: 1; transform: none; }
        }
        .term-titlebar {
            display: flex; align-items: center; gap: 16px; padding: 18px 26px;
            background: rgba(0,0,0,0.35); border-bottom: 1px solid var(--term-text-dim);
            color: var(--term-text); font-size: clamp(0.95rem, 1.8vw, 1.2rem); font-weight: 700;
            letter-spacing: 0.25em; text-transform: uppercase;
        }
        .term-dots { display: flex; gap: 8px; }
        .term-dots i { width: 13px; height: 13px; border-radius: 50%; background: var(--term-text-dim); box-shadow: 0 0 8px var(--term-glow); }
        .term-led { margin-left: auto; font-size: clamp(0.85rem, 1.5vw, 1rem); opacity: 0.9; letter-spacing: 0.2em; }
        .term-body { padding: clamp(24px, 4vh, 40px) clamp(26px, 5vw, 60px) clamp(18px, 3vh, 30px); display: flex; flex-direction: column; flex: 1; }
        .term-heading { font-size: clamp(2.2rem, 6vw, 4rem); font-weight: 800; letter-spacing: 0.2em; line-height: 1.25; }
        .term-heading .accent-text { color: var(--accent); }
        .term-sub { font-size: clamp(1rem, 2.2vw, 1.4rem); letter-spacing: 0.25em; opacity: 0.9; margin-bottom: 20px; }
        .term-divider { display: flex; align-items: center; gap: 14px; margin: 18px 0 28px; opacity: 0.7; }
        .term-divider::before, .term-divider::after { content: ''; height: 1px; flex: 1; background: var(--term-text-dim); }
        .term-divider span { font-size: clamp(0.95rem, 1.8vw, 1.15rem); letter-spacing: 0.3em; }
        .term-row { display: flex; align-items: baseline; gap: 18px; margin-bottom: clamp(18px, 3.5vh, 32px); }
        .term-row label { font-size: clamp(1.15rem, 2.6vw, 1.7rem); font-weight: 700; letter-spacing: 0.12em; white-space: pre; opacity: 1; min-width: clamp(170px, 24vw, 260px); }
        .term-input {
            flex: 1; min-width: 0; background: transparent; border: none;
            border-bottom: 2px solid var(--term-text-dim);
            color: var(--term-text); font-family: 'JetBrains Mono', monospace;
            font-size: clamp(1.4rem, 3vw, 2.2rem); font-weight: 700; letter-spacing: 0.12em;
            padding: 10px 6px 12px; caret-color: var(--term-text); outline: none;
            text-transform: uppercase;
        }
        .term-input:focus { border-bottom-color: var(--term-text); box-shadow: 0 10px 24px -12px var(--term-glow); }
        .term-input::placeholder { color: var(--term-text-dim); letter-spacing: 0.08em; }
        .term-eye {
            background: transparent; border: 1px solid var(--term-text-dim); color: var(--term-text);
            font-family: 'JetBrains Mono', monospace; font-size: clamp(1rem, 2vw, 1.25rem); font-weight: 700; letter-spacing: 0.12em;
            padding: 12px 18px; border-radius: 8px; cursor: pointer; transition: all 0.2s;
        }
        .term-eye:hover { border-color: var(--term-text); box-shadow: 0 0 14px var(--term-glow); }
        .term-log { margin-top: 6px; min-height: clamp(70px, 12vh, 120px); font-size: clamp(1.05rem, 2.2vw, 1.45rem); line-height: 1.9; letter-spacing: 0.04em; opacity: 1; overflow: hidden; }
        .term-log p { margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .term-log .log-ok { color: var(--term-text); }
        .term-log .log-err { color: #ef4444; font-weight: 700; text-shadow: 0 0 10px rgba(239,68,68,0.5); }
        .term-log .log-mut { color: var(--term-text-dim); }
        .log-cursor { color: var(--term-text); }
        .term-actions { display: flex; gap: 14px; margin-top: 8px; }
        .term-btn {
            background: transparent; border: 1px solid var(--term-text); color: var(--term-text);
            font-family: 'JetBrains Mono', monospace; font-size: clamp(1.1rem, 2.4vw, 1.6rem); font-weight: 800; letter-spacing: 0.18em;
            padding: clamp(14px, 2.4vh, 22px) 20px; border-radius: 8px; cursor: pointer; text-transform: uppercase;
            transition: all 0.2s; flex: 1; white-space: nowrap;
        }
        .term-btn:hover, .term-btn:focus-visible { background: var(--term-text); color: var(--term-crt-bg); box-shadow: 0 0 22px var(--term-glow); }
        .term-btn:disabled { opacity: 0.5; cursor: wait; }
        .term-status {
            display: flex; gap: 26px; align-items: center; margin-top: auto; padding-top: 18px;
            border-top: 1px solid var(--term-text-dim);
            font-size: clamp(0.95rem, 1.8vw, 1.15rem); letter-spacing: 0.2em; opacity: 0.9;
        }
        .term-status span:last-child { margin-left: auto; }

        /* Intro: ventana de arranque */
        .boot-window {
            position: relative; z-index: 2; width: min(92vw, 680px); padding: 30px 34px;
            background: var(--term-crt-bg); border: 1px solid var(--term-text-dim); border-radius: 12px;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.6), 0 0 70px var(--term-glow), inset 0 0 40px rgba(0,0,0,0.3);
            font-family: 'JetBrains Mono', monospace; color: var(--term-text);
            animation: term-in 0.45s ease-out;
        }
        .boot-title { font-size: clamp(1rem, 2vw, 1.3rem); font-weight: 800; letter-spacing: 0.3em; opacity: 0.85; margin-bottom: 18px; }
        .boot-lines { min-height: 150px; font-size: clamp(1.05rem, 2.2vw, 1.4rem); line-height: 2; letter-spacing: 0.04em; text-align: left; }
        .boot-lines .bk-ok::before { content: '[ OK ] '; color: var(--term-text-dim); }
        .boot-bar-track { height: 12px; margin-top: 20px; border: 1px solid var(--term-text-dim); border-radius: 6px; overflow: hidden; }
        .boot-bar-fill { height: 100%; width: 0%; background: var(--term-text); box-shadow: 0 0 14px var(--term-glow); transition: width 0.25s ease; }
        .boot-status { margin-top: 16px; font-size: clamp(1rem, 2vw, 1.25rem); letter-spacing: 0.2em; opacity: 0.9; }

        /* Sidebar Transition Optimization */
        #main-sidebar {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-collapsed {
            width: 0 !important; opacity: 0; pointer-events: none; overflow: hidden; margin-left: -288px;
        }

        /* SweetAlert2 Premium Styling Overrides */
        .swal2-popup {
            background: var(--bg-panel) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
            border-radius: 2rem !important;
            box-shadow: 0 40px 100px rgba(0,0,0,0.6) !important;
        }
        .swal2-title { font-family: 'Outfit', sans-serif !important; font-weight: 900 !important; color: var(--text-main) !important; }
        /* Mainframe Warp Mode Upgrades */
        .warp-active {
            filter: saturate(1.5) contrast(1.2);
            background: #000 !important;
        }
        .warp-active .glass-panel {
            background: rgba(var(--accent-rgb), 0.05) !important;
            border-color: rgba(var(--accent-rgb), 0.5) !important;
            box-shadow: 0 0 100px rgba(var(--accent-rgb), 0.2), inset 0 0 40px rgba(var(--accent-rgb), 0.1) !important;
            backdrop-filter: blur(40px) !important;
            transform: scale(1.05) perspective(1000px) rotateX(2deg) !important;
            transition: all 2s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        .holo-ring {
            position: absolute; border: 2px solid rgba(var(--accent-rgb), 0.2); border-radius: 50%;
            pointer-events: none; opacity: 0; transition: opacity 2s;
        }
        .warp-active .holo-ring { opacity: 1; }
        
        @keyframes rotate-holo {
            from { transform: translate(-50%, -50%) rotate(0deg); }
            to { transform: translate(-50%, -50%) rotate(360deg); }
        }
        .holo-ring-1 { width: 600px; height: 600px; border-style: dashed; animation: rotate-holo 20s linear infinite; }
        .holo-ring-2 { width: 500px; height: 500px; border-left-color: transparent; border-right-color: transparent; animation: rotate-holo 15s linear infinite reverse; }

        #warpspeed-canvas {
            position: absolute; inset: 0; z-index: 0; pointer-events: none; opacity: 1; transition: filter 2s;
        }
        .warp-active #warpspeed-canvas { filter: hue-rotate(90deg) contrast(1.5) saturate(2); }
    </style>
    <script>
        const themesApp = <?= json_encode($themesData, JSON_PRETTY_PRINT) ?>;
        const loginKitsApp = <?= json_encode($loginKits, JSON_PRETTY_PRINT) ?>;

        function applyAppTheme(themeName) {
            const t = themesApp[themeName];
            if(!t) return;
            localStorage.setItem('app_theme', themeName);
            document.documentElement.setAttribute('data-theme', themeName);
            document.documentElement.classList.toggle('theme-light', !!t.isLight);
            const labelEl = document.getElementById('current-theme-label');
            if(labelEl) labelEl.innerText = t.name || (themeName.charAt(0).toUpperCase() + themeName.slice(1));
            // Notifica para refrescar colores dinamicos (graficos, sellos, etc.)
            document.dispatchEvent(new CustomEvent('app:themechange', { detail: themeName }));
        }

        function applyLoginKit(kitId) {
            const kits = (loginKitsApp && loginKitsApp.kits) ? loginKitsApp.kits : null;
            if (!kits || !kits[kitId]) kitId = (loginKitsApp && loginKitsApp.default) || 'terminal';
            try { localStorage.setItem('app_login_kit', kitId); } catch (e) {}
            document.documentElement.setAttribute('data-login-kit', kitId);
            document.querySelectorAll('#kit-menu-items [data-kit]').forEach(b => {
                b.classList.toggle('kit-active', b.getAttribute('data-kit') === kitId);
            });
            if (kitId === 'matrix') startMatrixRain(); else stopMatrixRain();
            document.dispatchEvent(new CustomEvent('app:kitchange', { detail: kitId }));
        }

        function toggleKitMenu(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('kit-menu-items');
            if (!menu) return;
            menu.classList.toggle('hidden');
        }

        let _matrixRaf = null;
        let _matrixDrops = null;
        function startMatrixRain() {
            const cv = document.getElementById('matrix-canvas');
            if (!cv) return;
            const parent = cv.parentElement;
            cv.width = parent ? parent.clientWidth : window.innerWidth;
            cv.height = parent ? parent.clientHeight : window.innerHeight;
            _matrixDrops = null;
            if (_matrixRaf) { cancelAnimationFrame(_matrixRaf); _matrixRaf = null; }
            _matrixRaf = requestAnimationFrame(matrixTick);
        }
        function stopMatrixRain() {
            if (_matrixRaf) { cancelAnimationFrame(_matrixRaf); _matrixRaf = null; }
        }
        function matrixTick() {
            const cv = document.getElementById('matrix-canvas');
            if (!cv || cv.style.display === 'none') { stopMatrixRain(); return; }
            const ctx = cv.getContext('2d');
            const rgb = accentRGBRaw();
            ctx.fillStyle = 'rgba(0, 0, 0, 0.08)';
            ctx.fillRect(0, 0, cv.width, cv.height);
            const fs = 16;
            const cols = Math.floor(cv.width / fs);
            if (!_matrixDrops || _matrixDrops.length !== cols) {
                _matrixDrops = new Array(cols).fill(0).map(() => Math.floor(Math.random() * -Math.floor(cv.height / fs)));
            }
            ctx.font = fs + 'px "JetBrains Mono", monospace';
            ctx.fillStyle = 'rgba(' + rgb + ', 0.9)';
            const chars = 'アイウエオカキクケコサシスセソ0123456789ABCDEF$%&#@<>*+=;:';
            for (let i = 0; i < cols; i++) {
                const ch = chars[Math.floor(Math.random() * chars.length)];
                const y = _matrixDrops[i] * fs;
                ctx.fillText(ch, i * fs, y);
                if (y > cv.height && Math.random() > 0.975) _matrixDrops[i] = 0;
                _matrixDrops[i]++;
            }
            _matrixRaf = requestAnimationFrame(matrixTick);
        }
        window.addEventListener('resize', () => {
            if (document.documentElement.getAttribute('data-login-kit') === 'matrix') startMatrixRain();
        });

        function getDefaultLineColor() {
            return themeColor('--accent');
        }

        function themeColor(cssVar = '--accent') {
            const v = getComputedStyle(document.documentElement).getPropertyValue(cssVar).trim();
            return v || '#a3aab3';
        }

        function accentRGBRaw() {
            const v = getComputedStyle(document.documentElement).getPropertyValue('--accent-rgb').trim();
            return v || '163, 170, 179';
        }

        function accentRGB(alpha) {
            return `rgba(${accentRGBRaw()}, ${alpha})`;
        }

        function hexToRgba(hex, alpha) {
            hex = String(hex || '').replace('#', '');
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            const n = parseInt(hex, 16);
            if (isNaN(n) || hex.length !== 6) return `rgba(${accentRGBRaw()}, ${alpha})`;
            return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
        }

        document.addEventListener('app:themechange', () => {
            const modal = document.getElementById('dashboard-modal');
            if (window.lastSpoolList && modal && !modal.classList.contains('hidden')) renderDashboard();
        });

        function writeLoginLog(msg, cls) {
            const log = document.getElementById('login-log');
            if (!log) return;
            const cur = log.querySelector('.log-cursor');
            if (cur) cur.remove();
            const p = document.createElement('p');
            p.className = cls || 'log-ok';
            p.textContent = msg;
            log.appendChild(p);
            const cur2 = document.createElement('span');
            cur2.className = 'log-cursor term-blink';
            cur2.textContent = '▮';
            log.appendChild(cur2);
            log.scrollTop = log.scrollHeight;
        }

        function setLoginBusy(busy) {
            const btn = document.getElementById('login-submit');
            if (btn) {
                btn.disabled = busy;
                btn.innerHTML = busy
                    ? '<span>Procesando</span><span class="term-blink">▮</span>'
                    : '<span>Entrar ↵</span>';
            }
            const inputs = document.querySelectorAll('#login-form input');
            inputs.forEach(i => i.disabled = busy);
        }

        function togglePassword() {
            const p = document.getElementById('login-password');
            const eye = document.getElementById('login-eye');
            if (!p) return;
            const show = p.type === 'password';
            p.type = show ? 'text' : 'password';
            if (eye) eye.textContent = show ? '[Ocultar]' : '[Ver]';
            p.focus();
        }

        function tickTermClock() {
            const el = document.getElementById('term-clock');
            if (!el) return;
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            el.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
            setTimeout(tickTermClock, 1000);
        }
        document.addEventListener('DOMContentLoaded', tickTermClock);

        async function handleLogin(e) {
            e.preventDefault();
            const form = e.target;
            const user = form.user.value.trim().toUpperCase();
            const ip = form.ip.value;
            if (!user || !form.password.value) {
                writeLoginLog('> CAMPO REQUERIDO — INGRESE USUARIO Y CLAVE', 'log-err');
                return;
            }
            setLoginBusy(true);
            writeLoginLog('> CONECTANDO A ' + ip + ' ...');
            writeLoginLog('> AUTENTICANDO USUARIO ' + user);
            const data = {
                action: 'verify_login',
                ip: ip,
                user: user,
                password: form.password.value
            };
            try {
                const res = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                if (result.success) {
                    writeLoginLog('> CREDENCIALES VERIFICADAS — BIENVENIDO', 'log-ok');
                    writeLoginLog('> REDIRIGIENDO AL EXPLORADOR ...', 'log-mut');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    writeLoginLog('> ERROR: ' + (result.message || 'CREDENCIALES INVÁLIDAS'), 'log-err');
                    setLoginBusy(false);
                }
            } catch (err) {
                writeLoginLog('> ERROR DE RED — NO SE PUDO CONTACTAR EL PROXY', 'log-err');
                setLoginBusy(false);
            }
        }

        // ===== Perfiles de conexión guardados (localStorage) =====
        const PROFILES_KEY = 'saved_profiles';

        function getSavedProfiles() {
            try { return JSON.parse(localStorage.getItem(PROFILES_KEY) || '[]'); }
            catch (e) { return []; }
        }

        function persistProfiles(profiles) {
            try { localStorage.setItem(PROFILES_KEY, JSON.stringify(profiles)); } catch (e) {}
        }

        function loadProfileDropdown() {
            const sel = document.getElementById('login-profile');
            if (!sel) return;
            const profiles = getSavedProfiles();
            sel.innerHTML = '<option value="">— PERFIL GUARDADO —</option>' +
                profiles.map(p => `<option value="${p.name.replace(/"/g, '&quot;')}">${p.name}</option>`).join('');
        }

        function findProfile(name) {
            return getSavedProfiles().find(p => p.name === name);
        }

        function applyProfile(name) {
            const sel = document.getElementById('login-profile');
            if (!name) return;
            const p = findProfile(name);
            if (!p) return;
            document.querySelector('#login-form input[name="ip"]').value = p.ip || '';
            document.getElementById('login-user').value = p.user || '';
            document.getElementById('login-password').value = p.password || '';
            writeLoginLog('> PERFIL CARGADO: ' + name, 'log-ok');
        }

        async function saveProfilePrompt() {
            const ip = document.querySelector('#login-form input[name="ip"]').value.trim();
            const user = document.getElementById('login-user').value.trim();
            const pass = document.getElementById('login-password').value;
            if (!user || !pass) {
                writeLoginLog('> COMPLETE USUARIO Y CLAVE PARA GUARDAR PERFIL', 'log-err');
                return;
            }
            const { value: name } = await Swal.fire({
                title: 'Guardar Perfil de Conexión',
                html: `<div class="text-sm text-gray-400 mb-2">Credenciales: <span class="text-white font-bold">${user}</span> @ ${ip}</div>`,
                input: 'text',
                inputPlaceholder: 'Nombre del perfil (ej. Producción)',
                showCancelButton: true,
                confirmButtonText: 'GUARDAR',
                cancelButtonText: 'CANCELAR',
                background: 'var(--bg-panel)', color: 'var(--text-main)'
            });
            if (!name || !name.trim()) return;
            const profiles = getSavedProfiles().filter(p => p.name !== name.trim());
            profiles.push({ name: name.trim(), ip: ip, user: user, password: pass });
            persistProfiles(profiles);
            loadProfileDropdown();
            const sel = document.getElementById('login-profile');
            if (sel) sel.value = name.trim();
            Swal.fire({ toast: true, position: 'bottom-end', icon: 'success', title: `Perfil "${name.trim()}" guardado`, showConfirmButton: false, timer: 2000, background: 'var(--bg-panel)', color: 'var(--text-main)' });
        }

        function deleteProfile(name) {
            if (!name) return;
            persistProfiles(getSavedProfiles().filter(p => p.name !== name));
            loadProfileDropdown();
        }

        async function deleteProfilePrompt() {
            const sel = document.getElementById('login-profile');
            const name = sel ? sel.value : '';
            if (!name) {
                writeLoginLog('> SELECCIONE UN PERFIL PARA ELIMINAR', 'log-err');
                return;
            }
            const confirm = await Swal.fire({
                title: '¿Eliminar perfil?',
                text: `Se eliminará "${name}" de los perfiles guardados de este navegador.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ELIMINAR',
                confirmButtonColor: '#ef4444',
                cancelButtonText: 'CANCELAR',
                background: 'var(--bg-panel)', color: 'var(--text-main)'
            });
            if (!confirm.isConfirmed) return;
            deleteProfile(name);
            if (sel) sel.value = '';
            writeLoginLog('> PERFIL ELIMINADO: ' + name, 'log-ok');
        }

        window.addEventListener('DOMContentLoaded', loadProfileDropdown);

        function toggleSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');
            if (!sidebar) return;
            const isMobile = window.matchMedia('(max-width: 1023px)').matches;
            if (isMobile) {
                const opening = sidebar.classList.contains('sidebar-collapsed') || sidebar.style.display === 'none';
                sidebar.classList.remove('sidebar-collapsed');
                sidebar.classList.toggle('open', opening);
                sidebar.style.display = opening ? 'flex' : 'none';
                if (backdrop) backdrop.classList.toggle('hidden', !opening);
                return;
            }
            sidebar.classList.toggle('sidebar-collapsed');
            const collapsed = sidebar.classList.contains('sidebar-collapsed');
            sidebar.style.display = collapsed ? 'none' : 'flex';
        }

        function closeSidebarMobile() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');
            if (sidebar) {
                sidebar.classList.remove('open');
                sidebar.style.display = 'none';
            }
            if (backdrop) backdrop.classList.add('hidden');
        }

        function toggleExportMenu(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('export-menu');
            if (menu) menu.classList.toggle('hidden');
        }

        function closeExportMenu() {
            const menu = document.getElementById('export-menu');
            if (menu) menu.classList.add('hidden');
        }

        // Cerrar menús al hacer clic fuera y el drawer al elegir una acción en móvil
        document.addEventListener('click', (e) => {
            const menu = document.getElementById('export-menu');
            if (menu && !menu.classList.contains('hidden') && !e.target.closest('#export-menu, #export-menu-btn')) {
                closeExportMenu();
            }
            const sidebar = document.getElementById('main-sidebar');
            if (!sidebar || !sidebar.classList.contains('open')) return;
            const t = e.target;
            if (t.closest && t.closest('button[onclick*="toggleSidebar"]')) return;
            if (t.closest && (t.closest('#theme-menu-btn') || t.closest('#theme-menu-items'))) return;
            if (t.closest && (t.closest('#main-sidebar button') || t.closest('#main-sidebar a'))) closeSidebarMobile();
        });

        // Apply theme immediately to avoid flash
        const storedTheme = localStorage.getItem('app_theme');
        const savedTheme = (storedTheme && themesApp[storedTheme]) ? storedTheme : 'grafito';
        applyAppTheme(savedTheme);
        window.addEventListener('DOMContentLoaded', () => applyAppTheme(savedTheme));

        // Apply login kit immediately to avoid flash (solo afecta al login)
        let storedKit = null;
        try { storedKit = localStorage.getItem('app_login_kit'); } catch (e) {}
        const savedKit = (loginKitsApp && loginKitsApp.kits && storedKit && loginKitsApp.kits[storedKit]) ? storedKit : ((loginKitsApp && loginKitsApp.default) || 'terminal');
        document.documentElement.setAttribute('data-login-kit', savedKit);
        window.addEventListener('DOMContentLoaded', () => applyLoginKit(savedKit));
    </script>
    <style>
        /* UI Feel Enhancements */
        .sidebar-collapsed { width: 0 !important; opacity: 0; pointer-events: none; overflow: hidden; margin-left: -256px; }
        .login-glow { text-shadow: none; }
        .quote-anim { opacity: 0.9; }
        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; }
        
        /* Ultra Light Sync Loader */
        .quantum-orb { position: relative; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; }
        .orb-core { width: 15px; height: 15px; background: var(--accent); border-radius: 50%; }
        .quantum-ring { position: absolute; inset: -5px; border: 1px solid rgba(var(--accent-rgb), 0.4); border-radius: 50%; opacity: 0.4; }
        
        /* Row Tracking Glow */
        #preview-content tr:hover td { background: linear-gradient(90deg, transparent, rgba(var(--accent-rgb), 0.08), transparent) !important; color: var(--accent) !important; transition: all 0.2s; }
        
        /* Typing cursor */
        .cursor-type::after { content: '|'; animation: blink 1s infinite; margin-left: 2px; color: var(--accent); }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }

        /* AS400 Exact Font */
            font-family: 'Courier New', Courier, monospace !important;
            font-weight: normal !important;
            letter-spacing: -0.05em !important;
        }

        /* Premium Signature Style */
        .signature-premium {
            font-family: 'JetBrains Mono', monospace;
            letter-spacing: 0.3em;
            background: linear-gradient(90deg, var(--accent), #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        /* NEW CYBER ANIMATIONS */
        @keyframes loader-spin {
            0% { transform: rotate(0deg); border-radius: 50%; }
            50% { transform: rotate(180deg); border-radius: 30%; }
            100% { transform: rotate(360deg); border-radius: 50%; }
        }
        @keyframes pulse-glr {
            0%, 100% { opacity: 0.3; transform: scale(0.9); filter: blur(2px); }
            50% { opacity: 1; transform: scale(1.1); filter: blur(0px); }
        }
        @keyframes ring-orbit {
            from { transform: rotate(0deg) translateX(10px) rotate(0deg); }
            to { transform: rotate(360deg) translateX(10px) rotate(-360deg); }
        }
        .glr-mini-loader {
            position: relative;
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .glr-logo-core {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 800;
            font-size: 18px;
            color: var(--accent);
            text-shadow: 0 0 15px var(--accent);
            animation: pulse-glr 2s infinite ease-in-out;
            z-index: 2;
        }
        .loader-ring {
            position: absolute;
            border: 2px solid transparent;
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: loader-spin 2s linear infinite;
        }
        .ring-1 { inset: 0; opacity: 1; }
        .ring-2 { inset: 10px; border-top-color: #3b82f6; animation-duration: 1.5s; opacity: 0.7; }
        .ring-3 { inset: 20px; border-top-color: #a855f7; animation-duration: 1s; opacity: 0.4; }
        
        .premium-backdrop { background: rgba(0,0,0,0.6) !important; backdrop-filter: blur(12px) saturate(180%) !important; }
        .animate-fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        /* Print formatting optimized */
        @media print {
            /* Hide UI elements */
            .no-print, aside, header, #spool-queue-panel, .swal2-container, #focus-btn, #view-tab-raw, #view-tab-grid, #grid-toolbar { 
                display: none !important; 
            }
            
            body, html { 
                background: white !important; 
                color: black !important;
                height: auto !important;
                overflow: visible !important;
                width: 100% !important;
            }

            main {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                position: static !important;
                background: white !important;
                transform: none !important; /* Reset zoom */
            }

            #preview-container {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                position: static !important;
                background: white !important;
            }

            #preview-content {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                padding: 0 !important;
                margin: 0 !important;
                background: white !important;
                color: black !important;
                position: static !important;
                top: auto !important;
                left: auto !important;
            }

            #preview-content table {
                border-collapse: collapse;
                width: 100%;
                table-layout: auto;
            }

            #preview-content td, #preview-content th {
                border: 0 !important;
                color: black !important;
                background: white !important;
                font-family: 'Lucida Console', 'Consolas', Monaco, monospace !important;
                font-size: 9.5pt !important;
                line-height: 1.0 !important;
                padding: 0px 0.5px !important;
                white-space: pre !important;
            }

            @page {
                margin: 0.15cm !important;
            }
        }

        /* Modern Report View Styling */
        .as400-page {
            background-color: var(--bg-panel) !important;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 1.5rem;
            margin-bottom: 2.5rem;
            padding: 3rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            position: relative;
            width: fit-content;
            min-width: 800px;
            max-width: 100%;
            transition: transform 0.3s ease;
        }

        .as400-page:hover {
            border-color: rgba(var(--accent-rgb), 0.2);
            transform: translateY(-2px);
        }

        .as400-pre {
            font-family: 'Courier New', Courier, monospace !important;
            font-weight: 500 !important;
            letter-spacing: -0.02em !important;
        }

        .page-badge {
            background: linear-gradient(135deg, var(--accent), #3b82f6);
            color: black;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 800;
            font-size: 10px;
            padding: 6px 16px;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(var(--accent-rgb), 0.3);
            letter-spacing: 0.1em;
        }
    </style>
</head>
<body id="app-body" class="bg-[var(--bg-main)] text-gray-300 font-sans h-screen w-full overflow-hidden">
<?php if (!$isLoggedIn): ?>
    <!-- LOGIN TERMINAL AS/400 -->
    <div id="login-container" class="flex items-center justify-center h-full w-full relative overflow-hidden transition-colors duration-700">
        <!-- Scanlines y viñeta CRT -->
        <div class="crt-overlay"></div>
        <!-- Glow de fondo sutil -->
        <div id="login-glow" class="absolute top-[-20%] left-1/2 -translate-x-1/2 w-[70%] h-[55%] rounded-full blur-[140px] pointer-events-none" style="background: var(--term-glow)"></div>
        <!-- Decoraciones por kit: blobs (vidrio) y lluvia de codigo (matrix) -->
        <div id="login-blobs" class="kit-decoration" aria-hidden="true"><span class="blob blob-a"></span><span class="blob blob-b"></span><span class="blob blob-c"></span></div>
        <canvas id="matrix-canvas" class="kit-decoration" aria-hidden="true"></canvas>
        <!-- Panel de marca (kit corporativo) -->
        <div id="login-brand" class="kit-decoration">
            <div class="brand-inner">
                <img src="assets/spool_icon.png" alt="Spool Explorer" class="brand-logo">
                <h2 class="brand-title">SPOOL<span>.</span>EXPLORER</h2>
                <p class="brand-tagline">Explorador de colas de impresión AS/400</p>
                <ul class="brand-features">
                    <li><span class="bf-dot"></span>Colas de impresión en tiempo real</li>
                    <li><span class="bf-dot"></span>Vista previa y exportación de spools</li>
                    <li><span class="bf-dot"></span>Seguridad y perfiles integrados</li>
                </ul>
            </div>
        </div>

        <!-- Switcher de Login: Estilo + Tema -->
        <div class="absolute top-8 right-8 z-50 flex items-start gap-3">
            <div class="relative group/kits">
                <button id="login-kit-btn" onclick="toggleKitMenu(event)" class="p-4 bg-[var(--bg-panel)] border border-[var(--border-color)] rounded-xl text-[var(--text-muted)] hover:text-[var(--accent)] hover:border-[var(--accent)] transition-all premium-hover flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                    <span class="text-sm font-bold tracking-widest uppercase">Estilo</span>
                </button>
                <div id="kit-menu-items" class="hidden absolute top-full right-0 mt-3 w-72 bg-[var(--bg-panel)] border border-[var(--border-color)] rounded-xl shadow-[0_16px_48px_rgba(0,0,0,0.35)] overflow-hidden z-[100] animate-fade-in-up">
                    <div class="p-2 space-y-1">
                        <?php foreach ($loginKits['kits'] as $kitKey => $kitInfo) { echo loginKitButton($kitKey, $kitInfo); } ?>
                    </div>
                </div>
            </div>
            <div class="relative group/theme">
                <button id="login-theme-btn" onclick="toggleThemeMenu(event)" class="p-4 bg-[var(--bg-panel)] border border-[var(--border-color)] rounded-xl text-[var(--text-muted)] hover:text-[var(--accent)] hover:border-[var(--accent)] transition-all premium-hover flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.172-1.172a4 4 0 115.656 5.656L10 17.657"></path></svg>
                    <span class="text-sm font-bold tracking-widest uppercase">Tema</span>
                </button>
                <div id="theme-menu-items" class="hidden absolute top-full right-0 mt-3 w-72 bg-[var(--bg-panel)] border border-[var(--border-color)] rounded-xl shadow-[0_16px_48px_rgba(0,0,0,0.35)] overflow-hidden z-[100] animate-fade-in-up">
                    <div class="p-2 space-y-1">
                        <?php foreach ($themesData as $themeKey => $themeInfo) { echo themeMenuButton($themeKey, $themeInfo); } ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ventana de Terminal 5250 -->
        <div class="term-window">
            <div class="term-titlebar">
                <span class="term-dots"><i></i><i></i><i></i></span>
                <span>Spool 5250 — Sesión Interactiva</span>
                <span class="term-led">SYS</span>
            </div>
            <div class="term-body">
                <div class="term-heading">
                    <span class="accent-text">SPOOL</span>.EXPLORER
                    <span id="core-icon-trigger" onclick="triggerBioScan()" class="term-blink cursor-pointer select-none" style="font-size:10px; vertical-align:middle;" title="...">▮</span>
                </div>
                <div class="term-sub">AS/400 Portable · V12</div>
                <div class="term-divider"><span>Identificación de Usuario</span></div>

                <form id="login-form" onsubmit="handleLogin(event)" class="space-y-5">
                    <input type="hidden" name="ip" value="<?= htmlspecialchars($_SESSION['as400_session']['ip'] ?? '10.100.5.60') ?>">

                    <div class="term-row">
                        <label>USUARIO :</label>
                        <input type="text" name="user" id="login-user" class="term-input" autocomplete="off" autocapitalize="characters" spellcheck="false" required placeholder="IDENTIFICADOR">
                    </div>
                    <div class="term-row">
                        <label>CLAVE&nbsp;&nbsp;&nbsp;:</label>
                        <input type="password" name="password" id="login-password" class="term-input" autocomplete="off" required placeholder="••••••••">
                        <button type="button" id="login-eye" class="term-eye" onclick="togglePassword()">[Ver]</button>
                    </div>

                    <div class="term-row">
                        <label>PERFIL&nbsp;&nbsp;:</label>
                        <select id="login-profile" class="term-input" onchange="applyProfile(this.value)">
                            <option value="">— PERFIL GUARDADO —</option>
                        </select>
                        <button type="button" class="term-eye" onclick="saveProfilePrompt()" title="Guardar credenciales actuales como perfil">[+]</button>
                        <button type="button" class="term-eye" onclick="deleteProfilePrompt()" title="Eliminar el perfil seleccionado">[−]</button>
                    </div>

                    <div id="login-log" class="term-log">
                        <p class="log-mut">&gt; SISTEMA LISTO — INGRESE SUS CREDENCIALES</p>
                        <span class="log-cursor term-blink">▮</span>
                    </div>

                    <div class="term-actions">
                        <button type="submit" id="login-submit" class="term-btn">Entrar ↵</button>
                        <button type="button" class="term-btn" onclick="openTechnicalManual()">Manual</button>
                        <button type="button" class="term-btn" onclick="openGatekeeper()">Enlace</button>
                    </div>
                </form>

                <div class="term-status">
                    <span>Insert</span>
                    <span>Hex</span>
                    <span>CapS</span>
                    <span id="term-clock"></span>
                </div>
            </div>
        </div>

        <!-- Pie -->
        <div id="login-footer" class="absolute bottom-6 left-0 w-full text-center z-10">
            <p class="text-base text-[var(--text-muted)] tracking-[0.2em] uppercase opacity-70">Spool <span class="font-bold text-[var(--accent)]">v<?= htmlspecialchars($appVersion) ?></span> &middot; GLR</p>
            <button onclick="openFeedback()" class="mt-3 inline-flex items-center gap-2 text-[var(--text-muted)] hover:text-[var(--accent)] transition-colors text-sm font-bold uppercase tracking-widest" title="Envía tus ideas, sugerencias o reportes para futuras mejoras">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                Comentarios &amp; Ideas
            </button>
        </div>
    </div>
<?php else: ?>
<div id="app-wrapper" class="flex flex-col lg:flex-row h-full w-full transition-all duration-300 origin-top-left">
    <!-- Intro: Secuencia de Arranque 5250 -->
    <div id="app-loader" class="fixed inset-0 z-[1000] flex flex-col items-center justify-center transition-all duration-700 ease-in-out" style="background: var(--term-crt-bg);">
        <div class="crt-overlay"></div>
        <div class="boot-window">
            <div class="boot-title">Spool · Secuencia de Arranque</div>
            <div id="boot-lines" class="boot-lines"></div>
            <div class="boot-bar-track">
                <div id="boot-bar" class="boot-bar-fill"></div>
            </div>
            <p class="boot-status">
                <span id="boot-status-text">Sistema Iniciándose</span><span class="term-blink"> ▮</span>
            </p>
        </div>
    </div>
    <script>
        window.addEventListener('load', () => {
            // Attempt to maximize window size if supported
            try { if (window.outerWidth < screen.availWidth || window.outerHeight < screen.availHeight) { window.moveTo(0,0); window.resizeTo(screen.availWidth, screen.availHeight); } } catch(e){}
            runBootSequence();
        });
        function dismissLoader(loader, fadeMs) {
            loader.style.opacity = '0';
            loader.style.pointerEvents = 'none';
            setTimeout(() => loader.remove(), fadeMs);
        }
        function runBootSequence() {
            const loader = document.getElementById('app-loader');
            if (!loader) return;
            let bootSeen = false;
            try { bootSeen = sessionStorage.getItem('spool_boot_seen') === '1'; } catch (e) {}
            if (bootSeen) { dismissLoader(loader, 140); return; }
            const themeNames = Object.values(themesApp).map(t => t.name || '').filter(Boolean).join(' / ');
            const kitNames = Object.values(loginKitsApp.kits || {}).map(k => k.name || '').filter(Boolean).join(' / ');
            const lines = [
                'Núcleo Spool Portable V12',
                'Memoria OK — 512 colas detectadas',
                'Temas: ' + themeNames,
                'Estilos de login: ' + kitNames,
                'Módulo Explorador ... OK',
                'Enlace AS/400 ... Configurado',
                'Entorno de trabajo listo'
            ];
            const box = document.getElementById('boot-lines');
            const bar = document.getElementById('boot-bar');
            const statusEl = document.getElementById('boot-status-text');
            if (!box || !bar || !statusEl) { dismissLoader(loader, 140); return; }
            let i = 0;
            const step = () => {
                if (i < lines.length) {
                    const p = document.createElement('p');
                    p.className = 'bk-ok';
                    p.style.opacity = '0';
                    p.style.transition = 'opacity 0.2s';
                    p.textContent = lines[i];
                    box.appendChild(p);
                    requestAnimationFrame(() => { p.style.opacity = '1'; });
                    bar.style.width = Math.round(((i + 1) / lines.length) * 100) + '%';
                    i++;
                    const delay = (i === 3) ? 340 : 150;
                    setTimeout(step, delay);
                } else {
                    statusEl.textContent = 'Spool listo — Bienvenido';
                    bar.style.width = '100%';
                    try { sessionStorage.setItem('spool_boot_seen', '1'); } catch (e) {}
                    setTimeout(() => dismissLoader(loader, 500), 420);
                }
            };
            setTimeout(step, 180);
        }
    </script>

    <!-- Backdrop para el drawer de la barra lateral en móvil -->
    <div id="sidebar-backdrop" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[55] lg:hidden" onclick="closeSidebarMobile()" aria-hidden="true"></div>

    <!-- Left Sidebar -->
    <aside id="main-sidebar" class="no-print w-full lg:w-72 bg-[var(--bg-panel)] flex flex-col border-b lg:border-b-0 lg:border-r border-white/5 h-auto max-h-[40vh] lg:max-h-full lg:h-full flex-shrink-0 z-[20] transition-all duration-300 ease-in-out overflow-hidden relative glass-panel sidebar-collapsed" style="display: none;">
        <!-- Decoration -->
        <div class="absolute top-0 left-0 w-full h-32 bg-gradient-to-b from-accent/5 to-transparent pointer-events-none"></div>

        <!-- Logo -->
        <div class="p-8 pb-10 flex items-center justify-between relative">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-black/40 border border-white/10 rounded-2xl shadow-accent premium-hover">
                    <img src="../favicon.svg" class="w-7 h-7" alt="Icon" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%2300f3ff\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><rect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\'></rect><path d=\'M7 7h10 M7 12h10 M7 17h10\'></path></svg>'">
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-white leading-none tracking-tighter">SPOOL</h1>
                    <p class="text-[15px] font-bold tracking-[0.3em] text-accent mt-2 uppercase">Explorador</p>
                </div>
            </div>
            <button onclick="toggleSidebar()" class="lg:hidden p-3 bg-white/5 rounded-xl text-gray-400 hover:text-white transition-all">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
        </div>
        
        <!-- Session Profile Area -->
        <div class="px-8 flex-1 mt-2 space-y-8 overflow-y-auto custom-scroll">
            <div>
                <h2 class="text-[15px] font-bold text-gray-500 uppercase tracking-[0.2em] mb-4">Usuario en Sesión</h2>
                
                <div class="bg-black/30 border border-white/5 rounded-2xl p-5 relative overflow-hidden group premium-hover">
                    <!-- Decorative background -->
                    <div class="absolute top-0 right-0 w-24 h-24 bg-accent/5 rounded-bl-full pointer-events-none group-hover:bg-accent/10 transition-colors"></div>
                    
                    <div class="flex items-center gap-4 relative z-10">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-black to-gray-800 border border-accent/20 flex items-center justify-center shadow-accent relative">
                            <svg class="w-7 h-7 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-green-500 border-2 border-[var(--bg-panel)] animate-pulse"></span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <p id="profile-user-name" class="text-base font-bold text-white uppercase tracking-wide truncate">
                                    <?= htmlspecialchars($_SESSION['as400_session']['user_id']) ?>
                                </p>
                                <button onclick="toggleFavorite('<?= htmlspecialchars($_SESSION['as400_session']['user_id']) ?>')" class="text-gray-500 hover:text-yellow-500 transition-all p-1" title="Favorito">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.175 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.382-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
                                </button>
                            </div>
                            <p id="profile-user-id" class="text-[15px] text-gray-500 font-mono tracking-tight truncate mt-0.5"><?= htmlspecialchars($_SESSION['as400_session']['user_id']) ?> @ <?= htmlspecialchars($_SESSION['as400_session']['ip']) ?></p>
                        </div>
                    </div>
                </div>
            </div>



            <div>
                <h2 class="text-[15px] font-bold text-gray-500 uppercase tracking-[0.2em] mb-4">Interfaz Gráfica</h2>
                <div class="relative">
                    <button onclick="toggleThemeMenu(event)" class="w-full h-12 flex items-center justify-between text-[15px] bg-black/40 border border-white/10 rounded-xl px-4 text-gray-300 hover:text-accent hover:border-accent/40 transition-all shadow-inner premium-hover" id="theme-menu-btn">
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-accent shadow-accent"></span>
                            <span id="current-theme-label" class="font-bold uppercase tracking-widest text-[15px]"><?= htmlspecialchars($themesData[$defaultThemeKey]['name'] ?? 'Grafito') ?></span>
                        </div>
                        <svg class="w-4 h-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div id="theme-menu-items" class="absolute bottom-[calc(100%+12px)] left-0 w-full bg-[var(--bg-panel)] border border-white/10 rounded-2xl shadow-[0_12px_48px_rgba(0,0,0,0.8)] hidden overflow-hidden z-[50] backdrop-blur-xl animate-fade-in-up">
                        <div class="p-3 px-4 text-[15px] font-bold text-gray-500 uppercase tracking-[0.3em] bg-black/40 border-b border-white/5">Esquemas de Color</div>
                        <div class="max-h-64 overflow-y-auto custom-scroll">
                            <?php foreach ($themesData as $themeKey => $themeInfo) { echo themeMenuButton($themeKey, $themeInfo); } ?>
                        </div>
                    </div>
                </div>
                <button onclick="openThemeEditor()" class="mt-3 w-full h-11 flex items-center justify-center gap-2 text-[15px] bg-accent/10 text-accent border border-accent/25 rounded-xl hover:bg-accent hover:text-black transition-all premium-hover uppercase tracking-widest" title="Personalizar colores y tipografía">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.172-1.172a4 4 0 115.656 5.656L10 17.657"/></svg>
                    Personalizar
                </button>
            </div>


            <div>
                <h2 class="text-[15px] font-bold text-gray-500 uppercase tracking-[0.2em] mb-4">Herramientas de Sistema</h2>
                <button onclick="openDashboard()" class="mb-3 w-full h-11 flex items-center justify-center gap-2 text-[15px] bg-blue-500/10 text-blue-400 border border-blue-500/30 rounded-xl hover:bg-blue-500 hover:text-white transition-all premium-hover uppercase tracking-widest" title="Dashboard funcional con estadísticas de spools">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Dashboard
                </button>
                <div class="grid grid-cols-2 gap-3">
                    <button onclick="openControlCenter()" class="h-12 flex items-center justify-center rounded-xl bg-accent/20 text-accent border border-accent/40 hover:bg-accent hover:text-black transition-all premium-hover shadow-accent" title="Centro de Control y Favoritos">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </button>
                    <button onclick="openGatekeeper()" class="h-12 flex items-center justify-center rounded-xl bg-white/5 text-gray-400 border border-white/10 hover:text-accent transition-all premium-hover" title="Seguridad Gatekeeper">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </button>
                </div>
                <button onclick="openUpdater()" class="mt-3 w-full h-11 flex items-center justify-center gap-2 text-[15px] bg-green-500/10 text-green-400 border border-green-500/30 rounded-xl hover:bg-green-500 hover:text-white transition-all premium-hover uppercase tracking-widest" title="Buscar e instalar mejoras desde GitHub">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Actualizar
                </button>
                <button onclick="openFeedback()" class="mt-2 w-full h-11 flex items-center justify-center gap-2 text-[15px] bg-indigo-500/10 text-indigo-400 border border-indigo-500/30 rounded-xl hover:bg-indigo-500 hover:text-white transition-all premium-hover uppercase tracking-widest" title="Envía tus ideas, comentarios o reportes para futuras mejoras">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                    Comentarios
                </button>
            </div>
            
            <div class="pt-4 space-y-3">
                <button onclick="openTechnicalManual()" class="w-full flex items-center justify-center gap-3 bg-accent/10 text-accent border border-accent/20 hover:bg-accent hover:text-black py-4 rounded-2xl text-[15px] font-bold transition-all premium-hover uppercase tracking-widest no-underline">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                    MANUAL TÉCNICO
                </button>
                <a href="index.php?action=logout" class="w-full flex items-center justify-center gap-3 bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500 hover:text-white py-4 rounded-2xl text-[15px] font-bold transition-all shadow-lg premium-hover uppercase tracking-widest no-underline">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    CERRAR SESIÓN
                </a>
            </div>
        </div>
        
        <div class="p-8 text-center border-t border-white/5 flex flex-col items-center bg-black/20">
            <div onclick="triggerEgg()" class="signature-glow w-12 h-12 border border-white/10 bg-black/40 rounded-2xl flex items-center justify-center text-accent font-bold text-sm mb-4 shadow-accent cursor-pointer premium-hover active:scale-95 transition-all tracking-normal">&lt;/&gt;</div>
            <p class="signature-premium text-[15px] tracking-[0.5em] uppercase">&lt;GLR\&gt;</p>
            <p class="text-[15px] text-gray-600 font-mono mt-2 font-bold tracking-tight uppercase">Spool v<?= htmlspecialchars($appVersion) ?></p>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-[var(--bg-main)]">
        <header class="no-print h-20 flex items-center justify-between px-8 flex-shrink-0 bg-black/30 border-b border-white/10">
            <div class="flex items-center gap-6">
                <button onclick="toggleSidebar()" class="p-3 bg-white/5 border border-white/10 rounded-xl text-gray-400 hover:text-accent hover:border-accent/40 transition-all premium-hover" title="Colapsar Barra Lateral">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <div class="relative flex items-center w-72 bg-black/40 border border-white/10 rounded-xl px-4 py-2.5 focus-within:border-accent/50 focus-within:ring-4 focus-within:ring-accent/5 transition-all transition-colors group">
                    <svg class="w-4 h-4 text-gray-500 group-focus-within:text-accent mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <?php 
                        $currentUserId = $_SESSION['as400_session']['user_id'] ?? '';
                        $isPrivilegedUser = (strpos(strtoupper($currentUserId), 'TID') === 0 || strpos(strtoupper($currentUserId), 'TIO') === 0);
                    ?>
                    <?php if ($isPrivilegedUser): ?>
                    <input type="text" id="target-user-search" placeholder="Consultar Usuario..." 
                           class="bg-transparent border-none outline-none text-[15px] text-white w-full placeholder-gray-600 font-medium uppercase" 
                           value="<?= htmlspecialchars($currentUserId) ?>"
                           onkeydown="if(event.key==='Enter') { hideFavDropdown(); refreshSpoolList(); }"
                           oninput="updateStarIcon()"
                           onclick="showFavDropdown(event)"
                           onfocus="showFavDropdown(event)">
                    <button id="fav-star-btn" onclick="toggleFavorite()" class="ml-2 text-gray-500 hover:text-yellow-500 transition-all p-1" title="Favorito">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.175 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.382-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
                    </button>
                    <!-- Dropdown Favs -->
                    <div id="fav-dropdown" class="hidden absolute top-[calc(100%+8px)] left-0 w-full bg-[var(--bg-panel)] border border-white/10 rounded-2xl shadow-[0_12px_48px_rgba(0,0,0,0.8)] z-[60] overflow-hidden backdrop-blur-xl animate-fade-in-up">
                        <div class="px-4 py-3 text-[13px] font-bold text-gray-500 uppercase tracking-widest bg-black/40 border-b border-white/5 flex items-center justify-between">
                            <span>Favoritos</span>
                            <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.175 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.382-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
                        </div>
                        <div id="fav-dropdown-list" class="max-h-60 overflow-y-auto custom-scroll"></div>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-2 w-full px-1" title="Solo puedes consultar tus propios spools">
                        <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        <span class="text-[13px] font-bold uppercase tracking-widest text-gray-400">Spools de:</span>
                        <span class="text-[15px] text-accent font-bold uppercase"><?= htmlspecialchars($currentUserId) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="flex items-center gap-3 lg:gap-4">
                <div class="flex items-center bg-black/40 border border-white/10 rounded-xl p-1 shadow-inner">
                    <button onclick="changeZoom(-0.1)" class="text-gray-400 hover:text-accent px-3 py-1 cursor-pointer font-bold text-xl leading-none transition-colors" title="Reducir Zoom">-</button>
                    <span id="zoom-label" class="text-[15px] text-white/80 font-bold font-mono px-3 select-none tracking-normal">100%</span>
                    <button onclick="changeZoom(0.1)" class="text-gray-400 hover:text-accent px-3 py-1 cursor-pointer font-bold text-xl leading-none transition-colors" title="Aumentar Zoom">+</button>
                </div>
                
                <button onclick="refreshSpoolList()" class="hidden md:flex items-center gap-2.5 bg-accent/5 hover:bg-accent/20 hover:border-accent/40 border border-accent/20 text-accent px-5 py-2.5 rounded-xl text-[15px] font-bold transition-all premium-hover">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    SINCRONIZAR COLA
                </button>
                <div class="relative ml-1">
                    <button id="export-menu-btn" onclick="toggleExportMenu(event)" class="flex items-center gap-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 px-4 py-2.5 rounded-xl text-[15px] font-bold transition-all premium-hover uppercase" title="Exportar cola en varios formatos">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        <span class="hidden sm:inline">Exportar</span>
                        <svg class="w-3.5 h-3.5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div id="export-menu" class="hidden absolute top-full right-0 mt-2 w-52 bg-[var(--bg-panel)] border border-[var(--border-color)] rounded-xl shadow-[0_16px_48px_rgba(0,0,0,0.4)] overflow-hidden z-[100] animate-fade-in-up">
                        <div class="px-4 py-2.5 text-[11px] font-bold text-gray-500 uppercase tracking-[0.2em] bg-black/30 border-b border-white/5">Exportar Cola</div>
                        <button onclick="exportData('txt'); closeExportMenu()" class="w-full flex items-center gap-3 px-4 py-3 text-left text-[14px] font-bold text-gray-300 hover:bg-accent/10 hover:text-accent transition-all uppercase">
                            <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Texto
                        </button>
                        <button onclick="exportData('word'); closeExportMenu()" class="w-full flex items-center gap-3 px-4 py-3 text-left text-[14px] font-bold text-gray-300 hover:bg-accent/10 hover:text-accent transition-all uppercase">
                            <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Word
                        </button>
                        <button onclick="exportData('pdf'); closeExportMenu()" class="w-full flex items-center gap-3 px-4 py-3 text-left text-[14px] font-bold text-gray-300 hover:bg-accent/10 hover:text-accent transition-all uppercase">
                            <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            PDF
                        </button>
                        <button onclick="exportData('excel'); closeExportMenu()" class="w-full flex items-center gap-3 px-4 py-3 text-left text-[14px] font-bold text-gray-300 hover:bg-accent/10 hover:text-accent transition-all uppercase">
                            <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Excel
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="flex-1 p-4 lg:p-6 lg:pt-2 flex flex-col lg:flex-row gap-4 lg:gap-6 overflow-hidden">
            <!-- Spool Queue -->
            <div id="spool-queue-panel" class="w-full lg:w-[48%] h-[40vh] lg:h-auto bg-[rgba(var(--bg-panel-rgb),0.95)] border border-white/10 rounded-[1.5rem] flex flex-col overflow-hidden transition-all duration-300 glass-panel shadow-premium">
                <div class="p-6 flex flex-col gap-4 bg-black/40 z-10 border-b border-white/10">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <div class="w-2 h-6 bg-accent rounded-full"></div>
                            <h3 class="font-bold text-gray-100 text-base uppercase tracking-wider">Cola de Reportes</h3>
                        </div>
                        <span class="bg-accent/10 text-accent text-[15px] px-4 py-1.5 rounded-full font-bold shadow-accent" id="spool-count">0 REGISTROS</span>
                    </div>
                    
                    <div class="relative group flex gap-2">
                        <div class="relative flex-1">
                            <input type="text" id="filter-spools" placeholder="BUSCAR EN EL SERVIDOR..." 
                                   class="w-full h-11 bg-black/50 border border-white/10 rounded-xl px-11 text-[13px] font-bold text-gray-300 placeholder:text-gray-600 focus:border-accent/40 focus:ring-1 focus:ring-accent/20 transition-all outline-none uppercase tracking-widest group-hover:border-white/20"
                                   onkeydown="if(event.key==='Enter') fetchSpoolPage(0)">
                            <svg class="w-4 h-4 text-gray-600 absolute left-4 top-1/2 -translate-y-1/2 group-hover:text-accent transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>
                        <button onclick="fetchSpoolPage(0)" class="h-11 px-4 bg-accent/20 border border-accent/30 rounded-xl text-accent text-[11px] font-black tracking-widest hover:bg-accent/40 transition-all uppercase">BUSCAR</button>
                    </div>
                </div>
                
                <div class="flex-1 overflow-auto custom-scroll relative">
                    <table class="w-full text-left">
                        <thead class="bg-black/40 sticky top-0 border-b border-white/10 z-10">
                            <tr class="text-[13px] font-bold text-gray-500 uppercase tracking-wider">
                                <th class="py-3 px-3 w-10">
                                    <input type="checkbox" id="master-select" onclick="toggleAllSpools(this.checked)" class="w-4 h-4 rounded border-white/10 bg-black/40 accent-accent">
                                </th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(1)">Reporte</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(2)">Usuario</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(3)">Archivo</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(4)">Trabajo</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(5)">Num.</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors text-accent" onclick="sortTable(6)">ID</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(7)">Cola</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(8)">Status</th>
                                <th class="py-3 px-3 cursor-pointer hover:text-accent transition-colors" onclick="sortTable(9)">Pág</th>
                            </tr>
                        </thead>
                        <tbody id="spool-list-container" class="divide-y divide-white/5 text-[13px] font-medium">
                            <!-- Items -->
                            <?php if (!$isLoggedIn): ?>
                            <tr>
                                <td colspan="8" class="p-6 text-center text-gray-500 font-bold text-xs mt-10">
                                    Ninguna conexión establecida
                                </td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td colspan="8" class="p-6 text-center text-gray-500 flex items-center justify-center gap-2 font-mono text-xs">
                                     <div class="w-3 h-3 border-2 border-[rgba(var(--accent-rgb),0.2)] border-t-[var(--accent)] rounded-full animate-spin"></div>
                                     RECUPERANDO DATOS...
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Content Preview -->
            <div id="preview-panel" class="flex-1 bg-[var(--bg-panel)] border border-white/10 rounded-[1.5rem] flex flex-col overflow-hidden relative shadow-premium transition-all duration-500">
                <div class="p-6 flex justify-between items-center bg-black/40 z-10 border-b border-white/10">
                    <div class="flex items-center gap-4">
                        <div class="w-1.5 h-6 bg-accent rounded-full"></div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                            <h3 class="font-bold text-gray-100 text-base uppercase tracking-wider">Visor de Contenido</h3>
                        </div>
                        <button onclick="toggleExpandPreview()" id="expand-btn" class="h-10 px-6 bg-white/10 border border-white/20 rounded-xl text-gray-400 hover:text-accent transition-all flex items-center gap-3 font-bold text-[15px] premium-hover" title="Expandir Vista Principal">
                            <svg id="expand-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>
                            EXPANDIR
                        </button>
                        
                        <button onclick="openAdvancedEditor()" class="h-10 px-6 bg-green-500/10 border border-green-500/30 rounded-xl text-green-400 hover:bg-green-500 hover:text-white transition-all flex items-center gap-3 font-bold text-[15px] premium-hover uppercase tracking-widest shadow-[0_0_15px_rgba(34,197,94,0.1)]">
                             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 10l-2 1m0 0l-2-1m2 1v2.5M20 7l-2 1m2-1l-2-1m2 1v2.5M14 4l-2-1-2 1M4 7l2-1M4 7l2 1M4 7v2.5M12 21l-2-1m2 1l2-1m-2 1v-2.5M6 18l-2-1v-2.5M18 18l2-1v-2.5"></path></svg> 
                             EDITOR
                        </button>
                        
                    </div>
                    
                    <div class="flex gap-4 items-center">
                        <!-- BUSCADOR INTERNO (UTIL) -->
                        <div class="flex items-center bg-black/40 border border-white/10 rounded-xl px-4 py-1.5 focus-within:border-accent/40 transition-all">
                            <svg class="w-4 h-4 text-gray-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            <input type="text" id="internal-search" oninput="highlightContent()" placeholder="Filtrar reporte..." class="bg-transparent border-none outline-none text-[15px] text-gray-400 w-40 placeholder-gray-700 font-bold tracking-tight">
                            <span id="search-count" class="text-[15px] text-yellow-500 ml-3 font-black font-mono"></span>
                        </div>

                        <button onclick="exportData('pdf')" class="h-10 w-10 bg-[#dc2626]/20 text-red-500 border border-red-500/30 rounded-xl hover:bg-red-500 hover:text-white transition-all premium-hover flex items-center justify-center shadow-red-500/20" title="Exportar a PDF Profesional">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                        </button>
                    </div>
                </div>

                
                
            <div id="preview-container" class="flex-1 overflow-auto custom-scroll bg-[var(--bg-darker)] relative">
                <div id="preview-content" class="min-h-full w-full min-w-max p-0 text-[15px] font-mono text-gray-300">
                     <div class="min-h-full flex flex-col items-center justify-center text-gray-500 italic opacity-50 p-12">
                             <svg class="w-24 h-24 mb-6 opacity-30 text-accent/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="0.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                             <span class="text-base font-bold tracking-[0.2em] uppercase opacity-70">Seleccione un reporte de la cola para pre-visualizar</span>
                         </div>
                    </div>
                </div>

            </div>
            </div>
        </div>
    </main>

    <!-- Módulo de Estructuración de Datos -->
    <div id="advanced-editor-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-2xl flex flex-col items-center justify-center z-[200]">
        <div class="bg-[var(--bg-panel)] border border-white/10 rounded-[2rem] w-[95vw] h-[95vh] flex flex-col overflow-hidden shadow-[0_0_100px_rgba(0,0,0,0.8)] relative glass-panel">
            <div class="p-8 border-b border-white/5 flex justify-between items-center bg-black/40">
                <div class="flex items-center gap-5">
                    <div class="w-3 h-3 rounded-full bg-green-500 shadow-[0_0_15px_rgba(34,197,94,0.6)] animate-pulse"></div>
                    <h3 class="font-bold text-white text-lg tracking-[0.2em] uppercase">Editor de Estructura</h3>
                </div>
                <button onclick="closeAdvancedEditor()" class="p-4 bg-white/5 border border-white/10 rounded-2xl text-gray-500 hover:text-white transition-all premium-hover">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="bg-black/20 p-5 text-[15px] text-gray-500 border-b border-white/5 flex justify-between items-center shadow-lg z-10">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3 bg-black/40 px-5 py-2.5 rounded-xl border border-white/5 shadow-inner">
                        <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span class="font-bold tracking-widest uppercase text-xs">Pulse sobre el visor para definir los segmentos de datos</span>
                    </div>
                    
                    <div class="flex gap-2 items-center bg-black/40 px-5 py-2.5 rounded-xl border border-white/5 shadow-inner">
                        <span class="font-bold opacity-40 uppercase mr-2 text-[11px]">Escala:</span>
                        <button onclick="changeFontSize(-1)" class="w-8 h-8 rounded-lg bg-white/5 hover:bg-white/10 text-gray-300 font-bold text-lg flex items-center justify-center transition-all">-</button>
                        <button onclick="changeFontSize(1)" class="w-8 h-8 rounded-lg bg-white/5 hover:bg-white/10 text-gray-300 font-bold text-lg flex items-center justify-center transition-all">+</button>
                    </div>
                </div>

                <div class="flex items-center gap-5">
                    <div class="bg-black/60 rounded-2xl border border-white/10 flex p-1 shadow-2xl">
                        <button id="btn-col" onclick="setDrawMode('col')" class="editor-btn-transition text-[11px] font-black px-6 py-2.5 rounded-xl bg-accent text-black shadow-accent tracking-widest uppercase">COLUMNAS</button>
                        <button id="btn-row" onclick="setDrawMode('row')" class="editor-btn-transition text-[11px] font-black px-6 py-2.5 rounded-xl text-gray-500 hover:text-white uppercase tracking-widest">FILAS</button>
                    </div>
                    <button onclick="openColumnEditor()" class="h-11 px-6 text-yellow-500 font-bold border border-yellow-500/30 bg-yellow-500/5 hover:bg-yellow-500 hover:text-black rounded-xl transition-all premium-hover flex items-center gap-2 text-[11px] uppercase tracking-widest">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        CAMPOS
                    </button>
                    <button onclick="autoDetectColumns()" class="h-11 px-5 text-accent font-black border border-accent/30 bg-accent/5 hover:bg-accent hover:text-black rounded-xl transition-all premium-hover flex items-center gap-2 text-[11px] uppercase tracking-widest shadow-lg group">
                        <svg class="w-4 h-4 group-hover:animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707m12.728 12.728L12 12l4-4m-4 4l-4 4"></path></svg>
                        DETECCIÓN
                    </button>
                    <button onclick="clearAllSplits()" class="h-11 px-6 text-red-500 font-bold border border-red-500/30 bg-red-500/5 hover:bg-red-500 hover:text-white rounded-xl transition-all premium-hover flex items-center gap-2 text-[11px] font-black uppercase tracking-widest shadow-lg">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        LIMPIAR
                    </button>
                </div>

            </div>
            
            <div class="flex-1 bg-[var(--bg-main)] overflow-auto custom-scroll relative p-6 w-full" id="advanced-editor-canvas" onmousemove="updateCoords(event)">
                <pre id="advanced-editor-text" class="text-[15px] text-white m-0 leading-[19px] select-text pointer-events-auto relative z-10" style="white-space: pre; font-family: 'JetBrains Mono', Consolas, monospace !important; font-weight: 900;"></pre>
                <!-- Container de reglas alineado con el padding de 24px (p-6) -->
                <div id="advanced-ruler-container" class="absolute top-6 bottom-6 right-6 left-6 pointer-events-none z-20"></div>
            </div>

            <!-- Footer con acciones finales -->
            <div class="p-6 border-t border-white/10 flex justify-between items-center bg-black/90 backdrop-blur-3xl z-30">
                <div class="flex items-center gap-6">
                    <!-- Stats Group -->
                    <div class="flex bg-black/60 rounded-[1.25rem] border border-white/5 p-1 items-center shadow-inner">
                        <div class="px-4 py-2 flex flex-col items-center border-r border-white/5">
                            <span class="text-[9px] text-gray-500 font-black tracking-widest uppercase">Segmentos</span>
                            <span class="text-accent font-black font-mono text-sm tracking-wider" id="advanced-editor-status-count">1</span>
                        </div>
                        <div class="px-5 py-2 flex flex-col items-center">
                            <span class="text-[9px] text-gray-500 font-black tracking-widest uppercase">Cursor</span>
                            <span class="text-white/40 font-black font-mono text-[10px] tracking-widest" id="advanced-editor-coords">C:0 R:0</span>
                        </div>
                    </div>

                    <!-- Library Group -->
                    <div class="flex items-center gap-2 bg-black/60 px-4 py-1.5 rounded-[1.25rem] border border-white/5 shadow-inner">
                        <svg class="w-4 h-4 text-accent/50 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <select id="template-select" class="bg-transparent text-accent text-[10px] font-black tracking-widest uppercase cursor-pointer min-w-[170px] border-none outline-none appearance-none hover:text-white transition-colors">
                            <option value="" class="bg-[var(--bg-panel)] text-gray-500">BIBLIOTECA DE PLANTILLAS</option>
                        </select>
                        <div class="flex gap-2 border-l border-white/10 pl-2">
                            <button onclick="loadTemplate()" class="btn-template-load flex items-center gap-1.5 pl-3 pr-3.5 h-9 rounded-lg text-[10px] font-black tracking-widest uppercase transition-all hover:scale-[1.04] hover:brightness-110 active:scale-95" title="Cargar Plantilla">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.5l5 5V17a2 2 0 01-2 2z"></path></svg>
                                Cargar
                            </button>
                            <button onclick="saveTemplate()" class="btn-template-save flex items-center gap-1.5 pl-3 pr-3.5 h-9 rounded-lg text-[10px] font-black tracking-widest uppercase transition-all hover:scale-[1.04] hover:brightness-110 active:scale-95" title="Guardar esta Estructura">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                                Guardar
                            </button>
                            <button onclick="deleteTemplate()" id="template-delete-btn" class="p-2.5 text-red-500/70 hover:bg-red-500/10 rounded-xl transition-all hidden" title="Eliminar Plantilla">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                            <button onclick="renameTemplate()" id="template-rename-btn" class="p-2.5 text-gray-500 hover:bg-gray-500/10 rounded-xl transition-all hidden" title="Renombrar Plantilla">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center gap-4">
                    <!-- Export Actions -->
                    <div class="flex items-center gap-1 bg-black/60 p-1.5 rounded-2xl border border-white/5">
                        <span class="text-[9px] font-black text-gray-600 uppercase tracking-[0.2em] px-3">Exportar:</span>
                        <button onclick="exportAdvanced('excel')" class="flex items-center gap-2 px-4 py-2.5 bg-green-500/10 text-green-500 rounded-xl hover:bg-green-500 hover:text-black transition-all font-black text-[10px] tracking-widest group">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.5l5 5V17a2 2 0 01-2 2z"></path></svg>
                            EXCEL
                        </button>
                        <button onclick="exportAdvanced('txt')" class="flex items-center gap-2 px-4 py-2.5 bg-accent/10 text-accent rounded-xl hover:bg-accent hover:text-black transition-all font-black text-[10px] tracking-widest group">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                            TEXTO
                        </button>
                    </div>

                    <div class="h-8 w-px bg-white/10 mx-1"></div>

                    <div class="flex gap-3">
                        <button onclick="closeAdvancedEditor()" class="px-6 py-3 rounded-xl text-[10px] font-black text-gray-500 hover:text-white transition-all uppercase tracking-widest">DESCARTAR</button>
                        <button onclick="applyAdvancedEditor()" class="h-12 px-10 bg-accent text-black font-black tracking-[0.2em] uppercase text-[11px] rounded-[1.25rem] hover:scale-105 transition-all shadow-accent pulse-accent flex items-center gap-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                            HOMOLOGAR DATOS
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Column Editor Modal -->
    <div id="column-editor-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-2xl flex flex-col items-center justify-center z-[250]">
        <div class="bg-[var(--bg-panel)] border border-white/10 rounded-[2.5rem] w-full max-w-2xl overflow-hidden shadow-[0_0_80px_rgba(0,0,0,0.6)] glass-panel">
            <div class="p-8 border-b border-white/5 flex justify-between items-center bg-black/40">
                <div class="flex items-center gap-4">
                    <div class="w-2.5 h-7 bg-accent rounded-full"></div>
                    <h3 class="font-bold text-white text-lg tracking-widest uppercase">Mapeador de Columnas</h3>
                </div>
                <button onclick="closeColumnEditor()" class="p-3 bg-white/5 border border-white/10 rounded-xl text-gray-500 hover:text-white transition-all premium-hover">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="bg-black/20 p-5 text-[15px] text-gray-500 border-b border-white/5 uppercase font-bold tracking-[0.2em] shadow-inner text-center">
                Configure la visibilidad y nombres de exportación
            </div>
            <div class="p-8 max-h-[60vh] overflow-y-auto custom-scroll space-y-4" id="column-editor-list">
                <!-- Columns list rendered by JS -->
            </div>
            <div class="p-8 border-t border-white/5 flex justify-end gap-5 bg-black/40 shadow-2xl">
                <button onclick="closeColumnEditor()" class="px-8 py-3 rounded-2xl text-[15px] font-bold text-gray-500 hover:text-white transition-all uppercase tracking-widest">CANCELAR</button>
                <button onclick="applyColumnChanges()" class="px-10 py-4 rounded-[1.5rem] text-[15px] font-bold bg-accent text-black hover:bg-white transition-all shadow-accent uppercase tracking-widest">APLICAR</button>
            </div>
        </div>
    </div>


    <!-- Change Spool Properties Modal (CHGSPLFA) -->
    <div id="cp-modal" class="hidden fixed inset-0 z-[250] flex items-center justify-center bg-black/70 backdrop-blur-sm">
        <div class="w-full max-w-2xl glass-panel border border-white/10 rounded-[2rem] overflow-hidden shadow-[0_32px_128px_rgba(0,0,0,0.8)] animate-scale-in">
            <div class="flex items-center justify-between px-8 py-6 border-b border-white/5 bg-black/40">
                <div>
                    <h3 class="font-bold text-white text-lg tracking-widest uppercase">Cambiar Propiedades del Spool</h3>
                    <p id="cp-spool-name" class="text-sm font-mono text-accent mt-1"></p>
                </div>
                <button onclick="closeChangePropsModal()" class="p-3 bg-white/5 border border-white/10 rounded-xl text-gray-500 hover:text-white transition-all premium-hover">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-8 space-y-5">
                <div>
                    <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Estado (STATUS)</label>
                    <select id="cp-status" class="w-full mt-2 px-5 py-3 bg-black/40 border border-white/10 rounded-xl text-white font-bold outline-none focus:border-accent transition-all">
                        <option value="">Sin cambios</option>
                        <option value="*READY">*READY</option>
                        <option value="*HELD">*HELD</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Cola de salida (OUTQ)</label>
                        <input id="cp-outq" type="text" placeholder="ej. QPRINT o LIB/OUTQ" class="w-full mt-2 px-5 py-3 bg-black/40 border border-white/10 rounded-xl text-white font-bold outline-none focus:border-accent transition-all">
                    </div>
                    <div>
                        <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Formulario (FORMS)</label>
                        <input id="cp-forms" type="text" placeholder="ej. *STD" class="w-full mt-2 px-5 py-3 bg-black/40 border border-white/10 rounded-xl text-white font-bold outline-none focus:border-accent transition-all">
                    </div>
                    <div>
                        <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Copias (COPIES)</label>
                        <input id="cp-copies" type="number" min="1" max="255" placeholder="1" class="w-full mt-2 px-5 py-3 bg-black/40 border border-white/10 rounded-xl text-white font-bold outline-none focus:border-accent transition-all">
                    </div>
                    <div>
                        <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Prioridad (PRTY)</label>
                        <input id="cp-prty" type="number" min="1" max="9" placeholder="5" class="w-full mt-2 px-5 py-3 bg-black/40 border border-white/10 rounded-xl text-white font-bold outline-none focus:border-accent transition-all">
                    </div>
                </div>
                <div>
                    <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Datos de usuario (USRDTA)</label>
                    <input id="cp-usrdata" type="text" placeholder="ej. *BLANK" class="w-full mt-2 px-5 py-3 bg-black/40 border border-white/10 rounded-xl text-white font-bold outline-none focus:border-accent transition-all">
                </div>
            </div>
            <div class="p-8 border-t border-white/5 flex justify-end gap-5 bg-black/40 shadow-2xl">
                <button onclick="closeChangePropsModal()" class="px-8 py-3 rounded-2xl text-[15px] font-bold text-gray-500 hover:text-white transition-all uppercase tracking-widest">CANCELAR</button>
                <button onclick="applyChangeProps()" class="px-10 py-4 rounded-[1.5rem] text-[15px] font-bold bg-accent text-black hover:bg-white transition-all shadow-accent uppercase tracking-widest">APLICAR CAMBIOS</button>
            </div>
        </div>
    </div>




    <!-- Bulk Actions Floating Bar -->
    <div id="bulk-bar" class="hidden fixed bottom-10 left-1/2 -translate-x-1/2 z-[100] flex items-center gap-6 px-10 py-5 bg-[var(--bg-panel)]/90 backdrop-blur-2xl border border-accent/30 rounded-[2rem] shadow-[0_20px_60px_rgba(0,0,0,0.6)] animate-fade-in-up">
         <div class="flex items-center gap-4 pr-6 border-r border-white/10">
            <span id="bulk-count" class="w-12 h-12 bg-accent text-black font-black flex items-center justify-center rounded-full shadow-[0_0_20px_rgba(var(--accent-rgb),0.4)] text-lg ring-4 ring-white/10">0</span>
            <span class="text-[15px] font-bold text-white uppercase tracking-widest">Reportes Seleccionados</span>
        </div>
        <div class="flex items-center gap-3">
             <button onclick="downloadBulk('excel')" class="px-6 py-3 bg-white/5 border border-white/10 rounded-xl text-[15px] font-bold text-green-400 hover:text-green-300 transition-all flex items-center gap-3">
                COMPRIMIR EXCEL (ZIP)
             </button>

             <button onclick="downloadBulk('txt')" class="px-6 py-3 bg-white/5 border border-white/10 rounded-xl text-[15px] font-bold text-gray-300 hover:text-white transition-all flex items-center gap-3">
                COMPRIMIR TXT (ZIP)
             </button>
             <button id="bulk-compare-btn" onclick="compareSpools()" class="hidden px-6 py-3 bg-accent/10 border border-accent/20 rounded-xl text-[15px] font-bold text-accent hover:text-white transition-all flex items-center gap-3">
                COMPARAR
             </button>
             <div class="h-10 w-px bg-white/10 mx-1"></div>
             <button onclick="bulkSpoolAction('hold')" class="px-4 py-3 bg-orange-500/10 border border-orange-500/30 rounded-xl text-[15px] font-bold text-orange-400 hover:bg-orange-500 hover:text-white transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> MANTENER
             </button>
             <button onclick="bulkSpoolAction('release')" class="px-4 py-3 bg-green-500/10 border border-green-500/30 rounded-xl text-[15px] font-bold text-green-400 hover:bg-green-500 hover:text-white transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> SOLTAR
             </button>
             <button onclick="clearBulk()" class="px-6 py-3 text-[15px] font-bold text-gray-500 hover:text-white transition-all uppercase tracking-widest">CANCELAR</button>
        </div>
    </div>

    <!-- Context Menu Improved -->
    <div id="context-menu" class="hidden fixed z-[200] w-72 glass-panel border border-white/10 rounded-2xl shadow-[0_32px_128px_rgba(0,0,0,0.8)] overflow-hidden scale-95 transition-all duration-150 origin-top-left group/ctx animate-scale-in">
        <div class="p-4 bg-gradient-to-r from-black/60 to-accent/5 border-b border-white/5 flex flex-col gap-1">
            <div class="flex items-center justify-between">
                <span id="ctx-spool-name" class="text-[15px] font-bold text-white uppercase tracking-tight truncate max-w-[180px]">Reporte</span>
                <span id="ctx-spool-status" class="px-2 py-0.5 bg-accent/20 text-accent text-[8px] font-bold rounded-md uppercase tracking-widest">DISPONIBLE</span>
            </div>
            <p id="ctx-spool-job" class="text-[15px] text-gray-500 font-mono italic truncate">TRABAJO: 123456/USER/PRINTER</p>
        </div>
        <div class="p-2 space-y-0.5 text-gray-400">
            <div class="px-3 py-2 text-[15px] font-bold text-gray-600 uppercase tracking-[0.2em]">Acciones Sugeridas</div>
            <button onclick="handleContextAction('pdf')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-red-500/10 hover:text-red-400 rounded-xl flex items-center gap-3 transition-all group/item">
                <svg class="w-4 h-4 opacity-50 group-hover/item:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg> 
                Exportar PDF (Oficial)
            </button>
            
            <div class="h-px bg-white/5 my-2 mx-2"></div>
            <div class="px-3 py-2 text-[15px] font-bold text-gray-600 uppercase tracking-[0.2em]">Exportación Directa</div>
            
            <button onclick="handleContextAction('excel')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-green-500/10 hover:text-green-400 rounded-xl flex items-center gap-3 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg> Excel Inteligente
            </button>


            <button onclick="handleContextAction('txt')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-blue-500/10 hover:text-blue-400 rounded-xl flex items-center gap-3 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg> Texto sin formato
            </button>

            <div class="h-px bg-white/5 my-2 mx-2"></div>
            
            <button onclick="handleContextAction('properties')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-yellow-500/10 hover:text-yellow-400 rounded-xl flex items-center gap-3 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Detalles del Reporte
            </button>

            <div class="h-px bg-white/5 my-2 mx-2"></div>
            <div class="px-3 py-2 text-[15px] font-bold text-gray-600 uppercase tracking-[0.2em]">Gestión del Spool</div>

            <button onclick="handleContextAction('reprint')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-blue-500/10 hover:text-blue-400 rounded-xl flex items-center gap-3 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h-13a2 2 0 01-2-2V8m2-3h13a2 2 0 012 2v7a2 2 0 01-2 2m0 0l-3-3m3 3l-3 3m6-3h-6"></path></svg> Reimprimir
            </button>
            <button onclick="handleContextAction('change-props')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-yellow-500/10 hover:text-yellow-400 rounded-xl flex items-center gap-3 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> Cambiar Propiedades…
            </button>
            <button id="ctx-act-hold" onclick="handleContextAction('hold')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-orange-500/10 hover:text-orange-400 rounded-xl flex items-center gap-3 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Mantener (Hold)
            </button>
            <button id="ctx-act-release" onclick="handleContextAction('release')" class="w-full text-left px-4 py-3 text-[15px] font-bold hover:bg-green-500/10 hover:text-green-400 rounded-xl flex items-center gap-3 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> Soltar (Release)
            </button>

        </div>
    </div>
    <div id="control-center-modal" class="hidden fixed inset-0 z-[220] bg-black/60 backdrop-blur-2xl flex items-center justify-center p-4">
        <div class="bg-[var(--bg-panel)] border border-white/10 rounded-[2.5rem] w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden shadow-[0_32px_128px_rgba(0,0,0,0.9)] animate-fade-in-up">
            <header class="p-10 border-b border-white/5 flex justify-between items-center bg-black/20">
                <div class="flex items-center gap-6">
                    <div class="w-16 h-16 bg-accent/10 border border-accent/20 rounded-2xl flex items-center justify-center shadow-accent overflow-hidden">
                        <svg class="w-8 h-8 text-accent animate-[spin_8s_linear_infinite] origin-center" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"></circle></svg>
                    </div>
                    <div>
                        <h2 class="text-3xl font-bold text-white tracking-tighter">CENTRO DE CONTROL</h2>
                        <p class="text-gray-500 font-bold text-[15px] tracking-[0.3em] uppercase mt-1">Configuración y Administración de Identidades</p>
                    </div>
                </div>
                <button onclick="closeControlCenter()" class="p-4 bg-white/5 border border-white/10 rounded-2xl text-gray-400 hover:text-white transition-all premium-hover">&times;</button>
            </header>
            
            <main class="flex-1 overflow-y-auto custom-scroll p-10 grid grid-cols-1 lg:grid-cols-2 gap-10">
                <!-- Favorites Section -->
                <div class="space-y-6">
                    <div class="flex items-center justify-between">
                        <h3 class="text-[15px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-3">
                            <span class="w-1.5 h-6 bg-yellow-500 rounded-full"></span>
                            Gestión de Favoritos
                        </h3>
                        <div class="flex gap-2">
                             <input type="file" id="import-favs-file" class="hidden" accept=".json" onchange="importFavs(event)">
                             <button onclick="document.getElementById('import-favs-file').click()" class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-[15px] font-bold text-gray-400 hover:text-accent transition-all flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> IMPORTAR
                             </button>
                             <button onclick="exportFavs()" class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-[15px] font-bold text-gray-400 hover:text-accent transition-all flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> EXPORTAR
                             </button>
                        </div>
                    </div>
                    
                    <div class="bg-black/30 border border-white/5 rounded-3xl p-6 min-h-[300px] flex flex-col">
                        <div id="control-favs-list" class="space-y-3 flex-1 max-h-[400px] overflow-y-auto custom-scroll pr-4">
                            <!-- JS Renders here -->
                        </div>
                    </div>
                </div>

                <!-- Watchdog and Engine Settings -->
                <div class="space-y-8">
                    <div class="bg-black/40 border border-white/5 p-8 rounded-[2rem]">
                         <h3 class="text-[15px] font-bold text-gray-400 uppercase tracking-widest mb-6">Parámetros de Monitoreo</h3>
                         <div class="space-y-6">
                             <div class="flex items-center justify-between p-5 bg-black/20 border border-white/5 rounded-2xl">
                                 <div>
                                     <p class="text-[15px] font-bold text-gray-300">Intervalo de Sincronización</p>
                                     <p class="text-[15px] text-gray-500 mt-1 uppercase tracking-widest">Frecuencia de Muestreo</p>
                                 </div>
                                 <select id="watchdog-freq" class="bg-black border border-white/10 rounded-xl px-4 py-2 text-[15px] font-bold text-accent focus:outline-none">
                                     <option value="5000">5 SEGUNDOS</option>
                                     <option value="10000" selected>10 SEGUNDOS</option>
                                     <option value="30000">30 SEGUNDOS</option>
                                     <option value="60000">1 MINUTO</option>
                                 </select>
                             </div>
                             
                             <div class="flex items-center justify-between p-5 bg-black/20 border border-white/5 rounded-2xl">
                                 <div>
                                     <p class="text-[15px] font-bold text-gray-300">Notificaciones Sonoras</p>
                                     <p class="text-[15px] text-gray-500 mt-1 uppercase tracking-widest">Alerta auditiva en reportes nuevos</p>
                                 </div>
                                 <button id="sound-toggle" onclick="toggleOption('sound_alerts')" class="w-12 h-6 bg-accent border border-accent/40 rounded-full relative transition-all duration-300">
                                     <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full translate-x-6"></div>
                                 </button>
                             </div>
                         </div>
                    </div>

                    <div class="bg-blue-900/10 border border-blue-500/20 p-8 rounded-[2rem]">
                        <h3 class="text-[15px] font-bold text-blue-400 uppercase tracking-widest mb-4">Información del Sistema</h3>
                        <div class="space-y-2 text-[15px] font-mono text-gray-500">
                            <p>Versión Core: 1.7.5-FINAL</p>
                            <p>Compilación: 2026-03-01</p>
                            <p>Enlace Central: Conectado (V5R3M0)</p>
                            <p>Cifrado de Datos: AES-256 Activado</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Processing Overlay Fast -->
    <div id="loader" class="hidden fixed inset-0 premium-backdrop flex flex-col items-center justify-center z-[200] transition-all duration-300">
        <div class="relative flex flex-col items-center animate-fade-in-up">
            <div class="glr-mini-loader">
                <div class="loader-ring ring-1"></div>
                <div class="loader-ring ring-2"></div>
                <div class="loader-ring ring-3"></div>
                <div class="glr-logo-core">GLR</div>
            </div>
            <div class="mt-8 flex flex-col items-center">
                <p class="font-black text-white tracking-[0.6em] text-[11px] uppercase opacity-80 mb-2">Procesando Spool</p>
                <div class="flex gap-1">
                    <div class="w-1.5 h-1.5 bg-accent rounded-full animate-bounce" style="animation-delay: 0s"></div>
                    <div class="w-1.5 h-1.5 bg-accent rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
                    <div class="w-1.5 h-1.5 bg-accent rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentParsedData = null;
        let currentRawLines = [];
        let currentPreviewMode = 'raw';
        let drawMode = 'col';
        let currentLineColor = '#00f3ff';
        let currentFontSize = 15;
        let calculatedLineHeight = 19;
        let horizontalLines = [0];
        let bandColumns = { 0: [0] };
        let actualCharWidth = 7.2;
        window.smartHighlightActive = true;
        window.styleRules = [];
        window.columnAliases = {};
        window.columnHidden = {};

        let layoutHistory = [];
        let historyIdx = -1;

        function saveHistory() {
            const state = JSON.stringify({
                horizontalLines: [...horizontalLines],
                bandColumns: JSON.parse(JSON.stringify(bandColumns)),
                lineColor: currentLineColor,
                columnAliases: {...window.columnAliases},
                columnHidden: {...window.columnHidden}
            });
            if (historyIdx >= 0 && state === layoutHistory[historyIdx]) return;
            layoutHistory = layoutHistory.slice(0, historyIdx + 1);
            layoutHistory.push(state);
            historyIdx = layoutHistory.length - 1;
        }

        function undoLayout() {
            if (historyIdx > 0) {
                historyIdx--;
                restoreState(layoutHistory[historyIdx]);
            }
        }

        function redoLayout() {
            if (historyIdx < layoutHistory.length - 1) {
                historyIdx++;
                restoreState(layoutHistory[historyIdx]);
            }
        }

        function restoreState(json) {
            const data = JSON.parse(json);
            horizontalLines = data.horizontalLines;
            bandColumns = data.bandColumns;
            currentLineColor = data.lineColor;
            window.columnAliases = data.columnAliases;
            window.columnHidden = data.columnHidden;
            renderSplits();
            updateAdvancedStatus();
        }

        function exportTemplateToFile() {
            const state = {
                horizontalLines,
                bandColumns,
                lineColor: currentLineColor,
                columnAliases: window.columnAliases,
                columnHidden: window.columnHidden,
                v: "1.7"
            };
            const blob = new Blob([JSON.stringify(state, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `plantilla_${Date.now()}.json`;
            a.click();
        }

        async function importTemplateFromFile() {
            const { value: file } = await Swal.fire({
                title: 'Importar Plantilla',
                input: 'file',
                inputAttributes: { 'accept': '.json', 'aria-label': 'Seleccionar archivo de plantilla JSON' },
                background: 'var(--bg-panel)',
                color: 'var(--text-main)',
                confirmButtonColor: 'var(--accent)'
            });
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    try {
                        restoreState(e.target.result);
                        saveHistory();
                        Swal.fire({
                            title: 'Éxito',
                            text: 'Plantilla cargada al editor. ¡No olvides guardarla en tu lista si deseas conservarla!',
                            icon: 'success',
                            background: 'var(--bg-panel)',
                            color: 'var(--text-main)'
                        });
                    } catch(err) { Swal.fire('Error', 'Archivo no válido', 'error'); }
                };
                reader.readAsText(file);
            }
        }


        
        function setLineColor(colorHex) {
            currentLineColor = colorHex;
            
            // Highlight selected button logic
            if(drawMode === 'col') {
                const btnCol = document.getElementById('btn-col');
                btnCol.style.color = colorHex;
                btnCol.style.backgroundColor = colorHex + '33'; // 20% opacity
                btnCol.style.boxShadow = `0 0 8px ${colorHex}55`; // glow
            }
            renderSplits();
        }
        
        function changeFontSize(dir) {
            const textEl = document.getElementById('advanced-editor-text');
            if (!textEl) return;
            currentFontSize += dir;
            if (currentFontSize < 8) currentFontSize = 8;
            if (currentFontSize > 24) currentFontSize = 24;
            
            textEl.style.fontSize = currentFontSize + 'px';
            calculatedLineHeight = currentFontSize + 4; // Consistent 4px gap per Trial version
            textEl.style.lineHeight = calculatedLineHeight + 'px';
            
            recalculateCharWidth();
            renderSplits();
        }

        function recalculateCharWidth() {
            const pre = document.getElementById('advanced-editor-text');
            if (!pre) return;
            const test = document.createElement('span');
            test.innerText = 'X'.repeat(100);
            pre.appendChild(test);
            actualCharWidth = test.getBoundingClientRect().width / 100;
            pre.removeChild(test);
        }

        // --- SMART FEATURES: FAVORITOS, NOTIFICACIONES, AUTO-TEMPLATE ---
        
        // 1. Favoritos
        let favorites = JSON.parse(localStorage.getItem('as400_favs') || '[]');

        function saveFavs() {
            localStorage.setItem('as400_favs', JSON.stringify(favorites));
            renderFavs();
        }

        function renderFavs() {
            const container = document.getElementById('favorites-container');
            const controlContainer = document.getElementById('control-favs-list');
            
            if (favorites.length === 0) {
                const emptyMsg = '<p class="text-[15px] text-gray-600 text-center italic">No hay favoritos</p>';
                if(container) container.innerHTML = emptyMsg;
                if(controlContainer) controlContainer.innerHTML = emptyMsg;
                return;
            }

            // Sidebar: limit display to top 5
            if(container) {
                container.innerHTML = favorites.slice(0, 5).map(user => `
                    <div class="flex items-center justify-between group p-2 rounded-xl hover:bg-white/5 transition-all">
                        <button onclick="exploreUser('${user}')" class="flex items-center gap-3 flex-1">
                            <div class="w-2 h-2 rounded-full bg-accent/40 group-hover:bg-accent group-hover:shadow-accent transition-all"></div>
                            <span class="text-[15px] font-bold text-gray-400 group-hover:text-white uppercase transition-colors">${user}</span>
                        </button>
                    </div>
                `).join('');
                if(favorites.length > 5) {
                    container.innerHTML += `
                        <button onclick="openControlCenter()" class="w-full text-center py-2 text-[15px] font-bold text-gray-600 hover:text-accent transition-all tracking-[0.2em] uppercase">
                            Y ${favorites.length - 5} más... (GESTIONAR)
                        </button>`;
                }
            }

            // Control Center List
            if(controlContainer) {
                controlContainer.innerHTML = favorites.map(user => `
                    <div class="flex items-center justify-between p-4 bg-black/40 border border-white/5 rounded-2xl group premium-hover">
                        <div class="flex items-center gap-4">
                             <div class="w-10 h-10 bg-black/60 rounded-xl flex items-center justify-center text-gray-500 group-hover:text-accent transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                             </div>
                             <span class="text-sm font-bold text-gray-300 uppercase tracking-widest">${user}</span>
                        </div>
                        <div class="flex gap-2">
                             <button onclick="exploreUser('${user}'); closeControlCenter();" class="p-2 text-blue-500/50 hover:text-blue-500 hover:bg-blue-500/10 rounded-lg transition-all" title="Ver Spool">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                             </button>
                             <button onclick="removeFav('${user}')" class="p-2 text-red-500/50 hover:text-red-500 hover:bg-red-500/10 rounded-lg transition-all" title="Eliminar">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                             </button>
                        </div>
                    </div>
                `).join('');
            }
            
            // Header Search Dropdown List
            const dropdownList = document.getElementById('fav-dropdown-list');
            if (dropdownList) {
                dropdownList.innerHTML = favorites.map(user => `
                    <button onclick="exploreUser('${user}'); hideFavDropdown();" class="w-full text-left px-4 py-3 text-[15px] font-bold text-gray-300 hover:bg-white/5 hover:text-accent flex items-center gap-3 transition-colors uppercase border-b border-white/5 last:border-0 group/fav">
                        <svg class="w-4 h-4 opacity-50 group-hover/fav:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        ${user}
                    </button>
                `).join('');
                
                if (favorites.length === 0) {
                     dropdownList.innerHTML = '<div class="p-4 text-center text-sm font-bold text-gray-600 uppercase tracking-widest italic">Alista aquí a tu equipo</div>';
                }
            }
        }

        function exportFavs() {
            const data = JSON.stringify(favorites, null, 2);
            const blob = new Blob([data], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `favoritos_as400_${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function importFavs(event) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const imported = JSON.parse(e.target.result);
                    if(Array.isArray(imported)) {
                        favorites = [...new Set([...favorites, ...imported])];
                        saveFavs();
                        Swal.fire({
                            icon: 'success', title: 'Importación Exitosa',
                            text: `${imported.length} favoritos añadidos/combinados.`,
                            background: 'var(--bg-panel)', color: 'var(--text-main)'
                        });
                    }
                } catch(err) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Archivo JSON inválido', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            };
            reader.readAsText(file);
        }

        function openControlCenter() {
            document.getElementById('control-center-modal').classList.remove('hidden');
            renderFavs();
        }

        function closeControlCenter() {
            document.getElementById('control-center-modal').classList.add('hidden');
        }

        function exploreUser(user) {
            const input = document.getElementById('target-user-search');
            if(input) {
                input.value = user;
                refreshSpoolList();
            }
        }
        
        function showFavDropdown(e) {
            if(e) e.stopPropagation();
            if(favorites.length > 0) {
                document.getElementById('fav-dropdown').classList.remove('hidden');
            }
        }
        
        function hideFavDropdown() {
            setTimeout(() => {
                const dd = document.getElementById('fav-dropdown');
                if(dd) dd.classList.add('hidden');
            }, 150); // Small delay to allow click on items
        }

        function addCurrentToFavs() {
            toggleFavorite();
        }

        function toggleFavorite(user) {
            user = (user || document.getElementById('target-user-search')?.value || '').toUpperCase().trim();
            if (!user) return;
            
            if (favorites.includes(user)) {
                favorites = favorites.filter(f => f !== user);
            } else {
                favorites.push(user);
            }
            saveFavs();
            updateStarIcon();
        }

        function updateStarIcon() {
            const starBtn = document.getElementById('fav-star-btn');
            if(!starBtn) return;
            const currentUser = (document.getElementById('target-user-search')?.value || '').toUpperCase().trim();
            
            if (favorites.includes(currentUser)) {
                starBtn.classList.add('text-yellow-500');
                starBtn.classList.remove('text-gray-500');
            } else {
                starBtn.classList.remove('text-yellow-500');
                starBtn.classList.add('text-gray-500');
            }
        }

        function removeFav(user) {
            favorites = favorites.filter(f => f !== user);
            saveFavs();
            updateStarIcon();
        }

        // 2. Monitorización (Watchdog)
        let watchdogTimer = null;
        let lastKnownSpools = new Set();

        function toggleWatchdog() {
            const btn = document.getElementById('watchdog-btn');
            const knob = document.getElementById('watchdog-knob');
            const status = document.getElementById('watchdog-status');

            if (watchdogTimer) {
                clearInterval(watchdogTimer);
                watchdogTimer = null;
                btn.className = "w-12 h-6 bg-white/5 border border-white/10 rounded-full relative transition-all duration-300";
                knob.className = "absolute top-1 left-1 w-4 h-4 bg-gray-500 rounded-full transition-all duration-300 translate-x-0";
                status.innerText = "DESACTIVADO";
                status.className = "text-[15px] font-bold text-gray-400";
            } else {
                watchdogTimer = setInterval(checkNewSpools, 10000); 
                btn.className = "w-12 h-6 bg-accent/20 border border-accent/40 rounded-full relative transition-all duration-300";
                knob.className = "absolute top-1 left-1 w-4 h-4 bg-accent rounded-full transition-all duration-300 translate-x-6 shadow-accent";
                status.innerText = "ACTIVO";
                status.className = "text-[15px] font-bold text-accent animate-pulse";
                checkNewSpools(true);
            }
        }

        async function checkNewSpools(silent = false) {
            const targetUser = (document.getElementById('target-user-search')?.value || '').toUpperCase().trim();
            try {
                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_remote', target_user: targetUser })
                });
                const result = await response.json();
                if (result.success) {
                    const currentIds = new Set(result.list.map(s => `${s.name}_${s.job}_${s.splnbr}`));
                    
                    // Solo activar si NO es silencioso Y ya tenemos datos previos del mismo usuario
                    if (!silent && lastKnownSpools.size > 0 && window.lastWatchedUser === targetUser) {
                        const newOnes = result.list.filter(s => !lastKnownSpools.has(`${s.name}_${s.job}_${s.splnbr}`));
                        if (newOnes.length > 0) {
                            renderSpoolList(result.list); 
                            Swal.fire({
                                toast: true, position: 'bottom-end', icon: 'info',
                                title: `Nuevos reportes detectados`,
                                text: `${newOnes.length} archivo(s) recién llegados para ${targetUser}.`,
                                showConfirmButton: false, timer: 7000, timerProgressBar: true,
                                background: 'var(--bg-panel)', color: 'var(--text-main)'
                            });
                            // Reproducir sonido si está activo (futura mejora)
                        }
                    }
                    lastKnownSpools = currentIds;
                    window.lastWatchedUser = targetUser;
                }
            } catch (e) { console.error("Watchdog failed", e); }
        }



        function applyTemplateDirectly(name, data) {
            data = data || {};
            horizontalLines = Array.isArray(data.horizontalLines) && data.horizontalLines.length ? [...data.horizontalLines] : [0];

            const rawBc = data.bandColumns;
            let bcData = {};
            if (rawBc && typeof rawBc === 'object') {
                if (Array.isArray(rawBc)) {
                    rawBc.forEach((cols, i) => { bcData[horizontalLines[i] ?? i] = cols; });
                } else {
                    bcData = rawBc;
                }
            }
            if (Object.keys(bcData).length === 0) bcData = { 0: [0] };
            bandColumns = {...bcData};

            if (data.lineColor) setLineColor(data.lineColor);

            // RESTAURATION OF PERSISTENT SETTINGS
            window.columnAliases = (data.columnAliases && typeof data.columnAliases === 'object' && !Array.isArray(data.columnAliases)) ? data.columnAliases : {};
            window.columnHidden = (data.columnHidden && typeof data.columnHidden === 'object' && !Array.isArray(data.columnHidden)) ? data.columnHidden : {};
            window.styleRules = JSON.parse(JSON.stringify(Array.isArray(data.styleRules) ? data.styleRules : []));
            window.smartHighlightActive = data.smartHighlightActive !== undefined ? !!data.smartHighlightActive : true;

            if (currentRawLines && currentRawLines.length > 0) {
                applyCurrentSplits();
            }
        }
        
        // Ejecutar al cargar
        document.addEventListener('DOMContentLoaded', () => { 
            renderFavs(); 
            updateStarIcon();
        });



        function getProcessedSpoolLines() {
            let processedLines = [];
            let lastIdx = -1;
            
            (currentRawLines || []).forEach(line => {
                const ctrl = line.charAt(0);
                const content = line.substring(1);
                
                if (ctrl === '+' && lastIdx >= 0) {
                    let prev = processedLines[lastIdx];
                    let mergedText = '';
                    let boldMap = JSON.parse(JSON.stringify(prev.boldMap || []));
                    let maxLen = Math.max(prev.text.length, content.length);
                    
                    for (let i = 0; i < maxLen; i++) {
                        let charA = prev.text[i] || ' ';
                        let charB = content[i] || ' ';
                        
                        if (charB !== ' ') {
                            boldMap[i] = true;
                            mergedText += (charA !== ' ') ? charA : charB;
                        } else {
                            mergedText += charA;
                        }
                    }
                    processedLines[lastIdx] = { text: mergedText, boldMap: boldMap, ctrl: prev.ctrl };
                } else {
                    processedLines.push({ text: content, boldMap: [], ctrl: ctrl });
                    lastIdx = processedLines.length - 1;
                }
            });
            return processedLines;
        }

        function formatLineToHTML(lineObj) {
            let text = lineObj.text;
            let boldMap = lineObj.boldMap;
            let result = '';
            let inBold = false;
            
            const isCritHeader = /CIFRAS:|TOTAL|REPORTE DE|ORDEN DE COMPRA/i.test(text);
            const forceBold = isCritHeader && !boldMap.some(b => b);

            let stylesToApply = [];
            if (window.styleRules && window.styleRules.length > 0) {
                window.styleRules.forEach(rule => {
                    const pattern = rule.pattern.trim();
                    if (pattern === '') return;
                    
                    let idx = text.toUpperCase().indexOf(pattern.toUpperCase());
                    while (idx !== -1) {
                        stylesToApply.push({
                            start: idx,
                            end: idx + pattern.length,
                            type: rule.type
                        });
                        idx = text.toUpperCase().indexOf(pattern.toUpperCase(), idx + 1);
                    }
                });
            }

            for (let i = 0; i < text.length; i++) {
                const isNaturalBold = boldMap[i] || forceBold;
                const ruleAtPos = stylesToApply.find(s => i >= s.start && i < s.end);
                
                let htmlPrefix = '';
                let htmlSuffix = '';

                if (isNaturalBold && !inBold) {
                    htmlPrefix += '<b class="text-white font-[900] drop-shadow-[0_0_1px_rgba(255,255,255,0.5)]">';
                    inBold = true;
                } else if (!isNaturalBold && inBold) {
                    htmlSuffix = '</b>' + htmlSuffix;
                    inBold = false;
                }

                if (ruleAtPos) {
                    if (ruleAtPos.type === 'bold') htmlPrefix = '<b>' + htmlPrefix;
                    if (ruleAtPos.type === 'italic') htmlPrefix = '<i>' + htmlPrefix;
                    if (ruleAtPos.type === 'underline') htmlPrefix = '<u>' + htmlPrefix;
                    
                    if (ruleAtPos.type === 'bold') htmlSuffix += '</b>';
                    if (ruleAtPos.type === 'italic') htmlSuffix += '</i>';
                    if (ruleAtPos.type === 'underline') htmlSuffix += '</u>';
                }

                let char = text[i];
                let escaped = char;
                if (char === '&') escaped = '&amp;';
                else if (char === '<') escaped = '&lt;';
                else if (char === '>') escaped = '&gt;';
                
                result += htmlPrefix + escaped + htmlSuffix;
            }
            if (inBold) result += '</b>';
            return result;
        }

        // Filter Logic for Spool Queue (now server-side via fetchSpoolPage)
        document.getElementById('filter-spools')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') fetchSpoolPage(0);
        });

        // Find Logic for Report Content
        document.getElementById('find-in-report')?.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            const preElements = document.querySelectorAll('#preview-content pre');
            
            // Remove previous highlights
            document.querySelectorAll('.search-highlight').forEach(el => {
                const parent = el.parentNode;
                parent.replaceChild(document.createTextNode(el.innerText), el);
                parent.normalize();
            });

            if (!term || term.length < 2) return;

            preElements.forEach(pre => {
                const content = pre.innerText;
                if (content.toLowerCase().includes(term)) {
                    // Simple highlighting by replacing HTML content
                    const regex = new RegExp(`(${term.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&')})`, 'gi');
                    pre.innerHTML = pre.innerText.replace(regex, '<span class="search-highlight bg-yellow-500/50 text-white px-0.5 rounded">$1</span>');
                }
            });
        });

        // Fetch Spools
        async function refreshSpoolList() {
            if (!<?php echo $isLoggedIn ? 'true' : 'false'; ?>) return;
            const container = document.getElementById('spool-list-container');
            container.innerHTML = '<tr><td colspan="8" class="p-12 text-center text-gray-500 bg-black/10 rounded-2xl"><div class="flex flex-col items-center gap-4"><div class="w-8 h-8 border-4 border-accent/20 border-t-accent rounded-full animate-[spin_0.3s_linear_infinite]"></div><span class="text-sm font-bold tracking-widest uppercase">ACCEDIENDO AL SISTEMA...</span></div></td></tr>';
            
            const targetUser = document.getElementById('target-user-search')?.value || '';

            try {
                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_remote', target_user: targetUser })
                });
                const result = await response.json();
                if (result.success) {
                    currentParsedData = null;
                    currentRawLines = [];
                    renderPreview(null);
                    renderSpoolList(result.list, result);
                } else {
                    container.innerHTML = `<tr><td colspan="4" class="text-red-400 p-4 text-center">${result.message}</td></tr>`;
                }
            } catch (error) {
                container.innerHTML = `<tr><td colspan="4" class="text-red-500 p-4 text-center">Connection error</td></tr>`;
            }
        }

        // Pagination state
        let spoolCurrentOffset = 0;
        const SPOOL_PAGE_SIZE = 200;

        async function fetchSpoolPage(offset) {
            spoolCurrentOffset = offset;
            const container = document.getElementById('spool-list-container');
            const targetUser = (document.getElementById('target-user-search')?.value || '').toUpperCase().trim();
            const filterName = (document.getElementById('filter-spools')?.value || '').toUpperCase().trim();
            container.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-accent font-bold tracking-widest uppercase animate-pulse">Cargando...</td></tr>';
            try {
                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'list_remote',
                        target_user: targetUser,
                        limit: SPOOL_PAGE_SIZE,
                        offset: offset,
                        filter_name: filterName
                    })
                });
                const result = await response.json();
                if (result.success) {
                    renderSpoolList(result.list, result);
                } else {
                    container.innerHTML = `<tr><td colspan="9" class="text-red-400 p-4 text-center">${result.message}</td></tr>`;
                }
            } catch (e) {
                container.innerHTML = `<tr><td colspan="9" class="text-red-500 p-4 text-center">Error de conexión</td></tr>`;
            }
        }

        function renderPagination(meta) {
            let bar = document.getElementById('spool-pagination-bar');
            if (!bar) {
                bar = document.createElement('div');
                bar.id = 'spool-pagination-bar';
                bar.className = 'flex items-center justify-between px-4 py-2 border-t border-white/10 bg-black/40 text-[11px] font-bold text-gray-500 uppercase tracking-widest gap-2 flex-shrink-0';
                const tableWrap = document.getElementById('spool-list-container')?.closest('.overflow-auto');
                if (tableWrap && tableWrap.parentElement) {
                    tableWrap.parentElement.appendChild(bar);
                }
            }
            if (!meta || !meta.total) { bar.innerHTML = ''; return; }
            const total = meta.total;
            const offset = meta.offset || 0;
            const limit = meta.limit || SPOOL_PAGE_SIZE;
            const page = Math.floor(offset / limit);
            const pages = Math.ceil(total / limit);
            if (pages <= 1) { bar.innerHTML = ''; return; }
            let html = `<span>${total} reportes · Pág. ${page+1} de ${pages}</span><div class="flex gap-1">`;
            if (page > 0) html += `<button onclick="fetchSpoolPage(${(page-1)*limit})" class="px-3 py-1 bg-accent/20 border border-accent/30 rounded-lg text-accent hover:bg-accent/40 transition-all">&#8592; PREV</button>`;
            // Page numbers
            const startPage = Math.max(0, page - 2);
            const endPage = Math.min(pages - 1, page + 2);
            for (let i = startPage; i <= endPage; i++) {
                const active = i === page ? 'bg-accent text-black' : 'bg-black/40 text-gray-400 hover:bg-accent/20';
                html += `<button onclick="fetchSpoolPage(${i*limit})" class="px-3 py-1 border border-white/10 rounded-lg transition-all ${active}">${i+1}</button>`;
            }
            if (page < pages - 1) html += `<button onclick="fetchSpoolPage(${(page+1)*limit})" class="px-3 py-1 bg-accent/20 border border-accent/30 rounded-lg text-accent hover:bg-accent/40 transition-all">SIG &#8594;</button>`;
            html += '</div>';
            bar.innerHTML = html;
        }

        function renderSpoolList(list, meta) {
            const container = document.getElementById('spool-list-container');
            const total = (meta && meta.total != null) ? meta.total : (list ? list.length : 0);
            const shown = list ? list.length : 0;
            const offset = (meta && meta.offset) ? meta.offset : 0;
            document.getElementById('spool-count').innerText = `${shown} DE ${total} REGISTROS`;
            window.lastSpoolList = list; // Para el dashboard
            window.lastSpoolTotal = total;
            renderPagination(meta);
            
            // Sincronizar con el Watchdog para evitar notificaciones falsas tras refresh manual
            if(list) {
                const currentIds = new Set(list.map(s => `${s.name}_${s.job}_${s.splnbr}`));
                lastKnownSpools = currentIds;
                window.lastWatchedUser = (document.getElementById('target-user-search')?.value || '').toUpperCase().trim();
            }

            if (!list || list.length === 0) {
                container.innerHTML = '<tr><td colspan="9" class="p-12 text-center text-gray-600 font-bold tracking-[0.2em] uppercase">No se encontraron reportes activos</td></tr>';
                return;
            }

            let html = '';
            list.forEach((s, i) => {
                const uniqueId = `${s.name}_${s.job}_${s.splnbr}`;
                const rowHtml = `
                    <tr class="spool-row border-b border-white/5 hover:bg-accent/5 transition-all cursor-pointer group animate-fade-in-up" 
                        onclick="handleRowClick(window.lastSpoolList[${i}], this)" 
                        data-idx="${i}"
                        style="animation-delay: ${i * 0.03}s">
                        <td class="px-3 py-2 no-print"><input type="checkbox" onclick="event.stopPropagation(); toggleSpoolSelection('${uniqueId}', this.checked)" class="spool-cb w-4 h-4 bg-black/60 border-white/10 rounded focus:ring-accent accent-accent"></td>
                        <td class="px-3 py-2">
                            <div class="flex flex-col">
                                <span class="font-bold text-gray-200 group-hover:text-accent transition-colors uppercase leading-tight text-[13px]">${s.datos_usu && s.datos_usu.trim() ? s.datos_usu : s.name}</span>
                                <span class="text-[11px] text-gray-500 font-bold tracking-wide uppercase opacity-50">${s.datos_usu && s.datos_usu.trim() ? s.name : 'Reporte de Sistema'}</span>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-[13px] font-bold text-gray-300 uppercase">${s.user}</td>
                        <td class="px-3 py-2 text-[13px] font-bold text-gray-200 uppercase font-mono">${s.name}</td>
                        <td class="px-3 py-2 text-[13px] font-bold text-gray-300 font-mono">${s.job.split('/').pop()}</td>
                        <td class="px-3 py-2 text-[13px] font-bold text-gray-400 font-mono">${s.jobnbr}</td>
                        <td class="px-3 py-2 text-[13px] font-bold text-accent font-mono">${s.splnbr}</td>
                        <td class="px-3 py-2 text-[13px] font-bold text-gray-400 uppercase">${s.cola || '-'}</td>
                        <td class="px-3 py-2">
                            <span class="px-3 py-1 bg-black/50 border border-white/10 text-[12px] text-gray-300 font-bold rounded-lg uppercase shadow-sm">${s.status}</span>
                        </td>
                        <td class="px-3 py-2 font-mono font-bold text-accent text-xs">${s.pages || '1'}</td>
                    </tr>
                `;
                html += rowHtml;
            });
            container.innerHTML = html;
        }

        // --- EVENT HANDLERS ---
        // Duplicate handleRowClick removed to ensure consistency
        // Unified Sort Logic
        function sortTable(n) {
            const table = document.querySelector("#spool-list-container");
            const rows = Array.from(table.rows);
            if (rows.length === 0) return;
            
            const isAsc = table.getAttribute('data-sort-col') != n || table.getAttribute('data-sort-dir') === 'desc';
            table.setAttribute('data-sort-col', n);
            table.setAttribute('data-sort-dir', isAsc ? 'asc' : 'desc');

            rows.sort((r1, r2) => {
                if (!r1.cells[n] || !r2.cells[n]) return 0;
                const v1 = r1.cells[n].innerText.trim().toUpperCase();
                const v2 = r2.cells[n].innerText.trim().toUpperCase();
                const n1 = parseFloat(v1);
                const n2 = parseFloat(v2);
                
                let res;
                if (!isNaN(n1) && !isNaN(n2)) res = n1 - n2;
                else res = v1.localeCompare(v2);
                
                return isAsc ? res : -res;
            });
            
            rows.forEach(r => table.appendChild(r));
        }

        function setDrawMode(mode) {
            drawMode = mode;
            const btnCol = document.getElementById('btn-col');
            const btnRow = document.getElementById('btn-row');

            // Reset transitions for instant feedback
            btnCol.style.transition = 'none';
            btnRow.style.transition = 'none';

            if (mode === 'col') {
                btnCol.classList.add('bg-accent', 'text-black', 'shadow-accent', 'scale-105');
                btnCol.classList.remove('bg-white/5', 'text-gray-500', 'scale-100');
                btnRow.classList.remove('bg-green-500', 'text-white', 'shadow-[0_0_15px_rgba(34,197,94,0.6)]', 'scale-105');
                btnRow.classList.add('bg-white/5', 'text-gray-500', 'scale-100');
                document.getElementById('advanced-editor-canvas').style.cursor = 'col-resize';
            } else {
                btnRow.classList.add('bg-green-500', 'text-white', 'shadow-[0_0_15px_rgba(34,197,94,0.6)]', 'scale-105');
                btnRow.classList.remove('bg-white/5', 'text-gray-500', 'scale-100');
                btnCol.classList.remove('bg-accent', 'text-black', 'shadow-accent', 'scale-105');
                btnCol.classList.add('bg-white/5', 'text-gray-500', 'scale-100');
                document.getElementById('advanced-editor-canvas').style.cursor = 'row-resize';
            }
            
            setTimeout(() => {
                btnCol.style.transition = '';
                btnRow.style.transition = '';
            }, 50);
            
            renderSplits();
        }


        function getBandStart(rowIdx, hl) {
            const list = hl || horizontalLines;
            let start = 0;
            for (let i = 0; i < list.length; i++) {
                if (list[i] <= rowIdx) start = list[i];
            }
            return start;
        }

        // Columna de corte aplicable a una linea del spool dependiendo de la banda horizontal a la que pertenece
        function getBandColsForLine(lineIndex, hl, bc) {
            const list = hl || horizontalLines;
            const colsMap = bc || bandColumns;
            const bandStart = getBandStart(lineIndex, list);
            const cols = Array.isArray(colsMap[bandStart]) ? colsMap[bandStart] : [];
            const normalized = cols.filter(c => Number.isFinite(c) && c >= 0).map(Number).sort((a,b) => a - b);
            if (normalized[0] !== 0) normalized.unshift(0);
            return { bandStart: bandStart, cols: normalized };
        }

        // Unión de todas las posiciones de columna definidas en cualquier banda (encabezado de tabla)
        function getGlobalColumnPositions(bc) {
            const colsMap = bc || bandColumns;
            const set = new Set([0]);
            Object.values(colsMap).forEach(arr => {
                if (!Array.isArray(arr)) return;
                arr.forEach(c => { if (Number.isFinite(c) && c >= 0) set.add(Number(c)); });
            });
            return Array.from(set).sort((a,b) => a - b);
        }

        // Corta una línea con las columnas de su banda y las coloca alineadas a las columnas globales
        function sliceLineByBand(text, bandCols, globalCols) {
            const row = new Array(globalCols.length).fill('');
            for (let i = 0; i < bandCols.length; i++) {
                const start = bandCols[i];
                const end = (i + 1 < bandCols.length) ? bandCols[i + 1] : text.length;
                const gi = globalCols.indexOf(start);
                if (gi < 0) continue;
                if (start < end && start < text.length) {
                    const cell = text.substring(start, end).trim();
                    if (cell !== '') row[gi] = cell;
                }
            }
            return row;
        }

        function buildStructureHeaders(globalCols, aliases) {
            const a = aliases || window.columnAliases || {};
            return globalCols.map((c, i) => (a[c]) ? a[c] : `CAMPO_${i+1}`);
        }

        function openAdvancedEditor() {
            closeExportMenu();
            const favF = document.getElementById('fav-dropdown'); if (favF) favF.classList.add('hidden');
            if (!currentRawLines || currentRawLines.length === 0) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'Sin Datos',
                    text: 'Por favor, selecciona un documento spool primero para poder trazar tu layout.',
                    background: 'var(--bg-panel)',
                    color: 'var(--text-main)',
                    confirmButtonColor: 'var(--accent)'
                });
            }
            const pre = document.getElementById('advanced-editor-text');
            const processed = getProcessedSpoolLines();
            window.currentProcessedLength = processed.length;
            
            // UI Enhancement: Loading state for editor
            pre.innerHTML = '<div class="flex items-center justify-center p-20 text-gray-600 font-bold italic">CARGANDO LIENZO DE TRABAJO...</div>';
            
            setTimeout(() => {
                // Solo mostramos hasta 200 lineas para el trazado (un poco más para contexto)
                const htmlLines = processed.slice(0, 200).map(lineObj => {
                    return lineObj.text;
                });
                pre.innerText = htmlLines.join('\n');
                
                // Mostrar modal para que las mediciones en recalculateCharWidth sean reales
                document.getElementById('advanced-editor-modal').classList.remove('hidden');
                document.getElementById('advanced-editor-modal').classList.add('animate-fade-in-up');
                
                setTimeout(() => {
                    // Measure precise character width AFTER modal is for sure visible and layout computed
                    recalculateCharWidth();
                    
                    if (!bandColumns[0]) {
                        horizontalLines = [0];
                        bandColumns = { 0: [0] };
                    }
                    if (currentLineColor === '#00f3ff') currentLineColor = getDefaultLineColor();
                    setDrawMode('col'); 
                    loadTemplatesList();
                    renderSplits();
                }, 50);
            }, 100);
        }


        function closeAdvancedEditor() {
            document.getElementById('advanced-editor-modal').classList.add('hidden');
        }

        function clearAllSplits() {
            horizontalLines = [0];
            bandColumns = { 0: [0] };
            renderSplits();
        }

        document.getElementById('advanced-editor-canvas').addEventListener('click', (e) => {
            if (e.target.closest('button') || e.target.closest('input') || e.target.closest('label')) return; 
            if (e.target.id !== 'advanced-editor-canvas' && e.target.id !== 'advanced-editor-text') return;

            const textEl = document.getElementById('advanced-editor-text');
            const rect = textEl.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;
            
            if (mouseX < 0 || mouseY < 0) return;
            
            const colIdx = Math.round(mouseX / actualCharWidth);
            const rowIdx = Math.floor(mouseY / calculatedLineHeight);
            const bandStart = getBandStart(rowIdx);
            
            if (drawMode === 'col') {
                if (!bandColumns[bandStart]) bandColumns[bandStart] = [0];
                let cols = bandColumns[bandStart];
                
                if (cols.includes(colIdx)) {
                    bandColumns[bandStart] = cols.filter(c => c !== colIdx);
                } else {
                    if (colIdx > 0) {
                        cols.push(colIdx);
                        cols.sort((a,b) => a - b);
                    }
                }
            } else {
                if (rowIdx === 0) return; 
                if (horizontalLines.includes(rowIdx)) {
                    horizontalLines = horizontalLines.filter(r => r !== rowIdx);
                    delete bandColumns[rowIdx];
                } else {
                    horizontalLines.push(rowIdx);
                    horizontalLines.sort((a,b) => a - b);
                    bandColumns[rowIdx] = [0];
                }
            }
            saveHistory();
            renderSplits();
        });


        function updateCoords(e) {
            const textEl = document.getElementById('advanced-editor-text');
            if (!textEl) return;
            const rect = textEl.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;
            
            const col = Math.max(0, Math.round(mouseX / actualCharWidth));
            const row = Math.max(0, Math.floor(mouseY / calculatedLineHeight));
            
            const coordsEl = document.getElementById('advanced-editor-coords');
            if(coordsEl) coordsEl.innerText = `C:${col} R:${row}`;
        }

        function renderSplits() {
            const container = document.getElementById('advanced-ruler-container');
            container.innerHTML = '';
            
            horizontalLines.forEach((startRow, idx) => {
                const nextStartRow = (idx + 1 < horizontalLines.length) ? horizontalLines[idx+1] : (window.currentProcessedLength || 1000);
                
                if (startRow > 0) {
                    const topPx = startRow * calculatedLineHeight;
                    // Línea de corte de FILA: gruesa, llena y con brillo
                    const hLine = document.createElement('div');
                    hLine.className = 'absolute z-20 pointer-events-none transition-all';
                    hLine.style.backgroundColor = currentLineColor;
                    hLine.style.height = '3px';
                    hLine.style.top = topPx + 'px';
                    hLine.style.left = '0';
                    hLine.style.right = '0';
                    hLine.style.boxShadow = '0 0 14px ' + hexToRgba(currentLineColor, 0.65);
                    container.appendChild(hLine);

                    // Chip con el número de fila en el margen izquierdo
                    const chip = document.createElement('div');
                    chip.className = 'absolute z-20 pointer-events-none flex items-center';
                    chip.style.top = (topPx - 9) + 'px';
                    chip.style.left = '-6px';
                    chip.style.height = '18px';
                    chip.style.padding = '0 7px';
                    chip.style.borderRadius = '4px';
                    chip.style.backgroundColor = 'var(--accent)';
                    chip.style.color = '#000';
                    chip.style.fontFamily = "'JetBrains Mono', Consolas, monospace";
                    chip.style.fontSize = '10px';
                    chip.style.fontWeight = '900';
                    chip.style.lineHeight = '18px';
                    chip.style.letterSpacing = '0.1em';
                    chip.style.boxShadow = '0 0 10px ' + accentRGB(0.5);
                    chip.textContent = 'R' + startRow;
                    container.appendChild(chip);
                }

                if (drawMode === 'row' && startRow >= 0) {
                    const topPx = startRow * calculatedLineHeight;
                    const heightPx = (nextStartRow - startRow) * calculatedLineHeight;
                    const hBand = document.createElement('div');
                    hBand.className = 'absolute z-10 pointer-events-none transition-all';
                    hBand.style.backgroundColor = 'var(--accent)';
                    hBand.style.opacity = '0.13';
                    hBand.style.top = topPx + 'px';
                    hBand.style.left = '0';
                    hBand.style.right = '0';
                    hBand.style.height = heightPx + 'px';
                    container.appendChild(hBand);
                }

                const cols = bandColumns[startRow] || [0];
                cols.forEach(charIndex => {
                    if (charIndex === 0) return;
                    const vLine = document.createElement('div');
                    vLine.className = 'absolute w-[2px] opacity-90 z-20 pointer-events-none transition-all';
                    vLine.style.backgroundColor = currentLineColor;
                    vLine.style.boxShadow = '0 0 10px ' + hexToRgba(currentLineColor, 0.55);
                    vLine.style.left = (charIndex * actualCharWidth) + 'px';
                    vLine.style.top = (startRow * calculatedLineHeight) + 'px';
                    vLine.style.height = ((nextStartRow - startRow) * calculatedLineHeight) + 'px';
                    container.appendChild(vLine);
                });
            });

            let totalCols = 0;
            Object.values(bandColumns).forEach(c => totalCols += (c.length === 1 && c[0] === 0 ? 0 : c.length - 1));
            const statusEl = document.getElementById('advanced-editor-status-count');
            if(statusEl) statusEl.innerText = horizontalLines.length;

            updateLivePreview();
        }


        let splitViewActive = false;
        function toggleSplitView() {
            splitViewActive = !splitViewActive;
            const panel = document.getElementById('advanced-editor-preview-panel');
            const btn = document.getElementById('btn-toggle-split');
            if(!panel || !btn) return;

            if (splitViewActive) {
                panel.classList.remove('hidden');
                btn.classList.add('bg-white/20', 'text-white', 'border-white/30');
                updateLivePreview();
            } else {
                panel.classList.add('hidden');
                btn.classList.remove('bg-white/20', 'text-white', 'border-white/30');
            }
        }

        function updateLivePreview() {
            const liveGrid = document.getElementById('advanced-live-grid');
            if (!liveGrid || !splitViewActive) return;
            const state = getCurrentTableState();
            
            if (state.data.length === 0 || state.headers.length < 2) {
                liveGrid.innerHTML = `
                    <div class="h-full flex flex-col items-center justify-center p-20 text-center gap-4 opacity-30">
                        <svg class="w-12 h-12 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 7v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V7m-16 0V5a2 2 0 012-2h8a2 2 0 012 2v2m-12 0h12"></path></svg>
                        <div class="text-[11px] font-black uppercase tracking-[0.3em]">Defina segmentos para iniciar</div>
                    </div>`;
                return;
            }

            let html = `
                <div class="overflow-auto h-full custom-scroll">
                    <table class="w-full text-left border-collapse table-fixed">
                        <thead>
                            <tr class="bg-black/60 sticky top-0 z-20">
                                ${state.headers.map(h => `
                                    <th class="px-4 py-3 border-b border-white/10 text-[10px] font-black text-accent/80 uppercase tracking-widest bg-black/40 backdrop-blur-md">
                                        <div class="truncate">${h}</div>
                                    </th>
                                `).join('')}
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5 font-mono">
                            ${state.data.slice(0, 100).map(row => `
                                <tr class="hover:bg-accent/5 transition-colors group">
                                    ${row.map(cell => `
                                        <td class="px-4 py-2 border-r border-white/5 text-[10.5px] text-gray-400 group-hover:text-white truncate">
                                            ${cell || '&nbsp;'}
                                        </td>
                                    `).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${state.data.length > 100 ? `<div class="p-6 text-center text-[9px] text-gray-600 font-bold uppercase tracking-[0.4em] bg-black/20 italic">Vista limitada a 100 registros</div>` : ''}
                </div>
            `;
            liveGrid.innerHTML = html;
        }

        function getCurrentTableState() {
            const processedLines = getProcessedSpoolLines();
            const globalCols = getGlobalColumnPositions();
            const headers = buildStructureHeaders(globalCols);
            const data = [];

            for (let index = 0; index < processedLines.length && data.length < 100; index++) {
                const lineObj = processedLines[index];
                const band = getBandColsForLine(index);
                const row = sliceLineByBand(lineObj.text, band.cols, globalCols);
                if (row.some(c => c !== '')) data.push(row);
            }
            return { headers, data };
        }

        async function autoDetectColumns() {
            const pre = document.getElementById('advanced-editor-text');
            const lines = pre.innerText.split('\n').filter(l => l.trim().length > 0).slice(0, 15);
            if (lines.length === 0) return;

            const maxLen = Math.max(...lines.map(l => l.length));
            let potentialCols = [];
            
            // Analizar gaps de espacios en blanco verticales
            for (let c = 5; c < maxLen - 2; c++) {
                let isWhitespaceGutter = true;
                for (let l = 0; l < lines.length; l++) {
                    const char = lines[l][c] || ' ';
                    const prevChar = lines[l][c-1] || ' ';
                    if (char !== ' ' || prevChar !== ' ') {
                        isWhitespaceGutter = false;
                        break;
                    }
                }
                if (isWhitespaceGutter) {
                    // Si encontramos un gap, y no hay uno muy cerca
                    if (potentialCols.length === 0 || c - potentialCols[potentialCols.length-1] > 4) {
                        potentialCols.push(c);
                    }
                }
            }

            if (potentialCols.length > 0) {
                const confirm = await Swal.fire({
                    title: 'Ajuste Magnético',
                    text: `He detectado ${potentialCols.length} columnas probables. ¿Deseas aplicarlas?`,
                    icon: 'question',
                    showCancelButton: true,
                    background: 'var(--bg-panel)', color: 'var(--text-main)',
                    confirmButtonColor: 'var(--accent)'
                });

                if (confirm.isConfirmed) {
                    const currentBand = getBandStart(0);
                    bandColumns[currentBand] = [0, ...potentialCols];
                    saveHistory();
                    renderSplits();
                    
                    if (!splitViewActive) toggleSplitView(); // Mostrar preview si no estaba

                    Swal.fire({
                        icon: 'success',
                        title: 'Columnas Ajustadas',
                        timer: 1500,
                        showConfirmButton: false,
                        background: 'var(--bg-panel)', color: 'var(--text-main)'
                    });
                }
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'Sin patrones claros',
                    text: 'No pude detectar gaps claros. Prueba a trazar manualmente o usa un reporte con más encabezados.',
                    background: 'var(--bg-panel)', color: 'var(--text-main)'
                });
            }
        }


        function applyCurrentSplits() {
            if (!currentRawLines || currentRawLines.length === 0) return;
            
            const processedLines = getProcessedSpoolLines();
            const globalCols = getGlobalColumnPositions();
            const data = [];
            const boldRows = [];

            processedLines.forEach((lineObj, index) => {
                const band = getBandColsForLine(index);
                const cLine = lineObj.text;
                const row = sliceLineByBand(cLine, band.cols, globalCols);
                const hasBold = lineObj.boldMap.some(b => b) || /CIFRAS:|TOTAL|REPORTE DE|ORDEN DE COMPRA/i.test(cLine);
                
                if (lineObj.ctrl === '1') {
                    let fIdx = row.findIndex(c => c !== '');
                    if (fIdx !== -1) row[fIdx] = '___PAGE_BREAK___' + row[fIdx];
                    else row[0] = '___PAGE_BREAK___'; // En fila vacía ponemos el marcador al inicio
                }
                
                data.push(row);
                if(hasBold) boldRows.push(data.length); 
            });
            
            window.globalSplits = globalCols;
            const headers = buildStructureHeaders(globalCols);
            
            currentParsedData = {
                headers: headers,
                data: data,
                bold_rows: boldRows
            };
            
            renderSplits();
            renderPreview(currentParsedData);
        }

        function applyAdvancedEditor() {
            applyCurrentSplits();
            closeAdvancedEditor();
        }

        function exportAdvanced(type) {
            applyCurrentSplits();
            exportData(type);
        }

        // --- TEMPLATES LOGIC ---
        // --- SERVER TEMPLATES LOGIC ---
        let glrCurrentUser = 'USER';

        function isOwnTemplate(name) {
            return name.indexOf(glrCurrentUser + ' - ') === 0;
        }

        function updateTemplateActionButtons() {
            const select = document.getElementById('template-select');
            const del = document.getElementById('template-delete-btn');
            const ren = document.getElementById('template-rename-btn');
            if (!select) return;
            const owned = select.value ? isOwnTemplate(select.value) : false;
            if (del) del.classList.toggle('hidden', !owned);
            if (ren) ren.classList.toggle('hidden', !owned);
        }

        async function loadTemplatesList() {
            const select = document.getElementById('template-select');
            if(!select) return;
            select.innerHTML = '<option value="">Plantillas guardadas...</option>';
            try {
                const response = await fetch('process.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'load_templates' })
                });
                const result = await response.json();
                if(result.success && result.templates) {
                    window.glrSharedTemplates = result.templates;
                    glrCurrentUser = (result.currentUser || 'USER').toUpperCase();
                    Object.keys(result.templates).forEach(name => {
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.innerText = isOwnTemplate(name) ? name + '  (Tuyo)' : name;
                        select.appendChild(opt);
                    });
                    select.addEventListener('change', updateTemplateActionButtons);
                    updateTemplateActionButtons();
                }
            } catch(e) {}
        }

        async function deleteTemplate() {
            const select = document.getElementById('template-select');
            const name = select.value;
            if (!name) return;
            const { isConfirmed } = await Swal.fire({
                title: '¿Eliminar plantilla?',
                text: `Se borrará "${name}" del servidor.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
                background: 'var(--bg-panel)', color: 'var(--text-main)'
            });
            if (!isConfirmed) return;
            try {
                const response = await fetch('process.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_template', name })
                });
                const result = await response.json();
                if (result.success) {
                    await loadTemplatesList();
                    Swal.fire({ icon: 'success', title: 'Eliminada', text: 'Plantilla borrada del servidor.', timer: 1500, showConfirmButton: false, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'No se pudo eliminar.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            } catch(e) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Problema de red.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        async function renameTemplate() {
            const select = document.getElementById('template-select');
            const name = select.value;
            if (!name) return;
            const { value: newName } = await Swal.fire({
                title: 'Renombrar plantilla',
                input: 'text',
                inputValue: name.replace(glrCurrentUser + ' - ', ''),
                showCancelButton: true,
                confirmButtonText: 'Renombrar',
                cancelButtonText: 'Cancelar',
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                confirmButtonColor: 'var(--accent)',
                inputValidator: (value) => !value || !value.trim() ? 'Escribe un nombre' : null
            });
            if (!newName || !newName.trim()) return;
            try {
                const response = await fetch('process.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'rename_template', name, newName: newName.trim() })
                });
                const result = await response.json();
                if (result.success) {
                    await loadTemplatesList();
                    setTimeout(() => { if (select) select.value = result.name; }, 500);
                    updateTemplateActionButtons();
                    Swal.fire({ icon: 'success', title: 'Renombrada', timer: 1500, showConfirmButton: false, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'No se pudo renombrar.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            } catch(e) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Problema de red.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        async function saveTemplate() {
            const { value: name } = await Swal.fire({
                title: 'Guardar',
                text: 'Escribe un nombre para guardar esta plantilla (Compartida con todos):',
                input: 'text',
                background: 'var(--bg-panel)',
                color: 'var(--text-main)',
                confirmButtonColor: '#eab308',
                cancelButtonColor: 'var(--border-color)',
                showCancelButton: true,
                confirmButtonText: 'Guardar',
                cancelButtonText: 'Cancelar',
                inputValidator: (value) => {
                    if (!value) return '¡Necesitas escribir un nombre!'
                }
            });

            if (!name || name.trim() === '') return;
            
            Swal.fire({
                title: 'Guardando...',
                text: 'Subiendo plantilla al servidor web',
                allowOutsideClick: false,
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                didOpen: () => { Swal.showLoading() }
            });

            try {
                const payload = {
                    action: 'save_template',
                    name: name.trim(),
                    data: { 
                        horizontalLines: horizontalLines, 
                        bandColumns: bandColumns,
                        columnAliases: window.columnAliases || {},
                        columnHidden: window.columnHidden || {},
                        styleRules: window.styleRules || [],
                        smartHighlightActive: window.smartHighlightActive,
                        lineColor: currentLineColor || '#00f3ff',
                        pdf: {
                            fontFamily: themeColor('--font-mono') || 'JetBrains Mono',
                            bgColor: themeColor('--bg-panel'),
                            textColor: themeColor('--text-main'),
                            borderColor: themeColor('--accent'),
                            headerColor: themeColor('--accent')
                        }
                    }
                };
                
                const response = await fetch('process.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const result = await response.json();
                if(result.success) {
                    await loadTemplatesList();
                    setTimeout(() => {
                        const select = document.getElementById('template-select');
                        if (select) select.value = name.trim();
                    }, 500);
                    
                    Swal.fire({
                        title: '¡Guardada!',
                        text: 'Plantilla alojada en el servidor con éxito.',
                        icon: 'success',
                        background: 'var(--bg-panel)',
                        color: 'var(--text-main)',
                        confirmButtonColor: 'var(--accent)',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    throw new Error('Servidor respondio con error');
                }
            } catch(e) { 
                Swal.fire({title: 'Error', text: 'No se pudo guardar la plantilla en el servidor.', icon: 'error', background: 'var(--bg-panel)', color: 'var(--text-main)'});
            }
        }

        function loadTemplate() {
            const select = document.getElementById('template-select');
            const name = select.value;
            if (!name) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Oops...',
                    text: 'Selecciona una plantilla de la lista primero.',
                    background: 'var(--bg-panel)',
                    color: 'var(--text-main)',
                    confirmButtonColor: 'var(--accent)'
                });
                return;
            }
            
            try {
                const templates = window.glrSharedTemplates || {};
                if (templates[name]) {
                    applyTemplateDirectly(name, templates[name]);
                    Swal.fire({
                        title: 'Modo Aplicado',
                        text: `Se cargó la plantilla de red: ${name}`,
                        icon: 'success',
                        background: 'var(--bg-panel)',
                        color: 'var(--text-main)',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({title:'Error', text:'Plantilla no encontrada.', icon: 'error', background: 'var(--bg-panel)', color: 'var(--text-main)'});
                }
            } catch(e) { Swal.fire({title:'Error', text: 'Error al procesar la plantilla.', icon: 'error', background: 'var(--bg-panel)', color: 'var(--text-main)'}); }
        }

        // Column Editor Logic
        function openColumnEditor() {
            closeExportMenu();
            if (!currentParsedData) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'Sin Reporte',
                    text: 'Carga un reporte antes de configurar las cabeceras.',
                    background: 'var(--bg-panel)',
                    color: 'var(--text-main)',
                    confirmButtonColor: 'var(--accent)'
                });
            }
            const listContainer = document.getElementById('column-editor-list');
            let html = '<div class="space-y-2">';
            
            // Usamos globalSplits para mapear persistentemente
            const gSplits = window.globalSplits || [0];
            
            currentParsedData.headers.forEach((h, index) => {
                const splitStart = gSplits[index];
                const isHidden = window.columnHidden && window.columnHidden[splitStart];
                
                html += `
                <div class="flex items-center gap-5 p-4 border border-white/5 rounded-2xl bg-black/40 hover:border-accent/40 transition-all premium-hover group">
                    <input type="checkbox" id="col-chk-${index}" data-split="${splitStart}" class="w-6 h-6 rounded-lg border-white/10 bg-black/40 text-accent cursor-pointer transition-all" ${!isHidden ? 'checked' : ''}>
                    <input type="text" id="col-name-${index}" data-split="${splitStart}" value="${h}" class="flex-1 bg-black/40 border border-white/10 text-sm font-bold text-white px-5 py-3 rounded-xl outline-none focus:border-accent focus:ring-4 focus:ring-accent/5 transition-all uppercase">
                    <span class="text-[15px] text-gray-500 font-bold font-mono w-20 text-right group-hover:text-accent">CH:${splitStart}</span>
                </div>
                `;
            });
            html += '</div>';
            listContainer.innerHTML = html;
            document.getElementById('column-editor-modal').classList.remove('hidden');
        }

        function closeColumnEditor() {
            document.getElementById('column-editor-modal').classList.add('hidden');
        }

        function applyColumnChanges() {
            if (!currentParsedData) return;
            
            const gSplits = window.globalSplits || [0];
            
            // Actualizamos el estado global en lugar de filtrar datos destructivamente
            currentParsedData.headers.forEach((_, index) => {
                const ck = document.getElementById(`col-chk-${index}`);
                const input = document.getElementById(`col-name-${index}`);
                const splitStart = gSplits[index];
                
                if (ck && input && splitStart !== undefined) {
                    window.columnAliases[splitStart] = input.value.trim();
                    window.columnHidden[splitStart] = !ck.checked;
                }
            });

            closeColumnEditor();
            // Refrescamos los splits (esto usará los nuevos aliases)
            currentPreviewMode = 'grid'; // Switch to grid to see results
            applyCurrentSplits();
        }

        async function handleRowClick(spoolItem, rowElement) {
            return await fetchFromAS400(spoolItem, rowElement);
        }

        async function fetchFromAS400(spoolItem, rowElement) {
            // Highlight active row
            document.querySelectorAll('.active-spool').forEach(tr => tr.classList.remove('active-spool'));
            if (rowElement) rowElement.classList.add('active-spool');

            loader.classList.remove('hidden');
            const previewContent = document.getElementById('preview-content');
            try {
                // Clear state while loading
                previewContent.innerHTML = '<div class="h-full flex items-center justify-center"><div class="flex items-center gap-4 bg-black/40 border border-white/5 px-6 py-4 rounded-xl"><div class="w-6 h-6 border-4 border-accent/20 border-t-accent rounded-full animate-[spin_0.3s_linear_infinite]"></div><span class="text-white font-bold tracking-widest uppercase text-sm">LEYENDO SPOOL...</span></div></div>';
                
                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'fetch_remote',
                        file: spoolItem.name,
                        job: spoolItem.job,
                        number: spoolItem.splnbr
                    })
                });
                
                const responseText = await response.text();
                let result = JSON.parse(responseText);
                
                if (result.success) {
                    currentParsedData = result.data;
                    currentRawLines = result.raw_lines || [];
                    horizontalLines = [0];
                    bandColumns = { 0: [0] };
                    
                    // Update preview header info
                    // document.getElementById('current-report-badge').classList.remove('hidden');
                    // document.getElementById('current-report-badge').classList.add('flex');
                    // document.getElementById('current-report-info').innerText = `${spoolItem.name} | ${spoolItem.job}`;
                    
                    // IF we already have splits defined (perhaps from previous report in session), we COULD apply them here.
                    // But usually fetching a new report should reset to Default View (RAW).
                    currentPreviewMode = 'raw';
                    renderPreview(result.data);
                    

                    
                    // Notificacion profesional de carga exitosa
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                        background: 'var(--bg-panel)',
                        color: 'var(--text-main)'
                    });
                    Toast.fire({
                        icon: 'success',
                        title: 'Reporte cargado con éxito'
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error AS/400', text: result.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            } catch (error) {
                alert('Fetch Error: ' + error.message);
            } finally {
                loader.classList.add('hidden');
            }
        }
        
        function renderPreview(data) {
            const previewContent = document.getElementById('preview-content');
            if (!data) {
                previewContent.innerHTML = '<div class="h-full flex items-center justify-center text-gray-500 italic opacity-50">Selecciona un archivo spool para visualizar</div>';
                return;
            }

            currentParsedData = data;

            if (currentPreviewMode === 'raw') {
                let pagesHtml = '';
                let pageCount = 1;
                let processedLines = getProcessedSpoolLines();

                const startPageHTML = () => {
                    let h = `<div class="as400-page group/page mx-auto relative">`;
                    h += `<div class="no-print absolute top-6 right-8 z-10 opacity-60 group-hover/page:opacity-100 transition-opacity">
                            <span class="page-badge uppercase">Página ${pageCount}</span>
                          </div>`;
                    h += `<pre class="as400-pre text-[15px] leading-[1.3] text-gray-400 whitespace-pre select-text">`;
                    return h;
                };

                pagesHtml += startPageHTML();
                
                processedLines.forEach((lineObj, idx) => {
                    const ctrl = lineObj.ctrl;
                    const formatted = formatLineToHTML(lineObj);
                    
                    if (ctrl === '1' && idx > 0) {
                        pagesHtml += '</pre></div>';
                        pageCount++;
                        pagesHtml += startPageHTML();
                        pagesHtml += ' ' + formatted + '\n';
                    } else if (ctrl === '0') {
                        pagesHtml += '\n ' + formatted + '\n';
                    } else if (ctrl === '-') {
                        pagesHtml += '\n\n ' + formatted + '\n';
                    } else {
                        // Handle continuous content or page start
                        pagesHtml += ' ' + formatted + '\n';
                    }
                });

                
                pagesHtml += '</pre></div>';
                previewContent.innerHTML = pagesHtml;
                previewContent.classList.remove('p-0', 'p-4'); 
                previewContent.classList.add('px-8', 'py-6');
                previewContent.style.overflowX = 'auto'; // Permitir scroll si el reporte es muy ancho
                previewContent.scrollTop = 0;
                return;
            }

            // MODO GRID: Para edición rápida y exportación controlada
            previewContent.classList.add('p-0');
            previewContent.classList.remove('px-8', 'py-6');
            previewContent.style.overflowX = 'auto';
            
            let html = '<table class="min-w-full text-left table-auto border-separate border-spacing-0">';
            html += '<thead class="bg-black/60 sticky top-0 z-10 border-b border-white/10 shadow-xl"><tr class="uppercase text-[15px] font-black tracking-[0.2em] text-accent/80">';
            data.headers.forEach((h, colIdx) => {
                html += `<th class="px-5 py-4 border-b border-white/10 font-black whitespace-nowrap bg-black/40 backdrop-blur-md cursor-pointer hover:bg-accent/10 hover:text-accent transition-all group" onclick="sortGrid(${colIdx})">
                    <div class="flex items-center gap-3">${h || '-'} <svg class="w-3 h-3 text-gray-700 group-hover:text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path></svg></div>
                </th>`;
            });
            html += '</tr></thead><tbody>';
            
            data.data.forEach((row, rowIdx) => {
                const rowText = row.join(' ').toUpperCase();
                let rowStyle = '';
                // Check if row index is marked as bold (+ overprint)
                const isBold = data.bold_rows && data.bold_rows.includes(rowIdx + 1);
                
                if(isBold || rowText.includes('TOTAL')) rowStyle = 'bg-accent/10 border-l-4 border-l-accent font-bold';
                if(rowText.includes('ERROR') || rowText.includes('RECHAZADO')) rowStyle = 'bg-red-500/10 border-l-4 border-l-red-500';

                html += `<tr class="${rowStyle} ${isBold ? 'text-white' : ''} hover:bg-accent/5 group transition-all border-b border-white/[0.02] shadow-sm">`;
                row.forEach((cell, colIdx) => {
                    let cellContent = (cell || '');
                    let cellClass = isBold ? 'font-black text-white' : 'text-gray-300';
                    html += `<td class="px-5 py-2.5 font-mono text-[15px] ${cellClass} border-b border-white/5 whitespace-pre outline-none focus:bg-accent/20 focus:text-white transition-all cursor-text selection:bg-accent selection:text-black" contenteditable="true" onblur="updateGridCell(${rowIdx}, ${colIdx}, this.innerText)">${cellContent}</td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table>';
            
            previewContent.innerHTML = html;
            previewContent.scrollTop = 0;
        }

        // --- GRID INTERACTIVO LOGIC ---
        let currentSortCol = -1;
        let currentSortDir = 'asc';

        function switchViewMode(mode) {
            currentPreviewMode = 'raw';
            if (currentParsedData) renderPreview(currentParsedData);
        }

        function updateGridCell(rowIdx, colIdx, newValue) {
            if(!currentParsedData || !currentParsedData.data[rowIdx]) return;
            currentParsedData.data[rowIdx][colIdx] = newValue;
        }

        window.permanentHighlights = [];
        // --- LOGICA DE BUSQUEDA INTERNA (ULTRA UTIL) ---
        function highlightContent() {
            const term = document.getElementById('internal-search').value.toLowerCase();
            const previewContainer = document.getElementById('preview-content');
            const countEl = document.getElementById('search-count');
            
            if (!currentRawLines || currentRawLines.length === 0) return;
            
            if (!term) {
                renderPreview({ headers: currentParsedData.headers, data: currentParsedData.data });
                countEl.innerText = '';
                return;
            }

            const container = document.getElementById('preview-content');
            const rows = container.querySelectorAll('tr');
            let totalMatches = 0;
            let firstMatch = null;

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                cells.forEach(cell => {
                    const text = cell.innerText;
                    if (text.toLowerCase().includes(term)) {
                        const regex = new RegExp(`(${term})`, 'gi');
                        cell.innerHTML = text.replace(regex, '<span class="search-highlight bg-yellow-400 text-black px-1 rounded shadow-sm">$1</span>');
                        totalMatches++;
                        if(!firstMatch) firstMatch = cell;
                    }
                });
            });

            countEl.innerText = totalMatches > 0 ? `${totalMatches} matches` : 'No results';
            if(firstMatch) firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        async function promptHighlight() {
            const { value: term } = await Swal.fire({
                title: 'Marca Texto Profesional',
                text: 'Ingrese palabras clave separadas por comas:',
                input: 'text',
                inputPlaceholder: 'Ej: TOTAL, SALDO, ERROR, 2026...',
                showCancelButton: true,
                confirmButtonText: 'Resaltar',
                cancelButtonText: 'Limpiar Todo',
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                confirmButtonColor: '#eab308'
            });
            
            if (term) {
                window.permanentHighlights = term.split(',').map(t => t.trim().toUpperCase()).filter(t => t.length > 0);
            } else {
                window.permanentHighlights = [];
            }
            if (currentParsedData) renderPreview(currentParsedData);
        }

        function filterEmptyGridRows() {
            if (!currentParsedData || !currentParsedData.data) return;
            const originalLength = currentParsedData.data.length;
            
            // Filter out rows where ALL cells are completely empty, or have only whitespaces/dashes
            currentParsedData.data = currentParsedData.data.filter(row => {
                return row.some(cell => {
                    const cText = cell.trim();
                    return cText !== '' && cText !== '-';
                });
            });
            
            const removed = originalLength - currentParsedData.data.length;
            renderPreview(currentParsedData);
            
            Swal.fire({
                toast: true, position: 'bottom-end', showConfirmButton: false, timer: 3000,
                icon: 'success', title: `Data Grid Limpio`, text: `Se eliminaron ${removed} filas vacías.`
            });
        }

        // --- DASHBOARD LOGIC 2.0 ---
        let dashboardCharts = {};

        function openDashboard() {
            if (!window.lastSpoolList || window.lastSpoolList.length === 0) {
                return Swal.fire({ icon: 'info', title: 'Calculando...', text: 'No hay datos de reportes activos para analizar.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
            document.getElementById('dashboard-modal').classList.remove('hidden');
            renderDashboard();
        }

        function closeDashboard() {
            document.getElementById('dashboard-modal').classList.add('hidden');
        }

        function renderDashboard() {
            const list = window.lastSpoolList;
            
            // 1. KPIs
            const totalReports = list.length;
            const totalPages = list.reduce((acc, s) => acc + (parseInt(s.pages) || 0), 0);
            const statusCounts = list.reduce((acc, s) => { acc[s.status] = (acc[s.status] || 0) + 1; return acc; }, {});
            const userCounts = list.reduce((acc, s) => { acc[s.user] = (acc[s.user] || 0) + 1; return acc; }, {});
            const nameCounts = list.reduce((acc, s) => { acc[s.name] = (acc[s.name] || 0) + 1; return acc; }, {});
            
            const avgPages = totalReports > 0 ? (totalPages / totalReports).toFixed(1) : 0;
            const largestSpool = list.reduce((max, s) => Math.max(max, parseInt(s.pages) || 0), 0);

            const kpiContainer = document.getElementById('dash-kpi-container');
            kpiContainer.innerHTML = `
                <div class="flex flex-col border-l-4 border-accent pl-8 py-2 bg-accent/5 rounded-r-2xl transition-all hover:bg-accent/10">
                    <span class="text-[15px] text-gray-500 uppercase font-bold tracking-[0.2em] mb-2">Total Reportes</span>
                    <div class="flex items-baseline gap-2">
                        <span class="text-5xl font-bold text-white leading-none">${totalReports.toLocaleString()}</span>
                        <span class="text-xs text-accent font-bold">ELEMENTOS</span>
                    </div>
                </div>
                <div class="flex flex-col border-l-4 border-blue-500 pl-8 py-2 bg-blue-500/5 rounded-r-2xl transition-all hover:bg-blue-500/10">
                    <span class="text-[15px] text-gray-500 uppercase font-bold tracking-[0.2em] mb-2">Páginas Totales</span>
                    <div class="flex items-baseline gap-2">
                        <span class="text-5xl font-bold text-blue-400 leading-none">${totalPages.toLocaleString()}</span>
                        <span class="text-xs text-blue-500 font-bold">PÁGS</span>
                    </div>
                </div>
                <div class="flex flex-col border-l-4 border-yellow-500 pl-8 py-2 bg-yellow-500/5 rounded-r-2xl transition-all hover:bg-yellow-500/10">
                    <span class="text-[15px] text-gray-500 uppercase font-bold tracking-[0.2em] mb-2">Promedio Página</span>
                    <div class="flex items-baseline gap-2">
                        <span class="text-5xl font-bold text-yellow-400 leading-none">${avgPages}</span>
                        <span class="text-xs text-yellow-500 font-bold">PROM</span>
                    </div>
                </div>
                <div class="flex flex-col border-l-4 border-red-500 pl-8 py-2 bg-red-500/5 rounded-r-2xl transition-all hover:bg-red-500/10">
                    <span class="text-[15px] text-gray-500 uppercase font-bold tracking-[0.2em] mb-2">Spool Máximo</span>
                    <div class="flex items-baseline gap-2">
                        <span class="text-5xl font-bold text-red-400 leading-none">${largestSpool}</span>
                        <span class="text-xs text-red-500 font-bold">MÁX</span>
                    </div>
                </div>
            `;

            // 2. Metrics Technical
            const outqCounts = list.reduce((acc, s) => { acc[s.cola] = (acc[s.cola] || 0) + 1; return acc; }, {});
            document.getElementById('spool-outqs').innerText = Object.keys(outqCounts).length;
            document.getElementById('spool-network-load').innerText = (totalPages * 0.08).toFixed(1) + " MB";

            // 3. Clear existing charts
            Object.values(dashboardCharts).forEach(c => { if(c && c.destroy) c.destroy(); });

            // Chart Core Globals
            const globalOptions = { 
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { 
                    y: { grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#666', font: { size: 10 } }, border: { display: false } },
                    x: { grid: { display: false }, ticks: { color: '#666', font: { size: 10 } } }
                }
            };

            // ---- [CHART 1: USER BAR] ----
            const sortedUsers = Object.entries(userCounts).sort((a,b) => b[1] - a[1]).slice(0, 8);
            const ctxUsers = document.getElementById('chart-users-activity').getContext('2d');
            const g1 = ctxUsers.createLinearGradient(0, 0, 0, 400);
            g1.addColorStop(0, themeColor());
            g1.addColorStop(1, accentRGB(0.05));

            dashboardCharts.users = new Chart(ctxUsers, {
                type: 'bar',
                data: {
                    labels: sortedUsers.map(u => u[0]),
                    datasets: [{
                        data: sortedUsers.map(u => u[1]),
                        backgroundColor: g1,
                        borderColor: themeColor(),
                        borderWidth: 2,
                        borderRadius: 12
                    }]
                },
                options: globalOptions
            });

            // ---- [CHART 2: STATUS PIE] ----
            const ctxStatus = document.getElementById('chart-status-pie').getContext('2d');
            const statusColors = ['#00f3ff', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#a855f7', '#ec4899'];
            dashboardCharts.status = new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(statusCounts),
                    datasets: [{
                        data: Object.values(statusCounts),
                        backgroundColor: statusColors,
                        borderWidth: 0,
                        cutout: '80%',
                        spacing: 2
                    }]
                },
                options: { 
                    responsive: true, maintainAspectRatio: false, 
                    plugins: { legend: { display: false } },
                    animation: { animateScale: true }
                }
            });

            const statusLegend = document.getElementById('status-legend');
            statusLegend.innerHTML = Object.entries(statusCounts).map(([k, v], i) => `
                <div class="flex items-center justify-between p-3.5 bg-white/[0.03] rounded-2xl border border-white/[0.03] hover:border-white/10 transition-all">
                    <div class="flex items-center gap-3">
                        <span class="w-2 h-2 rounded-full" style="background: ${statusColors[i % statusColors.length]}"></span>
                        <span class="text-[15px] font-bold text-gray-500 uppercase tracking-tighter">${k}</span>
                    </div>
                    <span class="text-xs font-bold text-white">${v}</span>
                </div>
            `).join('');

            // ---- [CHART 3: TOP SPOOLS POR PÁGINAS] ----
            const topSpools = [...list]
                .map(s => ({ label: `${s.name}`, pages: parseInt(s.pages) || 0 }))
                .sort((a, b) => b.pages - a.pages)
                .slice(0, 10);
            const ctxTop = document.getElementById('chart-top-pages').getContext('2d');
            dashboardCharts.topPages = new Chart(ctxTop, {
                type: 'bar',
                data: {
                    labels: topSpools.map(s => s.label),
                    datasets: [{
                        label: 'Páginas',
                        data: topSpools.map(s => s.pages),
                        backgroundColor: accentRGB(0.55),
                        borderColor: themeColor(),
                        borderWidth: 2,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#666', font: { size: 10 } }, border: { display: false } },
                        y: { grid: { display: false }, ticks: { color: '#888', font: { size: 10 } }, border: { display: false } }
                    }
                }
            });

            // ---- [CHART 4: TIPOLOGY RADAR] ----
            const sortedTypes = Object.entries(nameCounts).sort((a,b) => b[1] - a[1]).slice(0, 6);
            const ctxRadar = document.getElementById('chart-types-radar').getContext('2d');
            dashboardCharts.radar = new Chart(ctxRadar, {
                type: 'radar',
                data: {
                    labels: sortedTypes.map(t => t[0]),
                    datasets: [{
                        data: sortedTypes.map(t => t[1]),
                        backgroundColor: accentRGB(0.2),
                        borderColor: themeColor(),
                        pointBackgroundColor: themeColor(),
                        pointBorderColor: themeColor('--text-main'),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        r: {
                            angleLines: { color: 'rgba(255,255,255,0.05)' },
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            suggestedMin: 0,
                            ticks: { display: false },
                            pointLabels: { color: '#666', font: { weight: 'bold', size: 9 } }
                        }
                    }
                }
            });
        }

        // --- BULK SELECTION ---
        let selectedSpools = new Set();

        function toggleSpoolSelection(id, checked) {
            if(checked) selectedSpools.add(id);
            else selectedSpools.delete(id);
            updateBulkBar();
        }

        function toggleAllSpools(checked) {
            const list = window.lastSpoolList || [];
            document.querySelectorAll('input.spool-cb').forEach(cb => {
                cb.checked = checked;
                const row = cb.closest('tr');
                const idx = row.dataset.idx;
                if(list[idx]) {
                    const id = `${list[idx].name}_${list[idx].job}_${list[idx].splnbr}`;
                    toggleSpoolSelection(id, checked);
                }
            });
        }

        function updateBulkBar() {
            const bar = document.getElementById('bulk-bar');
            const count = document.getElementById('bulk-count');
            const compareBtn = document.getElementById('bulk-compare-btn');
            
            if(selectedSpools.size > 0) {
                bar.classList.remove('hidden');
                count.innerText = selectedSpools.size;
                
                // Show compare button ONLY if exactly 2 are selected
                 if(selectedSpools.size >= 2) {
                     if(compareBtn) compareBtn.classList.remove('hidden');
                 } else {
                     if(compareBtn) compareBtn.classList.add('hidden');
                 }
            } else {
                bar.classList.add('hidden');
            }
        }

        function clearBulk() {
            selectedSpools.clear();
            document.querySelectorAll('input.spool-cb').forEach(cb => cb.checked = false);
            updateBulkBar();
        }

        function parseRawLinesWithTemplate(rawLines, templateData) {
            let processedLines = [];
            let lastIdx = -1;
            
            (rawLines || []).forEach(line => {
                const ctrl = line.charAt(0);
                const content = line.substring(1);
                
                if (ctrl === '+' && lastIdx >= 0) {
                    let prev = processedLines[lastIdx];
                    let mergedText = '';
                    let boldMap = JSON.parse(JSON.stringify(prev.boldMap || []));
                    let maxLen = Math.max(prev.text.length, content.length);
                    
                    for (let i = 0; i < maxLen; i++) {
                        let charA = prev.text[i] || ' ';
                        let charB = content[i] || ' ';
                        
                        if (charB !== ' ') {
                            boldMap[i] = true;
                            mergedText += (charA !== ' ') ? charA : charB;
                        } else {
                            mergedText += charA;
                        }
                    }
                    processedLines[lastIdx].text = mergedText;
                    processedLines[lastIdx].boldMap = boldMap;
                } else {
                    let boldMap = new Array(content.length).fill(false);
                    processedLines.push({ text: content, boldMap: boldMap });
                    lastIdx++;
                }
            });

            const hl = templateData.horizontalLines || [0];
            const bc = templateData.bandColumns || {0: [0]};
            const globalCols = getGlobalColumnPositions(bc);
            const headers = buildStructureHeaders(globalCols, templateData.columnAliases);
            const data = [];
            const boldRows = [];

            processedLines.forEach((lineObj, index) => {
                const band = getBandColsForLine(index, hl, bc);
                const cLine = lineObj.text;
                const row = sliceLineByBand(cLine, band.cols, globalCols);
                const hasBold = lineObj.boldMap.some(b => b) || /CIFRAS:|TOTAL|REPORTE DE|ORDEN DE COMPRA/i.test(cLine);
                
                if (row.some(c => c !== '')) {
                    data.push(row);
                    if (hasBold) boldRows.push(data.length - 1);
                }
            });
            return { headers, data, bold_rows: boldRows };
        }

        async function downloadBulk(type) {
            if(selectedSpools.size === 0) return;
            
            let wizardParams = null;
            if (type !== 'txt') {
                const wizard = await showExportWizard('Exportación Masiva');
                if (!wizard) return;
                wizardParams = wizard;
            }

            loader.classList.remove('hidden');
            
            const ids = Array.from(selectedSpools);
            const listToDownload = window.lastSpoolList.filter(s => ids.includes(`${s.name}_${s.job}_${s.splnbr}`));
            
            try {
                if(typeof JSZip === 'undefined') throw new Error("JSZip no cargado");
                const zip = new JSZip();
                const folder = zip.folder(`AS400_Exports_${type.toUpperCase()}`);
                
                for(let item of listToDownload) {
                    try {
                        const res = await fetch('process.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                action: 'fetch_remote', 
                                file: item.name, 
                                job: item.job, 
                                number: item.splnbr 
                            })
                        });
                        const d = await res.json();
                        if(d.success) {
                            if(type === 'txt' && !wizardParams) {
                                folder.file(`${item.name}_${item.splnbr}.txt`, d.raw_lines.join('\r\n'));
                            } else {
                                let exportPayload = { headers: ['Contenido'], data: d.raw_lines.map(line => [line]) };
                                if (wizardParams && wizardParams.structureTemplate && wizardParams.structureTemplate !== 'default') {
                                    const templateData = window.glrSharedTemplates ? window.glrSharedTemplates[wizardParams.structureTemplate] : null;
                                    if (templateData) {
                                        exportPayload = parseRawLinesWithTemplate(d.raw_lines, templateData);
                                    }
                                }

                                const exportRes = await fetch('process.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ 
                                        action: 'export', 
                                        type: type, 
                                        data: exportPayload,
                                        styleRules: window.styleRules || [],
                                        smartHighlight: window.smartHighlightActive,
                                        pdfTemplate: wizardParams ? wizardParams.template : 'default',
                                        pdfStampText: wizardParams ? wizardParams.stampText : 'OFICIAL',
                                        pdfStampStyle: wizardParams ? wizardParams.stampStyle : 'classic'
                                    })
                                });
                                const exportDataRes = await exportRes.json();
                                if (exportDataRes.success && exportDataRes.file) {
                                    const fileRes = await fetch(`exports/${exportDataRes.file}`);
                                    const blob = await fileRes.blob();
                                    folder.file(`${item.name}_${item.splnbr}_${exportDataRes.file}`, blob);
                                }
                            }
                        }
                    } catch(err) { console.error("Error batch fetching", item.name); }
                }
                
                const content = await zip.generateAsync({type:"blob"});
                const link = document.createElement('a');
                link.href = URL.createObjectURL(content);
                link.download = `Batch_${type.toUpperCase()}_${new Date().getTime()}.zip`;
                link.click();
            } catch(e) {
                Swal.fire({ icon: 'error', title: 'Batch Error', text: e.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            } finally {
                loader.classList.add('hidden');
                clearBulk();
            }
        }

         async function compareSpools() {
            const ids = Array.from(selectedSpools);
            if(ids.length < 2) return;
            
            loader.classList.remove('hidden');
            const list = window.lastSpoolList.filter(s => ids.includes(`${s.name}_${s.job}_${s.splnbr}`));
            
            try {
                const results = await Promise.all(list.map(s => 
                    fetch('process.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'fetch_remote', file: s.name, job: s.job, number: s.splnbr })
                    }).then(r => r.json())
                ));

                if(results.every(r => r.success)) {
                    // Create a side-by-side view in a modal
                    Swal.fire({
                        title: 'Comparación de Spools',
                        html: `
                            <div class="flex gap-4 h-[70vh] overflow-hidden text-left">
                                ${list.map((s, i) => `
                                    <div class="flex-1 border border-white/10 rounded-xl overflow-auto p-4 bg-black/40 font-mono text-[15px] min-w-[300px]">
                                        <div class="text-accent mb-2 font-bold">${s.name}</div>
                                        ${results[i].raw_lines.join('<br>').replace(/ /g, '&nbsp;')}
                                    </div>
                                `).join('')}
                            </div>
                        `,
                        width: '95vw',
                        background: 'var(--bg-panel)',
                        color: 'var(--text-main)',
                        showConfirmButton: false,
                        showCloseButton: true
                    });
                }
            } catch(e) {
                Swal.fire({ icon: 'error', title: 'Compare Error', text: e.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            } finally {
                loader.classList.add('hidden');
            }
        }

        // --- CONTEXT MENU ---
        let contextSpool = null;
        window.addEventListener('contextmenu', e => {
            const row = e.target.closest('.spool-row');
            if(row) {
                e.preventDefault();
                const idx = row.dataset.idx;
                contextSpool = window.lastSpoolList[idx];
                
                const menu = document.getElementById('context-menu');
                document.getElementById('ctx-spool-name').innerText = contextSpool.name;
                document.getElementById('ctx-spool-status').innerText = contextSpool.status;
                document.getElementById('ctx-spool-job').innerText = `JOB: ${contextSpool.job}`;

                const st = (contextSpool.status || '').toUpperCase();
                const held = /HLD|HELD|\*H/.test(st);
                const btnHold = document.getElementById('ctx-act-hold');
                const btnRelease = document.getElementById('ctx-act-release');
                if (btnHold) btnHold.classList.toggle('hidden', held);
                if (btnRelease) btnRelease.classList.toggle('hidden', !held);
                
                // Adjust position
                const rect = menu.getBoundingClientRect();
                let left = e.pageX;
                let top = e.pageY;
                if (left + 300 > window.innerWidth) left -= 300;
                if (top + 400 > window.innerHeight) top -= 400;

                menu.style.left = left + 'px';
                menu.style.top = top + 'px';
                menu.classList.remove('hidden');
                menu.classList.add('animate-scale-in');
            }
        });

        // Click centralizado abajo (Línea ~3200) para ocultarlo

        async function handleContextAction(action) {
            if(!contextSpool) return;
            document.getElementById('context-menu').classList.add('hidden'); // Hide menu immediately

            switch(action) {
                case 'open': 
                    await handleRowClick(contextSpool); 
                    break;
                case 'print': 
                    await handleRowClick(contextSpool); 
                    printReport(); 
                    break;
                case 'excel': 
                    await handleRowClick(contextSpool); 
                    exportData('excel'); 
                    break;
                case 'word': 
                    await handleRowClick(contextSpool); 
                    exportData('word'); 
                    break;
                case 'pdf': 
                    await handleRowClick(contextSpool); 
                    exportData('pdf'); 
                    break;
                case 'txt': 
                    await handleRowClick(contextSpool); 
                    exportData('txt'); 
                    break;
                case 'hold':
                    confirmSpoolAction(contextSpool, 'hold');
                    break;
                case 'release':
                    confirmSpoolAction(contextSpool, 'release');
                    break;
                case 'reprint':
                    confirmSpoolAction(contextSpool, 'reprint');
                    break;
                case 'change-props':
                    openChangePropsModal(contextSpool);
                    break;
                case 'properties':
                    Swal.fire({
                        title: 'Detalles del Spool',
                        html: `
                            <div class="text-left space-y-4 font-mono text-sm leading-relaxed p-4 bg-black/40 rounded-3xl border border-white/5 mt-4">
                                <div class="flex justify-between border-b border-white/5 pb-2">
                                    <span class="text-gray-500 uppercase">Archivo:</span>
                                    <span class="text-accent font-bold">${contextSpool.name}</span>
                                </div>
                                <div class="flex justify-between border-b border-white/5 pb-2">
                                    <span class="text-gray-500 uppercase">Usuario:</span>
                                    <span class="text-white">${contextSpool.user}</span>
                                </div>
                                <div class="flex justify-between border-b border-white/5 pb-2">
                                    <span class="text-gray-500 uppercase">Trabajo:</span>
                                    <span class="text-white">${contextSpool.job}</span>
                                </div>
                                <div class="flex justify-between border-b border-white/5 pb-2">
                                    <span class="text-gray-500 uppercase">Número:</span>
                                    <span class="text-white">${contextSpool.splnbr}</span>
                                </div>
                                <div class="flex justify-between border-b border-white/5 pb-2">
                                    <span class="text-gray-500 uppercase">Estado:</span>
                                    <span class="px-2 bg-accent/20 text-accent rounded">${contextSpool.status}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500 uppercase">Páginas:</span>
                                    <span class="text-white font-bold">${contextSpool.pages}</span>
                                </div>
                            </div>
                        `,
                        background: 'var(--bg-panel)', color: 'var(--text-main)',
                        confirmButtonText: 'ENTENDIDO',
                        confirmButtonColor: 'var(--accent)'
                    });
                    break;
                case 'add-fav':
                    toggleFavorite(contextSpool.user);
                    Swal.fire({
                        toast: true, position: 'bottom-end', icon: 'success',
                        title: favorites.includes(contextSpool.user) ? `Usuario ${contextSpool.user} añadido a favoritos` : `Usuario ${contextSpool.user} eliminado de favoritos`,
                        showConfirmButton: false, timer: 3000, timerProgressBar: true,
                        background: 'var(--bg-panel)', color: 'var(--text-main)'
                    });
                    break;
            }
        }

        // ===== Gestión de spools (HLDSPLF / RLSSPLF / CHGSPLFA) =====
        const SPOOL_ACTION_LABELS = {
            hold:    { title: '¿Mantener spool?',     running: 'Manteniendo spool...',     ok: 'Spool en mantenimiento (HLD)' },
            release: { title: '¿Soltar spool?',       running: 'Soltando spool...',        ok: 'Spool liberado (RLS)' },
            reprint: { title: '¿Reimprimir spool?',   running: 'Reimprimiendo spool...',   ok: 'Spool listo para reimprimir' },
            change:  { title: '¿Cambiar propiedades?', running: 'Aplicando cambios...',    ok: 'Propiedades actualizadas' }
        };

        async function execSpoolAction(sp, action, params = {}) {
            try {
                const res = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'spool_action', sp_action: action, file: sp.name, job: sp.job, number: sp.splnbr, params })
                });
                return await res.json();
            } catch (e) {
                return { success: false, message: 'Error de conexión con el servidor' };
            }
        }

        async function confirmSpoolAction(sp, action) {
            const meta = SPOOL_ACTION_LABELS[action];
            if (!meta) return;
            const confirm = await Swal.fire({
                title: meta.title,
                html: `<div class="text-sm text-gray-400 space-y-1 mt-2 font-mono">
                        <div class="flex justify-between"><span class="uppercase tracking-widest text-gray-500">Archivo</span><span class="text-white font-bold">${sp.name}</span></div>
                        <div class="flex justify-between"><span class="uppercase tracking-widest text-gray-500">Job</span><span class="text-white font-bold">${sp.job}</span></div>
                        <div class="flex justify-between"><span class="uppercase tracking-widest text-gray-500">Nº</span><span class="text-white font-bold">${sp.splnbr}</span></div>
                       </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'CONFIRMAR',
                confirmButtonColor: 'var(--accent)',
                cancelButtonText: 'CANCELAR',
                background: 'var(--bg-panel)', color: 'var(--text-main)'
            });
            if (!confirm.isConfirmed) return;
            await runSpoolAction(sp, action);
        }

        async function runSpoolAction(sp, action, params = {}) {
            const meta = SPOOL_ACTION_LABELS[action] || { running: 'Procesando spool...', ok: 'Operación completada' };
            Swal.fire({ title: meta.running, allowOutsideClick: false, didOpen: () => Swal.showLoading(), background: 'var(--bg-panel)', color: 'var(--text-main)' });
            const res = await execSpoolAction(sp, action, params);
            Swal.close();
            if (res.success) {
                Swal.fire({ icon: 'success', title: meta.ok, text: `${sp.name} · ${sp.job}/${sp.splnbr}`, timer: 1800, showConfirmButton: false, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                refreshSpoolList();
            } else {
                Swal.fire({ icon: 'error', title: 'Error AS/400', text: res.message || 'No se pudo completar la operación', background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        const SPOOL_STATUS_MAP = {
            RDY: '*READY', HLD: '*HELD', HELD: '*HELD', WTR: '*READY', SAV: '*READY', PRT: '*READY'
        };

        function openChangePropsModal(sp) {
            document.getElementById('cp-spool-name').innerText = `${sp.name} · ${sp.job}/${sp.splnbr}`;
            document.getElementById('cp-status').value = SPOOL_STATUS_MAP[(sp.status || '').toUpperCase()] || '';
            document.getElementById('cp-outq').value = '';
            document.getElementById('cp-forms').value = '';
            document.getElementById('cp-copies').value = '';
            document.getElementById('cp-prty').value = '';
            document.getElementById('cp-usrdata').value = '';
            document.getElementById('cp-modal').classList.remove('hidden');
        }

        function closeChangePropsModal() {
            document.getElementById('cp-modal').classList.add('hidden');
        }

        async function applyChangeProps() {
            const sp = contextSpool;
            if (!sp) return;
            const params = {};
            const outq = document.getElementById('cp-outq').value.trim();
            const forms = document.getElementById('cp-forms').value.trim();
            const copies = document.getElementById('cp-copies').value.trim();
            const prty = document.getElementById('cp-prty').value.trim();
            const usrdata = document.getElementById('cp-usrdata').value.trim();
            const status = document.getElementById('cp-status').value.trim();
            if (outq) params.outq = outq;
            if (forms) params.forms = forms;
            if (copies) params.copies = parseInt(copies, 10);
            if (prty) params.prty = parseInt(prty, 10);
            if (usrdata) params.usrdata = usrdata;
            const mappedStatus = SPOOL_STATUS_MAP[(sp.status || '').toUpperCase()] || '';
            if (status && status !== mappedStatus) params.status = status;

            if (Object.keys(params).length === 0) {
                Swal.fire({ icon: 'warning', title: 'Sin cambios', text: 'No se especificó ninguna propiedad a modificar', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                return;
            }
            closeChangePropsModal();
            await runSpoolAction(sp, 'change', params);
        }

        async function bulkSpoolAction(action) {
            const ids = Array.from(selectedSpools);
            const list = window.lastSpoolList || [];
            const targets = list.filter(s => ids.includes(`${s.name}_${s.job}_${s.splnbr}`));
            if (targets.length === 0) return;
            const meta = SPOOL_ACTION_LABELS[action];
            Swal.fire({ title: `Procesando ${targets.length} spool(s)...`, allowOutsideClick: false, didOpen: () => Swal.showLoading(), background: 'var(--bg-panel)', color: 'var(--text-main)' });
            let ok = 0, failed = 0, firstError = '';
            for (const sp of targets) {
                const res = await execSpoolAction(sp, action);
                if (res.success) ok++; else { failed++; if (!firstError) firstError = res.message; }
            }
            Swal.close();
            clearBulk();
            if (failed === 0) {
                Swal.fire({ icon: 'success', title: `${ok} spool(s) procesados`, text: meta.ok, timer: 1800, showConfirmButton: false, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            } else {
                Swal.fire({ icon: 'warning', title: `${ok} OK · ${failed} con error`, text: firstError || 'Algunos spools no pudieron procesarse', background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
            refreshSpoolList();
        }

        function sortGrid(colIdx) {
            if (!currentParsedData || !currentParsedData.data) return;
            
            if(currentSortCol === colIdx) {
                currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortCol = colIdx;
                currentSortDir = 'asc';
            }
            
            currentParsedData.data.sort((a, b) => {
                const v1 = (a[colIdx] || '').toString().trim();
                const v2 = (b[colIdx] || '').toString().trim();
                const n1 = parseFloat(v1.replace(/,/g, ''));
                const n2 = parseFloat(v2.replace(/,/g, ''));
                
                let res;
                if (!isNaN(n1) && !isNaN(n2)) res = n1 - n2;
                else res = v1.localeCompare(v2);
                
                return currentSortDir === 'asc' ? res : -res;
            });
            
            renderPreview(currentParsedData);
        }

        const EXPORT_TEMPLATES = <?= json_encode($pdfTemplateEntries, JSON_PRETTY_PRINT) ?>;

        async function showExportWizard(title = 'Ajuste de Exportación') {
            if (!window.glrSharedTemplates) {
                try {
                    const response = await fetch('process.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'load_templates' })
                    });
                    const result = await response.json();
                    if(result.success && result.templates) {
                        window.glrSharedTemplates = result.templates;
                    }
                } catch(e) {}
            }

            const { value: result } = await Swal.fire({
                title: `<span class="text-accent tracking-[0.2em] uppercase font-black">${title}</span>`,
                html: `
                    <div class="text-left space-y-6 pt-4">
                        <div class="bg-accent/5 border border-accent/20 p-5 rounded-[1.5rem] flex items-start gap-4">
                            <svg class="w-6 h-6 text-accent shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <p class="text-[12px] text-gray-300 leading-relaxed font-bold uppercase tracking-wider">Se generará el documento con el formato estándar profesional por defecto.</p>
                        </div>

                        <div class="border border-white/5 rounded-3xl overflow-hidden bg-black/20">
                            <button id="toggle-advanced-export" type="button" class="w-full flex items-center justify-between px-6 py-5 hover:bg-white/5 transition-all group">
                                <span class="text-[10px] font-black text-gray-500 group-hover:text-accent tracking-[0.3em] uppercase transition-colors">Personalización Avanzada</span>
                                <svg id="arrow-advanced" class="w-4 h-4 text-gray-500 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            
                            <div id="advanced-export-container" class="hidden p-8 space-y-6 border-t border-white/5 bg-black/40 animate-fade-in">
                                <div class="space-y-3">
                                    <label class="text-[9px] font-black text-accent/60 uppercase tracking-[0.3em] ml-1">Plantilla de Cortes (Filas/Columnas)</label>
                                    <div class="relative">
                                        <select id="swal-structure-template" class="w-full bg-black/60 border border-white/10 text-white rounded-2xl p-4 text-[11px] font-bold focus:border-accent outline-none appearance-none cursor-pointer">
                                            <option value="default">Por defecto (Sin cortes adicionales)</option>
                                            ${Object.keys(window.glrSharedTemplates || {}).map(name => `<option value="${name}">${name}</option>`).join('')}
                                        </select>
                                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4 4 4-4"></path></svg>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <label class="text-[9px] font-black text-accent/60 uppercase tracking-[0.3em] ml-1">Diseño de Plantilla</label>
                                    <div class="relative">
                                        <select id="swal-template" class="w-full bg-black/60 border border-white/10 text-white rounded-2xl p-4 text-[11px] font-bold focus:border-accent outline-none appearance-none cursor-pointer">
                                            ${Object.entries(EXPORT_TEMPLATES).map(([id, label]) => `<option value="${id}">${label}</option>`).join('')}
                                        </select>
                                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4 4 4-4"></path></svg>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-3">
                                        <label class="text-[9px] font-black text-accent/60 uppercase tracking-[0.3em] ml-1">Estilo de Sello</label>
                                        <select id="swal-stamp-style" class="w-full bg-black/60 border border-white/10 text-white rounded-2xl p-4 text-[11px] font-bold focus:border-accent outline-none appearance-none cursor-pointer">
                                            <option value="none">Sin Sello</option>
                                            <option value="classic">Círculo Oficial</option>
                                            <option value="square">Recuadro Industrial</option>
                                            <option value="ribbon">Cinta de Seguridad</option>
                                        </select>
                                    </div>
                                    <div class="space-y-3">
                                        <label class="text-[9px] font-black text-accent/60 uppercase tracking-[0.3em] ml-1">Leyenda del Sello</label>
                                        <input id="swal-stamp-text" type="text" value="OFICIAL" class="w-full bg-black/60 border border-white/10 text-white rounded-2xl p-4 text-[11px] font-bold focus:border-accent outline-none uppercase tracking-widest">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'INICIAR EXPORTACIÓN',
                cancelButtonText: 'CANCELAR',
                background: 'var(--bg-panel)',
                color: 'var(--text-main)',
                confirmButtonColor: 'var(--accent)',
                customClass: {
                    popup: 'rounded-[3rem] border border-white/10 shadow-premium max-w-lg',
                    confirmButton: 'px-10 py-5 rounded-2xl font-black text-[11px] tracking-[0.2em] shadow-lg shadow-accent/20',
                    cancelButton: 'px-10 py-5 rounded-2xl font-black text-[11px] tracking-[0.2em] text-gray-500'
                },
                didOpen: () => {
                    const btn = document.getElementById('toggle-advanced-export');
                    const container = document.getElementById('advanced-export-container');
                    const arrow = document.getElementById('arrow-advanced');
                    if(btn && container) {
                        btn.onclick = () => {
                            container.classList.toggle('hidden');
                            arrow.classList.toggle('rotate-180');
                        };
                    }

                    const styleSelect = document.getElementById('swal-stamp-style');
                    const textInput = document.getElementById('swal-stamp-text');
                    if(styleSelect && textInput) {
                        styleSelect.addEventListener('change', () => {
                            textInput.disabled = (styleSelect.value === 'none');
                            textInput.style.opacity = (styleSelect.value === 'none') ? '0.3' : '1';
                        });
                        // Init state
                        textInput.disabled = (styleSelect.value === 'none');
                        textInput.style.opacity = (styleSelect.value === 'none') ? '0.3' : '1';
                    }
                },
                preConfirm: () => {
                    return {
                        structureTemplate: document.getElementById('swal-structure-template') ? document.getElementById('swal-structure-template').value : 'default',
                        template: document.getElementById('swal-template').value,
                        stampStyle: document.getElementById('swal-stamp-style').value,
                        stampText: document.getElementById('swal-stamp-text').value.toUpperCase() || 'OFICIAL'
                    }
                }
            });

            return result || null;
        }



                // Construye el payload para PDF/Impresión: siempre las líneas RAW originales del
        // AS/400 (fiel al spool, ignorando cortes de fila/columna del editor).
        function buildRawExportPayload() {
            let lines = (currentRawLines && currentRawLines.length)
                ? currentRawLines
                : (currentParsedData && currentParsedData.data
                    ? currentParsedData.data.map(r => r[0]).filter(l => l !== undefined && l !== null)
                    : []);
            return { headers: [''], data: lines.map(l => [String(l)]) };
        }

        async function exportData(type) {
            if (!currentParsedData) return alert('Seleccione un reporte primero.');
            
            let pdfParams = { template: 'default', stampText: 'OFICIAL', stampStyle: 'classic' };

            if (type === 'pdf') {
                const wizard = await showExportWizard('Asistente de Exportación');
                if (!wizard) return;
                pdfParams = wizard;
            }

            const loadingTitles = {
                'pdf': 'Generando PDF Oficial...',
                'excel': 'Preparando Hoja de Cálculo...',
                'word': 'Construyendo Documento Word...',
                'txt': 'Extrayendo Texto Plano...'
            };

            const loadingSub = {
                'pdf': 'Ensamblando páginas y aplicando sellos...',
                'excel': 'Aplicando reglas de resaltado inteligente...',
                'word': 'Formateando estructura de oficina...',
                'txt': 'Limpiando biffer de impresión...'
            };

            Swal.fire({
                title: loadingTitles[type] || 'Exportando...',
                text: loadingSub[type] || 'Por favor espere un momento',
                allowOutsideClick: false,
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                didOpen: () => { Swal.showLoading() }
            });
            
            try {
                let exportPayload = currentParsedData;
                // PDF: exportar SIEMPRE las líneas crudas del spool (fiel al AS/400), sin cortes.
                if (type === 'pdf') {
                    exportPayload = buildRawExportPayload();
                } else if (pdfParams && pdfParams.structureTemplate && pdfParams.structureTemplate !== 'default'
                    && currentRawLines && currentRawLines.length > 0) {
                    const templateData = window.glrSharedTemplates ? window.glrSharedTemplates[pdfParams.structureTemplate] : null;
                    if (templateData) {
                        exportPayload = parseRawLinesWithTemplate(currentRawLines, templateData);
                    }
                }
                
                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'export', 
                        type: type, 
                        data: exportPayload,
                        styleRules: window.styleRules || [],
                        smartHighlight: window.smartHighlightActive,
                        pdfTemplate: pdfParams.template || 'default',
                        pdfStampText: pdfParams.stampText || 'OFICIAL',
                        pdfStampStyle: pdfParams.stampStyle || 'classic'
                    })
                });
                
                const result = await response.json();
                Swal.close();

                if (result.success) {
                    window.location.href = `download.php?file=${result.file}&name=${result.name}`;
                } else {
                    Swal.fire('Error de Exportación', result.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error del Sistema', err.message, 'error');
            }
        }

        async function printReport() {
            if (!currentParsedData) return alert('Seleccione un reporte primero.');
            
            const wizard = await showExportWizard('Homologar Impresión');
            if (!wizard) return;

            Swal.fire({
                title: 'Preparando Impresión...',
                text: 'Generando formato profesional con sellos de autenticidad',
                allowOutsideClick: false,
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                didOpen: () => { Swal.showLoading() }
            });

            try {
                // Impresión: usar las líneas RAW originales del spool (fiel al AS/400, sin cortes).
                let exportPayload = buildRawExportPayload();

                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'export', 
                        type: 'print_html', 
                        data: exportPayload,
                        pdfTemplate: wizard.template,
                        pdfStampText: wizard.stampText,
                        pdfStampStyle: wizard.stampStyle
                    })
                });

                
                const result = await response.json();
                if (result.success) {
                    Swal.close();
                    let printIframe = document.getElementById('glr-print-iframe');
                    if (printIframe) printIframe.remove();
                    printIframe = document.createElement('iframe');
                    printIframe.id = 'glr-print-iframe';
                    printIframe.style.position = 'fixed';
                    printIframe.style.right = '1000%';
                    printIframe.style.visibility = 'hidden';
                    document.body.appendChild(printIframe);

                    const doc = printIframe.contentWindow.document;
                    doc.open();
                    doc.write(result.html);
                    doc.close();

                    setTimeout(() => {
                        printIframe.contentWindow.focus();
                        printIframe.contentWindow.print();
                    }, 500);
                } else {
                    Swal.fire('Error', result.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error', 'No se pudo generar el formato de impresión.', 'error');
            }
        }

        // --- FUNCIONES DE PERFIL ---
        async function fetchUserProfileName() {
            const cachedName = localStorage.getItem('glr_user_name_' + "<?= $_SESSION['as400_session']['user_id'] ?>");
            if(cachedName) {
                document.getElementById('profile-user-name').innerText = cachedName;
                return;
            }

            try {
                const response = await fetch('process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_user_info' })
                });
                const result = await response.json();
                if (result.success && result.description) {
                    document.getElementById('profile-user-name').innerText = result.description;
                    localStorage.setItem('glr_user_name_' + "<?= $_SESSION['as400_session']['user_id'] ?>", result.description);
                }
            } catch (error) {}
        }

        // Initialize
        if (<?php echo $isLoggedIn ? 'true' : 'false'; ?>) {
            window.addEventListener('load', () => {
                refreshSpoolList();
                fetchUserProfileName();
            });
        }

        // --- KEYBOARD SHORTCUTS (TERMINAL FEEL) ---
        window.addEventListener('keydown', e => {

            // Ctrl+M para Maximizar
            if(e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                toggleExpandPreview();
            }
            // Navegación con flechas en la tabla
            if(e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                const active = document.activeElement;
                if(active && active.tagName !== 'INPUT' && active.tagName !== 'TEXTAREA') {
                    // Navegar entre filas de la tabla si no estamos escribiendo
                }
            }
        });

        window.currentZoom = 1.0;
        function changeZoom(delta) {
            window.currentZoom += delta;
            // Limites mas amplios y seguros
            if (window.currentZoom < 0.6) window.currentZoom = 0.6;
            if (window.currentZoom > 2.0) window.currentZoom = 2.0;
            
            // ESCALAMOS EL CONTENEDOR RAIZ para que sidebar y main crezcan juntos
            // Regresamos a transform: scale() pero con optimización de calidad.
            const wrapper = document.getElementById('app-wrapper');
            if(wrapper) {
                wrapper.style.zoom = ''; // Limpiamos zoom previo
                wrapper.style.transform = `scale(${window.currentZoom})`;
                wrapper.style.transformOrigin = 'top left';
                wrapper.style.width = `${(100 / window.currentZoom)}%`;
                wrapper.style.height = `${(100 / window.currentZoom)}%`;
            }
            
            const label = document.getElementById('zoom-label');
            if(label) label.innerText = Math.round(window.currentZoom * 100) + '%';
            
            // Re-render splits in advanced editor to correct ruler coordinates visually if zoomed
            if (!document.getElementById('advanced-editor-modal').classList.contains('hidden')) {
                renderSplits();
            }
        }

        // --- SISTEMA DE ACTUALIZACION INTELIGENTE ---
        let updaterAutoCheck = true;

        // --- COMENTARIOS / IDEAS (feedback a GitHub) ---
        function openFeedback() {
            document.getElementById('feedback-modal').classList.remove('hidden');
            document.getElementById('feedback-modal').classList.add('animate-fade-in-up');
            loadFeedbackStatus();
        }

        function closeFeedback() {
            document.getElementById('feedback-modal').classList.add('hidden');
        }

        async function feedbackFetch(action, extra = {}) {
            const res = await fetch('process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action }, extra))
            });
            return await res.json();
        }

        async function loadFeedbackStatus() {
            try {
                const res = await feedbackFetch('feedback_status');
                const warn = document.getElementById('feedback-config-warn');
                const ok = document.getElementById('feedback-config-ok');
                if (res && res.success && res.configured) {
                    warn.classList.add('hidden');
                    ok.classList.remove('hidden');
                    document.getElementById('feedback-target').innerText = `${res.owner}/${res.repo}`;
                    // Prefill los campos del administrador (una vez) para que solo falte pegar el token
                    if (res.owner) {
                        const o = document.getElementById('fb-owner');
                        if (o && !o.value.trim()) o.value = res.owner;
                        const r = document.getElementById('fb-repo');
                        if (r && !r.value.trim()) r.value = res.repo;
                    }
                } else {
                    ok.classList.add('hidden');
                    warn.classList.remove('hidden');
                    // Prefill owner/repo desde la config por defecto del servidor
                    if (res && res.owner && res.repo) {
                        const o = document.getElementById('fb-owner');
                        if (o && !o.value.trim()) o.value = res.owner;
                        const r = document.getElementById('fb-repo');
                        if (r && !r.value.trim()) r.value = res.repo;
                    }
                }
            } catch (e) { /* silencioso */ }
        }

        async function saveFeedbackConfig() {
            const owner = document.getElementById('fb-owner').value.trim();
            const repo = document.getElementById('fb-repo').value.trim();
            const token = document.getElementById('fb-token').value.trim();
            if (!owner || !repo || !token) {
                Swal.fire({ icon: 'warning', title: 'Campos incompletos', text: 'Completa Owner, Repo y Token.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                return;
            }
            let payload = { owner, repo, token };
            const ck = await feedbackFetch('check_gatekeeper');
            if (ck && ck.required) {
                const pwd = await Swal.fire({ title: 'Acceso de Administrador', text: 'Ingrese la contraseña del Gatekeeper:', input: 'password', inputAttributes: { autocapitalize: 'off' }, showCancelButton: true, confirmButtonText: 'AUTORIZAR', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                if (!pwd.isConfirmed || !pwd.value) return;
                payload.password = pwd.value;
            }
            Swal.fire({ title: 'Guardando configuración...', allowOutsideClick: false, didOpen: () => Swal.showLoading(), background: 'var(--bg-panel)', color: 'var(--text-main)' });
            const res = await feedbackFetch('save_feedback_config', payload);
            Swal.close();
            if (res && res.success) {
                document.getElementById('fb-token').value = '';
                Swal.fire({ icon: 'success', title: 'Configuración guardada', text: 'El token quedó almacenado en config/feedback.json.', timer: 1800, showConfirmButton: false, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                loadFeedbackStatus();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'No se pudo guardar la configuración', background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        async function submitFeedback() {
            const category = document.getElementById('fb-category').value;
            const title = document.getElementById('fb-title').value.trim();
            const message = document.getElementById('fb-message').value.trim();
            if (!title || !message) {
                Swal.fire({ icon: 'warning', title: 'Campos incompletos', text: 'El título y el mensaje son obligatorios.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                return;
            }
            Swal.fire({ title: 'Enviando a GitHub...', allowOutsideClick: false, didOpen: () => Swal.showLoading(), background: 'var(--bg-panel)', color: 'var(--text-main)' });
            const res = await feedbackFetch('submit_feedback', { category, title, message });
            Swal.close();
            if (res && res.success) {
                document.getElementById('fb-title').value = '';
                document.getElementById('fb-message').value = '';
                Swal.fire({
                    icon: 'success', title: '¡Gracias! Tu comentario fue enviado',
                    html: res.issue_url ? `<a href="${res.issue_url}" target="_blank" rel="noopener" style="color:var(--accent);font-weight:bold;text-decoration:underline">Ver el issue en GitHub</a>` : '',
                    background: 'var(--bg-panel)', color: 'var(--text-main)'
                });
            } else {
                Swal.fire({ icon: 'error', title: 'No se pudo enviar', text: (res && res.message) || 'Ocurrió un error', background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        document.addEventListener('input', function (e) {
            if (e.target && e.target.id === 'fb-message') {
                document.getElementById('fb-count').innerText = e.target.value.length + ' / 10000';
            }
        });

        function openUpdater() {
            document.getElementById('updater-modal').classList.remove('hidden');
            document.getElementById('updater-modal').classList.add('animate-fade-in-up');
            loadUpdaterStatus();
        }

        function closeUpdater() {
            document.getElementById('updater-modal').classList.add('hidden');
        }

        async function updaterFetch(action, extra = {}) {
            const res = await fetch('process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action }, extra))
            });
            return await res.json();
        }

        async function updaterAdminPayload() {
            const ck = await updaterFetch('check_gatekeeper');
            if (!ck || !ck.required) return {};
            const pwd = await Swal.fire({
                title: 'Acceso de Administrador',
                text: 'Ingrese la contraseña del Gatekeeper:',
                input: 'password',
                inputAttributes: { autocomplete: 'current-password' },
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                confirmButtonColor: 'var(--accent)', confirmButtonText: 'Autorizar',
                showCancelButton: true
            });
            if (!pwd.isConfirmed || !pwd.value) return null;
            return { password: pwd.value };
        }

        async function loadUpdaterStatus() {
            try {
                const data = await updaterFetch('updater_status');
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                    return;
                }
                document.getElementById('upd-local').textContent = data.local || '—';
                document.getElementById('upd-remote').textContent = data.remote || '—';
                document.getElementById('upd-last-check').textContent = data.last_check ? new Date(data.last_check).toLocaleString() : 'Nunca';
                document.getElementById('upd-last-applied').textContent = data.last_applied ? (data.last_applied_version ? 'v' + data.last_applied_version + ' — ' : '') + new Date(data.last_applied).toLocaleString() : 'Ninguna';
                document.getElementById('upd-changelog').textContent = data.changelog || 'Sin notas disponibles.';
                document.getElementById('upd-repo').value = data.repo || '';
                document.getElementById('upd-branch').value = data.branch || 'main';
                updaterAutoCheck = !!data.auto_check;
                renderUpdaterAutoToggle();
                const applyBtn = document.getElementById('upd-apply-btn');
                applyBtn.style.opacity = data.available ? '1' : '0.35';
                applyBtn.style.pointerEvents = data.available ? 'auto' : 'none';
                applyBtn.textContent = data.available ? ('ACTUALIZAR A v' + data.remote) : 'ACTUALIZAR';
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        function renderUpdaterAutoToggle() {
            const knob = document.getElementById('upd-auto-knob');
            const btn = document.getElementById('upd-auto-toggle');
            if (updaterAutoCheck) {
                btn.classList.add('bg-accent', 'border-accent/40');
                knob.classList.remove('bg-gray-300'); knob.classList.add('bg-white'); knob.style.transform = 'translateX(24px)';
            } else {
                btn.classList.remove('bg-accent', 'border-accent/40');
                knob.classList.add('bg-gray-300'); knob.classList.remove('bg-white'); knob.style.transform = 'translateX(4px)';
            }
        }

        function toggleUpdaterAuto() {
            updaterAutoCheck = !updaterAutoCheck;
            renderUpdaterAutoToggle();
        }

        async function runUpdateCheck() {
            Swal.fire({
                title: 'Buscando Mejoras...',
                text: 'Consultando GitHub...',
                allowOutsideClick: false,
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                didOpen: () => { Swal.showLoading() }
            });
            try {
                const data = await updaterFetch('check_update');
                Swal.close();
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Fallo', text: data.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                    return;
                }
                await loadUpdaterStatus();
                if (data.available && data.release_ready) {
                    const c = await Swal.fire({
                        title: '¡Versión v' + data.remote + ' disponible!',
                        html: '<p style="font-size:14px;line-height:1.6;max-height:200px;overflow-y:auto;white-space:pre-wrap">' + (data.changelog ? data.changelog : 'Mejoras y correcciones.') + '</p>',
                        icon: 'info', showCancelButton: true, confirmButtonText: 'Actualizar ahora', cancelButtonText: 'Después',
                        background: 'var(--bg-panel)', color: 'var(--text-main)', confirmButtonColor: 'var(--accent)'
                    });
                    if (c.isConfirmed) runApplyUpdate();
                } else if (data.available && !data.release_ready) {
                    Swal.fire({ icon: 'info', title: 'Publicación en preparación', text: 'La versión v' + data.remote + ' aún no tiene paquete de actualización disponible.', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                } else {
                    Swal.fire({ icon: 'success', title: 'Actualizado', text: 'Ya tienes la última versión (v' + data.local + ').', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            } catch (e) {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        async function runApplyUpdate() {
            const ap = await updaterAdminPayload();
            if (ap === null) return;
            const confirm = await Swal.fire({
                title: '¿Actualizar ahora?',
                text: 'El código se respaldará en backups/ antes de aplicar. Se recomienda cerrar otras pestañas.',
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, actualizar', cancelButtonText: 'Cancelar',
                background: 'var(--bg-panel)', color: 'var(--text-main)', confirmButtonColor: 'var(--accent)'
            });
            if (!confirm.isConfirmed) return;
            Swal.fire({
                title: 'Actualizando...',
                text: 'Descargando y aplicando el paquete...',
                allowOutsideClick: false,
                background: 'var(--bg-panel)', color: 'var(--text-main)',
                didOpen: () => { Swal.showLoading() }
            });
            try {
                const data = await updaterFetch('apply_update', ap);
                Swal.close();
                if (data.success) {
                    await Swal.fire({ icon: 'success', title: '¡Listo!', text: data.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                    window.location.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            } catch (e) {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        async function runRollbackUpdate() {
            const ap = await updaterAdminPayload();
            if (ap === null) return;
            const confirm = await Swal.fire({
                title: '¿Revertir la última actualización?',
                text: 'Se restaurará el código anterior desde el respaldo más reciente.',
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, revertir', cancelButtonText: 'Cancelar',
                background: 'var(--bg-panel)', color: 'var(--text-main)', confirmButtonColor: 'var(--accent)'
            });
            if (!confirm.isConfirmed) return;
            Swal.fire({ title: 'Revertiendo...', allowOutsideClick: false, background: 'var(--bg-panel)', color: 'var(--text-main)', didOpen: () => { Swal.showLoading() } });
            try {
                const data = await updaterFetch('rollback_update', ap);
                Swal.close();
                if (data.success) {
                    await Swal.fire({ icon: 'success', title: 'Reversión completada', text: data.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                    window.location.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            } catch (e) {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        async function saveUpdaterConfig() {
            const ap = await updaterAdminPayload();
            if (ap === null) return;
            const repo = document.getElementById('upd-repo').value.trim();
            const branch = document.getElementById('upd-branch').value.trim() || 'main';
            if (!/^[A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+$/.test(repo)) {
                Swal.fire({ icon: 'error', title: 'Repositorio inválido', text: 'Formato: usuario/repositorio', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                return;
            }
            Swal.fire({ title: 'Guardando...', allowOutsideClick: false, background: 'var(--bg-panel)', color: 'var(--text-main)', didOpen: () => { Swal.showLoading() } });
            try {
                const data = await updaterFetch('save_updater_config', Object.assign({ repo, branch, auto_check: updaterAutoCheck }, ap));
                Swal.close();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Configuración guardada', background: 'var(--bg-panel)', color: 'var(--text-main)' });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
                }
            } catch (e) {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, background: 'var(--bg-panel)', color: 'var(--text-main)' });
            }
        }

        function autoCheckUpdates() {
            updaterFetch('updater_status').then(s => {
                if (!s.success || !s.auto_check) return;
                const last = s.last_check ? new Date(s.last_check).getTime() : 0;
                if (Date.now() - last < 24 * 3600 * 1000) return;
                return updaterFetch('check_update').then(data => {
                    if (data.success && data.available && data.release_ready) {
                        Swal.fire({
                            title: '¡Nueva versión v' + data.remote + ' disponible!',
                            icon: 'info', showCancelButton: true, confirmButtonText: 'Ver', cancelButtonText: 'Después',
                            background: 'var(--bg-panel)', color: 'var(--text-main)', confirmButtonColor: 'var(--accent)'
                        }).then(r => { if (r.isConfirmed) openUpdater(); });
                    }
                });
            }).catch(() => {});
        }

        autoCheckUpdates();

        function toggleExpandPreview() {
            const sidebar = document.getElementById('main-sidebar');
            const queue = document.getElementById('spool-queue-panel');
            const panel = document.getElementById('preview-panel');
            const btn = document.getElementById('expand-btn');
            
            const isHidden = sidebar.classList.contains('lg:hidden');
            
            if (isHidden) {
                // Restaurar
                sidebar.classList.remove('lg:hidden', 'hidden');
                queue.classList.remove('lg:hidden', 'hidden');
                panel.classList.remove('fixed', 'inset-4', 'z-[100]', 'w-auto', 'h-auto');
                btn.innerHTML = `<svg id="expand-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg> EXPANDIR`;
            } else {
                // Maximizar
                sidebar.classList.add('lg:hidden', 'hidden');
                queue.classList.add('lg:hidden', 'hidden');
                panel.classList.add('fixed', 'inset-4', 'z-[100]', 'w-auto', 'h-auto');
                btn.innerHTML = `<svg id="expand-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg> RESTAURAR`;
            }
        }

        function showSystemCredits() {
            // --- UPGRADE: Versión cinematográfica con hello friend. ---
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth; canvas.height = window.innerHeight;
            canvas.style.position = 'fixed'; canvas.style.top = '0'; canvas.style.left = '0';
            canvas.style.zIndex = '100000'; canvas.style.background = '#000';
            document.body.appendChild(canvas);

            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const master = audioCtx.createGain();
            const compressor = audioCtx.createDynamicsCompressor();
            master.connect(compressor); compressor.connect(audioCtx.destination);
            master.gain.setValueAtTime(0, audioCtx.currentTime);

            const playDistNode = (freq, time, dur, gain=0.2) => {
                const osc = audioCtx.createOscillator(), g = audioCtx.createGain(), distort = audioCtx.createWaveShaper(), filter = audioCtx.createBiquadFilter();
                function makeDistortionCurve(amount) { const k = typeof amount==='number'?amount:50, n=44100, c=new Float32Array(n); for(let i=0;i<n;++i){const x=i*2/n-1;c[i]=(3+k)*x*20*(Math.PI/180)/(Math.PI+k*Math.abs(x));} return c; }
                distort.curve = makeDistortionCurve(400); osc.type = 'sawtooth'; osc.frequency.setValueAtTime(freq, time);
                filter.type = 'lowpass'; filter.frequency.setValueAtTime(800, time);
                g.gain.setValueAtTime(0,time); g.gain.linearRampToValueAtTime(gain,time+0.05); g.gain.exponentialRampToValueAtTime(0.001,time+dur);
                osc.connect(distort); distort.connect(filter); filter.connect(g); g.connect(master); osc.start(time); osc.stop(time+dur);
            };

            const speak = (text) => {
                const audio = new Audio('app/assets/audio/hello.mp3');
                audio.volume = 1;
                audio.play().catch(() => { const u=new SpeechSynthesisUtterance(text); u.pitch=0.2; u.rate=0.75; u.volume=1; speechSynthesis.speak(u); });
            };

            let frame=0, phase='hello', introText='', introIndex=0, nextNoteTime=audioCtx.currentTime, step=0;
            const audioLoop = setInterval(() => {
                while(nextNoteTime < audioCtx.currentTime+0.1) {
                    const t=nextNoteTime;
                    if(phase==='main') { if(step%4===0){const kOsc=audioCtx.createOscillator();const kg=audioCtx.createGain();kOsc.frequency.setValueAtTime(120,t);kOsc.frequency.exponentialRampToValueAtTime(0.01,t+0.5);kg.gain.setValueAtTime(0.5,t);kg.gain.exponentialRampToValueAtTime(0.001,t+0.5);kOsc.connect(kg);kg.connect(master);kOsc.start(t);kOsc.stop(t+0.5);} if(step%2===0)playDistNode(step%8<4?55:52,t,0.4,0.2); if(step%16===12)playDistNode(110,t,0.1,0.1); nextNoteTime+=0.25;
                    } else if(phase==='breach') { if(step%2===0){const kOsc=audioCtx.createOscillator();const kg=audioCtx.createGain();kOsc.frequency.setValueAtTime(90,t);kOsc.frequency.exponentialRampToValueAtTime(0.01,t+0.2);kg.gain.setValueAtTime(0.6,t);kg.gain.exponentialRampToValueAtTime(0.001,t+0.2);kOsc.connect(kg);kg.connect(master);kOsc.start(t);kOsc.stop(t+0.2);} if(step%8===2||step%8===5)playDistNode(880+Math.random()*400,t,0.05,0.1); playDistNode(40+Math.random()*2,t,0.15,0.3); nextNoteTime+=0.15;
                    } else { if(step%16===0)playDistNode(30,t,2,0.1); nextNoteTime+=0.5; }
                    step++;
                }
            }, 100);

            const terminalLines=[], sysCommands=["AS400:> WRKJOB GLR_PROCESS","GATEWAY:> 10.100.5.60 CONNECTED","ROOT:> ACCESS GRANTED","DECRYPTING:> SHA-256 SALT DETECTED","SYSTEM_MSG:> CONTROL_IS_AN_ILLUSION","SPOOL:> RENDER_V2_ACTIVE","STP_BRIDGE:> PIPE_OPENING","XLSX:> BUFFER_WRITING","V5R3:> KERNEL_PANIC_BYPASS"];
            const maskImg=new Image(); maskImg.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cGF0aCBmaWxsPSIjNDQ0IiBkPSJNMjU2IDQ4QzEyMy40IDQ4IDQ4IDEyMy40IDQ4IDI1NnM3NS40IDIwOCAyMDggMjA4IDIwOC03NS40IDIwOC0yMDhTMzg4LjYgNDggMjU2IDQ4em0wIDM4NGMtOTcuMiAwLTE3Ni03OC44LTE3Ni0xNzZTMTU4LjggNzIgMjU2IDcyczE3NiA3OC44IDE3NiAxNzYtNzguOCAxNzYtMTc2IDE3NnoiLz48cGF0aCBmaWxsPSIjNjY2IiBkPSJNMjU2IDMyMEMxNjMuOSAzMjAgODggMjcxLjkgODggMjEyaDMzNmMwIDU5LjktNzUuOSAxMDgtMTY4IDEwOHoiLz48Y2lyY2xlIGN4PSIxOTIiIGN5PSIyMTYiIHI9IjI0IiBmaWxsPSIjMDAwIi8+PGNpcmNsZSBjeD0iMzIwIiBjeT0iMjE2IiByPSIyNCIgZmlsbD0iIzAwMCIvPjwvc3ZnPg==';

            function drawCRT() { const grad=ctx.createRadialGradient(canvas.width/2,canvas.height/2,0,canvas.width/2,canvas.height/2,canvas.width/1.2); grad.addColorStop(0,'rgba(0,0,0,0)'); grad.addColorStop(1,'rgba(0,0,0,0.8)'); ctx.fillStyle=grad; ctx.fillRect(0,0,canvas.width,canvas.height); ctx.globalAlpha=0.15; ctx.fillStyle='#000'; for(let i=0;i<canvas.height;i+=4)ctx.fillRect(0,i,canvas.width,2); ctx.globalAlpha=1; }

            speak("Hello friend.");

            function animate() {
                frame++;
                ctx.fillStyle='#050505'; ctx.fillRect(0,0,canvas.width,canvas.height);
                if(phase==='hello') {
                    ctx.globalAlpha=0.05+Math.sin(frame/20)*0.02; ctx.drawImage(maskImg,canvas.width/2-200,canvas.height/2-200,400,400); ctx.globalAlpha=1;
                    const hTxt="hello friend.";
                    if(frame%15===0&&introIndex<hTxt.length){introText+=hTxt[introIndex];introIndex++;}
                    ctx.font='700 64px "JetBrains Mono"'; ctx.fillStyle='#fff'; ctx.textAlign='center';
                    ctx.fillText(introText+(Math.floor(frame/15)%2===0?"_":""),canvas.width/2,canvas.height/2);
                    if(introIndex===hTxt.length&&frame>350){phase='main';frame=0;master.gain.linearRampToValueAtTime(0.8,audioCtx.currentTime+3);}
                } else if(phase==='main') {
                    ctx.globalAlpha=0.05+Math.sin(frame/20)*0.02; ctx.drawImage(maskImg,canvas.width/2-200,canvas.height/2-200,400,400); ctx.globalAlpha=1;
                    if(frame%6===0)terminalLines.push({t:sysCommands[Math.floor(Math.random()*sysCommands.length)],y:canvas.height+20,o:1,x:40+Math.random()*20});
                    ctx.textAlign='left'; ctx.font='10px "JetBrains Mono"';
                    terminalLines.forEach(l=>{l.y-=3.5;l.o-=0.004;ctx.fillStyle=`rgba(0,255,100,${l.o})`;ctx.fillText("> "+l.t,l.x,l.y);});
                    if(terminalLines.length>80)terminalLines.shift();
                    const credits=[{t:"GABRIEL LÓPEZ REYES",s:100,y:-80,c:"#ff0011",w:"900"},{t:"INGENIERO DE SOPORTE",s:24,y:-15,c:"#ffffff",w:"700"},{t:"CDT TIJUANA · DIGITAL_CORE",s:16,y:35,c:"#888",w:"400"},{t:"< CONTROL_IS_AN_ILLUSION // TRST_NO_1 >",s:20,y:105,c:"#00f3ff",w:"900"}];
                    credits.forEach((c,idx)=>{const start=20+idx*30;if(frame<start)return;const p=Math.min(1,(frame-start)/30);let txt=(p<1)?c.t.split('').map(ch=>ch===' '?' ':String.fromCharCode(33+Math.random()*94)).join(''):c.t;const offX=(p<1||Math.random()>0.985)?Math.random()*30-15:0;ctx.globalAlpha=p;ctx.textAlign='center';ctx.font=`${c.w} ${c.s}px "JetBrains Mono"`;ctx.fillStyle='#ff000088';ctx.fillText(txt,canvas.width/2+6+offX,canvas.height/2+c.y);ctx.fillStyle='#00f3ff88';ctx.fillText(txt,canvas.width/2-6+offX,canvas.height/2+c.y);ctx.fillStyle=c.c;ctx.fillText(txt,canvas.width/2+offX,canvas.height/2+c.y);});
                    if(Math.random()>0.97){ctx.fillStyle=`rgba(255,255,255,${Math.random()*0.1})`;ctx.fillRect(0,Math.random()*canvas.height,canvas.width,Math.random()*100);const sliceY=Math.random()*canvas.height;const sliceH=Math.random()*50;ctx.drawImage(canvas,0,sliceY,canvas.width,sliceH,Math.random()*40-20,sliceY,canvas.width,sliceH);}
                    if(frame>650){phase='transition';frame=0;master.gain.exponentialRampToValueAtTime(0.001,audioCtx.currentTime+1);}
                } else if(phase==='transition') {
                    canvas.style.filter=`blur(${Math.min(50,frame*2)}px) brightness(${1-frame/30})`;
                    if(frame>30){phase='breach';frame=0;canvas.style.filter='none';master.gain.linearRampToValueAtTime(1,audioCtx.currentTime+1);}
                } else if(phase==='breach') {
                    ctx.fillStyle='#0a0000';ctx.fillRect(0,0,canvas.width,canvas.height);
                    ctx.save();ctx.translate(canvas.width/2,canvas.height/2);ctx.rotate(-0.1);ctx.font='900 500px "Outfit"';
                    const glt=Math.sin(frame*0.5)*15;ctx.fillStyle='rgba(255,0,0,0.3)';ctx.fillText("E",glt,0);ctx.fillStyle='rgba(0,243,255,0.3)';ctx.fillText("E",-glt,0);ctx.fillStyle='rgba(255,255,255,0.05)';ctx.fillText("E",0,0);ctx.restore();
                    ctx.font='700 12px "JetBrains Mono"';
                    for(let i=0;i<15;i++){ctx.fillStyle=`rgba(255,0,0,${Math.random()*0.4})`;ctx.fillText(Array(30).fill(0).map(()=>Math.floor(Math.random()*16).toString(16)).join(''),(i*canvas.width/15),(frame*15+i*100)%canvas.height);}
                    const prog=Math.min(1,frame/500);ctx.fillStyle='#222';ctx.fillRect(canvas.width/2-300,canvas.height-150,600,4);ctx.fillStyle='#ff0000';ctx.fillRect(canvas.width/2-300,canvas.height-150,600*prog,4);
                    ctx.font='900 16px "JetBrains Mono"';ctx.fillStyle='#ff0000';ctx.textAlign='center';ctx.fillText(`DELETING_DATABASE_E_CORP_GLOBAL :: ${(prog*100).toFixed(2)}%`,canvas.width/2,canvas.height-170);
                    if(frame%30<15){ctx.font='900 60px "JetBrains Mono"';ctx.fillStyle='#ff0000';ctx.shadowBlur=20;ctx.shadowColor='#ff0000';ctx.fillText("CRITICAL_FAILURE",canvas.width/2,150);ctx.shadowBlur=0;}
                    if(Math.random()>0.85){const h=Math.random()*100;const y=Math.random()*canvas.height;ctx.drawImage(canvas,0,y,canvas.width,h,Math.random()*60-30,y+Math.random()*10-5,canvas.width,h);}
                    ctx.globalAlpha=0.05;for(let i=0;i<10;i++){ctx.fillStyle=Math.random()>0.5?'#fff':'#f00';ctx.fillRect(Math.random()*canvas.width,Math.random()*canvas.height,2,2);}ctx.globalAlpha=1;
                    if(frame>650){phase='main';frame=0;}
                }
                drawCRT();
                if(canvas.parentNode) requestAnimationFrame(animate);
            }

            animate();
            canvas.onclick=()=>{clearInterval(audioLoop);master.gain.exponentialRampToValueAtTime(0.001,audioCtx.currentTime+0.5);setTimeout(()=>{audioCtx.close();canvas.remove();},500);};
            document.addEventListener('keydown',(e)=>{if(e.key==='Escape')canvas.click();},{once:true});
        } // end showSystemCredits

        function toggleOption(opt) {
            const current = (localStorage.getItem('as400_opt_' + opt) === 'true');
            localStorage.setItem('as400_opt_' + opt, !current);
            const btn = document.getElementById(opt === 'sound_alerts' ? 'sound-toggle' : '');
            if(btn) {
                const knob = btn.querySelector('div');
                if(!current) { btn.classList.add('bg-accent'); knob.classList.add('translate-x-6'); }
                else { btn.classList.remove('bg-accent'); knob.classList.remove('translate-x-6'); }
            }
        }

    </script>
<?php endif; ?>

    <script>
        let globalWarpState = { active: false, speed: 0.3, mouseX: 0, mouseY: 0 };
        async function openTechnicalManual() {
            // Detect location of manual (portable vs dev)
            let manualPath = 'MANUAL_WEB.html';
            try {
                const check = await fetch(manualPath, { method: 'HEAD' });
                if (!check.ok) manualPath = '../MANUAL_WEB.html';
            } catch(e) { manualPath = '../MANUAL_WEB.html'; }

            Swal.fire({
                title: 'CENTRO DE DOCUMENTACIÓN Y SOPORTE',
                html: `
                    <div class="flex flex-col h-[75vh]">
                        <!-- TABS -->
                        <div class="flex gap-2 p-1 bg-black/40 rounded-2xl mb-6 border border-white/5">
                            <button onclick="switchDocTab('guia')" id="tab-guia" class="flex-1 py-3 px-4 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all bg-accent text-black shadow-lg shadow-accent/20">Guía de Inicio</button>
                            <button onclick="switchDocTab('web')" id="tab-web" class="flex-1 py-3 px-4 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all text-gray-500 hover:text-white">Manual Usuario</button>
                            <button onclick="switchDocTab('tech')" id="tab-tech" class="flex-1 py-3 px-4 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all text-gray-500 hover:text-white">Ficha Técnica</button>
                        </div>

                        <div id="doc-content" class="flex-1 text-left overflow-y-auto custom-scroll pr-2">
                            <!-- GUIA RAPIDA (DEFAULT) -->
                            <div id="content-guia" class="space-y-6 animate-fade-in">
                                <div class="border-l-4 border-accent pl-4">
                                    <h3 class="text-accent font-black tracking-widest uppercase text-sm mb-2">1. Guía de Inicio Rápido</h3>
                                    <p class="text-gray-400 text-sm leading-relaxed">Este software es <b>Portable</b>. No requiere instalación. Al ejecutar <b>Iniciar_Servidor.bat</b>, se levantará un servidor local seguro y se abrirá la interfaz en modo aplicación.</p>
                                </div>
                                <div class="border-l-4 border-yellow-500 pl-4">
                                    <h3 class="text-yellow-500 font-black tracking-widest uppercase text-sm mb-2">2. Solución de Problemas</h3>
                                    <ul class="text-gray-400 text-sm space-y-2 list-disc ml-4">
                                        <li><b>¿La ventana se cierra rápido?</b> Verifique si el puerto 8181 está siendo usado por otra aplicación.</li>
                                        <li><b>¿Faltan componentes (DLL)?</b> Ejecute el instalador en la carpeta <i>redist</i>.</li>
                                        <li><b>¿Antivirus bloquea?</b> Añada la carpeta de la aplicación a "Exclusiones".</li>
                                    </ul>
                                </div>
                                <div class="bg-black/40 p-6 rounded-3xl border border-white/5 text-center mt-8">
                                    <p class="text-[10px] text-gray-500 uppercase tracking-[0.3em] mb-2">Desarrollado y Firmado por</p>
                                    <p class="text-white font-black text-lg tracking-wider">Ing. Gabriel Lopez Reyes</p>
                                    <p class="signature-premium text-accent mt-1 text-sm tracking-[0.4em]">&lt;GLR\&gt;</p>
                                </div>
                            </div>

                            <!-- MANUAL WEB (IFRAME) -->
                            <div id="content-web" class="hidden h-full w-full animate-fade-in">
                                <iframe src="${manualPath}" class="w-full h-full rounded-2xl border border-white/5 bg-black/20" frameborder="0"></iframe>
                            </div>

                            <!-- DOCS TECNICOS -->
                            <div id="content-tech" class="hidden space-y-6 animate-fade-in font-mono">
                                <div class="p-6 bg-black/40 border border-white/10 rounded-3xl">
                                    <h3 class="text-blue-400 font-black mb-4">SPOOL - EDITOR DE ESTRUCTURA</h3>
                                    <pre class="text-[12px] text-gray-400 leading-relaxed whitespace-pre-wrap">
1. ARQUITECTURA: PHP + JS Puro.
2. COMUNICACIÓN: Comandos CL remotos (CPYSPLF).
3. EXPORTACIÓN: Csv/Excel sanitizado sin inyección de fórmulas.
4. PORTABILIDAD: Binarios PHP nts x86/x64 integrados.
5. COMPATIBILIDAD: Dual-Engine (7.4 para Win 7 / 8.2 para Win 10+).
                                    </pre>
                                </div>
                            </div>
                        </div>
                    </div>
                `,
                width: window.innerWidth > 950 ? '950px' : '95%',
                background: 'var(--bg-panel)',
                color: 'var(--text-main)',
                confirmButtonText: 'SALIR',
                confirmButtonColor: 'var(--accent)',
                showCloseButton: true,
                customClass: {
                    popup: 'rounded-[2.5rem] border border-white/10 shadow-premium'
                }
            });
        }

        window.switchDocTab = function(tab) {
            // Reset all
            ['guia', 'web', 'tech'].forEach(t => {
                const el = document.getElementById('content-' + t);
                const btn = document.getElementById('tab-' + t);
                if(el) el.classList.add('hidden');
                if(btn) {
                    btn.classList.remove('bg-accent', 'text-black', 'shadow-lg', 'shadow-accent/20');
                    btn.classList.add('text-gray-500');
                }
            });

            // Active one
            const activeEl = document.getElementById('content-' + tab);
            const activeBtn = document.getElementById('tab-' + tab);
            if(activeEl) activeEl.classList.remove('hidden');
            if(activeBtn) {
                activeBtn.classList.add('bg-accent', 'text-black', 'shadow-lg', 'shadow-accent/20');
                activeBtn.classList.remove('text-gray-500');
            }

            // Special case for iframe h-full
            const docContent = document.getElementById('doc-content');
            if(docContent) {
                if(tab === 'web') {
                     docContent.style.overflowY = 'hidden';
                } else {
                     docContent.style.overflowY = 'auto';
                }
            }
        };

        // Easter egg trigger - siempre disponible sin importar el estado de login
        let eggClickCount = 0;
        function triggerEgg() {
            eggClickCount++;
            if(eggClickCount === 7) {
                eggClickCount = 0;
                if(typeof showSystemCredits === 'function') showSystemCredits();
            }
        }

        let bioClicks = 0;


        function triggerBioScan() {
            bioClicks++;
            const icon = document.getElementById('core-icon-trigger');
            if(icon) {
                 icon.style.transform = `scale(${1 + (bioClicks * 0.05)}) rotate(${bioClicks * 10}deg)`;
            }
            
            if (bioClicks === 5) {
                bioClicks = 0;
                executeOverloadEffect();
            }
            setTimeout(() => { if(bioClicks < 5 && bioClicks > 0) bioClicks = 0; }, 2000);
        }

        function initMainframeBackground() {
            const canvas = document.getElementById('warpspeed-canvas');
            if(!canvas) return;
            const ctx = canvas.getContext('2d');
            let stars = [];
            const numStars = 500;
            const piecesOfCode = ['WRKOUTQ', 'DSPSPLF', 'STRSQL', 'CHGJOB', '0x4A', 'SPOOL', 'CORE_SYS', 'DB2', 'QSYS', 'AS400'];

            function initStars() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
                stars = [];
                for(let i=0; i<numStars; i++) {
                    stars.push({
                        x: Math.random() * canvas.width - canvas.width/2,
                        y: Math.random() * canvas.height - canvas.height/2,
                        z: Math.random() * canvas.width,
                        code: Math.random() > 0.95 ? piecesOfCode[Math.floor(Math.random()*piecesOfCode.length)] : null,
                        o: Math.random()
                    });
                }
            }

            let scanBeamY = 0;
            let auroraOffset = 0;

            function draw() {
                ctx.fillStyle = "black";
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                
                // Aurora Effect (Subtle gradients)
                auroraOffset += 0.002;
                const grad = ctx.createRadialGradient(canvas.width/2, canvas.height/2, 50, canvas.width/2, canvas.height/2, canvas.width/2);
                grad.addColorStop(0, accentRGB(0.02));
                grad.addColorStop(0.5, `rgba(59, 130, 246, ${0.05 + Math.sin(auroraOffset)*0.03})`);
                grad.addColorStop(1, 'transparent');
                ctx.fillStyle = grad;
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                ctx.save();
                ctx.translate(canvas.width/2 + (globalWarpState.mouseX * 0.1), canvas.height/2 + (globalWarpState.mouseY * 0.1));
                
                for(let i=0; i<numStars; i++) {
                    let s = stars[i];
                    s.z -= globalWarpState.speed;
                    if(s.z <= 0) s.z = canvas.width;
                    
                    let x = s.x * (canvas.width / s.z);
                    let y = s.y * (canvas.width / s.z);
                    let size = (1 - s.z/canvas.width) * 2;
                    let opacity = (1 - s.z/canvas.width);
                    
                    if(s.code) {
                        ctx.font = `${globalWarpState.active ? 'bold' : ''} ${Math.floor(size * 14)}px 'JetBrains Mono'`;
                        ctx.shadowBlur = globalWarpState.active ? 20 : 8;
                        ctx.shadowColor = accentRGB(0.6);
                        // Random flicker for code
                        const flicker = Math.random() > 0.05 ? 1 : 0.3;
                        ctx.fillStyle = `rgba(${accentRGBRaw()}, ${opacity * flicker * (globalWarpState.active ? 1 : 0.6)})`;
                        ctx.fillText(s.code, x, y);
                        ctx.shadowBlur = 0;
                    } else {
                        ctx.fillStyle = `rgba(${accentRGBRaw()}, ${opacity})`;
                        ctx.beginPath();
                        ctx.arc(x, y, size, 0, Math.PI*2);
                        ctx.fill();
                    }
                    
                    if(globalWarpState.speed > 5) {
                        ctx.strokeStyle = `rgba(${accentRGBRaw()}, ${(globalWarpState.speed/30) * opacity})`;
                        ctx.lineWidth = size;
                        ctx.beginPath();
                        ctx.moveTo(x, y);
                        ctx.lineTo(x * 1.1, y * 1.1);
                        ctx.stroke();
                    }
                }
                ctx.restore();

                // Scanning Beam
                scanBeamY += 4;
                if(scanBeamY > canvas.height) scanBeamY = 0;
                ctx.fillStyle = accentRGB(0.03);
                ctx.fillRect(0, scanBeamY, canvas.width, 2);
                ctx.fillStyle = accentRGB(0.015);
                ctx.fillRect(0, scanBeamY - 20, canvas.width, 40);

                requestAnimationFrame(draw);
            }

            window.addEventListener('resize', initStars);
            window.addEventListener('mousemove', (e) => {
                globalWarpState.mouseX = e.clientX - window.innerWidth/2;
                globalWarpState.mouseY = e.clientY - window.innerHeight/2;
            });

            initStars();
            draw();
        }

        document.addEventListener('DOMContentLoaded', () => {
            if(document.getElementById('warpspeed-canvas')) initMainframeBackground();
        });

        function executeOverloadEffect() {
            const container = document.getElementById('login-container');
            if(!container) return;

            globalWarpState.active = true;
            container.classList.add('warp-active');

            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const master = audioCtx.createGain();
            const compressor = audioCtx.createDynamicsCompressor();
            const duckingGain = audioCtx.createGain();
            
            duckingGain.gain.setValueAtTime(1, audioCtx.currentTime);
            master.gain.setValueAtTime(0.4, audioCtx.currentTime);
            master.connect(duckingGain);
            duckingGain.connect(compressor);
            compressor.connect(audioCtx.destination);

            // Goth/AfterDark Reverb Spec
            const mainDelay = audioCtx.createDelay(1.0);
            const delayFeedback = audioCtx.createGain();
            const delayFilter = audioCtx.createBiquadFilter();
            mainDelay.delayTime.value = 0.35;
            delayFeedback.gain.value = 0.5;
            delayFilter.type = 'lowpass';
            delayFilter.frequency.value = 2000;
            mainDelay.connect(delayFilter); delayFilter.connect(delayFeedback); delayFeedback.connect(mainDelay);
            mainDelay.connect(master);

            const profiles = [
                { 
                    id: "DDOS HACKING THEME 2.0", 
                    bpm: 150, 
                    scale: [138.59, 146.83, 110, 123.47], 
                    style: 'aggressive',
                    timer: 10000 
                },
                { 
                    id: "Goth (Slowed + Reverb)", 
                    bpm: 92, 
                    scale: [69.30, 82.41, 69.30, 92.50, 77.78, 82.41], 
                    style: 'goth',
                    timer: 18000
                },
                { 
                    id: "Mr Robot Main Theme (Extended)", 
                    bpm: 124, 
                    scale: [69.30, 82.41, 69.30, 92.50, 77.78, 82.41, 110, 103.83], 
                    style: 'classic',
                    timer: 15000
                },
                { 
                    id: "Mr.Kitty - After Dark", 
                    bpm: 133, 
                    scale: [440, 392, 349.23, 311.13, 261.63], 
                    style: 'synthwave',
                    timer: 14000
                },
                { 
                    id: "Mac Quayle - 1.3_5-da3monsneverstop", 
                    bpm: 115, 
                    scale: [69.30, 138.59, 103.83, 77.78, 92.50], 
                    style: 'modular',
                    timer: 16000
                }
            ];

            const activeProfile = profiles[Math.floor(Math.random() * profiles.length)];
            const stepTime = 60 / activeProfile.bpm / 4;
            const phrases = [
                "EL CONTROL ES UNA ILUSIÓN.",
                "HOLA AMIGO.",
                "EL MUNDO ES UN ESCENARIO.",
                "LA PRIVACIDAD SE HA IDO.",
                "SÍGUEME AL CONEJO BLANCO.",
                "NADA ES LO QUE PARECE.",
                "ESTAMOS EN EL MOMENTO EQUIVOCADO.",
                "TU SEGURIDAD ES MI PUERTA.",
                "¿ESTÁS VIENDO LO MISMO QUE YO?"
            ];
            const randomPhrase = phrases[Math.floor(Math.random() * phrases.length)];
            let step = 0;

            const playKick = (t) => {
                const osc = audioCtx.createOscillator();
                const g = audioCtx.createGain();
                osc.frequency.setValueAtTime(activeProfile.style === 'aggressive' ? 220 : 160, t);
                osc.frequency.exponentialRampToValueAtTime(0.01, t + 0.6);
                g.gain.setValueAtTime(2.0, t);
                g.gain.linearRampToValueAtTime(0, t + 0.5);
                osc.connect(g); g.connect(compressor);
                osc.start(t); osc.stop(t + 0.6);
                duckingGain.gain.cancelScheduledValues(t);
                duckingGain.gain.setValueAtTime(1, t);
                duckingGain.gain.exponentialRampToValueAtTime(0.2, t + 0.05);
                duckingGain.gain.exponentialRampToValueAtTime(1, t + 0.3);
            };

            const playArp = (t, freq, accent = false) => {
                const osc = audioCtx.createOscillator();
                const g = audioCtx.createGain();
                osc.type = (activeProfile.style === 'synthwave' || activeProfile.style === 'goth') ? 'sawtooth' : 'square';
                osc.frequency.setValueAtTime(freq, t);
                const filter = audioCtx.createBiquadFilter();
                filter.type = 'lowpass'; filter.Q.value = accent ? 25 : 12;
                filter.frequency.setValueAtTime(accent ? 4000 : 1500, t);
                filter.frequency.exponentialRampToValueAtTime(80, t + 0.25);
                g.gain.setValueAtTime(accent ? 0.3 : 0.15, t);
                g.gain.exponentialRampToValueAtTime(0.001, t + 0.3);
                osc.connect(filter); filter.connect(g); 
                g.connect(activeProfile.style === 'goth' || activeProfile.style === 'synthwave' ? mainDelay : master);
                osc.start(t); osc.stop(t + 0.3);
            };

            const playModularGlitch = (t) => {
                const osc = audioCtx.createOscillator();
                const mod = audioCtx.createOscillator();
                const modGain = audioCtx.createGain();
                const g = audioCtx.createGain();
                osc.type = 'sine'; mod.type = 'sawtooth';
                mod.frequency.value = 80 + Math.random() * 200;
                modGain.gain.value = 1000;
                osc.frequency.value = 50 + Math.random() * 50;
                mod.connect(modGain); modGain.connect(osc.frequency);
                g.gain.setValueAtTime(0.2, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.1);
                osc.connect(g); g.connect(master);
                osc.start(t); mod.start(t); osc.stop(t+0.1); mod.stop(t+0.1);
            };

            let nextTick = audioCtx.currentTime;
            function scheduler() {
                while (nextTick < audioCtx.currentTime + 0.1) {
                    if (step % 8 === 0) playKick(nextTick);
                    
                    if (activeProfile.style === 'synthwave' && step % 2 === 0) {
                        playArp(nextTick, activeProfile.scale[step % activeProfile.scale.length] / 2, step % 8 === 0);
                    } else if (activeProfile.style === 'modular') {
                        if(step % 4 === 0) playModularGlitch(nextTick);
                        playArp(nextTick, activeProfile.scale[step%5], step%16===0);
                    } else {
                        playArp(nextTick, activeProfile.scale[step % activeProfile.scale.length], step % 16 === 0);
                    }
                    
                    if (Math.random() > 0.94) {
                        const bufferSize = audioCtx.sampleRate * 0.05;
                        const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate);
                        const data = buffer.getChannelData(0);
                        for (let i = 0; i < bufferSize; i++) data[i] = Math.random() * 2 - 1;
                        const source = audioCtx.createBufferSource();
                        source.buffer = buffer;
                        const g = audioCtx.createGain();
                        g.gain.setValueAtTime(0.05, nextTick);
                        g.gain.exponentialRampToValueAtTime(0.001, nextTick + 0.05);
                        source.connect(g); g.connect(master);
                        source.start(nextTick);
                    }
                    
                    nextTick += stepTime;
                    step++;
                    if(globalWarpState.active) globalWarpState.speed += (activeProfile.style === 'aggressive' ? 0.05 : 0.02);
                }
                if(globalWarpState.active) setTimeout(scheduler, 25);
            }
            
            scheduler();

            setTimeout(() => {
                globalWarpState.active = false;
                container.classList.remove('warp-active');
                globalWarpState.speed = 0.3;
                master.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 4);
                setTimeout(() => audioCtx.close(), 4100);
                
                Swal.fire({
                    title: `<span style="color:#ffffff; font-family: 'JetBrains Mono'; letter-spacing: 0.1em; font-weight: 900; font-size: 1.2rem;">${activeProfile.id}</span>`,
                    html: `<div class="font-mono text-[11px] space-y-4 text-center p-2">
                            <div class="h-px w-full bg-gradient-to-r from-transparent via-white/10 to-transparent"></div>
                            <p class="text-white opacity-90 leading-relaxed font-bold tracking-widest uppercase">
                                <span class="text-red-500">${randomPhrase}</span>
                            </p>
                            <div class="grid grid-cols-2 gap-2 py-4">
                                <span class="text-[8px] px-2 py-1 border border-white/5 rounded text-gray-400 bg-white/5 uppercase">BPM: ${activeProfile.bpm}</span>
                                <span class="text-[8px] px-2 py-1 border border-white/5 rounded text-gray-400 bg-white/5 uppercase">SYST: FSOCIETY_CORE</span>
                                <span class="text-[8px] px-2 py-1 border border-white/5 rounded text-gray-400 bg-white/5 uppercase">LOG: ${Math.random().toString(16).substr(2,6)}</span>
                                <span class="text-[8px] px-2 py-1 border border-red-500/20 rounded text-red-500 animate-pulse bg-red-500/5">BREACH_ACTIVE</span>
                            </div>
                            <div class="h-px w-full bg-gradient-to-r from-transparent via-white/10 to-transparent"></div>
                            <p class="text-[9px] text-gray-600 italic">"Hay una parte de mí que siempre está lista para este momento."</p>
                          </div>`,
                    showConfirmButton: false,
                    timer: 8000,
                    background: '#080808',
                    color: '#ffffff',
                    position: 'center',
                    backdrop: `rgba(0,0,0,0.98)`,
                    customClass: { popup: 'border border-white/5 shadow-[0_0_80px_rgba(255,0,0,0.05)] rounded-none' }
                });
            }, activeProfile.timer); 

        }

        function toggleThemeMenu(e) {
            e.stopPropagation();
            const menu = document.getElementById('theme-menu-items');
            menu.classList.toggle('hidden');
        }

        // Cerrar menus al hacer clic fuera
        document.addEventListener('click', (e) => {
            // Theme Menu
            const themeMenu = document.getElementById('theme-menu-items');
            const themeBtn = document.getElementById('theme-menu-btn') || document.getElementById('login-theme-btn');
            if (themeMenu && themeBtn && !themeMenu.contains(e.target) && !themeBtn.contains(e.target)) {
                themeMenu.classList.add('hidden');
            }
            // Kit Menu (login)
            const kitMenu = document.getElementById('kit-menu-items');
            const kitBtn = document.getElementById('login-kit-btn');
            if (kitMenu && kitBtn && !kitMenu.contains(e.target) && !kitBtn.contains(e.target)) {
                kitMenu.classList.add('hidden');
            }
            
            // Context Menu
            const contextMenu = document.getElementById('context-menu');
            if (contextMenu && !contextMenu.contains(e.target)) {
                contextMenu.classList.add('hidden');
            }
            
            // Fav Dropdown
            const favDropdown = document.getElementById('fav-dropdown');
            const searchInput = document.getElementById('target-user-search');
            if (favDropdown && searchInput && !favDropdown.contains(e.target) && !searchInput.contains(e.target)) {
                favDropdown.classList.add('hidden');
            }
        });

        async function openGatekeeper() {
            const { value: password } = await Swal.fire({
                title: 'Acceso de Administrador',
                text: 'Introduzca la contraseña maestra para configurar el Usuario Puente',
                input: 'password',
                inputPlaceholder: '••••••••',
                inputAttributes: { autocapitalize: 'off', autocorrect: 'off' },
                showCancelButton: true,
                confirmButtonText: 'Desbloquear',
                cancelButtonText: 'Cancelar'
            });

            if (password) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'configure_proxy.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'admin_password';
                input.value = password;
                form.appendChild(input);

                const loginAction = document.createElement('input');
                loginAction.type = 'hidden';
                loginAction.name = 'admin_login';
                loginAction.value = '1';
                form.appendChild(loginAction);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // --- SMART HIGHLIGHTER LOGIC ---
        function openSmartHighlighter() {
            document.getElementById('smart-highlighter-modal').classList.remove('hidden');
            renderStyleRules();
        }

        function closeSmartHighlighter() {
            document.getElementById('smart-highlighter-modal').classList.add('hidden');
        }

        function addStyleRule() {
            const pattern = document.getElementById('style-pattern').value.trim();
            const type = document.getElementById('style-type').value;
            if (!pattern) return;

            window.styleRules.push({ pattern, type });
            document.getElementById('style-pattern').value = '';
            renderStyleRules();
            if (currentParsedData) renderPreview(currentParsedData);
        }

        function removeStyleRule(index) {
            window.styleRules.splice(index, 1);
            renderStyleRules();
            if (currentParsedData) renderPreview(currentParsedData);
        }

        function renderStyleRules() {
            const list = document.getElementById('style-rules-list');
            if(!list) return;
            list.innerHTML = window.styleRules.map((rule, idx) => `
                <div class="flex items-center justify-between p-4 bg-white/5 border border-white/10 rounded-xl group hover:border-accent/40 transition-all">
                    <div class="flex items-center gap-4">
                        <span class="px-3 py-1 bg-accent/10 text-accent text-xs font-bold rounded-lg uppercase">${rule.type}</span>
                        <span class="text-white font-mono font-bold">${rule.pattern}</span>
                    </div>
                    <button onclick="removeStyleRule(${idx})" class="p-2 text-gray-500 hover:text-red-400 transition-colors opacity-0 group-hover:opacity-100 italic font-bold text-xs uppercase">
                        Eliminar
                    </button>
                </div>
            `).join('');
        }

        function clearAllStyles() {
            window.styleRules = [];
            renderStyleRules();
            if (currentParsedData) renderPreview(currentParsedData);
        }
    </script>

    <!-- Smart Highlighter Modal -->
    <div id="smart-highlighter-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-2xl flex flex-col items-center justify-center z-[250]">
        <div class="bg-[var(--bg-panel)] border border-white/10 rounded-[2.5rem] w-full max-w-xl overflow-hidden shadow-[0_0_80px_rgba(0,0,0,0.6)] glass-panel">
            <div class="p-8 border-b border-white/5 flex justify-between items-center bg-black/40">
                <div class="flex items-center gap-4">
                    <div class="w-2.5 h-7 bg-yellow-500 rounded-full"></div>
                    <h3 class="font-bold text-white text-lg tracking-widest uppercase">Resaltado Inteligente</h3>
                </div>
                <button onclick="closeSmartHighlighter()" class="p-3 bg-white/5 border border-white/10 rounded-xl text-gray-500 hover:text-white transition-all premium-hover">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <div class="p-8 space-y-6">
                <div class="space-y-4">
                    <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest block">Nueva Regla de Estilo</label>
                    <div class="flex gap-4">
                        <input type="text" id="style-pattern" placeholder="Palabra o patrón..." class="flex-1 bg-black/40 border border-white/10 text-sm font-bold text-white px-5 py-3 rounded-xl outline-none focus:border-yellow-500/40 focus:ring-4 focus:ring-yellow-500/5 transition-all uppercase">
                        <select id="style-type" class="bg-black/40 border border-white/10 text-[15px] font-bold text-gray-300 px-4 py-3 rounded-xl outline-none focus:border-yellow-500/40">
                            <option value="bold">NEGRITA</option>
                            <option value="italic">CURSIVA</option>
                            <option value="underline">SUBRAYADO</option>
                        </select>
                        <button onclick="addStyleRule()" class="px-6 py-3 bg-yellow-500 text-black font-bold rounded-xl hover:bg-white transition-all shadow-lg uppercase tracking-widest">AÑADIR</button>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <label class="text-[15px] font-bold text-gray-500 uppercase tracking-widest block">Reglas Activas</label>
                        <button onclick="clearAllStyles()" class="text-xs font-bold text-red-500/60 hover:text-red-500 uppercase tracking-widest transition-colors">Limpiar Todo</button>
                    </div>
                    <div id="style-rules-list" class="space-y-2 max-h-[40vh] overflow-y-auto custom-scroll pr-2">
                        <!-- Rules rendered by JS -->
                    </div>
                </div>
            </div>

            <div class="p-8 border-t border-white/5 flex justify-end bg-black/40">
                <button onclick="closeSmartHighlighter()" class="px-10 py-4 rounded-[1.5rem] text-[15px] font-bold bg-white/5 text-white border border-white/10 hover:bg-white/10 transition-all uppercase tracking-widest">CERRAR</button>
            </div>
        </div>
    </div>

    <!-- Dashboard Funcional -->
    <div id="dashboard-modal" class="hidden fixed inset-0 z-[250] flex items-center justify-center bg-black/70 backdrop-blur-sm">
        <div class="w-full max-w-6xl glass-panel border border-white/10 rounded-[2rem] overflow-hidden shadow-[0_32px_128px_rgba(0,0,0,0.8)] max-h-[92vh] flex flex-col animate-scale-in">
            <div class="flex items-center justify-between px-8 py-6 border-b border-white/5 bg-black/40">
                <div class="flex items-center gap-4">
                    <svg class="w-6 h-6 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <div>
                        <h3 class="font-bold text-white text-lg tracking-widest uppercase">Dashboard Funcional</h3>
                        <p class="text-sm text-gray-500 font-mono">Análisis en vivo de los spools activos</p>
                    </div>
                </div>
                <button onclick="closeDashboard()" class="p-3 bg-white/5 border border-white/10 rounded-xl text-gray-500 hover:text-white transition-all premium-hover">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="p-8 overflow-y-auto custom-scroll space-y-8">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-5" id="dash-kpi-container"></div>

                <div class="grid grid-cols-2 gap-5">
                    <div class="flex items-center justify-between p-5 bg-black/30 border border-white/5 rounded-2xl">
                        <span class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Colas de Salida</span>
                        <span id="spool-outqs" class="text-3xl font-black text-accent">0</span>
                    </div>
                    <div class="flex items-center justify-between p-5 bg-black/30 border border-white/5 rounded-2xl">
                        <span class="text-[15px] font-bold text-gray-500 uppercase tracking-widest">Carga Estimada</span>
                        <span id="spool-network-load" class="text-3xl font-black text-blue-400">0</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-black/30 border border-white/5 rounded-2xl p-6">
                        <h4 class="text-[15px] font-bold text-gray-500 uppercase tracking-widest mb-4">Actividad por Usuario</h4>
                        <div class="h-64"><canvas id="chart-users-activity"></canvas></div>
                    </div>
                    <div class="bg-black/30 border border-white/5 rounded-2xl p-6">
                        <h4 class="text-[15px] font-bold text-gray-500 uppercase tracking-widest mb-4">Distribución por Estado</h4>
                        <div class="flex items-center justify-center h-48"><canvas id="chart-status-pie"></canvas></div>
                        <div id="status-legend" class="mt-4 space-y-2"></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-black/30 border border-white/5 rounded-2xl p-6">
                        <h4 class="text-[15px] font-bold text-gray-500 uppercase tracking-widest mb-4">Top Spools por Páginas</h4>
                        <div class="h-64"><canvas id="chart-top-pages"></canvas></div>
                    </div>
                    <div class="bg-black/30 border border-white/5 rounded-2xl p-6">
                        <h4 class="text-[15px] font-bold text-gray-500 uppercase tracking-widest mb-4">Tipología de Reportes</h4>
                        <div class="h-64"><canvas id="chart-types-radar"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Personalizador de Tema (colores + tipografia) -->
    <div id="theme-editor-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-2xl flex flex-col items-center justify-center z-[260]">
        <div class="bg-[var(--bg-panel)] border border-[var(--border-color)] rounded-[2.5rem] w-full max-w-2xl overflow-hidden shadow-[0_0_80px_rgba(0,0,0,0.6)] glass-panel max-h-[92vh] flex flex-col">
            <div class="p-6 border-b border-[var(--border-color)] flex justify-between items-center bg-black/40">
                <div class="flex items-center gap-4">
                    <div class="w-2.5 h-7 bg-accent rounded-full"></div>
                    <h3 class="font-bold text-[var(--text-main)] text-lg tracking-widest uppercase">Personalizar Tema</h3>
                </div>
                <button onclick="closeThemeEditor()" class="p-3 bg-white/5 border border-white/10 rounded-xl text-gray-500 hover:text-white transition-all premium-hover">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="p-6 space-y-6 overflow-y-auto custom-scroll">
                <div class="flex gap-4 flex-wrap items-end">
                    <div class="flex-1 min-w-52 space-y-2">
                        <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest block">Tema Base</label>
                        <select id="te-base-theme" onchange="changeBaseTheme()" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-gray-300 px-4 py-3 rounded-xl outline-none focus:border-accent/40"></select>
                    </div>
                    <div class="flex-1 min-w-52 space-y-2">
                        <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest block">Nombre del Tema</label>
                        <input type="text" id="te-theme-name" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-accent/40">
                    </div>
                </div>

                <div id="te-controls" class="grid grid-cols-2 gap-4"></div>

                <div class="space-y-3 bg-black/30 border border-white/5 rounded-2xl p-5">
                    <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest block">Vista Previa</label>
                    <div class="flex flex-wrap gap-4 items-center">
                        <button id="te-swatch-btn" class="px-6 py-3 rounded-xl font-black uppercase tracking-widest shadow-lg">Botón</button>
                        <div id="te-swatch-modal" class="px-5 py-3 rounded-2xl border flex flex-col gap-1 min-w-40">
                            <span id="te-swatch-text" class="font-bold text-sm">Título del modal</span>
                            <span id="te-swatch-muted" class="text-xs">Texto secundario de ejemplo</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-6 border-t border-[var(--border-color)] flex flex-wrap justify-between gap-3 bg-black/40">
                <button onclick="resetTheme()" class="px-6 py-3 rounded-[1.5rem] text-[15px] font-bold bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500 hover:text-white transition-all uppercase tracking-widest">REINICIAR</button>
                <div class="flex flex-wrap gap-3">
                    <button onclick="closeThemeEditor()" class="px-6 py-3 rounded-[1.5rem] text-[15px] font-bold bg-white/5 text-white border border-white/10 hover:bg-white/10 transition-all uppercase tracking-widest">CANCELAR</button>
                    <button onclick="saveTheme('duplicate')" class="px-6 py-3 rounded-[1.5rem] text-[15px] font-bold bg-white/10 text-white border border-white/15 hover:bg-white/20 transition-all uppercase tracking-widest">DUPLICAR</button>
                    <button onclick="saveTheme('save')" class="px-8 py-3 rounded-[1.5rem] text-[15px] font-black bg-accent text-black hover:bg-white transition-all shadow-accent uppercase tracking-widest">GUARDAR</button>
                </div>
            </div>
        </div>
    </div>

    <div id="updater-modal" class="hidden fixed inset-0 z-[240] bg-black/60 backdrop-blur-2xl flex items-center justify-center p-4">
        <div class="bg-[var(--bg-panel)] border border-white/10 rounded-[2.5rem] w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden shadow-[0_32px_128px_rgba(0,0,0,0.9)] animate-fade-in-up">
            <header class="p-8 border-b border-white/5 flex justify-between items-center bg-black/20">
                <div class="flex items-center gap-5">
                    <div class="w-14 h-14 bg-accent/10 border border-accent/20 rounded-2xl flex items-center justify-center shadow-accent">
                        <svg class="w-7 h-7 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3 3m0 0l-3-3m3 3V8"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-white tracking-tighter">ACTUALIZACIONES</h2>
                        <p class="text-gray-500 font-bold text-[13px] tracking-[0.3em] uppercase mt-1">Auto-Update vía GitHub</p>
                    </div>
                </div>
                <button onclick="closeUpdater()" class="p-3 bg-white/5 border border-white/10 rounded-2xl text-gray-400 hover:text-white transition-all premium-hover">&times;</button>
            </header>
            
            <main class="flex-1 overflow-y-auto custom-scroll p-8 space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-black/30 border border-white/5 rounded-2xl p-5">
                        <p class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Versión Local</p>
                        <p id="upd-local" class="text-2xl font-black text-white mt-2 font-mono">—</p>
                    </div>
                    <div class="bg-black/30 border border-white/5 rounded-2xl p-5">
                        <p class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Versión Remota</p>
                        <p id="upd-remote" class="text-2xl font-black text-white mt-2 font-mono">—</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-black/20 border border-white/5 rounded-2xl p-5">
                        <p class="text-[12px] font-bold text-gray-500 uppercase tracking-widest mb-2">Última Comprobación</p>
                        <p id="upd-last-check" class="text-[15px] text-gray-400 font-mono">Nunca</p>
                    </div>
                    <div class="bg-black/20 border border-white/5 rounded-2xl p-5">
                        <p class="text-[12px] font-bold text-gray-500 uppercase tracking-widest mb-2">Última Aplicada</p>
                        <p id="upd-last-applied" class="text-[15px] text-gray-400 font-mono">Ninguna</p>
                    </div>
                </div>

                <div class="bg-black/20 border border-white/5 rounded-2xl p-5">
                    <p class="text-[12px] font-bold text-gray-500 uppercase tracking-widest mb-2">Notas de la Versión</p>
                    <p id="upd-changelog" class="text-[15px] text-gray-300 leading-relaxed whitespace-pre-wrap">Sin datos.</p>
                </div>

                <div class="bg-black/20 border border-white/5 rounded-2xl p-5 space-y-4">
                    <p class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Configuración del Repositorio</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Repositorio (usuario/repo)</label>
                            <input id="upd-repo" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-accent/40" placeholder="Usuario/Repositorio">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Rama</label>
                            <input id="upd-branch" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-accent/40" placeholder="main">
                        </div>
                    </div>
                    <label class="flex items-center gap-3 cursor-pointer select-none">
                        <button id="upd-auto-toggle" onclick="toggleUpdaterAuto()" class="w-12 h-6 bg-white/10 border border-white/10 rounded-full relative transition-all duration-300">
                            <div id="upd-auto-knob" class="absolute top-1 left-1 w-4 h-4 bg-gray-300 rounded-full transition-all duration-300"></div>
                        </button>
                        <span class="text-[15px] font-bold text-gray-300">Comprobar automáticamente (cada 24 h)</span>
                    </label>
                </div>
            </main>
            
            <footer class="p-6 border-t border-white/5 flex flex-wrap justify-between gap-3 bg-black/40">
                <button onclick="runRollbackUpdate()" class="px-5 py-3 rounded-[1.5rem] text-[15px] font-bold bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500 hover:text-white transition-all uppercase tracking-widest">REVERTIR</button>
                <div class="flex flex-wrap gap-3">
                    <button onclick="closeUpdater()" class="px-5 py-3 rounded-[1.5rem] text-[15px] font-bold bg-white/5 text-white border border-white/10 hover:bg-white/10 transition-all uppercase tracking-widest">CERRAR</button>
                    <button onclick="saveUpdaterConfig()" class="px-5 py-3 rounded-[1.5rem] text-[15px] font-bold bg-white/10 text-white border border-white/15 hover:bg-white/20 transition-all uppercase tracking-widest">GUARDAR CONFIG</button>
                    <button onclick="runUpdateCheck()" class="px-5 py-3 rounded-[1.5rem] text-[15px] font-bold bg-white/10 text-white border border-white/15 hover:bg-white/20 transition-all uppercase tracking-widest">BUSCAR</button>
                    <button id="upd-apply-btn" onclick="runApplyUpdate()" class="px-6 py-3 rounded-[1.5rem] text-[15px] font-black bg-accent text-black hover:bg-white transition-all shadow-accent uppercase tracking-widest" style="opacity:0.35;pointer-events:none">ACTUALIZAR</button>
                </div>
            </footer>
        </div>
    </div>

    <!-- Feedback Modal: Comentarios / Ideas / Mejoras a GitHub -->
    <div id="feedback-modal" class="hidden fixed inset-0 z-[250] bg-black/60 backdrop-blur-2xl flex items-center justify-center p-4">
        <div class="bg-[var(--bg-panel)] border border-white/10 rounded-[2.5rem] w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden shadow-[0_32px_128px_rgba(0,0,0,0.9)] animate-fade-in-up">
            <header class="p-8 border-b border-white/5 flex justify-between items-center bg-black/20">
                <div class="flex items-center gap-5">
                    <div class="w-14 h-14 bg-indigo-500/10 border border-indigo-500/20 rounded-2xl flex items-center justify-center">
                        <svg class="w-7 h-7 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-white tracking-tighter">COMENTARIOS &amp; IDEAS</h2>
                        <p class="text-gray-500 font-bold text-[13px] tracking-[0.3em] uppercase mt-1">Se envía a GitHub como issue para futuras mejoras</p>
                    </div>
                </div>
                <button onclick="closeFeedback()" class="p-3 bg-white/5 border border-white/10 rounded-2xl text-gray-400 hover:text-white transition-all premium-hover">&times;</button>
            </header>
            
            <main class="flex-1 overflow-y-auto custom-scroll p-8 space-y-6">
                <div id="feedback-config-warn" class="hidden bg-yellow-500/10 border border-yellow-500/25 rounded-2xl p-5 text-[14px] text-yellow-200 leading-relaxed">
                    El envío a GitHub aún no está configurado en este equipo. Un <b>administrador</b> debe guardar el token una sola vez (sección <b>Configuración</b>); a partir de ahí <b>todos los usuarios</b> podrán enviar comentarios sin necesidad de pegar nada.
                </div>
                <div id="feedback-config-ok" class="hidden bg-green-500/10 border border-green-500/25 rounded-2xl p-5 text-[14px] text-green-300 leading-relaxed">
                    Configuración activa: se enviará a <b id="feedback-target"></b> con tu usuario y la versión de la app.
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Categoría</label>
                            <select id="fb-category" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-indigo-400/40">
                                <option value="idea">Idea</option>
                                <option value="mejora">Mejora</option>
                                <option value="error">Error / Bug</option>
                                <option value="sugerencia">Sugerencia</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Título *</label>
                            <input id="fb-title" maxlength="200" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-indigo-400/40" placeholder="Resumen corto de tu idea">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Mensaje *</label>
                        <textarea id="fb-message" rows="6" maxlength="10000" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-indigo-400/40 resize-none" placeholder="Describe tu comentario, idea o el problema encontrado..."></textarea>
                        <p class="text-[11px] text-gray-600 font-bold text-right" id="fb-count">0 / 10000</p>
                    </div>
                </div>

                <div class="border-t border-white/5 pt-6">
                    <p class="text-[12px] font-bold text-gray-500 uppercase tracking-widest mb-3">Configuración (administrador)</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Owner (usuario GitHub)</label>
                            <input id="fb-owner" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-indigo-400/40" placeholder="GabrielLop3z">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Repo</label>
                            <input id="fb-repo" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-indigo-400/40" placeholder="AS400-Portable-Libre">
                        </div>
                    </div>
                    <div class="space-y-2 mt-4">
                        <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest">Token (Personal Access Token, scope public_repo)</label>
                        <div class="flex gap-2">
                            <input id="fb-token" type="password" autocomplete="off" class="flex-1 bg-black/40 border border-white/10 text-[15px] font-bold text-white px-4 py-3 rounded-xl outline-none focus:border-indigo-400/40" placeholder="ghp_...">
                            <button onclick="saveFeedbackConfig()" class="px-5 py-3 rounded-xl text-[15px] font-bold bg-indigo-500/10 text-indigo-300 border border-indigo-500/30 hover:bg-indigo-500 hover:text-white transition-all uppercase tracking-widest">GUARDAR</button>
                        </div>
                    </div>
                </div>
            </main>
            
            <footer class="p-6 border-t border-white/5 flex flex-wrap justify-between gap-3 bg-black/40">
                <button onclick="closeFeedback()" class="px-5 py-3 rounded-[1.5rem] text-[15px] font-bold bg-white/5 text-white border border-white/10 hover:bg-white/10 transition-all uppercase tracking-widest">CERRAR</button>
                <button id="fb-submit-btn" onclick="submitFeedback()" class="px-6 py-3 rounded-[1.5rem] text-[15px] font-black bg-indigo-500 text-black hover:bg-white transition-all uppercase tracking-widest">ENVIAR A GITHUB</button>
            </footer>
        </div>
    </div>

    <script src="assets/theme-editor.js?v=<?= $assetVer ?>"></script>
</body>
</html>

