# HapiPay pour PrestaShop

Module de paiement PrestaShop (8 et 9) pour encaisser par mobile money, carte bancaire et PayPal avec HapiPay. L'argent va directement du client au compte opérateur du commerçant : HapiPay ne le touche jamais.

## Fonctionnement

1. Le client choisit HapiPay à la caisse. Le module crée une page de paiement HapiPay à usage unique (`POST /api/v1/liens`, référence `ps-<panier>`, montant, devise, `url_retour`), puis valide la commande « En attente de paiement HapiPay ». Si HapiPay ne répond pas, aucune commande n'est créée.
2. Le client paie sur la page HapiPay, puis revient sur la boutique.
3. HapiPay envoie le webhook signé `paiement.reussi` au contrôleur `webhook` du module : la commande passe à « Paiement accepté », avec le numéro de transaction. Le montant et la devise sont vérifiés.
4. Si le webhook n'est pas encore arrivé au retour du client, le module demande l'état de la page à HapiPay.

Aucun secret dans ce dépôt : chaque commerçant saisit sa propre clé d'API et le secret de son webhook.

## Installation

Zippez le dossier `hapipay/` et installez-le depuis Modules → Gestionnaire de modules → Installer un module. Dans la configuration du module : adresse de l'API, clé d'API (`hpc_test_…` pour les essais), secret du webhook. L'adresse du webhook à déclarer dans l'espace HapiPay est indiquée sur la même page.

HapiPay encaisse des montants entiers : FCFA, ariary et francs congolais conviennent ; en euros ou en dollars, arrondissez vos prix.

## Essais

Testé de bout en bout avec PrestaShop 8.2.8 sur une boutique locale, en mode test : paiement, webhook signé, faux webhook refusé, montant différent laissé en attente avec une note, confirmation au retour du client sans webhook, retour avec une fausse clé ignoré. PrestaShop 9 utilise la même interface de paiement, mais n'a pas encore été essayé.

## Tests

`php tests/run.php` vérifie les signatures. Avec `HAPIPAY_API_URL` et `HAPIPAY_API_KEY` (clé de test), il essaie aussi l'API réelle.

## Licence

AFL-3.0.
