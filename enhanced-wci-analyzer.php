<?php
/**
 * Enhanced WCI Access & Security Analyzer
 * Kombiniert Quick-Access-Analyse mit Ultra-Secure File Safety Features
 */

// CLI Mode Detection - Skip web interface if running programmatically
$isCLI = (php_sapi_name() === 'cli' || defined('SUPPRESS_WEB_INTERFACE'));

if (!$isCLI) {
    require_once __DIR__ . '/auth.php';

    // Handle AJAX requests BEFORE authentication check (Web Mode Only)
    $action = $_GET['action'] ?? 'dashboard';

if ($action === 'analyze' && isset($_GET['ajax'])) {
    // AJAX requests: check auth but allow localhost without auth for development
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost', '192.168.15.14']) ||
               strpos($_SERVER['HTTP_HOST'] ?? '', '192.168.') === 0;
    
    if (!$isLocal && !AuthManager::checkSession()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    
    $mode = $_GET['mode'] ?? 'quick';
    $days = intval($_GET['days'] ?? 30);
    
    set_time_limit(300);
    ini_set('memory_limit', '512M');
    header('Content-Type: application/json');
    
    try {
        if ($mode === 'security') {
            $analyzer = new UltraSecureFileSafetyAnalyzer();
            $analysis = $analyzer->performUltraSecureAnalysis($days);
            
            echo json_encode([
                'success' => true,
                'mode' => 'security',
                'analysis' => $analysis,
                'execution_time' => time()
            ]);
        } else {
            $analyzer = new WCIAccessAnalyzerPro();
            $analysis = $analyzer->analyzeAccess($days);
            
            echo json_encode([
                'success' => true,
                'mode' => 'quick',
                'analysis' => $analysis,
                'execution_time' => time()
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($action === 'comprehensive' && isset($_GET['ajax'])) {
    // New comprehensive file list endpoint
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost', '192.168.15.14']) ||
               strpos($_SERVER['HTTP_HOST'] ?? '', '192.168.') === 0;
    
    if (!$isLocal && !AuthManager::checkSession()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    
    $days = intval($_GET['days'] ?? 30);
    
    set_time_limit(300);
    ini_set('memory_limit', '512M');
    header('Content-Type: application/json');
    
    try {
        $analyzer = new UltraSecureFileSafetyAnalyzer();
        $fileList = $analyzer->getComprehensiveFileList($days);
        
        echo json_encode([
            'success' => true,
            'mode' => 'comprehensive',
            'files' => $fileList,
            'total_files' => count($fileList),
            'execution_time' => time()
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Check authentication only for web page requests
if (!AuthManager::checkSession()) {
    header('Location: login-simple.php');
    exit;
}
}

// Embedded Ultra-Secure File Safety Analyzer
class UltraSecureFileSafetyAnalyzer {
    private $wciPath;
    private $wciWebPath = '/wci/';
    private $logSources = [];
    private $whitelist = [];
    private $analysis = [];
    private $silentMode = false;
    
    public function __construct($wciPath = null) {
        $this->silentMode = (php_sapi_name() !== 'cli');
        
        if ($wciPath) {
            $this->wciPath = $wciPath;
        } else {
            $possiblePaths = [
                '/home/vadmin/lemp/html/wci',
                '/var/www/html/wci',
                dirname(__FILE__),
                realpath(dirname(__FILE__))
            ];
            
            foreach ($possiblePaths as $path) {
                if (is_dir($path) && file_exists($path . '/config.php')) {
                    $this->wciPath = $path;
                    break;
                }
            }
            
            if (!$this->wciPath) {
                throw new Exception("Could not find WCI directory. Please provide path manually.");
            }
        }
        
        $this->initLogSources();
        $this->initCriticalWhitelist();
    }
    
    private function initLogSources() {
        $possibleLogPaths = [
            'apache_access' => [
                '/home/vadmin/lemp/logs/apache2/access.log',
                '/var/log/apache2/access.log',
                '/var/log/httpd/access_log'
            ],
            'apache_error' => [
                '/home/vadmin/lemp/logs/apache2/error.log',
                '/var/log/apache2/error.log',
                '/var/log/httpd/error_log'
            ],
            'apache_other' => [
                '/home/vadmin/lemp/logs/apache2/other_vhosts_access.log',
                '/var/log/apache2/other_vhosts_access.log'
            ]
        ];
        
        $this->logSources = [];
        
        foreach ($possibleLogPaths as $logType => $paths) {
            foreach ($paths as $path) {
                if (file_exists($path) && is_readable($path)) {
                    $this->logSources[$logType] = $path;
                    break;
                }
            }
        }
        
        // WCI spezifische Logs hinzufügen
        $wciLogs = [
            'wci_sync' => $this->wciPath . '/logs/sync.log',
            'wci_cronjob' => $this->wciPath . '/logs/cronjob-sync.log',
            'wci_av_dump' => $this->wciPath . '/logs/av-res-dump.log'
        ];
        
        foreach ($wciLogs as $logType => $path) {
            if (file_exists($path) && is_readable($path)) {
                $this->logSources[$logType] = $path;
            }
        }
    }
    
    private function initCriticalWhitelist() {
        $this->whitelist = [
            'config.php', 'config-safe.php', 'config-simple.php',
            'auth.php', 'authenticate.php', 'checkAuth.php', 'logout.php',
            'SyncManager.php', 'sync-cron.php', 'sync-cronjob.php',
            'index.php', 'dashboard.php', 'belegung.php',
            'reservierungen/api/addReservation.php', 'reservierungen/api/addReservationNames.php',
            'reservierungen/api/deleteReservation.php', 'reservierungen/api/deleteReservationNames.php',
            'include/data.php', 'style.css', 'script.js',
            'api.php', 'api-access-stats.php',
            'enhanced-wci-analyzer.php',
            '*.sql', 'logs/*', '*.log', '*.backup', '*.bak',
            '.htaccess', '.gitignore', 'README.md', '*.sh'
        ];
    }
    
    public function performUltraSecureAnalysis($daysBack = 365) {
        $this->analysis = [
            'scan_timestamp' => time(),
            'scan_period_days' => $daysBack,
            'log_sources_used' => [],
            'all_files' => [],
            'web_accessed_files' => [],
            'script_accessed_files' => [],
            'code_referenced_files' => [],
            'whitelist_protected' => [],
            'potentially_safe_to_delete' => [],
            'critical_warnings' => []
        ];
        
        $this->scanAllFiles();
        $this->analyzeWebLogs($daysBack);
        $this->analyzeScriptLogs($daysBack);
        $this->scanCodeDependencies();
        $this->applyWhitelist();
        $this->determineSafeDeletionCandidates();
        $this->generateSecurityWarnings();
        
        return $this->analysis;
    }
    
    private function scanAllFiles() {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->wciPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            $relativePath = str_replace($this->wciPath . '/', '', $file->getPathname());
            
            $this->analysis['all_files'][$relativePath] = [
                'full_path' => $file->getPathname(),
                'size' => $file->getSize(),
                'modified' => $file->getMTime(),
                'extension' => $file->getExtension(),
                'is_executable' => $file->isExecutable(),
                'permissions' => substr(sprintf('%o', $file->getPerms()), -4)
            ];
        }
    }
    
    private function analyzeWebLogs($daysBack) {
        $cutoffTime = time() - ($daysBack * 24 * 3600);
        
        foreach ($this->logSources as $source => $logFile) {
            if (!file_exists($logFile)) continue;
            
            $this->analysis['log_sources_used'][] = $source;
            
            if (strpos($source, 'apache') !== false) {
                $this->parseWebAccessLog($logFile, $cutoffTime);
            }
        }
    }
    
    private function parseWebAccessLog($logFile, $cutoffTime) {
        $handle = fopen($logFile, 'r');
        if (!$handle) return;
        
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^(\S+) - - \[([^\]]+)\] "(\S+) (\S+) ([^"]+)" (\d+) (\d+)/', $line, $matches)) {
                $timestamp = $matches[2];
                $url = $matches[4];
                
                $logTime = DateTime::createFromFormat('d/M/Y:H:i:s O', $timestamp);
                if (!$logTime || $logTime->getTimestamp() < $cutoffTime) continue;
                
                if (strpos($url, $this->wciWebPath) === 0) {
                    $filePath = ltrim(str_replace($this->wciWebPath, '', strtok($url, '?')), '/');
                    
                    if (!isset($this->analysis['web_accessed_files'][$filePath])) {
                        $this->analysis['web_accessed_files'][$filePath] = [
                            'first_seen' => $timestamp,
                            'last_seen' => $timestamp,
                            'access_count' => 0,
                            'unique_ips' => []
                        ];
                    }
                    
                    $this->analysis['web_accessed_files'][$filePath]['access_count']++;
                    $this->analysis['web_accessed_files'][$filePath]['last_seen'] = $timestamp;
                    $this->analysis['web_accessed_files'][$filePath]['unique_ips'][$matches[1]] = true;
                }
            }
        }
        
        fclose($handle);
    }
    
    private function analyzeScriptLogs($daysBack) {
        foreach ($this->logSources as $source => $logFile) {
            if (strpos($source, 'wci_') === 0) {
                $this->parseScriptLog($logFile);
            }
        }
        
        $this->scanCronjobFiles();
    }
    
    private function parseScriptLog($logFile) {
        if (!file_exists($logFile)) return;
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (preg_match('/\/([a-zA-Z0-9_-]+\.(php|js|css|html|htm))/', $line, $matches)) {
                $fileName = $matches[1];
                
                if (!isset($this->analysis['script_accessed_files'][$fileName])) {
                    $this->analysis['script_accessed_files'][$fileName] = 0;
                }
                $this->analysis['script_accessed_files'][$fileName]++;
            }
        }
    }
    
    private function scanCronjobFiles() {
        $cronOutput = shell_exec('crontab -l 2>/dev/null');
        if ($cronOutput) {
            preg_match_all('/(\S+\.php|\S+\.sh)/', $cronOutput, $matches);
            
            foreach ($matches[0] as $scriptPath) {
                $fileName = basename($scriptPath);
                $this->analysis['script_accessed_files'][$fileName] = 'CRONJOB_ACTIVE';
            }
        }
    }
    
    private function scanCodeDependencies() {
        foreach ($this->analysis['all_files'] as $relativePath => $fileInfo) {
            // Scan ALL text-based file types - comprehensive list
            $scanableExtensions = [
                // Web technologies
                'php', 'html', 'htm', 'js', 'css', 'json', 'xml', 'xhtml', 'xsl', 'xslt',
                // Programming languages
                'py', 'pl', 'rb', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'vb', 'go', 'rs', 'swift',
                // Scripting & config
                'sh', 'bash', 'zsh', 'fish', 'ps1', 'bat', 'cmd', 'sql', 'conf', 'ini', 'cfg', 'config',
                // Documentation & markup
                'txt', 'md', 'rst', 'tex', 'rtf', 'csv', 'tsv', 'yaml', 'yml', 'toml',
                // Special files
                'htaccess', 'gitignore', 'gitattributes', 'editorconfig', 'npmignore',
                // Build & deployment
                'dockerfile', 'makefile', 'cmake', 'gradle', 'ant', 'properties', 'env', 'envrc',
                // Templates & data
                'tpl', 'tmpl', 'template', 'jinja', 'mustache', 'handlebars', 'ejs',
                // Logs & traces
                'log', 'trace', 'debug', 'error', 'access',
                // Version control & misc
                'patch', 'diff', 'gitkeep', 'svn', 'hg', 'bzr'
            ];
            
            // Also scan files without extension (like Makefile, README, LICENSE, etc.)
            $hasExtension = !empty($fileInfo['extension']);
            $isTextFile = $hasExtension && in_array(strtolower($fileInfo['extension']), $scanableExtensions);
            $noExtensionFile = !$hasExtension && $fileInfo['size'] < 2000000; // < 2MB files without extension
            
            // Scan if it's a known text file or reasonable-sized file without extension
            if ($isTextFile || $noExtensionFile) {
                $this->scanFileForDependencies($fileInfo['full_path'], $relativePath);
            }
        }
    }
    
    private function scanFileForDependencies($filePath, $relativePath) {
        $content = file_get_contents($filePath);
        if (!$content || strlen($content) > 1000000) return; // Skip very large files
        
        $dependencies = [];
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        // === PHP SPECIFIC PATTERNS ===
        // Explicit includes/requires (highest priority)
        if (preg_match_all('/(?:include|require)(?:_once)?\s*[\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // === HTML PATTERNS ===
        // HTML src/href attributes - ALL image types and resources
        if (preg_match_all('/(?:src|href)\s*=\s*[\'"]([^\'"]+\.(css|js|php|html|htm|png|jpg|jpeg|gif|svg|ico|webp|bmp|tiff|pdf|doc|docx|xls|xlsx|zip|rar|json|xml))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // HTML object/embed tags
        if (preg_match_all('/<(?:object|embed)[^>]+(?:data|src)\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // HTML background images
        if (preg_match_all('/background\s*=\s*[\'"]([^\'"]+\.(png|jpg|jpeg|gif|svg|webp))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // === CSS SPECIFIC PATTERNS ===
        // CSS url() function - images, fonts, other resources
        if (preg_match_all('/url\s*\(\s*[\'"]?([^\'")\s]+\.(png|jpg|jpeg|gif|svg|ico|webp|bmp|ttf|woff|woff2|eot|otf|css|js))[\'"]?\s*\)/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // CSS @import statements
        if (preg_match_all('/@import\s+[\'"]([^\'"]+\.css)[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // CSS content property (often used for icons)
        if (preg_match_all('/content\s*:\s*url\s*\(\s*[\'"]?([^\'")\s]+\.(png|jpg|jpeg|gif|svg|ico|webp))[\'"]?\s*\)/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // === JAVASCRIPT PATTERNS ===
        // JavaScript fetch/AJAX calls
        if (preg_match_all('/(?:fetch|XMLHttpRequest|axios\.(?:get|post)|jQuery\.(?:get|post|ajax))\s*\(\s*[\'"]([^\'"]+\.(?:php|json|xml|html|txt))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // JavaScript image creation
        if (preg_match_all('/new\s+Image\s*\(\s*\)\s*\.src\s*=\s*[\'"]([^\'"]+\.(png|jpg|jpeg|gif|svg|webp))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // JavaScript url properties
        if (preg_match_all('/url\s*:\s*[\'"]([^\'"]+\.(?:php|json|xml|html))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // === JSON/XML PATTERNS ===
        // JSON/XML file references
        if (preg_match_all('/"(?:file|path|src|href|url|image)"\s*:\s*"([^"]+\.(php|html|css|js|png|jpg|jpeg|gif|svg|json|xml))"/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // === GENERIC FILE PATTERNS ===
        // Specific file extensions in strings (quoted) - ALLE wichtigen Typen
        if (preg_match_all('/[\'"]([^\'"\s\/]{1,100}\.(php|html|htm|css|js|png|jpg|jpeg|gif|svg|ico|webp|json|xml|pdf|zip|rar|doc|docx|xls|xlsx|csv|txt|sql|sh|py|pl|rb|java|c|cpp))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // Unquoted file references (in documentation, comments, etc.)
        if (preg_match_all('/\b([a-zA-Z0-9_-]+\.(php|html|htm|css|js|png|jpg|jpeg|gif|svg|ico|webp|json|xml|pdf|txt|sql|sh))\b/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // File paths with directories (api/reservierungen/api/getReservationDetails.php, images/logo.png)
        if (preg_match_all('/\b([a-zA-Z0-9_\/-]+\.(php|html|htm|css|js|png|jpg|jpeg|gif|svg|ico|webp|json|xml|pdf|txt))\b/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $dependencies[] = basename($match); // Extract just filename
                $dependencies[] = $match; // Also keep full path for matching
            }
        }
        
        // === SPECIAL CASES ===
        // Script tags with src (additional pattern)
        if (preg_match_all('/<script[^>]+src\s*=\s*[\'"]([^\'"]+\.js)[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // Link tags (CSS and other resources)
        if (preg_match_all('/<link[^>]+href\s*=\s*[\'"]([^\'"]+\.(css|ico|xml))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // Form actions
        if (preg_match_all('/<form[^>]+action\s*=\s*[\'"]([^\'"]+\.php)[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // iframes
        if (preg_match_all('/<iframe[^>]+src\s*=\s*[\'"]([^\'"]+\.(php|html|htm))[\'"]/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }
        
        // Remove duplicates and process
        $dependencies = array_unique($dependencies);
        
        foreach ($dependencies as $dep) {
            $cleanDep = basename(trim($dep, './'));
            if ($cleanDep && strlen($cleanDep) > 3 && strlen($cleanDep) < 255) { // Reasonable filename length
                if (!isset($this->analysis['code_referenced_files'][$cleanDep])) {
                    $this->analysis['code_referenced_files'][$cleanDep] = [];
                }
                // Count multiple occurrences in same file
                if (basename($relativePath) !== $cleanDep) {
                    $this->analysis['code_referenced_files'][$cleanDep][] = $relativePath;
                }
            }
        }
    }
    
    private function applyWhitelist() {
        foreach ($this->analysis['all_files'] as $relativePath => $fileInfo) {
            $fileName = basename($relativePath);
            
            foreach ($this->whitelist as $pattern) {
                if ($this->matchesPattern($fileName, $pattern) || 
                    $this->matchesPattern($relativePath, $pattern)) {
                    $this->analysis['whitelist_protected'][$relativePath] = $pattern;
                    break;
                }
            }
        }
    }
    
    private function matchesPattern($filename, $pattern) {
        $pattern = str_replace('*', '.*', preg_quote($pattern, '/'));
        return preg_match("/^$pattern$/i", $filename);
    }
    
    private function determineSafeDeletionCandidates() {
        foreach ($this->analysis['all_files'] as $relativePath => $fileInfo) {
            $fileName = basename($relativePath);
            
            if (isset($this->analysis['whitelist_protected'][$relativePath])) continue;
            if (isset($this->analysis['web_accessed_files'][$relativePath]) || 
                isset($this->analysis['web_accessed_files'][$fileName])) continue;
            if (isset($this->analysis['script_accessed_files'][$fileName])) continue;
            if (isset($this->analysis['code_referenced_files'][$fileName])) continue;
            
            $safetyScore = $this->calculateSafetyScore($relativePath, $fileInfo);
            
            if ($safetyScore >= 80) {
                $this->analysis['potentially_safe_to_delete'][$relativePath] = [
                    'safety_score' => $safetyScore,
                    'reasons' => $this->getSafetyReasons($relativePath, $fileInfo),
                    'file_info' => $fileInfo
                ];
            }
        }
    }
    
    private function calculateSafetyScore($relativePath, $fileInfo) {
        $score = 0;
        
        $daysSinceModified = (time() - $fileInfo['modified']) / 86400;
        if ($daysSinceModified > 365) {
            $score += 30;
        } elseif ($daysSinceModified > 180) {
            $score += 20;
        }
        
        if ($fileInfo['size'] == 0) {
            $score += 40;
        } elseif ($fileInfo['size'] < 1024) {
            $score += 10;
        }
        
        $lowRiskExtensions = ['txt', 'bak', 'backup', 'old', 'tmp', 'temp'];
        if (in_array($fileInfo['extension'], $lowRiskExtensions)) {
            $score += 30;
        }
        
        if (preg_match('/(backup|old|temp|test|debug|copy)/i', basename($relativePath))) {
            $score += 20;
        }
        
        return min(100, $score);
    }
    
    private function getSafetyReasons($relativePath, $fileInfo) {
        $reasons = [];
        
        $daysSinceModified = (time() - $fileInfo['modified']) / 86400;
        if ($daysSinceModified > 365) {
            $reasons[] = "Nicht modifiziert seit " . round($daysSinceModified) . " Tagen";
        }
        
        if ($fileInfo['size'] == 0) {
            $reasons[] = "Leere Datei (0 Bytes)";
        }
        
        $reasons[] = "Keine Web-Zugriffe in Analysezeitraum";
        $reasons[] = "Keine Script-Nutzung erkannt";
        $reasons[] = "Keine Code-Referenzen gefunden";
        
        return $reasons;
    }
    
    private function generateSecurityWarnings() {
        $expectedLogs = ['apache_access', 'apache_error'];
        $missingLogs = array_diff($expectedLogs, array_keys($this->logSources));
        
        if (!empty($missingLogs)) {
            $this->analysis['critical_warnings'][] = [
                'type' => 'missing_logs',
                'severity' => 'MEDIUM',
                'message' => "Log-Quellen nicht lesbar: " . implode(', ', $missingLogs),
                'recommendation' => "Prüfen Sie Dateiberechtigungen für vollständige Analyse"
            ];
        }
        
        if ($this->analysis['scan_period_days'] < 180) {
            $this->analysis['critical_warnings'][] = [
                'type' => 'short_analysis_period',
                'severity' => 'MEDIUM',
                'message' => "Analysezeitraum nur " . $this->analysis['scan_period_days'] . " Tage",
                'recommendation' => "Empfohlen: Mindestens 6-12 Monate für sichere Analyse"
            ];
        }
        
        if (count($this->analysis['potentially_safe_to_delete']) > 50) {
            $this->analysis['critical_warnings'][] = [
                'type' => 'many_candidates',
                'severity' => 'MEDIUM',
                'message' => count($this->analysis['potentially_safe_to_delete']) . " Lösch-Kandidaten gefunden",
            ];
        }
    }
    
    public function getComprehensiveFileList($days = 30) {
        $this->performUltraSecureAnalysis($days);
        
        $comprehensiveList = [];
        
        foreach ($this->analysis['all_files'] as $relativePath => $fileInfo) {
            $fileName = basename($relativePath);
            
            // Basic file info
            $item = [
                'file_name' => $fileName,
                'relative_path' => $relativePath,
                'file_size' => $fileInfo['size'],
                'file_size_human' => $this->formatBytes($fileInfo['size']),
                'modified_timestamp' => $fileInfo['modified'],
                'modified_date' => date('Y-m-d H:i:s', $fileInfo['modified']),
                'modified_days_ago' => round((time() - $fileInfo['modified']) / 86400),
                'file_extension' => $fileInfo['extension'],
                
                // Access information
                'web_accessed' => isset($this->analysis['web_accessed_files'][$relativePath]),
                'web_access_count' => 0,
                'web_last_access' => null,
                'web_unique_ips' => 0,
                
                // Code references
                'code_referenced' => isset($this->analysis['code_referenced_files'][$fileName]),
                'referenced_by_count' => 0,
                'referenced_by_files' => [],
                
                // Security status
                'whitelist_protected' => isset($this->analysis['whitelist_protected'][$relativePath]),
                'whitelist_pattern' => $this->analysis['whitelist_protected'][$relativePath] ?? null,
                'safety_score' => 0,
                'deletion_recommended' => false,
                'deletion_reasons' => []
            ];
            
            // Web access details
            if (isset($this->analysis['web_accessed_files'][$relativePath])) {
                $webData = $this->analysis['web_accessed_files'][$relativePath];
                $item['web_access_count'] = $webData['access_count'] ?? 0;
                $item['web_last_access'] = $webData['last_seen'] ?? null;
                $item['web_unique_ips'] = count($webData['unique_ips'] ?? []);
            } else {
                // Try alternative path matching for web access
                foreach ($this->analysis['web_accessed_files'] as $webPath => $webData) {
                    if (basename($webPath) === $fileName || 
                        str_contains($webPath, $fileName) || 
                        (isset($item['file_path']) && str_contains($item['file_path'], basename($webPath)))) {
                        $item['web_access_count'] = $webData['access_count'] ?? 0;
                        $item['web_last_access'] = $webData['last_seen'] ?? null;
                        $item['web_unique_ips'] = count($webData['unique_ips'] ?? []);
                        $item['web_accessed'] = true;
                        break;
                    }
                }
            }
            
            // Code reference details
            if (isset($this->analysis['code_referenced_files'][$fileName])) {
                $refs = array_unique($this->analysis['code_referenced_files'][$fileName]);
                $item['referenced_by_count'] = count($refs);
                $item['referenced_by_files'] = $refs;
            }
            
            // Safety assessment
            if (isset($this->analysis['potentially_safe_to_delete'][$relativePath])) {
                $safetyData = $this->analysis['potentially_safe_to_delete'][$relativePath];
                $item['safety_score'] = $safetyData['safety_score'];
                $item['deletion_recommended'] = $safetyData['safety_score'] >= 70;
                $item['deletion_reasons'] = $safetyData['reasons'];
            }
            
            // === INTELLIGENTE LÖSCH-SICHERHEIT ===
            $item['smart_deletion_safety'] = $this->calculateSmartDeletionSafety($item, $relativePath);
            
            $comprehensiveList[] = $item;
        }
        
        return $comprehensiveList;
    }
    
    private function calculateSmartDeletionSafety($fileData, $relativePath) {
        $safety = [
            'score' => 0,
            'level' => 'UNKNOWN',
            'reasons' => [],
            'recommendation' => 'KEEP'
        ];
        
        $score = 0;
        $reasons = [];
        
        // === KRITISCHE SICHERHEITSFAKTOREN (Negative Punkte) ===
        
        // Web-Zugriffe in letzter Zeit (-50 bis 0 Punkte)
        if ($fileData['web_accessed'] && $fileData['web_access_count'] > 0) {
            if ($fileData['web_access_count'] > 100) {
                $score -= 50;
                $reasons[] = "Sehr häufig verwendet ({$fileData['web_access_count']} Zugriffe)";
            } elseif ($fileData['web_access_count'] > 10) {
                $score -= 30;
                $reasons[] = "Regelmäßig verwendet ({$fileData['web_access_count']} Zugriffe)";
            } else {
                $score -= 10;
                $reasons[] = "Wenig verwendet ({$fileData['web_access_count']} Zugriffe)";
            }
        }
        
        // Code-Referenzen (-40 bis 0 Punkte)
        if ($fileData['code_referenced'] && $fileData['referenced_by_count'] > 0) {
            if ($fileData['referenced_by_count'] > 5) {
                $score -= 40;
                $reasons[] = "Stark referenziert ({$fileData['referenced_by_count']} Refs)";
            } elseif ($fileData['referenced_by_count'] > 1) {
                $score -= 25;
                $reasons[] = "Mehrfach referenziert ({$fileData['referenced_by_count']} Refs)";
            } else {
                $score -= 10;
                $reasons[] = "Einmal referenziert";
            }
        }
        
        // Whitelist-Schutz (-100 Punkte)
        if ($fileData['whitelist_protected']) {
            $score -= 100;
            $reasons[] = "Whitelist-geschützt";
        }
        
        // === POSITIVE SICHERHEITSFAKTOREN ===
        
        // Dateialter (+0 bis +50 Punkte)
        $daysOld = $fileData['modified_days_ago'] ?? 0;
        if ($daysOld > 365) {
            $score += 50;
            $reasons[] = "Sehr alt ({$daysOld} Tage)";
        } elseif ($daysOld > 180) {
            $score += 30;
            $reasons[] = "Alt ({$daysOld} Tage)";
        } elseif ($daysOld > 30) {
            $score += 15;
            $reasons[] = "Älter ({$daysOld} Tage)";
        }
        
        // Dateigröße - große Dateien sind verdächtiger (+0 bis +20 Punkte)
        $sizeBytes = $fileData['file_size'] ?? 0;
        if ($sizeBytes > 10485760) { // > 10MB
            $score += 20;
            $reasons[] = "Große Datei (" . $this->formatBytes($sizeBytes) . ")";
        } elseif ($sizeBytes > 1048576) { // > 1MB
            $score += 10;
            $reasons[] = "Mittlere Datei (" . $this->formatBytes($sizeBytes) . ")";
        } elseif ($sizeBytes === 0) {
            $score += 15;
            $reasons[] = "Leere Datei";
        }
        
        // Dateityp-spezifische Bewertung
        $extension = $fileData['file_extension'] ?? '';
        $fileName = $fileData['file_name'] ?? '';
        
        // Temporäre/Cache-Dateien (+30 Punkte)
        if (preg_match('/\.(tmp|cache|bak|backup|old|~)$/i', $fileName) || 
            strpos($fileName, 'temp') !== false || 
            strpos($fileName, 'cache') !== false) {
            $score += 30;
            $reasons[] = "Temporäre/Cache-Datei";
        }
        
        // Log-Dateien älter als 90 Tage (+25 Punkte)
        if (preg_match('/\.(log|trace|debug)$/i', $extension) && $daysOld > 90) {
            $score += 25;
            $reasons[] = "Alte Log-Datei";
        }
        
        // Dokumentation/Tests ohne Referenzen (+20 Punkte)
        if (preg_match('/\.(md|txt|doc|pdf)$/i', $extension) && !$fileData['code_referenced']) {
            $score += 20;
            $reasons[] = "Unreferenzierte Dokumentation";
        }
        
        // Duplicate-ähnliche Namen (+15 Punkte)
        if (preg_match('/(copy|duplicate|kopie|\(\d+\)|_\d+$)/i', $fileName)) {
            $score += 15;
            $reasons[] = "Duplicate-verdächtiger Name";
        }
        
        // === BERECHNUNG DER FINALEN BEWERTUNG ===
        
        // Normalisierung auf 0-100 Skala
        $finalScore = max(0, min(100, $score + 50));
        
        // Sicherheitslevel bestimmen
        if ($finalScore >= 85) {
            $level = 'SEHR_SICHER';
            $recommendation = 'DELETE';
        } elseif ($finalScore >= 70) {
            $level = 'SICHER';
            $recommendation = 'CONSIDER';
        } elseif ($finalScore >= 50) {
            $level = 'UNSICHER';
            $recommendation = 'KEEP';
        } elseif ($finalScore >= 30) {
            $level = 'RISKANT';
            $recommendation = 'KEEP';
        } else {
            $level = 'KRITISCH';
            $recommendation = 'NEVER_DELETE';
        }
        
        $safety['score'] = $finalScore;
        $safety['level'] = $level;
        $safety['reasons'] = $reasons;
        $safety['recommendation'] = $recommendation;
        
        return $safety;
    }
    
    private function formatBytes($bytes) {
        if ($bytes === 0) return '0 B';
        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
    
    private function isLocalTestMode() {
        $localIps = ['127.0.0.1', '::1', 'localhost', '192.168.15.14'];
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';
        
        return in_array($remoteAddr, $localIps) || 
               in_array($httpHost, ['localhost', 'localhost:8080', '192.168.15.14:8080']) ||
               strpos($httpHost, '192.168.') === 0;
    }
}

// Quick Access Analyzer (von access-analyzer-pro.php)
class WCIAccessAnalyzerPro {
    private $logFile = '/home/vadmin/lemp/logs/apache2/access.log';
    private $wciPath = '/wci/';
    private $wciDirectory;
    
    public function __construct() {
        $this->wciDirectory = dirname(__FILE__);
    }
    
    public function analyzeAccess($days = 30) {
        if (!file_exists($this->logFile)) {
            return ['error' => 'Log-Datei nicht gefunden'];
        }
        
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoffDate = date('Y-m-d', strtotime("-$days days"));
        
        $stats = [
            'total_requests' => 0,
            'unique_ips' => [],
            'files' => [],
            'file_types' => [],
            'hourly_activity' => array_fill(0, 24, 0),
            'daily_activity' => [],
            'response_codes' => [],
            'user_agents' => [],
            'duplicates' => [],
            'rapid_repeats' => [],
            // Erweiterte Felder für Kompatibilität mit Security Mode
            'all_files' => [],
            'web_accessed_files' => [],
            'script_accessed_files' => [],
            'code_referenced_files' => [],
            'whitelist_protected' => [],
            'potentially_safe_to_delete' => []
        ];
        
        $lastRequests = [];
        
        foreach ($lines as $line) {
            if (!preg_match('/^(\S+) - - \[([^\]]+)\] "(\S+) (\S+) ([^"]+)" (\d+) (\d+) "([^"]*)" "([^"]*)"/', $line, $matches)) {
                continue;
            }
            
            $ip = $matches[1];
            $datetime = $matches[2];
            $method = $matches[3];
            $url = $matches[4];
            $status = intval($matches[6]);
            $size = intval($matches[7]);
            $userAgent = $matches[9];
            
            if (strpos($url, $this->wciPath) !== 0) continue;
            
            $requestDate = DateTime::createFromFormat('d/M/Y:H:i:s O', $datetime);
            if (!$requestDate || $requestDate->format('Y-m-d') < $cutoffDate) continue;
            
            $stats['total_requests']++;
            
            $cleanUrl = strtok($url, '?');
            $fileName = basename($cleanUrl);
            $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
            
            if (!isset($stats['unique_ips'][$ip])) {
                $stats['unique_ips'][$ip] = 0;
            }
            $stats['unique_ips'][$ip]++;
            
            if (!isset($stats['files'][$cleanUrl])) {
                $stats['files'][$cleanUrl] = [
                    'count' => 0,
                    'ips' => [],
                    'methods' => [],
                    'status_codes' => [],
                    'first_seen' => $datetime,
                    'last_seen' => $datetime,
                    'total_size' => 0
                ];
            }
            
            $stats['files'][$cleanUrl]['count']++;
            $stats['files'][$cleanUrl]['ips'][$ip] = true;
            $stats['files'][$cleanUrl]['methods'][$method] = ($stats['files'][$cleanUrl]['methods'][$method] ?? 0) + 1;
            $stats['files'][$cleanUrl]['status_codes'][$status] = ($stats['files'][$cleanUrl]['status_codes'][$status] ?? 0) + 1;
            $stats['files'][$cleanUrl]['last_seen'] = $datetime;
            $stats['files'][$cleanUrl]['total_size'] += $size;
            
            if ($fileExt) {
                if (!isset($stats['file_types'][$fileExt])) {
                    $stats['file_types'][$fileExt] = 0;
                }
                $stats['file_types'][$fileExt]++;
            }
            
            $hour = intval($requestDate->format('H'));
            $stats['hourly_activity'][$hour]++;
            
            $day = $requestDate->format('Y-m-d');
            if (!isset($stats['daily_activity'][$day])) {
                $stats['daily_activity'][$day] = 0;
            }
            $stats['daily_activity'][$day]++;
            
            if (!isset($stats['response_codes'][$status])) {
                $stats['response_codes'][$status] = 0;
            }
            $stats['response_codes'][$status]++;
            
            if ($userAgent) {
                $agentKey = substr($userAgent, 0, 100);
                if (!isset($stats['user_agents'][$agentKey])) {
                    $stats['user_agents'][$agentKey] = 0;
                }
                $stats['user_agents'][$agentKey]++;
            }
            
            // Duplicate Detection
            $requestKey = $ip . '|' . $cleanUrl;
            $currentTime = $requestDate->getTimestamp();
            
            if (isset($lastRequests[$requestKey])) {
                $timeDiff = $currentTime - $lastRequests[$requestKey]['time'];
                if ($timeDiff < 60) {
                    if (!isset($stats['rapid_repeats'][$requestKey])) {
                        $stats['rapid_repeats'][$requestKey] = [
                            'ip' => $ip,
                            'url' => $cleanUrl,
                            'count' => 0,
                            'last_seen' => $datetime
                        ];
                    }
                    $stats['rapid_repeats'][$requestKey]['count']++;
                    $stats['rapid_repeats'][$requestKey]['last_seen'] = $datetime;
                }
            }
            
            $lastRequests[$requestKey] = [
                'time' => $currentTime,
                'datetime' => $datetime
            ];
        }
        
        // Erweiterte Analyse: Alle Dateien scannen und Code-Dependencies finden
        $this->scanAllFiles($stats);
        $this->scanCodeDependencies($stats);
        
        return $stats;
    }
    
    private function scanAllFiles(&$stats) {
        // Einfache Implementierung: nur PHP-Dateien im Hauptverzeichnis
        $phpFiles = glob($this->wciDirectory . '/*.php');
        $htmlFiles = glob($this->wciDirectory . '/*.{html,htm}', GLOB_BRACE);
        $allFiles = array_merge($phpFiles, $htmlFiles);
        
        foreach ($allFiles as $file) {
            $fileName = basename($file);
            $relativePath = $fileName;
            
            $stats['all_files'][$relativePath] = [
                'full_path' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file),
                'extension' => strtolower(pathinfo($file, PATHINFO_EXTENSION))
            ];
            
            // Markiere web-zugegriffene Dateien
            foreach ($stats['files'] as $webFile => $fileData) {
                $webFileName = basename($webFile);
                if ($webFileName === $fileName || $webFile === '/' . $relativePath) {
                    $stats['web_accessed_files'][$relativePath] = $fileData;
                    break;
                }
            }
        }
    }
    
    private function scanCodeDependencies(&$stats) {
        foreach ($stats['all_files'] as $relativePath => $fileInfo) {
            if (!in_array($fileInfo['extension'], ['php', 'html', 'htm', 'js', 'css'])) {
                continue;
            }
            
            $content = file_get_contents($fileInfo['full_path']);
            if (!$content) continue;
            
            $dependencies = [];
            
            // PHP includes/requires
            if (preg_match_all('/(?:include|require)(?:_once)?\s*[\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
                $dependencies = array_merge($dependencies, $matches[1]);
            }
            
            // HTML/CSS/JS src/href Referenzen
            if (preg_match_all('/(?:src|href)\s*=\s*[\'"]([^\'"]+\.(css|js|php|html|htm|png|jpg|gif))[\'"]/', $content, $matches)) {
                $dependencies = array_merge($dependencies, $matches[1]);
            }
            
            // AJAX URL Referenzen
            if (preg_match_all('/url\s*:\s*[\'"]([^\'"]+\.php)[\'"]/', $content, $matches)) {
                $dependencies = array_merge($dependencies, $matches[1]);
            }
            
            foreach ($dependencies as $dep) {
                $cleanDep = basename(trim($dep, './'));
                if ($cleanDep) {
                    if (!isset($stats['code_referenced_files'][$cleanDep])) {
                        $stats['code_referenced_files'][$cleanDep] = [];
                    }
                    $stats['code_referenced_files'][$cleanDep][] = $relativePath;
                }
            }
        }
    }
}

// Rest of the HTML page (no duplicate AJAX handler needed)

function formatBytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while ($size > 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, 2) . ' ' . $units[$unit];
}

function timeAgo($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 3600) return round($diff/60) . 'm';
    if ($diff < 86400) return round($diff/3600) . 'h';
    return round($diff/86400) . 'd';
}

// Web Interface (HTML) - only in web mode
if (!$isCLI) {
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 Enhanced WCI Access & Security Analyzer</title>
    <link rel="stylesheet" href="include/style.css">
    <style>
        .enhanced-container {
            max-width: 1800px;
            margin: 20px auto;
            padding: 20px;
        }
        
        .main-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        
        .main-header h1 {
            margin: 0;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .mode-selector {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .mode-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .mode-tab {
            padding: 15px 20px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
            background: white;
        }
        
        .mode-tab.active {
            border-color: #007bff;
            background: #f8f9ff;
            color: #007bff;
            font-weight: bold;
        }
        
        .mode-tab:hover {
            border-color: #007bff;
            background: #f8f9ff;
        }
        
        .analysis-controls {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 20px;
            align-items: center;
        }
        
        .period-selector select {
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 1em;
            background: white;
        }
        
        .analyze-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            font-size: 1em;
            transition: all 0.3s ease;
        }
        
        .analyze-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
        }
        
        .loading {
            text-align: center;
            padding: 50px;
            color: #6c757d;
            display: none;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .results-container {
            display: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 4px solid #17a2b8;
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9em;
            font-weight: 500;
        }
        
        .analysis-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .back-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            z-index: 1000;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .warning-item {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 10px 0;
            border-left: 4px solid #ffc107;
        }
        
        .severity-high { border-left-color: #dc3545; }
        .severity-medium { border-left-color: #ffc107; }
        
        .safety-score {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            color: white;
        }
        
        .score-high { background: #28a745; }
        .score-medium { background: #ffc107; color: #212529; }
        .score-low { background: #dc3545; }
    </style>
</head>
<body>
    <a href="index.php" class="back-button">← Zurück</a>
    
    <div class="enhanced-container">
        <div class="main-header">
            <h1>🔍 Enhanced WCI Access & Security Analyzer</h1>
            <p>Kombinierte Quick-Access-Analyse und Ultra-Secure File Safety Features</p>
        </div>
        
        <div class="mode-selector">
            <div class="mode-tabs">
                <div class="mode-tab active" data-mode="quick">
                    <h3>🚀 Quick Mode</h3>
                    <p>Schnelle Web-Zugriffs-Analyse</p>
                </div>
                <div class="mode-tab" data-mode="security">
                    <h3>🔒 Security Mode</h3>
                    <p>Ultra-sichere Datei-Sicherheitsanalyse</p>
                </div>
                <div class="mode-tab" data-mode="comprehensive">
                    <h3>📋 Comprehensive Mode</h3>
                    <p>Detaillierte Dateiliste mit allen Attributen</p>
                </div>
            </div>
            
            <div class="analysis-controls">
                <label for="analysis-period"><strong>Analysezeitraum:</strong></label>
                <div class="period-selector">
                    <select id="analysis-period">
                        <option value="7">7 Tage</option>
                        <option value="30" selected>30 Tage</option>
                        <option value="90">90 Tage</option>
                        <option value="180">180 Tage</option>
                        <option value="365">365 Tage</option>
                    </select>
                </div>
                <button class="analyze-btn" onclick="startAnalysis()">🔍 Analyse starten</button>
            </div>
        </div>
        
        <div id="loading" class="loading">
            <div class="spinner"></div>
            <p>Führe Analyse durch...<br>
            <small id="loading-text">Analysiere Zugriffslogs...</small></p>
        </div>
        
        <div id="results" class="results-container">
            <!-- Results will be dynamically inserted here -->
        </div>
    </div>
    
    <script>
        let currentMode = 'quick';
        
        // Mode Tab Switching
        document.querySelectorAll('.mode-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentMode = tab.dataset.mode;
                
                // Update loading text based on mode
                const loadingText = document.getElementById('loading-text');
                if (currentMode === 'security') {
                    loadingText.textContent = 'Ultra-Sichere Dateianalyse läuft...';
                } else {
                    loadingText.textContent = 'Analysiere Zugriffslogs...';
                }
            });
        });
        
        function startAnalysis() {
            const days = document.getElementById('analysis-period').value;
            const loading = document.getElementById('loading');
            const results = document.getElementById('results');
            const button = document.querySelector('.analyze-btn');
            
            console.log(`Starte ${currentMode} Analyse mit ${days} Tagen`);
            
            // UI Updates
            button.disabled = true;
            button.innerHTML = '⏳ Analysiere...';
            loading.style.display = 'block';
            results.style.display = 'none';
            
            loading.scrollIntoView({ behavior: 'smooth' });
            
            // Determine endpoint based on mode
            let endpoint;
            if (currentMode === 'comprehensive') {
                endpoint = `?action=comprehensive&days=${days}&ajax=1`;
            } else {
                endpoint = `?action=analyze&mode=${currentMode}&days=${days}&ajax=1`;
            }
            
            // AJAX Request
            const startTime = Date.now();
            fetch(endpoint)
                .then(response => {
                    console.log('Response Status:', response.status);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    const duration = ((Date.now() - startTime) / 1000).toFixed(1);
                    console.log(`Analyse abgeschlossen (${duration}s):`, data);
                    
                    if (data.success) {
                        button.innerHTML = '✅ Analyse abgeschlossen!';
                        
                        setTimeout(() => {
                            displayResults(data);
                            
                            // Reset UI
                            button.disabled = false;
                            button.innerHTML = '🔍 Analyse starten';
                            loading.style.display = 'none';
                            results.style.display = 'block';
                            results.scrollIntoView({ behavior: 'smooth' });
                        }, 1000);
                    } else {
                        throw new Error(data.error || 'Unbekannter Server-Fehler');
                    }
                })
                .catch(error => {
                    console.error('Analyse-Fehler:', error);
                    alert('Fehler bei der Analyse: ' + error.message);
                    
                    // Reset UI
                    button.disabled = false;
                    button.innerHTML = '🔍 Analyse starten';
                    loading.style.display = 'none';
                });
        }
        
        function displayResults(data) {
            const results = document.getElementById('results');
            
            if (data.mode === 'security') {
                results.innerHTML = generateSecurityResults(data.analysis);
            } else if (data.mode === 'comprehensive') {
                results.innerHTML = generateComprehensiveResults(data.files);
            } else {
                results.innerHTML = generateQuickResults(data.analysis);
            }
        }
        
        function generateQuickResults(analysis) {
            const uniqueIpsCount = Object.keys(analysis.unique_ips || {}).length;
            const filesCount = Object.keys(analysis.files || {}).length;
            const fileTypesCount = Object.keys(analysis.file_types || {}).length;
            const rapidRepeatsCount = Object.keys(analysis.rapid_repeats || {}).length;
            const totalRequests = analysis.total_requests || 0;
            
            return `
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">${totalRequests.toLocaleString()}</div>
                        <div class="stat-label">Gesamte Requests</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${uniqueIpsCount}</div>
                        <div class="stat-label">Unique IPs</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${filesCount}</div>
                        <div class="stat-label">Zugegriffene Dateien</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${fileTypesCount}</div>
                        <div class="stat-label">Dateitypen</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${rapidRepeatsCount}</div>
                        <div class="stat-label">Rapid Repeats</div>
                    </div>
                </div>
                
                <div class="analysis-section">
                    <h3>📊 Meistbesuchte Dateien</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Datei</th>
                                <th>Zugriffe</th>
                                <th>Unique IPs</th>
                                <th>Letzte Zugriff</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${Object.entries(analysis.files || {})
                                .sort((a, b) => b[1].count - a[1].count)
                                .slice(0, 20)
                                .map(([file, data]) => `
                                    <tr>
                                        <td><strong>${file}</strong></td>
                                        <td>${data.count.toLocaleString()}</td>
                                        <td>${Object.keys(data.ips).length}</td>
                                        <td>${data.last_seen}</td>
                                    </tr>
                                `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        function generateSecurityResults(analysis) {
            const allFilesCount = Object.keys(analysis.all_files || {}).length;
            const webAccessedCount = Object.keys(analysis.web_accessed_files || {}).length;
            const scriptAccessedCount = Object.keys(analysis.script_accessed_files || {}).length;
            const codeReferencedCount = Object.keys(analysis.code_referenced_files || {}).length;
            const whitelistProtectedCount = Object.keys(analysis.whitelist_protected || {}).length;
            const potentiallySafeCount = Object.keys(analysis.potentially_safe_to_delete || {}).length;
            
            let warningsHtml = '';
            if (analysis.critical_warnings && analysis.critical_warnings.length > 0) {
                warningsHtml = `
                    <div class="analysis-section">
                        <h3>⚠️ Sicherheitswarnungen</h3>
                        ${analysis.critical_warnings.map(warning => `
                            <div class="warning-item severity-${warning.severity.toLowerCase()}">
                                <strong>[${warning.severity}]</strong> ${warning.message}
                                <div style="margin-top: 5px; font-size: 0.9em;">
                                    <strong>Empfehlung:</strong> ${warning.recommendation}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            let candidatesHtml = '';
            if (analysis.potentially_safe_to_delete && Object.keys(analysis.potentially_safe_to_delete).length > 0) {
                candidatesHtml = `
                    <div class="analysis-section">
                        <h3>🗑️ Sichere Lösch-Kandidaten</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Datei</th>
                                    <th>Sicherheitsscore</th>
                                    <th>Größe</th>
                                    <th>Gründe</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${Object.entries(analysis.potentially_safe_to_delete)
                                    .sort((a, b) => b[1].safety_score - a[1].safety_score)
                                    .slice(0, 15)
                                    .map(([file, info]) => {
                                        const scoreClass = info.safety_score >= 90 ? 'score-high' : 
                                                         info.safety_score >= 70 ? 'score-medium' : 'score-low';
                                        return `
                                            <tr>
                                                <td><strong>${file}</strong></td>
                                                <td><span class="safety-score ${scoreClass}">${info.safety_score}%</span></td>
                                                <td>${formatBytes(info.file_info.size)}</td>
                                                <td>${info.reasons.slice(0, 2).join(', ')}</td>
                                            </tr>
                                        `;
                                    }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                candidatesHtml = `
                    <div class="analysis-section" style="text-align: center; padding: 40px; color: #28a745;">
                        ✅ <strong>Keine löschbaren Dateien gefunden!</strong><br>
                        Alle Dateien sind in Verwendung oder durch Sicherheitsregeln geschützt.
                    </div>
                `;
            }
            
            return `
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">${allFilesCount.toLocaleString()}</div>
                        <div class="stat-label">Gesamte Dateien</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${webAccessedCount.toLocaleString()}</div>
                        <div class="stat-label">Web-zugegriffen</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${scriptAccessedCount.toLocaleString()}</div>
                        <div class="stat-label">Script-zugegriffen</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${codeReferencedCount.toLocaleString()}</div>
                        <div class="stat-label">Code-referenziert</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${whitelistProtectedCount.toLocaleString()}</div>
                        <div class="stat-label">Whitelist-geschützt</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #28a745;">${potentiallySafeCount.toLocaleString()}</div>
                        <div class="stat-label">Potentiell löschbar</div>
                    </div>
                </div>
                
            `;
        }
        
        function generateComprehensiveResults(files) {
            // Store files globally for filtering and sorting
            currentFiles = files;
            
            const totalFiles = files.length;
            const webAccessedFiles = files.filter(f => f.web_accessed).length;
            const codeReferencedFiles = files.filter(f => f.code_referenced).length;
            const whitelistProtectedFiles = files.filter(f => f.whitelist_protected).length;
            const deletionCandidates = files.filter(f => f.deletion_recommended).length;
            
            // Sort files by different criteria for tabs
            const bySize = [...files].sort((a, b) => b.file_size - a.file_size);
            const byAge = [...files].sort((a, b) => b.modified_days_ago - a.modified_days_ago);
            const byAccess = [...files].filter(f => f.web_accessed).sort((a, b) => b.web_access_count - a.web_access_count);
            const unreferenced = files.filter(f => !f.web_accessed && !f.code_referenced && !f.whitelist_protected);
            
            return `
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">${totalFiles.toLocaleString()}</div>
                        <div class="stat-label">Gesamte Dateien</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${webAccessedFiles.toLocaleString()}</div>
                        <div class="stat-label">Web-zugegriffen</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${codeReferencedFiles.toLocaleString()}</div>
                        <div class="stat-label">Code-referenziert</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${whitelistProtectedFiles.toLocaleString()}</div>
                        <div class="stat-label">Whitelist-geschützt</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #dc3545;">${unreferenced.length.toLocaleString()}</div>
                        <div class="stat-label">Nicht referenziert</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #ffc107;">${deletionCandidates.toLocaleString()}</div>
                        <div class="stat-label">Lösch-Kandidaten</div>
                    </div>
                </div>
                
                <div class="analysis-section">
                    <h3>📋 Umfassende Dateiliste</h3>
                    
                    <!-- Enhanced Filter Controls -->
                    <div style="margin-bottom: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <strong>Quick Filters:</strong><br>
                            <button onclick="showFileList('all')" class="filter-btn active" id="btn-all">Alle</button>
                            <button onclick="showFileList('large')" class="filter-btn" id="btn-large">Große Dateien</button>
                            <button onclick="showFileList('old')" class="filter-btn" id="btn-old">Alte Dateien</button>
                            <button onclick="showFileList('accessed')" class="filter-btn" id="btn-accessed">Web-Zugriffe</button>
                            <button onclick="showFileList('unreferenced')" class="filter-btn" id="btn-unreferenced">Ungenutzt</button>
                        </div>
                        <div>
                            <strong>Range Filters:</strong><br>
                            <label>Größe: <input type="number" id="filter-size-min" placeholder="Min KB" style="width:80px"> - <input type="number" id="filter-size-max" placeholder="Max KB" style="width:80px"></label><br>
                            <label>Alter: <input type="number" id="filter-age-min" placeholder="Min Tage" style="width:80px"> - <input type="number" id="filter-age-max" placeholder="Max Tage" style="width:80px"></label>
                            <button onclick="applyRangeFilter()" style="margin-left:10px;">Filter</button>
                        </div>
                    </div>
                    
                    <div id="file-list-container">
                        ${generateFileTable(files, 'all')}
                    </div>
                </div>
                
                <style>
                .filter-btn {
                    background: #e9ecef;
                    border: 1px solid #dee2e6;
                    padding: 8px 16px;
                    margin: 0 5px 5px 0;
                    border-radius: 4px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                }
                
                .filter-btn.active, .filter-btn:hover {
                    background: #007bff;
                    color: white;
                    border-color: #007bff;
                }
                
                .status-badge {
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 0.8em;
                    font-weight: bold;
                }
                
                .status-web { background: #d4edda; color: #155724; }
                .status-code { background: #cce7ff; color: #004085; }
                .status-whitelist { background: #fff3cd; color: #856404; }
                .status-delete { background: #f8d7da; color: #721c24; }
                .status-safe { background: #d1ecf1; color: #0c5460; }
                
                .sort-indicator {
                    color: #007bff;
                    font-weight: bold;
                    margin-left: 5px;
                }
                
                th[onclick] {
                    user-select: none;
                    transition: background-color 0.2s ease;
                }
                
                th[onclick]:hover {
                    background-color: #e9ecef !important;
                }
                </style>
            `;
        }
        
        function generateFileTable(files, filter) {
            let filteredFiles;
            
            switch(filter) {
                case 'large':
                    filteredFiles = [...files].sort((a, b) => (b.file_size || 0) - (a.file_size || 0)).slice(0, 50);
                    break;
                case 'old':
                    filteredFiles = [...files].sort((a, b) => (b.modified_days_ago || 0) - (a.modified_days_ago || 0)).slice(0, 50);
                    break;
                case 'accessed':
                    filteredFiles = files.filter(f => f.web_accessed && f.web_access_count > 0).sort((a, b) => (b.web_access_count || 0) - (a.web_access_count || 0)).slice(0, 50);
                    break;
                case 'unreferenced':
                    filteredFiles = files.filter(f => !f.web_accessed && !f.code_referenced && !f.whitelist_protected);
                    break;
                default:
                    filteredFiles = files;
            }
            
            // Apply current sorting
            if (currentSortField && currentSortDirection) {
                filteredFiles.sort((a, b) => {
                    let valueA, valueB;
                    
                    // Handle nested properties (e.g., 'smart_deletion_safety.score')
                    if (currentSortField.includes('.')) {
                        const parts = currentSortField.split('.');
                        valueA = a[parts[0]] ? a[parts[0]][parts[1]] : null;
                        valueB = b[parts[0]] ? b[parts[0]][parts[1]] : null;
                    } else {
                        valueA = a[currentSortField];
                        valueB = b[currentSortField];
                    }
                    
                    // Handle null/undefined values
                    if (valueA === null || valueA === undefined) valueA = '';
                    if (valueB === null || valueB === undefined) valueB = '';
                    
                    // === SPECIAL HANDLING FOR DATE FIELDS ===
                    if (currentSortField === 'web_last_access' || currentSortField === 'last_modified' || currentSortField === 'modified_date') {
                        // Parse Apache log date format and other date formats
                        const parseDate = (dateStr) => {
                            if (!dateStr || dateStr === '0' || dateStr === '-' || dateStr === 'invalid date') {
                                return new Date(0); // Very old date for null values
                            }
                            
                            // Apache format: 19/Aug/2025:17:21:22 +0200
                            if (typeof dateStr === 'string' && dateStr.includes('/') && dateStr.includes(':')) {
                                const parts = dateStr.match(/(\d{2})\/(\w{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2})\s*([\+\-]\d{4})?/);
                                if (parts) {
                                    const months = {
                                        'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
                                        'May': '05', 'Jun': '06', 'Jul': '07', 'Aug': '08',
                                        'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
                                    };
                                    const day = parts[1];
                                    const month = months[parts[2]];
                                    const year = parts[3];
                                    const hour = parts[4];
                                    const minute = parts[5];
                                    const second = parts[6];
                                    
                                    const isoString = `${year}-${month}-${day}T${hour}:${minute}:${second}`;
                                    return new Date(isoString);
                                }
                            }
                            
                            // ISO format or other standard formats
                            return new Date(dateStr);
                        };
                        
                        const dateA = parseDate(valueA);
                        const dateB = parseDate(valueB);
                        
                        // Always use numeric comparison for dates
                        if (currentSortDirection === 'asc') {
                            return dateA.getTime() - dateB.getTime();
                        } else {
                            return dateB.getTime() - dateA.getTime();
                        }
                    }
                    
                    // === NUMERIC FIELDS ===
                    const numericFields = ['file_size', 'modified_days_ago', 'web_access_count', 'web_unique_ips', 'referenced_by_count', 'safety_score', 'smart_deletion_safety.score'];
                    if (numericFields.includes(currentSortField)) {
                        const numA = parseFloat(valueA) || 0;
                        const numB = parseFloat(valueB) || 0;
                        
                        if (currentSortDirection === 'asc') {
                            return numA - numB;
                        } else {
                            return numB - numA;
                        }
                    }
                    
                    // === STRING FIELDS ===
                    // Convert to lowercase for case-insensitive sorting
                    if (typeof valueA === 'string' && typeof valueB === 'string') {
                        valueA = valueA.toLowerCase();
                        valueB = valueB.toLowerCase();
                    } else {
                        // Convert to string if not already
                        valueA = String(valueA).toLowerCase();
                        valueB = String(valueB).toLowerCase();
                    }
                    
                    // String comparison
                    if (currentSortDirection === 'asc') {
                        return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
                    } else {
                        return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
                    }
                });
            }
            
            return `
                <div class="excel-table-container" style="border: 1px solid #d0d7de; border-radius: 6px; background: white; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="max-height: 600px; overflow: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 12px;">
                            <thead style="position: sticky; top: 0; background: #f6f8fa; z-index: 10;">
                                <tr>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; user-select: none;" onclick="sortFiles('file_name')">
                                        📄 Datei <span id="sort-file_name" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: right; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; width: 80px;" onclick="sortFiles('file_size')">
                                        📏 Größe <span id="sort-file_size" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: right; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; width: 60px;" onclick="sortFiles('modified_days_ago')">
                                        ⏰ Alter <span id="sort-modified_days_ago" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: center; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; width: 90px;" onclick="sortFiles('web_last_access')">
                                        🌐 Letzter Web <span id="sort-web_last_access" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: center; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; width: 90px;" onclick="sortFiles('modified_date')">
                                        ✏️ Geändert <span id="sort-modified_date" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: right; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; width: 60px;" onclick="sortFiles('web_access_count')">
                                        📊 Hits <span id="sort-web_access_count" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: right; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; width: 50px;" onclick="sortFiles('referenced_by_count')">
                                        🔗 Refs <span id="sort-referenced_by_count" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: center; border-bottom: 1px solid #d0d7de; border-right: 1px solid #d0d7de; cursor: pointer; width: 80px;" onclick="sortFiles('smart_deletion_safety.score')">
                                        🛡️ Lösch-Sicherheit <span id="sort-smart_deletion_safety.score" class="sort-indicator"></span>
                                    </th>
                                    <th style="padding: 8px 12px; text-align: center; border-bottom: 1px solid #d0d7de; width: 70px;">
                                        🏷️ Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                ${filteredFiles.map((file, index) => {
                                    // Create comprehensive tooltip
                                    const tooltipData = [
                                        `Vollständiger Pfad: ${file.file_path || 'Unbekannt'}`,
                                        `MIME-Type: ${file.file_extension ? file.file_extension.toUpperCase() : 'N/A'}`,
                                        `Erstellt: ${file.modified_date || 'Unbekannt'}`,
                                        `Dateigröße: ${file.file_size_human || 'Unbekannt'}`,
                                        file.web_unique_ips ? `Unique IPs: ${file.web_unique_ips}` : '',
                                        file.safety_score ? `Sicherheitsscore: ${file.safety_score}%` : '',
                                        file.referenced_by_files && Array.isArray(file.referenced_by_files) && file.referenced_by_files.length > 0 
                                            ? `Referenziert von: ${file.referenced_by_files.slice(0, 5).join(', ')}${file.referenced_by_files.length > 5 ? ' (+' + (file.referenced_by_files.length - 5) + ' weitere)' : ''}`
                                            : 'Keine Code-Referenzen gefunden'
                                    ].filter(Boolean).join('\\n');
                                    
                                    // Determine row background
                                    const rowBg = index % 2 === 0 ? '#ffffff' : '#f6f8fa';
                                    
                                    // Status determination
                                    let statusIcon = '✅';
                                    let statusColor = '#28a745';
                                    let statusTitle = 'OK';
                                    
                                    if (file.deletion_recommended) {
                                        statusIcon = '🗑️';
                                        statusColor = '#dc3545';
                                        statusTitle = 'Löschung empfohlen';
                                    } else if (!file.web_accessed && !file.code_referenced && !file.whitelist_protected) {
                                        statusIcon = '💤';
                                        statusColor = '#6c757d';
                                        statusTitle = 'Ungenutzt';
                                    } else if (file.web_access_count > 1000) {
                                        statusIcon = '🔥';
                                        statusColor = '#fd7e14';
                                        statusTitle = 'Häufig aufgerufen';
                                    } else if (file.safety_score > 70) {
                                        statusIcon = '⚠️';
                                        statusColor = '#ffc107';
                                        statusTitle = 'Sicherheitsrisiko';
                                    }
                                    
                                    return `
                                        <tr style="background: ${rowBg}; border-bottom: 1px solid #f1f3f4;" 
                                            onmouseover="this.style.background='#e6f3ff';" 
                                            onmouseout="this.style.background='${rowBg}';"
                                            title="${tooltipData.replace(/"/g, '&quot;')}">
                                            
                                            <td style="padding: 6px 12px; border-right: 1px solid #f1f3f4; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px;">
                                                ${file.file_name || 'Unbekannt'}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: right; border-right: 1px solid #f1f3f4; font-family: monospace; font-size: 11px;">
                                                ${file.file_size_human || '—'}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: right; border-right: 1px solid #f1f3f4; font-family: monospace; font-size: 11px;">
                                                ${file.modified_days_ago ? file.modified_days_ago + 'd' : '—'}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: center; border-right: 1px solid #f1f3f4; font-size: 10px;">
                                                ${file.web_accessed && file.web_last_access ? 
                                                    (() => {
                                                        try {
                                                            // Parse Apache log date format: 19/Aug/2025:17:21:22 +0200
                                                            const apacheDate = file.web_last_access;
                                                            
                                                            // Check if it's already in Apache format
                                                            if (apacheDate.includes('/') && apacheDate.includes(':')) {
                                                                // Convert Apache date format to ISO format
                                                                const parts = apacheDate.match(/(\d{2})\/(\w{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2})\s*([\+\-]\d{4})?/);
                                                                if (parts) {
                                                                    const months = {
                                                                        'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
                                                                        'May': '05', 'Jun': '06', 'Jul': '07', 'Aug': '08',
                                                                        'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
                                                                    };
                                                                    const day = parts[1];
                                                                    const month = months[parts[2]];
                                                                    const year = parts[3];
                                                                    const hour = parts[4];
                                                                    const minute = parts[5];
                                                                    const second = parts[6];
                                                                    
                                                                    const isoString = `${year}-${month}-${day}T${hour}:${minute}:${second}`;
                                                                    const date = new Date(isoString);
                                                                    
                                                                    if (!isNaN(date.getTime())) {
                                                                        return date.toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit'});
                                                                    }
                                                                }
                                                            }
                                                            
                                                            // Fallback: try to parse as-is
                                                            const date = new Date(apacheDate);
                                                            if (!isNaN(date.getTime())) {
                                                                return date.toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit'});
                                                            }
                                                            
                                                            // If all fails, return raw value
                                                            return apacheDate;
                                                        } catch(e) {
                                                            return file.web_last_access;
                                                        }
                                                    })() : 
                                                    '<span style="color: #6c757d;">—</span>'}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: center; border-right: 1px solid #f1f3f4; font-size: 10px;">
                                                ${file.modified_date ? 
                                                    new Date(file.modified_date).toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit'}) : 
                                                    '<span style="color: #6c757d;">—</span>'}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: right; border-right: 1px solid #f1f3f4; font-family: monospace; font-weight: bold;">
                                                ${file.web_access_count > 0 ? 
                                                    '<span style="color: #28a745;">' + (file.web_access_count > 999 ? Math.round(file.web_access_count/1000) + 'k' : file.web_access_count) + '</span>' : 
                                                    '<span style="color: #6c757d;">0</span>'}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: right; border-right: 1px solid #f1f3f4; font-family: monospace; font-weight: bold;">
                                                ${file.referenced_by_count > 0 ? 
                                                    '<span style="color: #007bff;">' + file.referenced_by_count + '</span>' : 
                                                    '<span style="color: #6c757d;">0</span>'}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: center; border-right: 1px solid #f1f3f4; font-size: 11px;">
                                                ${(() => {
                                                    const safety = file.smart_deletion_safety || {};
                                                    const score = safety.score || 0;
                                                    const level = safety.level || 'UNKNOWN';
                                                    const recommendation = safety.recommendation || 'KEEP';
                                                    
                                                    let color, icon, bgColor;
                                                    if (score >= 85) {
                                                        color = '#dc3545'; icon = '🔴'; bgColor = '#fff5f5';
                                                    } else if (score >= 70) {
                                                        color = '#fd7e14'; icon = '🟡'; bgColor = '#fff8f1';
                                                    } else if (score >= 50) {
                                                        color = '#6f42c1'; icon = '🟣'; bgColor = '#f8f7ff';
                                                    } else if (score >= 30) {
                                                        color = '#0d6efd'; icon = '🔵'; bgColor = '#f0f8ff';
                                                    } else {
                                                        color = '#198754'; icon = '🟢'; bgColor = '#f0fff4';
                                                    }
                                                    
                                                    const reasonsText = (safety.reasons || []).join('\\n• ');
                                                    const tooltip = 'Lösch-Sicherheit: ' + score + '%\\nLevel: ' + level + '\\nEmpfehlung: ' + recommendation + '\\n\\nFaktoren:\\n• ' + reasonsText;
                                                    
                                                    return '<div style="background: ' + bgColor + '; padding: 2px 6px; border-radius: 4px; border: 1px solid ' + color + '30; display: inline-block;" title="' + tooltip + '"><span style="color: ' + color + '; font-weight: bold;">' + score + '%</span> ' + icon + '</div>';
                                                })()}
                                            </td>
                                            
                                            <td style="padding: 6px 12px; text-align: center; font-size: 14px;">
                                                <span style="color: ${statusColor};" title="${statusTitle}">${statusIcon}</span>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="padding: 8px 12px; background: #f6f8fa; border-top: 1px solid #d0d7de; font-size: 11px; color: #656d76; display: flex; justify-content: space-between; align-items: center;">
                        <span>📊 ${filteredFiles.length} Dateien</span>
                        <span>💡 Hover für Details • Spalten klicken zum Sortieren</span>
                    </div>
                </div>
            `;
        }
        
        // Global variables for file data and sorting
        let currentFiles = [];
        let currentSortField = 'file_name';
        let currentSortDirection = 'asc';
        
        window.showFileList = function(filter) {
            // Update active filter button
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('btn-' + filter).classList.add('active');
            
            // Filter and display files
            const container = document.getElementById('file-list-container');
            if (container && currentFiles.length > 0) {
                container.innerHTML = generateFileTable(currentFiles, filter);
            }
            console.log('Filter changed to:', filter);
        };
        
        window.applyRangeFilter = function() {
            const sizeMin = parseFloat(document.getElementById('filter-size-min').value) || 0;
            const sizeMax = parseFloat(document.getElementById('filter-size-max').value) || Infinity;
            const ageMin = parseFloat(document.getElementById('filter-age-min').value) || 0;
            const ageMax = parseFloat(document.getElementById('filter-age-max').value) || Infinity;
            
            console.log('Applying range filter:', { sizeMin, sizeMax, ageMin, ageMax });
            
            if (currentFiles.length === 0) return;
            
            // Convert size from KB to bytes for comparison
            const sizeMinBytes = sizeMin * 1024;
            const sizeMaxBytes = sizeMax * 1024;
            
            const filteredFiles = currentFiles.filter(file => {
                const fileSize = file.file_size || 0;
                const fileAge = file.modified_days_ago || 0;
                
                return fileSize >= sizeMinBytes && 
                       fileSize <= sizeMaxBytes && 
                       fileAge >= ageMin && 
                       fileAge <= ageMax;
            });
            
            console.log('Range filter results:', filteredFiles.length, 'of', currentFiles.length, 'files');
            
            // Create custom table for range filtered results
            const container = document.getElementById('file-list-container');
            if (container) {
                container.innerHTML = `
                    <div class="excel-table-container" style="border: 1px solid #d0d7de; border-radius: 6px; background: white; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="padding: 8px 12px; background: #fff3cd; border-bottom: 1px solid #d0d7de; font-size: 12px; color: #856404;">
                            🔍 Range Filter aktiv: Größe ${sizeMin}-${sizeMax === Infinity ? '∞' : sizeMax} KB, Alter ${ageMin}-${ageMax === Infinity ? '∞' : ageMax} Tage
                            <button onclick="clearRangeFilter()" style="margin-left: 15px; padding: 2px 8px; font-size: 11px; background: #ffc107; border: none; border-radius: 3px; cursor: pointer;">✕ Filter löschen</button>
                        </div>
                        ${generateCustomTable(filteredFiles)}
                    </div>
                `;
            }
            
            // Clear active filter buttons since we're in range mode
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
        };
        
        window.clearRangeFilter = function() {
            // Clear input fields
            document.getElementById('filter-size-min').value = '';
            document.getElementById('filter-size-max').value = '';
            document.getElementById('filter-age-min').value = '';
            document.getElementById('filter-age-max').value = '';
            
            // Reset to 'all' filter
            document.getElementById('btn-all').classList.add('active');
            window.showFileList('all');
        };
        
        function generateCustomTable(files) {
            if (files.length === 0) {
                return `
                    <div style="padding: 40px; text-align: center; color: #6c757d;">
                        <h4>Keine Dateien gefunden</h4>
                        <p>Keine Dateien entsprechen den angegebenen Filterkriterien.</p>
                    </div>
                `;
            }
            
            return generateFileTable(files, 'custom').replace('function generateFileTable(files, filter) {', '');
        }
        
        window.sortFiles = function(field) {
            if (currentSortField === field) {
                // Toggle sort direction if same field
                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                // New field, default to ascending
                currentSortField = field;
                currentSortDirection = 'asc';
            }
            
            // Update sort indicators
            document.querySelectorAll('.sort-indicator').forEach(el => el.textContent = '');
            const indicator = document.getElementById('sort-' + field);
            if (indicator) {
                indicator.textContent = currentSortDirection === 'asc' ? ' ↑' : ' ↓';
            }
            
            // Re-render current view
            const activeFilter = document.querySelector('.filter-btn.active')?.id.replace('btn-', '') || 'all';
            window.showFileList(activeFilter);
            
            console.log('Sorting by:', field, currentSortDirection);
        };
        
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        console.log('Enhanced WCI Analyzer loaded');
    </script>
</body>
</html>
<?php
} // End of Web Interface
?>
