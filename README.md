# Module PrestaShop 8 — Facturation électronique via Abby

Synchronise les commandes PrestaShop vers **Abby** (plateforme agréée / PA-PDP)
au moment du paiement. Abby se charge ensuite du routage légal :
**Factur-X** transmise via la plateforme agréée pour les clients **professionnels (B2B)**,
**e-reporting** pour les clients **particuliers (B2C)**.

## Pourquoi cette répartition B2B / B2C

La réforme de la facturation électronique impose, pour une micro-entreprise :
- **réception** de factures électroniques dès le **1ᵉʳ septembre 2026** ;
- **émission** électronique + e-reporting dès le **1ᵉʳ septembre 2027**.

L'e-invoicing (Factur-X transmis client par client) ne concerne que les
transactions **B2B** entre assujettis. Les ventes aux **particuliers** relèvent
de **l'e-reporting** (transmission agrégée des données de transaction), pas de
l'émission d'une facture électronique structurée par vente. Le module gère les
deux cas et laisse Abby appliquer le bon circuit.

## Arborescence

```
abbyinvoicing/
├── abbyinvoicing.php                       # module : install, hooks, config BO
├── classes/
│   ├── AbbyApiClient.php                    # client HTTP de l'API Abby (cURL)
│   └── AbbyInvoiceSync.php                  # mapping commande -> facture + orchestration
├── sql/
│   └── install.php                          # table de mapping ps_abby_invoice
├── mails/
│   ├── fr/abby_invoice.{html,txt}           # email d'envoi de la facture (FR)
│   └── en/abby_invoice.{html,txt}           # email d'envoi de la facture (EN, fallback)
└── views/templates/admin/
    └── order_panel.tpl                      # encart statut dans la fiche commande
```

> Le template d'email `abby_invoice` doit exister dans `mails/<iso>/` pour **chaque
> langue active** de la boutique (au minimum `fr` et `en`). Variables disponibles :
> `{firstname}`, `{lastname}`, `{order_reference}`, `{invoice_number}`, `{shop_name}`.

## Installation

1. Zipper le dossier `abbyinvoicing/` et l'installer via le BO (Modules > Installer).
   (PrestaShop génère les `index.php` de sécurité automatiquement ; sinon en ajouter un par dossier.)
2. Dans **Abby** : activer la facturation électronique (signature du mandat par SMS,
   réglage **manuel one-shot**), puis créer une **clé API** dans Réglages > Intégrations.
3. Dans la config du module : coller la clé `suk-…`, garder **Mode test = Oui** au début,
   régler **Franchise en base de TVA** selon le statut de la cliente, choisir le
   **statut déclencheur** (par défaut « Paiement accepté »).

## Fonctionnement

- Hook `actionOrderStatusPostUpdate` : à chaque passage au statut payé, le module
  crée le client Abby (contact si B2C, organisation si B2B avec SIRET/TVA),
  crée une facture brouillon, pousse les lignes (**prix en centimes**), applique
  les mentions légales, et — si **Finalisation auto** est activée — finalise la
  facture (émission Factur-X / e-reporting).
- **Idempotence** : la table `ps_abby_invoice` empêche de re-générer une facture
  déjà créée pour une commande. Resync manuel possible depuis la fiche commande.
- Les erreurs API sont loguées (`PrestaShopLogger`) et stockées sur la ligne de mapping.

### Abby = moteur de facturation unique

Pour qu'Abby soit **le** moteur de facturation (et pas PrestaShop), le module
agit sur deux points :

- **Envoi au client** : l'option *« Envoyer la facture Abby au client »* envoie le
  PDF de la facture Abby par email (`ABBY_SEND_EMAIL`). Elle ne s'applique qu'aux
  factures **finalisées** : il faut donc aussi activer *« Finaliser automatiquement »*.
