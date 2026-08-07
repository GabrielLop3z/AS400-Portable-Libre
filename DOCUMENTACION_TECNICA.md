# 🏆 DOCUMENTACIÓN TÉCNICA: SPOOL GLR EXPLORER v4.0

El **Spool Explorer** es un motor de renderizado y exportación de archivos spool del mainframe AS400 (V5R3 y superior), diseñado para ser 100% autónomo, portable y seguro.

---

## 1. Arquitectura de Sistema
El sistema utiliza una arquitectura basada en PHP para la lógica del servidor y JavaScript puro para el renderizado visual y manipulación de datos en el cliente.

### Componentes Clave:
*   **PHP Engine (process.php):** Se encarga de la comunicación directa con el AS400 mediante comandos `CL` remotos.
*   **Editor de Layouts (JS):** Motor de segmentación por caracteres para transformar texto plano en cuadrículas de Excel.
*   **Exportación Sanitizada:** Lógica de escape para evitar caracteres de fórmula de Excel (`=`, `@`, `+`, `-`).
*   **Cero Dependencias Externas:** Todas las librerías (Tailwind, ChartJS, SweetAlert2, JSZip) y fuentes (Outfit, JetBrains Mono) son servidas localmente desde `/assets`.

---

## 2. Seguridad y Acceso (Gatekeeper)
El acceso a la configuración sensible está protegido por un panel oculto.
*   **Activación:** Botón invisible en la pantalla de login.
*   **Configuración:** Permite cambiar la IP del mainframe y las credenciales del usuario puente sin editar el código fuente.
*   **Protección Offline:** No se conectan bases de datos externas; las preferencias se guardan en archivos JSON locales en el servidor.

---

## 3. Compatibilidad V5R3 (Legacy Ready)
Diseñado específicamente para interactuar con versiones antiguas de OS/400 que carecen de servicios web modernos:
1.  Utiliza el comando `CPYSPLF` para volcar datos a archivos físicos.
2.  Extrae los datos mediante protocolos de transferencia nativos.
3.  Limpia las marcas de control de carro de impresora (FCFC) para una visualización digital limpia.

---

## 4. Versión Portable Libre
La distribución portátil está empaquetada para ejecutarse sin servidor externo:
*   **PHP Local:** Incluye un binario de PHP para Windows.
*   **Autodiagnóstico:** El archivo `Iniciar_Servidor.bat` realiza una verificación de 4 pasos para asegurar que el entorno es apto para ejecutar PHP.
*   **Gestión de Requisitos:** Incluye el instalador oficial `VC_redist.x64.exe` para equipos que carecen de las librerías de tiempo de ejecución de C++.
*   **Modo Ventana:** Lanza la aplicación en una instancia de navegador mediante flags `--app`, proporcionando una experiencia de escritorio limpia.

---

## 5. Configuración Centralizada (/config)
El tema visual y las plantillas PDF se administran en archivos JSON dentro de `/config`, sin tocar el código:
*   **`config/themes.json`:** Fuente única de los temas (Negro, Rojo, Claro). El tema por defecto es **Negro**. Cada tema define tokens CSS (`bgMain`, `bgPanel`, `border`, `textMain`, `accent`, `fontFamily`, etc.). El motor PHP genera los bloques `:root`/`[data-theme="x"]` y los menús de tema de forma dinámica. Si el navegador guardó un tema antiguo en `localStorage`, se aplica un fallback a Negro.
*   **Personalizador de temas (UI):** el botón "Personalizar" (sidebar → Interfaz Gráfica) abre un editor (`assets/theme-editor.js`) con pickers de color, modo claro y tipografía. Guarda vía `save_theme` en `process.php` (recalcula `accentRgb`/`bgPanelRgb`), permite duplicar temas y reiniciarlos a fábrica (`reset_theme` desde `config/themes.default.json`). Ambas acciones se protegen con el PIN de Gatekeeper si hay hash configurado.
*   **`config/pdf_templates.json`:** Plantillas de exportación PDF (default, t1..t6) con `fontFamily`, `bgColor`, `textColor`, `borderColor`, `headerColor`. `PdfExporter` las lee con fallback a valores por defecto; `EXPORT_TEMPLATES` en el frontend se genera desde este archivo.
*   **`config/templates.json`:** Plantillas de layout guardadas por usuario. Schema v1.8: `horizontalLines` (array), `bandColumns` (objeto `{startRow: [cols]}`), `columnAliases`/`columnHidden` (objetos), `styleRules`, `smartHighlightActive`, `lineColor`, `pdf`. Los formatos legacy (arrays) se migran automáticamente en `process.php`.
*   **CRUD de plantillas:** `load_templates`, `save_template`, `delete_template` y `rename_template`. Solo el propietario (prefijo `USUARIO - `) puede eliminar o renombrar sus plantillas.

