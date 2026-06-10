<?php
/**
 * Index de sécurité : empêche le listing du répertoire si l'accès direct
 * (autoindex) est autorisé sur le serveur. Présent dans chaque dossier du module,
 * comme le veut la convention PrestaShop.
 */
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Location: ../');
exit;
