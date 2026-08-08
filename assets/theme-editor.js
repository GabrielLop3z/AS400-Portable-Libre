/* theme-editor.js - Personalizador de colores y tipografia (config/themes.json)
   Dependencias globales: themesApp, applyAppTheme, accentRGBRaw, Swal */

function hexToRgbStr(hex) {
    hex = String(hex || '').replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    const n = parseInt(hex, 16);
    if (isNaN(n) || hex.length !== 6) return accentRGBRaw();
    return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`;
}

const THEME_FIELDS = [
    { key: 'bgMain',   label: 'Fondo Principal' },
    { key: 'bgPanel',  label: 'Fondo Panel / Modales' },
    { key: 'bgDarker', label: 'Fondo Oscuro' },
    { key: 'border',   label: 'Bordes' },
    { key: 'textMain', label: 'Texto Principal' },
    { key: 'textMuted',label: 'Texto Secundario' },
    { key: 'accent',   label: 'Acento (Botones / Links)' }
];

let teGkRequired = false;
let teBaseThemeKey = null;

function currentThemeKey() {
    const choice = document.documentElement.getAttribute('data-theme-choice') || localStorage.getItem('app_theme') || 'medio';
    if (choice === 'auto') {
        const resolved = document.documentElement.getAttribute('data-theme') || 'medio';
        return (window.themesApp && window.themesApp[resolved]) ? resolved : 'medio';
    }
    return choice;
}

async function openThemeEditor() {
    try {
        const gk = await fetch('process.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_gatekeeper' })
        }).then(r => r.json());
        teGkRequired = !!gk.required;
    } catch (e) { teGkRequired = false; }

    let pwd = '';
    if (teGkRequired) {
        const r = await Swal.fire({
            title: 'Acceso de Administrador', icon: 'warning',
            text: 'Introduzca la contraseña maestra para personalizar los colores del sistema.',
            input: 'password', inputPlaceholder: '••••••••', showCancelButton: true,
            confirmButtonText: 'Desbloquear', cancelButtonText: 'Cancelar'
        });
        if (!r.value) return;
        const v = await fetch('process.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'validate_gatekeeper', password: r.value })
        }).then(r => r.json());
        if (!v.valid) {
            Swal.fire({ icon: 'error', title: 'Acceso denegado', text: 'Contraseña maestra incorrecta.' });
            return;
        }
        pwd = r.value;
    }
    window._tePassword = pwd;
    buildThemeEditorUI();
    document.getElementById('theme-editor-modal').classList.remove('hidden');
}

function closeThemeEditor() {
    document.getElementById('theme-editor-modal').classList.add('hidden');
    const props = ['--bg-main','--bg-panel','--bg-panel-rgb','--bg-darker','--border-color',
                   '--text-main','--text-muted','--accent','--accent-rgb','--font-family-ui'];
    for (const p of props) document.documentElement.style.removeProperty(p);
    applyAppTheme(currentThemeKey());
}

function buildThemeEditorUI() {
    teBaseThemeKey = currentThemeKey();
    const t = themesApp[teBaseThemeKey] || {};
    document.getElementById('te-theme-name').value = t.name || teBaseThemeKey;
    const sel = document.getElementById('te-base-theme');
    sel.innerHTML = Object.keys(themesApp || {}).map(k =>
        `<option value="${k}">${(themesApp[k] && themesApp[k].name) || k}</option>`).join('');
    if (themesApp[teBaseThemeKey]) sel.value = teBaseThemeKey;
    renderTeControls(t);
}

function fontSelected(t, v) {
    const norm = (s) => String(s || '').replace(/['"]/g, '').toLowerCase();
    return norm(t.fontFamily) === v ? 'selected' : '';
}

function renderTeControls(t) {
    document.getElementById('te-controls').innerHTML = THEME_FIELDS.map(f => `
        <div class="space-y-2">
            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest block">${f.label}</label>
            <div class="flex items-center gap-3">
                <input type="color" id="te-${f.key}" value="${t[f.key] || '#888888'}" class="w-12 h-12 rounded-xl bg-white/5 border border-white/10 p-1 cursor-pointer" oninput="syncTextFromColor('${f.key}')">
                <input type="text" id="te-${f.key}-text" value="${t[f.key] || ''}" class="flex-1 bg-black/40 border border-white/10 text-[13px] font-mono text-white px-3 py-2 rounded-lg outline-none focus:border-accent/40" oninput="syncColorFromText('${f.key}')">
            </div>
        </div>`).join('') + `
        <div class="space-y-2">
            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest block">Modo Claro</label>
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" id="te-isLight" ${t.isLight ? 'checked' : ''} onchange="previewTheme()" class="w-5 h-5 accent-pink-500">
                <span class="text-[13px] font-bold text-gray-400">Variante clara (ajusta contraste del terminal)</span>
            </label>
        </div>
        <div class="space-y-2">
            <label class="text-[12px] font-bold text-gray-500 uppercase tracking-widest block">Tipografía de Interfaz</label>
            <select id="te-fontFamily" onchange="previewTheme()" class="w-full bg-black/40 border border-white/10 text-[15px] font-bold text-gray-300 px-4 py-3 rounded-xl outline-none focus:border-accent/40">
                <option value="Arial" ${fontSelected(t, 'arial')}>Arial (Predeterminada)</option>
                <option value="'JetBrains Mono'" ${fontSelected(t, "'jetbrains mono'")}>JetBrains Mono (Terminal)</option>
                <option value="'Outfit'" ${fontSelected(t, 'outfit')}>Outfit (Moderna)</option>
                <option value="'Inter'" ${fontSelected(t, 'inter')}>Inter (Sistema)</option>
            </select>
        </div>`;
}

function gatherTheme() {
    const t = {};
    for (const f of THEME_FIELDS) {
        const el = document.getElementById('te-' + f.key);
        if (!el) return null;
        t[f.key] = el.value;
    }
    t.isLight = document.getElementById('te-isLight').checked;
    t.fontFamily = document.getElementById('te-fontFamily').value;
    return t;
}

function syncColorFromText(key) {
    const colorEl = document.getElementById('te-' + key);
    const textEl = document.getElementById('te-' + key + '-text');
    const v = (textEl.value || '').trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) colorEl.value = v;
    previewTheme();
}

function syncTextFromColor(key) {
    const colorEl = document.getElementById('te-' + key);
    const textEl = document.getElementById('te-' + key + '-text');
    if (textEl && colorEl) textEl.value = colorEl.value;
    previewTheme();
}

function previewTheme() {
    const theme = gatherTheme();
    if (!theme) return;
    const set = (k, v) => { if (v !== undefined && v !== '') document.documentElement.style.setProperty(k, v); };
    set('--bg-main', theme.bgMain);
    set('--bg-panel', theme.bgPanel);
    set('--bg-panel-rgb', hexToRgbStr(theme.bgPanel));
    set('--bg-darker', theme.bgDarker);
    set('--border-color', theme.border);
    set('--text-main', theme.textMain);
    set('--text-muted', theme.textMuted);
    set('--accent', theme.accent);
    set('--accent-rgb', hexToRgbStr(theme.accent));
    set('--font-family-ui', theme.fontFamily);
    const btn = document.getElementById('te-swatch-btn');
    const modal = document.getElementById('te-swatch-modal');
    if (btn) { btn.style.backgroundColor = theme.accent; btn.style.color = theme.isLight ? '#ffffff' : '#000000'; }
    if (modal) {
        modal.style.backgroundColor = theme.bgPanel;
        modal.style.borderColor = theme.border;
        modal.style.color = theme.textMain;
    }
    const t1 = document.getElementById('te-swatch-text');
    const t2 = document.getElementById('te-swatch-muted');
    if (t1) t1.style.color = theme.textMain;
    if (t2) t2.style.color = theme.textMuted;
}

function changeBaseTheme() {
    const key = document.getElementById('te-base-theme').value;
    teBaseThemeKey = key;
    const t = themesApp[key] || {};
    renderTeControls(t);
    document.getElementById('te-theme-name').value = t.name || key;
    previewTheme();
}

function slugify(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'nuevo_tema';
}

async function saveTheme(mode) {
    const theme = gatherTheme();
    if (!theme) return;
    const name = (document.getElementById('te-theme-name').value || '').trim();
    if (!name) {
        Swal.fire({ icon: 'warning', title: 'Falta el nombre', text: 'Indique un nombre para el tema.' });
        return;
    }
    theme.name = name;
    let key;
    if (mode === 'duplicate') {
        key = slugify(name);
        if (themesApp[key]) key = key + '_' + Date.now().toString(36);
    } else {
        key = teBaseThemeKey;
    }
    const payload = { action: 'save_theme', key, theme, mode };
    if (window._tePassword) payload.password = window._tePassword;
    const res = await fetch('process.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r => r.json());
    if (res && res.success) {
        await Swal.fire({ icon: 'success', title: 'Tema guardado', text: `El tema "${name}" se guardó correctamente.`, timer: 1600, timerProgressBar: true, showConfirmButton: false });
        location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'No se pudo guardar el tema.' });
    }
}

async function resetTheme() {
    const r = await Swal.fire({
        title: 'Reiniciar temas', icon: 'warning',
        text: 'Se restaurarán TODOS los temas a sus valores de fábrica. ¿Continuar?',
        showCancelButton: true, confirmButtonText: 'Reiniciar', cancelButtonText: 'Cancelar'
    });
    if (!r.isConfirmed) return;
    const payload = { action: 'reset_theme' };
    if (window._tePassword) payload.password = window._tePassword;
    const res = await fetch('process.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r => r.json());
    if (res && res.success) {
        await Swal.fire({ icon: 'success', title: 'Listo', text: 'Temas restaurados a los valores de fábrica.', timer: 1600, timerProgressBar: true, showConfirmButton: false });
        location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'No se pudo reiniciar.' });
    }
}
