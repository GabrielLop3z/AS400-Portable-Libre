# Spool | Explorador de Sistemas Master

**Spool** es una solución robusta y moderna diseñada para la extracción, visualización y procesamiento de archivos Spool de servidores IBM AS/400 (V5R3M0 y superiores). Esta herramienta nace como una alternativa eficiente a soluciones propietarias como Compleo Explorer, ofreciendo total portabilidad y cero dependencias.

## 🚀 Características Principales
- **Totalmente Portable**: Ejecute la aplicación desde cualquier carpeta o unidad USB sin instalar PHP o bases de datos.
- **Validación Real AS/400**: Acceso seguro mediante credenciales reales del mainframe.
- **Visualización Inteligente**: Motor de renderizado con resaltado de sintaxis para reportes antiguos.
- **Exportación Multi-formato**: Descargue sus reportes a Excel, PDF o TXT con un solo clic.
- **Editor de Cortes Visual**: Cree plantillas personalizadas para extraer datos estructurados de reportes complejos.
- **Context Menu 3.0**: Menú contextual avanzado con información en tiempo real y borrado seguro.
- **Dashboard Analítico 3.0**: Estadísticas de consumo, distribución técnica y KPIs de eficiencia.
- **Temas Dinámicos**: Personalice su entorno de trabajo con múltiples temas (Modo Oscuro, Turquesa, Verde, etc.).
- **Seguridad Gatekeeper**: Panel de administración protegido para gestionar el túnel de conexión.

## 🛠️ Instalación y Uso Rápido
1.  Descargue y extraiga la carpeta `Portable_Spool` (o el código desde GitHub).
2.  Haga doble clic en `Iniciar_Servidor.bat`.
3.  **Primera ejecución:** si faltan los componentes portables (PHP, Python, VC Redist), la aplicación los descarga e instala automáticamente desde los Releases de GitHub (115 MB). Solo necesita internet la primera vez.
4.  Ingrese la IP de su servidor AS/400 y sus credenciales de usuario.

> **Nota (descarga desde GitHub):** el repositorio solo contiene el código de la aplicación.
> Los componentes portables (`php74`, `php82`, `python311`, `python38`, `redist`) se descargan automáticamente al primer arranque mediante `Configurar_Entorno.bat`.
> Si el acceso a GitHub está restringido o la descarga falla, `Configurar_Entorno.bat` reintenta con **fuentes oficiales** (php.net, python.org y Microsoft) vía `Instalar_Runtime_Oficial.ps1`.
> Si la descarga automática falla por completo, descargue
> `AS400_Portable_Runtime_x64.zip` desde el Release `runtime-v1` y descomprímalo en la misma carpeta.

## 📖 Documentación para el Usuario
- [Manual de Usuario - Versión WEB](MANUAL_USUARIO_WEB.md)
- [Manual de Usuario - Versión PORTABLE](MANUAL_USUARIO_PORTABLE.md)

## 🔒 Seguridad
- **Cifrado de Credenciales**: El usuario puente se almacena cifrado en la bóveda local.
- **Panel Administrativo**: Acceso mediante icono de escudo `🛡️` con contraseña maestra 

---
© 2026 Desarrollado por **<GLR \>**.