---

## 7. Gestión de Spools (Acciones Operativas)
Módulo que ejecuta comandos CL remotos sobre spools individuales o en lote, vía el mismo puente FTP `RCMD` del motor Python:
*   **Pipeline:** frontend (`execSpoolAction`) → `process.php` (`action=spool_action`) → `src/spool_explorer.py` (`dispatch "manage"` → `manage_spool()`). Los argumentos se pasan con `escapeshellarg`; la respuesta se parsea con regex `{...}` y pasa por `refineError()`.
*   **Acciones (`sp_action`):** `delete`→`DLTSPLF`, `hold`→`HLDSPLF`, `release`→`RLSSPLF`, `reprint`→`CHGSPLFA STATUS(*READY)`, `change`→`CHGSPLFA` con opciones `OUTQ`, `FORMS`, `COPIES`, `PRTY`, `USRDTA`, `STATUS` (solo las que lleguen en `params`).
*   **UI:** menú contextual (sección "Gestión del Spool") con visibilidad inteligente por estado (Hold se oculta si el spool está retenido y Release solo aparece si lo está), modal "Cambiar Propiedades…" (`#cp-modal`, CHGSPLFA) y barra bulk (`bulkSpoolAction`) con MANTENER/SOLTAR/ELIMINAR en lote sobre `selectedSpools`. Las acciones destructivas (`delete`) piden confirmación Swal.
*   **Seguridad:** la acción se bloquea si no hay sesión activa; la sesión se inicia con `session_start()` antes de ejecutar. El comando se construye siempre con `escapeshellarg` (sin interpolación directa del usuario).

---

## 8. Dashboard Funcional
Analítica en vivo sobre `window.lastSpoolList`:
*   **KPIs:** total de reportes, páginas totales, promedio de páginas y spool máximo (`#dash-kpi-container`).
*   **Métricas técnicas:** `#spool-outqs` (nº de colas de salida) y `#spool-network-load` (carga estimada = páginas × 0.08 MB).
*   **Gráficos (Chart.js local, `assets/chart.js`):** barras "Actividad por Usuario" (`chart-users-activity`), dona "Distribución por Estado" (`chart-status-pie` + leyenda `status-legend`), barras horizontales "Top Spools por Páginas" (`chart-top-pages`) y radar "Tipología de Reportes" (`chart-types-radar`). Los colores se resuelven con `themeColor()`/`accentRGB()` para seguir el tema activo.
*   **Ciclo de vida:** los charts se destruyen y reconstruyen en cada `renderDashboard()` (`dashboardCharts`); se re-renderiza automáticamente en cada refresco de cola y con el monitor de 10s (handler de ventana línea ~470). El modal `#dashboard-modal` se abre desde el botón "Dashboard" del sidebar.

---

## 9. Perfiles de Conexión
Carga rápida de credenciales en el login, persistidas solo en `localStorage` del navegador:
*   **Almacenamiento:** clave `saved_profiles` (array `{name, ip, user, password}`). Funciones `getSavedProfiles`, `persistProfiles`, `loadProfileDropdown`, `applyProfile`, `saveProfilePrompt`.
*   **UI:** desplegable "PERFIL" en el formulario de login más botón `[+]` para guardar el perfil actual (pide nombre vía Swal) y botón `[−]` para eliminar el perfil seleccionado (con confirmación). Al seleccionar un perfil se autocompletan la IP (input oculto `name="ip"`), usuario y clave.
*   **Nota de seguridad:** las credenciales nunca salen del navegador ni se envían al servidor excepto en el propio `verify_login`; la app ya dispone del usuario puente (proxy) configurado en el Gatekeeper cuando corresponde.

---

## 10. Actualizaciones Automáticas (Updater)
La app se actualiza a sí misma desde GitHub. La versión local vive en `version.json` y la versión remota se compara con `version.json` de la rama configurada del repositorio.

