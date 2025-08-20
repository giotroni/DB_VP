<?php
/**
 * Importazione CSV - Importa tutti i file CSV nella cartella Dati
 * 
 * Questo script importa i file CSV nella sequenza corretta rispettando
 * le dipendenze delle foreign key tra le tabelle.
 * 
 * Sequenza di importazione:
 * 1. ANA_CLIENTI (tabella principale)
 * 2. ANA_COLLABORATORI (tabella principale)
 * 3. ANA_COMMESSE (dipende da CLIENTI)
 * 4. ANA_TASK (dipende da COMMESSE)
 * 5. ANA_TARIFFE_COLLABORATORI (dipende da COLLABORATORI)
 * 6. FACT_GIORNATE (dipende da COLLABORATORI, COMMESSE, TASK)
 * 7. FACT_FATTURE (dipende da CLIENTI, COMMESSE)
 */

require_once 'config.php';

class CSVImporter {
    private $db;
    private $dataDir;
    private $logFile;
    
    // Sequenza di importazione rispettando le dipendenze
    private $importSequence = [
        'ANA_CLIENTI',
        'ANA_COLLABORATORI', 
        'ANA_COMMESSE',
        'ANA_TASK',
        'ANA_TARIFFE_COLLABORATORI',
        'FACT_GIORNATE',
        'FACT_FATTURE'
    ];
    
    // Mapping nomi file CSV -> nomi tabelle
    private $tableMapping = [
        'ANA_CLIENTI.csv' => 'ANA_CLIENTI',
        'ANA_COLLABORATORI.csv' => 'ANA_COLLABORATORI',
        'ANA_COMMESSE.csv' => 'ANA_COMMESSE', 
        'ANA_TASK.csv' => 'ANA_TASK',
        'ANA_TARIFFE_COLLABORATORI.csv' => 'ANA_TARIFFE_COLLABORATORI',
        'FACT_GIORNATE.csv' => 'FACT_GIORNATE',
        'FACT_FATTURE.csv' => 'FACT_FATTURE'
    ];
    
    // Mapping per correggere nomi colonne problematiche
    private $columnMapping = [
        'FACT_GIORNATE' => [
            'Spese_Viaggi' => 'Spese_Viaggi',
            'Vitto__alloggio' => 'Vitto_alloggio', 
            'Altri_costi' => 'Altri_costi'
        ]
    ];
    
    // Tabelle con campi auto-increment che possono essere vuoti
    private $autoIncrementTables = [
        'FACT_FATTURE' => 'ID_FATTURA'
    ];
    
    public function __construct() {
        $this->db = getDatabase();
        $this->dataDir = __DIR__ . '/Dati';
        $this->logFile = __DIR__ . '/logs/import_' . date('Y-m-d_H-i-s') . '.log';
        
        if (!is_dir($this->dataDir)) {
            throw new Exception("Cartella Dati non trovata: " . $this->dataDir);
        }
    }
    
    /**
     * Avvia il processo di importazione
     */
    public function run() {
        $this->log("=== INIZIO IMPORTAZIONE CSV ===");
        $this->log("Data/Ora: " . date('Y-m-d H:i:s'));
        $this->log("Cartella dati: " . $this->dataDir);
        
        try {
            // Chiedi conferma per svuotare le tabelle
            $this->askTruncateConfirmation();
            
            // Verifica presenza file CSV
            $this->checkCSVFiles();
            
            // Importa i file nella sequenza corretta
            $this->importAllCSV();
            
            $this->log("=== IMPORTAZIONE COMPLETATA CON SUCCESSO ===");
            if (php_sapi_name() === 'cli') {
                echo "\n✅ Importazione completata con successo!\n";
                echo "📄 Log salvato in: " . $this->logFile . "\n";
            } else {
                echo "<div style='background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
                echo "<h3>✅ Importazione completata con successo!</h3>";
                echo "<p>📄 Log salvato in: " . basename($this->logFile) . "</p>";
                echo "</div>";
            }
            
        } catch (Exception $e) {
            $this->log("ERRORE FATALE: " . $e->getMessage());
            if (php_sapi_name() === 'cli') {
                echo "\n❌ Errore durante l'importazione: " . $e->getMessage() . "\n";
                echo "📄 Controlla il log: " . $this->logFile . "\n";
            } else {
                echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
                echo "<h3>❌ Errore durante l'importazione</h3>";
                echo "<p><strong>Errore:</strong> " . $e->getMessage() . "</p>";
                echo "<p>📄 Controlla il log: " . basename($this->logFile) . "</p>";
                echo "</div>";
            }
            throw $e;
        }
    }
    
