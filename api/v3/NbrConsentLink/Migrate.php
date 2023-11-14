<?php
use CRM_Nbrmigration_ExtensionUtil as E;


/**
 * NbrConsentLink.Migrate API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_nbr_consent_link_Migrate($params) {
  set_time_limit(0);
  $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_nbr_consent_link WHERE processed = FALSE LIMIT 4000");
  $logDate = new DateTime();
  $logger = new CRM_Nihrbackbone_NihrLogger('consent_link_' . $logDate->format('Ymdhis'));
  while ($dao->fetch()) {
    $returnValues[] = CRM_Nbrmigration_BAO_NbrConsentLinkMigration::migrate($dao, $logger);
    $update = "UPDATE civicrm_nbr_consent_link_migration SET processed = TRUE WHERE id = %1";
    CRM_Core_DAO::executeQuery($update, [1 => [$dao->id, 'Integer']]);
  }
  return civicrm_api3_create_success($returnValues, $params, 'NbrConsentLink', 'Migrate');
}
