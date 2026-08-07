# ⚙️ MANUAL DE INSTALACIÓN: SPOOL EXPLORER (PORTABLE)

Este sistema está diseñado para ser **"Plug & Play"** (conectar y usar). Ahora incluye configuración automática de acceso directo con icono profesional.

---

## 🚀 Paso 1: Copiar la aplicación
1.  Copia la carpeta completa `AS400_Portable_Libre` a tu computadora (escritorio, documentos o un USB).
2.  **IMPORTANTE:** No separes los archivos. Toda la carpeta debe estar junta para que funcione.

---

## 🔌 Paso 2: Iniciar el Sistema (Auto-Configuración)
Haz doble clic en el archivo:
👉 **`Iniciar_Servidor.bat`**

El sistema realizará los siguientes pasos automáticamente:
1.  **Validación de Shortcut:** Si no tienes el acceso directo en el escritorio, el programa lo creará por ti con el nombre **"Spool"**.
2.  **Icono Premium:** Se configurará automáticamente el icono de portapapeles azul.
3.  **Autodiagnóstico:** Se iniciará el motor PHP y la aplicación se abrirá sola en tu navegador.

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
Todo el entorno es portable y funciona **100% sin internet**. El instalador de requisitos y los manuales están incluidos localmente.

---

## 📋 Requisitos Mínimos:
*   **Sistema:** Windows 10 o Windows 11 (64 bits).
*   **Navegador:** Microsoft Edge o Google Chrome.
*   **Componente:** Visual C++ Redistributable (Incluido).

---
*Ing. Gabriel Lopez Reyes - Spool Explorer v1.7.5*