    /**
     * Chiede conferma per svuotare le tabelle
     */
    private function askTruncateConfirmation() {
        // Verifica se stiamo eseguendo da CLI o da web
        $isCLI = php_sapi_name() === 'cli';
        
        if (!$isCLI) {
            echo "<h2>🗂️ IMPORTAZIONE DATI CSV</h2>";
            echo "<hr>";
        } else {
            echo "\n🗂️  IMPORTAZIONE DATI CSV\n";
            echo "=========================\n\n";
        }
        
        // Mostra tabelle esistenti e conta record
        $this->showCurrentData();
        
        if ($isCLI) {
            // Modalità CLI - input interattivo
            $this->askTruncateConfirmationCLI();
        } else {
            // Modalità WEB - usa parametri GET
            $this->askTruncateConfirmationWEB();
        }
    }
    
    /**
     * Gestione conferma per ambiente CLI
     */
    private function askTruncateConfirmationCLI() {
        echo "\nVuoi svuotare tutte le tabelle prima dell'importazione?\n";
        echo "(Questo cancellerà tutti i dati esistenti nelle tabelle)\n";
        echo "\n[S]i - Svuota tutte le tabelle\n";
        echo "[N]o - Mantieni dati esistenti (potrebbero verificarsi errori di duplicazione)\n";
        echo "[C]ancella - Annulla operazione\n\n";
        
        $choice = '';
        while (!in_array(strtoupper($choice), ['S', 'N', 'C'])) {
            echo "Scelta (S/N/C): ";
            $choice = trim(fgets(STDIN));
        }
        
        $this->processChoice(strtoupper($choice));
    }
    
    /**
     * Gestione conferma per ambiente WEB
     */
    private function askTruncateConfirmationWEB() {
        // Controlla se è stato passato un parametro di scelta
        $choice = isset($_GET['action']) ? strtoupper($_GET['action']) : '';
        
        if (empty($choice) || !in_array($choice, ['S', 'N', 'C'])) {
            // Mostra i pulsanti per la scelta
            echo "<br><h3>Vuoi svuotare tutte le tabelle prima dell'importazione?</h3>";
            echo "<p><strong>Attenzione:</strong> Questo cancellerà tutti i dati esistenti nelle tabelle</p>";
            
            $currentUrl = $_SERVER['PHP_SELF'];
            
            echo "<div style='margin: 20px 0;'>";
            echo "<a href='$currentUrl?action=S' style='background-color: #dc3545; color: white; padding: 10px 20px; text-decoration: none; margin-right: 10px; border-radius: 5px;'>
                    ✅ SÌ - Svuota tutte le tabelle</a>";
            echo "<a href='$currentUrl?action=N' style='background-color: #ffc107; color: black; padding: 10px 20px; text-decoration: none; margin-right: 10px; border-radius: 5px;'>
                    ⚠️ NO - Mantieni dati esistenti</a>";
            echo "<a href='$currentUrl' style='background-color: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>
                    ❌ CANCELLA - Annulla operazione</a>";
            echo "</div>";
            
            echo "<p><em>Clicca su una delle opzioni per procedere.</em></p>";
            exit(0);
        }
        
        $this->processChoice($choice);
    }
    
    /**
     * Processa la scelta dell'utente
     */
    private function processChoice($choice) {
        switch ($choice) {
            case 'S':
                $this->log("Utente ha scelto di svuotare le tabelle");
                $this->truncateAllTables();
                break;
                
            case 'N':
                $this->log("Utente ha scelto di mantenere i dati esistenti");
                if (php_sapi_name() === 'cli') {
                    echo "\n⚠️  Attenzione: I dati esistenti verranno mantenuti.\n";
                    echo "   Potrebbero verificarsi errori di chiave duplicata.\n";
                } else {
                    echo "<div style='background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
                    echo "<strong>⚠️ Attenzione:</strong> I dati esistenti verranno mantenuti.<br>";
                    echo "Potrebbero verificarsi errori di chiave duplicata.";
                    echo "</div>";
                }
                break;
                
            case 'C':
                $this->log("Utente ha annullato l'operazione");
                if (php_sapi_name() === 'cli') {
                    echo "\nOperazione annullata.\n";
                } else {
                    echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
                    echo "<strong>❌ Operazione annullata.</strong>";
                    echo "</div>";
                }
                exit(0);
        }
    }
    
