<?php
/**
 * Import CSV - Importazione dati da file CSV
 * Legge file CSV dalla cartella /Dati e li importa nel database
 * Ogni tabella ha il proprio file CSV: ANA_CLIENTI.csv, ANA_COLLABORATORI.csv, etc.
 */

require_once 'config.php';

class CSVImporter {
    private $db;
    private $supportedTables;
    private $fieldMappings;
    private $stats;
    private $dataDir;
    
    public function __construct() {
        $this->db = getDatabase();
        $this->initializeConfiguration();
        $this->stats = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
            'skipped' => 0
        ];
        
        // Directory dati CSV
        $this->dataDir = __DIR__ . '/Dati';
        if (!file_exists($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }
    
    /**
     * Inizializza la configurazione delle tabelle supportate
     */
    private function initializeConfiguration() {
        $this->supportedTables = [
            'ANA_CLIENTI',
            'ANA_COLLABORATORI', 
            'ANA_COMMESSE',
            'ANA_TASK',
            'ANA_TARIFFE_COLLABORATORI',
            'FACT_GIORNATE',
            'FACT_FATTURE'
        ];
        
        // Mappatura campi speciali per ogni tabella
        $this->fieldMappings = [
            'ANA_COLLABORATORI' => [
                'PWD' => 'password_hash'
            ],
            'FACT_GIORNATE' => [
                'Desk' => 'enum_fix',
                'Tipo' => 'enum_fix'
            ],
            'FACT_FATTURE' => [
                'Data_Ordine' => 'date_fix',
                'Data_Pagamento' => 'date_fix'
            ]
        ];
    }
    
    /**
     * Scansiona la directory Dati per file CSV disponibili
     */
    public function scanCSVFiles() {
        $availableFiles = [];
        $missingFiles = [];
        
        foreach ($this->supportedTables as $tableName) {
            $csvFile = $this->dataDir . '/' . $tableName . '.csv';
            if (file_exists($csvFile)) {
                $availableFiles[$tableName] = [
                    'file' => $csvFile,
                    'size' => filesize($csvFile),
                    'modified' => filemtime($csvFile),
                    'rows' => $this->countCSVRows($csvFile)
                ];
            } else {
                $missingFiles[] = $tableName;
            }
        }
        
        return [
            'available' => $availableFiles,
            'missing' => $missingFiles
        ];
    }
    
    /**
     * Conta le righe in un file CSV
     */
    private function countCSVRows($filename) {
        $count = 0;
        if (($handle = fopen($filename, 'r')) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $count++;
            }
            fclose($handle);
        }
        return max(0, $count - 1); // -1 per escludere header
    }
    
    /**
     * Importa tutti i file CSV disponibili
     */
    public function importAllCSV($options = []) {
        echo "<div class='import-section'>";
        echo "<h3>📂 Importazione da file CSV</h3>";
        
        $scanResult = $this->scanCSVFiles();
        $availableFiles = $scanResult['available'];
        $missingFiles = $scanResult['missing'];
        
        if (empty($availableFiles)) {
            echo "<div class='alert error'>❌ Nessun file CSV trovato nella cartella /Dati</div>";
            echo "</div>";
            return $this->stats;
        }
        
        // Mostra file disponibili
        echo "<div class='log-entry'>📁 File CSV trovati: " . count($availableFiles) . "</div>";
        foreach ($availableFiles as $table => $info) {
            echo "<div class='log-entry'>  ✅ {$table}.csv - {$info['rows']} righe (" . $this->formatBytes($info['size']) . ")</div>";
        }
        
        if (!empty($missingFiles)) {
            echo "<div class='log-entry warning'>⚠️ File mancanti: " . implode(', ', $missingFiles) . "</div>";
        }
        
        // Ordine di importazione (rispetta foreign key)
        $importOrder = [
            'ANA_CLIENTI',
            'ANA_COLLABORATORI',
            'ANA_COMMESSE', 
            'ANA_TASK',
            'ANA_TARIFFE_COLLABORATORI',
            'FACT_GIORNATE',
            'FACT_FATTURE'
        ];
        
        $mode = $options['mode'] ?? 'insert';
        $truncate = $options['truncate'] ?? false;
        
        foreach ($importOrder as $tableName) {
            if (isset($availableFiles[$tableName])) {
                echo "<div class='table-section'>";
                echo "<h4>📊 Importazione $tableName</h4>";
                
                if ($truncate) {
                    $this->truncateTable($tableName);
                }
                
                $this->importCSVFile($tableName, $availableFiles[$tableName]['file'], $mode);
                echo "</div>";
            }
        }
        
        $this->showFinalStats();
        echo "</div>";
        return $this->stats;
    }
    
