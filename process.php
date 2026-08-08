<?php
if (PHP_VERSION_ID < 80000 && file_exists(__DIR__ . '/vendor74/autoload.php')) { require_once __DIR__ . '/vendor74/autoload.php'; } else { require_once __DIR__ . '/vendor/autoload.php'; }

use App\Parser;
use App\Updater;

error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '2048M');
set_time_limit(300);

$input = json_decode(file_get_contents('php://input'), true);
$action = $_POST['action'] ?? $input['action'] ?? null;

if (in_array($action, ['list_remote', 'fetch_remote', 'get_user_info', 'export', 'save_template', 'load_templates', 'delete_template', 'rename_template', 'save_theme', 'reset_theme', 'spool_action', 'updater_status', 'check_update', 'apply_update', 'rollback_update', 'save_updater_config', 'submit_feedback', 'feedback_status', 'save_feedback_config'], true)) {
    session_start();
}

// --- CONFIGURACION DE LLAVE MAESTRA (USUARIO PROXY) ---
$proxyCreds = \App\CredentialManager::load();
define('PROXY_USER', $proxyCreds['user'] ?? '');
define('PROXY_PASS', $proxyCreds['pass'] ?? '');
define('USE_PROXY', !empty(PROXY_USER));
// --- GATEKEEPER CONFIGURATION ---
$gatekeeperFile = __DIR__ . '/config/gatekeeper.json';
$gatekeeperHash = '';
if (file_exists($gatekeeperFile)) {
    $data = json_decode(file_get_contents($gatekeeperFile), true);
    $gatekeeperHash = $data['hash'] ?? '';
}
function saveGatekeeperHash($hash) {
    $file = __DIR__ . '/config/gatekeeper.json';
    $payload = ['hash' => $hash];
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT));
}

function isListArray($arr) {
    if (!is_array($arr)) return false;
    $i = 0;
    foreach (array_keys($arr) as $k) {
        if ($k !== $i++) return false;
    }
    return true;
}

// Convierte "#rrggbb" en "r, g, b" (para tokens RGB de los temas)
function themeHexToRgb($hex) {
    $hex = trim((string)$hex);
    if (preg_match('/^\s*[\d]+\s*,\s*[\d]+\s*,\s*[\d]+\s*$/', $hex)) return $hex;
    if ($hex === '' || $hex[0] !== '#') return '';
    $c = ltrim($hex, '#');
    if (strlen($c) === 3) $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $c)) return '';
    return hexdec(substr($c, 0, 2)) . ', ' . hexdec(substr($c, 2, 2)) . ', ' . hexdec(substr($c, 4, 2));
}

// Valida un color hex "#rrggbb" (o devuelve el fallback)
function themeSanitizeHex($val, $fallback) {
    $val = trim((string)$val);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) return strtolower($val);
    return $fallback;
}

function themeSanitizeFont($val, $fallback = 'Arial') {
    $norm = strtolower(str_replace(["'", '"'], '', (string)$val));
    $allowed = ['arial', 'jetbrains mono', 'outfit', 'inter'];
    if (in_array($norm, $allowed, true)) {
        return $norm === 'jetbrains mono' ? "'JetBrains Mono'" : ($norm === 'outfit' ? "'Outfit'" : ($norm === 'inter' ? "'Inter'" : 'Arial'));
    }
    return $fallback;
}

// Carga la boveda de temas (config/themes.json)
function loadThemes() {
    $file = __DIR__ . '/config/themes.json';
    $themes = (file_exists($file)) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    // Fallback a la copia de fabrica si falta o esta vacia/corrupta.
    if (empty($themes)) {
        $default = __DIR__ . '/config/themes.default.json';
        if (file_exists($default)) $themes = json_decode(file_get_contents($default), true) ?: [];
    }
    return $themes;
}

