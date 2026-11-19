<?php
/**
 * php tests/run.php — sans PrestaShop.
 * Avec HAPIDITPAY_API_URL et HAPIDITPAY_API_KEY (clé de test) : essai réel contre une instance HapiditPay.
 */
define('HAPIDITPAY_TESTS', true);
require dirname(__DIR__) . '/hapiditpay/classes/HapiditPayApi.php';

$failures = 0;
$check = function ($label, $ok) use (&$failures) {
    echo ($ok ? '  ok      ' : '  ÉCHEC   ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};

echo "Signatures des webhooks\n";
$secret = 'whsec_essai';
$body = '{"evenement":"paiement.reussi","data":{"reference":"ps-12"}}';
$t = time();
$good = hash_hmac('sha256', $t . '.' . $body, $secret);
$check('signature valide acceptée', HapiditPayApi::validSignature("t=$t,v1=$good", $body, $secret));
$check('mauvais secret refusé', !HapiditPayApi::validSignature("t=$t,v1=$good", $body, 'autre'));
$check('corps modifié refusé', !HapiditPayApi::validSignature("t=$t,v1=$good", $body . ' ', $secret));
$old = $t - 600;
$check('horodatage trop ancien refusé', !HapiditPayApi::validSignature("t=$old,v1=" . hash_hmac('sha256', $old . '.' . $body, $secret), $body, $secret));
$check('deux v1 après un changement de secret : l\'un suffit', HapiditPayApi::validSignature("t=$t,v1=" . hash_hmac('sha256', $t . '.' . $body, 'nouveau') . ",v1=$good", $body, $secret));
$check('en-tête vide refusé', !HapiditPayApi::validSignature('', $body, $secret));
$check('secret non configuré refusé', !HapiditPayApi::validSignature("t=$t,v1=$good", $body, ''));

echo "\nMontants écrits pour une personne (FCFA pour XAF et XOF)\n";
$check('XAF écrit FCFA', HapiditPayApi::formatAmount(25000, 'XAF') === "25\u{202F}000 FCFA");
$check('XOF écrit FCFA', HapiditPayApi::formatAmount(1500, 'xof') === "1\u{202F}500 FCFA");
$check('les autres devises gardent leur code', HapiditPayApi::formatAmount(5000, 'MGA') === "5\u{202F}000 MGA");

echo "\nAdresse de l'API\n";
$baseUrl = new ReflectionProperty(HapiditPayApi::class, 'baseUrl');
$check('réglage vide : adresse de production', $baseUrl->getValue(new HapiditPayApi('', 'hdp_test_x')) === HapiditPayApi::DEFAULT_URL);
$check('site déduit de l\'API de production', (new HapiditPayApi('', 'k'))->siteUrl('portail/register') === 'https://hapiditpay.com/portail/register');
$check('site d\'une autre instance', (new HapiditPayApi('https://exemple.test/api/v1/', 'k'))->siteUrl('/portail') === 'https://exemple.test/portail');
$check('barre finale retirée', $baseUrl->getValue(new HapiditPayApi(' https://exemple.test/api/v1/ ', 'hdp_test_x')) === 'https://exemple.test/api/v1');

$url = getenv('HAPIDITPAY_API_URL');
$key = getenv('HAPIDITPAY_API_KEY');
if ($url && $key) {
    echo "\nAPI réelle : $url\n";
    $api = new HapiditPayApi($url, $key);
    $check('clé de test reconnue comme telle', $api->isTest());
    $who = $api->whoami();
    $check('la clé ouvre le compte (' . ($who['ok'] ? $who['data']['name'] : $who['message']) . ')', $who['ok']);
    $link = $api->createLink(['reference' => 'ps-42', 'titre' => 'Commande de la boutique', 'montant' => 15000, 'devise' => 'XAF', 'usage_unique' => true, 'url_retour' => 'http://boutique.test/module/hapiditpay/validation?id_cart=42', 'langue' => 'fr']);
    $check('page de paiement créée' . ($link['ok'] ? ' : ' . $link['data']['url'] : ' : ' . $link['message']), $link['ok']);
    if ($link['ok']) {
        $state = $api->linkState($link['data']['code']);
        $check('la page est ouverte, au bon montant', $state['ok'] && $state['data']['etat'] === 'ouvert' && $state['data']['montant'] === 15000 && $state['data']['reference'] === 'ps-42');
    }
    $bad = $api->createLink(['reference' => 'ps-43', 'montant' => 100, 'devise' => 'JPY', 'usage_unique' => true]);
    $check('une devise refusée remonte une erreur claire', !$bad['ok'] && $bad['status'] === 422);
    $wrong = (new HapiditPayApi($url, 'hdp_test_mauvaise'))->whoami();
    $check('une clé inconnue est refusée (401)', !$wrong['ok'] && $wrong['status'] === 401);
}

echo $failures ? "\n$failures échec(s).\n" : "\nTout est bon.\n";
exit($failures ? 1 : 0);
