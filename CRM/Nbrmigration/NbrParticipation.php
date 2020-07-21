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
    $this->logger = new CRM_Nihrbackbone_NihrLogger($fileName);
    $this->participationCaseTypeId = (int) CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCaseTypeId();
    $this->acceptedStatus = Civi::service('nbrBackbone')->getAcceptedParticipationStatusValue();
    $this->excludedStatus = Civi::service('nbrBackbone')->getExcludedParticipationStatusValue();
    $this->invitationPendingStatus = Civi::service('nbrBackbone')->getInvitationPendingParticipationStatusValue();
    $this->invitedStatus = Civi::service('nbrBackbone')->getInvitedParticipationStatusValue();
    $this->noResponseStatus = Civi::service('nbrBackbone')->getNoResponseParticipationStatusValue();
    $this->notParticipatedStatus = Civi::service('nbrBackbone')->getNotParticipatedParticipationStatusValue();
    $this->participatedStatus = Civi::service('nbrBackbone')->getParticipatedParticipationStatusValue();
    $this->refusedStatus = Civi::service('nbrBackbone')->getRefusedParticipationStatusValue();
    $this->renegedStatus = Civi::service('nbrBackbone')->getRenegedParticipationStatusValue();
    $this->returnToSenderStatus = Civi::service('nbrBackbone')->getReturnToSenderParticipationStatusValue();
    $this->selectedStatus = Civi::service('nbrBackbone')->getSelectedParticipationStatusValue();
    $this->withdrawnStatus = Civi::service('nbrBackbone')->getWithdrawnParticipationStatusValue();
    $this->studyIdCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_study_id', 'id');
    $this->studyParticipantIdCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_study_participant_id', 'id');
    $this->studyParticipationStatusCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_study_participation_status', 'id');
    $this->dateInvitedCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_date_invited', 'id');
    $this->recallGroupCustomField = "custom_" . CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_recall_group', 'id');
    $this->sentToResearcherActTypeId = CRM_Nihrbackbone_BackboneConfig::singleton()->getExportExternalActivityTypeId();
    $this->changeStatusActTypeId = CRM_Nihrbackbone_BackboneConfig::singleton()->getChangedStudyStatusActivityTypeId();
    $this->meetingActTypeId = Civi::service('nbrBackbone')->getMeetingActivityTypeId();
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
        $this->logger->logMessage('No contact found with sample_id: ' . $sourceData->sample_id, 'error');
        return FALSE;
      }
      // next find study, log if none found
      $studyId = CRM_Nbrmigration_NbrUtils::getStudyIdWithStudyNumber($sourceData->study_number);
      if (!$studyId) {
        $this->logger->logMessage('No study found with study_number: ' . $sourceData->study_number, 'error');
        return FALSE;
      }
      // create case with custom data (only if volunteer is not already on study)
      if (!CRM_Nihrbackbone_NbrVolunteerCase::isAlreadyOnStudy($contactId, $studyId)) {
        try {
          $createdCase = civicrm_api3('Case', 'create', $this->prepareParticipationData($contactId, $studyId, $sourceData));
          // add sent to researcher activity if required
          if (!empty($sourceData->sent_to_researcher)) {
            $this->createCaseActivity($this->prepareSentData($createdCase['id'], $contactId, $sourceData));
          }
          // add change status from invited to participated activity if required
          if (!empty($sourceData->project_participation_date_answered)) {
            $this->createCaseActivity($this->prepareAnsweredData($createdCase['id'], $contactId, $sourceData));
          }
          // add meeting activity with note if required
          if (!empty($sourceData->project_participation_notes)) {
            $this->createCaseActivity($this->prepareNoteData($createdCase['id'], $contactId, $sourceData));
          }
          try {
            civicrm_api3("Contact", "addidentity", [
              'contact_id' => $contactId,
              'identifier' => $sourceData->anon_study_participation_id,
              'identifier_type' => CRM_Nihrnumbergenerator_Config::singleton()->studyParticipantIdIdentifier,
            ]);
          }
          catch (CiviCRM_API3_Exception $ex) {
            $this->logger->logMessage("Could not add study participant id ") . $sourceData->anon_study_participation_id
            . " as new contact identifier for contact ID " . $contactId . ", error from API Contact addidentity: " . $ex->getMessage();
          }
        }
        catch (CiviCRM_API3_Exception $ex) {
          $this->logger->logMessage('Error when trying to create case in: ' . __METHOD__ . ', API error message: ' . $ex->getMessage(), 'error');
          return FALSE;
        }
      }
      else {
        $this->logger->logMessage('Volunteer with id: ' . $contactId . ' is already on study with id: ' . $studyId . ', not imported.', 'error');
        return FALSE;
      }
    }
  }

  /**
   * Method to prepare note
   *
   * @param $caseId
   * @param $contactId
   * @param $sourceData
   * @return array
   */
  private function prepareNoteData($caseId, $contactId, $sourceData) {
    return [
      'activity_type_id' => $this->meetingActTypeId,
      'case_id' => $caseId,
      'target_contact_id' => $contactId,
      'status_id' => "Completed",
      'subject' => "Note from Starfish (imported during migration)",
      'details' => CRM_Core_DAO::escapeString(strip_tags($sourceData->project_participation_notes)),
    ];
  }

  /**
   * Method to prepare data for sent to researcher
   *
   * @param $caseId
   * @param $contactId
   * @param $sourceData
   * @return array
   * @throws Exception
   */
  private function prepareSentData($caseId, $contactId, $sourceData) {
    $sentDate = new DateTime($sourceData->sent_to_researcher);
    return [
      'activity_type_id' => $this->sentToResearcherActTypeId,
      'activity_date_time' => $sentDate->format('Y-m-d'),
      'case_id' => $caseId,
      'target_contact_id' => $contactId,
      'status_id' => "Completed",
      'subject' => "Exported to External Researcher(s) (imported during migration)",
    ];
  }

  /**
   * Method to prepare data for changed status to accepted
   *
   * @param $caseId
   * @param $contactId
   * @param $sourceData
   * @return array
   * @throws Exception
   */
  private function prepareAnsweredData($caseId, $contactId, $sourceData) {
    $answeredDate = new DateTime($sourceData->project_participation_date_answered);
    return [
      'activity_type_id' => $this->changeStatusActTypeId,
      'activity_date_time' => $answeredDate->format('Y-m-d'),
      'case_id' => $caseId,
      'target_contact_id' => $contactId,
      'status_id' => "Completed",
      'subject' => "Changed from status Invited to status Accepted (imported during migration)",
    ];

  }

  /**
   * Method to add activity to the migrated case
   *
   * @param array
   * @throws Exception
   */
  public function createCaseActivity($activityData) {
    // only if we have a case id and activity type id
    if (isset($activityData['case_id']) && !empty($activityData['case_id']) && isset($activityData['activity_type_id']) && !empty($activityData['activity_type_id'])) {
      if (!isset($activityData['activity_date_time']) || empty($activityData['activity_date_time'])) {
        $activityDateTime = new DateTime();
        $activityData['activity_date_time'] = $activityDateTime->format("Y-m-d");
      }
      if (!isset($activityData['status_id']) || empty($activityData['status_id'])) {
        $activityData['status_id'] = "Completed";
      }
      if (!isset($activityData['subject']) || empty($activityData['subject'])) {
        $activityData['subject'] = "Activity added during migration of participation data from Starfish";
      }
      if (!isset($activityData['source_contact_id']) || empty($activityData['source_contact_id'])) {
        $activityData['source_contact_id'] = 'user_contact_id';
      }
      try {
        civicrm_api3('Activity', 'create', $activityData);
        return TRUE;
      }
      catch (CiviCRM_API3_Exception $ex) {
        $this->logger->logMesagge("Could not create invite activity with data " . json_encode($activityData)
          . ", error from API Activity create: " . $ex->getMessage());
      }
    }
    else {
      $this->logger->logMessage("Trying to create case activity but caseId/activityTypeId is empty in data: " . json_encode($activityData), "Warning");
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
      'case_type_id' => $this->participationCaseTypeId,
      'subject' => "Selected for study " . $sourceData->study_number,
      'status_id' => "Open",
      $this->studyIdCustomField => $studyId,
      $this->studyParticipationStatusCustomField => $this->transformStatus($sourceData->status),
    ];
    if (!empty($sourceData->anon_study_participation_id)) {
      $result[$this->studyParticipantIdCustomField] = $sourceData->anon_study_participation_id;
    }
    if (!empty($sourceData->date_invited)) {
      $dateInvited = new DateTime($sourceData->date_invited);
      $result[$this->dateInvitedCustomField] = $dateInvited->format("Y-m-d");
    }
    if (!empty($sourceData->recall_group)) {
      $result[$this->recallGroupCustomField] = $sourceData->recall_group;
    }
    return $result;
  }

  /**
   * Method to transform the source status to the civicrm study participation status
   *
   * @param $sourceStatus
   * @return mixed
   */
  private function transformStatus($sourceStatus) {
    $sourceStatus = strtolower(trim($sourceStatus));
    switch ($sourceStatus) {
      case "accepted":
        return $this->acceptedStatus;
        break;
      case "excluded":
        return $this->excludedStatus;
        break;
      case "invitation pending":
        return $this->invitationPendingStatus;
        break;
      case "invited":
        return $this->invitedStatus;
        break;
      case "no response":
        return $this->noResponseStatus;
        break;
      case "not participated":
        return $this->notParticipatedStatus;
        break;
      case "participated":
        return $this->participatedStatus;
        break;
      case "refused":
        return $this->refusedStatus;
        break;
      case "reneged":
        return $this->renegedStatus;
        break;
      case "return to sender":
        return $this->returnToSenderStatus;
        break;
      case "selected":
        return $this->selectedStatus;
        break;
      case "withdrawn":
        return $this->withdrawnStatus;
        break;
      default:
        $this->logger->logMessage("Status " . $sourceStatus . " is not valid, status Selected used.", "warning");
        return $this->selectedStatus;
        break;
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
    // sample_id should be present and not empty
    if (!isset($sourceData->sample_id) || empty($sourceData->sample_id)) {
      $this->logger->logMessage('Empty sample_id or no sample_id in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    // study_number should be present and not empty
    if (!isset($sourceData->study_number) || empty($sourceData->study_number)) {
      $this->logger->logMessage('Empty study_number or no study_number in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    // status should be present and not empty
    if (!isset($sourceData->status) || empty($sourceData->status)) {
      $this->logger->logMessage('Empty status or no status in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    else {
      // if status != selected, anon_study_participation_id should be present and not empty
      if (strtolower($sourceData->status) != "selected") {
        if (!isset($sourceData->anon_study_participation_id) || empty($sourceData->anon_study_participation_id)) {
          $this->logger->logMessage('Empty anon_study_participation_id or no anon_study_participation_id whilst status is not selected in source data with id: ' . $sourceData->id, 'error');
          $valid = FALSE;
        }
      }
    }
    return $valid;
  }

}
