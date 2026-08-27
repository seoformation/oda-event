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
define('EVENT_SWISS_API_URL', 'https://event-swiss.com');
define('EVENT_SWISS_API_SECRET', '');
