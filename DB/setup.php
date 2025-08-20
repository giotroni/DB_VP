<?php
/**
 * Setup Database - Creazione tabelle e inserimento dati
 * Eseguire questo file per inizializzare il database con le tabelle e i dati dal file Excel
 */

require_once 'config.php';

class DatabaseSetup {
    private $db;
    
    public function __construct() {
        // Prima verifica se il database esiste, altrimenti lo crea
        $this->ensureDatabaseExists();
        $this->db = getDatabase();
    }
    
    /**
     * Verifica e crea il database se non esiste
     */
    private function ensureDatabaseExists() {
        try {
            // Connessione senza specificare il database
            $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $tempConnection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Verifica se il database esiste
            $stmt = $tempConnection->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([DB_NAME]);
            
            if (!$stmt->fetch()) {
                // Il database non esiste, lo creiamo
                echo "Database " . DB_NAME . " non trovato. Creazione in corso...\n";
                $sql = "CREATE DATABASE `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                $tempConnection->exec($sql);
                echo "Database " . DB_NAME . " creato con successo.\n";
            } else {
                echo "Database " . DB_NAME . " già esistente.\n";
            }
            
            $tempConnection = null; // Chiudi connessione temporanea
            
        } catch (PDOException $e) {
            die("Errore durante la verifica/creazione del database: " . $e->getMessage() . "\n");
        }
    }
    
