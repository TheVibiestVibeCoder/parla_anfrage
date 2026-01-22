<?php
// ==========================================
// MAILING LIST SIGNUP PAGE
// ==========================================

require_once __DIR__ . '/MailingListDB.php';

// Initialize variables
$success = false;
$reactivated = false;
$error = false;
$errorMessage = '';
$db = null;

// Get client IP (handle proxies)
function getClientIP() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Check for proxied IP (but validate it)
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIPs = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $firstIP = trim($forwardedIPs[0]);
        if (filter_var($firstIP, FILTER_VALIDATE_IP)) {
            $ip = $firstIP;
        }
    }

    return $ip;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = new MailingListDB();
        $clientIP = getClientIP();

        // Rate limiting check (DDoS protection)
        if ($db->checkRateLimit($clientIP, 'signup', 3, 60)) {
            $error = true;
            $errorMessage = 'Zu viele Anmeldeversuche. Bitte versuchen Sie es in einer Stunde erneut.';
        } else {
            // Log attempt for rate limiting
            $db->logRateLimitAttempt($clientIP, 'signup');

            // Get and validate form data
            $email = trim($_POST['email'] ?? '');
            $gdprConsent = isset($_POST['gdpr_consent']) && $_POST['gdpr_consent'] === '1';

            // Validate required fields
            if (empty($email)) {
                $error = true;
                $errorMessage = 'Bitte geben Sie Ihre E-Mail-Adresse ein.';
            }
            // Validate email format
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = true;
                $errorMessage = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
            }
            // Check GDPR consent
            elseif (!$gdprConsent) {
                $error = true;
                $errorMessage = 'Bitte stimmen Sie der Datenschutzerklärung und Datenverarbeitung zu.';
            }
            // Add subscriber
            else {
                try {
                    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    $result = $db->addSubscriber($email, $clientIP, $userAgent);

                    if ($result['success']) {
                        $success = true;
                        $reactivated = $result['reactivated'] ?? false;
                        $email = ''; // Clear form
                    }
                } catch (Exception $e) {
                    $error = true;
                    $errorMessage = $e->getMessage();
                }
            }
        }

        // Clean old rate limit entries periodically
        if (rand(1, 100) === 1) {
            $db->cleanOldRateLimits(24);
        }

    } catch (Exception $e) {
        $error = true;
        $errorMessage = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
        error_log('Mailing list signup error: ' . $e->getMessage());
    }
}

