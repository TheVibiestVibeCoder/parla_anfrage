<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impressum - NGO Business Tracker</title>

    <style>
        /* Import Bebas Neue font */
        @import url('https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', sans-serif;
            background-color: #000000;
            color: #ffffff;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
            flex: 1;
        }

        .back-link {
            display: inline-block;
            color: #10b981;
            text-decoration: none;
            font-family: monospace;
            font-size: 0.875rem;
            margin-bottom: 2rem;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: #34d399;
        }

        h1 {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 4rem;
            line-height: 1;
            margin-bottom: 1rem;
            border-bottom: 2px solid #ffffff;
            padding-bottom: 1rem;
        }

        h2 {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 2rem;
            margin-top: 2.5rem;
            margin-bottom: 1rem;
            color: #ffffff;
        }

        h3 {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.5rem;
            margin-top: 2rem;
            margin-bottom: 0.75rem;
            color: #d1d5db;
        }

        p {
            margin-bottom: 1rem;
            color: #9ca3af;
            font-size: 0.875rem;
        }

        .meta-info {
            font-family: monospace;
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        .info-block {
            background-color: #0a0a0a;
            border: 1px solid #ffffff;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-block p:last-child {
            margin-bottom: 0;
        }

        footer {
            border-top: 1px solid #ffffff;
            padding: 2rem;
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            text-align: center;
            font-family: monospace;
            font-size: 0.75rem;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .container {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← ZURÜCK ZUR HAUPTSEITE</a>

        <div class="meta-info">RECHTLICHE INFORMATIONEN</div>
        <h1>Offenlegung<br>Impressum</h1>

        <p style="margin-top: 1.5rem; margin-bottom: 2rem;">Informationen gemäß §5 (1) ECG, § 25 MedienG, § 63 GewO und § 14 UGB.</p>

        <div class="info-block">
            <h2>Webseitenbetreiber</h2>
            <p><strong>Disinfo Combat GmbH</strong></p>
        </div>

        <div class="info-block">
            <h2>Anschrift</h2>
            <p>
                Hettenkofergasse 34/I<br>
                1160 Wien<br>
                Österreich
            </p>
        </div>

        <div class="info-block">
            <h2>Firmenbuch</h2>
            <p>
                FN 563690 g<br>
                Handelsgericht Wien
            </p>
        </div>

        <div class="info-block">
            <h2>UID-Nummer</h2>
            <p>ATU77349503</p>
        </div>

        <div class="info-block">
            <h2>Aufsichtsbehörde</h2>
            <p>Magistrat der Stadt Wien</p>
        </div>

        <div class="info-block">
            <h2>Rechtsvorschriften</h2>
            <p><a href="https://www.ris.bka.gv.at" target="_blank" rel="noopener noreferrer" style="color: #10b981; text-decoration: underline;">www.ris.bka.gv.at</a></p>
        </div>

        <h2>Urheberrecht</h2>
        <p>Die Inhalte dieser Webseite unterliegen, soweit dies rechtlich möglich ist, diversen Schutzrechten (z.B. dem Urheberrecht). Jegliche Verwendung oder Verbreitung von bereitgestelltem Material, welche urheberrechtlich untersagt ist, bedarf schriftlicher Zustimmung des Webseitenbetreibers.</p>
        <p>Die Urheberrechte Dritter werden vom Betreiber dieser Webseite mit größter Sorgfalt beachtet. Sollten Sie trotzdem auf eine Urheberrechtsverletzung aufmerksam werden, bitten wir um einen entsprechenden Hinweis. Bei Bekanntwerden derartiger Rechtsverletzungen werden wir den betroffenen Inhalt umgehend entfernen.</p>

        <h2>Haftungsausschluss</h2>
        <p>Trotz sorgfältiger inhaltlicher Kontrolle übernimmt der Webseitenbetreiber dieser Webseite keine Haftung für die Inhalte externer Links. Für den Inhalt der verlinkten Seiten sind ausschließlich deren Betreiber verantwortlich. Sollten Sie dennoch auf ausgehende Links aufmerksam werden, welche auf eine Webseite mit rechtswidriger Tätigkeit oder Information verweisen, ersuchen wir um dementsprechenden Hinweis, um diese nach § 17 Abs. 2 ECG umgehend zu entfernen.</p>

        <p style="margin-top: 2rem; font-size: 0.75rem; color: #6b7280;">Quelle: Impressum Generator Österreich</p>
    </div>

    <footer>
        <div class="footer-content">
            © <?php echo date('Y'); ?> "NGO BUSINESS" TRACKER
        </div>
    </footer>
</body>
</html>
