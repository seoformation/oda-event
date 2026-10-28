<?php
/**
 * admin-functions.php — Règles de gestion des dossiers membres utilisées
 * par admin.php : actions disponibles selon le statut, désinscription
 * (calcul du préavis, enregistrement, e-mail), journal des actions admin.
 *
 * Volontairement sans require : les fonctions de calcul pures restent
 * testables seules (tests/desinscription-test.php). Les appelants incluent
 * functions.php (send_mail, render_email_html, t, e) et db.php avant.
 */

declare(strict_types=1);

// ---------- Paramètres de la désinscription ----------
//
// Isolés ici pour pouvoir être ajustés (ou surchargés dans config.php via
// define()) sans toucher au reste de l'application :
//
// - DESINSCRIPTION_FIN_EXERCICE (MM-JJ) : date de référence. L'adhésion est
//   conclue pour une année civile (art. 2 des conditions d'adhésion), la
//   résiliation prend effet en fin d'exercice (art. 8 ; FAQ : « pour la fin
//   d'un exercice »). Par défaut 12-31.
// - DESINSCRIPTION_PREAVIS_MOIS : préavis écrit avant la date de référence.
//   3 mois selon la FAQ / les statuts (l'art. 6 des conditions mentionne
//   encore 30 jours — contradiction à corriger dans les conditions).
// - DESINSCRIPTION_ANNEE_SUPP : quand une année supplémentaire est due.
//   'hors_delai' (défaut) : seulement si le préavis n'est pas respecté — la
//   désinscription prend alors effet à la fin de l'exercice suivant.
//   'toujours' : dans tous les cas (désinscription effective à la fin de
//   l'exercice suivant, une année supplémentaire due).
function desinscription_parametres(): array
{
    $finExercice = defined('DESINSCRIPTION_FIN_EXERCICE') ? (string) DESINSCRIPTION_FIN_EXERCICE : '12-31';
    if (!preg_match('/^(\d{2})-(\d{2})$/', $finExercice, $m) || !checkdate((int) $m[1], (int) $m[2], 2001)) {
        $finExercice = '12-31';
    }
    $preavis = defined('DESINSCRIPTION_PREAVIS_MOIS') ? (int) DESINSCRIPTION_PREAVIS_MOIS : 3;
    $mode = defined('DESINSCRIPTION_ANNEE_SUPP') ? (string) DESINSCRIPTION_ANNEE_SUPP : 'hors_delai';
    return [
        'fin_exercice' => $finExercice,
        'preavis_mois' => max(0, $preavis),
        'annee_supp' => in_array($mode, ['hors_delai', 'toujours'], true) ? $mode : 'hors_delai',
    ];
}

/**
 * Soustrait des mois calendaires en ramenant le jour au dernier jour du
 * mois cible si besoin (31 décembre - 3 mois = 30 septembre, et non
 * 1er octobre comme le ferait DateTime::modify('-3 months')).
 */
function soustraire_mois(DateTimeImmutable $date, int $mois): DateTimeImmutable
{
    $annee = (int) $date->format('Y');
    $moisCible = (int) $date->format('n') - $mois;
    while ($moisCible < 1) {
        $moisCible += 12;
        $annee--;
    }
    $jour = min((int) $date->format('j'), cal_days_in_month(CAL_GREGORIAN, $moisCible, $annee));
    return $date->setDate($annee, $moisCible, $jour);
}

/**
 * Fin d'exercice (MM-JJ) pour une année donnée — gère le 29 février d'un
 * exercice paramétré ainsi les années non bissextiles.
 */
function fin_exercice_pour_annee(string $finExercice, int $annee): DateTimeImmutable
{
    [$mois, $jour] = array_map('intval', explode('-', $finExercice));
    $jour = min($jour, cal_days_in_month(CAL_GREGORIAN, $mois, $annee));
    return (new DateTimeImmutable('today'))->setDate($annee, $mois, $jour);
}

/**
 * Calcule les conséquences d'une demande de désinscription reçue à la date
 * $demande (seule la date compte, pas l'heure).
 *
 * - reference : première fin d'exercice >= date de la demande.
 * - limite : dernier jour où la demande respecte le préavis (reference -
 *   preavis_mois). Une demande reçue ce jour-là est dans les délais.
 * - hors_delai : demande reçue après la limite (moins de N mois avant).
 * - effective_le : fin de l'exercice en cours, ou du suivant si une année
 *   supplémentaire est due.
 * - annee_supplementaire : exercice supplémentaire dû (année de
 *   effective_le), ou null.
 */
