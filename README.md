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

### Opción A — Descarga única (todo incluido) ⭐ Recomendada
1.  En la página de [Releases](https://github.com/GabrielLop3z/AS400-Portable-Libre/releases), descargue
    **`AS400_Portable_Libre_vX.Y.Z_TODO_INCLUIDO.zip`** (un solo archivo con todo: código + PHP 7.4/8.2 + Python 3.8/3.11 + VC Redist).
2.  Descomprima la carpeta en cualquier ubicación (unidad USB incluida).
3.  Haga doble clic en `Iniciar_Servidor.bat` — funciona sin instalación y **sin necesidad de internet**.
4.  Ingrese la IP de su servidor AS/400 y sus credenciales de usuario.

### Opción B — Código + auto-descarga del entorno
1.  Descargue y extraiga el código desde GitHub.
2.  Haga doble clic en `Iniciar_Servidor.bat`.
3.  **Primera ejecución:** si faltan los componentes portables (PHP, Python, VC Redist), la aplicación los descarga e instala automáticamente. Solo necesita internet la primera vez.

> **Nota (descarga desde GitHub):** el repositorio solo contiene el código de la aplicación.
> Los componentes portables (`php74`, `php82`, `python311`, `python38`, `redist`) se descargan automáticamente al primer arranque mediante `Configurar_Entorno.bat`.
> Si el acceso a GitHub está restringido o la descarga falla, `Configurar_Entorno.bat` reintenta con **fuentes oficiales** (php.net, python.org y Microsoft) vía `Instalar_Runtime_Oficial.ps1`.

### Actualizaciones de instalaciones existentes
Si ya tiene la aplicación, actualícela desde el menú **Actualizaciones** (paquete `update_vX.Y.Z.zip`),
que solo reemplaza el código sin tocar sus datos, configuración ni el entorno portable.

## 📖 Documentación para el Usuario
- [Manual de Usuario - Versión WEB](MANUAL_USUARIO_WEB.md)
- [Manual de Usuario - Versión PORTABLE](MANUAL_USUARIO_PORTABLE.md)

## 🔒 Seguridad
- **Cifrado de Credenciales**: El usuario puente se almacena cifrado en la bóveda local.
- **Panel Administrativo**: Acceso mediante icono de escudo `🛡️` con contraseña maestra 

---
© 2026 Desarrollado por **<GLR \>**.
