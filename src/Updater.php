<?php

namespace App;

class Updater {
    private $root;
    private $config;
    private $state;

    const CONFIG_FILE = 'config/updater.json';
    const STATE_FILE = 'cache/updater_state.json';
    const LOCK_FILE = 'cache/update.lock';

    private $protectedDirs = [
        'php', 'php74', 'php82', 'python', 'python311', 'python38', 'redist',
        'uploads', 'exports', 'cache', 'backups', '.git',
        'backup_v1.7.5', 'backup_v1.8.0_pre_redesign'
    ];

    private $protectedFiles = [
        'config/proxy.dat', 'config/gatekeeper.json', 'config/themes.json',
        'config/templates.json', 'config/updater.json', 'config/updater_state.json',
        'trace.log', 'debug_raw.txt', 'server_logs.txt', 'VC_redist.x64.exe'
    ];

    public function __construct($root = null) {
        $this->root = $root ? rtrim(str_replace('\\', '/', $root), '/') : rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $this->config = $this->loadConfig();
        $this->state = $this->loadState();
    }

    private function loadConfig() {
        $file = $this->root . '/' . self::CONFIG_FILE;
        $cfg = (file_exists($file)) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        if (empty($cfg['repo'])) {
            $defaultFile = $this->root . '/config/updater.default.json';
            if (file_exists($defaultFile)) {
                $def = json_decode(file_get_contents($defaultFile), true) ?: [];
                if (!empty($def['repo']) && is_string($def['repo'])) {
                    $cfg['repo'] = trim($def['repo']);
                    if (!empty($def['branch']) && is_string($def['branch'])) $cfg['branch'] = trim($def['branch']);
                    if (isset($def['auto_check'])) $cfg['auto_check'] = (bool)$def['auto_check'];
                }
            }
        }
        if (!isset($cfg['repo']) || !is_string($cfg['repo'])) $cfg['repo'] = '';
        if (!isset($cfg['branch']) || !is_string($cfg['branch'])) $cfg['branch'] = 'main';
        if (!isset($cfg['auto_check'])) $cfg['auto_check'] = true;
        if (!isset($cfg['last_check'])) $cfg['last_check'] = null;
        if (!isset($cfg['last_applied'])) $cfg['last_applied'] = null;
        if (!isset($cfg['last_applied_version'])) $cfg['last_applied_version'] = null;
        return $cfg;
    }

    private function saveConfig() {
        $file = $this->root . '/' . self::CONFIG_FILE;
        @mkdir(dirname($file), 0777, true);
        return @file_put_contents($file, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }

    private function loadState() {
        $file = $this->root . '/' . self::STATE_FILE;
        return (file_exists($file)) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    }

    private function saveState() {
        $file = $this->root . '/' . self::STATE_FILE;
        @mkdir(dirname($file), 0777, true);
        return @file_put_contents($file, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }

    public function getConfig() {
        return $this->config;
    }

    public function updateConfig($repo, $branch, $autoCheck) {
        $repo = trim((string)$repo);
        $branch = trim((string)$branch);
        if (!preg_match('/^[A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+$/', $repo)) {
            throw new \Exception('Repositorio inválido. Formato: usuario/repositorio');
        }
        if ($branch === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $branch)) {
            throw new \Exception('Rama inválida.');
        }
        $this->config['repo'] = $repo;
        $this->config['branch'] = $branch;
        $this->config['auto_check'] = (bool)$autoCheck;
        if (!$this->saveConfig()) throw new \Exception('No se pudo escribir config/updater.json. Verifique permisos.');
        return $this->config;
    }

    public function getLocalVersion() {
        $file = $this->root . '/version.json';
        if (!file_exists($file)) return '0.0.0';
        $data = json_decode(file_get_contents($file), true);
        return $data['version'] ?? '0.0.0';
    }

    public function getStatus() {
        return [
            'configured' => !empty($this->config['repo']),
            'repo' => $this->config['repo'],
            'branch' => $this->config['branch'],
            'auto_check' => (bool)$this->config['auto_check'],
            'local' => $this->getLocalVersion(),
            'last_check' => $this->config['last_check'],
            'last_applied' => $this->config['last_applied'],
            'last_applied_version' => $this->config['last_applied_version'],
            'remote' => $this->state['remote_version'] ?? null,
            'changelog' => $this->state['changelog'] ?? null,
            'available' => !empty($this->state['available']),
            'zip_url' => $this->state['zip_url'] ?? null,
            'checked_at' => $this->state['checked_at'] ?? null,
        ];
    }

    private function httpGet($url, $timeout = 30) {
        if (!function_exists('curl_init')) {
            throw new \Exception('La extensión cURL no está disponible en PHP.');
        }
        $caFile = $this->root . '/cacert.pem';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'AS400-Portable-Libre-Updater/' . $this->getLocalVersion(),
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
            throw new \Exception('Fallo de red al contactar GitHub: ' . $err);
        }
        return ['code' => $code, 'body' => $body];
    }

