<?php
/**
 * config.example.php — Modèle de configuration (MySQL + SMTP)
 *
 * Copier ce fichier en `config.php` et renseigner les vraies valeurs.
 * `config.php` est ignoré par git (voir .gitignore) : ne jamais committer
 * de vrais identifiants dans le dépôt.
 */

// ---------- Base de données MySQL (hPanel > Bases de données MySQL) ----------
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// ---------- Envoi d'e-mails via SMTP Hostinger (hPanel > E-mails) ----------
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);              // 465 (SSL) ou 587 (STARTTLS)
define('SMTP_SECURE', 'ssl');          // 'ssl' pour le port 465, 'tls' pour le port 587
define('SMTP_USER', '');               // adresse e-mail créée dans hPanel
define('SMTP_PASS', '');

// ---------- Adresses e-mail ----------
define('MAIL_FROM_ADDRESS', 'contact@oda-event.ch');
define('MAIL_FROM_NAME', "OrTra Suisse de l'Événementiel");
define('MAIL_NOTIFICATION_TO', 'contact@oda-event.ch'); // reçoit les notifications d'inscription/contact

// ---------- Site ----------
define('SITE_URL', 'https://oda-event.ch');

// ---------- API event-swiss.com (option Silver gratuite, devenir-membre) ----------
// EVENT_SWISS_API_SECRET doit avoir exactement la même valeur que
// ODA_API_SECRET dans le .env.local / Vercel du projet event-swiss.com.
define('EVENT_SWISS_API_URL', 'https://www.event-swiss.com');
define('EVENT_SWISS_API_SECRET', '');

// ---------- Paiement Stripe (cotisation OrTra, paiement.php) ----------
// À renseigner après création du compte Stripe (dashboard.stripe.com) :
// - STRIPE_SECRET_KEY : clé secrète (sk_live_... ou sk_test_...)
// - STRIPE_WEBHOOK_SECRET : secret de signature du webhook Stripe pointant
//   vers https://oda-event.ch/paiement-webhook.php pour l'événement
//   checkout.session.completed
define('STRIPE_SECRET_KEY', '');
define('STRIPE_WEBHOOK_SECRET', '');

// ---------- Facture QR-suisse (facture-qr.php, paiement des cotisations) ----------
// IBAN du compte bancaire de l'association (format avec espaces accepté,
// nettoyé automatiquement à l'usage). Compte non-QR-IBAN : la facture
// utilise le type de référence "NON" (message libre pour identifier le
// paiement, pas de numéro de référence structuré).
define('QRBILL_IBAN', '');
define('QRBILL_CREDITOR_NAME', "OrTra Suisse de l'Événementiel");
define('QRBILL_CREDITOR_STREET', 'La Petite Camargue');
define('QRBILL_CREDITOR_BUILDING_NR', '66');
define('QRBILL_CREDITOR_ZIP', '1897');
define('QRBILL_CREDITOR_CITY', 'Bouveret');
define('QRBILL_CREDITOR_COUNTRY', 'CH');

// ---------- Tâche planifiée (cron-relance-paiement.php) ----------
// A configurer comme Cron Job Hostinger (hPanel > Avancé > Cron Jobs),
// une fois par jour, sur : php cron-relance-paiement.php <CRON_SECRET>
define('CRON_SECRET', '');
