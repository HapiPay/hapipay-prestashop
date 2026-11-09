<?php
/**
 * HapiPay pour PrestaShop : encaissez par mobile money, carte bancaire et
 * PayPal. L'argent arrive directement sur le compte opérateur du commerçant.
 *
 * Parcours : le client choisit HapiPay, le module crée une page de paiement
 * HapiPay à usage unique (référence ps-<panier>), valide la commande « en
 * attente de paiement HapiPay » et y envoie le client. La commande passe à
 * « Paiement accepté » sur le webhook signé, ou au retour du client après
 * vérification auprès de HapiPay. Jamais sur le seul retour du navigateur.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

define('HAPIPAY_PS_VERSION', '0.1.0');

require_once __DIR__ . '/classes/HapiPayApi.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class HapiPay extends PaymentModule
{
    public const CONFIG = ['HAPIPAY_API_URL', 'HAPIPAY_API_KEY', 'HAPIPAY_WEBHOOK_SECRET', 'HAPIPAY_TITLE'];

    public function __construct()
    {
        $this->name = 'hapipay';
        $this->tab = 'payments_gateways';
        $this->version = HAPIPAY_PS_VERSION;
        $this->author = 'HapiPay';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = 'HapiPay';
        $this->description = 'Mobile money, carte bancaire et PayPal. L\'argent arrive directement sur votre propre compte opérateur : HapiPay ne le touche jamais, et ne prend aucune commission.';
        $this->confirmUninstall = 'Désinstaller HapiPay ? Les commandes déjà passées sont conservées.';
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->installOrderState()
            && Configuration::updateValue('HAPIPAY_TITLE', 'Mobile money ou carte bancaire');
    }

    public function uninstall()
    {
        foreach (self::CONFIG as $key) {
            Configuration::deleteByName($key);
        }

        // L'état de commande est gardé : des commandes peuvent l'utiliser.
        return parent::uninstall();
    }

    /** État « En attente de paiement HapiPay », créé une seule fois. */
    private function installOrderState()
    {
        $id = (int) Configuration::get('HAPIPAY_OS_WAITING');
        if ($id && Validate::isLoadedObject(new OrderState($id))) {
            return true;
        }

        $state = new OrderState();
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'En attente de paiement HapiPay';
        }
        $state->module_name = $this->name;
        $state->color = '#047857';
        $state->send_email = false;
        $state->logable = false;
        $state->unremovable = true;
        $state->hidden = false;
        $state->paid = false;

        return $state->add() && Configuration::updateValue('HAPIPAY_OS_WAITING', (int) $state->id);
    }

    public function api()
    {
        return new HapiPayApi(Configuration::get('HAPIPAY_API_URL'), Configuration::get('HAPIPAY_API_KEY'));
    }

    public function webhookUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'webhook', [], true);
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !Configuration::get('HAPIPAY_API_KEY') || !Configuration::get('HAPIPAY_API_URL')) {
            return [];
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText((string) Configuration::get('HAPIPAY_TITLE') ?: 'HapiPay')
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/hapipay.svg'))
            ->setAdditionalInformation('<p>Payez en toute sécurité avec Airtel Money, Orange Money, MVola, votre carte bancaire ou PayPal, sur la page de paiement HapiPay.</p>');

        return [$option];
    }

    /**
     * Confirme le paiement d'une commande, une seule fois, si le montant et la
     * devise confirmés par HapiPay sont bien ceux de la commande.
     */
    public function markPaid(Order $order, array $data)
    {
        if ($order->hasBeenPaid()) {
            return true; // une notification peut arriver deux fois
        }

        $currency = new Currency((int) $order->id_currency);
        $expected = (int) round((float) $order->total_paid);
        $amountOk = isset($data['montant']) && (int) $data['montant'] === $expected;
        $currencyOk = isset($data['devise']) && strtoupper((string) $data['devise']) === strtoupper($currency->iso_code);

        if (!$amountOk || !$currencyOk) {
            $this->note($order, 'HapiPay : un paiement a été confirmé, mais son montant ou sa devise ne correspond pas à la commande. Vérifiez avant d\'expédier.');

            return false;
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $order, true);
        $history->addWithemail();

        $transaction = isset($data['id']) ? (string) $data['id'] : '';
        if ($transaction !== '') {
            foreach ($order->getOrderPaymentCollection() as $payment) {
                $payment->transaction_id = $transaction;
                $payment->update();
            }
        }

        $this->note($order, 'HapiPay : paiement confirmé (' . (isset($data['operateur']) ? $data['operateur'] . ', ' : '') . ($this->api()->isTest() ? 'simulé, mode test' : 'production') . ').');

        return true;
    }

    /** Note privée sur la commande, visible du commerçant seulement. */
    public function note(Order $order, $text)
    {
        $message = new Message();
        $message->id_order = (int) $order->id;
        $message->id_cart = (int) $order->id_cart;
        $message->id_customer = (int) $order->id_customer;
        $message->message = $text;
        $message->private = 1;
        $message->add();
    }

    /** La commande d'une référence HapiPay « ps-<panier> ». */
    public static function orderForReference($reference)
    {
        if (!preg_match('/^ps-(\d+)$/', (string) $reference, $m)) {
            return null;
        }

        $order = Order::getByCartId((int) $m[1]);

        return Validate::isLoadedObject($order) && $order->module === 'hapipay' ? $order : null;
    }

    /* ----- Réglages ----- */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitHapiPay')) {
            Configuration::updateValue('HAPIPAY_TITLE', trim((string) Tools::getValue('HAPIPAY_TITLE')));
            Configuration::updateValue('HAPIPAY_API_URL', trim((string) Tools::getValue('HAPIPAY_API_URL')));
            foreach (['HAPIPAY_API_KEY', 'HAPIPAY_WEBHOOK_SECRET'] as $secret) {
                // Champ vide : on garde la valeur déjà enregistrée.
                if (trim((string) Tools::getValue($secret)) !== '') {
                    Configuration::updateValue($secret, trim((string) Tools::getValue($secret)));
                }
            }

            $who = $this->api()->whoami();
            $output .= $who['ok']
                ? $this->displayConfirmation('Clé reconnue pour « ' . ($who['data']['name'] ?? '?') . ' », en mode ' . ($this->api()->isTest() ? 'test' : 'production') . '.')
                : $this->displayError('La clé n\'a pas pu être vérifiée : ' . $who['message']);
        }

        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $hasKey = (bool) Configuration::get('HAPIPAY_API_KEY');
        $hasSecret = (bool) Configuration::get('HAPIPAY_WEBHOOK_SECRET');

        $form = ['form' => [
            'legend' => ['title' => 'HapiPay', 'icon' => 'icon-cogs'],
            'description' => 'L\'argent de vos ventes arrive directement sur votre propre compte opérateur : HapiPay ne le touche jamais, et ne prend aucune commission.',
            'input' => [
                ['type' => 'text', 'label' => 'Nom affiché à la caisse', 'name' => 'HAPIPAY_TITLE'],
                ['type' => 'text', 'label' => 'Adresse de l\'API', 'name' => 'HAPIPAY_API_URL', 'placeholder' => 'https://…/api/v1',
                    'desc' => 'Indiquée en tête de la documentation, dans votre espace HapiPay. Elle se termine par /api/v1.'],
                ['type' => 'password', 'label' => 'Clé d\'API', 'name' => 'HAPIPAY_API_KEY',
                    'desc' => ($hasKey ? 'Une clé est enregistrée : laissez vide pour la garder. ' : '') . 'Créée dans votre espace HapiPay, menu Développeurs → Clés d\'API. Une clé hpc_test_… simule les paiements ; une clé hpc_… encaisse pour de vrai.'],
                ['type' => 'password', 'label' => 'Secret du webhook', 'name' => 'HAPIPAY_WEBHOOK_SECRET',
                    'desc' => ($hasSecret ? 'Un secret est enregistré : laissez vide pour le garder. ' : '') . 'Dans votre espace HapiPay, menu Développeurs → Webhooks, ajoutez cette adresse : ' . $this->webhookUrl() . ' puis copiez ici son secret (bouton « Secret »). C\'est grâce à lui que les commandes passent à « Paiement accepté ».'],
            ],
            'submit' => ['title' => 'Enregistrer'],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitHapiPay';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = [
            'HAPIPAY_TITLE' => Configuration::get('HAPIPAY_TITLE'),
            'HAPIPAY_API_URL' => Configuration::get('HAPIPAY_API_URL'),
            'HAPIPAY_API_KEY' => '',
            'HAPIPAY_WEBHOOK_SECRET' => '',
        ];

        return $helper->generateForm([$form]);
    }
}