    /**
     * Importa un singolo file CSV con validazione headers
     */
    private function importCSVFile($tableName, $csvFile, $mode) {
        echo "<div class='log-entry'>📄 Lettura file: " . basename($csvFile) . "</div>";
        
        // Leggi CSV
        $csvData = $this->readCSV($csvFile);
        if (empty($csvData)) {
            echo "<div class='log-entry error'>❌ File CSV vuoto o non leggibile</div>";
            return;
        }
        
        $headers = $csvData['headers'];
        $rows = $csvData['data'];
        
        // Valida headers
        $validationResult = $this->validateCSVHeaders($tableName, $headers);
        if (!$validationResult['valid']) {
            echo "<div class='log-entry error'>❌ Headers non validi: " . $validationResult['message'] . "</div>";
            return;
        }
        
        echo "<div class='log-entry success'>✅ Headers validati correttamente</div>";
        echo "<div class='log-entry'>Colonne: " . implode(', ', $headers) . "</div>";
        echo "<div class='log-entry'>Righe da processare: " . count($rows) . "</div>";
        
        // Prepara la query SQL
        $sql = $this->buildInsertSQL($tableName, $headers, $mode);
        $stmt = $this->db->prepare($sql);
        
        $tableStats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0];
        
        echo "<div class='progress-container'>";
        echo "<div class='progress-bar' id='progress-{$tableName}'></div>";
        echo "</div>";
        