function calculer_desinscription(DateTimeImmutable $demande, ?array $params = null): array
{
    $params = $params ?? desinscription_parametres();
    $demande = $demande->setTime(0, 0);
    $annee = (int) $demande->format('Y');

    $reference = fin_exercice_pour_annee($params['fin_exercice'], $annee);
    if ($demande > $reference) {
        $reference = fin_exercice_pour_annee($params['fin_exercice'], $annee + 1);
    }
    $limite = soustraire_mois($reference, $params['preavis_mois']);
    $horsDelai = $demande > $limite;
    $anneeSuppDue = $params['annee_supp'] === 'toujours' || $horsDelai;

    $effective = $anneeSuppDue
        ? fin_exercice_pour_annee($params['fin_exercice'], (int) $reference->format('Y') + 1)
        : $reference;

    return [
        'demande_le' => $demande->format('Y-m-d'),
        'reference' => $reference->format('Y-m-d'),
        'limite' => $limite->format('Y-m-d'),
        'hors_delai' => $horsDelai,
        'effective_le' => $effective->format('Y-m-d'),
        'annee_supplementaire' => $anneeSuppDue ? (int) $effective->format('Y') : null,
    ];
}

/**
 * Cotisation annuelle appliquée à l'année supplémentaire due en cas de
 * désinscription hors délai : 300 CHF pour une entreprise, 150 CHF pour une
 * personne physique (surchargeables dans config.php). Le montant est figé
 * dans le dossier au moment de l'enregistrement.
 */
function cotisation_annuelle_chf(?string $accountType): int
{
    return $accountType === 'company'
        ? (defined('COTISATION_ENTREPRISE_CHF') ? (int) COTISATION_ENTREPRISE_CHF : 300)
        : (defined('COTISATION_PERSONNE_CHF') ? (int) COTISATION_PERSONNE_CHF : 150);
}

function format_date_ch(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts === false ? '—' : date('d.m.Y', $ts);
}

// ---------- Statut et actions d'un dossier ----------

function dossier_est_desinscrit(array $d): bool
{
    return !empty($d['desinscription_enregistree_at']);
}

/**
 * Actions proposées dans le menu « Choisir une action » d'une ligne, selon
 * le statut du dossier. Sert aussi de contrôle côté serveur : une action
 * absente de cette liste est refusée, quoi que le formulaire envoie.
 */
function actions_dossier_disponibles(array $d, array $adminEmails): array
{
    $actions = [];
    $statut = (string) $d['statut_admission'];
    $aToken = (string) ($d['payment_token'] ?? '') !== '';
    $desinscrit = dossier_est_desinscrit($d);

    if ($statut === 'en_attente' && $aToken) {
        $actions['accepter'] = 'Accepter la demande';
        $actions['refuser'] = 'Refuser la demande';
    }
    if ($statut === 'accepte' && !$desinscrit) {
        if ($d['paiement_statut'] !== 'paye' && $aToken) {
            $actions['marquer_paye'] = 'Marquer la cotisation payée';
        }
        $actions['desinscrire'] = 'Désinscrire le membre';
    }
    if ($desinscrit) {
        if (($d['annee_supp_statut'] ?? null) === 'due') {
            $actions['marquer_annee_supp_payee'] = 'Marquer l\'année supplémentaire payée';
        }
        if (email_desinscription_peut_etre_envoye($d)) {
            $actions['renvoyer_email_desinscription'] = 'Renvoyer l\'e-mail de désinscription';
        }
    }
    if (!$desinscrit && $statut !== 'refuse' && !in_array((string) $d['email'], $adminEmails, true)) {
        $actions['passer_admin'] = 'Donner un accès admin';
    }
    return $actions;
}

/**
 * Un envoi « en_cours » depuis plus de 10 minutes est considéré comme
 * interrompu (erreur fatale pendant l'envoi) et peut être relancé.
 */
function email_desinscription_peut_etre_envoye(array $d): bool
{
    $statut = $d['desinscription_email_statut'] ?? null;
    if ($statut === 'a_envoyer' || $statut === 'echec') {
        return true;
    }
    if ($statut === 'en_cours') {
        $ts = strtotime((string) ($d['desinscription_email_at'] ?? ''));
        return $ts === false || $ts < time() - 600;
    }
    return false;
}