    private function downloadTo($url, $dest, $timeout = 120) {
        if (!function_exists('curl_init')) {
            throw new \Exception('La extensión cURL no está disponible en PHP.');
        }
        $caFile = $this->root . '/cacert.pem';
        $fp = fopen($dest, 'wb');
        if (!$fp) throw new \Exception('No se pudo crear el archivo temporal de descarga.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'AS400-Portable-Libre-Updater/' . $this->getLocalVersion(),
        ]);
        if (file_exists($caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $code >= 400) {
            @unlink($dest);
            throw new \Exception('No se pudo descargar ' . basename($url) . ' (HTTP ' . $code . ')' . ($err ? ' — ' . $err : ''));
        }
        return true;
    }

    public function checkForUpdates() {
        if (empty($this->config['repo'])) {
            throw new \Exception('No hay un repositorio configurado. Vaya a Actualizaciones y configure uno.');
        }
        $repo = $this->config['repo'];
        $branch = $this->config['branch'];
        $versionUrl = 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/version.json';

        $res = $this->httpGet($versionUrl);
        if ($res['code'] !== 200) {
            throw new \Exception('No se pudo leer version.json del repositorio ' . $repo . ' (HTTP ' . $res['code'] . '). Verifique el repositorio y la rama.');
        }
        $remoteData = json_decode($res['body'], true);
        if (!$remoteData || empty($remoteData['version'])) {
            throw new \Exception('version.json remoto inválido.');
        }
        $remoteVersion = $remoteData['version'];
        $local = $this->getLocalVersion();
        $available = version_compare($remoteVersion, $local, '>');

        $zipUrl = null;
        $shaUrl = null;
        if ($available) {
            $releaseUrl = 'https://api.github.com/repos/' . $repo . '/releases/tags/v' . rawurlencode($remoteVersion);
            try {
                $rel = $this->httpGet($releaseUrl);
                if ($rel['code'] === 200) {
                    $relData = json_decode($rel['body'], true);
                    foreach (($relData['assets'] ?? []) as $asset) {
                        $name = (string)($asset['name'] ?? '');
                        if (preg_match('/^update_v[0-9.]+\.zip$/', $name)) $zipUrl = $asset['browser_download_url'] ?? null;
                        if (preg_match('/^update_v[0-9.]+\.zip\.sha256$/', $name)) $shaUrl = $asset['browser_download_url'] ?? null;
                    }
                }
            } catch (\Exception $e) {
                // si la API falla, dejamos las URLs nulas y se resuelven en apply()
            }
        }

        $this->state = [
            'remote_version' => $remoteVersion,
            'changelog' => $remoteData['changelog'] ?? '',
            'available' => $available,
            'zip_url' => $zipUrl,
            'sha256_url' => $shaUrl,
            'checked_at' => date('c'),
        ];
        $this->saveState();
        $this->config['last_check'] = date('c');
        $this->saveConfig();

        return [
            'success' => true,
            'local' => $local,
            'remote' => $remoteVersion,
            'available' => $available,
            'changelog' => $this->state['changelog'],
            'release_ready' => $zipUrl !== null,
        ];
    }

