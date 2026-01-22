<?php
// ==========================================
// MAILING LIST DATABASE MANAGER
// ==========================================
// Handles SQLite database operations for the mailing list
// Includes rate limiting and security features

class MailingListDB {
    private $db;
    private $dbPath;

    public function __construct($dbPath = null) {
        $this->dbPath = $dbPath ?? __DIR__ . '/mailinglist.db';
        $this->connect();
        $this->initializeTables();
    }

    /**
     * Connect to SQLite database
     */
    private function connect() {
        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->exec('PRAGMA journal_mode = WAL');
        } catch (PDOException $e) {
            error_log('Mailing List DB Connection Error: ' . $e->getMessage());
            throw new Exception('Datenbankverbindung fehlgeschlagen.');
        }
    }

    /**
     * Initialize database tables
     */
    private function initializeTables() {
        try {
            // Subscribers table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS subscribers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT UNIQUE NOT NULL,
                    subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    confirmed BOOLEAN DEFAULT 0,
                    confirmation_token TEXT,
                    ip_address TEXT,
                    user_agent TEXT,
                    last_email_sent DATETIME,
                    active BOOLEAN DEFAULT 1
                )
            ");

            // Rate limiting table for DDoS protection
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    action TEXT NOT NULL,
                    attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Email log table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    recipient_count INTEGER,
                    had_new_entries BOOLEAN,
                    entry_count INTEGER,
                    success BOOLEAN
                )
            ");

            // Create indexes for performance
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_email ON subscribers(email)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_active ON subscribers(active)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_ip ON rate_limits(ip_address, action)");

        } catch (PDOException $e) {
            error_log('Mailing List DB Table Creation Error: ' . $e->getMessage());
            throw new Exception('Datenbank-Initialisierung fehlgeschlagen.');
        }
    }

    /**
     * Check rate limit for IP address
     * Returns true if rate limit exceeded
     */
    public function checkRateLimit($ipAddress, $action = 'signup', $maxAttempts = 3, $windowMinutes = 60) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as attempts
                FROM rate_limits
                WHERE ip_address = :ip
                AND action = :action
                AND datetime(attempt_time) > datetime('now', '-' || :window || ' minutes')
            ");
            $stmt->execute([
                ':ip' => $ipAddress,
                ':action' => $action,
                ':window' => $windowMinutes
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['attempts'] >= $maxAttempts;

        } catch (PDOException $e) {
            error_log('Rate limit check error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log rate limit attempt
     */
    public function logRateLimitAttempt($ipAddress, $action = 'signup') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO rate_limits (ip_address, action, attempt_time)
                VALUES (:ip, :action, datetime('now'))
            ");
            $stmt->execute([
                ':ip' => $ipAddress,
                ':action' => $action
            ]);
        } catch (PDOException $e) {
            // Silently fail - not critical
            error_log('Rate limit log error: ' . $e->getMessage());
        }
    }

    /**
     * Clean old rate limit entries
     */
    public function cleanOldRateLimits($olderThanHours = 24) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM rate_limits
                WHERE datetime(attempt_time) < datetime('now', '-' || :hours || ' hours')
            ");
            $stmt->execute([':hours' => $olderThanHours]);
        } catch (PDOException $e) {
            error_log('Rate limit cleanup error: ' . $e->getMessage());
        }
    }

    /**
     * Add a new subscriber
     */
    public function addSubscriber($email, $ipAddress = null, $userAgent = null) {
        try {
            // Generate confirmation token
            $confirmationToken = bin2hex(random_bytes(32));

            $stmt = $this->db->prepare("
                INSERT INTO subscribers (email, confirmation_token, ip_address, user_agent, confirmed)
                VALUES (:email, :token, :ip, :ua, 1)
            ");

            $stmt->execute([
                ':email' => strtolower(trim($email)),
                ':token' => $confirmationToken,
                ':ip' => $ipAddress,
                ':ua' => $userAgent
            ]);

            return [
                'success' => true,
                'token' => $confirmationToken
            ];

        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                throw new Exception('Diese E-Mail-Adresse ist bereits registriert.');
            }
            error_log('Add subscriber error: ' . $e->getMessage());
            throw new Exception('Fehler beim Speichern der E-Mail-Adresse.');
        }
    }

    /**
     * Get all active subscribers
     */
    public function getActiveSubscribers() {
        try {
            $stmt = $this->db->query("
                SELECT email, subscribed_at
                FROM subscribers
                WHERE active = 1 AND confirmed = 1
                ORDER BY subscribed_at ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Get subscribers error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update last email sent timestamp for subscriber
     */
    public function updateLastEmailSent($email) {
        try {
            $stmt = $this->db->prepare("
                UPDATE subscribers
                SET last_email_sent = datetime('now')
                WHERE email = :email
            ");
            $stmt->execute([':email' => $email]);
        } catch (PDOException $e) {
            error_log('Update last email error: ' . $e->getMessage());
        }
    }

    /**
     * Log email sending
     */
    public function logEmailSending($recipientCount, $hadNewEntries, $entryCount, $success) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_log (recipient_count, had_new_entries, entry_count, success)
                VALUES (:recipients, :had_entries, :count, :success)
            ");
            $stmt->execute([
                ':recipients' => $recipientCount,
                ':had_entries' => $hadNewEntries ? 1 : 0,
                ':count' => $entryCount,
                ':success' => $success ? 1 : 0
            ]);
        } catch (PDOException $e) {
            error_log('Log email sending error: ' . $e->getMessage());
        }
    }

    /**
     * Get subscriber count
     */
    public function getSubscriberCount() {
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) as count
                FROM subscribers
                WHERE active = 1 AND confirmed = 1
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'];
        } catch (PDOException $e) {
            error_log('Get subscriber count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Unsubscribe email
     */
    public function unsubscribe($email) {
        try {
            $stmt = $this->db->prepare("
                UPDATE subscribers
                SET active = 0
                WHERE email = :email
            ");
            $stmt->execute([':email' => strtolower(trim($email))]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('Unsubscribe error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if email exists and is active
     */
    public function isSubscribed($email) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM subscribers
                WHERE email = :email AND active = 1
            ");
            $stmt->execute([':email' => strtolower(trim($email))]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
        } catch (PDOException $e) {
            error_log('Check subscription error: ' . $e->getMessage());
            return false;
        }
    }
}
