<?php
/**
 * HapiditPay pour PrestaShop : encaissez par mobile money, carte bancaire et
 * PayPal. L'argent arrive directement sur le compte opérateur du commerçant.
 *
 * Parcours : le client choisit HapiditPay, le module crée une page de paiement
 * HapiditPay à usage unique (référence ps-<panier>), valide la commande « en
 * attente de paiement HapiditPay » et y envoie le client. La commande passe à
 * « Paiement accepté » sur le webhook signé, ou au retour du client après
 * vérification auprès de HapiditPay. Jamais sur le seul retour du navigateur.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

define('HAPIDITPAY_PS_VERSION', '0.2.0');

require_once __DIR__ . '/classes/HapiditPayApi.php';
require_once __DIR__ . '/classes/HapiditPayUpgrade.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class HapiditPay extends PaymentModule
{
    public const CONFIG = ['HAPIDITPAY_API_URL', 'HAPIDITPAY_API_KEY', 'HAPIDITPAY_WEBHOOK_SECRET', 'HAPIDITPAY_TITLE'];

    public function __construct()
    {
        $this->name = 'hapiditpay';
        $this->tab = 'payments_gateways';
        $this->version = HAPIDITPAY_PS_VERSION;
        $this->author = 'Hapidit Group';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = 'HapiditPay';
        $this->description = 'Mobile money, carte bancaire et PayPal. L\'argent arrive directement sur votre propre compte opérateur : HapiditPay ne le touche jamais, et ne prend aucune commission.';
        $this->confirmUninstall = 'Désinstaller HapiditPay ? Les commandes déjà passées sont conservées.';
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Module déjà utilisé sous son ancien nom : réglages, état et commandes repris.
        if (HapiditPayUpgrade::run($this->name)) {
            Configuration::updateValue('HAPIDITPAY_WEBHOOK_MOVED', 1);
        }

        return $this->registerHook('paymentOptions')
            && $this->installOrderState()
            && (Configuration::get('HAPIDITPAY_TITLE') !== false || Configuration::updateValue('HAPIDITPAY_TITLE', 'Mobile money ou carte bancaire'))
            && (Configuration::get('HAPIDITPAY_API_URL') !== false || Configuration::updateValue('HAPIDITPAY_API_URL', HapiditPayApi::DEFAULT_URL));
    }

    public function uninstall()
    {
        foreach (self::CONFIG as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName('HAPIDITPAY_WEBHOOK_MOVED');

        // L'état de commande est gardé : des commandes peuvent l'utiliser.
        return parent::uninstall();
    }

    /** État « En attente de paiement HapiditPay », créé une seule fois. */
    private function installOrderState()
    {
        $id = (int) Configuration::get('HAPIDITPAY_OS_WAITING');
        if ($id && Validate::isLoadedObject(new OrderState($id))) {
            return true;
        }

        $state = new OrderState();
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'En attente de paiement HapiditPay';
        }
        $state->module_name = $this->name;
        $state->color = '#047857';
        $state->send_email = false;
        $state->logable = false;
        $state->unremovable = true;
        $state->hidden = false;
        $state->paid = false;

        return $state->add() && Configuration::updateValue('HAPIDITPAY_OS_WAITING', (int) $state->id);
    }

    public function api()
    {
        return new HapiditPayApi(Configuration::get('HAPIDITPAY_API_URL'), Configuration::get('HAPIDITPAY_API_KEY'));
    }

    public function webhookUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'webhook', [], true);
    }

    public function hookPaymentOptions($params)
    {
        // Adresse de l'API vide : celle de production (HapiditPayApi::DEFAULT_URL).
        if (!$this->active || !Configuration::get('HAPIDITPAY_API_KEY')) {
            return [];
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText((string) Configuration::get('HAPIDITPAY_TITLE') ?: 'HapiditPay')
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/hapiditpay.svg'))
            ->setAdditionalInformation('<p>Payez par Airtel Money, Orange Money, MVola, carte bancaire ou PayPal, sur la page de paiement HapiditPay.</p>');

        return [$option];
    }

    /**
     * Confirme le paiement d'une commande, une seule fois, si le montant et la
     * devise confirmés par HapiditPay sont bien ceux de la commande.
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
            $this->note($order, 'HapiditPay : un paiement a été confirmé, mais son montant ou sa devise ne correspond pas à la commande. Vérifiez avant d\'expédier.');

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

        $this->note($order, 'HapiditPay : paiement confirmé (' . (isset($data['operateur']) ? $data['operateur'] . ', ' : '') . ($this->api()->isTest() ? 'simulé, mode test' : 'production') . ').');

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

    /** La commande d'une référence HapiditPay « ps-<panier> ». */
    public static function orderForReference($reference)
    {
        if (!preg_match('/^ps-(\d+)$/', (string) $reference, $m)) {
            return null;
        }

        $order = Order::getByCartId((int) $m[1]);

        return Validate::isLoadedObject($order) && $order->module === 'hapiditpay' ? $order : null;
    }

    /* ----- Réglages ----- */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitHapiditPay')) {
            Configuration::updateValue('HAPIDITPAY_TITLE', trim((string) Tools::getValue('HAPIDITPAY_TITLE')));
            Configuration::updateValue('HAPIDITPAY_API_URL', trim((string) Tools::getValue('HAPIDITPAY_API_URL')));
            foreach (['HAPIDITPAY_API_KEY', 'HAPIDITPAY_WEBHOOK_SECRET'] as $secret) {
                // Champ vide : on garde la valeur déjà enregistrée.
                if (trim((string) Tools::getValue($secret)) !== '') {
                    Configuration::updateValue($secret, trim((string) Tools::getValue($secret)));
                }
            }
            Configuration::deleteByName('HAPIDITPAY_WEBHOOK_MOVED');

            $who = $this->api()->whoami();
            $output .= $who['ok']
                ? $this->displayConfirmation('Clé reconnue pour « ' . ($who['data']['name'] ?? '?') . ' », en mode ' . ($this->api()->isTest() ? 'test' : 'production') . '.')
                : $this->displayError('La clé n\'a pas pu être vérifiée : ' . $who['message']);
        }

        if (Configuration::get('HAPIDITPAY_WEBHOOK_MOVED')) {
            $output .= $this->displayWarning('Le module a changé de nom : l\'adresse de son webhook est désormais ' . $this->webhookUrl() . '. Dans votre espace HapiditPay, menu Développeurs → Webhooks, remplacez l\'ancienne adresse par celle-ci (le secret ne change pas), puis enregistrez cette page. D\'ici là, les commandes sont confirmées au retour du client.');
        }

        return $output . $this->accountLinks() . $this->renderForm();
    }

    /** En tête de la configuration : où créer son compte, où trouver sa clé. */
    private function accountLinks()
    {
        $api = $this->api();
        $register = htmlspecialchars($api->siteUrl('portail/register'), ENT_QUOTES, 'UTF-8');
        $portal = htmlspecialchars($api->siteUrl('portail'), ENT_QUOTES, 'UTF-8');
        $host = htmlspecialchars((string) parse_url($api->siteUrl(), PHP_URL_HOST), ENT_QUOTES, 'UTF-8');

        return '<div class="alert alert-info"><p><strong>Pas encore de compte HapiditPay ?</strong> '
            . '<a href="' . $register . '" target="_blank" rel="noopener">Créez-le sur ' . $host . '</a> : l\'environnement de test est gratuit et illimité. '
            . 'Déjà inscrit ? <a href="' . $portal . '" target="_blank" rel="noopener">Ouvrir mon espace HapiditPay</a> : clés d\'API et webhooks se trouvent dans le menu Développeurs.</p></div>';
    }

    private function renderForm()
    {
        $hasKey = (bool) Configuration::get('HAPIDITPAY_API_KEY');
        $hasSecret = (bool) Configuration::get('HAPIDITPAY_WEBHOOK_SECRET');

        $form = ['form' => [
            'legend' => ['title' => 'HapiditPay', 'icon' => 'icon-cogs'],
            'description' => 'L\'argent de vos ventes arrive directement sur votre propre compte opérateur : HapiditPay ne le touche jamais, et ne prend aucune commission.',
            'input' => [
                ['type' => 'text', 'label' => 'Nom affiché à la caisse', 'name' => 'HAPIDITPAY_TITLE'],
                ['type' => 'text', 'label' => 'Adresse de l\'API', 'name' => 'HAPIDITPAY_API_URL', 'placeholder' => HapiditPayApi::DEFAULT_URL,
                    'desc' => 'Laissez ' . HapiditPayApi::DEFAULT_URL . ', sauf indication contraire de HapiditPay. Elle doit être exactement celle-ci, en HTTPS : une adresse redirigée est refusée.'],
                ['type' => 'password', 'label' => 'Clé d\'API', 'name' => 'HAPIDITPAY_API_KEY',
                    'desc' => ($hasKey ? 'Une clé est enregistrée : laissez vide pour la garder. ' : '') . 'Créée dans votre espace HapiditPay, menu Développeurs → Clés d\'API. Une clé hdp_test_… simule les paiements ; une clé hdp_… encaisse pour de vrai.'],
                ['type' => 'password', 'label' => 'Secret du webhook', 'name' => 'HAPIDITPAY_WEBHOOK_SECRET',
                    'desc' => ($hasSecret ? 'Un secret est enregistré : laissez vide pour le garder. ' : '') . 'Dans votre espace HapiditPay, menu Développeurs → Webhooks, ajoutez cette adresse : ' . $this->webhookUrl() . ' puis copiez ici son secret (bouton « Secret »). C\'est grâce à lui que les commandes passent à « Paiement accepté ».'],
            ],
            'submit' => ['title' => 'Enregistrer'],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitHapiditPay';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = [
            'HAPIDITPAY_TITLE' => Configuration::get('HAPIDITPAY_TITLE'),
            'HAPIDITPAY_API_URL' => Configuration::get('HAPIDITPAY_API_URL') ?: HapiditPayApi::DEFAULT_URL,
            'HAPIDITPAY_API_KEY' => '',
            'HAPIDITPAY_WEBHOOK_SECRET' => '',
        ];

        return $helper->generateForm([$form]);
    }
}
