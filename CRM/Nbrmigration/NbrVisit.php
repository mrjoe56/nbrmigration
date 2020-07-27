<?php

/**
 * Class to process NIHR BioResource vist migration
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org)
 * @date 27 July 2020
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Nbrmigration_NbrVisit {

  /**
   * CRM_Nbrmigration_NbrParticipation constructor.
   */
  public function __construct() {
    $fileName = "visit_migration_" . date("YmdhIs");
    $this->logger = new CRM_Nihrbackbone_NihrLogger($fileName);
  }

  /**
   * Method to migrate the source data
   *
   * @param $sourceData
   * @return bool
   */
  public function migrate($sourceData) {
    if ($this->isDataValid($sourceData)) {
    }
  }

  /**
   * Check if data looks like it can be processed
   *
   * @param $sourceData
   * @return bool
   */
  private function isDataValid($sourceData) {
    $valid = TRUE;
    // sample_id (participant) should be present and not empty
    if (!isset($sourceData->sample_id) || empty($sourceData->sample_id)) {
      $this->logger->logMessage('Empty sample_id or no sample_id in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    return $valid;
  }

}
