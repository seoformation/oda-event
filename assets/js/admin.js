/**
 * admin.js — Menu « Choisir une action » + OK de admin.php : fenêtre de
 * confirmation avant chaque action, et calcul de la désinscription
 * (préavis, date d'effet, année supplémentaire) affiché AVANT de confirmer.
 *
 * Le calcul reproduit calculer_desinscription() de admin-functions.php pour
 * l'aperçu seulement : le serveur recalcule tout et fait foi.
 */
(function (root) {
  "use strict";

  function pad(n) { return (n < 10 ? "0" : "") + n; }
  function joursDansMois(annee, mois) { return new Date(Date.UTC(annee, mois, 0)).getUTCDate(); }

  // Dates manipulées comme {a, m, j} (pas d'objet Date) : aucun effet de
  // fuseau horaire ni d'heure d'été sur les comparaisons.
  function lireDate(iso) {
    var p = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || "");
    if (!p) return null;
    var d = { a: +p[1], m: +p[2], j: +p[3] };
    if (d.m < 1 || d.m > 12 || d.j < 1 || d.j > joursDansMois(d.a, d.m)) return null;
    return d;
  }
  function iso(d) { return d.a + "-" + pad(d.m) + "-" + pad(d.j); }
  function suisse(d) { return pad(d.j) + "." + pad(d.m) + "." + d.a; }
  function comparer(x, y) { return iso(x) < iso(y) ? -1 : iso(x) > iso(y) ? 1 : 0; }

  function finExercice(finMMJJ, annee) {
    var m = +finMMJJ.slice(0, 2), j = +finMMJJ.slice(3, 5);
    return { a: annee, m: m, j: Math.min(j, joursDansMois(annee, m)) };
  }
  function soustraireMois(d, mois) {
    var a = d.a, m = d.m - mois;
    while (m < 1) { m += 12; a--; }
    return { a: a, m: m, j: Math.min(d.j, joursDansMois(a, m)) };
  }

  function calculer(demandeIso, params) {
    var demande = lireDate(demandeIso);
    if (!demande) return null;
    var reference = finExercice(params.finExercice, demande.a);
    if (comparer(demande, reference) > 0) reference = finExercice(params.finExercice, demande.a + 1);
    var limite = soustraireMois(reference, params.preavisMois);
    var horsDelai = comparer(demande, limite) > 0;
    var anneeSupp = params.anneeSupp === "toujours" || horsDelai;
    var effective = anneeSupp ? finExercice(params.finExercice, reference.a + 1) : reference;
    return {
      demande_le: iso(demande), reference: iso(reference), limite: iso(limite),
      hors_delai: horsDelai, effective_le: iso(effective),
      annee_supplementaire: anneeSupp ? effective.a : null,
      _fr: { reference: suisse(reference), limite: suisse(limite), effective: suisse(effective) }
    };
  }

  root.OrtraDesinscription = { calculer: calculer };
  if (typeof module !== "undefined" && module.exports) module.exports = root.OrtraDesinscription;
  if (typeof document === "undefined") return;

  var dialog = document.getElementById("action-dialog");
  if (!dialog) return;
  var params = {
    finExercice: dialog.dataset.finExercice,
    preavisMois: parseInt(dialog.dataset.preavisMois, 10),
    anneeSupp: dialog.dataset.anneeSupp
  };
  var titre = dialog.querySelector(".admin-dialog-title");
  var message = dialog.querySelector(".admin-dialog-message");
  var blocDesinscription = dialog.querySelector(".admin-dialog-desinscription");
  var champDate = document.getElementById("dialog-date-demande");
  var avertissement = dialog.querySelector(".admin-warning");
  var resume = dialog.querySelector(".admin-dialog-summary");
  var boutonConfirmer = dialog.querySelector(".admin-dialog-confirm");
  var formulaireEnCours = null;

  var TEXTES = {
    accepter: { titre: "Accepter la demande", bouton: "Accepter", msg: function (f) { return "Accepter la demande d'adhésion de " + f.dataset.nom + " ? event-swiss.com sera informé de la décision."; } },
    refuser: { titre: "Refuser la demande", bouton: "Confirmer le refus", danger: true, msg: function (f) { return "Refuser la demande d'adhésion de " + f.dataset.nom + " ? Un e-mail de refus lui sera envoyé et le dossier sera déplacé dans Archives → Refus d'acceptation. Cette décision n'est pas réversible depuis l'admin."; } },
    marquer_paye: { titre: "Cotisation payée", bouton: "Marquer payée", msg: function (f) { return "Marquer la cotisation de " + f.dataset.nom + " comme payée ? À faire uniquement après réception effective du paiement."; } },
    passer_admin: { titre: "Accès admin", bouton: "Donner l'accès", msg: function (f) { return "Donner un accès admin OrTra à " + f.dataset.nom + " (" + f.dataset.email + ") ?"; } },
    marquer_annee_supp_payee: { titre: "Année supplémentaire payée", bouton: "Marquer payée", msg: function (f) { return "Confirmer la réception du paiement de l'année supplémentaire " + f.dataset.anneeSupp + " de " + f.dataset.nom + " ?"; } },
    renvoyer_email_desinscription: { titre: "Renvoyer l'e-mail", bouton: "Renvoyer", msg: function (f) { return "Renvoyer l'e-mail de confirmation de désinscription à " + f.dataset.email + " ? La désinscription elle-même n'est pas modifiée."; } },
    desinscrire: { titre: "Désinscrire un membre", bouton: "Confirmer la désinscription", danger: true, msg: function (f) { return "Enregistrer la désinscription de " + f.dataset.nom + ". Le dossier sera archivé et un e-mail de confirmation lui sera envoyé à " + f.dataset.email + "."; } }
  };

  function majDesinscription() {
    var c = calculer(champDate.value, params);
    resume.innerHTML = "";
    if (!c || champDate.value > dialog.dataset.aujourdhui || (champDate.min && champDate.value < champDate.min)) {
      avertissement.hidden = true;
      boutonConfirmer.disabled = true;
      var li = document.createElement("li");
      li.textContent = "Saisissez une date valide (entre l'inscription et aujourd'hui).";
      resume.appendChild(li);
      return;
    }
    boutonConfirmer.disabled = false;
    avertissement.hidden = !c.hors_delai;
    var lignes = [
      "Date de référence : " + c._fr.reference + " (limite de préavis : " + c._fr.limite + ")",
      c.hors_delai
        ? "Préavis non respecté (moins de " + params.preavisMois + " mois avant la date de référence)."
        : "Préavis respecté (au moins " + params.preavisMois + " mois avant la date de référence).",
      "Désinscription effective le " + c._fr.effective,
      c.annee_supplementaire !== null
        ? "Année supplémentaire due : " + c.annee_supplementaire + " (restera « due » jusqu'à confirmation du paiement)"
        : "Aucune année supplémentaire due"
    ];
    lignes.forEach(function (texte) {
      var li = document.createElement("li");
      li.textContent = texte;
      resume.appendChild(li);
    });
  }
  champDate.addEventListener("input", majDesinscription);

  document.querySelectorAll("form.row-action").forEach(function (form) {
    var erreur = form.querySelector(".row-action-error");
    form.querySelector("select").addEventListener("change", function () { erreur.hidden = true; });
    form.addEventListener("submit", function (ev) {
      var choix = form.elements.choix.value;
      if (form.elements.confirme.value === "1") return; // confirmé : envoi réel
      ev.preventDefault();
      if (!choix || !TEXTES[choix]) {
        erreur.textContent = "Choisissez d'abord une action.";
        erreur.hidden = false;
        return;
      }
      var t = TEXTES[choix];
      formulaireEnCours = form;
      titre.textContent = t.titre;
      message.textContent = t.msg(form);
      boutonConfirmer.textContent = t.bouton;
      boutonConfirmer.classList.toggle("btn-danger", !!t.danger);
      boutonConfirmer.disabled = false;
      blocDesinscription.hidden = choix !== "desinscrire";
      avertissement.hidden = true;
      if (choix === "desinscrire") {
        champDate.max = dialog.dataset.aujourdhui;
        champDate.min = form.dataset.inscription || "";
        champDate.value = dialog.dataset.aujourdhui;
        majDesinscription();
      }
      dialog.returnValue = "";
      dialog.showModal();
    });
  });

  // Retour arrière (cache navigateur) : remet les formulaires à l'état
  // initial pour qu'une ancienne confirmation ne soit jamais réutilisée.
  window.addEventListener("pageshow", function () {
    document.querySelectorAll("form.row-action").forEach(function (form) {
      form.elements.confirme.value = "";
      form.querySelector("button[type=submit]").disabled = false;
    });
  });

  dialog.addEventListener("close", function () {
    var form = formulaireEnCours;
    formulaireEnCours = null;
    // Annuler / Échap : aucune donnée envoyée, rien n'est modifié.
    if (!form || dialog.returnValue !== "confirmer") return;
    if (form.elements.choix.value === "desinscrire") form.elements.date_demande.value = champDate.value;
    form.elements.confirme.value = "1";
    form.querySelector("button[type=submit]").disabled = true; // anti double clic
    form.submit();
  });
})(typeof window !== "undefined" ? window : globalThis);
