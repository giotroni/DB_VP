<?php
/**
 * Cleanup Database - Cancellazione completa del database
 * ATTENZIONE: Questo file cancella TUTTO il database in modo IRREVERSIBILE
 * Usare con estrema cautela!
 */

require_once 'config.php';

class DatabaseCleanup {
    private $db;
    
    public function __construct() {
        $this->db = getDatabase();
    }
    
    /**
     * Cancella tutte le tabelle del database
     */
    public function dropAllTables() {
        echo "ATTENZIONE: Cancellazione di tutte le tabelle in corso...\n";
        
        // Lista delle tabelle nell'ordine corretto per evitare errori di foreign key
        $tables = [
            'FACT_FATTURE',
            'FACT_GIORNATE', 
            'ANA_TARIFFE_COLLABORATORI',
            'ANA_TASK',
            'ANA_COMMESSE',
            'ANA_CLIENTI',
            'ANA_COLLABORATORI'
        ];
        
        // Disabilita i controlli delle foreign key temporaneamente
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        foreach ($tables as $table) {
            try {
                $sql = "DROP TABLE IF EXISTS $table";
                $this->db->exec($sql);
                echo "Tabella $table cancellata.\n";
            } catch (PDOException $e) {
                echo "Errore cancellazione tabella $table: " . $e->getMessage() . "\n";
            }
        }
        
        // Riabilita i controlli delle foreign key
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "Tutte le tabelle sono state cancellate.\n";
    }
    
    /**
     * Cancella solo i dati dalle tabelle, mantenendo la struttura
     */
    public function truncateAllTables() {
        echo "Cancellazione di tutti i dati dalle tabelle...\n";
        
        // Lista delle tabelle nell'ordine corretto per evitare errori di foreign key
        $tables = [
            'FACT_FATTURE',
            'FACT_GIORNATE', 
            'ANA_TARIFFE_COLLABORATORI',
            'ANA_TASK',
            'ANA_COMMESSE',
            'ANA_CLIENTI',
            'ANA_COLLABORATORI'
        ];
        
        // Disabilita i controlli delle foreign key temporaneamente
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        foreach ($tables as $table) {
            try {
                $sql = "TRUNCATE TABLE $table";
                $this->db->exec($sql);
                echo "Dati cancellati dalla tabella $table.\n";
            } catch (PDOException $e) {
                echo "Errore cancellazione dati da $table: " . $e->getMessage() . "\n";
            }
        }
        
        // Riabilita i controlli delle foreign key
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "Tutti i dati sono stati cancellati dalle tabelle.\n";
    }
    
