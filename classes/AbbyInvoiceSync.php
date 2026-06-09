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

                // 3e. Envoi du PDF Factur-X au client par email PrestaShop.
                // Uniquement sur une facture finalisée : un brouillon n'a aucune
                // valeur comptable et n'est pas censé être transmis au client.
                if (!empty($this->settings['send_email'])) {
                    $this->sendInvoiceByEmail($order, $finalized);
                }

                return ['status' => 'created', 'invoice_id' => $invoiceId, 'message' => 'Facture finalisée ' . ($number ?: '')];
            }

            return ['status' => 'created', 'invoice_id' => $invoiceId, 'message' => 'Facture brouillon créée.'];
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
            return ['status' => 'error', 'invoice_id' => null, 'message' => $e->getMessage()];
        }
    }

    /* ----------------------------------------------------------------------
     * Envoi du PDF de la facture au client (email PrestaShop)
     * -------------------------------------------------------------------- */

    /**
     * Récupère le PDF Factur-X de la facture finalisée et l'envoie au client
     * via le système de mail PrestaShop, en pièce jointe.
     *
     * Prérequis : un template mail/<iso>/abby_invoice.(html|txt) dans le module.
     *
     * @param Order $order
     * @param array $invoice Réponse Abby de la facture finalisée (contient id, number)
     * @throws AbbyApiException
     */
    private function sendInvoiceByEmail(Order $order, array $invoice)
    {
        if (empty($invoice['id'])) {
            throw new AbbyApiException('Facture finalisée sans identifiant : PDF introuvable.', 0);
        }

        // Endpoint dédié : Abby renvoie directement le PDF (Factur-X) du document.
        $pdfContent = $this->api->downloadBilling($invoice['id']);

        $customer = new Customer((int) $order->id_customer);
        $idLang = (int) $order->id_lang;
        $number = isset($invoice['number']) ? $invoice['number'] : $invoice['id'];
        $fileName = 'Facture-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $number) . '.pdf';

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_reference}' => $order->reference,
            '{invoice_number}' => (string) $number,
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
        ];

        $sent = Mail::Send(
            $idLang,
            'abby_invoice',                                   // mail/<iso>/abby_invoice.(html|txt)
            Mail::l('Votre facture', $idLang),
            $templateVars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,                                             // from
            null,                                             // from_name
            ['content' => $pdfContent, 'name' => $fileName, 'mime' => 'application/pdf'],
            null,                                             // mode_smtp
            _PS_MODULE_DIR_ . 'abbyinvoicing/mails/'          // template_path
        );

        if (!$sent) {
            throw new AbbyApiException('Echec de l\'envoi du mail PrestaShop avec la facture Abby.', 0);
        }
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
