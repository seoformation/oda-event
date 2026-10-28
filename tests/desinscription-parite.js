// Vérifie que l'aperçu JS (assets/js/admin.js) calcule exactement comme le
// serveur (admin-functions.php), jour par jour sur 6 ans et 4 paramétrages.
//   php tests/desinscription-test.php --export /tmp/php.json
//   node tests/desinscription-parite.js /tmp/php.json
const { calculer } = require("../assets/js/admin.js");
const cas = require(require("path").resolve(process.argv[2]));
let echecs = 0;
for (const { p, r } of cas) {
  const js = calculer(r.demande_le, { finExercice: p.fin_exercice, preavisMois: p.preavis_mois, anneeSupp: p.annee_supp });
  delete js._fr;
  if (JSON.stringify(js) !== JSON.stringify(r)) {
    if (echecs++ < 5) console.log("ÉCART", JSON.stringify(p), "\n  PHP", JSON.stringify(r), "\n  JS ", JSON.stringify(js));
  }
}
console.log(`${cas.length - echecs}/${cas.length} dates identiques PHP/JS`);
process.exit(echecs ? 1 : 0);
