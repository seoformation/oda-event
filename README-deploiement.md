# Déploiement de oda-event.ch sur Hostinger

Ce guide explique comment publier le site sur un hébergement mutualisé
Hostinger (hPanel), étape par étape.

## 0. Prérequis

- Un accès à hPanel (Hostinger) sur le plan qui héberge `oda-event.ch`.
- Les fichiers du site en local (ce dossier).

## 1. Créer la base de données MySQL

1. Dans hPanel, aller dans **Bases de données > Bases de données MySQL**.
2. Créer une nouvelle base (ex. `u123456789_oda_event`) et un utilisateur MySQL
   associé avec un mot de passe fort. Noter les 3 informations générées :
   nom de la base, nom d'utilisateur, mot de passe.
3. Associer l'utilisateur à la base avec tous les privilèges.

## 2. Importer le schéma SQL

1. Toujours dans **Bases de données MySQL**, cliquer sur **Gérer** /
   **phpMyAdmin** pour la base créée.
2. Sélectionner la base, aller dans l'onglet **Importer**.
3. Choisir le fichier `sql/schema.sql` de ce projet et lancer l'import.
4. Vérifier que la table `membres_inscription` a bien été créée (onglet
   **Structure**).

## 3. Créer l'adresse e-mail SMTP

1. Dans hPanel, aller dans **E-mails > Comptes e-mail**.
2. Créer une adresse (ex. `contact@oda-event.ch`) avec un mot de passe fort.
3. Noter l'hôte SMTP sortant fourni par Hostinger (généralement
   `smtp.hostinger.com`, port `465` en SSL ou `587` en STARTTLS — à vérifier
   dans la documentation Hostinger de votre plan, cela peut varier selon le
   datacenter).

## 4. Renseigner config.php

Ouvrir `config.php` et remplacer les valeurs `REMPLACER_...` par les
identifiants obtenus aux étapes 1 et 3 :

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_oda_event');
define('DB_USER', 'u123456789_admin');
define('DB_PASS', 'mot-de-passe-mysql');

define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USER', 'contact@oda-event.ch');
define('SMTP_PASS', 'mot-de-passe-email');

define('MAIL_FROM_ADDRESS', 'contact@oda-event.ch');
define('MAIL_NOTIFICATION_TO', 'contact@oda-event.ch');
```

**Important** : `config.php` contient des identifiants sensibles. Ne jamais
le publier dans un dépôt Git public avec de vraies valeurs.

## 5. Uploader les fichiers sur Hostinger

**Option A — Gestionnaire de fichiers hPanel**
1. hPanel > **Fichiers > Gestionnaire de fichiers**.
2. Aller dans `public_html` (ou le sous-dossier associé au domaine
   `oda-event.ch` si le domaine est un site additionnel).
3. Uploader l'intégralité du contenu de ce dossier `oda-event.ch/`
   **directement à la racine** de `public_html` (et non dans un
   sous-dossier), y compris les fichiers cachés `.htaccess`.

**Option B — FTP**
1. hPanel > **Fichiers > Comptes FTP** pour récupérer l'hôte, l'utilisateur
   et le mot de passe FTP (ou utiliser les identifiants hPanel principaux).
2. Se connecter avec un client FTP (FileZilla, Cyberduck…) et transférer tout
   le contenu du dossier vers `public_html`.

## 6. Pointer le domaine oda-event.ch

- Si `oda-event.ch` est le domaine principal de l'hébergement : rien à faire,
  `public_html` sert déjà ce domaine.
- Si c'est un domaine additionnel : hPanel > **Domaines** > s'assurer que
  `oda-event.ch` pointe vers le dossier où les fichiers ont été uploadés
  (étape 5), et que les enregistrements DNS (A / CNAME) du domaine pointent
  vers les serveurs Hostinger (souvent automatique si le domaine a été acheté
  chez Hostinger, sinon à configurer chez le registrar).
- Activer le certificat SSL gratuit (hPanel > **Sécurité > SSL**) puis
  attendre sa propagation avant de tester `https://oda-event.ch`.

## 7. Vérifications post-déploiement

1. Ouvrir `https://oda-event.ch/index.html` (et vérifier que `/` redirige
   bien vers `index.html`, sinon renommer `index.html` reste nécessaire
   selon la configuration du serveur — Apache sert `index.html` par défaut).
2. Tester le formulaire d'adhésion (`devenir-membre.html`) avec un cas valide
   puis un cas invalide (champ manquant, e-mail mal formé) : les messages
   d'erreur/succès doivent s'afficher sans recharger la page.
3. Vérifier dans phpMyAdmin que la ligne a bien été insérée dans
   `membres_inscription`.
4. Vérifier la réception de l'e-mail de notification sur
   `contact@oda-event.ch` et de l'e-mail de confirmation sur l'adresse
   utilisée pour le test.
5. Tester le formulaire de contact (`contact.html`) de la même façon.
6. Tester l'affichage sur mobile (largeur ~375px) pour les 4 pages.

## 8. Maintenance

- Les logs d'erreurs PHP (échecs d'envoi d'e-mail, erreurs SQL) sont écrits
  via `error_log()` — consultables dans hPanel > **Avancé > Journal des
  erreurs PHP**.
- Pour ajouter une langue (DE/IT/EN) plus tard : dupliquer les fichiers
  `.html` (ex. `index.de.html`) en traduisant les textes, qui sont pour
  l'instant tous en dur dans le HTML de chaque page (aucun système de
  traduction n'a été mis en place dans cette première version).
