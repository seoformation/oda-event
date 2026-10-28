<?php
/**
 * Tests de la logique de désinscription et des actions de dossier
 * (admin-functions.php). Sans base de données :
 *   php tests/desinscription-test.php
 * Code de sortie 0 si tout passe. Le dossier tests/ n'a pas à être mis en
 * ligne (et refuse tout accès web via son .htaccess s'il l'était).
 */

declare(strict_types=1);

require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/../admin-functions.php';

// Dépendances de functions.php nécessaires au rendu de l'e-mail, sans
// charger config.php ni PHPMailer.
if (!function_exists('e')) {
    function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('render_email_html')) {
    function render_email_html(string $body): string { return '<html>' . $body . '</html>'; }
}

$echecs = 0;
$total = 0;
function verifier(string $nom, $obtenu, $attendu): void
{
    global $echecs, $total;
    $total++;
    if ($obtenu !== $attendu) {
        $echecs++;
        echo "ÉCHEC  $nom\n        obtenu : " . var_export($obtenu, true) . "\n        attendu : " . var_export($attendu, true) . "\n";
    }
}
function calc(string $date, array $p = []): array
{
    $params = array_merge(['fin_exercice' => '12-31', 'preavis_mois' => 3, 'annee_supp' => 'hors_delai'], $p);
    return calculer_desinscription(new DateTimeImmutable($date), $params);
}

// --- Règle des trois mois, fin d'exercice au 31 décembre ---
$c = calc('2026-09-30');
verifier('30.09 : limite exacte = dans les délais', $c['hors_delai'], false);
verifier('30.09 : effective fin d\'année', $c['effective_le'], '2026-12-31');
verifier('30.09 : pas d\'année supp.', $c['annee_supplementaire'], null);
verifier('limite = 30.09 (et non 01.10)', $c['limite'], '2026-09-30');

$c = calc('2026-10-01');
verifier('01.10 : hors délai', $c['hors_delai'], true);
verifier('01.10 : effective fin année suivante', $c['effective_le'], '2027-12-31');
verifier('01.10 : année 2027 due', $c['annee_supplementaire'], 2027);

$c = calc('2026-01-01');
verifier('01.01 : dans les délais', [$c['hors_delai'], $c['effective_le']], [false, '2026-12-31']);

$c = calc('2026-12-31');
verifier('31.12 (dernier jour) : hors délai', [$c['hors_delai'], $c['effective_le'], $c['annee_supplementaire']], [true, '2027-12-31', 2027]);

$c = calc('2027-01-01');
verifier('01.01 après changement d\'année : nouvel exercice', [$c['reference'], $c['hors_delai'], $c['effective_le']], ['2027-12-31', false, '2027-12-31']);

$c = calc('2026-10-01 23:59:59');
verifier('l\'heure est ignorée', $c['demande_le'], '2026-10-01');

$c = calc('2028-09-30');
verifier('année bissextile 30.09 dans les délais', $c['hors_delai'], false);

// --- Mode « toujours » ---
$c = calc('2026-03-01', ['annee_supp' => 'toujours']);
verifier('mode toujours : effective année suivante', [$c['hors_delai'], $c['effective_le'], $c['annee_supplementaire']], [false, '2027-12-31', 2027]);

// --- Paramètres isolés : exercice au 30 juin ---
$c = calc('2026-03-30', ['fin_exercice' => '06-30']);
verifier('exercice 30.06 : limite 30.03', [$c['limite'], $c['hors_delai']], ['2026-03-30', false]);
$c = calc('2026-03-31', ['fin_exercice' => '06-30']);
verifier('exercice 30.06 : 31.03 hors délai', [$c['hors_delai'], $c['effective_le']], [true, '2027-06-30']);
$c = calc('2026-07-01', ['fin_exercice' => '06-30']);
verifier('exercice 30.06 : 01.07 => exercice suivant', [$c['reference'], $c['hors_delai']], ['2027-06-30', false]);

// --- Arithmétique des mois aux dates limites ---
verifier('31.05 - 1 mois', soustraire_mois(new DateTimeImmutable('2026-05-31'), 1)->format('Y-m-d'), '2026-04-30');
verifier('31.05.2024 - 3 mois (bissextile)', soustraire_mois(new DateTimeImmutable('2024-05-31'), 3)->format('Y-m-d'), '2024-02-29');
verifier('28.02 - 3 mois (changement d\'année)', soustraire_mois(new DateTimeImmutable('2026-02-28'), 3)->format('Y-m-d'), '2025-11-28');
verifier('fin d\'exercice 29.02 une année non bissextile', fin_exercice_pour_annee('02-29', 2027)->format('Y-m-d'), '2027-02-28');