// Get current subscriber count
$subscriberCount = 0;
try {
    if (!$db) {
        $db = new MailingListDB();
    }
    $subscriberCount = $db->getSubscriberCount();
} catch (Exception $e) {
    error_log('Error getting subscriber count: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newsletter | "NGO Business" Tracker</title>

    <meta name="description" content="Erhalten Sie täglich Updates über neue parlamentarische Anfragen zum Thema NGO-Business. Bleiben Sie informiert über die neuesten Entwicklungen.">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="Newsletter | NGO Business Tracker">
    <meta property="og:description" content="Erhalten Sie täglich Updates über neue parlamentarische Anfragen zum Thema NGO-Business.">
    <meta property="og:locale" content="de_AT">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'bebas': ['"Bebas Neue"', 'cursive'],
                        'sans': ['"Inter"', 'sans-serif'],
                        'mono': ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        'brand-black': '#050505',
                        'brand-gray': '#1a1a1a',
                    }
                }
            }
        }
    </script>

    <style>
        :root {
            --bg-color: #000000;
            --text-color: #ffffff;
            --border-color: #333333;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container-custom {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .form-input {
            width: 100%;
            padding: 1rem;
            background-color: #111;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            border-radius: 4px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-checkbox {
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid var(--border-color);
            background-color: #111;
            cursor: pointer;
            accent-color: #3B82F6;
        }

        .btn-primary {
            background-color: #3B82F6;
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 4px;
            font-family: 'Bebas Neue', cursive;
            font-size: 1.25rem;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
        }

        .btn-primary:hover {
            background-color: #2563EB;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            font-family: 'Inter', sans-serif;
        }

        .alert-success {
            background-color: rgba(34, 197, 94, 0.1);
            border: 1px solid #22C55E;
            color: #22C55E;
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid #EF4444;
            color: #EF4444;
        }

        .stats-badge {
            display: inline-block;
            background: linear-gradient(135deg, #3B82F6, #2563EB);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.875rem;
            font-weight: bold;
        }

        .info-box {
            background-color: rgba(59, 130, 246, 0.05);
            border-left: 4px solid #3B82F6;
            padding: 1.5rem;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="bg-black border-b border-white py-6">
        <div class="container-custom">
            <div class="flex items-center justify-between">
                <a href="index.php" class="text-2xl font-bebas tracking-wider hover:text-gray-300 transition-colors">
                    "NGO BUSINESS" TRACKER
                </a>
                <nav class="flex gap-6">
                    <a href="index.php" class="text-sm font-mono text-gray-400 hover:text-white transition-colors">Dashboard</a>
                    <a href="kontakt.php" class="text-sm font-mono text-gray-400 hover:text-white transition-colors">Kontakt</a>
                    <a href="impressum.php" class="text-sm font-mono text-gray-400 hover:text-white transition-colors">Impressum</a>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 py-12 md:py-20">
        <div class="container-custom">
            <div class="max-w-2xl mx-auto">
                <!-- Hero Section -->
                <div class="text-center mb-12">
                    <h1 class="text-5xl md:text-6xl font-bebas tracking-wider mb-4 uppercase">
                        Newsletter Anmeldung
                    </h1>
                    <p class="text-lg text-gray-400 font-sans mb-6">
                        Erhalten Sie täglich Updates über neue parlamentarische Anfragen zum Thema NGO-Business.
                    </p>
                    <div class="stats-badge">
                        📊 <?php echo number_format($subscriberCount, 0, ',', '.'); ?> Abonnenten
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?php if ($reactivated): ?>
                            <strong>✅ Willkommen zurück!</strong><br>
                            Sie haben sich erfolgreich wieder angemeldet und erhalten ab heute täglich um 20:00 Uhr eine E-Mail mit den neuesten Anfragen – falls vorhanden.
                        <?php else: ?>
                            <strong>✅ Erfolgreich angemeldet!</strong><br>
                            Sie erhalten ab heute täglich um 20:00 Uhr eine E-Mail mit den neuesten Anfragen – falls vorhanden.
                        <?php endif; ?>
                        Sollte die FPÖ mal faul sein, erhalten Sie eine unterhaltsame Nachricht von uns. 😄
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <strong>❌ Fehler</strong><br>
                        <?php echo htmlspecialchars($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <!-- Signup Form -->
                <form method="POST" class="bg-brand-gray border border-gray-800 rounded-lg p-8 shadow-2xl">
                    <div class="mb-6">
                        <label for="email" class="block text-sm font-mono text-gray-400 mb-2 uppercase tracking-wider">
                            E-Mail-Adresse
                        </label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-input"
                            placeholder="ihre.email@beispiel.at"
                            value="<?php echo htmlspecialchars($email ?? ''); ?>"
                            required
                        >
                    </div>

                    <!-- GDPR Consent -->
                    <div class="mb-8">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                name="gdpr_consent"
                                value="1"
                                class="form-checkbox mt-1"
                                required
                            >
                            <span class="text-sm text-gray-300 font-sans leading-relaxed">
                                Ich stimme zu, dass meine E-Mail-Adresse zum Versand des täglichen Newsletters
                                gespeichert und verarbeitet wird. Ich kann mich jederzeit wieder abmelden.
                                Die <a href="impressum.php" class="text-blue-400 hover:text-blue-300 underline">Datenschutzerklärung</a>
                                habe ich zur Kenntnis genommen.
                                <span class="text-red-400">*</span>
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="btn-primary w-full">
                        Jetzt anmelden
                    </button>
                </form>

                <!-- Information Box -->
                <div class="info-box">
                    <h3 class="text-lg font-bebas tracking-wider mb-3 text-blue-400">
                        ℹ️ Was Sie erwartet:
                    </h3>
                    <ul class="space-y-2 text-sm text-gray-300 font-sans">
                        <li>✉️ <strong>Täglich um 20:00 Uhr</strong> erhalten Sie eine E-Mail</li>
                        <li>📋 <strong>Neue Anfragen</strong> werden übersichtlich zusammengefasst</li>
                        <li>😄 <strong>Keine neuen Anfragen?</strong> Dann gibt's eine lustige Nachricht</li>
                        <li>🔒 <strong>Ihre Daten sind sicher</strong> – kein Spam, keine Weitergabe</li>
                        <li>🚫 <strong>Jederzeit abmelden</strong> – Link in jeder E-Mail</li>
                    </ul>
                </div>

                <!-- Security Note -->
                <div class="mt-8 text-center">
                    <p class="text-xs text-gray-600 font-mono">
                        🔐 Diese Seite ist DDoS-geschützt mit Rate-Limiting<br>
                        Max. 3 Anmeldeversuche pro Stunde
                    </p>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-black border-t border-white py-8 md:py-12 mt-auto">
        <div class="container-custom">
            <div class="flex flex-col md:flex-row justify-between items-start gap-8">
                <div class="max-w-md">
                    <h3 class="text-sm font-bold text-white mb-4 uppercase tracking-wider">Über das Projekt</h3>
                    <p class="text-xs text-gray-500 leading-relaxed font-sans mb-4">
                        Der NGO Business Tracker analysiert parlamentarische Anfragen im österreichischen Nationalrat, die gezielt zum Thema NGOs gestellt werden.
                        <br><br>
                        Er macht sichtbar, wie oft, von wem und in welchen Mustern das Framing gepusht wird.
                    </p>
                    <div class="text-xs text-yellow-600 leading-relaxed font-sans mb-4 italic">
                        Hinweis: Diese Plattform ist experimentell. Fehler können vorkommen.
                    </div>
                    <div class="text-xs font-mono text-gray-600">
                          © <?php echo date('Y'); ?> "NGO BUSINESS" TRACKER
                    </div>
                    <div class="mt-2 space-x-4">
                        <a href="impressum.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Impressum</a>
                        <a href="kontakt.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Kontakt</a>
                        <a href="index.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Dashboard</a>
                    </div>
                </div>

                <div class="text-left md:text-right w-full md:w-auto">
                    <div class="text-xs font-mono text-gray-500 mb-2">NEWSLETTER SERVICE</div>
                    <div class="text-xs font-mono text-gray-500 mb-2">DAILY DELIVERY: 20:00</div>
                    <div class="flex items-center justify-start md:justify-end gap-2 mt-4">
                        <div class="w-2 h-2 bg-green-600 rounded-full"></div>
                        <span class="text-xs font-mono text-green-600">SYSTEM OPERATIONAL</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
