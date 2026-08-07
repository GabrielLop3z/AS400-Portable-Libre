<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 | Sistema</title>
    <!-- Usamos CDN para que cargue sin importar qué tan rota esté la ruta en el navegador -->
    <script src="assets/tailwindcss.js"></script>
    <link rel="stylesheet" href="assets/fonts.css">
    <style>
        body { background-color: #0f1115; color: #cbd5e1; font-family: 'Inter', sans-serif; overflow: hidden; }
        .glass { background: rgba(0,0,0,0.6); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.05); }
        .bg-cyber { background-image: radial-gradient(circle at center, rgba(0,243,255,0.05) 0%, transparent 60%); }
    </style>
</head>
<body class="h-screen w-full flex items-center justify-center bg-[#0a0c10] bg-cyber relative">
    <!-- Grid elements -->
    <div class="absolute inset-0 z-0 opacity-[0.02]" style="background-image: linear-gradient(#ffffff 1px, transparent 1px), linear-gradient(90deg, #ffffff 1px, transparent 1px); background-size: 30px 30px;"></div>

    <div class="glass p-12 rounded-[2rem] flex flex-col items-center text-center relative z-10 w-[90%] max-w-xl mx-auto border-t border-t-white/10 shadow-[0_30px_60px_rgba(0,0,0,0.6)] animate-[pulse_5s_ease-in-out_infinite]">
        
        <div class="w-24 h-24 rounded-2xl bg-black/50 border border-white/10 flex items-center justify-center mb-8 rotate-12 shadow-[0_0_30px_rgba(0,243,255,0.2)]">
            <svg class="w-12 h-12 text-[#00f3ff]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        </div>
        
        <h1 class="text-8xl md:text-9xl font-black text-white leading-none tracking-tighter shadow-black drop-shadow-xl" style="text-shadow: 0 0 15px rgba(0,243,255,0.4);">404</h1>
        
        <div class="h-px w-20 bg-gradient-to-r from-transparent via-[#00f3ff]/50 to-transparent my-6"></div>
        
        <p class="text-[11px] font-[900] uppercase tracking-[0.4em] text-[#00f3ff] mb-6" style="font-family: 'JetBrains Mono', monospace;">Brecha en la matriz virtual</p>
        
        <p class="text-[14px] text-gray-400 mb-10 max-w-sm font-semibold">
            El nodo o documento al que intentas acceder no existe en la topología de este servidor AS/400 o el enlace está fragmentado.
        </p>
        
        <!-- Redirigir siempre mediante un script simple hacia la aplicacion -->
        <button onclick="window.location.href='/as400/app/'" class="px-8 py-4 bg-[#00f3ff]/10 border border-[#00f3ff]/30 text-[#00f3ff] hover:bg-[#00f3ff] hover:text-black rounded-xl text-[12px] font-black uppercase tracking-widest transition-all shadow-[0_0_20px_rgba(0,243,255,0.2)] hover:shadow-[0_0_30px_rgba(0,243,255,0.6)] hover:-translate-y-1 flex items-center gap-3">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            REINGRESAR AL SISTEMA
        </button>
    </div>
</body>
</html>