/**
 * event-swiss.com (source de vérité de la décision) a confirmé : reflète
 * aussi le statut ici sans attendre statut-webhook.php, pour que le dossier
 * change de liste immédiatement même si le webhook échoue. Invalide au
 * passage les liens à token unique de l'e-mail de notification admin.
 */
function appliquer_decision_locale(PDO $pdo, int $membreId, string $statut, bool $migrationOk): void
{
    $params = [':statut' => $statut, ':s2' => $statut, ':id' => $membreId];
    $refuseAt = '';
    if ($migrationOk) {
        $refuseAt = ', refuse_at = IF(:s3 = "refuse", COALESCE(refuse_at, NOW()), refuse_at)';
        $params[':s3'] = $statut;
    }
    $upd = $pdo->prepare(
        'UPDATE membres_inscription SET statut_admission = :statut, accept_token = NULL, refuse_token = NULL,
            accepte_at = IF(:s2 = "accepte", COALESCE(accepte_at, NOW()), accepte_at)' . $refuseAt . '
         WHERE id = :id'
    );
    $upd->execute($params);
}

function migration_archives_appliquee(PDO $pdo): bool
{
    return (bool) $pdo->query("SHOW COLUMNS FROM membres_inscription LIKE 'desinscription_enregistree_at'")->fetch();
}

// ---------- Journal des actions admin ----------

function journaliser_action(PDO $pdo, ?int $adminId, ?int $membreId, string $action, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_journal (admin_id, membre_id, action, details) VALUES (:admin, :membre, :action, :details)');
        $stmt->execute([':admin' => $adminId, ':membre' => $membreId, ':action' => $action, ':details' => $details !== '' ? $details : null]);
    } catch (PDOException $ex) {
        // Le journal ne doit jamais faire échouer l'action elle-même.
        error_log('admin_journal : ' . $ex->getMessage());
    }
}

const JOURNAL_LIBELLES = [
    'accepter' => 'Demande acceptée',
    'refuser' => 'Demande refusée',
    'marquer_paye' => 'Cotisation marquée payée',
    'passer_admin' => 'Accès admin donné',
    'desinscrire' => 'Désinscription enregistrée',
    'marquer_annee_supp_payee' => 'Année supplémentaire marquée payée',
    'email_desinscription_envoye' => 'E-mail de désinscription envoyé',
    'email_desinscription_echec' => 'Échec de l\'e-mail de désinscription',
];

// ---------- Désinscription ----------

/**
 * Enregistre la désinscription d'un membre accepté. Idempotent : la mise à
 * jour ne passe que si aucune désinscription n'est déjà enregistrée, donc un
 * double clic ou un rafraîchissement ne crée jamais une deuxième
 * désinscription. Retourne ['success', 'error', 'calcul'].
 */
function enregistrer_desinscription(PDO $pdo, int $membreId, DateTimeImmutable $demande, int $adminId, ?string $accountType): array
{
    $calcul = calculer_desinscription($demande);
    $montant = $calcul['annee_supplementaire'] !== null ? cotisation_annuelle_chf($accountType) : null;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE membres_inscription SET
                desinscription_demande_le = :demande,
                desinscription_effective_le = :effective,
                desinscription_hors_delai = :hors_delai,
                desinscription_enregistree_at = NOW(),
                annee_supp_annee = :annee_supp,
                annee_supp_statut = :annee_supp_statut,
                annee_supp_montant_chf = :montant,
                desinscription_email_statut = "a_envoyer"
             WHERE id = :id AND statut_admission = "accepte" AND desinscription_enregistree_at IS NULL'
        );
        $stmt->execute([
            ':demande' => $calcul['demande_le'],
            ':effective' => $calcul['effective_le'],
            ':hors_delai' => $calcul['hors_delai'] ? 1 : 0,
            ':annee_supp' => $calcul['annee_supplementaire'],
            ':annee_supp_statut' => $calcul['annee_supplementaire'] !== null ? 'due' : null,
            ':montant' => $montant,
            ':id' => $membreId,
        ]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Désinscription impossible : le membre n\'est pas (ou plus) actif, ou sa désinscription est déjà enregistrée.', 'calcul' => $calcul];
        }
        journaliser_action(
            $pdo,
            $adminId,
            $membreId,
            'desinscrire',
            'Demande reçue le ' . format_date_ch($calcul['demande_le'])
                . ', effective le ' . format_date_ch($calcul['effective_le'])
                . ($calcul['hors_delai'] ? ', hors délai de préavis' : '')
                . ($calcul['annee_supplementaire'] !== null ? ', année ' . $calcul['annee_supplementaire'] . ' due (CHF ' . $montant . ')' : '')
        );
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('enregistrer_desinscription : ' . $ex->getMessage());
        return ['success' => false, 'error' => 'Erreur technique : la désinscription n\'a pas été enregistrée.', 'calcul' => $calcul];
    }
    return ['success' => true, 'error' => null, 'calcul' => $calcul];
}

