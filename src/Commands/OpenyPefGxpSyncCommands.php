<?php

namespace Drupal\openy_pef_gxp_sync\Commands;

use Drush\Commands\DrushCommands;

/**
 * Groupex sync drush commands.
 */
class OpenyPefGxpSyncCommands extends DrushCommands {

  /**
   * Run syncer.
   *
   * @command openy:pef-gxp-sync
   * @aliases openy-pef-gxp-sync
   */
  public function pefGxpSync() {
    try {
      ymca_sync_run("openy_pef_gxp_sync.syncer", "proceed");
    }
    catch (\Exception $e) {
      $this->logger()
        ->error('Failed to run syncer with message: %message', ['%message' => $e->getMessage()]);
    }
  }

}
