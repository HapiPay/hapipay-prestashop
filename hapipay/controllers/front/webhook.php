<?php
/**
 * Réception des webhooks HapiPay. La signature HapiPay-Signature tient lieu
 * d'authentification : sans elle, rien n'est modifié.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class HapiPayWebhookModuleFrontController extends ModuleFrontController
{
    /** Appel de serveur à serveur : ni session client, ni redirection. */
    public $ajax = true;

    public function postProcess()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->reply(405, ['message' => 'POST attendu.']);
        }

        $raw = (string) file_get_contents('php://input');
        $header = isset($_SERVER['HTTP_HAPIPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_HAPIPAY_SIGNATURE'] : '';

        if (!HapiPayApi::validSignature($header, $raw, (string) Configuration::get('HAPIPAY_WEBHOOK_SECRET'))) {
            $this->reply(401, ['message' => 'Signature invalide.']);
        }

        $event = json_decode($raw, true);
        $name = is_array($event) && isset($event['evenement']) ? (string) $event['evenement'] : '';
        $data = is_array($event) && isset($event['data']) && is_array($event['data']) ? $event['data'] : [];

        if ($name === 'test.ping') {
            $this->reply(200, ['ok' => true]);
        }

        $order = HapiPay::orderForReference($data['reference'] ?? '');
        if (!$order) {
            // Pas une commande de cette boutique : on accuse réception pour éviter les relances.
            $this->reply(200, ['ignore' => true]);
        }

        switch ($name) {
            case 'paiement.reussi':
                $this->module->markPaid($order, $data);
                break;
            case 'paiement.echoue':
                $this->module->note($order, 'HapiPay : le paiement a échoué. Le client peut réessayer.');
                break;
            case 'paiement.expire':
                $this->module->note($order, 'HapiPay : le client n\'a pas validé à temps, rien n\'a été débité.');
                break;
            case 'paiement.a_verifier':
                $this->module->note($order, 'HapiPay : l\'opérateur n\'a pas répondu clairement. Le paiement est à vérifier dans votre espace HapiPay avant d\'expédier.');
                break;
            case 'remboursement.reussi':
                $this->module->note($order, 'HapiPay : remboursement de ' . (int) ($data['montant'] ?? 0) . ' ' . ($data['devise'] ?? '') . ' effectué par l\'opérateur.');
                break;
        }

        $this->reply(200, ['ok' => true]);
    }

    private function reply($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
        exit;
    }
}
