<?php

/**
 * Class to process NIHR BioResource participation case migration
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org)
 * @date 6 July 2020
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Nbrmigration_NbrParticipation {

  /**
   * CRM_Nbrmigration_NbrParticipation constructor.
   */
  public function __construct() {
    $fileName = "participation_migration_" . date("YmdhIs");
    $this->_logger = new CRM_Nihrbackbone_NihrLogger($fileName);
    $this->_participationCaseTypeId = (int) CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCaseTypeId();
    $this->_acceptedStatus = Civi::service('nbrBackbone')->getAcceptedParticipationStatusValue();
    $this->_excludedStatus = Civi::service('nbrBackbone')->getExcludedParticipationStatusValue();
    $this->_invitationPendingStatus = Civi::service('nbrBackbone')->getInvitationPendingParticipationStatusValue();
    $this->_invitedStatus = Civi::service('nbrBackbone')->getInvitedParticipationStatusValue();
    $this->_noResponseStatus = Civi::service('nbrBackbone')->getNoResponseParticipationStatusValue();
    $this->_notParticipatedStatus = Civi::service('nbrBackbone')->getNotParticipatedParticipationStatusValue();
    $this->_participatedStatus = Civi::service('nbrBackbone')->getParticipatedParticipationStatusValue();
    $this->_refusedStatus = Civi::service('nbrBackbone')->getRefusedParticipationStatusValue();
    $this->_renegedStatus = Civi::service('nbrBackbone')->getRenegedParticipationStatusValue();
    $this->_returnToSenderStatus = Civi::service('nbrBackbone')->getReturnToSenderParticipationStatusValue();
    $this->_selectedStatus = Civi::service('nbrBackbone')->getSelectedParticipationStatusValue();
    $this->_withdrawnStatus = Civi::service('nbrBackbone')->getWithdrawnParticipationStatusValue();
    $this->_studyIdCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_study_id', 'id');
    $this->_studyParticipantIdCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_study_participant_id', 'id');
    $this->_studyParticipationStatusCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_study_participation_status', 'id');
    $this->_dateInvitedCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_date_invited', 'id');
    $this->_recallGroupCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_recall_group', 'id');
  }

  /**
   * Process data into CiviCRM
   *
   * @param $sourceData
   * @return bool
   */
  public function migrate($sourceData) {
    // only if valid sourcedata
    if ($this->isDataValid($sourceData)) {
      // first find contact id with sample_id, log if none found
      $contactId = CRM_Nbrmigration_NbrUtils::getContactIdWithSampleId($sourceData->sample_id);
      if (!$contactId) {
        $this->_logger->logMessage('No contact found with sample_id: ' . $sourceData->sample_id, 'error');
        return FALSE;
      }
      // next find study, log if none found
      $studyId = CRM_Nbrmigration_NbrUtils::getStudyIdWithStudyNumber($sourceData->study_number);
      if (!$studyId) {
        $this->_logger->logMessage('No study found with study_number: ' . $sourceData->study_number, 'error');
        return FALSE;
      }
      // create case with custom data
      try {
        // todo check what happens to the post hook! and if contact identifier already created
        civicrm_api3('Case', 'create', $this->prepareParticipationData($contactId, $studyId, $sourceData));
        // todo add sent to researcher activity if required
        // todo add study participant id as contact identifier
      }
      catch (CiviCRM_API3_Exception $ex) {
        $this->_logger->logMessage('Error when trying to create case in: ' . __METHOD__ . ', API error message: ' . $ex->getMessage(), 'error');
        return FALSE;
      }
    }
  }

  /**
   * Method to prepare an array with data for the case api
   *
   * @param $contactId
   * @param $studyId
   * @param $sourceData
   * @return array
   */
  private function prepareParticipationData($contactId, $studyId, $sourceData) {
    $result = [
      'contact_id' => $contactId,
      'case_type_id' => $this->_participationCaseTypeId,
      $this->_studyIdCustomField => $studyId,
      $this->_studyParticipationStatusCustomField => $this->transformStatus($sourceData->status),
    ];
    if (!empty($sourceData->anon_study_participation_id)) {
      $result[$this->_studyParticipantIdCustomField] = $sourceData->anon_study_participation_id;
    }
    if (!empty($sourceData->date_invited)) {
      $dateInvited = new DateTime($sourceData->date_invited);
      $result[$this->_dateInvitedCustomField] = $dateInvited->format("Y-m-d");
    }
    if (!empty($sourceData->recall_group)) {
      $result[$this->_recallGroupCustomField] = $sourceData->recall_group;
    }
    return $result;
  }

  /**
   * Check if data looks like it can be processed
   *
   * @param $sourceData
   * @return bool
   */
  private function isDataValid($sourceData) {
    $valid = TRUE;
    // sample_id should be present and not empty
    if (!isset($sourceData->sample_id) || empty($sourceData->sample_id)) {
      $this->_logger->logMessage('Empty sample_id or no sample_id in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    // study_number should be present and not empty
    if (!isset($sourceData->study_number) || empty($sourceData->study_number)) {
      $this->_logger->logMessage('Empty study_number or no study_number in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    // status should be present and not empty
    if (!isset($sourceData->status) || empty($sourceData->status)) {
      $this->_logger->logMessage('Empty status or no status in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    else {
      // if status != selected, anon_study_participation_id should be present and not empty
      if (strtolower($sourceData->status) != "selected") {
        if (!isset($sourceData->anon_study_participation_id) || empty($sourceData->anon_study_participation_id)) {
          $this->_logger->logMessage('Empty anon_study_participation_id or no anon_study_participation_id whilst status is not selected in source data with id: ' . $sourceData->id, 'error');
          $valid = FALSE;
        }
      }
    }
    return $valid;
  }

}