    /**
     * Cancella completamente il database
     * NOTA: Questa operazione potrebbe rendere impossibile la riconnessione
     */
    public function dropDatabase() {
        echo "ATTENZIONE: Cancellazione completa del database in corso...\n";
        echo "IMPORTANTE: Dopo questa operazione dovrai ricreare il database manualmente\n";
        echo "o usare create_database.php prima di setup.php\n\n";
        
        try {
            $dbName = DB_NAME;
            $sql = "DROP DATABASE IF EXISTS $dbName";
            $this->db->exec($sql);
            echo "Database $dbName cancellato completamente.\n";
            echo "\n⚠️  IMPORTANTE: Prima del prossimo setup.php esegui:\n";
            echo "   1. create_database.php (per ricreare il database)\n";
            echo "   2. Oppure crea manualmente il database dal pannello hosting\n";
        } catch (PDOException $e) {
            echo "Errore cancellazione database: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Mostra statistiche del database prima della cancellazione
     */
    public function showDatabaseStats() {
        echo "=== STATISTICHE DATABASE ===\n";
        
        $tables = [
            'ANA_CLIENTI' => 'Clienti',
            'ANA_COLLABORATORI' => 'Collaboratori',
            'ANA_COMMESSE' => 'Commesse',
            'ANA_TASK' => 'Task',
            'ANA_TARIFFE_COLLABORATORI' => 'Tariffe',
            'FACT_GIORNATE' => 'Giornate',
            'FACT_FATTURE' => 'Fatture'
        ];
        
        foreach ($tables as $tableName => $description) {
            try {
                $stmt = $this->db->query("SELECT COUNT(*) as count FROM $tableName");
                $result = $stmt->fetch();
                echo "$description ($tableName): {$result['count']} record\n";
            } catch (PDOException $e) {
                echo "$description ($tableName): Tabella non esistente\n";
            }
        }
        
        echo "========================\n\n";
    }
    
    /**
     * Funzione di conferma per operazioni pericolose
     */
    private function confirmAction($message) {
        if (php_sapi_name() === 'cli') {
            // Modalità command line
            echo "$message\nDigitare 'CONFERMA' per procedere: ";
            $handle = fopen("php://stdin", "r");
            $confirmation = trim(fgets($handle));
            fclose($handle);
            return $confirmation === 'CONFERMA';
        } else {
            // Modalità web - richiede parametro di conferma
            return isset($_GET['confirm']) && $_GET['confirm'] === 'CONFERMA';
        }
    }
    
    /**
     * Menu principale per la pulizia
     */
    public function runCleanup() {
        echo "=== CLEANUP DATABASE VAGLIO & PARTNERS ===\n";
        echo "ATTENZIONE: Le operazioni seguenti sono IRREVERSIBILI!\n\n";
        
        $this->showDatabaseStats();
        
        if (php_sapi_name() === 'cli') {
            // Modalità command line interattiva
            echo "Scegliere un'opzione:\n";
            echo "1. Cancellare solo i dati (mantenere struttura tabelle)\n";
            echo "2. Cancellare tutte le tabelle\n";
            echo "3. Cancellare completamente il database\n";
            echo "4. Annullare operazione\n";
            echo "Scelta (1-4): ";
            
            $handle = fopen("php://stdin", "r");
            $choice = trim(fgets($handle));
            fclose($handle);
            
            switch ($choice) {
                case '1':
                    if ($this->confirmAction("Cancellare tutti i dati dalle tabelle?")) {
                        $this->truncateAllTables();
                        echo "\n=== CANCELLAZIONE DATI COMPLETATA ===\n";
                    } else {
                        echo "Operazione annullata.\n";
                    }
                    break;
                    
                case '2':
                    if ($this->confirmAction("Cancellare tutte le tabelle?")) {
                        $this->dropAllTables();
                        echo "\n=== CANCELLAZIONE TABELLE COMPLETATA ===\n";
                    } else {
                        echo "Operazione annullata.\n";
                    }
                    break;
                    
                case '3':
                    if ($this->confirmAction("Cancellare completamente il database?")) {
                        $this->dropDatabase();
                        echo "\n=== CANCELLAZIONE DATABASE COMPLETATA ===\n";
                    } else {
                        echo "Operazione annullata.\n";
                    }
                    break;
                    
                case '4':
                default:
                    echo "Operazione annullata.\n";
                    break;
            }
        } else {
            // Modalità web
            $action = $_GET['action'] ?? '';
            
            switch ($action) {
                case 'truncate':
                    if ($this->confirmAction("Cancellare tutti i dati?")) {
                        $this->truncateAllTables();
                        echo "<br><strong>CANCELLAZIONE DATI COMPLETATA</strong><br>";
                    } else {
                        echo "<br><strong>ERRORE: Parametro di conferma mancante o errato</strong><br>";
                        echo "Aggiungere '&confirm=CONFERMA' all'URL per confermare<br>";
                    }
                    break;
                    
                case 'drop_tables':
                    if ($this->confirmAction("Cancellare tutte le tabelle?")) {
                        $this->dropAllTables();
                        echo "<br><strong>CANCELLAZIONE TABELLE COMPLETATA</strong><br>";
                    } else {
                        echo "<br><strong>ERRORE: Parametro di conferma mancante o errato</strong><br>";
                        echo "Aggiungere '&confirm=CONFERMA' all'URL per confermare<br>";
                    }
                    break;
                    
                case 'drop_database':
                    if ($this->confirmAction("Cancellare completamente il database?")) {
                        $this->dropDatabase();
                        echo "<br><strong>CANCELLAZIONE DATABASE COMPLETATA</strong><br>";
                    } else {
                        echo "<br><strong>ERRORE: Parametro di conferma mancante o errato</strong><br>";
                        echo "Aggiungere '&confirm=CONFERMA' all'URL per confermare<br>";
                    }
                    break;
                    
                default:
                    echo "<h3>Opzioni di cancellazione disponibili:</h3>";
                    echo "<p><strong>ATTENZIONE: Tutte le operazioni sono IRREVERSIBILI!</strong></p>";
                    echo "<ul>";
                    echo "<li><a href='?action=truncate&confirm=CONFERMA' onclick='return confirm(\"Sicuro di voler cancellare tutti i dati?\")'>Cancellare solo i dati (mantenere struttura)</a></li>";
                    echo "<li><a href='?action=drop_tables&confirm=CONFERMA' onclick='return confirm(\"Sicuro di voler cancellare tutte le tabelle?\")'>Cancellare tutte le tabelle</a></li>";
                    echo "<li><a href='?action=drop_database&confirm=CONFERMA' onclick='return confirm(\"Sicuro di voler cancellare il database?\")'>Cancellare completamente il database</a></li>";
                    echo "</ul>";
                    echo "<p><em>Nota: Per sicurezza, aggiungere '&confirm=CONFERMA' all'URL per confermare l'operazione</em></p>";
                    break;
            }
        }
    }
    
    /**
     * Backup del database prima della cancellazione
     */
    public function createBackup($backupPath = null) {
        if (!$backupPath) {
            $backupPath = __DIR__ . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
        }
        
        echo "Creazione backup in: $backupPath\n";
        
        try {
            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s %s > %s',
                escapeshellarg(DB_HOST),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASS),
                escapeshellarg(DB_NAME),
                escapeshellarg($backupPath)
            );
            
            $result = shell_exec($command);
            
            if (file_exists($backupPath) && filesize($backupPath) > 0) {
                echo "Backup creato con successo: $backupPath\n";
                return $backupPath;
            } else {
                echo "Errore nella creazione del backup\n";
                return false;
            }
        } catch (Exception $e) {
            echo "Errore durante il backup: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Esecuzione del cleanup se il file viene chiamato direttamente
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    $cleanup = new DatabaseCleanup();
    $cleanup->runCleanup();
}
?>