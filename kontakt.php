<?php
// ==========================================
// CONTACT FORM
// ==========================================

// Initialize variables
$success = false;
$error = false;
$errorMessage = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Validate required fields
    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        $error = true;
        $errorMessage = 'Bitte füllen Sie alle Felder aus.';
    }
    // Validate email format
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = true;
        $errorMessage = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }
    // Send email
    else {
        $to = 'markus@disinfoconsulting.eu';
        $emailSubject = '[NGO Tracker Kontakt] ' . $subject;

        // Build email body
        $emailBody = "Neue Nachricht vom NGO Business Tracker Kontaktformular\n\n";
        $emailBody .= "Name: " . $name . "\n";
        $emailBody .= "E-Mail: " . $email . "\n";
        $emailBody .= "Betreff: " . $subject . "\n\n";
        $emailBody .= "Nachricht:\n" . $message . "\n";

        // Set headers
        $headers = array(
            'From: noreply@' . $_SERVER['HTTP_HOST'],
            'Reply-To: ' . $email,
            'X-Mailer: PHP/' . phpversion(),
            'Content-Type: text/plain; charset=UTF-8'
        );

        // Send email
        if (mail($to, $emailSubject, $emailBody, implode("\r\n", $headers))) {
            $success = true;
            // Clear form fields on success
            $name = $email = $subject = $message = '';
        } else {
            $error = true;
            $errorMessage = 'Beim Versenden der Nachricht ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontakt | "NGO Business" Tracker</title>

    <meta name="description" content="Kontaktieren Sie das Team hinter dem NGO Business Tracker. Feedback, Fragen oder Anmerkungen willkommen.">
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="styles.css">

    <style>
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            font-family: var(--font-body);
            font-weight: 600;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            background: transparent;
            color: #fff;
            border: none;
            border-bottom: 2px solid var(--border-color);
            padding: 0.75rem 0;
            font-family: var(--font-body);
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #fff;
        }

        .form-textarea {
            min-height: 150px;
            resize: vertical;
            border: 2px solid var(--border-color);
            padding: 0.75rem;
        }

        .form-textarea:focus {
            border-color: #fff;
        }

        .form-button {
            background: #fff;
            color: #111;
            border: 2px solid #fff;
            padding: 0.75rem 2rem;
            font-family: var(--font-head);
            font-size: 1.25rem;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.2s;
            letter-spacing: 1px;
        }

        .form-button:hover {
            background: transparent;
            color: #fff;
        }

        .form-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border-color: #22c55e;
            color: #22c55e;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            color: #ef4444;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <!-- Header Navigation -->
    <header class="bg-black border-b border-white py-4">
        <div class="container-custom flex justify-between items-center">
            <a href="index.php" class="flex items-center gap-2 hover:opacity-80 transition-opacity">
                <div class="w-3 h-3 bg-white"></div>
                <span class="tracking-widest text-lg font-head text-white">NGO-Business Tracker</span>
            </a>
            <nav>
                <a href="index.php" class="text-sm font-mono text-gray-500 hover:text-white transition-colors">← Zurück</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container-custom flex-grow py-12 md:py-16">
        <div class="max-w-3xl mx-auto">

            <!-- Page Header -->
            <div class="mb-12 border-b-2 border-white pb-6">
                <div class="text-xs font-mono text-gray-500 mb-2">SYSTEM: CONTACT_MODULE</div>
                <h1 class="text-5xl md:text-6xl lg:text-7xl text-white leading-none mb-4">Kontakt</h1>
                <p class="text-lg text-gray-400">
                    Haben Sie Fragen, Feedback oder Anmerkungen zum NGO Business Tracker?
                    Wir freuen uns über Ihre Nachricht.
                </p>
            </div>

            <!-- Success Message -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Nachricht gesendet!</strong><br>
                    Vielen Dank für Ihre Nachricht. Wir werden uns so schnell wie möglich bei Ihnen melden.
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Fehler!</strong><br>
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <!-- Contact Form -->
            <form method="POST" action="" class="space-y-6">

                <div class="form-group">
                    <label for="name" class="form-label">Name *</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="form-input"
                        required
                        value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>"
                        placeholder="Ihr Name"
                    >
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">E-Mail *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        required
                        value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
                        placeholder="ihre.email@beispiel.at"
                    >
                </div>

                <div class="form-group">
                    <label for="subject" class="form-label">Betreff *</label>
                    <input
                        type="text"
                        id="subject"
                        name="subject"
                        class="form-input"
                        required
                        value="<?php echo isset($subject) ? htmlspecialchars($subject) : ''; ?>"
                        placeholder="Worum geht es?"
                    >
                </div>

                <div class="form-group">
                    <label for="message" class="form-label">Nachricht *</label>
                    <textarea
                        id="message"
                        name="message"
                        class="form-textarea"
                        required
                        placeholder="Ihre Nachricht an uns..."
                    ><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="form-button">
                        Nachricht senden
                    </button>
                </div>

                <p class="text-xs text-gray-500 font-mono">
                    * Pflichtfelder
                </p>
            </form>

            <!-- Alternative Contact Info -->
            <div class="mt-16 pt-8 border-t border-gray-800">
                <h2 class="text-2xl text-white mb-4 font-head">Direkter Kontakt</h2>
                <p class="text-gray-400 mb-4">
                    Sie können uns auch direkt per E-Mail erreichen:
                </p>
                <a href="mailto:markus@disinfoconsulting.eu" class="text-white hover:text-gray-300 underline font-mono text-sm">
                    markus@disinfoconsulting.eu
                </a>
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
                    <div class="text-xs font-mono text-gray-600">
                        © <?php echo date('Y'); ?> "NGO BUSINESS" TRACKER
                    </div>
                    <div class="mt-2 space-x-4">
                        <a href="impressum.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Impressum</a>
                        <a href="kontakt.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Kontakt</a>
                    </div>
                </div>

                <div class="text-left md:text-right w-full md:w-auto">
                    <div class="text-xs font-mono text-gray-500 mb-2">QUELLE: PARLAMENT.GV.AT</div>
                    <div class="text-xs font-mono text-gray-500 mb-2">LAST UPDATE: <?php echo date('d.m.Y H:i'); ?></div>
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
