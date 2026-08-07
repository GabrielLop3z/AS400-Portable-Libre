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

## 6. Mantenimiento y Depuración
*   **Limpieza Automática:** El sistema purga archivos temporales de exportación periódicamente.
*   **Caché Local:** Utiliza LocalStorage para recordar el tema visual seleccionado y las últimas plantillas usadas.
*   **Colores Dinámicos:** Los componentes canvas (charts, warp) resuelven el color de acento actual mediante `themeColor()`/`accentRGB()`; los diálogos usan variables CSS (`--bg-panel`, `--text-main`, `--accent`).

---
*Ingeniería de Siguiente Generación para Sistemas Heredados.*
