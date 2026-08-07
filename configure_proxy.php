<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
use App\CredentialManager;

// CONFIGURACION DE ACCESO (ADMIN) - Ahora protegida por Hash (Bcrypt)
define('ADMIN_PASS_HASH', '$2y$10$nxCF1TH6ep0o9fbDkpK7.OxozPjHoJl3SiyywdmYr2icNW6g1GYYu'); 

$authenticated = $_SESSION['proxy_admin_auth'] ?? false;
$message = "";

if (isset($_POST['admin_login'])) {
    if (password_verify($_POST['admin_password'], ADMIN_PASS_HASH)) {
        $_SESSION['proxy_admin_auth'] = true;
        $authenticated = true;
    } else {
        $message = "<div class='error'>Contraseña de Administrador incorrecta.</div>";
    }
}

if (isset($_POST['logout'])) {
    unset($_SESSION['proxy_admin_auth']);
    header("Location: index.php");
    exit;
}

if ($authenticated && isset($_POST['proxy_user']) && isset($_POST['proxy_pass'])) {
    $user = $_POST['proxy_user'] ?? '';
    $pass = $_POST['proxy_pass'] ?? '';
    
    if ($user && $pass) {
        // Load Gatekeeper config
$gatekeeperFile = __DIR__ . '/config/gatekeeper.json';
$gatekeeperHash = '';
if (file_exists($gatekeeperFile)) {
    $data = json_decode(file_get_contents($gatekeeperFile), true);
    $gatekeeperHash = $data['hash'] ?? '';
}

if (!empty($gatekeeperHash)) {
    $gpwd = $_POST['gatekeeper_password'] ?? '';
    if (!$gpwd || !password_verify($gpwd, $gatekeeperHash)) {
        $message = "<div class='error'>Gatekeeper password invalid.</div>";
    } else {
        // Proceed to store credentials
        if (CredentialManager::store($user, $pass)) {
            $message = "<div class='success'>Credenciales guardadas y cifradas con éxito.</div>";
        } else {
            $message = "<div class='error'>Error al guardar el archivo. Verifique permisos.</div>";
        }
    }
} else {
    // No gatekeeper set, proceed normally
    if (CredentialManager::store($user, $pass)) {
        $message = "<div class='success'>Credenciales guardadas y cifradas con éxito.</div>";
    } else {
        $message = "<div class='error'>Error al guardar el archivo. Verifique permisos.</div>";
    }
}

    } else {
        $message = "<div class='error'>Ambos campos son obligatorios.</div>";
    }
}