    /**
     * Mostra i dati attualmente presenti nelle tabelle
     */
    private function showCurrentData() {
        $isCLI = php_sapi_name() === 'cli';
        
        if ($isCLI) {
            echo "📊 STATO ATTUALE DELLE TABELLE:\n";
            echo "================================\n";
        } else {
            echo "<h3>📊 STATO ATTUALE DELLE TABELLE:</h3>";
            echo "<table style='border-collapse: collapse; width: 100%; margin: 15px 0;'>";
            echo "<tr style='background-color: #f8f9fa;'>";
            echo "<th style='border: 1px solid #ddd; padding: 12px; text-align: left;'>Tabella</th>";
            echo "<th style='border: 1px solid #ddd; padding: 12px; text-align: right;'>Record</th>";
            echo "</tr>";
        }
        
        foreach ($this->importSequence as $tableName) {
            try {
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$tableName`");
                $stmt->execute();
                $count = $stmt->fetch()['count'];
                
                if ($isCLI) {
                    echo sprintf("%-25s: %d record\n", $tableName, $count);
                } else {
                    $color = $count > 0 ? '#28a745' : '#6c757d';
                    echo "<tr>";
                    echo "<td style='border: 1px solid #ddd; padding: 8px;'>$tableName</td>";
                    echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: $color; font-weight: bold;'>$count record</td>";
                    echo "</tr>";
                }
                
            } catch (PDOException $e) {
                if ($e->getCode() == '42S02') { // Table doesn't exist
                    if ($isCLI) {
                        echo sprintf("%-25s: Tabella non esistente\n", $tableName);
                    } else {
                        echo "<tr>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px;'>$tableName</td>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #dc3545;'>Tabella non esistente</td>";
                        echo "</tr>";
                    }
                } else {
                    if ($isCLI) {
                        echo sprintf("%-25s: Errore (%s)\n", $tableName, $e->getMessage());
                    } else {
                        echo "<tr>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px;'>$tableName</td>";
                        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #dc3545;'>Errore</td>";
                        echo "</tr>";
                    }
                }
            }
        }
        
        if (!$isCLI) {
            echo "</table>";
        }
    }
    
    /**
     * Svuota tutte le tabelle nella sequenza inversa
     */
    private function truncateAllTables() {
        $isCLI = php_sapi_name() === 'cli';
        
        if ($isCLI) {
            echo "\n🗑️  Svuotamento tabelle in corso...\n";
        } else {
            echo "<h3>🗑️ Svuotamento tabelle in corso...</h3>";
            echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        }
        
        // Disabilita controlli foreign key temporaneamente
        $this->db->exec("SET foreign_key_checks = 0");
        
        // Svuota in ordine inverso per rispettare le dipendenze
        $reversedSequence = array_reverse($this->importSequence);
        
        foreach ($reversedSequence as $tableName) {
            try {
                $this->db->exec("TRUNCATE TABLE `$tableName`");
                if ($isCLI) {
                    echo "   ✅ $tableName svuotata\n";
                } else {
                    echo "✅ $tableName svuotata<br>";
                }
                $this->log("Tabella $tableName svuotata");
                
            } catch (PDOException $e) {
                if ($e->getCode() == '42S02') {
                    if ($isCLI) {
                        echo "   ⚠️  $tableName non esiste\n";
                    } else {
                        echo "⚠️ $tableName non esiste<br>";
                    }
                    $this->log("Tabella $tableName non esiste, saltata");
                } else {
                    if ($isCLI) {
                        echo "   ❌ Errore svuotando $tableName: " . $e->getMessage() . "\n";
                    } else {
                        echo "❌ Errore svuotando $tableName: " . $e->getMessage() . "<br>";
                    }
                    $this->log("Errore svuotando $tableName: " . $e->getMessage());
                }
            }
        }
        
        // Riabilita controlli foreign key
        $this->db->exec("SET foreign_key_checks = 1");
        
        if ($isCLI) {
            echo "   Svuotamento completato.\n";
        } else {
            echo "</div>";
            echo "<p><strong>Svuotamento completato.</strong></p>";
        }
    }
    
    /**
     * Verifica la presenza di tutti i file CSV
     */
    private function checkCSVFiles() {
        $isCLI = php_sapi_name() === 'cli';
        
        if ($isCLI) {
            echo "\n📁 Verifica file CSV...\n";
        } else {
            echo "<h3>📁 Verifica file CSV...</h3>";
            echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        }
        
        $missingFiles = [];
        $availableFiles = [];
        
        foreach ($this->tableMapping as $csvFile => $tableName) {
            $filePath = $this->dataDir . '/' . $csvFile;
            
            if (file_exists($filePath)) {
                $fileSize = filesize($filePath);
                if ($isCLI) {
                    echo "   ✅ $csvFile (". $this->formatBytes($fileSize) . ")\n";
                } else {
                    echo "✅ $csvFile (". $this->formatBytes($fileSize) . ")<br>";
                }
                $availableFiles[] = $csvFile;
                $this->log("File trovato: $csvFile ($fileSize bytes)");
            } else {
                if ($isCLI) {
                    echo "   ❌ $csvFile (NON TROVATO)\n";
                } else {
                    echo "❌ $csvFile (NON TROVATO)<br>";
                }
                $missingFiles[] = $csvFile;
                $this->log("File mancante: $csvFile");
            }
        }
        
        if (!empty($missingFiles)) {
            if (!$isCLI) {
                echo "</div>";
            }
            throw new Exception("File CSV mancanti: " . implode(', ', $missingFiles));
        }
        
        if ($isCLI) {
            echo "   Tutti i file CSV sono presenti.\n";
        } else {
            echo "</div>";
            echo "<p><strong>✅ Tutti i file CSV sono presenti.</strong></p>";
        }
    }
    
    /**
     * Importa tutti i CSV nella sequenza corretta
     */
    private function importAllCSV() {
        $isCLI = php_sapi_name() === 'cli';
        
        if ($isCLI) {
            echo "\n📥 Importazione in corso...\n";
        } else {
            echo "<h3>📥 Importazione in corso...</h3>";
            echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        }
        
        $totalImported = 0;
        $totalErrors = 0;
        
        foreach ($this->importSequence as $tableName) {
            $csvFile = array_search($tableName, $this->tableMapping);
            
            if ($csvFile === false) {
                if ($isCLI) {
                    echo "   ⚠️  Nessun file CSV per tabella $tableName\n";
                } else {
                    echo "⚠️ Nessun file CSV per tabella $tableName<br>";
                }
                continue;
            }
            
            $filePath = $this->dataDir . '/' . $csvFile;
            
            if ($isCLI) {
                echo "\n   📄 Importando $csvFile → $tableName...\n";
            } else {
                echo "<br><strong>📄 Importando $csvFile → $tableName...</strong><br>";
            }
            $this->log("Inizio importazione: $csvFile → $tableName");
            
            try {
                $result = $this->importCSVFile($filePath, $tableName);
                
                if ($isCLI) {
                    echo "✅ Importati <strong>{$result['imported']}</strong> record";
                    if ($result['skipped'] > 0) {
                        echo " ({$result['skipped']} saltati)";
                    }
                    if ($result['errors'] > 0) {
                        echo " ({$result['errors']} errori)";
                    }
                    echo "\n";
                } else {
                    echo "✅ Importati <strong>{$result['imported']}</strong> record";
                    if ($result['skipped'] > 0) {
                        echo " (<span style='color: #ffc107;'>{$result['skipped']} saltati</span>)";
                    }
                    if ($result['errors'] > 0) {
                        echo " (<span style='color: #dc3545;'>{$result['errors']} errori</span>)";
                    }
                    echo "<br>";
                }
                
                $totalImported += $result['imported'];
                $totalErrors += $result['errors'];
                $totalSkipped = isset($result['skipped']) ? $result['skipped'] : 0;
                
                $this->log("Completata importazione $tableName: {$result['imported']} record importati, {$result['errors']} errori, $totalSkipped saltati");
                
            } catch (Exception $e) {
                if ($isCLI) {
                    echo "      ❌ Errore: " . $e->getMessage() . "\n";
                } else {
                    echo "<span style='color: #dc3545;'>❌ Errore: " . $e->getMessage() . "</span><br>";
                }
                $this->log("Errore importazione $tableName: " . $e->getMessage());
                $totalErrors++;
            }
        }
        
        if ($isCLI) {
            echo "\n📊 RIEPILOGO IMPORTAZIONE:\n";
            echo "   Record importati: $totalImported\n";
            echo "   Record saltati: $totalSkipped\n";
            echo "   Errori totali: $totalErrors\n";
        } else {
            echo "</div>";
            echo "<div style='background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
            echo "<h4>📊 RIEPILOGO IMPORTAZIONE:</h4>";
            echo "<strong>Record importati:</strong> $totalImported<br>";
            echo "<strong>Record saltati:</strong> $totalSkipped<br>";
            echo "<strong>Errori totali:</strong> $totalErrors";
            echo "</div>";
        }
        
        $this->log("RIEPILOGO: $totalImported record importati, $totalSkipped saltati, $totalErrors errori");
    }
    
    /**
     * Importa un singolo file CSV con gestione avanzata degli errori
     */
    private function importCSVFile($filePath, $tableName) {
        $imported = 0;
        $errors = 0;
        $skipped = 0;
        $lineNumber = 0;
        
        $this->log("=== INIZIO IMPORTAZIONE $tableName ===");
        $this->log("File: $filePath");
        
        // Apri il file CSV
        if (($handle = fopen($filePath, 'r')) === false) {
            throw new Exception("Impossibile aprire il file: $filePath");
        }
        
        // Leggi la prima riga (header)
        $lineNumber++;
        $headers = fgetcsv($handle, 0, ';');
        
        if ($headers === false) {
            fclose($handle);
            throw new Exception("File CSV vuoto o non valido: $filePath");
        }
        
        // Rimuovi eventuali caratteri BOM e spazi
        $originalHeaders = array_map(function($header) {
            return trim(str_replace("\xEF\xBB\xBF", '', $header));
        }, $headers);
        
        $headers = array_map(function($header) {
            $cleaned = trim(str_replace("\xEF\xBB\xBF", '', $header));
            // Rimuovi caratteri non validi dai nomi delle colonne per evitare errori SQL
            $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '_', $cleaned);
            // Rimuovi underscore multipli consecutivi
            $cleaned = preg_replace('/_+/', '_', $cleaned);
            // Rimuovi underscore all'inizio e alla fine
            $cleaned = trim($cleaned, '_');
            return $cleaned;
        }, $headers);
        
        // Rimuovi colonne vuote alla fine
        while (!empty($headers) && empty(end($headers))) {
            array_pop($headers);
        }
        
        $this->log("Headers originali: " . implode(', ', $originalHeaders));
        $this->log("Headers puliti: " . implode(', ', $headers));
        $this->log("Numero di colonne header: " . count($headers));
        
        // Applica mapping colonne specifico per tabella se esiste
        $finalHeaders = $headers;
        if (isset($this->columnMapping[$tableName])) {
            $mapping = $this->columnMapping[$tableName];
            for ($i = 0; $i < count($finalHeaders); $i++) {
                if (isset($mapping[$finalHeaders[$i]])) {
                    $this->log("Mapping colonna: {$finalHeaders[$i]} → {$mapping[$finalHeaders[$i]]}");
                    $finalHeaders[$i] = $mapping[$finalHeaders[$i]];
                }
            }
        }
        
        // Gestione speciale per tabelle con auto-increment
        $isAutoIncrementTable = isset($this->autoIncrementTables[$tableName]);
        $autoIncrementField = $isAutoIncrementTable ? $this->autoIncrementTables[$tableName] : null;
        
        // Disabilita temporaneamente i controlli foreign key per questa importazione
        $this->db->exec("SET foreign_key_checks = 0");
        
        // Per tabelle auto-increment, prepariamo due query: una con ID e una senza
        if ($isAutoIncrementTable) {
            // Query senza campo auto-increment
            $headersWithoutAuto = array_filter($finalHeaders, function($header) use ($autoIncrementField) {
                return $header !== $autoIncrementField;
            });
            $headersWithoutAuto = array_values($headersWithoutAuto);
            
            $placeholdersWithoutAuto = ':' . implode(', :', $headersWithoutAuto);
            $columnsWithoutAuto = '`' . implode('`, `', $headersWithoutAuto) . '`';
            $sqlWithoutAuto = "INSERT IGNORE INTO `$tableName` ($columnsWithoutAuto) VALUES ($placeholdersWithoutAuto)";
            
            // Query con campo auto-increment  
            $placeholders = ':' . implode(', :', $finalHeaders);
            $columns = '`' . implode('`, `', $finalHeaders) . '`';
            $sqlWithAuto = "INSERT IGNORE INTO `$tableName` ($columns) VALUES ($placeholders)";
            
            $this->log("Query preparata (senza auto-increment): $sqlWithoutAuto");
            $this->log("Query preparata (con auto-increment): $sqlWithAuto");
            
            $stmtWithoutAuto = $this->db->prepare($sqlWithoutAuto);
            $stmtWithAuto = $this->db->prepare($sqlWithAuto);
        } else {
            // Query normale
            $placeholders = ':' . implode(', :', $finalHeaders);
            $columns = '`' . implode('`, `', $finalHeaders) . '`';
            $sql = "INSERT IGNORE INTO `$tableName` ($columns) VALUES ($placeholders)";
            
            $this->log("Query preparata: $sql");
            $stmt = $this->db->prepare($sql);
        }
        
        // Conta il numero totale di righe per progresso
        $totalLines = 0;
        while (fgetcsv($handle, 0, ';') !== false) {
            $totalLines++;
        }
        rewind($handle);
        fgetcsv($handle, 0, ';'); // Ri-leggi header
        
        $this->log("Numero totale di righe dati da processare: $totalLines");
        
        // Processa ogni riga
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $lineNumber++;
            
            // Log delle prime 3 righe per debug
            if ($lineNumber <= 4) {
                $this->log("Riga $lineNumber dati grezzi: " . implode(' | ', $data));
                $this->log("Numero di campi riga $lineNumber: " . count($data));
            }
            
            // Salta righe vuote
            if (empty(array_filter($data, function($value) { return trim($value) !== ''; }))) {
                $skipped++;
                $this->log("Riga $lineNumber vuota, saltata");
                continue;
            }
            
            // Adatta il numero di colonne
            $originalDataCount = count($data);
            $data = array_pad($data, count($headers), '');
            $data = array_slice($data, 0, count($headers));
            
            if ($originalDataCount != count($headers)) {
                $this->log("Riga $lineNumber: regolato numero campi da $originalDataCount a " . count($headers));
            }
            
            // Prepara i dati per l'inserimento
            $params = [];
            $paramsWithoutAuto = [];
            $hasAutoIncrementValue = false;
            
            for ($i = 0; $i < count($headers); $i++) {
                $value = trim($data[$i]);
                
                // Gestione speciale per campi data (formato italiano)
                $isDateField = (
                    strpos(strtolower($finalHeaders[$i]), 'data') !== false ||
                    in_array(strtolower($finalHeaders[$i]), ['data', 'scadenza_pagamento', 'data_pagamento', 'data_ordine', 'data_apertura_task', 'dal'])
                );
                
                if ($isDateField && !empty($value)) {
                    $originalValue = $value;
                    $value = $this->convertDateFormat($value);
                    
                    // Log conversioni date per i primi record
                    if ($imported < 5 && $originalValue !== $value) {
                        $this->log("Campo '{$finalHeaders[$i]}': '$originalValue' → '$value'");
                    }
                }
                
                // Converti stringhe vuote in NULL
                $cleanValue = ($value === '') ? null : $value;
                $params[$finalHeaders[$i]] = $cleanValue;
                
                // Per tabelle auto-increment, prepara anche parametri senza campo auto-increment
                if ($isAutoIncrementTable) {
                    if ($finalHeaders[$i] === $autoIncrementField) {
                        $hasAutoIncrementValue = !empty($value);
                    } else {
                        $paramsWithoutAuto[$finalHeaders[$i]] = $cleanValue;
                    }
                }
            }
            
            // Log dei primi 3 record per debug
            if ($imported < 3) {
                $this->log("Record " . ($imported + 1) . " parametri: " . json_encode($params, JSON_UNESCAPED_UNICODE));
                if ($isAutoIncrementTable) {
                    $this->log("Record " . ($imported + 1) . " auto-increment vuoto: " . ($hasAutoIncrementValue ? 'NO' : 'SI'));
                }
            }
            
            try {
                // Scegli quale query utilizzare
                if ($isAutoIncrementTable && !$hasAutoIncrementValue) {
                    // Usa query senza auto-increment se il campo è vuoto
                    $result = $stmtWithoutAuto->execute($paramsWithoutAuto);
                    $currentStmt = $stmtWithoutAuto;
                } elseif ($isAutoIncrementTable) {
                    // Usa query con auto-increment se il campo ha valore
                    $result = $stmtWithAuto->execute($params);
                    $currentStmt = $stmtWithAuto;
                } else {
                    // Query normale per altre tabelle
                    $result = $stmt->execute($params);
                    $currentStmt = $stmt;
                }
                
                // Verifica se il record è stato effettivamente inserito
                if ($currentStmt->rowCount() > 0) {
                    $imported++;
                } else {
                    $skipped++;
                    if ($skipped <= 3) {
                        $this->log("Riga $lineNumber: record duplicato o ignorato");
                    }
                }
                
                // Mostra progresso ogni 25 record
                if (($imported + $skipped) % 25 == 0) {
                    $progress = round((($imported + $skipped) / $totalLines) * 100, 1);
                    if (php_sapi_name() === 'cli') {
                        echo "      📝 Processate " . ($imported + $skipped) . "/$totalLines righe ($progress%) - Importati: $imported, Saltati: $skipped\n";
                    } else {
                        echo "&nbsp;&nbsp;📝 Processate " . ($imported + $skipped) . "/$totalLines righe ($progress%) - Importati: $imported, Saltati: $skipped<br>";
                        // Flush output per mostrare progresso in tempo reale nel browser
                        if (ob_get_level()) ob_flush();
                        flush();
                    }
                }
                
            } catch (PDOException $e) {
                $errors++;
                $errorCode = $e->getCode();
                $errorMsg = "Errore riga $lineNumber (codice $errorCode): " . $e->getMessage();
                $this->log($errorMsg);
                $this->log("Dati che hanno causato l'errore: " . json_encode($params, JSON_UNESCAPED_UNICODE));
                
                // Categorizza gli errori
                if (strpos($e->getMessage(), 'foreign key') !== false || strpos($e->getMessage(), 'Cannot add or update') !== false) {
                    $this->log("ERRORE FOREIGN KEY - Verificare esistenza record riferiti");
                } elseif (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $this->log("ERRORE DUPLICATO - Record già esistente");
                } elseif (strpos($e->getMessage(), 'Data too long') !== false) {
                    $this->log("ERRORE LUNGHEZZA - Dati troppo lunghi per il campo");
                }
                
                // Mostra solo i primi 5 errori per non intasare l'output
                if ($errors <= 5) {
                    if (php_sapi_name() === 'cli') {
                        echo "      ⚠️  $errorMsg\n";
                    } else {
                        echo "&nbsp;&nbsp;<span style='color: #dc3545;'>⚠️ $errorMsg</span><br>";
                    }
                } elseif ($errors == 6) {
                    if (php_sapi_name() === 'cli') {
                        echo "      ⚠️  (Altri errori registrati nel log...)\n";
                    } else {
                        echo "&nbsp;&nbsp;<span style='color: #dc3545;'>⚠️ (Altri errori registrati nel log...)</span><br>";
                    }
                }
            }
        }
        
        // Riabilita controlli foreign key
        $this->db->exec("SET foreign_key_checks = 1");
        
        fclose($handle);
        
        $this->log("Completata lettura file $filePath:");
        $this->log("- Righe totali processate: " . ($lineNumber - 1));
        $this->log("- Record importati con successo: $imported");
        $this->log("- Record saltati/duplicati: $skipped");
        $this->log("- Errori: $errors");
        
        return [
            'imported' => $imported,
            'errors' => $errors,
            'skipped' => $skipped,
            'total_lines' => $lineNumber - 1
        ];
    }
    
    /**
     * Converte date dal formato italiano (gg/mm/aa o gg/mm/aaaa) al formato MySQL (YYYY-MM-DD)
     */
    private function convertDateFormat($dateString) {
        // Rimuovi spazi
        $dateString = trim($dateString);
        
        // Se è vuoto, ritorna NULL
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
                // Se l'anno è <= 30, assume 2000+, altrimenti 1900+
                // Es: 25 = 2025, 90 = 1990
                if ($yearInt <= 30) {
                    $year = '20' . $year;
                } else {
                    $year = '19' . $year;
                }
            }
            
            // Verifica che la data sia valida
            if (checkdate($month, $day, $year)) {
                $convertedDate = "$year-$month-$day";
                $this->log("Conversione data: '$dateString' → '$convertedDate'");
                return $convertedDate;
            } else {
                $this->log("ERRORE: Data non valida '$dateString' (gg=$day, mm=$month, aa=$year)");
                return null;
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
                $convertedDate = "$year-$month-$day";
                $this->log("Conversione data (formato -): '$dateString' → '$convertedDate'");
                return $convertedDate;
            } else {
                $this->log("ERRORE: Data non valida '$dateString' (formato -)");
                return null;
            }
        }
        
        // Se non riconosciuto, log e ritorna NULL
        $this->log("ATTENZIONE: Formato data non riconosciuto: '$dateString'");
        return null;
    }
    
    /**
     * Scrive un messaggio nel log
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Formatta i byte in modo leggibile
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Mostra statistiche finali
     */
    public function showFinalStats() {
        $isCLI = php_sapi_name() === 'cli';
        
        if ($isCLI) {
            echo "\n📊 STATISTICHE FINALI:\n";
            echo "======================\n";
        } else {
            echo "<h3>📊 STATISTICHE FINALI:</h3>";
            echo "<table style='border-collapse: collapse; width: 100%; margin: 15px 0;'>";
            echo "<tr style='background-color: #f8f9fa;'>";
            echo "<th style='border: 1px solid #ddd; padding: 12px; text-align: left;'>Tabella</th>";
            echo "<th style='border: 1px solid #ddd; padding: 12px; text-align: right;'>Record</th>";
            echo "</tr>";
        }
        
        $totalRecords = 0;
        
        foreach ($this->importSequence as $tableName) {
            try {
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM `$tableName`");
                $stmt->execute();
                $count = $stmt->fetch()['count'];
                
                if ($isCLI) {
                    echo sprintf("%-25s: %d record\n", $tableName, $count);
                } else {
                    $color = $count > 0 ? '#28a745' : '#6c757d';
                    echo "<tr>";
                    echo "<td style='border: 1px solid #ddd; padding: 8px;'>$tableName</td>";
                    echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: $color; font-weight: bold;'>$count record</td>";
                    echo "</tr>";
                }
                $totalRecords += $count;
                
            } catch (PDOException $e) {
                if ($isCLI) {
                    echo sprintf("%-25s: Errore\n", $tableName);
                } else {
                    echo "<tr>";
                    echo "<td style='border: 1px solid #ddd; padding: 8px;'>$tableName</td>";
                    echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #dc3545;'>Errore</td>";
                    echo "</tr>";
                }
            }
        }
        
        if ($isCLI) {
            echo "======================\n";
            echo sprintf("%-25s: %d record\n", "TOTALE", $totalRecords);
        } else {
            echo "<tr style='background-color: #e9ecef; font-weight: bold;'>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>TOTALE</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; color: #007bff;'>$totalRecords record</td>";
            echo "</tr>";
            echo "</table>";
        }
    }
}

// Esecuzione se chiamato direttamente
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    try {
        // Se siamo in modalità web, abilita output buffering per mostrare progresso
        if (php_sapi_name() !== 'cli') {
            // Disabilita output buffering per vedere il progresso in tempo reale
            if (ob_get_level()) ob_end_clean();
            
            echo "<!DOCTYPE html>";
            echo "<html><head>";
            echo "<meta charset='UTF-8'>";
            echo "<title>Importazione CSV - Database VP</title>";
            echo "<style>";
            echo "body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }";
            echo "h1 { color: #007bff; }";
            echo ".container { background-color: #f8f9fa; padding: 20px; border-radius: 10px; }";
            echo "</style>";
            echo "</head><body>";
            echo "<h1>🗂️ Importazione Dati CSV - Database VP</h1>";
            echo "<div class='container'>";
        }
        
        $importer = new CSVImporter();
        $importer->run();
        $importer->showFinalStats();
        
        if (php_sapi_name() !== 'cli') {
            echo "</div>";
            echo "<div style='margin-top: 20px; padding: 15px; background-color: #d4edda; border-radius: 5px;'>";
            echo "<p><strong>🎉 Processo completato!</strong></p>";
            echo "<p><a href='" . $_SERVER['PHP_SELF'] . "' style='color: #007bff;'>📥 Esegui nuova importazione</a></p>";
            echo "</div>";
            echo "</body></html>";
        }
        
    } catch (Exception $e) {
        if (php_sapi_name() === 'cli') {
            echo "\n💥 ERRORE FATALE: " . $e->getMessage() . "\n";
            echo "Controlla la configurazione del database e la presenza dei file CSV.\n";
        } else {
            echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
            echo "<h3>💥 ERRORE FATALE</h3>";
            echo "<p><strong>Errore:</strong> " . $e->getMessage() . "</p>";
            echo "<p>Controlla la configurazione del database e la presenza dei file CSV.</p>";
            echo "<p><a href='" . $_SERVER['PHP_SELF'] . "' style='color: #007bff;'>🔄 Riprova</a></p>";
            echo "</div>";
            if (isset($importer)) {
                echo "</div></body></html>";
            }
        }
        exit(1);
    }
}
?>
