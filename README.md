# Module PrestaShop 8 - Facturation électronique via Abby

Synchronise les commandes PrestaShop vers **Abby** (plateforme agréée / PA-PDP)
au moment du paiement. Abby se charge ensuite du routage légal :
**Factur-X** transmise via la plateforme agréée pour les clients **professionnels (B2B)**,
**e-reporting** pour les clients **particuliers (B2C)**.

Attention : ce module a été conçu et développé pour une personne en particulier, je ne fournirai *PAS* de support si vous décidez de l'utiliser de votre côté sans connaissances de développement.

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
├── index.php                               # stub de sécurité (présent dans chaque dossier)
├── classes/
│   ├── AbbyApiClient.php                    # client HTTP de l'API Abby (cURL)
│   └── AbbyInvoiceSync.php                  # mapping commande -> facture + orchestration
├── sql/
│   └── install.php                          # table de mapping ps_abby_invoice
├── mails/
│   ├── fr/abby_invoice.{html,txt}           # email client unique (FR)
│   └── en/abby_invoice.{html,txt}           # email client unique (EN, fallback)
└── views/templates/admin/
    └── order_panel.tpl                      # encart statut dans la fiche commande
```

> Chaque dossier contient aussi un `index.php` de sécurité (stub `header('Location: ../')`)
> pour empêcher le listing du répertoire, conformément à la convention PrestaShop.

> Le template d'email `abby_invoice` doit exister dans `mails/<iso>/` pour **chaque
> langue active** de la boutique (au minimum `fr` et `en`). Variables disponibles :
> `{firstname}`, `{lastname}`, `{shop_name}`, `{order_reference}`, `{order_link}`,
> `{invoice_line_html|text}`, `{order_details_html|text}`,
> `{invoice_address_html|text}`, `{delivery_address_html|text}`,
> `{download_block_html|text}`. Les variantes `_html` sont utilisées dans le `.html`,
> les `_text` dans le `.txt`.

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

### Email client unique (fusion des emails)

Par défaut, une commande payée déclenche **4 emails** PrestaShop (confirmation de
commande, paiement accepté, lien de téléchargement pour les produits virtuels, et
la facture). L'option *« Fusionner les emails client »* (`ABBY_MERGE_EMAILS`,
activée par défaut) les remplace par **un seul** email récapitulatif envoyé par le
module après paiement (template `abby_invoice`), contenant :

- la confirmation de commande (lignes, totaux, adresses),
- les **liens de téléchargement directs** des produits virtuels (mêmes liens
  sécurisés `get-file` que l'email natif `download_product`), plus un lien vers la
  commande dans le compte client,
- la **facture Abby en pièce jointe** (si finalisée),
- la mention du **droit de rétractation** (texte du template, **à adapter à vos CGV**).

Mécanique :
- Le hook `actionEmailSendBefore` **annule** les emails natifs `order_conf`,
  `payment` et `download_product` (retour `false`). Il ne les annule **que** si la
  clé API est configurée, pour ne jamais couper un email sans pouvoir le remplacer.
- Le module envoie ensuite l'email unique — **même si la synchro Abby échoue**
  (dans ce cas : confirmation de commande sans la facture). Le client reçoit donc
  toujours exactement un email.

> **Dépannage — on reçoit encore les 4 emails.** Le hook `actionEmailSendBefore`
> n'est enregistré qu'à l'installation : si le module a été **mis à jour sans
> réinstallation**, ce hook manque et la suppression ne s'active pas. Le module
> se répare tout seul à la **première ouverture de la page de configuration** (et
> à la commande suivante). Sinon : vérifier que *« Fusionner les emails client »*
> est sur **Oui**, ou réinstaller le module. (Inutile de désinstaller : ça
> effacerait la clé API.)

> ⚠️ **Réservé aux paiements instantanés (CB/PayPal).** Pour un paiement différé
> (virement, chèque, contre-remboursement), la commande est confirmée avant le
> paiement : il faut alors garder les emails natifs (un email précoce avec les
> instructions de paiement). **Désactivez** l'option dans ce cas.
>
> ⚠️ L'email unique **remplace la confirmation de commande** (support durable au
> sens de l'art. L221-13 du Code de la consommation). Vérifiez que son contenu —
> notamment la clause de **droit de rétractation** du template — est conforme à vos
> CGV avant la mise en production.

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
