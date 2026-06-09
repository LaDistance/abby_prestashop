{*
 * Encart "Facturation Abby" affiché en bas de la fiche commande (BO).
 *}
<div class="card mt-2">
  <div class="card-header">
    <h3 class="card-header-title">Facturation Abby</h3>
  </div>
  <div class="card-body">
    {if $abby_row}
      <p>
        <strong>Statut :</strong>
        {if $abby_row.status == 'finalized'}
          <span class="badge badge-success">Facture émise</span>
        {elseif $abby_row.status == 'created'}
          <span class="badge badge-info">Brouillon créé</span>
        {elseif $abby_row.status == 'error'}
          <span class="badge badge-danger">Erreur</span>
        {else}
          <span class="badge badge-secondary">{$abby_row.status|escape:'html':'UTF-8'}</span>
        {/if}
      </p>
      {if $abby_row.abby_invoice_number}
        <p><strong>N° facture :</strong> {$abby_row.abby_invoice_number|escape:'html':'UTF-8'}</p>
      {/if}
      {if $abby_row.abby_invoice_id}
        <p><strong>ID Abby :</strong> {$abby_row.abby_invoice_id|escape:'html':'UTF-8'}</p>
      {/if}
      {if $abby_row.error_message}
        <p class="text-danger">{$abby_row.error_message|escape:'html':'UTF-8'}</p>
      {/if}
    {else}
      <p>Aucune facture Abby n'a encore été générée pour cette commande.</p>
    {/if}
    <a href="{$abby_resync_url|escape:'html':'UTF-8'}" class="btn btn-primary">
      (Re)synchroniser avec Abby
    </a>
  </div>
</div>