    private function isProtectedPath($relPath) {
        $rel = str_replace('\\', '/', ltrim($relPath, '/'));
        if ($rel === '') return true;
        $first = strtolower(explode('/', $rel)[0]);
        foreach ($this->protectedDirs as $dir) {
            if (strtolower($dir) === $first) return true;
        }
        foreach ($this->protectedFiles as $pf) {
            if (strcasecmp($rel, $pf) === 0) return true;
        }
        return false;
    }

    private function sanitizeRelPath($entry) {
        $rel = str_replace('\\', '/', $entry);
        $rel = ltrim($rel, '/');
        if (preg_match('#^(\.\.|[A-Za-z]:)#', $rel)) return null;
        if (strpos($rel, '../') !== false || strpos($rel, '..\\') !== false) return null;
        $parts = explode('/', $rel);
        foreach ($parts as $p) {
            if ($p === '..') return null;
        }
        return $rel;
    }

    private function phpBinary() {
        $isWin7 = (strpos(php_uname('r'), '6.1') !== false);
        $dirs = $isWin7 ? ['php74', 'php'] : ['php82', 'php74', 'php'];
        foreach ($dirs as $d) {
            $candidate = $this->root . '/' . $d . '/php.exe';
            if (file_exists($candidate)) return ['bin' => $candidate, 'ini' => $this->root . '/' . $d . '/php.ini'];
        }
        return ['bin' => 'php', 'ini' => ''];
    }

