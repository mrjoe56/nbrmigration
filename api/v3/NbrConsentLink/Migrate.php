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
  Civi::log()->debug("Coming into the migrate API");
  set_time_limit(0);
  $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_nbr_consent_link WHERE processed = FALSE LIMIT 5000");
  Civi::log()->debug("Reading the unprocessed records -> found " . $dao->N);
  $logDate = new DateTime();
  $logger = new CRM_Nihrbackbone_NihrLogger('consent_link_' . $logDate->format('Ymdhis'));
  while ($dao->fetch()) {
    $returnValues[] = CRM_Nbrmigration_BAO_NbrConsentLink::migrate($dao, $logger);
  }
  return civicrm_api3_create_success($returnValues, $params, 'NbrConsentLink', 'Migrate');
}
