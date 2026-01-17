<?php
/**
 * Simple File-Based Cache Manager
 * Optimized for speed and simplicity
 */
class CacheManager {
    private $cacheDir;
    private $defaultTTL;

    /**
     * @param string $cacheDir Directory to store cache files
     * @param int $defaultTTL Default time-to-live in seconds (default: 15 minutes)
     */
    public function __construct($cacheDir = './cache', $defaultTTL = 900) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->defaultTTL = $defaultTTL;

        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get cached data if valid, otherwise return null
     *
     * @param string $key Cache key
     * @return mixed|null Cached data or null if expired/not found
     */
    public function get($key) {
        $filename = $this->getCacheFilename($key);

        if (!file_exists($filename)) {
            return null;
        }

        $data = @file_get_contents($filename);
        if ($data === false) {
            return null;
        }

        $cached = @unserialize($data);
        if ($cached === false || !isset($cached['expires']) || !isset($cached['data'])) {
            return null;
        }

        // Check if expired
        if (time() > $cached['expires']) {
            @unlink($filename);
            return null;
        }

        return $cached['data'];
    }

    /**
     * Store data in cache
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int|null $ttl Time-to-live in seconds (null = use default)
     * @return bool Success status
     */
    public function set($key, $data, $ttl = null) {
        $ttl = $ttl ?? $this->defaultTTL;
        $filename = $this->getCacheFilename($key);

        $cached = [
            'expires' => time() + $ttl,
            'data' => $data
        ];

        $serialized = serialize($cached);
        return @file_put_contents($filename, $serialized, LOCK_EX) !== false;
    }

    /**
     * Check if cache exists and is valid
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has($key) {
        return $this->get($key) !== null;
    }

    /**
     * Delete cached item
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete($key) {
        $filename = $this->getCacheFilename($key);
        if (file_exists($filename)) {
            return @unlink($filename);
        }
        return true;
    }

    /**
     * Clear all cached items (optionally older than X seconds)
     *
     * @param int|null $olderThan Clear items older than X seconds (null = all)
     * @return int Number of items cleared
     */
    public function clear($olderThan = null) {
        $cleared = 0;
        $files = glob($this->cacheDir . '/cache_*.dat');

        foreach ($files as $file) {
            if ($olderThan === null) {
                if (@unlink($file)) {
                    $cleared++;
                }
            } else {
                $age = time() - filemtime($file);
                if ($age > $olderThan) {
                    if (@unlink($file)) {
                        $cleared++;
                    }
                }
            }
        }

        return $cleared;
    }

    /**
     * Get or set cached data (convenience method)
     *
     * @param string $key Cache key
     * @param callable $callback Function to generate data if cache miss
     * @param int|null $ttl Time-to-live in seconds
     * @return mixed Cached or freshly generated data
     */
    public function remember($key, callable $callback, $ttl = null) {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $data = $callback();
        $this->set($key, $data, $ttl);

        return $data;
    }

    /**
     * Generate safe filename for cache key
     *
     * @param string $key Cache key
     * @return string Full path to cache file
     */
    private function getCacheFilename($key) {
        $hash = md5($key);
        return $this->cacheDir . '/cache_' . $hash . '.dat';
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics about cache
     */
    public function getStats() {
        $files = glob($this->cacheDir . '/cache_*.dat');
        $totalSize = 0;
        $validCount = 0;
        $expiredCount = 0;

        foreach ($files as $file) {
            $totalSize += filesize($file);

            $data = @file_get_contents($file);
            if ($data !== false) {
                $cached = @unserialize($data);
                if ($cached !== false && isset($cached['expires'])) {
                    if (time() <= $cached['expires']) {
                        $validCount++;
                    } else {
                        $expiredCount++;
                    }
                }
            }
        }

        return [
            'total_files' => count($files),
            'valid_items' => $validCount,
            'expired_items' => $expiredCount,
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2)
        ];
    }
}
