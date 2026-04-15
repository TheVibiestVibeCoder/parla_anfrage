<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NGO Business Tracker — Wartung</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: #000;
            color: #fff;
            font-family: 'Inter', Helvetica, Arial, sans-serif;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            border: 2px solid #fff;
            width: 100%;
            max-width: 560px;
        }

        .card__header {
            padding: 36px 32px 24px;
            border-bottom: 2px solid #333;
        }

        .dot {
            width: 12px;
            height: 12px;
            background: #fff;
            margin-bottom: 18px;
        }

        .dot--pulse {
            background: #EF4444;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: .3; }
        }

        h1 {
            font-family: 'Bebas Neue', Impact, 'Arial Narrow', sans-serif;
            font-size: clamp(32px, 8vw, 46px);
            font-weight: normal;
            line-height: 1;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .eyebrow {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 11px;
            letter-spacing: 2px;
            color: #666;
            text-transform: uppercase;
            margin-top: 14px;
        }

        .card__body {
            padding: 28px 32px 32px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #EF4444;
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .message {
            font-size: 15px;
            line-height: 1.7;
            color: #ccc;
        }

        .message strong {
            color: #fff;
        }

        .meta {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #222;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .meta__item {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 10px;
            letter-spacing: 1px;
            color: #444;
            text-transform: uppercase;
        }

        .meta__item span {
            color: #888;
        }
    </style>
</head>
<body>
    <div class="card">

        <div class="card__header">
            <div class="dot dot--pulse"></div>
            <h1>NGO Business<br>Tracker</h1>
            <p class="eyebrow">Parlamentarische Anfragen // Wartungsmodus</p>
        </div>

        <div class="card__body">
            <div class="status-badge">API-Störung</div>

            <p class="message">
                Der <strong>API-Endpoint des Parlaments</strong> liefert derzeit fehlerhafte
                Ergebnisse.
                <br><br>
                Wir haben das Problem identifiziert, den Fehler gemeldet und warten auf eine Behebung auf deren Seite.
                <br><br>
                Das Dashboard steht wieder vollständig zur Verfügung, sobald die Daten
                korrekt ausgeliefert werden.
            </p>

            <div class="meta">
                <div class="meta__item">Gemeldet&nbsp;<span>15.04.2026 / 09:00</span></div>
                <div class="meta__item">Status&nbsp;<span>In Bearbeitung</span></div>
            </div>
        </div>

    </div>
</body>
</html>
