<?php
/**
 * Création / suppression de la table de mapping commande <-> facture Abby.
 * Inclus depuis le module à l'install/uninstall.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function abbyinvoicing_install_sql()
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'abby_invoice` (
        `id_abby_invoice` INT(11) NOT NULL AUTO_INCREMENT,
        `id_order` INT(11) NOT NULL,
        `abby_invoice_id` VARCHAR(64) NULL,
        `abby_invoice_number` VARCHAR(64) NULL,
        `status` VARCHAR(32) NOT NULL DEFAULT "pending",
        `error_message` VARCHAR(500) NULL,
        `date_add` DATETIME NULL,
        `date_upd` DATETIME NULL,
        PRIMARY KEY (`id_abby_invoice`),
        UNIQUE KEY `uniq_order` (`id_order`),
        KEY `idx_status` (`status`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

    return Db::getInstance()->execute($sql);
}

function abbyinvoicing_uninstall_sql()
{
    // On NE supprime PAS la table à la désinstallation : elle contient la trace
    // des numéros de factures émises (obligation de conservation). Décommente si besoin.
    // return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'abby_invoice`;');
    return true;
}