### Empaquetado y Release
*   **`build_update_zip.ps1`:** construye `update_vX.Y.Z.zip` + `update_vX.Y.Z.zip.sha256`. Empaqueta **solo archivos versionados** (`git ls-files`) — nunca runtimes, datos de usuario ni respaldos — y agrega `_updater/version.txt` + `_updater/remove.txt` (archivos borrados desde el tag anterior, vía `git diff --name-status --diff-filter=D`).
*   **`.github/workflows/release.yml`:** al hacer `git tag vX.Y.Z && git push origin vX.Y.Z`, un runner Windows ejecuta el script y publica ambos assets con `softprops/action-gh-release`.
*   **Regla de versionado:** el `version` de `version.json` debe coincidir con el tag del release (`v1.8.11` ↔ `1.8.11`); el updater busca la release `tags/v<version_remota>`.

### Configuración y Estado
*   **`config/updater.json`** (por instancia, ignorado por git, nunca se sobrescribe al actualizar): `repo` (usuario/repo), `branch`, `auto_check`, `last_check`, `last_applied`, `last_applied_version`. Se edita desde el modal de Actualizaciones (`save_updater_config`) con validación de formato.
*   **`cache/updater_state.json`:** estado de la última comprobación (`remote_version`, `changelog`, `available`, `zip_url`, `sha256_url`, `checked_at`).
*   **`cache/update.lock`:** lock global para evitar dos actualizaciones simultáneas.

### Proceso de `apply()` (src/Updater.php)
1.  Resuelve URLs del release (`api.github.com/repos/.../releases/tags/v<ver>`).
2.  Descarga `update_vX.Y.Z.zip` y `.sha256` con cURL (`cacert.pem` local; si falta, `SSL_VERIFYPEER=false`) y verifica el hash SHA-256.
3.  Extrae a `cache/update_stage_<ts>` con `sanitizeRelPath()` (bloquea `..` y rutas absolutas) y `isProtectedPath()`.
4.  Ejecuta `php -l` sobre todo el PHP propio (excluye `vendor/` y `vendor74/`); si hay errores, rechaza el paquete.
5.  Genera `backups/update_<ts>/_manifest.json` (`replaced`/`new`/`removed`) y copia ahí lo que será reemplazado/eliminado.
6.  Copia el stage a la raíz y borra los archivos de `_updater/remove.txt`; ante cualquier fallo a mitad, `restoreBackup()` revierte automáticamente.
7.  Limpia stage/zip/lock y actualiza `config/updater.json` (`last_applied`).

### `rollback()`
Restaura el respaldo más reciente de `backups/update_*` (reverted `replaced`, restaura `removed`, borra `new`).

### Rutas protegidas (nunca se tocan al actualizar)
*   **Directorios:** `php`, `php74`, `php82`, `python`, `python311`, `python38`, `redist`, `uploads`, `exports`, `cache`, `backups`, `.git`, `backup_v1.7.5`, `backup_v1.8.0_pre_redesign`.
*   **Archivos:** `config/proxy.dat`, `config/gatekeeper.json`, `config/themes.json`, `config/templates.json`, `config/updater.json`, `config/updater_state.json`, `trace.log`, `debug_raw.txt`, `server_logs.txt`, `VC_redist.x64.exe`.

### Integración
*   **`process.php`:** acciones `updater_status`, `check_update`, `apply_update`, `rollback_update`, `save_updater_config` (requieren sesión; `apply/rollback/save` verifican el hash de Gatekeeper si está configurado). `check_gatekeeper`/`validate_gatekeeper`/`update_gatekeeper` funcionan pre-login.
*   **`index.php`:** botón "Actualizar" en el sidebar, modal `#updater-modal`, JS `openUpdater/loadUpdaterStatus/runUpdateCheck/runApplyUpdate/runRollbackUpdate/saveUpdaterConfig`, auto-check cada 24 h si `auto_check` está activo.
*   **Binario de lint:** `phpBinary()` elige `php82`/`php74` según `php_uname('r')` (Win7 usa 6.1).

---

## 6. Mantenimiento y Depuración
*   **Limpieza Automática:** El sistema purga archivos temporales de exportación periódicamente.
*   **Caché Local:** Utiliza LocalStorage para recordar el tema visual seleccionado y las últimas plantillas usadas.
*   **Colores Dinámicos:** Los componentes canvas (charts, warp) resuelven el color de acento actual mediante `themeColor()`/`accentRGB()`; los diálogos usan variables CSS (`--bg-panel`, `--text-main`, `--accent`).

---
*Ingeniería de Siguiente Generación para Sistemas Heredados.*