// --- Actions selon le statut ---
$base = ['id' => 1, 'email' => 'a@b.ch', 'payment_token' => 'tok', 'paiement_statut' => 'non_paye',
    'desinscription_enregistree_at' => null, 'annee_supp_statut' => null, 'desinscription_email_statut' => null, 'desinscription_email_at' => null];
$k = fn (array $d, array $admins = []) => array_keys(actions_dossier_disponibles($d, $admins));
verifier('en attente', $k($base + ['statut_admission' => 'en_attente']), ['accepter', 'refuser', 'passer_admin']);
verifier('en attente sans token', $k(['payment_token' => ''] + $base + ['statut_admission' => 'en_attente']), ['passer_admin']);
verifier('accepté non payé', $k($base + ['statut_admission' => 'accepte']), ['marquer_paye', 'desinscrire', 'passer_admin']);
verifier('accepté payé, déjà admin', $k(['paiement_statut' => 'paye'] + $base + ['statut_admission' => 'accepte'], ['a@b.ch']), ['desinscrire']);
verifier('refusé : aucune action', $k($base + ['statut_admission' => 'refuse']), []);
$desinscrit = ['desinscription_enregistree_at' => '2026-10-28 10:00:00', 'annee_supp_statut' => 'due', 'desinscription_email_statut' => 'echec'] + $base + ['statut_admission' => 'accepte'];
verifier('désinscrit, année due, e-mail en échec', $k($desinscrit), ['marquer_annee_supp_payee', 'renvoyer_email_desinscription']);
verifier('désinscrit, tout réglé', $k(['annee_supp_statut' => 'paye', 'desinscription_email_statut' => 'envoye'] + $desinscrit), []);
verifier('pas de 2e désinscription', in_array('desinscrire', $k($desinscrit), true), false);

// --- Renvoi d'e-mail : réservation « en_cours » ---
verifier('en_cours récent : pas de renvoi', email_desinscription_peut_etre_envoye(['desinscription_email_statut' => 'en_cours', 'desinscription_email_at' => date('Y-m-d H:i:s')]), false);
verifier('en_cours bloqué > 10 min : renvoi possible', email_desinscription_peut_etre_envoye(['desinscription_email_statut' => 'en_cours', 'desinscription_email_at' => date('Y-m-d H:i:s', time() - 900)]), true);
verifier('envoyé : pas de renvoi', email_desinscription_peut_etre_envoye(['desinscription_email_statut' => 'envoye']), false);

// --- Montant de l'année supplémentaire ---
verifier('entreprise : 300 CHF', cotisation_annuelle_chf('company'), 300);
verifier('personne physique : 150 CHF', cotisation_annuelle_chf('private'), 150);
verifier('type inconnu : tarif personne physique', cotisation_annuelle_chf(null), 150);

// --- Contenu de l'e-mail ---
$membre = ['prenom' => 'Léa <b>', 'nom' => 'X', 'lang' => 'fr', 'desinscription_effective_le' => '2027-12-31', 'annee_supp_annee' => 2027, 'annee_supp_montant_chf' => 150];
$mail = contenu_email_desinscription($membre);
verifier('objet', $mail['subject'], 'Confirmation de votre demande de désinscription');
verifier('prénom personnalisé et échappé', str_contains($mail['html'], 'Bonjour Léa &lt;b&gt;,'), true);
verifier('date d\'effet', str_contains($mail['html'], 'prendra effet le 31.12.2027'), true);
verifier('année supplémentaire mentionnée', str_contains($mail['text'], "une année supplémentaire (cotisation 2027 : CHF 150) reste due"), true);
$mail = contenu_email_desinscription(['annee_supp_annee' => null] + $membre);
verifier('sans année due : pas de mention', str_contains($mail['text'], 'reste due'), false);
$mail = contenu_email_desinscription(['lang' => 'de'] + $membre);
verifier('e-mail en allemand', $mail['subject'], 'Bestätigung Ihrer Austrittserklärung');

// --- Export pour le test de parité JS (tests/desinscription-parite.js) ---
if (($argv[1] ?? '') === '--export') {
    $out = [];
    foreach ([['12-31', 3, 'hors_delai'], ['12-31', 3, 'toujours'], ['06-30', 3, 'hors_delai'], ['12-31', 1, 'hors_delai']] as [$fin, $preavis, $mode]) {
        $p = ['fin_exercice' => $fin, 'preavis_mois' => $preavis, 'annee_supp' => $mode];
        for ($d = new DateTimeImmutable('2024-01-01'); $d <= new DateTimeImmutable('2029-12-31'); $d = $d->modify('+1 day')) {
            $out[] = ['p' => $p, 'r' => calculer_desinscription($d, $p)];
        }
    }
    file_put_contents($argv[2], json_encode($out));
}

echo ($total - $echecs) . "/$total tests réussis\n";
exit($echecs === 0 ? 0 : 1);