/**
 * Corps de l'e-mail de confirmation (HTML déjà échappé, dans la langue du
 * membre). Deux variantes : avec ou sans année supplémentaire due.
 */
function contenu_email_desinscription(array $membre): array
{
    $lang = in_array($membre['lang'] ?? 'fr', ['fr', 'de', 'it'], true) ? (string) $membre['lang'] : 'fr';
    $effective = format_date_ch((string) $membre['desinscription_effective_le']);
    $anneeSupp = $membre['annee_supp_annee'] !== null ? (int) $membre['annee_supp_annee'] : null;

    $paragraphes = [
        sprintf(t($lang, 'desinscr_email_hello'), e((string) $membre['prenom'])),
        e(t($lang, 'desinscr_email_recue')),
        e(sprintf(t($lang, 'desinscr_email_effet'), $effective)),
    ];
    if ($anneeSupp !== null) {
        $paragraphes[] = e(sprintf(t($lang, 'desinscr_email_annee_due'), (string) $anneeSupp, (string) (int) $membre['annee_supp_montant_chf']));
    }
    $paragraphes[] = e(t($lang, 'desinscr_email_merci'));

    $html = '';
    foreach ($paragraphes as $p) {
        $html .= '<p style="margin:0 0 14px;">' . $p . '</p>';
    }
    $html .= '<p style="margin:20px 0 0;">' . e(t($lang, 'desinscr_email_salutations')) . '<br>' . e(t($lang, 'desinscr_email_signature')) . '</p>';

    $texte = implode("\n\n", array_map(fn ($p) => html_entity_decode(strip_tags($p), ENT_QUOTES, 'UTF-8'), $paragraphes))
        . "\n\n" . t($lang, 'desinscr_email_salutations') . "\n" . t($lang, 'desinscr_email_signature');

    return ['subject' => t($lang, 'desinscr_email_subject'), 'html' => render_email_html($html), 'text' => $texte];
}

/**
 * Envoie l'e-mail de confirmation de désinscription. Le statut est
 * « réservé » (en_cours) avant l'envoi : deux requêtes simultanées (double
 * clic) ne peuvent pas envoyer deux e-mails. Ne marque « envoye » que si
 * le serveur SMTP a accepté le message.
 */
function envoyer_email_desinscription(PDO $pdo, int $membreId, ?int $adminId): array
{
    $stmt = $pdo->prepare('SELECT * FROM membres_inscription WHERE id = :id');
    $stmt->execute([':id' => $membreId]);
    $membre = $stmt->fetch();
    if (!$membre || !dossier_est_desinscrit($membre)) {
        return ['success' => false, 'error' => 'Dossier introuvable ou désinscription non enregistrée.'];
    }
    if (!email_desinscription_peut_etre_envoye($membre)) {
        return ['success' => false, 'error' => $membre['desinscription_email_statut'] === 'envoye'
            ? 'L\'e-mail de désinscription a déjà été envoyé.'
            : 'Un envoi est déjà en cours pour ce dossier. Réessayez dans quelques minutes.'];
    }

    $reserve = $pdo->prepare(
        'UPDATE membres_inscription SET desinscription_email_statut = "en_cours", desinscription_email_at = NOW()
         WHERE id = :id AND (desinscription_email_statut IN ("a_envoyer", "echec")
            OR (desinscription_email_statut = "en_cours" AND (desinscription_email_at IS NULL OR desinscription_email_at < NOW() - INTERVAL 10 MINUTE)))'
    );
    $reserve->execute([':id' => $membreId]);
    if ($reserve->rowCount() !== 1) {
        return ['success' => false, 'error' => 'Un envoi est déjà en cours pour ce dossier. Réessayez dans quelques minutes.'];
    }

    $contenu = contenu_email_desinscription($membre);
    $nom = trim((string) $membre['prenom'] . ' ' . (string) $membre['nom']);
    try {
        $envoye = send_mail((string) $membre['email'], $nom, $contenu['subject'], $contenu['html'], $contenu['text']);
    } catch (Throwable $ex) {
        error_log('envoyer_email_desinscription : ' . $ex->getMessage());
        $envoye = false;
    }

    $fin = $pdo->prepare('UPDATE membres_inscription SET desinscription_email_statut = :statut, desinscription_email_at = NOW() WHERE id = :id');
    $fin->execute([':statut' => $envoye ? 'envoye' : 'echec', ':id' => $membreId]);
    journaliser_action($pdo, $adminId, $membreId, $envoye ? 'email_desinscription_envoye' : 'email_desinscription_echec', (string) $membre['email']);

    return $envoye
        ? ['success' => true, 'error' => null]
        : ['success' => false, 'error' => 'L\'e-mail de confirmation n\'a pas pu être envoyé à ' . $membre['email'] . '. Vous pouvez réessayer depuis Archives → Désinscriptions.'];
}