    // Creazione delle tabelle
    public function createTables() {
        echo "Creazione tabelle...\n";
        
        // 1. ANA_CLIENTI
        $sql = "CREATE TABLE IF NOT EXISTS ANA_CLIENTI (
            ID_CLIENTE VARCHAR(50) PRIMARY KEY,
            Cliente VARCHAR(255),
            Denominazione_Sociale VARCHAR(255),
            Indirizzo VARCHAR(255),
            Citta VARCHAR(255),
            CAP VARCHAR(10),
            Provincia VARCHAR(10),
            P_IVA VARCHAR(20),
            Data_Creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ID_UTENTE_CREAZIONE VARCHAR(50),
            Data_Modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ID_UTENTE_MODIFICA VARCHAR(50),
            INDEX idx_cliente (Cliente),
            INDEX idx_piva (P_IVA)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->executeSQL($sql, "ANA_CLIENTI");
        
        // 2. ANA_COLLABORATORI
        $sql = "CREATE TABLE IF NOT EXISTS ANA_COLLABORATORI (
            ID_COLLABORATORE VARCHAR(50) PRIMARY KEY,
            Collaboratore VARCHAR(255),
            Email VARCHAR(255) UNIQUE,
            PWD VARCHAR(255),
            Ruolo ENUM('Admin', 'Manager', 'User', 'Amministrazione') DEFAULT 'User',
            PIVA VARCHAR(20),
            Data_Creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ID_UTENTE_CREAZIONE VARCHAR(50),
            Data_Modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ID_UTENTE_MODIFICA VARCHAR(50),
            INDEX idx_email (Email),
            INDEX idx_ruolo (Ruolo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->executeSQL($sql, "ANA_COLLABORATORI");
        
        // 3. ANA_COMMESSE
        $sql = "CREATE TABLE IF NOT EXISTS ANA_COMMESSE (
            ID_COMMESSA VARCHAR(50) PRIMARY KEY,
            Commessa VARCHAR(255),
            Desc_Commessa TEXT,
            Tipo_Commessa ENUM('Cliente', 'Interna') DEFAULT 'Cliente',
            ID_CLIENTE VARCHAR(50),
            Commissione DECIMAL(5,4) DEFAULT 0,
            ID_COLLABORATORE VARCHAR(50),
            Data_Apertura_Commessa DATE,
            Stato_Commessa ENUM('In corso', 'Sospesa', 'Chiusa', 'Archiviata') DEFAULT 'In corso',
            Documento_Offerta VARCHAR(500),
            Documento_Ordine VARCHAR(500),
            Data_Creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ID_UTENTE_CREAZIONE VARCHAR(50),
            Data_Modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ID_UTENTE_MODIFICA VARCHAR(50),
            FOREIGN KEY (ID_CLIENTE) REFERENCES ANA_CLIENTI(ID_CLIENTE) ON DELETE SET NULL,
            FOREIGN KEY (ID_COLLABORATORE) REFERENCES ANA_COLLABORATORI(ID_COLLABORATORE) ON DELETE SET NULL,
            INDEX idx_cliente (ID_CLIENTE),
            INDEX idx_stato (Stato_Commessa),
            INDEX idx_data_apertura (Data_Apertura_Commessa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->executeSQL($sql, "ANA_COMMESSE");
        
        // 4. ANA_TASK
        $sql = "CREATE TABLE IF NOT EXISTS ANA_TASK (
            ID_TASK VARCHAR(50) PRIMARY KEY,
            Task VARCHAR(255),
            Desc_Task TEXT,
            ID_COMMESSA VARCHAR(50),
            ID_COLLABORATORE VARCHAR(50),
            Tipo ENUM('Campo', 'Monitoraggio', 'Promo', 'Sviluppo', 'Formazione') DEFAULT 'Campo',
            Data_Apertura_Task DATE,
            Stato_Task ENUM('In corso', 'Sospeso', 'Chiuso', 'Archiviato') DEFAULT 'In corso',
            gg_previste DECIMAL(10,2),
            Spese_Comprese ENUM('Si', 'No') DEFAULT 'No',
            Valore_Spese_std DECIMAL(10,2),
            Valore_gg DECIMAL(10,2),
            Data_Creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ID_UTENTE_CREAZIONE VARCHAR(50),
            Data_Modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ID_UTENTE_MODIFICA VARCHAR(50),
            FOREIGN KEY (ID_COMMESSA) REFERENCES ANA_COMMESSE(ID_COMMESSA) ON DELETE CASCADE,
            FOREIGN KEY (ID_COLLABORATORE) REFERENCES ANA_COLLABORATORI(ID_COLLABORATORE) ON DELETE SET NULL,
            INDEX idx_commessa (ID_COMMESSA),
            INDEX idx_collaboratore (ID_COLLABORATORE),
            INDEX idx_tipo (Tipo),
            INDEX idx_stato (Stato_Task)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->executeSQL($sql, "ANA_TASK");
        
        // 5. ANA_TARIFFE_COLLABORATORI
        $sql = "CREATE TABLE IF NOT EXISTS ANA_TARIFFE_COLLABORATORI (
            ID_TARIFFA VARCHAR(50) PRIMARY KEY,
            ID_COLLABORATORE VARCHAR(50),
            ID_COMMESSA VARCHAR(50),
            Tariffa_gg DECIMAL(10,2),
            Spese_comprese ENUM('Si', 'No') DEFAULT 'No',
            Dal DATE,
            Data_Creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ID_UTENTE_CREAZIONE VARCHAR(50),
            Data_Modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ID_UTENTE_MODIFICA VARCHAR(50),
            FOREIGN KEY (ID_COLLABORATORE) REFERENCES ANA_COLLABORATORI(ID_COLLABORATORE) ON DELETE CASCADE,
            FOREIGN KEY (ID_COMMESSA) REFERENCES ANA_COMMESSE(ID_COMMESSA) ON DELETE SET NULL,
            INDEX idx_collaboratore (ID_COLLABORATORE),
            INDEX idx_commessa (ID_COMMESSA),
            INDEX idx_dal (Dal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->executeSQL($sql, "ANA_TARIFFE_COLLABORATORI");
        
        // 6. FACT_GIORNATE
        $sql = "CREATE TABLE IF NOT EXISTS FACT_GIORNATE (
            ID_GIORNATA VARCHAR(50) PRIMARY KEY,
            Data DATE,
            ID_COLLABORATORE VARCHAR(50),
            ID_TASK VARCHAR(50),
            Tipo ENUM('Campo', 'Promo', 'Sviluppo', 'Formazione') DEFAULT 'Campo',
            Desk ENUM('Si', 'No') DEFAULT 'No',
            gg DECIMAL(10,2),
            Spese_Viaggi DECIMAL(10,2) DEFAULT 0,
            Vitto_alloggio DECIMAL(10,2) DEFAULT 0,
            Altri_costi DECIMAL(10,2) DEFAULT 0,
            Note TEXT,
            Data_Creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ID_UTENTE_CREAZIONE VARCHAR(50),
            Data_Modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ID_UTENTE_MODIFICA VARCHAR(50),
            FOREIGN KEY (ID_COLLABORATORE) REFERENCES ANA_COLLABORATORI(ID_COLLABORATORE) ON DELETE CASCADE,
            FOREIGN KEY (ID_TASK) REFERENCES ANA_TASK(ID_TASK) ON DELETE CASCADE,
            INDEX idx_data (Data),
            INDEX idx_collaboratore (ID_COLLABORATORE),
            INDEX idx_task (ID_TASK),
            INDEX idx_tipo (Tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->executeSQL($sql, "FACT_GIORNATE");
        
        // 7. FACT_FATTURE
        $sql = "CREATE TABLE IF NOT EXISTS FACT_FATTURE (
            ID_FATTURA VARCHAR(50) PRIMARY KEY,
            Data DATE,
            ID_CLIENTE VARCHAR(50),
            TIPO ENUM('Fattura', 'Nota_Accredito') DEFAULT 'Fattura',
            NR VARCHAR(100),
            ID_COMMESSA VARCHAR(50),
            Fatturato_gg DECIMAL(10,2) DEFAULT 0,
            Fatturato_Spese DECIMAL(10,2) DEFAULT 0,
            Fatturato_TOT DECIMAL(10,2) DEFAULT 0,
            Note TEXT,
            Riferimento_Ordine VARCHAR(255),
            Data_Ordine DATE,
            Tempi_Pagamento INT,
            Scadenza_Pagamento DATE,
            Data_Pagamento DATE,
            Valore_Pagato DECIMAL(10,2),
            Data_Creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ID_UTENTE_CREAZIONE VARCHAR(50),
            Data_Modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ID_UTENTE_MODIFICA VARCHAR(50),
            FOREIGN KEY (ID_CLIENTE) REFERENCES ANA_CLIENTI(ID_CLIENTE) ON DELETE SET NULL,
            FOREIGN KEY (ID_COMMESSA) REFERENCES ANA_COMMESSE(ID_COMMESSA) ON DELETE SET NULL,
            INDEX idx_data (Data),
            INDEX idx_cliente (ID_CLIENTE),
            INDEX idx_commessa (ID_COMMESSA),
            INDEX idx_numero (NR),
            INDEX idx_scadenza (Scadenza_Pagamento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->executeSQL($sql, "FACT_FATTURE");
        
        echo "Tutte le tabelle sono state create con successo!\n";
    }
    
    // Popolamento dati - RIMOSSO: Ora si usa import.php
    // Tutte le funzioni di inserimento dati sono state spostate in import.php
    
    private function insertClients($clients) {
        $sql = "INSERT INTO ANA_CLIENTI (ID_CLIENTE, Cliente, Denominazione_Sociale, Indirizzo, Citta, CAP, Provincia, P_IVA) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($clients as $client) {
            try {
                $stmt->execute($client);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) { // Ignora errori di duplicato
                    echo "Errore inserimento cliente {$client[0]}: " . $e->getMessage() . "\n";
                }
            }
        }
        echo "Clienti inseriti.\n";
    }
    
    private function insertCollaboratori($collaboratori) {
        $sql = "INSERT INTO ANA_COLLABORATORI (ID_COLLABORATORE, Collaboratore, Email, PWD, Ruolo, PIVA) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($collaboratori as $collab) {
            try {
                $stmt->execute($collab);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) {
                    echo "Errore inserimento collaboratore {$collab[0]}: " . $e->getMessage() . "\n";
                }
            }
        }
        echo "Collaboratori inseriti.\n";
    }
    
    private function insertCommesse() {
        $commesse = [
            ['COM0001', 'CALVI SVIL MANAGERIALITA\'', 'Sviluppo Manageriale', 'Cliente', 'CLI0003', 0.27, 'CONS001', '2024-11-30', 'In corso'],
            ['COM0002', 'CONAD AUDIT MAGAZZINO', 'Audit magazzino', 'Cliente', 'CLI0005', 0.00, 'CONS001', '2024-12-01', 'In corso'],
            ['COM0003', 'COOPERLAT AUDIT', 'Audit sistema qualità', 'Cliente', 'CLI0006', 0.00, 'CONS001', '2024-12-15', 'In corso'],
            ['COM0004', 'FILENI CONSULENZA HACCP', 'Consulenza HACCP', 'Cliente', 'CLI0007', 0.15, 'CONS003', '2024-12-20', 'In corso'],
            ['COM0005', 'LEVONI FORMAZIONE', 'Formazione personale', 'Cliente', 'CLI0010', 0.20, 'CONS004', '2025-01-10', 'In corso'],
            ['COM0006', 'NESTLÉ AUDIT STABILIMENTO', 'Audit stabilimento produttivo', 'Cliente', 'CLI0013', 0.25, 'CONS001', '2025-01-15', 'In corso'],
            ['COM0007', 'RANA CONSULENZA QUALITÀ', 'Consulenza sistema qualità', 'Cliente', 'CLI0016', 0.18, 'CONS003', '2025-01-20', 'In corso'],
            ['COM0008', 'PARMAREGGIO MONITORAGGIO', 'Monitoraggio processi', 'Cliente', 'CLI0014', 0.12, 'CONS005', '2025-02-01', 'In corso'],
            ['COM0009', 'SVILUPPO INTERNO', 'Attività di sviluppo interno', 'Interna', NULL, 0.00, 'CONS001', '2024-12-01', 'In corso'],
            ['COM0010', 'FORMAZIONE TEAM', 'Formazione interna team', 'Interna', NULL, 0.00, 'CONS002', '2024-12-15', 'In corso']
        ];
        
        $sql = "INSERT INTO ANA_COMMESSE (ID_COMMESSA, Commessa, Desc_Commessa, Tipo_Commessa, ID_CLIENTE, Commissione, ID_COLLABORATORE, Data_Apertura_Commessa, Stato_Commessa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($commesse as $commessa) {
            try {
                $stmt->execute($commessa);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) {
                    echo "Errore inserimento commessa {$commessa[0]}: " . $e->getMessage() . "\n";
                }
            }
        }
        echo "Commesse inserite.\n";
    }
    
    private function insertTask() {
        $tasks = [
            ['TAS00001', 'CALVI VAGLIO', 'Consulenza Vaglio', 'COM0001', '', 'Campo', '2024-11-30', 'In corso', null, 'Si', null, 1550],
            ['TAS00002', 'CALVI MOZZANICA', 'Consulenza Mozzanica', 'COM0001', 'CONS004', 'Campo', '2024-12-01', 'In corso', 5.0, 'Si', null, 1200],
            ['TAS00003', 'CONAD AUDIT MAG', 'Audit magazzino principale', 'COM0002', 'CONS001', 'Campo', '2024-12-01', 'In corso', 3.0, 'No', 200, 1500],
            ['TAS00004', 'COOPERLAT AUDIT SQ', 'Audit sistema qualità completo', 'COM0003', 'CONS001', 'Campo', '2024-12-15', 'In corso', 4.0, 'No', 300, 1500],
            ['TAS00005', 'FILENI HACCP CONS', 'Consulenza HACCP stabilimento', 'COM0004', 'CONS003', 'Campo', '2024-12-20', 'In corso', 2.5, 'Si', null, 1100],
            ['TAS00006', 'LEVONI FORM PERS', 'Formazione personale produzione', 'COM0005', 'CONS004', 'Formazione', '2025-01-10', 'In corso', 6.0, 'No', 150, 900],
            ['TAS00007', 'NESTLÉ AUDIT STAB', 'Audit stabilimento completo', 'COM0006', 'CONS001', 'Campo', '2025-01-15', 'In corso', 5.0, 'No', 400, 1600],
            ['TAS00008', 'RANA CONS QUAL', 'Consulenza qualità processi', 'COM0007', 'CONS003', 'Campo', '2025-01-20', 'In corso', 3.5, 'Si', null, 1150],
            ['TAS00009', 'PARMAREGGIO MONIT', 'Monitoraggio processi produttivi', 'COM0008', 'CONS005', 'Monitoraggio', '2025-02-01', 'In corso', 8.0, 'No', 100, 800],
            ['TAS00010', 'SVILUPPO PROC INT', 'Sviluppo procedure interne', 'COM0009', 'CONS001', 'Sviluppo', '2024-12-01', 'In corso', 10.0, 'No', null, 0]
        ];
        
        $sql = "INSERT INTO ANA_TASK (ID_TASK, Task, Desc_Task, ID_COMMESSA, ID_COLLABORATORE, Tipo, Data_Apertura_Task, Stato_Task, gg_previste, Spese_Comprese, Valore_Spese_std, Valore_gg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($tasks as $task) {
            try {
                $stmt->execute($task);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) {
                    echo "Errore inserimento task {$task[0]}: " . $e->getMessage() . "\n";
                }
            }
        }
        echo "Task inseriti.\n";
    }
    
    private function insertTariffe() {
        $tariffe = [
            ['TAR00001', 'CONS001', '', 1100, 'No', '2024-11-30'],
            ['TAR00002', 'CONS002', '', 800, 'No', '2024-11-30'],
            ['TAR00003', 'CONS003', '', 900, 'No', '2024-11-30'],
            ['TAR00004', 'CONS004', '', 850, 'No', '2024-11-30'],
            ['TAR00005', 'CONS005', '', 750, 'No', '2024-11-30']
        ];
        
        $sql = "INSERT INTO ANA_TARIFFE_COLLABORATORI (ID_TARIFFA, ID_COLLABORATORE, ID_COMMESSA, Tariffa_gg, Spese_comprese, Dal) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($tariffe as $tariffa) {
            try {
                $stmt->execute($tariffa);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) {
                    echo "Errore inserimento tariffa {$tariffa[0]}: " . $e->getMessage() . "\n";
                }
            }
        }
        echo "Tariffe inserite.\n";
    }
    
    private function insertGiornate() {
        // Inserimento di alcune giornate di esempio
        $giornate = [
            ['DAY000000001', '2025-01-09', 'CONS001', 'TAS00006', 'Campo', 'No', 1, 250, 0, 0, ''],
            ['DAY000000002', '2025-01-10', 'CONS001', 'TAS00007', 'Campo', 'No', 1, 300, 0, 0, 'Audit primo giorno'],
            ['DAY000000003', '2025-01-11', 'CONS003', 'TAS00005', 'Campo', 'No', 0.5, 0, 0, 0, 'Mezza giornata consulenza'],
            ['DAY000000004', '2025-01-12', 'CONS004', 'TAS00006', 'Formazione', 'No', 1, 150, 0, 0, 'Formazione team'],
            ['DAY000000005', '2025-01-13', 'CONS001', 'TAS00007', 'Campo', 'No', 1, 300, 80, 0, 'Continuazione audit'],
            ['DAY000000006', '2025-01-14', 'CONS005', 'TAS00009', 'Monitoraggio', 'Si', 1, 0, 0, 0, 'Monitoraggio da remoto'],
            ['DAY000000007', '2025-01-15', 'CONS003', 'TAS00008', 'Campo', 'No', 1, 200, 0, 0, 'Consulenza qualità'],
            ['DAY000000008', '2025-01-16', 'CONS001', 'TAS00004', 'Campo', 'No', 1, 350, 0, 0, 'Audit sistema qualità'],
            ['DAY000000009', '2025-01-17', 'CONS004', 'TAS00002', 'Campo', 'No', 1, 180, 0, 0, 'Consulenza Calvi'],
            ['DAY000000010', '2025-01-18', 'CONS001', 'TAS00010', 'Sviluppo', 'Si', 1, 0, 0, 0, 'Sviluppo procedure']
        ];
        
        $sql = "INSERT INTO FACT_GIORNATE (ID_GIORNATA, Data, ID_COLLABORATORE, ID_TASK, Tipo, Desk, gg, Spese_Viaggi, Vitto_alloggio, Altri_costi, Note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($giornate as $giornata) {
            try {
                $stmt->execute($giornata);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) {
                    echo "Errore inserimento giornata {$giornata[0]}: " . $e->getMessage() . "\n";
                }
            }
        }
        echo "Giornate inserite.\n";
    }
    
    private function insertFatture() {
        $fatture = [
            ['FAT25001', '2025-01-31', 'CLI0006', 'Fattura', '25_001', 'COM0003', 1550, 250, 1800, 'Attività di supporto consulenziale', '', null, 30, '2025-03-01', null, null],
            ['FAT25002', '2025-01-31', 'CLI0003', 'Fattura', '25_002', 'COM0001', 3100, 500, 3600, 'Sviluppo manageriale - primo periodo', '', null, 30, '2025-03-02', null, null],
            ['FAT25003', '2025-02-15', 'CLI0007', 'Fattura', '25_003', 'COM0004', 2750, 0, 2750, 'Consulenza HACCP completa', '', null, 30, '2025-03-17', null, null],
            ['FAT25004', '2025-02-20', 'CLI0013', 'Fattura', '25_004', 'COM0006', 8000, 1200, 9200, 'Audit stabilimento produttivo', '', null, 60, '2025-04-21', null, null],
            ['FAT25005', '2025-02-28', 'CLI0010', 'Fattura', '25_005', 'COM0005', 5400, 900, 6300, 'Formazione personale - febbraio', '', null, 30, '2025-03-30', null, null]
        ];
        
        $sql = "INSERT INTO FACT_FATTURE (ID_FATTURA, Data, ID_CLIENTE, TIPO, NR, ID_COMMESSA, Fatturato_gg, Fatturato_Spese, Fatturato_TOT, Note, Riferimento_Ordine, Data_Ordine, Tempi_Pagamento, Scadenza_Pagamento, Data_Pagamento, Valore_Pagato) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($fatture as $fattura) {
            try {
                $stmt->execute($fattura);
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) {
                    echo "Errore inserimento fattura {$fattura[0]}: " . $e->getMessage() . "\n";
                }
            }
        }
        echo "Fatture inserite.\n";
    }
    
    private function executeSQL($sql, $tableName) {
        try {
            $this->db->exec($sql);
            echo "Tabella $tableName creata con successo.\n";
        } catch (PDOException $e) {
            echo "Errore creazione tabella $tableName: " . $e->getMessage() . "\n";
        }
    }
    
    public function runSetup() {
        echo "=== SETUP DATABASE VAGLIO & PARTNERS ===\n";
        echo "Creazione solo struttura tabelle...\n\n";
        
        try {
            $this->createTables();
            echo "\n=== SETUP STRUTTURA COMPLETATO CON SUCCESSO ===\n";
            echo "Per importare i dati da Excel, usa: php import.php nomefile.xlsx\n";
        } catch (Exception $e) {
            echo "ERRORE DURANTE IL SETUP: " . $e->getMessage() . "\n";
        }
    }
}

// Esecuzione dello setup se il file viene chiamato direttamente
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    $setup = new DatabaseSetup();
    $setup->runSetup();
}
