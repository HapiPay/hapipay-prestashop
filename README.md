# HapiditPay pour PrestaShop

Module de paiement PrestaShop (8 et 9) pour encaisser par mobile money, carte bancaire et PayPal avec HapiditPay. L'argent va directement du client au compte opérateur du commerçant : HapiditPay ne le touche jamais.

## Fonctionnement

1. Le client choisit HapiditPay à la caisse. Le module crée une page de paiement HapiditPay à usage unique (`POST /api/v1/liens`, référence `ps-<panier>`, montant, devise, `url_retour`), puis valide la commande « En attente de paiement HapiditPay ». Si HapiditPay ne répond pas, aucune commande n'est créée.
2. Le client paie sur la page HapiditPay, puis revient sur la boutique.
3. HapiditPay envoie le webhook signé `paiement.reussi` au contrôleur `webhook` du module : la commande passe à « Paiement accepté », avec le numéro de transaction. Le montant et la devise sont vérifiés.
4. Si le webhook n'est pas encore arrivé au retour du client, le module demande l'état de la page à HapiditPay.

Aucun secret dans ce dépôt : chaque commerçant saisit sa propre clé d'API et le secret de son webhook.

## Installation

Zippez le dossier `hapiditpay/` et installez-le depuis Modules → Gestionnaire de modules → Installer un module. Dans la configuration du module : adresse de l'API (par défaut `https://hapiditpay.com/api/v1`, sans redirection), clé d'API (`hdp_test_…` pour les essais), secret du webhook. L'adresse du webhook à déclarer dans l'espace HapiditPay est indiquée sur la même page.

HapiditPay encaisse des montants entiers : FCFA, ariary et francs congolais conviennent ; en euros ou en dollars, arrondissez vos prix.

## Essais

Testé de bout en bout avec PrestaShop 8.2.8 sur une boutique locale, en mode test : paiement, webhook signé, faux webhook refusé, montant différent laissé en attente avec une note, confirmation au retour du client sans webhook, retour avec une fausse clé ignoré. PrestaShop 9 utilise la même interface de paiement, mais n'a pas encore été essayé.

## Tests

`php tests/run.php` vérifie les signatures. Avec `HAPIDITPAY_API_URL` et `HAPIDITPAY_API_KEY` (clé de test), il essaie aussi l'API réelle.

## Versions

- **0.2.1** : retrait du code de reprise des réglages d'une version antérieure : une installation précédente se reconfigure depuis la page de réglages.
- **0.2.0** : changement de nom : le module s'appelle désormais HapiditPay. L'adresse du webhook change avec le nom du module : la page de configuration l'indique, à déclarer dans l'espace HapiditPay. Adresse de l'API de production : `https://hapiditpay.com/api/v1`, clés `hdp_…` et `hdp_test_…`, en-tête `HapiditPay-Signature`.
- **0.1.2** : la page de configuration renvoie vers l'inscription et vers l'espace HapiditPay (clés d'API, webhooks) ; texte de caisse plus direct. Testé avec PrestaShop 8.2.9.
- **0.1.1** : adresse de l'API de production proposée par défaut (`https://hapiditpay.com/api/v1`, un réglage vide l'utilise) ; une redirection de l'API n'est jamais suivie et est signalée avec l'adresse à indiquer ; montants des notes de commande écrits comme sur HapiditPay (FCFA pour XAF et XOF).
- **0.1.0** : première version.

## Licence

AFL-3.0.
