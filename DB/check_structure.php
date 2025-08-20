<?php
/**
 * Verifica struttura tabelle - Mostra le colonne delle tabelle del database
 * Usa questo per verificare i nomi esatti delle colonne nel database
 */

require_once 'config.php';

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<meta charset='UTF-8'>";
echo "<title>Struttura Tabelle Database VP</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }";
echo "table { border-collapse: collapse; width: 100%; margin: 15px 0; }";
echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
echo "th { background-color: #f8f9fa; font-weight: bold; }";
echo ".table-name { background-color: #e9ecef; padding: 10px; font-weight: bold; font-size: 18px; margin: 20px 0 10px 0; }";
echo "</style>";
echo "</head><body>";

echo "<h1>🗂️ Struttura Tabelle Database VP</h1>";

try {
    $db = getDatabase();
    
    $tables = [
        'ANA_CLIENTI',
        'ANA_COLLABORATORI', 
        'ANA_COMMESSE',
        'ANA_TASK',
        'ANA_TARIFFE_COLLABORATORI',
        'FACT_GIORNATE',
        'FACT_FATTURE'
    ];
    
    foreach ($tables as $tableName) {
        echo "<div class='table-name'>📋 $tableName</div>";
        
        try {
            // Ottieni struttura della tabella
            $stmt = $db->query("DESCRIBE `$tableName`");
            $columns = $stmt->fetchAll();
            
            if (empty($columns)) {
                echo "<p style='color: #dc3545;'>❌ Tabella non trovata o vuota</p>";
                continue;
            }
            
            echo "<table>";
            echo "<tr>";
            echo "<th>Nome Colonna</th>";
            echo "<th>Tipo</th>";
            echo "<th>Null</th>";
            echo "<th>Chiave</th>";
            echo "<th>Default</th>";
            echo "<th>Extra</th>";
            echo "</tr>";
            
            foreach ($columns as $column) {
                echo "<tr>";
                echo "<td><strong>" . $column['Field'] . "</strong></td>";
                echo "<td>" . $column['Type'] . "</td>";
                echo "<td>" . $column['Null'] . "</td>";
                echo "<td>" . $column['Key'] . "</td>";
                echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
                echo "<td>" . $column['Extra'] . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
            
            // Mostra anche conteggio record
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM `$tableName`");
            $stmt->execute();
            $count = $stmt->fetch()['count'];
            echo "<p><strong>Record attuali:</strong> $count</p>";
            
        } catch (PDOException $e) {
            echo "<p style='color: #dc3545;'>❌ Errore: " . $e->getMessage() . "</p>";
        }
    }
    
    // Mostra anche i file CSV e la loro struttura
    echo "<h2>📁 Struttura File CSV</h2>";
    
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
    
    foreach ($csvFiles as $csvFile) {
        $filePath = $dataDir . '/' . $csvFile;
        
        if (!file_exists($filePath)) {
            echo "<div class='table-name'>❌ $csvFile (Non trovato)</div>";
            continue;
        }
        
        echo "<div class='table-name'>📄 $csvFile</div>";
        
        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = fgetcsv($handle, 0, ';');
            fclose($handle);
            
            if ($headers) {
                // Mostra headers originali
                echo "<p><strong>Headers originali:</strong></p>";
                echo "<table>";
                echo "<tr><th>Posizione</th><th>Nome Originale</th><th>Nome Pulito</th></tr>";
                
                for ($i = 0; $i < count($headers); $i++) {
                    $original = trim(str_replace("\xEF\xBB\xBF", '', $headers[$i]));
                    $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '_', $original);
                    $cleaned = preg_replace('/_+/', '_', $cleaned);
                    $cleaned = trim($cleaned, '_');
                    
                    $color = ($original !== $cleaned) ? 'color: #dc3545; font-weight: bold;' : '';
                    
                    echo "<tr>";
                    echo "<td>" . ($i + 1) . "</td>";
                    echo "<td style='$color'>$original</td>";
                    echo "<td>$cleaned</td>";
                    echo "</tr>";
                }
                
                echo "</table>";
                
                // Conta righe
                $lines = count(file($filePath)) - 1;
                echo "<p><strong>Righe di dati:</strong> $lines</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<strong>❌ Errore:</strong> " . $e->getMessage();
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='test_connection.php'>🔧 Test Connessione</a> | ";
echo "<a href='import_csv.php'>📥 Importa CSV</a></p>";

echo "</body></html>";
?>