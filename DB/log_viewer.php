<?php
/**
 * Visualizzatore Log - Interfaccia web per visualizzare i log dell'applicazione
 * Accesso sicuro ai file di log con funzionalità di filtro e ricerca
 */

// Configurazione di sicurezza
$allowedIPs = ['127.0.0.1', '::1']; // Aggiungi qui gli IP autorizzati
$requireAuth = true; // Imposta false per disabilitare l'autenticazione
$adminPassword = 'VaglioLog2025!'; // Cambia questa password!

// Verifica accesso
if ($requireAuth) {
    session_start();
    
    if (!isset($_SESSION['log_authenticated'])) {
        if (isset($_POST['password'])) {
            if ($_POST['password'] === $adminPassword) {
                $_SESSION['log_authenticated'] = true;
            } else {
                $error = "Password errata!";
            }
        }
        
        if (!isset($_SESSION['log_authenticated'])) {
            showLoginForm($error ?? '');
            exit;
        }
    }
}

// Configurazione percorsi
$logsDir = __DIR__ . '/logs';

// Ottieni lista dinamica dei file di log
$logFiles = [];
if (is_dir($logsDir)) {
    $files = scandir($logsDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
            // Crea descrizione basata sul nome del file
            if (strpos($file, 'import_') === 0) {
                $logFiles[$file] = 'Import CSV - ' . substr($file, 7, 10);
            } elseif ($file === 'php_errors.log') {
                $logFiles[$file] = 'Errori PHP';
            } elseif ($file === 'api_access.log') {
                $logFiles[$file] = 'Accessi API';
            } elseif ($file === 'database.log') {
                $logFiles[$file] = 'Database';
            } elseif ($file === 'security.log') {
                $logFiles[$file] = 'Sicurezza';
            } else {
                $logFiles[$file] = ucfirst(pathinfo($file, PATHINFO_FILENAME));
            }
        }
    }
}

// Se non ci sono file, aggiungi almeno php_errors.log
if (empty($logFiles)) {
    $logFiles = ['php_errors.log' => 'Errori PHP'];
}

// Parametri dalla richiesta
$selectedFile = $_GET['file'] ?? '';
// Se il file selezionato non esiste, prendi il primo disponibile
if (!$selectedFile || !isset($logFiles[$selectedFile])) {
    $selectedFile = array_keys($logFiles)[0] ?? 'php_errors.log';
}
$lines = (int)($_GET['lines'] ?? 50);
$search = $_GET['search'] ?? '';
$level = $_GET['level'] ?? '';
$action = $_GET['action'] ?? '';

// Azioni
if ($action === 'clear' && isset($_GET['confirm'])) {
    clearLogFile($selectedFile);
}

