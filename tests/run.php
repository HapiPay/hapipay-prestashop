<?php
/**
 * php tests/run.php — sans PrestaShop.
 * Avec HAPIPAY_API_URL et HAPIPAY_API_KEY (clé de test) : essai réel contre une instance HapiPay.
 */
define('HAPIPAY_TESTS', true);
require dirname(__DIR__) . '/hapipay/classes/HapiPayApi.php';

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
$check('signature valide acceptée', HapiPayApi::validSignature("t=$t,v1=$good", $body, $secret));
$check('mauvais secret refusé', !HapiPayApi::validSignature("t=$t,v1=$good", $body, 'autre'));
$check('corps modifié refusé', !HapiPayApi::validSignature("t=$t,v1=$good", $body . ' ', $secret));
$old = $t - 600;
$check('horodatage trop ancien refusé', !HapiPayApi::validSignature("t=$old,v1=" . hash_hmac('sha256', $old . '.' . $body, $secret), $body, $secret));
$check('deux v1 après un changement de secret : l\'un suffit', HapiPayApi::validSignature("t=$t,v1=" . hash_hmac('sha256', $t . '.' . $body, 'nouveau') . ",v1=$good", $body, $secret));
$check('en-tête vide refusé', !HapiPayApi::validSignature('', $body, $secret));
$check('secret non configuré refusé', !HapiPayApi::validSignature("t=$t,v1=$good", $body, ''));

$url = getenv('HAPIPAY_API_URL');
$key = getenv('HAPIPAY_API_KEY');
if ($url && $key) {
    echo "\nAPI réelle : $url\n";
    $api = new HapiPayApi($url, $key);
    $check('clé de test reconnue comme telle', $api->isTest());
    $who = $api->whoami();
    $check('la clé ouvre le compte (' . ($who['ok'] ? $who['data']['name'] : $who['message']) . ')', $who['ok']);
    $link = $api->createLink(['reference' => 'ps-42', 'titre' => 'Commande de la boutique', 'montant' => 15000, 'devise' => 'XAF', 'usage_unique' => true, 'url_retour' => 'http://boutique.test/module/hapipay/validation?id_cart=42', 'langue' => 'fr']);
    $check('page de paiement créée' . ($link['ok'] ? ' : ' . $link['data']['url'] : ' : ' . $link['message']), $link['ok']);
    if ($link['ok']) {
        $state = $api->linkState($link['data']['code']);
        $check('la page est ouverte, au bon montant', $state['ok'] && $state['data']['etat'] === 'ouvert' && $state['data']['montant'] === 15000 && $state['data']['reference'] === 'ps-42');
    }
    $bad = $api->createLink(['reference' => 'ps-43', 'montant' => 100, 'devise' => 'JPY', 'usage_unique' => true]);
    $check('une devise refusée remonte une erreur claire', !$bad['ok'] && $bad['status'] === 422);
    $wrong = (new HapiPayApi($url, 'hpc_test_mauvaise'))->whoami();
    $check('une clé inconnue est refusée (401)', !$wrong['ok'] && $wrong['status'] === 401);
}

echo $failures ? "\n$failures échec(s).\n" : "\nTout est bon.\n";
exit($failures ? 1 : 0);