    private function lintStage($stageDir) {
        $php = $this->phpBinary();
        $phpBin = $php['bin'];
        $phpIni = $php['ini'];
        $errors = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stageDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isDir() || strtolower($file->getExtension()) !== 'php') continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($stageDir) + 1));
            if (stripos($rel, 'vendor/') === 0 || stripos($rel, 'vendor74/') === 0) continue;
            $cmd = escapeshellarg($phpBin);
            if ($phpIni !== '') $cmd .= ' -c ' . escapeshellarg($phpIni);
            $cmd .= ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
            $out = [];
            $code = 0;
            exec($cmd, $out, $code);
            if ($code !== 0) {
                $errors[] = $rel . ': ' . trim(implode(' ', array_slice($out, -1)));
            }
        }
        return $errors;
    }

    private function acquireLock() {
        $lockFile = $this->root . '/' . self::LOCK_FILE;
        $fp = @fopen($lockFile, 'x');
        if ($fp === false) {
            throw new \Exception('Ya hay una actualización en curso. Espere a que termine o elimine cache/update.lock.');
        }
        fwrite($fp, getmypid() . ' ' . date('c'));
        fclose($fp);
    }

    private function releaseLock() {
        @unlink($this->root . '/' . self::LOCK_FILE);
    }

    private function recursiveCopy($src, $dst) {
        if (is_dir($src)) {
            @mkdir($dst, 0777, true);
            $it = new \FilesystemIterator($src, \FilesystemIterator::SKIP_DOTS);
            foreach ($it as $item) {
                $this->recursiveCopy($item->getPathname(), $dst . '/' . $item->getFilename());
            }
        } elseif (is_file($src)) {
            @mkdir(dirname($dst), 0777, true);
            copy($src, $dst);
        }
    }

    private function deleteRel($rel) {
        $target = $this->root . '/' . $rel;
        if (is_dir($target)) {
            $this->deleteTree($target);
        } elseif (is_file($target)) {
            @unlink($target);
        }
    }

    private function deleteTree($dir) {
        if (!is_dir($dir)) return;
        $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($it as $item) {
            if ($item->isDir()) {
                $this->deleteTree($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function findReleaseAssets() {
        if (!empty($this->state['zip_url'])) return;
        if (empty($this->config['repo'])) return;
        $version = $this->state['remote_version'] ?? null;
        if (!$version) return;
        $url = 'https://api.github.com/repos/' . $this->config['repo'] . '/releases/tags/v' . rawurlencode($version);
        $rel = $this->httpGet($url);
        if ($rel['code'] !== 200) {
            throw new \Exception('No se encontró la publicación v' . $version . ' en GitHub.');
        }
        $relData = json_decode($rel['body'], true);
        foreach (($relData['assets'] ?? []) as $asset) {
            $name = (string)($asset['name'] ?? '');
            if (preg_match('/^update_v[0-9.]+\.zip$/', $name)) $this->state['zip_url'] = $asset['browser_download_url'] ?? null;
            if (preg_match('/^update_v[0-9.]+\.zip\.sha256$/', $name)) $this->state['sha256_url'] = $asset['browser_download_url'] ?? null;
        }
        if (empty($this->state['zip_url'])) {
            throw new \Exception('La publicación v' . $version . ' no incluye el paquete de actualización (update_v' . $version . '.zip).');
        }
        $this->saveState();
    }

    public function apply() {
        if (empty($this->config['repo'])) {
            throw new \Exception('No hay un repositorio configurado.');
        }
        if (empty($this->state['available']) || empty($this->state['remote_version'])) {
            throw new \Exception('No hay una actualización descargable. Ejecute primero "Buscar actualizaciones".');
        }

        $version = $this->state['remote_version'];
        $ts = date('Ymd_His');
        $cacheDir = $this->root . '/cache';
        $stageDir = $cacheDir . '/update_stage_' . $ts;
        $zipPath = $cacheDir . '/update_v' . $version . '.zip';
        $shaPath = $cacheDir . '/update_v' . $version . '.zip.sha256';
        $backupDir = $this->root . '/backups/update_' . $ts;

        @mkdir($cacheDir, 0777, true);
        $this->acquireLock();
        $started = false;
        try {
            $this->findReleaseAssets();

            $this->downloadTo($this->state['zip_url'], $zipPath);
            if (!empty($this->state['sha256_url'])) {
                $this->downloadTo($this->state['sha256_url'], $shaPath);
                $expectedRaw = trim(file_get_contents($shaPath));
                if (preg_match('/^[0-9a-fA-F]{64}$/', $expectedRaw)) {
                    $expected = strtolower($expectedRaw);
                } elseif (preg_match('/^([0-9a-fA-F]{64})\s+/', $expectedRaw, $m)) {
                    $expected = strtolower($m[1]);
                } else {
                    throw new \Exception('El archivo de verificación SHA-256 no es válido.');
                }
                $actual = strtolower(hash_file('sha256', $zipPath));
                if ($actual !== $expected) {
                    throw new \Exception('Verificación SHA-256 fallida. La descarga está corrupta o el paquete no es de confianza.');
                }
            }

            if (!class_exists('ZipArchive')) {
                throw new \Exception('La extensión ZIP no está disponible en PHP.');
            }
            $zip = new \ZipArchive();
            $openRes = $zip->open($zipPath);
            if ($openRes !== true) {
                throw new \Exception('No se pudo abrir el paquete de actualización.');
            }
            @mkdir($stageDir, 0777, true);
            $files = [];
            $removeList = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $rel = $this->sanitizeRelPath($name);
                if ($rel === null || $rel === '') continue;
                if ($this->isProtectedPath($rel)) continue;
                if (strpos($rel, '_updater/') === 0) {
                    if ($rel === '_updater/remove.txt') {
                        $removeList = array_filter(array_map('trim', explode("\n", $zip->getFromName($name))));
                    }
                    continue;
                }
                if (substr($rel, -1) === '/') continue;
                $target = $stageDir . '/' . $rel;
                @mkdir(dirname($target), 0777, true);
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    $zip->close();
                    throw new \Exception('No se pudo leer el archivo del paquete: ' . $name);
                }
                $fp = fopen($target, 'wb');
                while (!feof($stream)) {
                    fwrite($fp, fread($stream, 8192));
                }
                fclose($fp);
                fclose($stream);
                $files[] = $rel;
            }
            $zip->close();

            $sanitizedRemove = [];
            foreach ($removeList as $r) {
                $rel = $this->sanitizeRelPath($r);
                if ($rel !== null && $rel !== '' && !$this->isProtectedPath($rel)) {
                    $sanitizedRemove[] = $rel;
                }
            }
            $removeList = array_values(array_unique($sanitizedRemove));

            $lintErrors = $this->lintStage($stageDir);
            if (!empty($lintErrors)) {
                throw new \Exception('El paquete contiene errores de sintaxis y fue rechazado: ' . implode(' | ', array_slice($lintErrors, 0, 5)));
            }

            $manifest = [
                'version' => $version,
                'created' => date('Y-m-d H:i:s'),
                'replaced' => [],
                'new' => [],
                'removed' => [],
            ];
            foreach ($files as $rel) {
                $srcRoot = $this->root . '/' . $rel;
                if (file_exists($srcRoot)) {
                    $manifest['replaced'][] = $rel;
                } else {
                    $manifest['new'][] = $rel;
                }
            }
            foreach ($removeList as $rel) {
                if (file_exists($this->root . '/' . $rel)) {
                    $manifest['removed'][] = $rel;
                }
            }
            @mkdir($backupDir, 0777, true);
            foreach ($manifest['replaced'] as $rel) {
                $this->recursiveCopy($this->root . '/' . $rel, $backupDir . '/replaced/' . $rel);
            }
            foreach ($manifest['removed'] as $rel) {
                $this->recursiveCopy($this->root . '/' . $rel, $backupDir . '/removed/' . $rel);
            }
            file_put_contents($backupDir . '/_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $started = true;
            foreach ($files as $rel) {
                $dest = $this->root . '/' . $rel;
                @mkdir(dirname($dest), 0777, true);
                if (is_dir($stageDir . '/' . $rel)) continue;
                if (!copy($stageDir . '/' . $rel, $dest)) {
                    throw new \Exception('No se pudo escribir el archivo: ' . $rel . '. El proceso PHP podría estar bloqueándolo. Reversión automática en curso.');
                }
            }
            foreach ($removeList as $rel) {
                $this->deleteRel($rel);
            }

            $this->config['last_applied'] = date('Y-m-d H:i:s');
            $this->config['last_applied_version'] = $version;
            $this->saveConfig();
            $this->state = [];
            $this->saveState();

            $this->deleteTree($stageDir);
            @unlink($zipPath);
            @unlink($shaPath);
            $this->releaseLock();

            return [
                'success' => true,
                'version' => $version,
                'message' => 'Aplicación actualizada a v' . $version . '. El respaldo quedó en backups/update_' . $ts,
            ];
        } catch (\Throwable $e) {
            if ($started) {
                $this->restoreBackup($backupDir);
            }
            $this->deleteTree($stageDir);
            @unlink($zipPath);
            @unlink($shaPath);
            $this->releaseLock();
            throw $e;
        }
    }

    private function restoreBackup($backupDir) {
        $manifestFile = $backupDir . '/_manifest.json';
        if (!file_exists($manifestFile)) return;
        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!$manifest) return;
        foreach (($manifest['new'] ?? []) as $rel) {
            $this->deleteRel($rel);
        }
        foreach (($manifest['removed'] ?? []) as $rel) {
            $src = $backupDir . '/removed/' . $rel;
            if (file_exists($src)) {
                $this->recursiveCopy($src, $this->root . '/' . $rel);
            }
        }
        foreach (($manifest['replaced'] ?? []) as $rel) {
            $src = $backupDir . '/replaced/' . $rel;
            if (file_exists($src)) {
                $this->recursiveCopy($src, $this->root . '/' . $rel);
            }
        }
    }

    public function rollback() {
        $backupDirs = glob($this->root . '/backups/update_*');
        if (!$backupDirs) {
            throw new \Exception('No hay respaldos de actualización disponibles para revertir.');
        }
        usort($backupDirs, function ($a, $b) {
            return strcmp($b, $a);
        });
        $backupDir = $backupDirs[0];
        $manifestFile = $backupDir . '/_manifest.json';
        if (!file_exists($manifestFile)) {
            throw new \Exception('El respaldo de actualización no tiene manifiesto (reversión imposible).');
        }
        $this->acquireLock();
        try {
            $manifest = json_decode(file_get_contents($manifestFile), true);
            $this->restoreBackup($backupDir);
            $this->config['last_applied'] = null;
            $this->config['last_applied_version'] = null;
            $this->saveConfig();
            $this->state = [];
            $this->saveState();
            $this->releaseLock();
            return [
                'success' => true,
                'message' => 'Reversión completada. Se restauró la versión previa desde ' . basename($backupDir) . '.',
                'backup' => basename($backupDir),
            ];
        } catch (\Throwable $e) {
            $this->releaseLock();
            throw $e;
        }
    }
}
