<?php
/**
 * Client HTTP minimal pour l'API Abby (https://api.app-abby.com).
 *
 * Auth : header `Authorization: Bearer suk-…`, HTTPS obligatoire.
 * Volontairement basé sur cURL pour ne dépendre d'aucune lib externe
 * (Guzzle n'est pas garanti présent sur tous les hébergements PrestaShop).
 *
 * Endpoints de facturation confirmés (https://docs.abby.fr/api/factures) :
 *   - création de facture : POST  /v2/billing/invoice/{customerId}
 *   - mise à jour lignes  : PATCH /v2/billing/{billingId}/lines
 *   - finalisation        : PATCH /v2/billing/{billingId}/finalize
 *   - mentions légales    : PATCH /v2/billing/invoice/{invoiceId}/general-informations
 *   - téléchargement PDF  : GET   /v2/billing/{billingId}/download
 * Ils sont isolés dans des méthodes dédiées pour être ajustables d'un seul endroit.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AbbyApiClient
{
    /**
     * Routes clients (cf. https://docs.abby.fr/api/clients).
     * Attention : pas de préfixe `/v2` ici, et la lecture et la création n'utilisent
     * pas le même chemin :
     *   - lister     : GET  /contacts      /organizations      (pluriel)
     *   - créer      : POST /contact       /organization       (singulier)
     */
    const ENDPOINT_CONTACTS_LIST = '/contacts';
    const ENDPOINT_CONTACT_CREATE = '/contact';
    const ENDPOINT_ORGANIZATIONS_LIST = '/organizations';
    const ENDPOINT_ORGANIZATION_CREATE = '/organization';

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var int */
    private $timeout;

    public function __construct($apiKey, $baseUrl = 'https://api.app-abby.com', $timeout = 30)
    {
        $this->apiKey = trim((string) $apiKey);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = (int) $timeout;
    }

    /**
     * Vérifie que la clé est exploitable (appel léger).
     * @return bool
     */
    public function ping()
    {
        try {
            // Un GET authentifié quelconque : si la clé est invalide on récupère un 401.
            $this->request('GET', self::ENDPOINT_CONTACTS_LIST . '?limit=1');
            return true;
        } catch (AbbyApiException $e) {
            return $e->getHttpCode() !== 401 && $e->getHttpCode() !== 403;
        }
    }

    /**
     * Crée un contact (client particulier) chez Abby.
     *
     * @param array $contact ex. [
     *   'firstname' => 'Marie', 'lastname' => 'Durand',
     *   'emails' => ['marie@example.com'],
     *   'billingAddress' => ['line1' => '...', 'city' => '...', 'zipCode' => '...', 'country' => 'FR'],
     *   'language' => 'fr',
     * ]
     * @return array Contact créé (contient 'id')
     */
    public function createContact(array $contact)
    {
        return $this->request('POST', self::ENDPOINT_CONTACT_CREATE, $contact);
    }

    /**
     * Crée une organisation (client professionnel, B2B) chez Abby.
     * Pour le B2B il faut un SIREN/SIRET + numéro de TVA pour que la facture
     * parte bien en e-invoicing via la plateforme agréée.
     *
     * @param array $organization
     * @return array Organisation créée (contient 'id')
     */
    public function createOrganization(array $organization)
    {
        return $this->request('POST', self::ENDPOINT_ORGANIZATION_CREATE, $organization);
    }

    /**
     * Crée une facture rattachée à un customerId (contactId OU organizationId).
     * La facture est en brouillon tant qu'elle n'est pas finalisée.
     *
     * @param string $customerId
     * @param array  $payload  (ex. ['locale' => 'fr', 'currencyCode' => 'EUR', ...])
     * @return array Facture créée (contient 'id', 'state', ...)
     */
    public function createInvoiceForCustomer($customerId, array $payload = [])
    {
        return $this->request('POST', '/v2/billing/invoice/' . rawurlencode($customerId), $payload);
    }

    /**
     * Met à jour les lignes d'un document de facturation (endpoint partagé billing).
     *
     * @param string $billingId
     * @param array  $lines  liste de lignes au format Abby (unitPrice en CENTIMES)
     * @param array|null $discount remise globale optionnelle
     * @return array Document mis à jour
     */
    public function updateLines($billingId, array $lines, array $discount = null)
    {
        $body = ['lines' => $lines];
        if ($discount !== null) {
            $body['discount'] = $discount;
        }

        return $this->request('PATCH', '/v2/billing/' . rawurlencode($billingId) . '/lines', $body);
    }

    /**
     * Met à jour les informations générales / mentions légales d'une facture.
     * Utile pour la mention "TVA non applicable, art. 293 B du CGI" (franchise en base).
     *
     * @param string $invoiceId
     * @param array  $generalInfo
     * @return array
     */
    public function updateInvoiceGeneralInformations($invoiceId, array $generalInfo)
    {
        return $this->request(
            'PATCH',
            '/v2/billing/invoice/' . rawurlencode($invoiceId) . '/general-informations',
            $generalInfo
        );
    }

    /**
     * Finalise la facture : c'est l'étape qui rend le document immuable, lui
     * attribue son numéro définitif et déclenche l'émission Factur-X (B2B) /
     * l'alimentation de l'e-reporting (B2C) via la plateforme agréée Abby.
     *
     * @param string $invoiceId
     * @return array Facture finalisée (numéro, état, lien PDF Factur-X dans attachments)
     */
    public function finalizeInvoice($invoiceId)
    {
        return $this->request('PATCH', '/v2/billing/' . rawurlencode($invoiceId) . '/finalize', []);
    }

    /**
     * Télécharge le PDF d'un document de facturation via l'endpoint dédié
     * (GET /v2/billing/{billingId}/download). À privilégier quand on a le
     * billingId : pas besoin de fouiller `attachments[]`.
     *
     * @param string $billingId
     * @throws AbbyApiException
     * @return string Contenu brut du fichier (PDF)
     */
    public function downloadBilling($billingId)
    {
        $url = $this->baseUrl . '/v2/billing/' . rawurlencode($billingId) . '/download';

        return $this->rawGet($url);
    }

    /**
     * Télécharge le contenu binaire d'un fichier Abby (ex. le PDF Factur-X
     * présent dans `attachments[].url` d'une facture finalisée).
     * L'URL peut être signée (publique) ou nécessiter le Bearer : on envoie
     * le token, c'est sans effet si l'URL est déjà signée.
     *
     * @param string $fileUrl
     * @throws AbbyApiException
     * @return string Contenu brut du fichier (PDF)
     */
    public function downloadFile($fileUrl)
    {
        return $this->rawGet($fileUrl);
    }

    /**
     * GET binaire (PDF) avec Bearer : renvoie le corps brut, lève sur HTTP non-2xx.
     *
     * @param string $url URL absolue (endpoint Abby ou URL signée d'un attachment)
     * @throws AbbyApiException
     * @return string
     */
    private function rawGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->apiKey]);

        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($data === false || $code < 200 || $code >= 300) {
            throw new AbbyApiException('Téléchargement du PDF Abby échoué (HTTP ' . $code . ') ' . $err, $code);
        }

        return $data;
    }

    /**
     * Requête HTTP générique.
     *
     * @throws AbbyApiException
     * @return array Réponse JSON décodée
     */
    public function request($method, $path, array $body = null)
    {
        if ($this->apiKey === '') {
            throw new AbbyApiException('Clé API Abby manquante.', 0);
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($body !== null) {
            $json = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new AbbyApiException('Erreur réseau Abby : ' . $curlErr, 0);
        }

        $decoded = json_decode($raw, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (is_array($decoded['message']) ? implode(' | ', $decoded['message']) : $decoded['message'])
                : ('HTTP ' . $httpCode);
            throw new AbbyApiException('API Abby : ' . $message, $httpCode, $raw);
        }

        return is_array($decoded) ? $decoded : [];
    }
}

class AbbyApiException extends Exception
{
    /** @var int */
    private $httpCode;

    /** @var string|null */
    private $rawResponse;

    public function __construct($message, $httpCode = 0, $rawResponse = null)
    {
        parent::__construct($message);
        $this->httpCode = (int) $httpCode;
        $this->rawResponse = $rawResponse;
    }

    public function getHttpCode()
    {
        return $this->httpCode;
    }

    public function getRawResponse()
    {
        return $this->rawResponse;
    }
}
