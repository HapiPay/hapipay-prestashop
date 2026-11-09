<?php
/**
 * Validation de la commande : crée la page de paiement HapiPay, puis la
 * commande « en attente de paiement HapiPay », et envoie le client payer.
 * La page est créée d'abord : si HapiPay ne répond pas, aucune commande
 * orpheline n'est créée.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class HapiPayRedirectModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$cart->id || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice || !$this->module->active) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $currency = new Currency((int) $cart->id_currency);
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $amount = (int) round($total);

        // HapiPay compte en unités entières de la devise (FCFA, ariary, francs congolais…).
        if (abs($total - $amount) > 0.001) {
            $this->fail('Ce montant comporte des centimes : HapiPay n\'accepte que des montants entiers. Choisissez un autre moyen de paiement.');
        }

        $link = $this->module->api()->createLink([
            'reference' => 'ps-' . (int) $cart->id,
            'titre' => 'Commande de ' . Configuration::get('PS_SHOP_NAME'),
            'description' => Configuration::get('PS_SHOP_NAME'),
            'montant' => $amount,
            'devise' => $currency->iso_code,
            'usage_unique' => true,
            'url_retour' => $this->context->link->getModuleLink('hapipay', 'validation', ['id_cart' => (int) $cart->id, 'key' => $customer->secure_key], true),
            'langue' => in_array($this->context->language->iso_code, ['fr', 'en', 'ar', 'mg', 'es'], true) ? $this->context->language->iso_code : null,
        ]);

        if (!$link['ok'] || empty($link['data']['url'])) {
            PrestaShopLogger::addLog('HapiPay : page de paiement non créée (' . $link['message'] . ')', 3, null, 'Cart', (int) $cart->id);
            $this->fail('Le paiement HapiPay est momentanément indisponible. Réessayez dans un instant, ou choisissez un autre moyen de paiement.');
        }

        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('HAPIPAY_OS_WAITING'),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        $order = new Order((int) $this->module->currentOrder);
        $this->module->note($order, 'HapiPay : page de paiement ' . $link['data']['code'] . ' créée (mode ' . ($this->module->api()->isTest() ? 'test' : 'production') . '), en attente du paiement.');

        Tools::redirect($link['data']['url']);
    }

    private function fail($message)
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }
}