function saveThemes($themes) {
    $file = __DIR__ . '/config/themes.json';
    return file_put_contents($file, json_encode($themes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// Normaliza plantillas legacy (array) al schema v1.8 (objetos clave-valor)
function migrateTemplateSchema($data) {
    if (!is_array($data)) $data = [];

    if (!isset($data['horizontalLines']) || !is_array($data['horizontalLines']) || count($data['horizontalLines']) === 0) {
        $data['horizontalLines'] = [0];
    }
    $hl = array_values($data['horizontalLines']);

    // bandColumns: array de arrays (legacy) -> objeto { startRow: [cols] }
    if (isset($data['bandColumns']) && is_array($data['bandColumns'])) {
        if (isListArray($data['bandColumns'])) {
            $bc = [];
            foreach ($data['bandColumns'] as $i => $cols) {
                $key = isset($hl[$i]) ? $hl[$i] : $i;
                if (!is_array($cols)) $cols = [$cols];
                $bc[(string)$key] = array_values(array_map('intval', $cols));
            }
        } else {
            $bc = $data['bandColumns'];
        }
        $data['bandColumns'] = (object)$bc;
    }
    if (!isset($data['bandColumns']) || !is_object($data['bandColumns'])) {
        $data['bandColumns'] = (object)['0' => [0]];
    }

    // columnAliases / columnHidden: arrays vacios/numericos (legacy) -> objeto {}
    foreach (['columnAliases', 'columnHidden'] as $field) {
        if (!isset($data[$field])) {
            $data[$field] = new stdClass();
        } elseif (is_array($data[$field]) && isListArray($data[$field])) {
            $data[$field] = new stdClass();
        }
    }

    if (!isset($data['styleRules']) || !is_array($data['styleRules'])) {
        $data['styleRules'] = [];
    }
    $data['smartHighlightActive'] = isset($data['smartHighlightActive']) ? (bool)$data['smartHighlightActive'] : true;
    return $data;
}

function refineError($msg) {
    if (strpos($msg, '10060') !== false || strpos($msg, 'timed out') !== false) {
        return "ERROR DE RED: No se pudo conectar al Mainframe (Timeout). Verifique que esté conectado a la VPN o Red Corporativa.";
    }
    if (strpos($msg, '10061') !== false || strpos($msg, 'refused') !== false) {
        return "ERROR DE ACCESO: El servidor AS/400 rechazó la conexión. Verifique que el servicio FTP esté activo en el host.";
    }
    if (strpos($msg, '10054') !== false) {
        return "CONEXIÓN PERDIDA: El Mainframe cerró la conexión de forma inesperada.";
    }
    return $msg;
}

// Usuarios con privilegio de ver TODOS los spools (su nombre empieza por TID o TIO)
function isPrivilegedSpoolUser($userId) {
    $u = strtoupper((string)$userId);
    return strpos($u, 'TID') === 0 || strpos($u, 'TIO') === 0;
}

// Extrae el usuario propietario de un job con formato "numero/usuario/nombre"
function jobOwner($job) {
    $parts = explode('/', (string)$job);
    return isset($parts[1]) ? trim($parts[1]) : '';
}

// Valida que el job pertenezca al usuario de la sesión (salvo usuarios privilegiados TID/TIO)
function assertSpoolOwnership($job, $sessionUser) {
    if (isPrivilegedSpoolUser($sessionUser)) return;
    $job = trim((string)$job);
    if ($job === '' || $job === '*') {
        throw new Exception('No tienes permiso para acceder a este spool.');
    }
    $owner = strtoupper(jobOwner($job));
    if ($owner === '' || $owner !== strtoupper((string)$sessionUser)) {
        throw new Exception('No tienes permiso para acceder a este spool.');
    }
}

// --- CONFIGURACION DE COMENTARIOS / FEEDBACK (issues de GitHub) ---
function loadFeedbackConfig() {
    $file = __DIR__ . '/config/feedback.json';
    return (file_exists($file)) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

function saveFeedbackConfig($cfg) {
    $file = __DIR__ . '/config/feedback.json';
    @mkdir(dirname($file), 0777, true);
    return file_put_contents($file, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function feedbackGitHubRequest($url, $token, $payload) {
    if (!function_exists('curl_init')) {
        throw new Exception('La extensión cURL no está disponible en PHP.');
    }
    $ch = curl_init($url);
    $caFile = __DIR__ . '/cacert.pem';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 6,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'AS400-Portable-Libre-Feedback',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: AS400-Portable-Libre-Feedback'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    if (file_exists($caFile)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caFile);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        throw new Exception('Fallo de red al contactar GitHub: ' . $err);
    }
    return ['code' => $code, 'body' => $body];
}

try {
    $session = $_SESSION['as400_session'] ?? null;

    if ($action === 'list_remote' || $action === 'fetch_remote' || $action === 'get_user_info' || $action === 'export') {
        if (!$session || empty($session['user_id']) || empty($session['password'])) {
            throw new Exception('Sesión no válida o campos vacíos. Por favor, inicie sesión de nuevo.');
        }
    }

    if ($action === 'verify_login') {
        $ip = $input['ip'] ?? '';
        $user = $input['user'] ?? '';
        $pass = $input['password'] ?? '';

        if (!$ip || !$user || !$pass) throw new Exception('Campos incompletos.');

        $isWin7 = (strpos(php_uname('r'), '6.1') !== false);
        $pythonBin = 'python';
        
        // Deteccion Inteligente: Win7 prefiere Python 3.8, Win10+ prefiere Python 3.11/Portable
        $dirs = $isWin7 ? ['python38', 'python'] : ['python311', 'python', 'python38'];
        
        foreach ($dirs as $d) {
            if (file_exists(__DIR__ . "/$d/python.exe")) {
                $pythonBin = __DIR__ . "/$d/python.exe";
                break;
            } elseif (file_exists(dirname(__DIR__) . "/$d/python.exe")) {
                $pythonBin = dirname(__DIR__) . "/$d/python.exe";
                break;
            }
        }
        
        // Intentamos un listado rápido del propio usuario para verificar credenciales
        $cmd = escapeshellarg($pythonBin) . " " . escapeshellarg(__DIR__ . '/src/spool_explorer.py') . " " . 
               escapeshellarg($ip) . " " . 
               escapeshellarg($user) . " " . 
               escapeshellarg($pass) . " list " .
               escapeshellarg($user);
        
        $output = shell_exec($cmd . " 2>&1");
        $output = trim($output);
        
        $res = null;
        if (preg_match('/\{.*\}/s', $output, $matches)) {
            $res = json_decode($matches[0], true);
        }

        if ($res) {
            $isSuccess = $res['success'] ?? false;
            $msg = $res['message'] ?? '';
            
            // Un login es válido si:
            // 1. Python dice success: true
            // 2. Python dice success: false PERO el mensaje es que no hay spools (esto implica que el login sí funcionó)
            if ($isSuccess || strpos($msg, 'No se encontraron spools') !== false) {
                 session_start();
                 $_SESSION['as400_session'] = [
                    'ip' => $ip,
                    'user_id' => $user,
                    'password' => $pass,
                    'logged_at' => date('Y-m-d H:i:s')
                 ];
                 echo json_encode(['success' => true]);
            } else {
                 // Cualquier otro error (530 Login incorrect, Timeout, etc)
                 $errorMsg = 'Error de autenticación';
                 if (strpos($msg, '530') !== false || strpos($msg, 'login') !== false) {
                     $errorMsg = 'Usuario o contraseña de AS/400 incorrectos.';
                 } else if ($msg) {
                     $errorMsg = refineError($msg);
                 }
                 echo json_encode(['success' => false, 'message' => $errorMsg]);
            }
        } else {
            // Convirtiendo salida a UTF-8 para evitar que json_encode devuelva false en caracteres especiales de la consola Windows (Win7 usa CP850/1252)
            $cleanOutput = mb_convert_encoding(substr($output, 0, 250), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, CP850');
            // Si la conversion falla o el output es vacio, intentamos devolver el pedazo crudo o un aviso
            $finalMsg = !empty($cleanOutput) ? $cleanOutput : (!empty($output) ? "Response error (binary data?)" : "No output from python motor");
            echo json_encode(['success' => false, 'message' => 'El servidor AS/400 no responde o el motor falló: ' . $finalMsg], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    } elseif ($action === 'list_remote') {
        $isWin7 = (strpos(php_uname('r'), '6.1') !== false);
        $pythonBin = 'python';
        $dirs = $isWin7 ? ['python38', 'python'] : ['python311', 'python', 'python38'];
        
        foreach ($dirs as $d) {
            if (file_exists(__DIR__ . "/$d/python.exe")) {
                $pythonBin = __DIR__ . "/$d/python.exe";
                break;
            } elseif (file_exists(dirname(__DIR__) . "/$d/python.exe")) {
                $pythonBin = dirname(__DIR__) . "/$d/python.exe";
                break;
            }
        }
        
        $connUser = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_USER : $session['user_id'];
        $connPass = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_PASS : $session['password'];
        
        if (!isPrivilegedSpoolUser($session['user_id'])) {
            // Los usuarios regulares solo pueden listar SUS propios spools.
            $targetUser = $session['user_id'];
        } else {
            $targetUser = trim($input['target_user'] ?? $_POST['target_user'] ?? '');
            if (empty($targetUser)) {
                $targetUser = $session['user_id'];
            }
        }
        $pageLimit  = max(1, intval($input['limit']  ?? 200));
        $pageOffset = max(0, intval($input['offset'] ?? 0));
        $filterName = trim($input['filter_name'] ?? '');

        $cmd = escapeshellarg($pythonBin) . " " . escapeshellarg(__DIR__ . '/src/spool_explorer.py') . " " . 
               escapeshellarg($session['ip']) . " " . 
               escapeshellarg($connUser) . " " . 
               escapeshellarg($connPass) . " list " .
               escapeshellarg($targetUser) . " " .
               escapeshellarg($pageLimit)  . " " .
               escapeshellarg($pageOffset) . " " .
               escapeshellarg($filterName);
        
        $cmd .= " 2>&1";
        $output = shell_exec($cmd);
        $output = trim($output);
        
        if (preg_match('/\{.*\}/s', $output, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json && !$json['success']) {
                $json['message'] = refineError($json['message']);
                echo json_encode($json);
            } else {
                echo $matches[0];
            }
        } else {
            $cleanOutput = mb_convert_encoding($output, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, CP850');
            $finalMsg = !empty($cleanOutput) ? $cleanOutput : (!empty($output) ? "Response error (binary data?)" : "No output from python motor");
            echo json_encode(['success' => false, 'message' => refineError($finalMsg)], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }

    } elseif ($action === 'fetch_remote') {
        $file = $_POST['file'] ?? $input['file'] ?? 'QPRINT';
        $job = $_POST['job'] ?? $input['job'] ?? '*';
        $number = $_POST['number'] ?? $input['number'] ?? '*LAST';

        assertSpoolOwnership($job, $session['user_id']);

        $isWin7 = (strpos(php_uname('r'), '6.1') !== false);
        $pythonBin = 'python';
        $dirs = $isWin7 ? ['python38', 'python'] : ['python311', 'python', 'python38'];
        
        foreach ($dirs as $d) {
            if (file_exists(__DIR__ . "/$d/python.exe")) {
                $pythonBin = __DIR__ . "/$d/python.exe";
                break;
            } elseif (file_exists(dirname(__DIR__) . "/$d/python.exe")) {
                $pythonBin = dirname(__DIR__) . "/$d/python.exe";
                break;
            }
        }
        
        $connUser = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_USER : $session['user_id'];
        $connPass = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_PASS : $session['password'];

        $cmd = escapeshellarg($pythonBin) . " " . escapeshellarg(__DIR__ . '/src/spool_explorer.py') . " " . 
               escapeshellarg($session['ip']) . " " . 
               escapeshellarg($connUser) . " " . 
               escapeshellarg($connPass) . " fetch " . 
               escapeshellarg($file) . " " . escapeshellarg($job) . " " . escapeshellarg($number);
        
        $cmd .= " 2>&1";
        $raw = shell_exec($cmd);
        
        if (preg_match('/\{.*\}/s', $raw, $matches)) {
            $res = json_decode($matches[0], true);
        } else {
            throw new Exception(refineError($raw));
        }

        if ($res && $res['success']) {
            $content = implode("\n", $res['data']);
            $parser = new Parser($content);
            $parsedData = $parser->parse();
            echo json_encode(['success' => true, 'data' => $parsedData, 'raw_lines' => $res['data']], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            $msg = $res['message'] ?? 'Fetch failed';
            $cleanMsg = mb_convert_encoding($msg, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, CP850');
            throw new Exception(refineError($cleanMsg));
        }

    } elseif ($action === 'spool_action') {
        // Gestión de spools en el AS/400 (DLT/HLD/RLS/CHGSPLFA/reprint)
        $spAction = trim($input['sp_action'] ?? $_POST['sp_action'] ?? '');
        $file = trim($input['file'] ?? $_POST['file'] ?? '');
        $job  = trim($input['job'] ?? $_POST['job'] ?? '*');
        $number = trim($input['number'] ?? $_POST['number'] ?? '*LAST');
        if ($spAction === 'delete') {
            echo json_encode(['success' => false, 'message' => 'La eliminación de spools fue deshabilitada.']);
            exit;
        }
        if (!in_array($spAction, ['hold', 'release', 'reprint', 'change'], true)) {
            echo json_encode(['success' => false, 'message' => 'Acción de spool inválida.']);
            exit;
        }
        if ($spAction !== 'change' && ($file === '')) {
            echo json_encode(['success' => false, 'message' => 'Faltan datos del spool.']);
            exit;
        }
        assertSpoolOwnership($job, $session['user_id']);
        $params = $input['params'] ?? $_POST['params'] ?? [];
        if (!is_array($params)) $params = [];

        $isWin7 = (strpos(php_uname('r'), '6.1') !== false);
        $pythonBin = 'python';
        $dirs = $isWin7 ? ['python38', 'python'] : ['python311', 'python', 'python38'];
        foreach ($dirs as $d) {
            if (file_exists(__DIR__ . "/$d/python.exe")) {
                $pythonBin = __DIR__ . "/$d/python.exe";
                break;
            } elseif (file_exists(dirname(__DIR__) . "/$d/python.exe")) {
                $pythonBin = dirname(__DIR__) . "/$d/python.exe";
                break;
            }
        }

        $connUser = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_USER : $session['user_id'];
        $connPass = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_PASS : $session['password'];

        $cmd = escapeshellarg($pythonBin) . " " . escapeshellarg(__DIR__ . '/src/spool_explorer.py') . " " .
               escapeshellarg($session['ip']) . " " .
               escapeshellarg($connUser) . " " .
               escapeshellarg($connPass) . " manage " .
               escapeshellarg($spAction) . " " .
               escapeshellarg($file) . " " .
               escapeshellarg($job) . " " .
               escapeshellarg($number) . " " .
               escapeshellarg(json_encode($params));

        $cmd .= " 2>&1";
        $output = shell_exec($cmd);
        $output = trim($output);

        if (preg_match('/\{.*\}/s', $output, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json && !$json['success']) {
                $json['message'] = refineError($json['message']);
                echo json_encode($json);
            } else {
                echo $matches[0];
            }
        } else {
            $cleanOutput = mb_convert_encoding($output, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, CP850');
            $finalMsg = !empty($cleanOutput) ? $cleanOutput : (!empty($output) ? "Response error (binary data?)" : "No output from python motor");
            echo json_encode(['success' => false, 'message' => refineError($finalMsg)], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        exit;

    } elseif ($action === 'save_template') {
        $name = $_POST['name'] ?? $input['name'] ?? null;
        $data = $input['data'] ?? null;
        if (!$name || !$data) throw new Exception('Missing template data.');
        
        $user_id = strtoupper($session['user_id'] ?? 'USER');
        if (strpos($name, $user_id . ' - ') !== 0) {
            $name = $user_id . ' - ' . $name;
        }

        $configDir = __DIR__ . '/config';
        if (!is_dir($configDir)) mkdir($configDir, 0777, true);
        $templatesFile = $configDir . '/templates.json';
        $templates = file_exists($templatesFile) ? (json_decode(file_get_contents($templatesFile), true) ?: []) : [];
        $templates[$name] = migrateTemplateSchema($data);
        file_put_contents($templatesFile, json_encode($templates, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
    } elseif ($action === 'load_templates') {
        $templatesFile = __DIR__ . '/config/templates.json';
        $templates = file_exists($templatesFile) ? (json_decode(file_get_contents($templatesFile), true) ?: []) : [];
        $migrated = [];
        foreach ($templates as $name => $tpl) {
            $migrated[$name] = migrateTemplateSchema($tpl);
        }
        echo json_encode(['success' => true, 'templates' => $migrated, 'currentUser' => strtoupper($session['user_id'] ?? 'USER')]);
    } elseif ($action === 'delete_template') {
        $name = $_POST['name'] ?? $input['name'] ?? null;
        if (!$name) throw new Exception('Falta el nombre de la plantilla.');
        $user_id = strtoupper($session['user_id'] ?? 'USER');
        if (strpos($name, $user_id . ' - ') !== 0) {
            throw new Exception('Solo puedes eliminar tus propias plantillas.');
        }
        $templatesFile = __DIR__ . '/config/templates.json';
        $templates = file_exists($templatesFile) ? (json_decode(file_get_contents($templatesFile), true) ?: []) : [];
        if (!isset($templates[$name])) throw new Exception('Plantilla no encontrada.');
        unset($templates[$name]);
        file_put_contents($templatesFile, json_encode($templates, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
    } elseif ($action === 'rename_template') {
        $old = $_POST['name'] ?? $input['name'] ?? null;
        $new = $_POST['newName'] ?? $input['newName'] ?? null;
        if (!$old || !$new) throw new Exception('Faltan datos para renombrar.');
        $user_id = strtoupper($session['user_id'] ?? 'USER');
        if (strpos($old, $user_id . ' - ') !== 0) {
            throw new Exception('Solo puedes renombrar tus propias plantillas.');
        }
        $newName = (strpos($new, $user_id . ' - ') === 0) ? $new : $user_id . ' - ' . trim($new);
        $templatesFile = __DIR__ . '/config/templates.json';
        $templates = file_exists($templatesFile) ? (json_decode(file_get_contents($templatesFile), true) ?: []) : [];
        if (!isset($templates[$old])) throw new Exception('Plantilla no encontrada.');
        if (isset($templates[$newName])) throw new Exception('Ya existe una plantilla con ese nombre.');
        $templates[$newName] = $templates[$old];
        unset($templates[$old]);
        file_put_contents($templatesFile, json_encode($templates, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'name' => $newName]);
    } elseif ($action === 'parse') {
        $content = isset($_FILES['report']) ? file_get_contents($_FILES['report']['tmp_name']) : ($input['content'] ?? '');
        $parser = new Parser($content);
        echo json_encode(['success' => true, 'data' => $parser->parse()], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($action === 'get_user_info') {
        
        $isWin7 = (strpos(php_uname('r'), '6.1') !== false);
        $pythonBin = 'python';
        $dirs = $isWin7 ? ['python38', 'python'] : ['python311', 'python', 'python38'];
        
        foreach ($dirs as $d) {
            if (file_exists(__DIR__ . "/$d/python.exe")) {
                $pythonBin = __DIR__ . "/$d/python.exe";
                break;
            } elseif (file_exists(dirname(__DIR__) . "/$d/python.exe")) {
                $pythonBin = dirname(__DIR__) . "/$d/python.exe";
                break;
            }
        }
        $connUser = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_USER : $session['user_id'];
        $connPass = (USE_PROXY && strtoupper($session['user_id']) !== strtoupper(PROXY_USER)) ? PROXY_PASS : $session['password'];

        $cmd = escapeshellarg($pythonBin) . " " . escapeshellarg(__DIR__ . '/src/spool_explorer.py') . " " . 
               escapeshellarg($session['ip']) . " " . 
               escapeshellarg($connUser) . " " . 
               escapeshellarg($connPass) . " user_info " .
               escapeshellarg($session['user_id']);
        
        $output = shell_exec($cmd . " 2>&1");
        echo trim($output);

    } elseif ($action === 'updater_status') {
        $updater = new Updater();
        echo json_encode(array_merge(['success' => true], $updater->getStatus()));

    } elseif ($action === 'check_update') {
        $updater = new Updater();
        echo json_encode($updater->checkForUpdates());

    } elseif ($action === 'apply_update' || $action === 'rollback_update' || $action === 'save_updater_config') {
        if (empty($session)) {
            throw new Exception('Sesión no válida. Inicie sesión de nuevo.');
        }
        if (!empty($gatekeeperHash)) {
            $apwd = $input['password'] ?? $_POST['password'] ?? null;
            if (!$apwd || !password_verify($apwd, $gatekeeperHash)) {
                echo json_encode(['success' => false, 'message' => 'Acceso de administrador inválido.']);
                exit;
            }
        }
        $updater = new Updater();
        if ($action === 'apply_update') {
            echo json_encode($updater->apply());
        } elseif ($action === 'rollback_update') {
            echo json_encode($updater->rollback());
        } else {
            $repo = $input['repo'] ?? $_POST['repo'] ?? '';
            $branch = $input['branch'] ?? $_POST['branch'] ?? 'main';
            $autoCheck = (($input['auto_check'] ?? $_POST['auto_check'] ?? null) === false) ? false : true;
            echo json_encode(['success' => true, 'config' => $updater->updateConfig($repo, $branch, $autoCheck)]);
        }
        exit;

    } elseif ($action === 'export') {
        $type = $input['type'] ?? 'excel';
        $data = $input['data'] ?? null;
        if (!$data) throw new Exception('No data to export.');
        // Normalizar filas: si una fila llega como string, la envolvemos en array para evitar fatales
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = array_map(function ($row) {
                return is_array($row) ? $row : [$row];
            }, $data['data']);
        }
        
        $exportDir = __DIR__ . '/exports';
        if (!is_dir($exportDir)) mkdir($exportDir, 0777, true);
        
        $fileName = 'report_' . date('Ymd_His');
        $downloadName = '';
        if ($type === 'excel') {
            $downloadName = $fileName . '.xlsx';
            $styleRules = $input['styleRules'] ?? [];
            $smartHighlight = $input['smartHighlight'] ?? true;
            (new \App\ExcelExporter())->export($data['headers'], $data['data'], $exportDir . '/' . $downloadName, $styleRules, $smartHighlight, $data['bold_rows'] ?? []);
        } elseif ($type === 'pdf') {
            $downloadName = $fileName . '.pdf';
            $orientation = $input['orientation'] ?? null;
            $pdfTemplate = $input['pdfTemplate'] ?? 'default';
            $pdfStampText = $input['pdfStampText'] ?? 'OFICIAL';
            $pdfStampStyle = $input['pdfStampStyle'] ?? 'classic';
            (new \App\PdfExporter())->export($data['headers'], $data['data'], $exportDir . '/' . $downloadName, $orientation, $pdfTemplate, $pdfStampText, $pdfStampStyle);


        } elseif ($type === 'word') {
            $downloadName = $fileName . '.docx';
            (new \App\WordExporter())->export($data['headers'], $data['data'], $exportDir . '/' . $downloadName);
        } elseif ($type === 'print_html') {
            $pdfTemplate = $input['pdfTemplate'] ?? 'default';
            $pdfStampText = $input['pdfStampText'] ?? 'OFICIAL';
            $pdfStampStyle = $input['pdfStampStyle'] ?? 'classic';
            $html = (new \App\PdfExporter())->getHtmlForPrint($data['headers'], $data['data'], $pdfTemplate, $pdfStampText, $pdfStampStyle);
            echo json_encode(['success' => true, 'html' => $html]);
            exit;

        } elseif ($type === 'txt') {

            $downloadName = $fileName . '.txt';
            $content = "";
            // Si solo hay una columna, asumimos que es modo RAW (líneas directas)
            if (count($data['headers']) === 1) {
                foreach($data['data'] as $row) {
                    $content .= ($row[0] ?? '') . "\r\n";
                }
            } else {
                // Modo GRID: Separamos por tabulaciones para compatibilidad con Excel/Notepad
                $hasCustomHeaders = false;
                foreach ($data['headers'] as $h) {
                    if ($h !== '' && !preg_match('/^(CAMPO|CABECERA|COLUMNA|COL|COLUMN)_\d+$/i', $h)) {
                        $hasCustomHeaders = true;
                        break;
                    }
                }
                if ($hasCustomHeaders) {
                    $headerLine = array_map(function ($h) {
                        return preg_match('/^(CAMPO|CABECERA|COLUMNA|COL|COLUMN)_\d+$/i', $h) ? '' : $h;
                    }, $data['headers']);
                    $content .= implode("\t", $headerLine) . "\r\n";
                }
                foreach($data['data'] as $row) {
                    $content .= implode("\t", $row) . "\r\n";
                }
            }
            file_put_contents($exportDir . '/' . $downloadName, $content);
        }
        echo json_encode(['success' => true, 'file' => $downloadName, 'name' => $downloadName]);
    } elseif ($action === 'update_gatekeeper') {
        $pwd = $input['password'] ?? $_POST['password'] ?? null;
        if (!$pwd) {
            echo json_encode(['success' => false, 'message' => 'Password required']);
            exit;
        }
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        saveGatekeeperHash($hash);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'check_gatekeeper') {
        $required = !empty($gatekeeperHash);
        echo json_encode(['required' => $required]);
        exit;
    } elseif ($action === 'validate_gatekeeper') {
        $pwd = $input['password'] ?? $_POST['password'] ?? null;
        $valid = $gatekeeperHash && password_verify($pwd, $gatekeeperHash);
        echo json_encode(['valid' => $valid]);
        exit;
    } elseif ($action === 'save_theme') {
        // Protegido por Gatekeeper si existe un hash configurado
        if (!empty($gatekeeperHash)) {
            $apwd = $input['password'] ?? $_POST['password'] ?? null;
            if (!$apwd || !password_verify($apwd, $gatekeeperHash)) {
                echo json_encode(['success' => false, 'message' => 'Acceso de administrador inválido.']);
                exit;
            }
        }
        $key = trim((string)($input['key'] ?? $_POST['key'] ?? ''));
        $theme = $input['theme'] ?? $_POST['theme'] ?? null;
        if ($key === '' || !preg_match('/^[a-z0-9_\-]+$/i', $key) || !is_array($theme)) {
            echo json_encode(['success' => false, 'message' => 'Parámetros de tema inválidos.']);
            exit;
        }
        $themes = loadThemes();
        // Base de valores para el tema nuevo (herencia del tema base si existe)
        $base = $themes[$key] ?? [];
        $clean = [
            'name'       => mb_substr((string)($theme['name'] ?? $key), 0, 30),
            'isLight'    => !empty($theme['isLight']),
            'bgMain'     => themeSanitizeHex($theme['bgMain'] ?? null, $base['bgMain'] ?? '#0b0c0e'),
            'bgPanel'    => themeSanitizeHex($theme['bgPanel'] ?? null, $base['bgPanel'] ?? '#141519'),
            'bgDarker'   => themeSanitizeHex($theme['bgDarker'] ?? null, $base['bgDarker'] ?? '#07080a'),
            'border'     => themeSanitizeHex($theme['border'] ?? null, $base['border'] ?? '#232529'),
            'textMain'   => themeSanitizeHex($theme['textMain'] ?? null, $base['textMain'] ?? '#f2f3f5'),
            'textMuted'  => themeSanitizeHex($theme['textMuted'] ?? null, $base['textMuted'] ?? '#9aa0a8'),
            'accent'     => themeSanitizeHex($theme['accent'] ?? null, $base['accent'] ?? '#a3aab3'),
            'accentRgb'  => themeHexToRgb($theme['accent'] ?? null) ?: ($base['accentRgb'] ?? ''),
            'bgPanelRgb' => themeHexToRgb($theme['bgPanel'] ?? null) ?: ($base['bgPanelRgb'] ?? ''),
            'fontFamily' => themeSanitizeFont($theme['fontFamily'] ?? null, $base['fontFamily'] ?? 'Arial')
        ];
        if ($clean['accentRgb'] === '') $clean['accentRgb'] = themeHexToRgb($clean['accent']);
        if ($clean['bgPanelRgb'] === '') $clean['bgPanelRgb'] = themeHexToRgb($clean['bgPanel']);
        $targetKey = $key;
        if (($input['mode'] ?? 'save') === 'duplicate' && isset($themes[$targetKey])) {
            $targetKey .= '_' . substr(base_convert((string)time(), 10, 36), 0, 6);
        }
        $themes[$targetKey] = $clean;
        if (!saveThemes($themes)) {
            echo json_encode(['success' => false, 'message' => 'No se pudo escribir config/themes.json. Verifique permisos.']);
            exit;
        }
        echo json_encode(['success' => true, 'key' => $targetKey]);
        exit;
    } elseif ($action === 'reset_theme') {
        if (!empty($gatekeeperHash)) {
            $apwd = $input['password'] ?? $_POST['password'] ?? null;
            if (!$apwd || !password_verify($apwd, $gatekeeperHash)) {
                echo json_encode(['success' => false, 'message' => 'Acceso de administrador inválido.']);
                exit;
            }
        }
        $defaultFile = __DIR__ . '/config/themes.default.json';
        if (!file_exists($defaultFile)) {
            echo json_encode(['success' => false, 'message' => 'No existe la copia de fábrica (config/themes.default.json).']);
            exit;
        }
        if (!saveThemes(json_decode(file_get_contents($defaultFile), true) ?: [])) {
            echo json_encode(['success' => false, 'message' => 'No se pudo reiniciar los temas. Verifique permisos.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'feedback_status') {
        $cfg = loadFeedbackConfig();
        echo json_encode([
            'success' => true,
            'configured' => !empty($cfg['token']) && !empty($cfg['owner']) && !empty($cfg['repo']),
            'owner' => $cfg['owner'] ?? '',
            'repo' => $cfg['repo'] ?? ''
        ]);
        exit;
    } elseif ($action === 'save_feedback_config') {
        if (empty($session)) {
            throw new Exception('Sesión no válida. Inicie sesión de nuevo.');
        }
        if (!empty($gatekeeperHash)) {
            $apwd = $input['password'] ?? $_POST['password'] ?? null;
            if (!$apwd || !password_verify($apwd, $gatekeeperHash)) {
                echo json_encode(['success' => false, 'message' => 'Acceso de administrador inválido.']);
                exit;
            }
        }
        $owner = trim((string)($input['owner'] ?? $_POST['owner'] ?? ''));
        $repo = trim((string)($input['repo'] ?? $_POST['repo'] ?? ''));
        $token = trim((string)($input['token'] ?? $_POST['token'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.\-]+$/', $repo)) {
            echo json_encode(['success' => false, 'message' => 'Owner o repo inválidos.']);
            exit;
        }
        if (strlen($token) < 10 || !preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
            echo json_encode(['success' => false, 'message' => 'El token de GitHub no es válido.']);
            exit;
        }
        if (!saveFeedbackConfig(['owner' => $owner, 'repo' => $repo, 'token' => $token])) {
            echo json_encode(['success' => false, 'message' => 'No se pudo escribir config/feedback.json. Verifique permisos.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'submit_feedback') {
        $category = trim((string)($input['category'] ?? $_POST['category'] ?? 'idea'));
        $title = trim((string)($input['title'] ?? $_POST['title'] ?? ''));
        $message = trim((string)($input['message'] ?? $_POST['message'] ?? ''));
        $allowed = ['idea', 'error', 'mejora', 'sugerencia', 'otro'];
        if (!in_array($category, $allowed, true)) $category = 'otro';
        if ($title === '' || mb_strlen($title) > 200) {
            throw new Exception('El título es obligatorio (máx. 200 caracteres).');
        }
        if ($message === '' || mb_strlen($message) > 10000) {
            throw new Exception('El mensaje es obligatorio (máx. 10.000 caracteres).');
        }
        $cfg = loadFeedbackConfig();
        if (empty($cfg['token']) || empty($cfg['owner']) || empty($cfg['repo'])) {
            throw new Exception('El envío a GitHub no está configurado. Configure un token en Comentarios.');
        }
        $appVersion = '1.8.19';
        $verFile = __DIR__ . '/version.json';
        if (file_exists($verFile)) {
            $vd = json_decode(file_get_contents($verFile), true);
            if (!empty($vd['version'])) $appVersion = $vd['version'];
        }
        $issueUser = strtoupper(trim((string)($session['user_id'] ?? '')));
        $issueBody = "**Usuario AS/400:** " . ($issueUser !== '' ? $issueUser : 'Anónimo') . "\n"
            . "**Versión de la aplicación:** " . $appVersion . "\n"
            . "**Categoría:** " . strtoupper($category) . "\n"
            . "**Fecha:** " . date('Y-m-d H:i:s') . "\n\n---\n\n" . $message;
        $labels = ['feedback'];
        $res = feedbackGitHubRequest(
            'https://api.github.com/repos/' . rawurlencode($cfg['owner']) . '/' . rawurlencode($cfg['repo']) . '/issues',
            $cfg['token'],
            [
                'title' => '[' . strtoupper($category) . '] ' . $title,
                'body' => $issueBody,
                'labels' => $labels
            ]
        );
        $decoded = json_decode($res['body'], true);
        if ($res['code'] >= 400) {
            $reason = $decoded['message'] ?? 'HTTP ' . $res['code'];
            throw new Exception('GitHub rechazó el envío: ' . $reason);
        }
        $issueUrl = $decoded['html_url'] ?? '';
        echo json_encode(['success' => true, 'issue_url' => $issueUrl]);
        exit;
    } else {
        throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    $cleanMsg = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, CP850');
    echo json_encode(['success' => false, 'message' => $cleanMsg], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
