<?php
/**
 * Asset Downloader
 * Downloads and caches external CDN assets locally for faster loading
 * Run this script once to download all assets, or it will run automatically on first page load
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$assetsDir = __DIR__ . '/assets';
$cssDir = $assetsDir . '/css';
$jsDir = $assetsDir . '/js';

// Create directories if they don't exist
@mkdir($assetsDir, 0755, true);
@mkdir($cssDir, 0755, true);
@mkdir($jsDir, 0755, true);

$assets = [
    'chart.js' => [
        'url' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
        'path' => $jsDir . '/chart.min.js',
        'type' => 'js'
    ],
    'tailwind.css' => [
        'url' => 'https://cdn.tailwindcss.com',
        'path' => $cssDir . '/tailwind.min.css',
        'type' => 'css'
    ]
];

/**
 * Download a file using cURL with proper headers
 */
function downloadFile($url, $destination) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Cache-Control: no-cache'
        ]
    ]);

    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($content === false || $httpCode !== 200) {
        throw new Exception("Failed to download from $url (HTTP $httpCode): $error");
    }

    if (file_put_contents($destination, $content) === false) {
        throw new Exception("Failed to write to $destination");
    }

    return true;
}

/**
 * Check if assets need to be downloaded
 */
function assetsExist($assets) {
    foreach ($assets as $asset) {
        if (!file_exists($asset['path']) || filesize($asset['path']) < 100) {
            return false;
        }
    }
    return true;
}

/**
 * Main download function
 */
function downloadAssets($assets, $silent = false) {
    $results = [];

    foreach ($assets as $name => $asset) {
        if (!$silent) {
            echo "Downloading $name from {$asset['url']}...\n";
        }

        try {
            downloadFile($asset['url'], $asset['path']);
            $size = filesize($asset['path']);
            $results[$name] = [
                'success' => true,
                'size' => $size,
                'path' => $asset['path']
            ];

            if (!$silent) {
                echo "✓ Downloaded $name (" . round($size / 1024, 2) . " KB)\n";
            }
        } catch (Exception $e) {
            $results[$name] = [
                'success' => false,
                'error' => $e->getMessage()
            ];

            if (!$silent) {
                echo "✗ Failed to download $name: " . $e->getMessage() . "\n";
            }
        }
    }

    return $results;
}

// If called directly (not included), run the download
if (php_sapi_name() === 'cli' || basename($_SERVER['PHP_SELF']) === 'download-assets.php') {
    echo "=== NGO Tracker Asset Downloader ===\n\n";

    if (assetsExist($assets)) {
        echo "All assets already exist. Delete them to re-download.\n";
        echo "\nExisting assets:\n";
        foreach ($assets as $name => $asset) {
            if (file_exists($asset['path'])) {
                $size = filesize($asset['path']);
                echo "  - $name: " . round($size / 1024, 2) . " KB at {$asset['path']}\n";
            }
        }
        exit(0);
    }

    echo "Downloading assets...\n\n";
    $results = downloadAssets($assets, false);

    echo "\n=== Download Summary ===\n";
    $success = 0;
    $failed = 0;

    foreach ($results as $name => $result) {
        if ($result['success']) {
            $success++;
            echo "✓ $name: OK\n";
        } else {
            $failed++;
            echo "✗ $name: FAILED - {$result['error']}\n";
        }
    }

    echo "\nTotal: $success succeeded, $failed failed\n";
    exit($failed > 0 ? 1 : 0);
}