        foreach ($rows as $rowIndex => $row) {
            $tableStats['processed']++;
            $this->stats['processed']++;
            
            // Aggiorna progress bar
            $progress = round(($rowIndex + 1) / count($rows) * 100);
            echo "<script>
                const progressBar = document.getElementById('progress-{$tableName}');
                if (progressBar) progressBar.style.width = '{$progress}%';
            </script>";
            
            // Forza output immediato
            if (ob_get_level()) ob_flush();
            flush();
            
            // Salta righe vuote
            if ($this->isEmptyRow($row)) {
                $tableStats['skipped']++;
                $this->stats['skipped']++;
                continue;
            }
            
            try {
                // Processa i dati della riga
                $processedRow = $this->processRowData($tableName, $headers, $row);
                
                // Esegui l'inserimento/aggiornamento
                if ($mode === 'upsert') {
                    $result = $this->upsertRow($tableName, $headers, $processedRow);
                    if ($result === 'inserted') {
                        $tableStats['inserted']++;
                        $this->stats['inserted']++;
                    } else {
                        $tableStats['updated']++;
                        $this->stats['updated']++;
                    }
                } else {
                    $stmt->execute($processedRow);
                    $tableStats['inserted']++;
                    $this->stats['inserted']++;
                }
                
            } catch (PDOException $e) {
                $tableStats['errors']++;
                $this->stats['errors']++;
                
                // Log errore solo se non è un duplicato in modalità insert
                if ($e->getCode() != 23000 || $mode !== 'insert') {
                    echo "<div class='log-entry error'>❌ Errore riga " . ($rowIndex + 1) . ": " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
        
        echo "<div class='table-stats'>";
        echo "✅ Processate: <strong>{$tableStats['processed']}</strong>, ";
        echo "Inserite: <strong>{$tableStats['inserted']}</strong>, ";
        echo "Aggiornate: <strong>{$tableStats['updated']}</strong>, ";
        echo "Errori: <strong>{$tableStats['errors']}</strong>, ";
        echo "Saltate: <strong>{$tableStats['skipped']}</strong>";
        echo "</div>";
    }
    
    /**
     * Valida gli headers del CSV contro la struttura della tabella
     */
    private function validateCSVHeaders($tableName, $headers) {
        $expectedHeaders = $this->getTableHeaders($tableName);
        
        if (empty($expectedHeaders)) {
            return ['valid' => false, 'message' => "Tabella $tableName non supportata"];
        }
        
        // Controlla che almeno la chiave primaria sia presente
        $primaryKey = $expectedHeaders[0];
        if (!in_array($primaryKey, $headers)) {
            return ['valid' => false, 'message' => "Chiave primaria '$primaryKey' mancante"];
        }
        
        // Avviso per colonne mancanti (non bloccante)
        $missingHeaders = array_diff($expectedHeaders, $headers);
        if (!empty($missingHeaders)) {
            echo "<div class='log-entry warning'>⚠️ Colonne mancanti (verranno usati valori di default): " . implode(', ', $missingHeaders) . "</div>";
        }
        
        // Avviso per colonne extra (non bloccante)
        $extraHeaders = array_diff($headers, $expectedHeaders);
        if (!empty($extraHeaders)) {
            echo "<div class='log-entry warning'>⚠️ Colonne extra (verranno ignorate): " . implode(', ', $extraHeaders) . "</div>";
        }
        
        return ['valid' => true, 'message' => 'Headers validi'];
    }
    
    /**
     * Ottiene gli header attesi per una tabella
     */
    private function getTableHeaders($tableName) {
        $headers = [
            'ANA_CLIENTI' => ['ID_CLIENTE', 'Cliente', 'Denominazione_Sociale', 'Indirizzo', 'Citta', 'CAP', 'Provincia', 'P_IVA'],
            'ANA_COLLABORATORI' => ['ID_COLLABORATORE', 'Collaboratore', 'Email', 'PWD', 'Ruolo', 'PIVA'],
            'ANA_COMMESSE' => ['ID_COMMESSA', 'Commessa', 'Desc_Commessa', 'Tipo_Commessa', 'ID_CLIENTE', 'Commissione', 'ID_COLLABORATORE', 'Data_Apertura_Commessa', 'Stato_Commessa'],
            'ANA_TASK' => ['ID_TASK', 'Task', 'Desc_Task', 'ID_COMMESSA', 'ID_COLLABORATORE', 'Tipo', 'Data_Apertura_Task', 'Stato_Task', 'gg_previste', 'Spese_Comprese', 'Valore_Spese_std', 'Valore_gg'],
            'ANA_TARIFFE_COLLABORATORI' => ['ID_TARIFFA', 'ID_COLLABORATORE', 'ID_COMMESSA', 'Tariffa_gg', 'Spese_comprese', 'Dal'],
            'FACT_GIORNATE' => ['ID_GIORNATA', 'Data', 'ID_COLLABORATORE', 'ID_TASK', 'Tipo', 'Desk', 'gg', 'Spese_Viaggi', 'Vitto_alloggio', 'Altri_costi', 'Note'],
            'FACT_FATTURE' => ['ID_FATTURA', 'Data', 'ID_CLIENTE', 'TIPO', 'NR', 'ID_COMMESSA', 'Fatturato_gg', 'Fatturato_Spese', 'Fatturato_TOT', 'Note', 'Riferimento_Ordine', 'Data_Ordine', 'Tempi_Pagamento', 'Scadenza_Pagamento', 'Data_Pagamento', 'Valore_Pagato']
        ];
        
        return $headers[$tableName] ?? [];
    }
    
    /**
     * Legge un file CSV con rilevamento automatico del separatore
     */
    private function readCSV($filename) {
        $data = [];
        $headers = [];
        
        if (($handle = fopen($filename, 'r')) !== FALSE) {
            // Leggi la prima riga per rilevare il separatore
            $firstLine = fgets($handle);
            rewind($handle);
            
            // Rileva separatore automaticamente
            $separator = $this->detectCSVSeparator($firstLine);
            echo "<div class='log-entry'>Separatore rilevato: '<strong>$separator</strong>'</div>";
            
            // Prima riga = headers
            if (($headerRow = fgetcsv($handle, 0, $separator)) !== FALSE) {
                $headers = array_map('trim', $headerRow);
                
                // Rimuovi eventuali BOM UTF-8
                if (!empty($headers[0])) {
                    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
                }
                
                echo "<div class='log-entry'>Headers trovati: " . implode(', ', $headers) . "</div>";
            }
            
            // Righe dati
            while (($row = fgetcsv($handle, 0, $separator)) !== FALSE) {
                if (!$this->isEmptyRow($row)) {
                    // Pulisci i dati
                    $row = array_map('trim', $row);
                    
                    // Assicurati che il numero di colonne corrisponda agli headers
                    $row = array_pad($row, count($headers), '');
                    $data[] = array_slice($row, 0, count($headers));
                }
            }
            
            fclose($handle);
        }
        
        return [
            'headers' => $headers,
            'data' => $data
        ];
    }
    
    /**
     * Rileva automaticamente il separatore CSV
     */
    private function detectCSVSeparator($line) {
        $separators = [',', ';', '\t', '|'];
        $maxCount = 0;
        $bestSeparator = ',';
        
        foreach ($separators as $separator) {
            $actualSeparator = ($separator === '\t') ? "\t" : $separator;
            $count = substr_count($line, $actualSeparator);
            
            if ($count > $maxCount) {
                $maxCount = $count;
                $bestSeparator = $actualSeparator;
            }
        }
        
        return $bestSeparator;
    }
    
    /**
     * Costruisce la query SQL per inserimento/aggiornamento
     */
    private function buildInsertSQL($tableName, $headers, $mode) {
        $columns = implode(', ', $headers);
        $placeholders = ':' . implode(', :', $headers);
        
        if ($mode === 'update') {
            $primaryKey = $headers[0];
            $setClause = [];
            foreach (array_slice($headers, 1) as $column) {
                $setClause[] = "$column = :$column";
            }
            return "UPDATE $tableName SET " . implode(', ', $setClause) . " WHERE $primaryKey = :$primaryKey";
        } else {
            $sql = "INSERT INTO $tableName ($columns) VALUES ($placeholders)";
            if ($mode === 'upsert') {
                $updateClause = [];
                foreach (array_slice($headers, 1) as $column) {
                    $updateClause[] = "$column = VALUES($column)";
                }
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateClause);
            }
            return $sql;
        }
    }
    
    /**
     * Processa i dati di una riga applicando le trasformazioni necessarie
     */
    private function processRowData($tableName, $headers, $row) {
        $processedRow = [];
        
        foreach ($headers as $index => $column) {
            $value = isset($row[$index]) ? trim($row[$index]) : '';
            
            // Applica trasformazioni specifiche per tabella/campo
            if (isset($this->fieldMappings[$tableName][$column])) {
                $transformation = $this->fieldMappings[$tableName][$column];
                $value = $this->applyTransformation($value, $transformation);
            }
            
            // Trasformazioni generali
            $value = $this->applyGeneralTransformations($column, $value);
            
            $processedRow[$column] = $value;
        }
        
        return $processedRow;
    }
    
    /**
     * Applica trasformazioni specifiche
     */
    private function applyTransformation($value, $transformation) {
        switch ($transformation) {
            case 'password_hash':
                return $value ? password_hash($value, PASSWORD_DEFAULT) : '';
            case 'enum_fix':
                return ($value === '' || $value === null) ? 'No' : $value;
            case 'date_fix':
                return ($value === '' || $value === '0000-00-00') ? null : $value;
            default:
                return $value;
        }
    }
    
    /**
     * Applica trasformazioni generali basate sul nome del campo
     */
    private function applyGeneralTransformations($column, $value) {
        // Gestione valori monetari
        if (preg_match('/(Fatturato|Tariffa|Spese|Valore|Costi)/i', $column)) {
            return is_numeric($value) ? $value : 0;
        }
        
        // Gestione percentuali (commissioni)
        if (strpos($column, 'Commissione') !== false) {
            return is_numeric($value) ? $value : 0;
        }
        
        // Gestione date
        if (strpos($column, 'Data') === 0 && $value) {
            // Converte vari formati di data in MySQL format
            $value = $this->normalizeDate($value);
        }
        
        return $value;
    }
    
    /**
     * Normalizza formato date
     */
    private function normalizeDate($dateString) {
        if (empty($dateString) || $dateString === '0000-00-00') {
            return null;
        }
        
        // Prova diversi formati
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d'];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        
        // Se non riesce a parsare, ritorna il valore originale
        return $dateString;
    }
    
    /**
     * Esegue upsert (insert o update)
     */
    private function upsertRow($tableName, $headers, $row) {
        $primaryKey = $headers[0];
        $primaryValue = $row[$primaryKey];
        
        // Verifica se il record esiste
        $checkSql = "SELECT COUNT(*) FROM $tableName WHERE $primaryKey = ?";
        $stmt = $this->db->prepare($checkSql);
        $stmt->execute([$primaryValue]);
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            // UPDATE
            $setClause = [];
            $values = [];
            foreach (array_slice($headers, 1) as $column) {
                $setClause[] = "$column = ?";
                $values[] = $row[$column];
            }
            $values[] = $primaryValue; // WHERE condition
            
            $updateSql = "UPDATE $tableName SET " . implode(', ', $setClause) . " WHERE $primaryKey = ?";
            $stmt = $this->db->prepare($updateSql);
            $stmt->execute($values);
            return 'updated';
        } else {
            // INSERT
            $columns = implode(', ', $headers);
            $placeholders = str_repeat('?,', count($headers) - 1) . '?';
            $insertSql = "INSERT INTO $tableName ($columns) VALUES ($placeholders)";
            
            $stmt = $this->db->prepare($insertSql);
            $stmt->execute(array_values($row));
            return 'inserted';
        }
    }
    
