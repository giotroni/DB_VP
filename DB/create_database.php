<?php
/**
 * Creazione Database - Script per creare il database se non esiste
 * Da eseguire se il database è stato cancellato completamente
 */

require_once 'config.php';

class DatabaseCreator {
    
    public function createDatabase() {
        echo "=== CREAZIONE DATABASE ===\n";
        echo "Database da creare: " . DB_NAME . "\n";
        echo "Host: " . DB_HOST . "\n";
        echo "User: " . DB_USER . "\n\n";
        
        try {
            // Connessione al server MySQL senza specificare il database
            $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            
            echo "Connessione al server MySQL...\n";
            $connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            echo "✅ Connessione al server riuscita.\n";
            
            // Verifica se il database esiste già
            echo "Verifica esistenza database...\n";
            $stmt = $connection->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([DB_NAME]);
            
            if ($stmt->fetch()) {
                echo "⚠️  Database '" . DB_NAME . "' già esistente.\n";
                
                // Verifica se ha tabelle
                $stmt = $connection->prepare("SELECT COUNT(*) as table_count FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?");
                $stmt->execute([DB_NAME]);
                $result = $stmt->fetch();
                
                if ($result['table_count'] > 0) {
                    echo "   Il database contiene {$result['table_count']} tabelle.\n";
                    echo "   Usa setup.php per creare/aggiornare le tabelle.\n";
                } else {
                    echo "   Il database è vuoto. Usa setup.php per creare le tabelle.\n";
                }
                
            } else {
                // Crea il database
                echo "Creazione database '" . DB_NAME . "'...\n";
                $sql = "CREATE DATABASE `" . DB_NAME . "` 
                        CHARACTER SET utf8mb4 
                        COLLATE utf8mb4_unicode_ci";
                $connection->exec($sql);
                echo "✅ Database '" . DB_NAME . "' creato con successo.\n";
                
                // Verifica creazione
                $stmt = $connection->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
                $stmt->execute([DB_NAME]);
                
                if ($stmt->fetch()) {
                    echo "✅ Verifica: Database creato correttamente.\n";
                } else {
                    echo "❌ Errore: Database non trovato dopo la creazione.\n";
                    return false;
                }
            }
            
            // Test connessione al database specifico
            echo "\nTest connessione al database specifico...\n";
            $testDsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $testConnection = new PDO($testDsn, DB_USER, DB_PASS, $options);
            echo "✅ Connessione al database '" . DB_NAME . "' riuscita.\n";
            
            // Mostra informazioni sul database
            $stmt = $testConnection->query("SELECT DATABASE() as current_db, USER() as current_user, VERSION() as mysql_version");
            $info = $stmt->fetch();
            
            echo "\n=== INFORMAZIONI DATABASE ===\n";
            echo "Database corrente: " . $info['current_db'] . "\n";
            echo "Utente corrente: " . $info['current_user'] . "\n";
            echo "Versione MySQL: " . $info['mysql_version'] . "\n";
            
            // Mostra privilegi utente
            echo "\n=== PRIVILEGI UTENTE ===\n";
            try {
                $stmt = $testConnection->query("SHOW GRANTS FOR CURRENT_USER()");
                while ($grant = $stmt->fetch()) {
                    echo "- " . $grant['Grants for ' . $info['current_user']] . "\n";
                }
            } catch (PDOException $e) {
                echo "Non è possibile visualizzare i privilegi: " . $e->getMessage() . "\n";
            }
            
            echo "\n=== COMPLETATO ===\n";
            echo "Il database è pronto. Ora puoi eseguire setup.php per creare le tabelle.\n";
            
            return true;
            
        } catch (PDOException $e) {
            echo "❌ ERRORE: " . $e->getMessage() . "\n";
            
            // Suggerimenti per errori comuni
            $errorCode = $e->getCode();
            echo "\n=== SUGGERIMENTI ===\n";
            
            switch ($errorCode) {
                case 1044:
                    echo "- L'utente non ha i privilegi per creare database\n";
                    echo "- Contatta l'amministratore del server\n";
                    echo "- Oppure crea manualmente il database dal pannello di controllo\n";
                    break;
                    
                case 1045:
                    echo "- Username o password errati\n";
                    echo "- Verifica le credenziali in config.php\n";
                    break;
                    
                case 2002:
                    echo "- Server MySQL non raggiungibile\n";
                    echo "- Verifica l'host in config.php\n";
                    echo "- Verifica che MySQL sia in esecuzione\n";
                    break;
                    
                default:
                    echo "- Verifica le impostazioni in config.php\n";
                    echo "- Contatta l'amministratore del server\n";
            }
            
            return false;
        }
    }
    
    public function showCurrentConfig() {
        echo "=== CONFIGURAZIONE CORRENTE ===\n";
        echo "Host: " . DB_HOST . "\n";
        echo "Database: " . DB_NAME . "\n";
        echo "Username: " . DB_USER . "\n";
        echo "Password: " . (DB_PASS ? str_repeat('*', strlen(DB_PASS)) : 'NON IMPOSTATA') . "\n";
        echo "Charset: " . DB_CHARSET . "\n";
        echo "================================\n\n";
    }
}

// Esecuzione se chiamato direttamente
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    $creator = new DatabaseCreator();
    $creator->showCurrentConfig();
    
    if ($creator->createDatabase()) {
        echo "\n🎉 Operazione completata con successo!\n";
        echo "Prossimo passo: esegui setup.php\n";
    } else {
        echo "\n❌ Operazione fallita. Verifica la configurazione.\n";
    }
}
?>