// ---------- Traitement d'une action « Choisir une action » + OK ----------

/**
 * Exécute l'action choisie dans le menu d'une ligne (formulaire
 * action=action_dossier de admin.php). Tout est revérifié ici, côté
 * serveur : existence du dossier, action autorisée pour son statut,
 * confirmation explicite, dates. Retourne [notice, type, tab, sub].
 */
function traiter_action_dossier(PDO $pdo, array $admin, array $post, bool $migrationOk): array
{
    $membreId = (int) ($post['membre_id'] ?? 0);
    $choix = (string) ($post['choix'] ?? '');
    $retourTab = in_array($post['retour_tab'] ?? '', ['demandes', 'archives'], true) ? (string) $post['retour_tab'] : 'demandes';
    $retourSub = in_array($post['retour_sub'] ?? '', ['desinscriptions', 'refus'], true) ? (string) $post['retour_sub'] : '';
    $erreur = fn (string $msg) => [$msg, 'error', $retourTab, $retourSub];

    if ($choix === '') {
        return $erreur('Aucune action sélectionnée : rien n\'a été modifié.');
    }
    $stmt = $pdo->prepare('SELECT * FROM membres_inscription WHERE id = :id');
    $stmt->execute([':id' => $membreId]);
    $membre = $stmt->fetch();
    if (!$membre) {
        return $erreur('Dossier introuvable.');
    }
    $adminEmails = $pdo->query('SELECT email FROM admins')->fetchAll(PDO::FETCH_COLUMN);
    $disponibles = actions_dossier_disponibles($membre, $adminEmails);
    if (in_array($choix, ['desinscrire', 'marquer_annee_supp_payee', 'renvoyer_email_desinscription'], true) && !$migrationOk) {
        return $erreur('Action indisponible : la migration SQL des archives n\'a pas encore été appliquée.');
    }
    if (!isset($disponibles[$choix])) {
        return $erreur('Cette action n\'est pas disponible pour ce dossier dans son état actuel (il a peut-être été modifié entre-temps). Rien n\'a été modifié.');
    }
    if ((string) ($post['confirme'] ?? '') !== '1') {
        return $erreur('Confirmation requise : l\'action n\'a pas été exécutée.');
    }

    $nom = trim((string) $membre['prenom'] . ' ' . (string) $membre['nom']);
    $adminId = (int) $admin['id'];

    switch ($choix) {
        case 'accepter':
        case 'refuser':
            $decision = $choix === 'accepter' ? 'approve' : 'reject';
            $result = decider_adhesion_event_swiss((string) $membre['payment_token'], $decision);
            if (!$result['success']) {
                return $erreur((string) $result['error']);
            }
            appliquer_decision_locale($pdo, $membreId, $choix === 'accepter' ? 'accepte' : 'refuse', $migrationOk);
            journaliser_action($pdo, $adminId, $membreId, $choix);
            return $choix === 'accepter'
                ? ['Demande de ' . $nom . ' acceptée.', 'success', 'demandes', '']
                : ['Demande de ' . $nom . ' refusée. Le dossier a été déplacé dans Archives → Refus d\'acceptation.', 'success', 'archives', 'refus'];

        case 'marquer_paye':
            // Les virements bancaires (facture QR-suisse) n'ont pas de webhook
            // automatique comme Stripe — l'équipe confirme la réception ici.
            $factureNumero = 'ORTRA-' . date('Y') . '-' . str_pad((string) $membreId, 5, '0', STR_PAD_LEFT);
            $upd = $pdo->prepare(
                'UPDATE membres_inscription SET paiement_statut = "paye", paiement_confirme_at = NOW(), facture_numero = :facture
                 WHERE id = :id AND paiement_statut <> "paye"'
            );
            $upd->execute([':facture' => $factureNumero, ':id' => $membreId]);
            if ($upd->rowCount() !== 1) {
                return $erreur('Le paiement de ce membre est déjà enregistré.');
            }
            journaliser_action($pdo, $adminId, $membreId, 'marquer_paye', $factureNumero);
            return ['Paiement de ' . $nom . ' marqué comme reçu.', 'success', 'demandes', ''];

        case 'passer_admin':
            // Réutilise le mot de passe déjà choisi par la personne lors de sa
            // demande d'adhésion (même hash bcrypt, copié tel quel — le mot
            // de passe en clair n'est jamais connu ni régénéré) plutôt que
            // d'en imposer un nouveau qu'il faudrait communiquer à part.
            $motDePasseGenere = null;
            $hash = (string) ($membre['password_hash'] ?? '');
            if ($hash === '') {
                $motDePasseGenere = generer_mot_de_passe_admin();
                $hash = password_hash($motDePasseGenere, PASSWORD_DEFAULT);
            }
            try {
                $ins = $pdo->prepare('INSERT INTO admins (email, password_hash, nom) VALUES (:email, :password_hash, :nom)');
                $ins->execute([':email' => (string) $membre['email'], ':password_hash' => $hash, ':nom' => $nom]);
            } catch (PDOException $ex) {
                return $erreur($ex->getCode() === '23000' ? 'Cette personne a déjà un compte admin.' : 'Erreur lors de la création du compte admin.');
            }
            journaliser_action($pdo, $adminId, $membreId, 'passer_admin');
            return [
                $motDePasseGenere === null
                    ? 'Compte admin créé pour ' . $nom . '. Cette personne peut se connecter à admin-connexion.php avec le même e-mail et le même mot de passe que pour son compte membre.'
                    : 'Compte admin créé pour ' . $nom . '. Mot de passe généré : ' . $motDePasseGenere . ' — communiquez-le en toute sécurité à cette personne, il ne sera plus jamais affiché.',
                'success', 'demandes', '',
            ];

        case 'desinscrire':
            $saisie = (string) ($post['date_demande'] ?? '');
            $demande = DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);
            if (!$demande || $demande->format('Y-m-d') !== $saisie) {
                return $erreur('Date de réception de la demande invalide : la désinscription n\'a pas été enregistrée.');
            }
            if ($demande > new DateTimeImmutable('today')) {
                return $erreur('La date de réception de la demande ne peut pas être dans le futur.');
            }
            $inscription = substr((string) $membre['date_inscription'], 0, 10);
            if ($inscription !== '' && $demande->format('Y-m-d') < $inscription) {
                return $erreur('La date de réception de la demande ne peut pas précéder la date d\'inscription (' . format_date_ch($inscription) . ').');
            }
            $resultat = enregistrer_desinscription($pdo, $membreId, $demande, $adminId, $membre['account_type'] ?? null);
            if (!$resultat['success']) {
                return $erreur((string) $resultat['error']);
            }
            $calcul = $resultat['calcul'];
            $message = 'Désinscription de ' . $nom . ' enregistrée : effective le ' . format_date_ch($calcul['effective_le'])
                . ($calcul['annee_supplementaire'] !== null ? ', année supplémentaire ' . $calcul['annee_supplementaire'] . ' due (CHF ' . cotisation_annuelle_chf($membre['account_type'] ?? null) . ').' : ', aucune année supplémentaire due.');
            $envoi = envoyer_email_desinscription($pdo, $membreId, $adminId);
            if (!$envoi['success']) {
                return [$message . ' ATTENTION : ' . $envoi['error'], 'error', 'archives', 'desinscriptions'];
            }
            return [$message . ' L\'e-mail de confirmation a été envoyé à ' . $membre['email'] . '.', 'success', 'archives', 'desinscriptions'];

        case 'marquer_annee_supp_payee':
            $upd = $pdo->prepare('UPDATE membres_inscription SET annee_supp_statut = "paye", annee_supp_paye_at = NOW() WHERE id = :id AND annee_supp_statut = "due"');
            $upd->execute([':id' => $membreId]);
            if ($upd->rowCount() !== 1) {
                return $erreur('Aucune année supplémentaire due à marquer pour ce dossier.');
            }
            journaliser_action($pdo, $adminId, $membreId, 'marquer_annee_supp_payee', 'Année ' . $membre['annee_supp_annee']);
            return ['Année supplémentaire ' . $membre['annee_supp_annee'] . ' de ' . $nom . ' marquée comme payée.', 'success', 'archives', 'desinscriptions'];

        case 'renvoyer_email_desinscription':
            $envoi = envoyer_email_desinscription($pdo, $membreId, $adminId);
            return $envoi['success']
                ? ['E-mail de confirmation de désinscription envoyé à ' . $membre['email'] . '.', 'success', 'archives', 'desinscriptions']
                : [(string) $envoi['error'], 'error', 'archives', 'desinscriptions'];
    }
    return $erreur('Action inconnue.');
}