if ($action === 'download') {
    downloadLogFile($selectedFile);
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Funzioni
function showLoginForm($error = '') {
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Log Viewer - Accesso</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-container { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .form-group { margin-bottom: 1rem; }
            label { display: block; margin-bottom: 0.5rem; }
            input[type="password"] { width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; }
            button { background: #007cba; color: white; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; }
            .error { color: red; margin-bottom: 1rem; }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h2>🔐 Accesso Log Viewer</h2>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit">Accedi</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function clearLogFile($filename) {
    global $logsDir;
    $filepath = $logsDir . '/' . $filename;
    if (file_exists($filepath)) {
        file_put_contents($filepath, '');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?file=' . urlencode($filename) . '&message=Log cancellato');
        exit;
    }
}

function downloadLogFile($filename) {
    global $logsDir;
    $filepath = $logsDir . '/' . $filename;
    if (file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

function getLogContent($filename, $lines, $search = '', $level = '') {
    global $logsDir;
    $filepath = $logsDir . '/' . $filename;
    
    if (!file_exists($filepath)) {
        return ['content' => "File di log non trovato: $filename\nPercorso: $filepath", 'total_lines' => 0, 'file_size' => 0];
    }
    
    $fileSize = filesize($filepath);
    
    // Controllo dimensione file (max 10MB per sicurezza)
    if ($fileSize > 10 * 1024 * 1024) {
        return ['content' => "File troppo grande (" . formatBytes($fileSize) . "). Usa il download per file > 10MB.", 'total_lines' => 0, 'file_size' => $fileSize];
    }
    
    // Leggi tutto il contenuto
    $allContent = @file_get_contents($filepath);
    if ($allContent === false) {
        return ['content' => "Errore nella lettura del file: $filename\nVerifica i permessi del file.", 'total_lines' => 0, 'file_size' => $fileSize];
    }
    
    // Gestisci file vuoti
    if (empty($allContent)) {
        return ['content' => "File di log vuoto.", 'total_lines' => 0, 'file_size' => $fileSize];
    }
    
    $allLines = explode("\n", $allContent);
    $totalLines = count($allLines);
    
    // Prendi le ultime N righe (escludendo righe vuote alla fine)
    $allLines = array_filter($allLines, function($line) {
        return trim($line) !== '';
    });
    $allLines = array_values($allLines); // Reindexing
    
    $contentLines = array_slice($allLines, -$lines);
    
    // Applica filtri se specificati
    if ($search || $level) {
        $filteredLines = [];
        
        foreach ($contentLines as $line) {
            $include = true;
            
            if ($search && stripos($line, $search) === false) {
                $include = false;
            }
            
            if ($level && stripos($line, $level) === false) {
                $include = false;
            }
            
            if ($include) {
                $filteredLines[] = $line;
            }
        }
        
        $contentLines = $filteredLines;
    }
    
    $content = implode("\n", $contentLines);
    
    return [
        'content' => $content,
        'total_lines' => $totalLines,
        'file_size' => $fileSize
    ];
}

function formatBytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = $size > 0 ? floor(log($size, 1024)) : 0;
    return number_format($size / pow(1024, $power), 2, '.', '') . ' ' . $units[$power];
}

function highlightLogLine($line) {
    $line = htmlspecialchars($line);
    
    // Highlight diversi livelli di log
    if (preg_match('/\b(ERROR|FATAL)\b/i', $line)) {
        return '<span class="log-error">' . $line . '</span>';
    } elseif (preg_match('/\b(WARNING|WARN)\b/i', $line)) {
        return '<span class="log-warning">' . $line . '</span>';
    } elseif (preg_match('/\b(INFO)\b/i', $line)) {
        return '<span class="log-info">' . $line . '</span>';
    } elseif (preg_match('/\b(DEBUG)\b/i', $line)) {
        return '<span class="log-debug">' . $line . '</span>';
    }
    
    return $line;
}

// Ottieni contenuto del log
$logData = getLogContent($selectedFile, $lines, $search, $level);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer - Vaglio & Partners</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; }
        
        .header { background: #343a40; color: white; padding: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { display: inline-block; margin-right: 2rem; }
        .header .actions { float: right; }
        .header .actions a { color: white; text-decoration: none; margin-left: 1rem; padding: 0.5rem 1rem; background: rgba(255,255,255,0.2); border-radius: 4px; }
        .header .actions a:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        
        .controls { background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .controls-row { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; min-width: 120px; }
        .form-group label { margin-bottom: 0.25rem; font-weight: 500; color: #495057; }
        .form-group select, .form-group input { padding: 0.5rem; border: 1px solid #ced4da; border-radius: 4px; }
        
        .log-info { background: white; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 1rem; }
        .log-stats { display: flex; gap: 2rem; flex-wrap: wrap; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 1.5rem; font-weight: bold; color: #007cba; }
        .stat-label { color: #6c757d; font-size: 0.9rem; }
        
        .log-container { background: #2d3748; color: #e2e8f0; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto; }
        .log-content { font-family: 'Courier New', monospace; font-size: 0.9rem; line-height: 1.4; white-space: pre-wrap; }
        .log-error { color: #fed7d7; background: rgba(254, 215, 215, 0.1); }
        .log-warning { color: #fef5e7; background: rgba(254, 245, 231, 0.1); }
        .log-info { color: #bee3f8; background: rgba(190, 227, 248, 0.1); }
        .log-debug { color: #c6f6d5; background: rgba(198, 246, 213, 0.1); }
        
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007cba; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn:hover { opacity: 0.9; }
        
        .message { padding: 1rem; margin-bottom: 1rem; border-radius: 4px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .loading { text-align: center; padding: 2rem; color: #6c757d; }
        
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .controls-row { flex-direction: column; align-items: stretch; }
            .log-stats { flex-direction: column; gap: 1rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Log Viewer</h1>
        <div class="actions">
            <a href="?action=download&file=<?= urlencode($selectedFile) ?>">⬇️ Download</a>
            <a href="?action=clear&file=<?= urlencode($selectedFile) ?>&confirm=1" onclick="return confirm('Sicuro di voler cancellare questo log?')">🗑️ Cancella</a>
            <a href="?action=logout">🚪 Logout</a>
        </div>
        <div style="clear: both;"></div>
    </div>

    <div class="container">
        <?php if (isset($_GET['message'])): ?>
            <div class="message success"><?= htmlspecialchars($_GET['message']) ?></div>
        <?php endif; ?>

        <div class="controls">
            <form method="get" class="controls-row">
                <div class="form-group">
                    <label for="file">File di Log:</label>
                    <select id="file" name="file" onchange="this.form.submit()">
                        <?php foreach ($logFiles as $filename => $description): ?>
                            <option value="<?= htmlspecialchars($filename) ?>" <?= $selectedFile === $filename ? 'selected' : '' ?>>
                                <?= htmlspecialchars($description) ?> (<?= htmlspecialchars($filename) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="lines">Righe:</label>
                    <select id="lines" name="lines" onchange="this.form.submit()">
                        <option value="25" <?= $lines === 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $lines === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $lines === 100 ? 'selected' : '' ?>>100</option>
                        <option value="250" <?= $lines === 250 ? 'selected' : '' ?>>250</option>
                        <option value="500" <?= $lines === 500 ? 'selected' : '' ?>>500</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="level">Livello:</label>
                    <select id="level" name="level" onchange="this.form.submit()">
                        <option value="">Tutti</option>
                        <option value="ERROR" <?= $level === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                        <option value="WARNING" <?= $level === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                        <option value="INFO" <?= $level === 'INFO' ? 'selected' : '' ?>>INFO</option>
                        <option value="DEBUG" <?= $level === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="search">Cerca:</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Testo da cercare...">
                </div>
                
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Filtra</button>
                </div>
                
                <div class="form-group">
                    <label>&nbsp;</label>
                    <a href="?" class="btn btn-success">Reset</a>
                </div>
            </form>
        </div>

        <div class="log-info">
            <div class="log-stats">
                <div class="stat-item">
                    <div class="stat-value"><?= number_format($logData['total_lines']) ?></div>
                    <div class="stat-label">Righe Totali</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= formatBytes($logData['file_size']) ?></div>
                    <div class="stat-label">Dimensione File</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= $lines ?></div>
                    <div class="stat-label">Righe Visualizzate</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= date('Y-m-d H:i:s') ?></div>
                    <div class="stat-label">Ultimo Aggiornamento</div>
                </div>
            </div>
        </div>

        <div class="log-container">
            <div class="log-content">
                <?php if (empty(trim($logData['content']))): ?>
                    <div class="loading">📄 Nessun contenuto trovato per i filtri selezionati</div>
                <?php else: ?>
                    <?php 
                    $lines = explode("\n", $logData['content']);
                    foreach ($lines as $lineNumber => $line): 
                        if (trim($line)):
                    ?>
                        <div><?= highlightLogLine($line) ?></div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh ogni 30 secondi
        setInterval(function() {
            if (!document.querySelector('input[name="search"]').value && !document.querySelector('select[name="level"]').value) {
                location.reload();
            }
        }, 30000);
        
        // Scroll al fondo per vedere gli ultimi log
        window.addEventListener('load', function() {
            const logContainer = document.querySelector('.log-container');
            logContainer.scrollTop = logContainer.scrollHeight;
        });
    </script>
</body>
</html>