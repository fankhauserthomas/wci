<?php
/**
 * Gemeinsame Helper-Funktion für den Transfer von AV-Res-webImp → AV-Res
 *
 * Kann sowohl vom SSE-Importer als auch vom klassischen Import genutzt werden.
 *
 * @param mysqli        $mysqli   Aktive Datenbankverbindung
 * @param callable|null $logger   Optionaler Logger mit Signatur function(string $level, string $message): void
 *
 * @return array{
 *     success: bool,
 *     stats: array{inserted:int,updated:int,unchanged:int,errors:int,total:int},
 *     totalRecords: int
 * }
 */

if (!function_exists('transferWebImpToProduction')) {
    /**
     * Determines a usable PHP CLI binary even when running inside FPM.
     */
    function detectPhpCliBinary(): string
    {
        $candidates = [];

        // Allow explicit override via environment
        $envBinary = getenv('PHP_CLI_BINARY');
        if ($envBinary) {
            $candidates[] = $envBinary;
        }

        $sapi = PHP_SAPI;

        if ($sapi === 'cli') {
            $candidates[] = PHP_BINARY;
        } else {
            // Under FPM, PHP_BINARY often points to php-fpm – skip that binary
            if (PHP_BINARY && stripos(PHP_BINARY, 'php-fpm') === false) {
                $candidates[] = PHP_BINARY;
            }
        }

        if (defined('PHP_BINDIR')) {
            $bindir = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR);
            $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . 'php';
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . 'php' . $version;
            $candidates[] = $bindir . DIRECTORY_SEPARATOR . 'php-cli';
        }

        // Fallback to PATH lookup
        $candidates[] = 'php';

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            if ($candidate === 'php') {
                return $candidate;
            }

            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    function transferWebImpToProduction(mysqli $mysqli, callable $logger = null) {
        $log = function ($level, $message) use ($logger) {
            if ($logger) {
                $logger($level, $message);
            }
        };

        $phpBinary = detectPhpCliBinary();
        $log('info', 'Nutze PHP CLI Binary: ' . $phpBinary);

        $php = escapeshellarg($phpBinary);
        $script = escapeshellarg(__DIR__ . '/import_webimp.php');
        $jsonFlag = escapeshellarg('--json');
        $command = "$php $script $jsonFlag";

        $outputLines = [];
        $exitCode = 0;
        exec($command, $outputLines, $exitCode);
        $rawOutput = trim(implode("\n", $outputLines));

        if ($exitCode !== 0) {
            $log('error', 'import_webimp.php beendete sich mit Exit-Code ' . $exitCode);
            $log('error', $rawOutput);
            return [
                'success' => false,
                'stats' => [
                    'inserted' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'skipped' => 0,
                    'errors' => 1,
                    'total' => 0
                ],
                'raw' => $rawOutput
            ];
        }

        $decoded = json_decode($rawOutput, true);
        if (!is_array($decoded)) {
            $log('error', 'Konnte JSON-Ausgabe von import_webimp.php nicht parsen');
            $log('error', $rawOutput);
            return [
                'success' => false,
                'stats' => [
                    'inserted' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'skipped' => 0,
                    'errors' => 1,
                    'total' => 0
                ],
                'raw' => $rawOutput
            ];
        }

        if (!empty($decoded['success'])) {
            $log('success', $decoded['message'] ?? 'import_webimp erfolgreich');
        } else {
            $log('error', $decoded['error'] ?? 'Unbekannter Fehler bei import_webimp');
        }

        $errorCount = 0;
        if (isset($decoded['errors'])) {
            if (is_array($decoded['errors'])) {
                $errorCount = count($decoded['errors']);
            } elseif (is_numeric($decoded['errors'])) {
                $errorCount = (int)$decoded['errors'];
            }
        }

        $stats = [
            'inserted' => $decoded['inserted'] ?? 0,
            'updated' => $decoded['updated'] ?? 0,
            'unchanged' => $decoded['unchanged'] ?? 0,
            'skipped' => $decoded['skipped'] ?? 0,
            'errors' => $errorCount,
            'total' => $decoded['total'] ?? 0
        ];

        return [
            'success' => !empty($decoded['success']),
            'stats' => $stats,
            'raw' => $decoded
        ];
    }
}
