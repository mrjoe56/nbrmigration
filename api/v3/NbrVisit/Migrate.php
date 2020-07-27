<?php
use CRM_Nbrmigration_ExtensionUtil as E;

/**
 * NbrVisit.Migrate API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_nbr_visit_Migrate($params) {
  $returnValues = [];
  $query = "SELECT * FROM nbr_migration.nbr_visit_import WHERE processed = %1 LIMIT 5000";
  $data = CRM_Core_DAO::executeQuery($query, [1 =>[0, "Integer"]]);
  if ($data->N == 0) {
    $returnValues[] = "All visit records in table migrated";
  }
  else {
    $migration = new CRM_Nbrmigration_NbrVisit();
    $countProcessed = 0;
    while ($data->fetch()) {
      $countProcessed++;
      $update = "UPDATE nbr_migration.nbr_visit_import SET processed = %1 WHERE id = %2";
      CRM_Core_DAO::executeQuery($update, [
        1 => [1, "Integer"],
        2 => [$data->id, "Integer"],
      ]);
      $migration->migrate($data);
    }
    $returnValues[] = $countProcessed . " visit activities migrated, more runs required.";
  }
  return civicrm_api3_create_success($returnValues, $params, 'NbrVisit', 'Migrate');
}
