<?php
/**
 * Orchestration : transforme une commande PrestaShop en facture Abby.
 *
 * Flux :
 *   1. Idempotence : si la commande a déjà une facture Abby, on s'arrête.
 *   2. Client : B2B (SIRET/TVA présents) -> organisation ; sinon B2C -> contact.
 *   3. Facture : création brouillon -> push des lignes (en centimes) ->
 *      mentions légales (franchise en base) -> finalisation (= émission /
 *      e-reporting via la plateforme agréée Abby).
 *   4. Persistance du mapping commande <-> facture.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AbbyInvoiceSync
{
    /** @var AbbyApiClient */
    private $api;

    /** @var array Réglages issus de la config du module */
    private $settings;

    public function __construct(AbbyApiClient $api, array $settings)
    {
        $this->api = $api;
        $this->settings = $settings;
    }

    /**
     * Point d'entrée : synchronise une commande PrestaShop vers Abby.
     *
     * @param int $idOrder
     * @return array ['status' => 'created'|'skipped'|'error', 'invoice_id' => ?string, 'message' => string]
     */
    public function syncOrder($idOrder)
    {
        $idOrder = (int) $idOrder;

        // 1. Idempotence
        $existing = $this->findMapping($idOrder);
        if ($existing && in_array($existing['status'], ['created', 'finalized'], true)) {
            return ['status' => 'skipped', 'invoice_id' => $existing['abby_invoice_id'], 'message' => 'Déjà synchronisée.'];
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return ['status' => 'error', 'invoice_id' => null, 'message' => 'Commande introuvable.'];
        }

        try {
            $customer = new Customer((int) $order->id_customer);
            $invoiceAddress = new Address((int) $order->id_address_invoice);

            // 2. Client Abby (création à chaque fois ici ; en prod, mémoriser le
            //    contactId Abby sur le client PrestaShop pour éviter les doublons).
            $isB2B = $this->isBusiness($invoiceAddress);
            $customerId = $isB2B
                ? $this->ensureOrganization($customer, $invoiceAddress)
                : $this->ensureContact($customer, $invoiceAddress);

            // 3a. Brouillon de facture
            $invoice = $this->api->createInvoiceForCustomer($customerId, [
                'locale' => 'fr',
                'currencyCode' => 'EUR',
                'test' => (bool) $this->settings['test_mode'],
            ]);
            $invoiceId = isset($invoice['id']) ? $invoice['id'] : null;
            if (!$invoiceId) {
                throw new AbbyApiException('Réponse Abby sans id de facture.', 0);
            }

            $this->saveMapping($idOrder, $invoiceId, 'created', null);

            // 3b. Lignes
            $lines = $this->buildLines($order);
            $this->api->updateLines($invoiceId, $lines);

            // 3c. Mentions légales (franchise en base -> art. 293 B du CGI)
            if ((bool) $this->settings['franchise_tva']) {
                $this->api->updateInvoiceGeneralInformations($invoiceId, [
                    // vatMention : valeur d'énum côté Abby correspondant à "TVA non applicable,
                    // art. 293 B du CGI". À mapper précisément depuis la doc Abby (enum VatMention).
                    'footerNote' => 'TVA non applicable, art. 293 B du CGI',
                    'paymentMethods' => ['credit_card'],
                ]);
            }

            // 3d. Finalisation (optionnelle : selon que tu veux émettre auto ou relire avant)
            if ((bool) $this->settings['auto_finalize']) {
                $finalized = $this->api->finalizeInvoice($invoiceId);
                $number = isset($finalized['number']) ? $finalized['number'] : null;
                $this->saveMapping($idOrder, $invoiceId, 'finalized', $number);

                // 3e. Email facture séparé : uniquement en mode NON fusionné. En mode
                // fusionné, c'est le module (hookActionOrderStatusPostUpdate) qui envoie
                // l'unique email récapitulatif, pour pouvoir aussi gérer le cas d'échec.
                if (!empty($this->settings['send_email']) && empty($this->settings['merge_emails'])) {
                    $this->sendConsolidatedEmail($idOrder, $finalized);
                }

                return ['status' => 'created', 'invoice_id' => $invoiceId, 'invoice' => $finalized, 'message' => 'Facture finalisée ' . ($number ?: '')];
            }

            return ['status' => 'created', 'invoice_id' => $invoiceId, 'invoice' => null, 'message' => 'Facture brouillon créée.'];
        } catch (AbbyApiException $e) {
            $this->saveMapping($idOrder, null, 'error', null, $e->getMessage());
            PrestaShopLogger::addLog(
                'AbbyInvoicing - commande ' . $idOrder . ' : ' . $e->getMessage()
                    . ' | réponse Abby : ' . Tools::substr((string) $e->getRawResponse(), 0, 500),
                3,
                $e->getHttpCode(),
                'Order',
                $idOrder
            );
            return ['status' => 'error', 'invoice_id' => null, 'invoice' => null, 'message' => $e->getMessage()];
        }
    }

    /* ----------------------------------------------------------------------
     * Email client unique (récapitulatif de commande + facture Abby)
     * -------------------------------------------------------------------- */

    /**
     * Envoie au client l'unique email récapitulatif : confirmation de commande
     * (lignes, totaux, adresses, droit de rétractation via le template) + lien de
     * téléchargement le cas échéant + facture Abby en pièce jointe si disponible.
     *
     * Remplace les emails natifs PrestaShop quand la fusion est active. Conçu pour
     * être appelé même si la synchro Abby a échoué ($invoice = null) : le client
     * reçoit alors la confirmation de commande sans la facture, jamais rien.
     *
     * Ne lève pas d'exception : un échec d'email ne doit pas casser le flux de
     * commande (il est seulement logué).
     *
     * @param int        $idOrder
     * @param array|null $invoice Facture Abby finalisée (contient id, number) ou null
     * @return bool true si l'email est parti
     */
    public function sendConsolidatedEmail($idOrder, array $invoice = null)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $customer = new Customer((int) $order->id_customer);
        $idLang = (int) $order->id_lang;
        $blocks = $this->buildOrderBlocks($order);

        // Pièce jointe : on ne joint le PDF que si la facture existe ET se télécharge.
        $attachment = null;
        $invoiceNumber = '';
        if ($invoice !== null && !empty($invoice['id'])) {
            $invoiceNumber = isset($invoice['number']) ? (string) $invoice['number'] : '';
            try {
                $pdf = $this->api->downloadBilling($invoice['id']);
                $label = $invoiceNumber !== '' ? $invoiceNumber : (string) $invoice['id'];
                $fileName = 'Facture-' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) . '.pdf';
                $attachment = ['content' => $pdf, 'name' => $fileName, 'mime' => 'application/pdf'];
            } catch (AbbyApiException $e) {
                PrestaShopLogger::addLog(
                    'AbbyInvoicing - PDF non joint à l\'email (commande ' . (int) $idOrder . ') : ' . $e->getMessage(),
                    2
                );
            }
        }

        if ($attachment !== null) {
            $suffix = $invoiceNumber !== '' ? ' n° ' . $invoiceNumber : '';
            $invoiceLineHtml = 'Votre facture' . htmlspecialchars($suffix, ENT_QUOTES, 'UTF-8') . ' est jointe à cet email (PDF).';
            $invoiceLineText = 'Votre facture' . $suffix . ' est jointe à cet email (PDF).';
            $subject = Mail::l('Votre commande et votre facture', $idLang);
        } else {
            $invoiceLineHtml = $invoiceLineText = 'Votre facture vous sera transmise séparément.';
            $subject = Mail::l('Confirmation de votre commande', $idLang);
        }

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
            '{order_reference}' => $order->reference,
            '{invoice_line_html}' => $invoiceLineHtml,
            '{invoice_line_text}' => $invoiceLineText,
            '{order_details_html}' => $blocks['details_html'],
            '{order_details_text}' => $blocks['details_text'],
            '{invoice_address_html}' => $blocks['invoice_address_html'],
            '{invoice_address_text}' => $blocks['invoice_address_text'],
            '{delivery_address_html}' => $blocks['delivery_address_html'],
            '{delivery_address_text}' => $blocks['delivery_address_text'],
            '{download_block_html}' => $blocks['download_html'],
            '{download_block_text}' => $blocks['download_text'],
            '{order_link}' => $blocks['order_link'],
        ];

        $sent = Mail::Send(
            $idLang,
            'abby_invoice',                                   // mails/<iso>/abby_invoice.(html|txt)
            $subject,
            $templateVars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,                                             // from
            null,                                             // from_name
            $attachment,                                      // null => pas de pièce jointe
            null,                                             // mode_smtp
            _PS_MODULE_DIR_ . 'abbyinvoicing/mails/'          // template_path
        );

        if (!$sent) {
            PrestaShopLogger::addLog(
                'AbbyInvoicing - échec de l\'envoi de l\'email client (commande ' . (int) $idOrder . ').',
                3
            );
        }

        return (bool) $sent;
    }

    /**
     * Construit les blocs réutilisés dans l'email (HTML et texte) : tableau des
     * articles + totaux, adresses, lien commande, bloc téléchargement.
     *
     * @param Order $order
     * @return array
     */
    private function buildOrderBlocks(Order $order)
    {
        $idLang = (int) $order->id_lang;
        $currency = new Currency((int) $order->id_currency);
        $link = Context::getContext()->link;

        $rowsHtml = '';
        $rowsText = '';
        $downloads = [];

        foreach ($order->getProducts() as $p) {
            $name = (string) $p['product_name'];
            $ref = isset($p['product_reference']) ? (string) $p['product_reference'] : '';
            $qty = (int) $p['product_quantity'];
            $unit = (float) $p['unit_price_tax_incl'];
            $total = $unit * $qty;

            $nameHtml = htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                . ($ref !== '' ? ' <span style="color:#999;">(' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . ')</span>' : '');

            $rowsHtml .= '<tr>'
                . '<td style="padding:8px 0; border-bottom:1px solid #eee;">' . $nameHtml . '</td>'
                . '<td style="padding:8px 0; border-bottom:1px solid #eee; text-align:center;">' . $qty . '</td>'
                . '<td style="padding:8px 0; border-bottom:1px solid #eee; text-align:right;">' . Tools::displayPrice($unit, $currency) . '</td>'
                . '<td style="padding:8px 0; border-bottom:1px solid #eee; text-align:right;">' . Tools::displayPrice($total, $currency) . '</td>'
                . '</tr>';

            $rowsText .= $qty . ' x ' . $name . ($ref !== '' ? ' (' . $ref . ')' : '')
                . ' — ' . Tools::displayPrice($total, $currency) . "\n";

            // Lien de téléchargement direct (produit virtuel avec fichier).
            $download = $this->buildDownloadLink($p);
            if ($download !== null) {
                $downloads[] = $download;
            }
        }

        $totProducts = (float) $order->total_products_wt;
        $totShipping = (float) $order->total_shipping_tax_incl;
        $totPaid = (float) $order->total_paid_tax_incl;

        $detailsHtml = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px;">'
            . '<thead><tr>'
            . '<th align="left" style="padding:8px 0; border-bottom:2px solid #333;">Article</th>'
            . '<th align="center" style="padding:8px 0; border-bottom:2px solid #333;">Qté</th>'
            . '<th align="right" style="padding:8px 0; border-bottom:2px solid #333;">P.U.</th>'
            . '<th align="right" style="padding:8px 0; border-bottom:2px solid #333;">Total</th>'
            . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px; font-size:14px;">'
            . '<tr><td align="right" style="padding:2px 0;">Produits :</td><td align="right" width="120" style="padding:2px 0;">' . Tools::displayPrice($totProducts, $currency) . '</td></tr>'
            . '<tr><td align="right" style="padding:2px 0;">Livraison :</td><td align="right" style="padding:2px 0;">' . Tools::displayPrice($totShipping, $currency) . '</td></tr>'
            . '<tr><td align="right" style="padding:6px 0; font-weight:bold; border-top:1px solid #333;">Total :</td><td align="right" style="padding:6px 0; font-weight:bold; border-top:1px solid #333;">' . Tools::displayPrice($totPaid, $currency) . '</td></tr>'
            . '</table>';

        $detailsText = $rowsText . "\n"
            . 'Produits : ' . Tools::displayPrice($totProducts, $currency) . "\n"
            . 'Livraison : ' . Tools::displayPrice($totShipping, $currency) . "\n"
            . 'Total : ' . Tools::displayPrice($totPaid, $currency) . "\n";

        $invoiceAddress = new Address((int) $order->id_address_invoice);
        $deliveryAddress = new Address((int) $order->id_address_delivery);

        $orderLink = $link->getPageLink('order-detail', true, $idLang, 'id_order=' . (int) $order->id);

        if (!empty($downloads)) {
            $itemsHtml = '';
            $itemsText = '';
            foreach ($downloads as $d) {
                $itemsHtml .= '<li style="margin-bottom:6px;"><a href="' . $d['url'] . '">'
                    . htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') . '</a></li>';
                $itemsText .= '- ' . $d['name'] . ' : ' . $d['url'] . "\n";
            }
            $downloadHtml = '<div style="margin-top:8px;">'
                . '<h4 style="margin:0 0 8px; font-size:14px;">Vos téléchargements</h4>'
                . '<ul style="margin:0 0 16px; padding-left:20px; font-size:14px; line-height:1.5;">' . $itemsHtml . '</ul>'
                . '</div>';
            $downloadText = "VOS TÉLÉCHARGEMENTS\n" . $itemsText;
        } else {
            $downloadHtml = '';
            $downloadText = '';
        }

        return [
            'details_html' => $detailsHtml,
            'details_text' => $detailsText,
            'invoice_address_html' => AddressFormat::generateAddress($invoiceAddress, [], '<br />', ' '),
            'invoice_address_text' => AddressFormat::generateAddress($invoiceAddress, [], "\n", ' '),
            'delivery_address_html' => AddressFormat::generateAddress($deliveryAddress, [], '<br />', ' '),
            'delivery_address_text' => AddressFormat::generateAddress($deliveryAddress, [], "\n", ' '),
            'download_html' => $downloadHtml,
            'download_text' => $downloadText,
            'order_link' => $orderLink,
        ];
    }

    /**
     * Construit le lien de téléchargement direct (sécurisé) d'un produit virtuel,
     * identique à celui de l'email natif `download_product` de PrestaShop :
     * URL `get-file&key=<filename>-<download_hash>` via ProductDownload::getTextLink().
     * La péremption / le nombre de téléchargements sont contrôlés par le contrôleur
     * get-file lui-même.
     *
     * @param array $product Ligne issue de Order::getProducts()
     * @return array|null ['url' => string, 'name' => string] ou null si non téléchargeable
     */
    private function buildDownloadLink(array $product)
    {
        $hash = isset($product['download_hash']) ? (string) $product['download_hash'] : '';
        if ($hash === '') {
            return null;
        }

        $idProduct = 0;
        if (isset($product['product_id'])) {
            $idProduct = (int) $product['product_id'];
        } elseif (isset($product['id_product'])) {
            $idProduct = (int) $product['id_product'];
        }
        if ($idProduct <= 0) {
            return null;
        }

        $idProductDownload = ProductDownload::getIdFromIdProduct($idProduct);
        if (!$idProductDownload) {
            return null;
        }

        $productDownload = new ProductDownload((int) $idProductDownload);
        if (!Validate::isLoadedObject($productDownload)) {
            return null;
        }

        $name = $productDownload->display_filename;
        if (!$name) {
            $name = isset($product['product_name']) ? (string) $product['product_name'] : 'Téléchargement';
        }

        return [
            'url' => $productDownload->getTextLink($hash),
            'name' => $name,
        ];
    }

    /* ----------------------------------------------------------------------
     * Mapping commande -> lignes Abby
     * -------------------------------------------------------------------- */

    /**
     * Construit les lignes Abby à partir des produits de la commande.
     * unitPrice est exprimé en CENTIMES par l'API Abby.
     */
    private function buildLines(Order $order)
    {
        $lines = [];
        $franchise = (bool) $this->settings['franchise_tva'];

        foreach ($order->getProducts() as $product) {
            // En micro-entreprise franchise en base, on facture HT = TTC, sans TVA.
            $unitPrice = $franchise
                ? (float) $product['unit_price_tax_excl']
                : (float) $product['unit_price_tax_incl'];

            $vatRate = $franchise ? 0.0 : (float) $product['tax_rate'];

            $lines[] = [
                'designation' => Tools::substr($product['product_name'], 0, 255),
                'reference' => isset($product['reference']) ? $product['reference'] : '',
                'unitPrice' => (int) round($unitPrice * 100), // centimes
                'quantity' => (int) $product['product_quantity'],
                'quantityUnit' => 'unit',     // enum ProductUnit Abby = chaîne (unit, gram, hour, day…)
                'type' => 'sale_of_goods',    // enum ProductType Abby = chaîne : vente de bien (physique ou numérique)
                'vatCode' => $this->mapVatCode($vatRate),
                'isTaxIncluded' => !$franchise,
            ];
        }

        // Frais de port éventuels en ligne distincte
        $shipping = $franchise
            ? (float) $order->total_shipping_tax_excl
            : (float) $order->total_shipping_tax_incl;
        if ($shipping > 0) {
            $lines[] = [
                'designation' => 'Frais de livraison',
                'unitPrice' => (int) round($shipping * 100),
                'quantity' => 1,
                'quantityUnit' => 'unit',
                'type' => 'service_delivery',  // les frais de port = prestation de livraison
                'vatCode' => $this->mapVatCode($franchise ? 0.0 : (float) $order->carrier_tax_rate),
                'isTaxIncluded' => !$franchise,
            ];
        }

        return $lines;
    }

    /**
     * Mappe un taux de TVA PrestaShop vers un vatCode Abby.
     * Enum Abby : FR_2000 (20%), FR_1000 (10%), FR_550 (5,5%), FR_210 (2,1%),
     * FR_850 (8,5% DOM), FR_00HT (hors taxe / franchise),
     * FR_00UE (intracom UE), FR_0HUE (hors UE / export).
     * (À recouper avec la doc officielle Abby.)
     */
    private function mapVatCode($rate)
    {
        $rate = round((float) $rate, 2);
        switch ($rate) {
            case 20.0:
                return 'FR_2000';
            case 10.0:
                return 'FR_1000';
            case 5.5:
                return 'FR_550';
            case 2.1:
                return 'FR_210';
            case 8.5:
                return 'FR_850';
            default:
                return 'FR_00HT'; // 0 % : franchise en base par défaut
        }
    }

    /* ----------------------------------------------------------------------
     * Clients Abby
     * -------------------------------------------------------------------- */

    private function isBusiness(Address $address)
    {
        $hasCompany = !empty($address->company);
        $hasVatOrSiret = !empty($address->vat_number) || !empty($address->dni);
        return $hasCompany && $hasVatOrSiret;
    }

    private function ensureContact(Customer $customer, Address $address)
    {
        $payload = [
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'emails' => [$customer->email],
            'billingAddress' => $this->mapAddress($address),
        ];
        $contact = $this->api->createContact($payload);
        return $contact['id'];
    }

    private function ensureOrganization(Customer $customer, Address $address)
    {
        $payload = [
            'name' => $address->company,
            'commercialName' => $address->company,
            'siret' => preg_replace('/\D/', '', (string) $address->dni),
            'vatNumber' => (string) $address->vat_number,
            'emails' => [$customer->email],
            'billingAddress' => $this->mapAddress($address),
        ];
        $org = $this->api->createOrganization($payload);
        return $org['id'];
    }

    private function mapAddress(Address $address)
    {
        $country = new Country((int) $address->id_country);
        $mapped = [
            'address' => $address->address1,
            'city' => $address->city,
            'zipCode' => $address->postcode,
            'country' => $country->iso_code ?: 'FR',
        ];
        if (!empty($address->address2)) {
            $mapped['complement'] = $address->address2;
        }

        return $mapped;
    }

    /* ----------------------------------------------------------------------
     * Persistance du mapping
     * -------------------------------------------------------------------- */

    private function findMapping($idOrder)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'abby_invoice` WHERE id_order = ' . (int) $idOrder
        );
        return $row ?: null;
    }

    private function saveMapping($idOrder, $invoiceId, $status, $number, $error = null)
    {
        $db = Db::getInstance();
        $data = [
            'id_order' => (int) $idOrder,
            'abby_invoice_id' => $invoiceId ? pSQL($invoiceId) : null,
            'abby_invoice_number' => $number ? pSQL($number) : null,
            'status' => pSQL($status),
            'error_message' => $error ? pSQL(Tools::substr($error, 0, 500)) : null,
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($this->findMapping($idOrder)) {
            $db->update('abby_invoice', $data, 'id_order = ' . (int) $idOrder);
        } else {
            $data['date_add'] = date('Y-m-d H:i:s');
            $db->insert('abby_invoice', $data);
        }
    }
}
