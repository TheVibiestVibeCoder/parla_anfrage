<?php
/**
 * Dynamic XML Sitemap Generator for NGO Business Tracker
 * Generates sitemap with all important pages and time ranges
 */

header('Content-Type: application/xml; charset=utf-8');

// Get the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'https';
$baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
$currentDate = date('c');

// Define all time range pages
$timeRanges = [
    '1week' => ['priority' => '0.8', 'changefreq' => 'daily'],
    '1month' => ['priority' => '0.9', 'changefreq' => 'daily'],
    '3months' => ['priority' => '0.9', 'changefreq' => 'daily'],
    '6months' => ['priority' => '0.8', 'changefreq' => 'weekly'],
    '12months' => ['priority' => '1.0', 'changefreq' => 'daily'],  // Default/most important
    '1year' => ['priority' => '0.8', 'changefreq' => 'weekly'],
    '3years' => ['priority' => '0.7', 'changefreq' => 'weekly'],
    '5years' => ['priority' => '0.6', 'changefreq' => 'monthly']
];

// Start XML
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">

    <!-- Homepage / Default View (12 months) -->
    <url>
        <loc><?php echo htmlspecialchars($baseUrl); ?>/</loc>
        <lastmod><?php echo $currentDate; ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <!-- Main index.php -->
    <url>
        <loc><?php echo htmlspecialchars($baseUrl); ?>/index.php</loc>
        <lastmod><?php echo $currentDate; ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <?php foreach ($timeRanges as $range => $config): ?>
    <!-- <?php echo strtoupper($range); ?> View -->
    <url>
        <loc><?php echo htmlspecialchars($baseUrl); ?>/index.php?range=<?php echo $range; ?></loc>
        <lastmod><?php echo $currentDate; ?></lastmod>
        <changefreq><?php echo $config['changefreq']; ?></changefreq>
        <priority><?php echo $config['priority']; ?></priority>
    </url>

    <!-- Alternative URL format without index.php -->
    <url>
        <loc><?php echo htmlspecialchars($baseUrl); ?>/?range=<?php echo $range; ?></loc>
        <lastmod><?php echo $currentDate; ?></lastmod>
        <changefreq><?php echo $config['changefreq']; ?></changefreq>
        <priority><?php echo $config['priority']; ?></priority>
    </url>
    <?php endforeach; ?>

</urlset>