- **Coupure de la facture native PrestaShop** : l'option *« Désactiver la facture
  native PrestaShop »* (`ABBY_DISABLE_PS_INVOICE`, activée par défaut) met le
  réglage global **`PS_INVOICE` à 0**. PrestaShop n'attribue plus de numéro de
  facture, ne génère plus de PDF natif et n'attache plus sa facture aux emails de
  changement de statut. La valeur d'origine de `PS_INVOICE` est sauvegardée
  (`ABBY_PS_INVOICE_BACKUP`) et **restaurée** si on désactive l'option ou si on
  désinstalle le module.

> ⚠️ `PS_INVOICE = 0` est un réglage **global** : il coupe aussi la génération de
> factures natives dans le BO et le téléchargement de facture depuis le compte
> client. C'est voulu (Abby devient la seule source), mais à connaître.

## Endpoints utilisés

Confirmés sur la doc Abby :

| Méthode | Chemin | Usage | Doc |
| --- | --- | --- | --- |
| GET | `/contacts` | lister les contacts (utilisé par le ping) | [clients](https://docs.abby.fr/api/clients) |
| POST | `/contact` | créer un contact (B2C) | [clients](https://docs.abby.fr/api/clients) |
| POST | `/organization` | créer une organisation (B2B) | [clients](https://docs.abby.fr/api/clients) |
| POST | `/v2/billing/invoice/{customerId}` | créer une facture | [factures](https://docs.abby.fr/api/factures) |
| PATCH | `/v2/billing/{billingId}/lines` | mettre à jour les lignes | [factures](https://docs.abby.fr/api/factures) |
| PATCH | `/v2/billing/{billingId}/finalize` | finaliser la facture (Factur-X / e-reporting) | [factures](https://docs.abby.fr/api/factures) |
| PATCH | `/v2/billing/invoice/{invoiceId}/general-informations` | mentions légales (293 B…) | [factures](https://docs.abby.fr/api/factures) |
| GET | `/v2/billing/{billingId}/download` | télécharger le PDF du document | [factures](https://docs.abby.fr/api/factures) |

> ⚠️ Les routes clients/organisations **n'ont pas** de préfixe `/v2` et la lecture
> (pluriel) diffère de la création (singulier). Les routes facturation, elles, sont
> bien sous `/v2/billing`. La finalisation est un **PATCH** (pas un POST).

## ⚠️ À confirmer avec Abby avant la production

1. **Enums lignes** (toutes des **chaînes**, contrairement à la doc qui les
   présente parfois en entiers — l'API valide des chaînes) :
   - `quantityUnit` : `unit`, `gram`, `hour`, `day`… (on utilise `unit`).
   - `type` : `sale_of_goods`, `service_delivery`, `commercial_or_craft_services`,
     `sale_of_manufactured_goods`, `disbursement`. On utilise `sale_of_goods` pour
     les produits (bien physique ou numérique) et `service_delivery` pour le port.
   - `vatCode` : `FR_00HT`, `FR_2000`, `FR_1000`, `FR_550`… (on utilise `FR_00HT`
     en franchise en base).
2. Que la **finalisation via API** déclenche bien le même circuit légal (Factur-X /
   e-reporting via la PA) que la finalisation dans l'interface Abby.

## Recommandations

- **Laisser Abby gérer la numérotation** des factures (continuité légale) ; ne pas
  activer en parallèle la génération de factures natives PrestaShop pour le même flux.
- Démarrer en **mode test** + **finalisation manuelle** (auto_finalize = Non) pour
  relire les premières factures dans Abby avant d'automatiser l'émission.
- En production, mémoriser le `contactId` Abby sur le client PrestaShop (ex. table
  dédiée ou `id_customer` -> `abby_contact_id`) pour éviter de recréer un contact à
  chaque commande.
- Idéalement, déporter l'appel API dans une **file d'attente / cron** plutôt que dans
  le hook synchrone, pour ne pas ralentir le tunnel de commande ni perdre une facture
  si Abby est momentanément indisponible (prévoir un retry sur les lignes `status=error`).
- Ceci n'est pas un conseil juridique ou fiscal : faire valider la configuration
  (franchise, mentions, périmètre e-reporting) par l'expert-comptable de la cliente.
```
