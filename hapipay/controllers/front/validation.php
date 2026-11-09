<?php
/**
 * Retour du client après la page HapiPay. Si le webhook n'est pas encore
 * arrivé, on demande l'état du paiement à HapiPay (côté serveur), puis on
 * affiche la confirmation de commande.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class HapiPayValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $order = HapiPay::orderForReference('ps-' . (int) Tools::getValue('id_cart'));
        $key = (string) Tools::getValue('key');

        // La clé secrète du client prouve qu'il s'agit bien du retour de cette commande.
        if (!$order || !hash_equals((string) $order->secure_key, $key)) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }

        if (!$order->hasBeenPaid()) {
            $this->checkWithHapiPay($order);
        }

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => $order->secure_key,
        ]));
    }

    private function checkWithHapiPay(Order $order)
    {
        // Le paiement ?paiement=… de l'adresse de retour n'est qu'une indication : on interroge HapiPay.
        $code = $this->linkCode($order);
        if ($code === null) {
            return;
        }

        $state = $this->module->api()->linkState($code);
        if ($state['ok'] && ($state['data']['etat'] ?? '') === 'paye') {
            $this->module->markPaid($order, [
                'id' => $state['data']['paiement'] ?? $code,
                'montant' => $state['data']['montant'] ?? null,
                'devise' => $state['data']['devise'] ?? null,
            ]);
        }
    }

    /** Code de la page HapiPay, retrouvé dans la note écrite à sa création. */
    private function linkCode(Order $order)
    {
        foreach (Message::getMessagesByOrderId((int) $order->id, true) as $message) {
            if (preg_match('/^HapiPay : page de paiement (\w+) créée/u', (string) $message['message'], $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
