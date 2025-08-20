<?php
/**
 * Verifica e correzione date nel database
 * Controlla le date in formato italiano e le converte in formato MySQL
 */

require_once 'config.php';

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<meta charset='UTF-8'>";
echo "<title>Verifica Date Database VP</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }";
echo "table { border-collapse: collapse; width: 100%; margin: 15px 0; }";
echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
echo "th { background-color: #f8f9fa; font-weight: bold; }";
echo ".date-issue { background-color: #fff3cd; }";
echo ".date-fixed { background-color: #d4edda; }";
echo ".date-error { background-color: #f8d7da; }";
echo "</style>";
echo "</head><body>";

echo "<h1>📅 Verifica Date Database VP</h1>";

try {
    $db = getDatabase();
    
    // Definisci le tabelle e i loro campi data
    $tablesWithDates = [
        'ANA_CLIENTI' => ['Data_Creazione', 'Data_Modifica'],
        'ANA_COLLABORATORI' => ['Data_Creazione', 'Data_Modifica'],
        'ANA_COMMESSE' => ['Data_Creazione', 'Data_Modifica'],
        'ANA_TASK' => ['Data_Apertura_Task', 'Data_Creazione', 'Data_Modifica'],
        'ANA_TARIFFE_COLLABORATORI' => ['Dal', 'Data_Creazione', 'Data_Modifica'],
        'FACT_GIORNATE' => ['Data', 'Data_Creazione', 'Data_Modifica'],
        'FACT_FATTURE' => ['Data', 'Data_Ordine', 'Scadenza_Pagamento', 'Data_Pagamento', 'Data_Creazione', 'Data_Modifica']
    ];
    
    $fixMode = isset($_GET['fix']) && $_GET['fix'] === 'true';
    
    if ($fixMode) {
        echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h3>⚠️ MODALITÀ CORREZIONE ATTIVA</h3>";
        echo "<p>Le date in formato italiano verranno corrette automaticamente!</p>";
        echo "</div>";
    } else {
        echo "<div style='background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h3>🔍 MODALITÀ VERIFICA</h3>";
        echo "<p>Verifica date senza modificare. <a href='?fix=true'>Clicca qui per attivare la correzione automatica</a></p>";
        echo "</div>";
    }
    
    $totalIssues = 0;
    $totalFixed = 0;
    
    foreach ($tablesWithDates as $tableName => $dateColumns) {
        echo "<h2>📋 $tableName</h2>";
        
        try {
            // Conta record totali
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM `$tableName`");
            $stmt->execute();
            $totalRecords = $stmt->fetch()['count'];
            
            if ($totalRecords == 0) {
                echo "<p>⚪ Nessun record nella tabella.</p>";
                continue;
            }
            
            echo "<p><strong>Record totali:</strong> $totalRecords</p>";
            
            // Verifica ogni campo data
            foreach ($dateColumns as $dateColumn) {
                echo "<h3>📅 Campo: $dateColumn</h3>";
                
                // Cerca date in formato non-standard
                $sql = "SELECT * FROM `$tableName` WHERE `$dateColumn` IS NOT NULL AND `$dateColumn` != '' 
                        AND `$dateColumn` NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'";
                
                $stmt = $db->prepare($sql);
                $stmt->execute();
                $problematicDates = $stmt->fetchAll();
                
                if (empty($problematicDates)) {
                    echo "<p style='color: #28a745;'>✅ Tutte le date sono in formato corretto (YYYY-MM-DD)</p>";
                    continue;
                }
                
                echo "<p style='color: #dc3545;'>⚠️ Trovate " . count($problematicDates) . " date in formato non standard:</p>";
                $totalIssues += count($problematicDates);
                
                echo "<table>";
                echo "<tr><th>ID/Record</th><th>Data Originale</th><th>Data Convertita</th><th>Azione</th></tr>";
                
                foreach ($problematicDates as $record) {
                    $originalDate = $record[$dateColumn];
                    $convertedDate = convertItalianDate($originalDate);
                    
                    $primaryKey = array_keys($record)[0]; // Primo campo come chiave primaria
                    $recordId = $record[$primaryKey];
                    
                    $rowClass = '';
                    $action = '';
                    
                    if ($convertedDate === null) {
                        $rowClass = 'date-error';
                        $action = '❌ Formato non riconosciuto';
                        $convertedDate = 'NULL';
                    } else {
                        if ($fixMode) {
                            // Correggi la data
                            try {
                                $updateSql = "UPDATE `$tableName` SET `$dateColumn` = ? WHERE `$primaryKey` = ?";
                                $updateStmt = $db->prepare($updateSql);
                                $updateStmt->execute([$convertedDate, $recordId]);
                                
                                $rowClass = 'date-fixed';
                                $action = '✅ Corretta';
                                $totalFixed++;
                            } catch (Exception $e) {
                                $rowClass = 'date-error';
                                $action = '❌ Errore: ' . $e->getMessage();
                            }
                        } else {
                            $rowClass = 'date-issue';
                            $action = '⚠️ Da correggere';
                        }
                    }
                    
                    echo "<tr class='$rowClass'>";
                    echo "<td>$recordId</td>";
                    echo "<td>$originalDate</td>";
                    echo "<td>$convertedDate</td>";
                    echo "<td>$action</td>";
                    echo "</tr>";
                }
                
                echo "</table>";
            }
            
        } catch (PDOException $e) {
            echo "<p style='color: #dc3545;'>❌ Errore: " . $e->getMessage() . "</p>";
        }
    }
    
    // Riepilogo
    echo "<hr>";
    echo "<h2>📊 Riepilogo</h2>";
    echo "<div style='background-color: #e9ecef; padding: 15px; border-radius: 5px;'>";
    echo "<p><strong>Date problematiche trovate:</strong> $totalIssues</p>";
    if ($fixMode) {
        echo "<p><strong>Date corrette:</strong> $totalFixed</p>";
    }
    echo "</div>";
    
    if ($totalIssues > 0 && !$fixMode) {
        echo "<div style='margin: 20px 0;'>";
        echo "<a href='?fix=true' style='background-color: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>";
        echo "🔧 Correggi Tutte le Date</a>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<strong>❌ Errore:</strong> " . $e->getMessage();
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='test_connection.php'>🔧 Test Connessione</a> | ";
echo "<a href='import_csv.php'>📥 Importa CSV</a> | ";
echo "<a href='check_structure.php'>🗂️ Struttura Tabelle</a></p>";

echo "</body></html>";

/**
 * Converte data dal formato italiano al formato MySQL
 */
function convertItalianDate($dateString) {
    $dateString = trim($dateString);
    
    if (empty($dateString)) {
        return null;
    }
    
    // Se è già in formato YYYY-MM-DD, lascia così
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        return $dateString;
    }
    
    // Formato italiano gg/mm/aa o gg/mm/aaaa
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $dateString, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        
        // Se anno a 2 cifre, determina il secolo
        if (strlen($year) == 2) {
            $yearInt = intval($year);
            if ($yearInt <= 30) {
                $year = '20' . $year;
            } else {
                $year = '19' . $year;
            }
        }
        
        // Verifica che la data sia valida
        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }
    
    // Formato alternativo dd-mm-yyyy o dd-mm-yy
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $dateString, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        
        if (strlen($year) == 2) {
            $yearInt = intval($year);
            if ($yearInt <= 30) {
                $year = '20' . $year;
            } else {
                $year = '19' . $year;
            }
        }
        
        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }
    
    return null;
}
?>