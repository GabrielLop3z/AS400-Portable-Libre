# ⚙️ MANUAL DE INSTALACIÓN: SPOOL EXPLORER (PORTABLE)

Este sistema está diseñado para ser **"Plug & Play"** (conectar y usar). Ahora incluye configuración automática de acceso directo con icono profesional.

---

## 🚀 Paso 1: Copiar la aplicación
1.  Copia la carpeta completa `AS400_Portable_Libre` a tu computadora (escritorio, documentos o un USB).
2.  **IMPORTANTE:** No separes los archivos. Toda la carpeta debe estar junta para que funcione.
3.  **Si descargaste solo el código desde GitHub:** en la carpeta faltarán los componentes portables (`php74`, `php82`, `python311`, `python38`, `redist`). No te preocupes: el sistema los descargará e instalará automáticamente la primera vez que lo inicies.

---

## 🔌 Paso 2: Iniciar el Sistema (Auto-Configuración)
Haz doble clic en el archivo:
👉 **`Iniciar_Servidor.bat`**

El sistema realizará los siguientes pasos automáticamente:
1.  **Primera ejecución (descarga desde GitHub):** si detecta que faltan los componentes portables, descargará automáticamente `AS400_Portable_Runtime_x64.zip` (PHP + Python + VC Redist, 115 MB) desde los Releases de GitHub y lo descomprimirá en la carpeta. Este paso solo ocurre una vez y necesita internet.
2.  **Validación de Shortcut:** Si no tienes el acceso directo en el escritorio, el programa lo creará por ti con el nombre **"Spool"**.
3.  **Icono Premium:** Se configurará automáticamente el icono de portapapeles azul.
4.  **Autodiagnóstico:** Se iniciará el motor PHP y la aplicación se abrirá sola en tu navegador.

> Si la descarga automática no se completa, **`Configurar_Entorno.bat`** reintenta automáticamente desde **fuentes oficiales** (php.net, python.org y Microsoft); si tampoco lo logra, descarga manualmente `AS400_Portable_Runtime_x64.zip` desde el Release `runtime-v1` del repositorio y descomprímelo en la misma carpeta que `Iniciar_Servidor.bat`.

---

## 🛠️ Paso 3: ¿Qué hacer si aparece un error?

Si al iniciar ves un mensaje en rojo que dice:
> **"[ERROR CRITICO] El motor PHP no puede iniciarse"**

Significa que a tu Windows le faltan las librerías oficiales de Microsoft. Sigue estos pasos:

1.  El programa te preguntará: **"¿Desea instalar los requisitos ahora? (S/N)"**.
2.  Presiona la tecla **S** y luego **Enter**.
3.  Se abrirá el instalador oficial llamado `VC_redist.x64.exe` que ya incluimos en la carpeta.
4.  Sigue las instrucciones de instalación de Microsoft (Aceptar y Siguiente).
5.  **Una vez que termine la instalación**, vuelve a abrir el **`Iniciar_Servidor.bat`**.

---

## 🌐 Paso 4: Sin conexión a Internet
Una vez que los componentes portables están instalados (ya sea porque copiaste la carpeta completa o porque la primera ejecución los descargó), todo el entorno es portable y funciona **100% sin internet**. El instalador de requisitos y los manuales están incluidos localmente.

---

## 📋 Requisitos Mínimos:
*   **Sistema:** Windows 10 o Windows 11 (64 bits).
*   **Navegador:** Microsoft Edge o Google Chrome.
*   **Componente:** Visual C++ Redistributable (Incluido).

---
*Ing. Gabriel Lopez Reyes - Spool Explorer v1.7.5*
