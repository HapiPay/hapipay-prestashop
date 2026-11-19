<?php
/**
 * Reprise du module sous son ancien nom (« hapipay », jusqu'à la version
 * 0.1.2), lancée à l'installation du module hapiditpay.
 *
 * Pour PrestaShop, un module renommé est un nouveau module : les scripts du
 * dossier upgrade/ ne passent pas. La reprise se fait donc à l'installation.
 * Seul endroit du code qui nomme l'ancien module : ses réglages, son état de
 * commande, ses commandes et leurs notes sont recopiés sous le nouveau nom,
 * l'ancien est effacé, puis l'ancien module est désinstallé. Rien n'est
 * perdu : clé d'API, secret du webhook, texte de caisse, commandes en attente.
 *
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class HapiditPayUpgrade
{
    /** Nom technique de l'ancien module. */
    const OLD_MODULE = 'hapipay';

    /** Réglages : ancien nom => nouveau nom. */
    const CONFIG = [
        'HAPIPAY_API_URL' => 'HAPIDITPAY_API_URL',
        'HAPIPAY_API_KEY' => 'HAPIDITPAY_API_KEY',
        'HAPIPAY_WEBHOOK_SECRET' => 'HAPIDITPAY_WEBHOOK_SECRET',
        'HAPIPAY_TITLE' => 'HAPIDITPAY_TITLE',
        'HAPIPAY_OS_WAITING' => 'HAPIDITPAY_OS_WAITING',
    ];

    /** Anciennes adresses de l'API de production, remplacées par la nouvelle. */
    const OLD_API_URLS = ['https://pay.scigroup.td/api/v1', 'https://pay.hapiditgroup.co/api/v1'];

    /**
     * Fait la reprise. Renvoie vrai si un ancien webhook était réglé : son
     * adresse change avec le nom du module, le commerçant doit la redéclarer.
     */
    public static function run($newModule)
    {
        $db = Db::getInstance();
        $hadWebhook = false;

        // Réglages : renommés sur place, pour garder les valeurs de chaque boutique.
        foreach (self::CONFIG as $old => $new) {
            $oldRows = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($old) . '\'');
            if (!$oldRows) {
                continue;
            }
            if ($old === 'HAPIPAY_WEBHOOK_SECRET') {
                $hadWebhook = (bool) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($old) . '\' AND `value` <> \'\'');
            }
            $newRows = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($new) . '\'');
            if ($newRows) {
                $db->delete('configuration', '`name` = \'' . pSQL($old) . '\'');
            } else {
                $db->update('configuration', ['name' => pSQL($new)], '`name` = \'' . pSQL($old) . '\'');
            }
        }

        foreach (self::OLD_API_URLS as $url) {
            $db->update('configuration', ['value' => pSQL(HapiditPayApi::DEFAULT_URL)], '`name` = \'HAPIDITPAY_API_URL\' AND TRIM(TRAILING \'/\' FROM `value`) = \'' . pSQL($url) . '\'');
        }
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = REPLACE(`value`, \'HapiPay\', \'HapiditPay\') WHERE `name` = \'HAPIDITPAY_TITLE\'');

        // État « En attente de paiement » : rattaché au nouveau module, nom mis à jour.
        $db->update('order_state', ['module_name' => pSQL($newModule)], '`module_name` = \'' . pSQL(self::OLD_MODULE) . '\'');
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'order_state_lang` SET `name` = REPLACE(`name`, \'HapiPay\', \'HapiditPay\') WHERE `name` LIKE \'%HapiPay%\'');

        // Commandes : retrouvées au webhook et au retour du client par le nom du module.
        $db->update('orders', ['module' => pSQL($newModule)], '`module` = \'' . pSQL(self::OLD_MODULE) . '\'');

        // Note « page de paiement … créée » : le retour du client y relit le code de la page.
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'message` SET `message` = CONCAT(\'HapiditPay\', SUBSTRING(`message`, 8)) WHERE `message` LIKE \'HapiPay : page de paiement %\'');

        Configuration::loadConfiguration();

        // L'ancien module, s'il est encore installé, est désinstallé : tout est repris.
        if (Module::isInstalled(self::OLD_MODULE)) {
            $old = Module::getInstanceByName(self::OLD_MODULE);
            if ($old) {
                $old->uninstall();
            }
        }

        return $hadWebhook;
    }
}