// ---------- Rendu (admin.php) ----------

/**
 * Menu « Choisir une action » + bouton OK d'une ligne. Les données utiles
 * aux fenêtres de confirmation (assets/js/admin.js) passent par des
 * attributs data-*. Sans JavaScript, le serveur refuse l'action faute de
 * confirmation explicite (champ confirme).
 */
function render_menu_actions(array $d, array $adminEmails, string $retourTab, string $retourSub, bool $migrationOk): void
{
    $actions = actions_dossier_disponibles($d, $adminEmails);
    if (!$migrationOk) {
        unset($actions['desinscrire'], $actions['marquer_annee_supp_payee'], $actions['renvoyer_email_desinscription']);
    }
    if ($actions === []) {
        echo '<span class="admin-card-meta">Aucune action</span>';
        return;
    }
    $id = (int) $d['id'];
    echo '<form method="post" class="row-action" data-nom="' . e(trim((string) $d['prenom'] . ' ' . (string) $d['nom'])) . '"'
        . ' data-email="' . e((string) $d['email']) . '"'
        . ' data-inscription="' . e(substr((string) $d['date_inscription'], 0, 10)) . '"'
        . ' data-annee-supp="' . e((string) ($d['annee_supp_annee'] ?? '')) . '">'
        . '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="action_dossier">'
        . '<input type="hidden" name="membre_id" value="' . $id . '">'
        . '<input type="hidden" name="retour_tab" value="' . e($retourTab) . '">'
        . '<input type="hidden" name="retour_sub" value="' . e($retourSub) . '">'
        . '<input type="hidden" name="confirme" value="">'
        . '<input type="hidden" name="date_demande" value="">'
        . '<select name="choix" aria-label="Choisir une action" id="choix-' . $id . '">'
        . '<option value="">Choisir une action</option>';
    foreach ($actions as $valeur => $libelle) {
        echo '<option value="' . e($valeur) . '">' . e($libelle) . '</option>';
    }
    echo '</select><button type="submit" class="btn btn-secondary btn-xs">OK</button>'
        . '<span class="row-action-error" role="alert" hidden></span></form>';
}

