<?php
/**
 * Abby Invoicing — synchronise les commandes PrestaShop 8 vers Abby
 * (plateforme agréée) pour la facturation électronique / e-reporting.
 *
 * Auteur : (à compléter)
 * Licence : à définir
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'abbyinvoicing/classes/AbbyApiClient.php';
require_once _PS_MODULE_DIR_ . 'abbyinvoicing/classes/AbbyInvoiceSync.php';

class AbbyInvoicing extends Module
{
    public function __construct()
    {
        $this->name = 'abbyinvoicing';
        $this->tab = 'billing_invoicing';
        $this->version = '1.0.0';
        $this->author = 'Votre nom';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Abby — Facturation électronique');
        $this->description = $this->l('Génère et synchronise les factures via Abby (plateforme agréée) lors du paiement des commandes : Factur-X en B2B, e-reporting en B2C.');
        $this->confirmUninstall = $this->l('Désinstaller le module Abby ? Le mapping des factures émises sera conservé.');
    }

    public function install()
    {
        require_once _PS_MODULE_DIR_ . 'abbyinvoicing/sql/install.php';

        if (!parent::install()
            || !abbyinvoicing_install_sql()
            || !$this->registerHook('actionOrderStatusPostUpdate')
            || !$this->registerHook('actionEmailSendBefore')
            || !$this->registerHook('displayAdminOrderMainBottom')
        ) {
            return false;
        }

        Configuration::updateValue('ABBY_API_KEY', '');
        Configuration::updateValue('ABBY_TEST_MODE', 1);
        Configuration::updateValue('ABBY_FRANCHISE_TVA', 1);
        Configuration::updateValue('ABBY_AUTO_FINALIZE', 0);
        Configuration::updateValue('ABBY_SEND_EMAIL', 1);
        Configuration::updateValue('ABBY_DISABLE_PS_INVOICE', 1);
        Configuration::updateValue('ABBY_MERGE_EMAILS', 1);
        Configuration::updateValue('ABBY_TRIGGER_STATUS', (int) Configuration::get('PS_OS_PAYMENT'));

        // Abby devient le moteur de facturation : on coupe la facture native PrestaShop
        // (plus de numéro ni de PDF PrestaShop attaché aux emails de commande).
        $this->applyNativeInvoiceSetting(true);

        return true;
    }

    public function uninstall()
    {
        require_once _PS_MODULE_DIR_ . 'abbyinvoicing/sql/install.php';
        abbyinvoicing_uninstall_sql();

        // On rétablit la facturation native PrestaShop dans l'état où on l'a trouvée.
        $this->applyNativeInvoiceSetting(false);

        Configuration::deleteByName('ABBY_API_KEY');
        Configuration::deleteByName('ABBY_TEST_MODE');
        Configuration::deleteByName('ABBY_FRANCHISE_TVA');
        Configuration::deleteByName('ABBY_AUTO_FINALIZE');
        Configuration::deleteByName('ABBY_SEND_EMAIL');
        Configuration::deleteByName('ABBY_DISABLE_PS_INVOICE');
        Configuration::deleteByName('ABBY_MERGE_EMAILS');
        Configuration::deleteByName('ABBY_TRIGGER_STATUS');

        return parent::uninstall();
    }

    /* ----------------------------------------------------------------------
     * Hook principal : déclenché à chaque changement de statut de commande.
     * On agit quand la commande passe au statut de déclenchement choisi
     * (par défaut "Paiement accepté").
     * -------------------------------------------------------------------- */
    public function hookActionOrderStatusPostUpdate(array $params)
    {
        if (empty($params['newOrderStatus']) || empty($params['id_order'])) {
            return;
        }

        /** @var OrderState $newState */
        $newState = $params['newOrderStatus'];
        $triggerStatus = (int) Configuration::get('ABBY_TRIGGER_STATUS');

        // Soit le statut exact configuré, soit n'importe quel statut "payé".
        $shouldRun = ((int) $newState->id === $triggerStatus) || (bool) $newState->paid;
        if (!$shouldRun) {
            return;
        }

        $sync = $this->buildSyncService();
        if (!$sync) {
            return;
        }

        $idOrder = (int) $params['id_order'];
        $result = $sync->syncOrder($idOrder);

        // En mode "email fusionné", PrestaShop n'a pas envoyé ses emails natifs
        // (cf. hookActionEmailSendBefore) : c'est ici qu'on envoie l'unique email
        // client. On l'envoie même si la synchro Abby a échoué — sinon le client
        // ne recevrait aucune confirmation (l'email natif ayant été supprimé).
        // En revanche, si la commande était déjà synchronisée ('skipped'), on ne
        // renvoie pas l'email à chaque nouveau passage de statut.
        if ((int) Configuration::get('ABBY_MERGE_EMAILS') && $result['status'] !== 'skipped') {
            $invoice = isset($result['invoice']) ? $result['invoice'] : null;
            $sync->sendConsolidatedEmail($idOrder, $invoice);
        }
    }

    /* ----------------------------------------------------------------------
     * Fusion des emails client : on supprime les emails natifs PrestaShop
     * (confirmation de commande, paiement accepté, lien de téléchargement) ;
     * le module envoie à la place un unique email récapitulatif + facture.
     *
     * Conçu pour les paiements INSTANTANÉS (CB/PayPal) : commande et paiement
     * sont simultanés, donc un seul email après paiement suffit. À ne pas
     * activer si la boutique accepte des paiements différés (virement, chèque).
     * -------------------------------------------------------------------- */
    public function hookActionEmailSendBefore(array $params)
    {
        // Fonctionnalité désactivée -> on laisse partir tous les emails natifs.
        if (!(int) Configuration::get('ABBY_MERGE_EMAILS')) {
            return true;
        }

        // Si le module n'est pas opérationnel (clé API absente), on ne supprime
        // rien : on ne veut pas couper les emails natifs sans pouvoir les remplacer.
        if (!Configuration::get('ABBY_API_KEY')) {
            return true;
        }

        $merged = ['order_conf', 'payment', 'download_product'];
        $template = isset($params['template']) ? $params['template'] : '';

        // Retourner false annule l'envoi de CET email (cf. Mail::send PS 8).
        if (in_array($template, $merged, true)) {
            return false;
        }

        return true;
    }

    /* ----------------------------------------------------------------------
     * Petit encart dans la fiche commande BO : statut de la synchro Abby.
     * -------------------------------------------------------------------- */
    public function hookDisplayAdminOrderMainBottom(array $params)
    {
        $idOrder = (int) $params['id_order'];
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'abby_invoice` WHERE id_order = ' . $idOrder
        );

        $this->context->smarty->assign([
            'abby_row' => $row,
            'abby_resync_url' => $this->context->link->getAdminLink('AdminModules', true) .
                '&configure=' . $this->name . '&abby_resync_order=' . $idOrder,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/order_panel.tpl');
    }

    /* ----------------------------------------------------------------------
     * Page de configuration (clé API, mode test, options).
     * -------------------------------------------------------------------- */
    public function getContent()
    {
        $output = '';

        // Resync manuel depuis la fiche commande
        if (Tools::getValue('abby_resync_order')) {
            $sync = $this->buildSyncService();
            if ($sync) {
                $res = $sync->syncOrder((int) Tools::getValue('abby_resync_order'));
                $output .= $this->displayConfirmation('Resync : ' . $res['status'] . ' — ' . $res['message']);
            }
        }

        if (Tools::isSubmit('submitAbbyConfig')) {
            Configuration::updateValue('ABBY_API_KEY', trim(Tools::getValue('ABBY_API_KEY')));
            Configuration::updateValue('ABBY_TEST_MODE', (int) Tools::getValue('ABBY_TEST_MODE'));
            Configuration::updateValue('ABBY_FRANCHISE_TVA', (int) Tools::getValue('ABBY_FRANCHISE_TVA'));
            Configuration::updateValue('ABBY_AUTO_FINALIZE', (int) Tools::getValue('ABBY_AUTO_FINALIZE'));
            Configuration::updateValue('ABBY_SEND_EMAIL', (int) Tools::getValue('ABBY_SEND_EMAIL'));
            Configuration::updateValue('ABBY_DISABLE_PS_INVOICE', (int) Tools::getValue('ABBY_DISABLE_PS_INVOICE'));
            Configuration::updateValue('ABBY_MERGE_EMAILS', (int) Tools::getValue('ABBY_MERGE_EMAILS'));
            Configuration::updateValue('ABBY_TRIGGER_STATUS', (int) Tools::getValue('ABBY_TRIGGER_STATUS'));

            // Applique l'activation/désactivation de la facture native PrestaShop.
            $this->applyNativeInvoiceSetting((bool) Tools::getValue('ABBY_DISABLE_PS_INVOICE'));

            // Garde-fou : l'envoi au client n'a lieu que sur une facture finalisée.
            if ((int) Tools::getValue('ABBY_SEND_EMAIL') && !(int) Tools::getValue('ABBY_AUTO_FINALIZE')) {
                $output .= $this->displayWarning($this->l('« Envoyer la facture au client » nécessite « Finaliser automatiquement » : sans finalisation, aucune facture n\'est envoyée.'));
            }

            // Garde-fou : la fusion des emails remplace la confirmation native par
            // l'email du module ; sans facture finalisée, le client reçoit la
            // confirmation mais pas le PDF. À réserver aux paiements instantanés.
            if ((int) Tools::getValue('ABBY_MERGE_EMAILS') && !(int) Tools::getValue('ABBY_AUTO_FINALIZE')) {
                $output .= $this->displayWarning($this->l('Email fusionné activé sans finalisation auto : le client recevra la confirmation de commande mais la facture ne sera pas jointe (brouillon). Active aussi « Finaliser automatiquement » pour joindre le PDF.'));
            }

            // Test de connexion
            $client = new AbbyApiClient(Configuration::get('ABBY_API_KEY'));
            $output .= $client->ping()
                ? $this->displayConfirmation($this->l('Réglages enregistrés. Connexion à Abby OK.'))
                : $this->displayWarning($this->l('Réglages enregistrés, mais la clé API ne répond pas (vérifie-la sur my.app-abby.com).'));
        }

        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $statuses = OrderState::getOrderStates((int) $this->context->language->id);

        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->l('Configuration Abby'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Clé API Abby (suk-…)'),
                        'name' => 'ABBY_API_KEY',
                        'desc' => $this->l('À créer dans Abby > Réglages > Intégrations. Stockée côté serveur, ne jamais l\'exposer.'),
                        'size' => 60,
                        'required' => true,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Mode test'),
                        'name' => 'ABBY_TEST_MODE',
                        'desc' => $this->l('Crée des factures de test chez Abby (non comptabilisées) tant que tu valides l\'intégration.'),
                        'values' => $this->switchValues(),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Franchise en base de TVA'),
                        'name' => 'ABBY_FRANCHISE_TVA',
                        'desc' => $this->l('Micro-entreprise non redevable : facture sans TVA + mention art. 293 B du CGI.'),
                        'values' => $this->switchValues(),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Finaliser automatiquement'),
                        'name' => 'ABBY_AUTO_FINALIZE',
                        'desc' => $this->l('Si activé, la facture est émise (Factur-X / e-reporting) dès le paiement. Sinon elle reste en brouillon dans Abby pour relecture.'),
                        'values' => $this->switchValues(),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Envoyer la facture Abby au client'),
                        'name' => 'ABBY_SEND_EMAIL',
                        'desc' => $this->l('Envoie le PDF de la facture Abby au client par email. Nécessite la finalisation automatique (on n\'envoie pas un brouillon).'),
                        'values' => $this->switchValues(),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Désactiver la facture native PrestaShop'),
                        'name' => 'ABBY_DISABLE_PS_INVOICE',
                        'desc' => $this->l('Recommandé : Abby devient le seul moteur de facturation. PrestaShop n\'émet plus de numéro de facture ni de PDF, et n\'attache plus sa facture aux emails de commande.'),
                        'values' => $this->switchValues(),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Fusionner les emails client'),
                        'name' => 'ABBY_MERGE_EMAILS',
                        'desc' => $this->l('Supprime les emails natifs (confirmation de commande, paiement accepté, téléchargement) et envoie à la place UN seul email récapitulatif avec la facture Abby. ⚠ Réservé aux paiements instantanés (CB/PayPal) : à désactiver si la boutique accepte virement/chèque.'),
                        'values' => $this->switchValues(),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Statut déclencheur'),
                        'name' => 'ABBY_TRIGGER_STATUS',
                        'desc' => $this->l('Statut de commande qui déclenche la création de la facture Abby.'),
                        'options' => [
                            'query' => $statuses,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => ['title' => $this->l('Enregistrer')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitAbbyConfig';
        $helper->fields_value = [
            'ABBY_API_KEY' => Configuration::get('ABBY_API_KEY'),
            'ABBY_TEST_MODE' => Configuration::get('ABBY_TEST_MODE'),
            'ABBY_FRANCHISE_TVA' => Configuration::get('ABBY_FRANCHISE_TVA'),
            'ABBY_AUTO_FINALIZE' => Configuration::get('ABBY_AUTO_FINALIZE'),
            'ABBY_SEND_EMAIL' => Configuration::get('ABBY_SEND_EMAIL'),
            'ABBY_DISABLE_PS_INVOICE' => Configuration::get('ABBY_DISABLE_PS_INVOICE'),
            'ABBY_MERGE_EMAILS' => Configuration::get('ABBY_MERGE_EMAILS'),
            'ABBY_TRIGGER_STATUS' => Configuration::get('ABBY_TRIGGER_STATUS'),
        ];

        return $helper->generateForm([$fields_form]);
    }

    private function switchValues()
    {
        return [
            ['id' => 'on', 'value' => 1, 'label' => $this->l('Oui')],
            ['id' => 'off', 'value' => 0, 'label' => $this->l('Non')],
        ];
    }

    /**
     * Instancie le service de synchro avec la config courante.
     * @return AbbyInvoiceSync|null
     */
    private function buildSyncService()
    {
        $apiKey = Configuration::get('ABBY_API_KEY');
        if (!$apiKey) {
            PrestaShopLogger::addLog('AbbyInvoicing : clé API non configurée.', 2);
            return null;
        }

        $client = new AbbyApiClient($apiKey);

        return new AbbyInvoiceSync($client, [
            'test_mode' => (bool) Configuration::get('ABBY_TEST_MODE'),
            'franchise_tva' => (bool) Configuration::get('ABBY_FRANCHISE_TVA'),
            'auto_finalize' => (bool) Configuration::get('ABBY_AUTO_FINALIZE'),
            'send_email' => (bool) Configuration::get('ABBY_SEND_EMAIL'),
            'merge_emails' => (bool) Configuration::get('ABBY_MERGE_EMAILS'),
        ]);
    }

    /**
     * Active ou coupe la facturation native PrestaShop (réglage global `PS_INVOICE`).
     * Quand Abby est le moteur de facturation, PrestaShop ne doit plus générer de
     * numéro de facture ni attacher son PDF aux emails de changement de statut.
     *
     * La valeur d'origine de `PS_INVOICE` est sauvegardée pour pouvoir la restaurer
     * (désactivation du réglage / désinstallation du module).
     *
     * @param bool $disable true = couper la facture native, false = la rétablir.
     */
    private function applyNativeInvoiceSetting($disable)
    {
        if ($disable) {
            // Sauvegarde unique de la valeur marchand avant de couper.
            if (Configuration::get('ABBY_PS_INVOICE_BACKUP') === false) {
                Configuration::updateValue('ABBY_PS_INVOICE_BACKUP', (int) Configuration::get('PS_INVOICE'));
            }
            Configuration::updateValue('PS_INVOICE', 0);
        } else {
            $backup = Configuration::get('ABBY_PS_INVOICE_BACKUP');
            Configuration::updateValue('PS_INVOICE', $backup === false ? 1 : (int) $backup);
            Configuration::deleteByName('ABBY_PS_INVOICE_BACKUP');
        }
    }
}