    /**
     * Verifica se una riga è vuota
     */
    private function isEmptyRow($row) {
        return empty(array_filter($row, function($value) {
            return trim($value) !== '';
        }));
    }
    
    /**
     * Svuota una tabella
     */
    private function truncateTable($tableName) {
        echo "<div class='log-entry'>🗑️ Svuotamento tabella $tableName...</div>";
        try {
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->exec("TRUNCATE TABLE $tableName");
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
            echo "<div class='log-entry success'>✅ Tabella svuotata</div>";
        } catch (PDOException $e) {
            echo "<div class='log-entry error'>❌ Errore svuotamento: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    /**
     * Mostra statistiche finali
     */
    private function showFinalStats() {
        echo "<div class='final-stats'>";
        echo "<h3>📊 Statistiche Importazione</h3>";
        echo "<div class='stats-grid'>";
        echo "<div class='stat-item'><span class='stat-label'>Righe processate:</span> <span class='stat-value'>{$this->stats['processed']}</span></div>";
        echo "<div class='stat-item'><span class='stat-label'>Righe inserite:</span> <span class='stat-value success'>{$this->stats['inserted']}</span></div>";
        echo "<div class='stat-item'><span class='stat-label'>Righe aggiornate:</span> <span class='stat-value info'>{$this->stats['updated']}</span></div>";
        echo "<div class='stat-item'><span class='stat-label'>Errori:</span> <span class='stat-value error'>{$this->stats['errors']}</span></div>";
        echo "<div class='stat-item'><span class='stat-label'>Righe saltate:</span> <span class='stat-value warning'>{$this->stats['skipped']}</span></div>";
        
        $successRate = $this->stats['processed'] > 0 
            ? round(($this->stats['inserted'] + $this->stats['updated']) / $this->stats['processed'] * 100, 2)
            : 0;
        echo "<div class='stat-item'><span class='stat-label'>Tasso successo:</span> <span class='stat-value'>{$successRate}%</span></div>";
        echo "</div>";
        
        if ($this->stats['errors'] > 0) {
            echo "<div class='alert warning'>⚠️ Sono stati rilevati errori. Controlla i dettagli sopra.</div>";
        } else {
            echo "<div class='alert success'>🎉 Importazione completata senza errori!</div>";
        }
        echo "</div>";
    }
    
    /**
     * Crea file CSV di esempio
     */
    public function createSampleCSVFiles() {
        echo "📝 Creazione file CSV di esempio...\n";
        
        $sampleData = $this->getSampleData();
        
        foreach ($sampleData as $tableName => $data) {
            $csvFile = $this->dataDir . '/' . $tableName . '.csv';
            
            if (($handle = fopen($csvFile, 'w')) !== FALSE) {
                // Scrivi header
                fputcsv($handle, $data['headers']);
                
                // Scrivi dati
                foreach ($data['data'] as $row) {
                    fputcsv($handle, $row);
                }
                
                fclose($handle);
                echo "  ✅ Creato {$tableName}.csv\n";
            }
        }
        
        echo "✅ File CSV di esempio creati nella cartella /Dati\n";
    }
    
    /**
     * Dati di esempio per i file CSV
     */
    private function getSampleData() {
        return [
            'ANA_CLIENTI' => [
                'headers' => ['ID_CLIENTE', 'Cliente', 'Denominazione_Sociale', 'Indirizzo', 'Citta', 'CAP', 'Provincia', 'P_IVA'],
                'data' => [
                    ['CLI0001', 'ALBINI', 'ALBINI SRL', 'Via Roma 1', 'Milano', '20100', 'MI', '12345678901'],
                    ['CLI0002', 'LEVONI', 'LEVONI SPA', 'Via Verdi 2', 'Verona', '37100', 'VR', '12345678902'],
                    ['CLI0003', 'CALVI CARNI', 'CALVI CARNI SRL', 'Via Bianchi 3', 'Bologna', '40100', 'BO', '12345678903']
                ]
            ],
            'ANA_COLLABORATORI' => [
                'headers' => ['ID_COLLABORATORE', 'Collaboratore', 'Email', 'PWD', 'Ruolo', 'PIVA'],
                'data' => [
                    ['CONS001', 'Alessandro Vaglio', 'avaglio@vaglioandpartners.com', 'Boss01', 'Manager', ''],
                    ['CONS002', 'Paola Vaglio', 'pvaglio@vaglioandpartners.com', 'Boss02', 'Amministrazione', ''],
                    ['CONS003', 'Mario Rossi', 'mrossi@vaglioandpartners.com', 'User123', 'User', '']
                ]
            ],
            'ANA_COMMESSE' => [
                'headers' => ['ID_COMMESSA', 'Commessa', 'Desc_Commessa', 'Tipo_Commessa', 'ID_CLIENTE', 'Commissione', 'ID_COLLABORATORE', 'Data_Apertura_Commessa', 'Stato_Commessa'],
                'data' => [
                    ['COM0001', 'ALBINI AUDIT', 'Audit sistema qualità', 'Cliente', 'CLI0001', '0.15', 'CONS001', '2024-01-15', 'In corso'],
                    ['COM0002', 'LEVONI FORMAZIONE', 'Formazione personale', 'Cliente', 'CLI0002', '0.20', 'CONS003', '2024-02-01', 'In corso']
                ]
            ]
        ];
    }
    
    /**
     * Formatta dimensione file
     */
    public function formatBytes($size) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;
        return number_format($size / pow(1024, $power), 2, '.', '') . ' ' . $units[$power];
    }
}

// Funzione helper per formattare bytes
function formatBytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = $size > 0 ? floor(log($size, 1024)) : 0;
    return number_format($size / pow(1024, $power), 2, '.', '') . ' ' . $units[$power];
}