/**
 * Historique d'un dossier archivé : dates clés du dossier puis actions du
 * journal (les actions antérieures à la création du journal n'y figurent
 * pas, d'où les dates reprises du dossier lui-même).
 */
function render_historique(array $entrees, array $d): void
{
    $lignes = ['Demande d\'adhésion : ' . format_date_ch((string) $d['date_inscription'])];
    if (!empty($d['accepte_at'])) {
        $lignes[] = 'Acceptation : ' . format_date_ch((string) $d['accepte_at']);
    }
    if (($d['paiement_statut'] ?? '') === 'paye') {
        $lignes[] = 'Cotisation payée : ' . format_date_ch($d['paiement_confirme_at'] ?? null) . (!empty($d['facture_numero']) ? ' (' . $d['facture_numero'] . ')' : '');
    }
    foreach ($entrees as $j) {
        $lignes[] = format_date_ch((string) $j['created_at']) . ' — ' . (JOURNAL_LIBELLES[$j['action']] ?? $j['action'])
            . (!empty($j['details']) ? ' : ' . $j['details'] : '')
            . (!empty($j['admin_nom']) ? ' (par ' . $j['admin_nom'] . ')' : '');
    }
    echo '<details class="admin-history"><summary>Voir</summary><ul>';
    foreach ($lignes as $ligne) {
        echo '<li>' . e($ligne) . '</li>';
    }
    echo '</ul></details>';
}
