<?php
/**
 * Test di connessione al database
 * Usa questo file per verificare che tutto funzioni sul server remoto
 */

require_once 'config.php';

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<meta charset='UTF-8'>";
echo "<title>Test Connessione Database</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }";
echo ".success { background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; color: #155724; }";
echo ".error { background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; color: #721c24; }";
echo ".info { background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; color: #0c5460; }";
echo "</style>";
echo "</head><body>";

echo "<h1>🔧 Test Connessione Database VP</h1>";

// Test 1: Verifica driver PDO
echo "<h2>1. Test Driver PDO</h2>";
$drivers = PDO::getAvailableDrivers();
if (in_array('mysql', $drivers)) {
    echo "<div class='success'>✅ Driver MySQL PDO disponibile</div>";
    echo "<p>Driver disponibili: " . implode(', ', $drivers) . "</p>";
} else {
    echo "<div class='error'>❌ Driver MySQL PDO NON disponibile</div>";
    echo "<p>Driver disponibili: " . implode(', ', $drivers) . "</p>";
    echo "</body></html>";
    exit;
}

// Test 2: Configurazione
echo "<h2>2. Configurazione Database</h2>";
echo "<div class='info'>";
echo "<strong>Host:</strong> " . DB_HOST . "<br>";
echo "<strong>Database:</strong> " . DB_NAME . "<br>";
echo "<strong>Username:</strong> " . DB_USER . "<br>";
echo "<strong>Password:</strong> " . str_repeat('*', strlen(DB_PASS)) . "<br>";
echo "<strong>Charset:</strong> " . DB_CHARSET;
echo "</div>";

// Test 3: Connessione al database
echo "<h2>3. Test Connessione</h2>";
try {
    $db = getDatabase();
    echo "<div class='success'>✅ Connessione al database riuscita</div>";
    
    // Test query
    $stmt = $db->query("SELECT DATABASE() as current_db, USER() as current_user, VERSION() as mysql_version");
    $info = $stmt->fetch();
    
    echo "<div class='info'>";
    echo "<strong>Database corrente:</strong> " . $info['current_db'] . "<br>";
    echo "<strong>Utente corrente:</strong> " . $info['current_user'] . "<br>";
    echo "<strong>Versione MySQL:</strong> " . $info['mysql_version'];
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Errore di connessione: " . $e->getMessage() . "</div>";
    echo "</body></html>";
    exit;
}

// Test 4: Verifica tabelle
echo "<h2>4. Verifica Tabelle</h2>";
$tables = [
    'ANA_CLIENTI',
    'ANA_COLLABORATORI', 
    'ANA_COMMESSE',
    'ANA_TASK',
    'ANA_TARIFFE_COLLABORATORI',
    'FACT_GIORNATE',
    'FACT_FATTURE'
];

echo "<table style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background-color: #f8f9fa;'>";
echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: left;'>Tabella</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: center;'>Esiste</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: right;'>Record</th>";
echo "</tr>";

foreach ($tables as $table) {
    echo "<tr>";
    echo "<td style='border: 1px solid #ddd; padding: 8px;'>$table</td>";
    
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM `$table`");
        $stmt->execute();
        $count = $stmt->fetch()['count'];
        
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center; color: #28a745;'>✅</td>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: bold;'>$count</td>";
        
    } catch (PDOException $e) {
        if ($e->getCode() == '42S02') {
            echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center; color: #dc3545;'>❌</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #dc3545;'>Non esiste</td>";
        } else {
            echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center; color: #ffc107;'>⚠️</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #ffc107;'>Errore</td>";
        }
    }
    
    echo "</tr>";
}
echo "</table>";

// Test 5: Verifica file CSV
echo "<h2>5. Verifica File CSV</h2>";
$dataDir = __DIR__ . '/Dati';
$csvFiles = [
    'ANA_CLIENTI.csv',
    'ANA_COLLABORATORI.csv',
    'ANA_COMMESSE.csv',
    'ANA_TASK.csv',
    'ANA_TARIFFE_COLLABORATORI.csv',
    'FACT_GIORNATE.csv',
    'FACT_FATTURE.csv'
];

echo "<table style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background-color: #f8f9fa;'>";
echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: left;'>File CSV</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: center;'>Esiste</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: right;'>Dimensione</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: right;'>Righe</th>";
echo "</tr>";

foreach ($csvFiles as $csvFile) {
    echo "<tr>";
    echo "<td style='border: 1px solid #ddd; padding: 8px;'>$csvFile</td>";
    
    $filePath = $dataDir . '/' . $csvFile;
    
    if (file_exists($filePath)) {
        $fileSize = filesize($filePath);
        $lines = count(file($filePath)) - 1; // -1 per header
        
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center; color: #28a745;'>✅</td>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right;'>" . formatBytes($fileSize) . "</td>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; font-weight: bold;'>$lines</td>";
    } else {
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center; color: #dc3545;'>❌</td>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #dc3545;'>-</td>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #dc3545;'>-</td>";
    }
    
    echo "</tr>";
}
echo "</table>";

// Test completato
echo "<h2>✅ Test Completato</h2>";
echo "<div class='success'>";
echo "<strong>Il sistema è pronto per l'importazione!</strong><br>";
echo "<a href='import_csv.php' style='color: #155724; font-weight: bold;'>➡️ Avvia Importazione CSV</a>";
echo "</div>";

echo "</body></html>";

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>