// ============================================================================
// INTERFACCIA WEB
// ============================================================================

$action = $_GET['action'] ?? $_POST['action'] ?? 'scan';
$message = '';
$messageType = '';

try {
    if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $importer = new CSVImporter();
        
        // Opzioni importazione
        $options = [
            'mode' => $_POST['import_mode'] ?? 'insert',
            'truncate' => isset($_POST['truncate_tables'])
        ];
        
        // Avvia importazione
        ob_start();
        $stats = $importer->importAllCSV($options);
        $importOutput = ob_get_clean();
        
    } elseif ($action === 'create_samples') {
        $importer = new CSVImporter();
        ob_start();
        $importer->createSampleCSVFiles();
        $createOutput = ob_get_clean();
        $message = "File CSV di esempio creati con successo!";
        $messageType = 'success';
    }
    
} catch (Exception $e) {
    $message = $e->getMessage();
    $messageType = 'error';
}

// Scansiona sempre i file disponibili
$importer = new CSVImporter();
$scanResult = $importer->scanCSVFiles();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importazione CSV - Vaglio & Partners</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; line-height: 1.6; }
        
        .header { background: #28a745; color: white; padding: 1.5rem; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin-bottom: 0.5rem; }
        .header p { opacity: 0.9; }
        
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; overflow: hidden; }
        .card-header { background: #f8f9fa; padding: 1rem; border-bottom: 1px solid #dee2e6; }
        .card-header h3 { color: #495057; }
        .card-body { padding: 2rem; }
        
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057; }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid #ced4da; border-radius: 4px; font-size: 1rem; }
        .form-control:focus { outline: none; border-color: #28a745; box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1); }
        
        .checkbox-group { display: flex; align-items: center; gap: 0.5rem; }
        .checkbox-group input[type="checkbox"] { transform: scale(1.2); }
        
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.3s; margin-right: 0.5rem; margin-bottom: 0.5rem; }
        .btn-primary { background: #28a745; color: white; }
        .btn-primary:hover { background: #218838; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        
        .alert { padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert.warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert.info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        
        .file-list { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 1rem; }
        .file-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .file-item:last-child { border-bottom: none; }
        .file-info { font-family: monospace; font-size: 0.9rem; color: #6c757d; }
        
        .import-output { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 1rem; max-height: 500px; overflow-y: auto; }
        
        .log-entry { padding: 0.25rem 0; border-bottom: 1px solid #eee; }
        .log-entry:last-child { border-bottom: none; }
        .log-entry.success { color: #28a745; }
        .log-entry.error { color: #dc3545; }
        .log-entry.warning { color: #ffc107; }
        
        .progress-container { background: #e9ecef; border-radius: 4px; height: 8px; margin: 0.5rem 0; overflow: hidden; }
        .progress-bar { background: #28a745; height: 100%; transition: width 0.3s ease; width: 0%; }
        
        .table-section { margin: 1rem 0; padding: 1rem; background: #f8f9fa; border-left: 4px solid #28a745; }
        .table-stats { margin-top: 0.5rem; padding: 0.5rem; background: white; border-radius: 4px; }
        
        .final-stats { margin-top: 2rem; padding: 1.5rem; background: #f8f9fa; border-radius: 8px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin: 1rem 0; }
        .stat-item { display: flex; justify-content: space-between; padding: 0.5rem; background: white; border-radius: 4px; }
        .stat-label { font-weight: 500; }
        .stat-value { font-weight: bold; }
        .stat-value.success { color: #28a745; }
        .stat-value.error { color: #dc3545; }
        .stat-value.warning { color: #ffc107; }
        .stat-value.info { color: #17a2b8; }
        
        .requirements { background: #e8f5e8; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; border-left: 4px solid #28a745; }
        .requirements h4 { color: #155724; margin-bottom: 1rem; }
        .requirements ul { margin-left: 1.5rem; }
        .requirements li { margin-bottom: 0.5rem; }
        
        .missing-files { background: #fff3cd; padding: 1rem; border-radius: 4px; border-left: 4px solid #ffc107; margin: 1rem 0; }
        
        .import-section { margin-top: 1rem; }
        .import-section h3 { color: #28a745; margin-bottom: 1rem; }
        
        @media (max-width: 768px) {
            .container { padding: 0 0.5rem; }
            .card-body { padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .file-item { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📂 Importazione Dati CSV</h1>
        <p>Sistema di importazione CSV per Vaglio & Partners Database</p>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Sezione Requisiti -->
        <div class="requirements">
            <h4>📋 Struttura File CSV</h4>
            <ul>
                <li><strong>Posizione:</strong> Crea una cartella <code>/Dati</code> nella stessa directory di questo file</li>
                <li><strong>Nomi file:</strong> Devono essere esattamente: <code>ANA_CLIENTI.csv</code>, <code>ANA_COLLABORATORI.csv</code>, etc.</li>
                <li><strong>Formato:</strong> CSV con separatori supportati: virgola (,), punto e virgola (;), tab, pipe (|)</li>
                <li><strong>Codifica:</strong> UTF-8 (per caratteri speciali)</li>
                <li><strong>Rilevamento automatico:</strong> Il separatore viene rilevato automaticamente</li>
                <li><strong>Ordine importazione:</strong> Automatico (rispetta le relazioni foreign key)</li>
            </ul>
        </div>

        <!-- Status File CSV -->
        <div class="card">
            <div class="card-header">
                <h3>📁 Status File CSV</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($scanResult['available'])): ?>
                    <h4>✅ File Disponibili (<?= count($scanResult['available']) ?>)</h4>
                    <div class="file-list">
                        <?php foreach ($scanResult['available'] as $table => $info): ?>
                            <div class="file-item">
                                <div>
                                    <strong><?= $table ?>.csv</strong>
                                    <span class="file-info">- <?= $info['rows'] ?> righe</span>
                                </div>
                                <div class="file-info">
                                    <?= formatBytes($info['size']) ?> | 
                                    Modificato: <?= date('d/m/Y H:i', $info['modified']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert warning">
                        ⚠️ Nessun file CSV trovato nella cartella <code>/Dati</code>
                    </div>
                <?php endif; ?>

                <?php if (!empty($scanResult['missing'])): ?>
                    <div class="missing-files">
                        <h4>❌ File Mancanti</h4>
                        <p>I seguenti file non sono stati trovati:</p>
                        <ul>
                            <?php foreach ($scanResult['missing'] as $table): ?>
                                <li><code><?= $table ?>.csv</code></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 1.5rem;">
                    <a href="?action=create_samples" class="btn btn-warning">
                        📝 Crea File CSV di Esempio
                    </a>
                    <a href="?" class="btn btn-secondary">
                        🔄 Aggiorna Status
                    </a>
                </div>
            </div>
        </div>

        <!-- Form Importazione -->
        <?php if (!empty($scanResult['available']) && !isset($importOutput)): ?>
        <div class="card">
            <div class="card-header">
                <h3>🚀 Importazione CSV</h3>
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="form-group">
                        <label for="import_mode">Modalità importazione:</label>
                        <select id="import_mode" name="import_mode" class="form-control">
                            <option value="insert">Insert - Solo nuovi record (ignora duplicati)</option>
                            <option value="upsert">Upsert - Inserisce o aggiorna automaticamente</option>
                            <option value="update">Update - Solo aggiornamento record esistenti</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="truncate_tables" name="truncate_tables">
                            <label for="truncate_tables">⚠️ Svuota tabelle prima dell'importazione (ATTENZIONE: cancella tutti i dati esistenti!)</label>
                        </div>
                    </div>

                    <button type="submit" name="action" value="import" class="btn btn-primary">
                        🚀 Avvia Importazione
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Output Creazione Sample -->
        <?php if (isset($createOutput)): ?>
        <div class="card">
            <div class="card-header">
                <h3>📝 File CSV di Esempio Creati</h3>
            </div>
            <div class="card-body">
                <div class="import-output">
                    <?= nl2br(htmlspecialchars($createOutput)) ?>
                </div>
                <div style="margin-top: 1rem;">
                    <a href="?" class="btn btn-primary">🔄 Aggiorna Pagina</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Risultati Importazione -->
        <?php if (isset($importOutput)): ?>
        <div class="card">
            <div class="card-header">
                <h3>📊 Risultati Importazione</h3>
            </div>
            <div class="card-body">
                <div class="import-output">
                    <?= $importOutput ?>
                </div>
                
                <div style="margin-top: 2rem; text-align: center;">
                    <a href="?" class="btn btn-primary">🔄 Nuova Importazione</a>
                    <a href="log_viewer.php" class="btn btn-secondary">📋 Visualizza Log</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sezione Aiuto -->
        <div class="card">
            <div class="card-header">
                <h3>❓ Guida e Struttura File</h3>
            </div>
            <div class="card-body">
                <h4>📂 Struttura Directory:</h4>
                <pre style="background: #f8f9fa; padding: 1rem; border-radius: 4px; margin: 1rem 0;">
/tuo-progetto/
├── import_csv.php        # Questo file
├── Dati/                # Cartella con file CSV
│   ├── ANA_CLIENTI.csv
│   ├── ANA_COLLABORATORI.csv
│   ├── ANA_COMMESSE.csv
│   ├── ANA_TASK.csv
│   ├── ANA_TARIFFE_COLLABORATORI.csv
│   ├── FACT_GIORNATE.csv
│   └── FACT_FATTURE.csv
└── ...
                </pre>

                <h4>📋 Intestazioni CSV Richieste:</h4>
                <div style="margin: 1rem 0;">
                    <details style="margin-bottom: 0.5rem;">
                        <summary><strong>ANA_CLIENTI.csv</strong></summary>
                        <code>ID_CLIENTE,Cliente,Denominazione_Sociale,Indirizzo,Citta,CAP,Provincia,P_IVA</code>
                    </details>
                    
                    <details style="margin-bottom: 0.5rem;">
                        <summary><strong>ANA_COLLABORATORI.csv</strong></summary>
                        <code>ID_COLLABORATORE,Collaboratore,Email,PWD,Ruolo,PIVA</code>
                    </details>
                    
                    <details style="margin-bottom: 0.5rem;">
                        <summary><strong>ANA_COMMESSE.csv</strong></summary>
                        <code>ID_COMMESSA,Commessa,Desc_Commessa,Tipo_Commessa,ID_CLIENTE,Commissione,ID_COLLABORATORE,Data_Apertura_Commessa,Stato_Commessa</code>
                    </details>
                    
                    <details style="margin-bottom: 0.5rem;">
                        <summary><strong>ANA_TASK.csv</strong></summary>
                        <code>ID_TASK,Task,Desc_Task,ID_COMMESSA,ID_COLLABORATORE,Tipo,Data_Apertura_Task,Stato_Task,gg_previste,Spese_Comprese,Valore_Spese_std,Valore_gg</code>
                    </details>
                    
                    <details style="margin-bottom: 0.5rem;">
                        <summary><strong>ANA_TARIFFE_COLLABORATORI.csv</strong></summary>
                        <code>ID_TARIFFA,ID_COLLABORATORE,ID_COMMESSA,Tariffa_gg,Spese_comprese,Dal</code>
                    </details>
                    
                    <details style="margin-bottom: 0.5rem;">
                        <summary><strong>FACT_GIORNATE.csv</strong></summary>
                        <code>ID_GIORNATA,Data,ID_COLLABORATORE,ID_TASK,Tipo,Desk,gg,Spese_Viaggi,Vitto_alloggio,Altri_costi,Note</code>
                    </details>
                    
                    <details style="margin-bottom: 0.5rem;">
                        <summary><strong>FACT_FATTURE.csv</strong></summary>
                        <code>ID_FATTURA,Data,ID_CLIENTE,TIPO,NR,ID_COMMESSA,Fatturato_gg,Fatturato_Spese,Fatturato_TOT,Note,Riferimento_Ordine,Data_Ordine,Tempi_Pagamento,Scadenza_Pagamento,Data_Pagamento,Valore_Pagato</code>
                    </details>
                </div>

                <h4>🔧 Trasformazioni Automatiche:</h4>
                <ul style="margin: 1rem 0 2rem 1.5rem;">
                    <li><strong>Password:</strong> Hash automatico per campo PWD in ANA_COLLABORATORI</li>
                    <li><strong>Date:</strong> Conversione automatica da vari formati (DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD)</li>
                    <li><strong>Valori vuoti:</strong> Gestione automatica di ENUM (vuoto → 'No')</li>
                    <li><strong>Importi:</strong> Conversione automatica di valori numerici</li>
                    <li><strong>Foreign Key:</strong> Importazione nell'ordine corretto</li>
                </ul>

                <h4>🔗 Link Utili:</h4>
                <div style="margin-top: 1rem;">
                    <a href="setup.php" class="btn btn-secondary">🏗️ Setup Database</a>
                    <a href="log_viewer.php" class="btn btn-secondary">📋 Visualizza Log</a>
                    <a href="cleanup.php" class="btn btn-secondary">🗑️ Cleanup Database</a>
                    <a href="import.php" class="btn btn-secondary">📊 Import Excel</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Conferma per operazioni pericolose
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const truncate = document.getElementById('truncate_tables');
                    if (truncate && truncate.checked) {
                        if (!confirm('⚠️ ATTENZIONE: Stai per cancellare tutti i dati esistenti nelle tabelle!\n\nSei sicuro di voler continuare?')) {
                            e.preventDefault();
                        }
                    }
                });
            }
        });

        // Auto-refresh progress bars
        function updateProgressBars() {
            const progressBars = document.querySelectorAll('.progress-bar');
            // La logica di aggiornamento è gestita dal PHP
        }

        // Auto-scroll per seguire il progresso
        function scrollToBottom() {
            const importOutput = document.querySelector('.import-output');
            if (importOutput) {
                importOutput.scrollTop = importOutput.scrollHeight;
            }
        }

        // Aggiorna ogni secondo durante l'importazione
        const progressElements = document.querySelectorAll('.progress-bar');
        if (progressElements.length > 0) {
            setInterval(scrollToBottom, 1000);
        }
    </script>
</body>
</html>