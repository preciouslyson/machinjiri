<?php

namespace Mlangeni\Machinjiri\Core\Network;

class DHCPManager {
    private $pdo;
    private $config;
    private $leaseTime;
    private $rateLimits = [];
    private $logFile;

    public function __construct(array $config) {
        $this->config = $config;
        $this->leaseTime = $config['leaseTime'] ?? 86400;
        $this->logFile = $config['logFile'] ?? __DIR__ . '/../../../storage/logs/dhcp.log';
        
        // Initialize database
        $this->initDatabase();
    }

    // Initialize SQLite database
    private function initDatabase(): void {
        $this->pdo = new PDO('sqlite:' . $this->config['dbFile']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create tables if not exists
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS leases (
                ip TEXT PRIMARY KEY,
                mac TEXT NOT NULL,
                expires INTEGER NOT NULL,
                type TEXT CHECK(type IN ('dynamic', 'static', 'bootp')),
                hostname TEXT
            );
            
            CREATE TABLE IF NOT EXISTS reservations (
                ip TEXT PRIMARY KEY,
                mac TEXT UNIQUE NOT NULL,
                hostname TEXT
            );
            
            CREATE TABLE IF NOT EXISTS lease_history (
                id INTEGER PRIMARY KEY,
                timestamp INTEGER NOT NULL,
                event TEXT NOT NULL,
                ip TEXT NOT NULL,
                mac TEXT NOT NULL,
                hostname TEXT
            );
        ");
    }

    // Validate IP address (v4/v6)
    public function validateIP(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    // Validate MAC address
    public function validateMAC(string $mac): bool {
        return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac) === 1;
    }

    // Check IP availability (ping + ARP)
    public function isIPAvailable(string $ip): bool {
        // Ping check (2 attempts, 100ms timeout)
        $ping = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' 
            ? "ping -n 2 -w 100 $ip"
            : "ping -c 2 -W 0.1 $ip";
        
        exec($ping, $output, $result);
        
        // ARP check
        $arp = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? "arp -a $ip"
            : "arp -n $ip";
        
        exec($arp, $arpOutput, $arpResult);
        
        return $result !== 0 && $arpResult !== 0;
    }

    // Assign IP lease with conflict detection
    public function assignLease(string $mac, string $hostname = null, bool $isBootp = false): ?string {
        $this->cleanExpiredLeases();
        $this->rateLimit($mac);
        
        // Check for existing valid lease
        $stmt = $this->pdo->prepare("SELECT ip FROM leases WHERE mac = ? AND expires > ?");
        $stmt->execute([$mac, time()]);
        $existing = $stmt->fetchColumn();
        if ($existing) return $existing;
        
        // Check reservations
        $reserved = $this->getReservedIP($mac);
        if ($reserved) {
            $this->createLease($reserved, $mac, $hostname, 'static');
            $this->logEvent('assign', $reserved, $mac, $hostname);
            return $reserved;
        }
        
        // Find available IP in range
        $ip = $this->findAvailableIP();
        if ($ip) {
            $type = $isBootp ? 'bootp' : 'dynamic';
            $this->createLease($ip, $mac, $hostname, $type);
            $this->logEvent('assign', $ip, $mac, $hostname);
            return $ip;
        }
        
        $this->logEvent('error', null, $mac, $hostname, 'No available IP addresses');
        return null;
    }

    // Renew lease
    public function renewLease(string $ip, string $mac): bool {
        $stmt = $this->pdo->prepare("SELECT * FROM leases WHERE ip = ? AND mac = ?");
        $stmt->execute([$ip, $mac]);
        $lease = $stmt->fetch();
        
        if ($lease) {
            $expires = time() + $this->leaseTime;
            $update = $this->pdo->prepare("UPDATE leases SET expires = ? WHERE ip = ?");
            $update->execute([$expires, $ip]);
            
            $this->logEvent('renew', $ip, $mac);
            return $update->rowCount() > 0;
        }
        
        return false;
    }

    // Release lease
    public function releaseLease(string $ip): bool {
        $stmt = $this->pdo->prepare("SELECT * FROM leases WHERE ip = ?");
        $stmt->execute([$ip]);
        $lease = $stmt->fetch();
        
        if ($lease) {
            $delete = $this->pdo->prepare("DELETE FROM leases WHERE ip = ?");
            $delete->execute([$ip]);
            
            $this->logEvent('release', $ip, $lease['mac']);
            return $delete->rowCount() > 0;
        }
        
        return false;
    }

    // Add IP reservation
    public function addReservation(string $ip, string $mac, string $hostname = null): bool {
        if (!$this->validateIP($ip) || !$this->validateMAC($mac)) {
            return false;
        }
        
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO reservations (ip, mac, hostname)
            VALUES (?, ?, ?)
        ");
        
        return $stmt->execute([$ip, $mac, $hostname]);
    }

    // Get current leases
    public function getActiveLeases(): array {
        $this->cleanExpiredLeases();
        $stmt = $this->pdo->query("SELECT * FROM leases");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get lease history
    public function getLeaseHistory(int $limit = 100): array {
        $stmt = $this->pdo->prepare("SELECT * FROM lease_history ORDER BY timestamp DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get subnet utilization
    public function getUtilization(): array {
        $stmt = $this->pdo->query("
            SELECT 
                (SELECT COUNT(*) FROM leases) as used,
                (SELECT COUNT(*) FROM reservations) as reserved
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Find available IP in range
    private function findAvailableIP(): ?string {
        $start = ip2long($this->config['rangeStart']);
        $end = ip2long($this->config['rangeEnd']);
        
        for ($i = 0; $i < 100; $i++) { // Limit attempts
            $randomIP = long2ip(mt_rand($start, $end));
            
            // Skip reserved IPs
            $reserved = $this->pdo->prepare("SELECT 1 FROM reservations WHERE ip = ?");
            $reserved->execute([$randomIP]);
            if ($reserved->fetchColumn()) continue;
            
            // Check if already leased
            $leased = $this->pdo->prepare("SELECT 1 FROM leases WHERE ip = ? AND expires > ?");
            $leased->execute([$randomIP, time()]);
            if ($leased->fetchColumn()) continue;
            
            // Check network availability
            if ($this->isIPAvailable($randomIP)) {
                return $randomIP;
            }
        }
        
        return null;
    }

    // Create lease record
    private function createLease(string $ip, string $mac, ?string $hostname, string $type): void {
        $expires = ($type === 'bootp') ? PHP_INT_MAX : time() + $this->leaseTime;
        
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO leases (ip, mac, expires, type, hostname)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$ip, $mac, $expires, $type, $hostname]);
    }

    // Get reserved IP for MAC
    private function getReservedIP(string $mac): ?string {
        $stmt = $this->pdo->prepare("SELECT ip FROM reservations WHERE mac = ?");
        $stmt->execute([$mac]);
        return $stmt->fetchColumn() ?: null;
    }

    // Clean expired leases
    private function cleanExpiredLeases(): void {
        $stmt = $this->pdo->prepare("DELETE FROM leases WHERE expires <= ? AND type = 'dynamic'");
        $stmt->execute([time()]);
    }

    // Rate limiting
    private function rateLimit(string $mac): void {
        $now = time();
        $window = 60; // 60-second window
        
        if (!isset($this->rateLimits[$mac])) {
            $this->rateLimits[$mac] = ['count' => 1, 'start' => $now];
            return;
        }
        
        $limit = &$this->rateLimits[$mac];
        
        if ($now - $limit['start'] > $window) {
            $limit = ['count' => 1, 'start' => $now];
        } else {
            $limit['count']++;
            if ($limit['count'] > 5) {
                $this->logEvent('rate_limit', null, $mac, null, 'Exceeded request limit');
                throw new Exception("Request limit exceeded for MAC: $mac");
            }
        }
    }

    // Log events
    private function logEvent(string $event, ?string $ip, ?string $mac, ?string $hostname = null, ?string $message = null): void {
        $log = sprintf(
            "[%s] %-7s %-15s %-17s %-20s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($event),
            $ip ?? 'N/A',
            $mac ?? 'N/A',
            $hostname ?? 'N/A',
            $message ?? ''
        );
        
        // Save to database
        $stmt = $this->pdo->prepare("
            INSERT INTO lease_history (timestamp, event, ip, mac, hostname, message)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([time(), $event, $ip, $mac, $hostname, $message]);
        
        // Append to log file
        file_put_contents($this->logFile, $log, FILE_APPEND);
    }
}