$current = CredentialManager::load();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Gatekeeper | Professional Spool Engine</title>
    <meta charset="UTF-8">
    <link href="assets/fonts.css" rel="stylesheet">
    <style>
        :root {
            --accent: #00f3ff;
            --accent-glow: rgba(0, 243, 255, 0.4);
            --bg: #0f1115;
            --panel: rgba(20, 23, 28, 0.7);
            --text: #ffffff;
            --text-dim: #94a3b8;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--bg);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(0, 243, 255, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(59, 130, 246, 0.05) 0%, transparent 40%);
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
            color: var(--text);
            overflow: hidden;
        }

        .ambient-orb {
            position: fixed;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--accent-glow) 0%, transparent 70%);
            filter: blur(60px);
            z-index: -1;
            opacity: 0.3;
            animation: drift 20s infinite alternate ease-in-out;
        }

        @keyframes drift {
            from { transform: translate(-20%, -20%) scale(1); }
            to { transform: translate(20%, 20%) scale(1.2); }
        }

        .card { 
            background: var(--panel); 
            padding: 3.5rem; 
            border-radius: 40px; 
            box-shadow: 0 40px 100px rgba(0,0,0,0.6); 
            width: 100%; 
            max-width: 480px; 
            border: 1px solid rgba(255,255,255,0.05);
            backdrop-filter: blur(30px);
            position: relative;
            z-index: 10;
        }

        .logo-area {
            text-align: center;
            margin-bottom: 3rem;
        }

        .logo-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            display: inline-block;
            filter: drop-shadow(0 0 20px var(--accent-glow));
        }

        h2 { 
            color: var(--text); 
            margin: 0; 
            font-weight: 900;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-size: 1.5rem;
        }

        p.subtitle {
            color: var(--text-dim);
            font-size: 0.8rem;
            margin-top: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            font-weight: 600;
        }

        .form-group { margin-bottom: 2rem; }
        
        label { 
            display: block; 
            margin-bottom: 0.8rem; 
            font-weight: 800; 
            color: var(--text-dim);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }

        input { 
            width: 100%; 
            padding: 1.25rem; 
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 20px; 
            box-sizing: border-box; 
            color: white;
            font-size: 1.1rem;
            font-family: inherit;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: center;
        }

        input:focus {
            outline: none;
            border-color: var(--accent);
            background: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 30px rgba(0, 243, 255, 0.1);
            transform: scale(1.02);
        }

        button.btn-primary { 
            width: 100%; 
            padding: 1.25rem; 
            background: var(--accent);
            color: black; 
            border: none; 
            border-radius: 20px; 
            cursor: pointer; 
            font-weight: 900; 
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 10px 30px rgba(0, 243, 255, 0.3);
        }

        button.btn-primary:hover { 
            transform: translateY(-5px);
            background: white;
            box-shadow: 0 15px 40px rgba(0, 243, 255, 0.5);
        }

        button.btn-primary:active { transform: translateY(-2px); }

        .success { 
            color: #4ade80; 
            background: rgba(34, 197, 94, 0.1); 
            border: 1px solid rgba(34, 197, 94, 0.2); 
            padding: 1.25rem; 
            border-radius: 20px; 
            margin-bottom: 2rem; 
            font-size: 0.9rem;
            text-align: center;
            font-weight: 600;
        }

        .error { 
            color: #f87171; 
            background: rgba(239, 68, 68, 0.1); 
            border: 1px solid rgba(239, 68, 68, 0.2); 
            padding: 1.25rem; 
            border-radius: 20px; 
            margin-bottom: 2rem; 
            font-size: 0.9rem;
            text-align: center;
            font-weight: 600;
        }

        .credential-info { 
            font-size: 0.8rem; 
            color: var(--text-dim); 
            margin-top: 2rem; 
            text-align: center;
            background: rgba(255,255,255,0.02);
            padding: 1rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .credential-info strong {
            color: var(--accent);
            font-weight: 900;
        }

        .back-link { 
            display: block;
            text-align: center; 
            margin-top: 2.5rem;
            color: var(--text-dim); 
            font-size: 0.75rem; 
            text-decoration: none;
            transition: all 0.3s;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }
        
        .back-link:hover { color: var(--accent); letter-spacing: 0.25em; }
    </style>
</head>
<body>
    <div class="ambient-orb" style="top: 0; left: 0;"></div>
    <div class="ambient-orb" style="bottom: 0; right: 0; animation-delay: -10s;"></div>
    <div class="card">
        <div class="logo-area">
            <span class="logo-icon"><?php echo $authenticated ? '🔐' : '🛡️'; ?></span>
            <h2><?php echo $authenticated ? 'Gatekeeper' : 'Admin Login'; ?></h2>
            <p class="subtitle"><?php echo $authenticated ? 'Configuración del Puente AS/400' : 'Acceso Restringido'; ?></p>
        </div>
        
        <?php echo $message; ?>

        <?php if (!$authenticated): ?>
            <form method="POST">
                <div class="form-group">
                    <label>Contraseña Maestra</label>
                    <input type="password" name="admin_password" placeholder="Introduzca PIN de acceso" required autofocus>
                </div>
                <button type="submit" name="admin_login" class="btn-primary">Desbloquear Panel</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label>Usuario Técnico</label>
                    <input type="text" name="proxy_user" placeholder="Ej: SPOOL_SVC" value="<?php echo htmlspecialchars($current['user'] ?? ''); ?>" required autocomplete="off">
                </div>
<?php if (!empty($gatekeeperHash)): ?>
                    <div class="form-group">
                        <label>Contraseña Gatekeeper</label>
                        <input type="password" name="gatekeeper_password" placeholder="PIN de Gatekeeper" required>
                    </div>
<?php endif; ?>
                    <div class="form-group">
                    <label>Contraseña de Sistema</label>
                    <input type="password" name="proxy_pass" placeholder="••••••••••••" required>
                </div>
                <button type="submit" class="btn-primary">Actualizar Bóveda</button>
            </form>

            <div class="credential-info">
                <?php if ($current): ?>
                    Activo: <strong><?php echo htmlspecialchars($current['user']); ?></strong>
                <?php else: ?>
                    🔌 Esperando configuración inicial...
                <?php endif; ?>
            </div>
            
            <form method="POST" style="margin-top: 1rem;">
                <button type="submit" name="logout" style="background: transparent; border: 1px solid var(--text-muted); color: var(--text-muted); padding: 0.5rem; font-size: 0.8rem; box-shadow: none;">Cerrar Sesión Segura</button>
            </form>
        <?php endif; ?>

        <a href="index.php" class="back-link">← Cancelar y volver al Explorador</a>
    </div>
</body>
</html>
