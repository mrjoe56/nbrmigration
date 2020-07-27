<?php

/**
 * Class to process NIHR BioResource communication migration
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org)
 * @date 21 July 2020
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Nbrmigration_NbrCommunication {

  /**
   * CRM_Nbrmigration_NbrParticipation constructor.
   */
  public function __construct() {
    $fileName = "communication_migration_" . date("YmdhIs");
    $this->logger = new CRM_Nihrbackbone_NihrLogger($fileName);
    $this->emailActivityTypeId = Civi::service('nbrBackbone')->getEmailActivityTypeId();
    $this->incomingActivityTypeId = Civi::service('nbrBackbone')->getIncomingCommunicationActivityTypeId();
    $this->letterActivityTypeId = Civi::service('nbrBackbone')->getLetterActivityTypeId();
    $this->meetingActivityTypeId = Civi::service('nbrBackbone')->getMeetingActivityTypeId();
    $this->phoneActivityTypeId = Civi::service('nbrBackbone')->getPhoneActivityTypeId();
    $this->smsActivityTypeId = Civi::service('nbrBackbone')->getSmsActivityTypeId();
    $this->completedActivityStatusId = Civi::service('nbrBackbone')->getCompletedActivityStatusId();
    $this->returnToSenderActivityStatusId = Civi::service('nbrBackbone')->getReturnToSenderActivityStatusId();
    $this->scheduledActivityStatusId = Civi::service('nbrBackbone')->getScheduledActivityStatusId();
    $this->normalPriorityId = Civi::service('nbrBackbone')->getNormalPriorityId();
    $this->emailMediumId = Civi::service('nbrBackbone')->getEmailMediumId();
    $this->inPersonMediumId = Civi::service('nbrBackbone')->getInPersonMediumId();
    $this->letterMediumId = Civi::service('nbrBackbone')->getLetterMediumId();
    $this->phoneMediumId = Civi::service('nbrBackbone')->getPhoneMediumId();
    $this->smsMediumId = Civi::service('nbrBackbone')->getSmsMediumId();
  }

  /**
   * Method to migrate the source data
   *
   * @param $sourceData
   * @return bool
   */
  public function migrate($sourceData) {
    if ($this->isDataValid($sourceData)) {
      // first find contact id with sample_id, log if none found
      $contactId = CRM_Nbrmigration_NbrUtils::getContactIdWithSampleId($sourceData->participant_id);
      if (!$contactId) {
        $this->logger->logMessage('No contact found with participant_id: ' . $sourceData->participant_id, 'error');
        return FALSE;
      }
      // processing based on type (stage 1, stage 2 or stand alone)
      switch ($sourceData->communication_type) {
        // recruitment
        case 1:
          $caseId = CRM_Nbrmigration_NbrUtils::getRecruitmentCaseId($contactId);
          if (!$caseId) {
            $this->logger->logMessage('No recruitment case for contact_id: ' . $contactId . ', communication not migrated.', 'error');
            return FALSE;
          }
          $this->createActivity($this->prepareCaseActivityData($contactId, $caseId, $sourceData));
          break;

        // participation
        case 2:
          // find study, log if none found
          $studyId = CRM_Nbrmigration_NbrUtils::getStudyIdWithStudyNumber($sourceData->study_number);
          if (!$studyId) {
            $this->logger->logMessage('No study found with study_number: ' . $sourceData->study_number, 'error');
            return FALSE;
          }
          $caseId = CRM_Nbrmigration_NbrUtils::getParticipationCaseId($studyId, $contactId);
          if (!$caseId) {
            $this->logger->logMessage('No participation case for contact_id: ' . $contactId . ' and study_id: ' . $studyId . ', communication not migrated.', 'error');
            return FALSE;
          }
          $this->createActivity($this->prepareCaseActivityData($contactId, $caseId, $sourceData));
          break;
        default:
          $this->createActivity($this->prepareActivityData($contactId, $sourceData));
          break;
      }
    }
  }

  /**
   * Method to determine the medium for case activities
   *
   * @param $type
   * @return bool|int
   */
  public function determineMedium($type) {
    $type = strtolower($type);
    switch ($type) {
      case "email":
        return $this->emailMediumId;
        break;
      case "in person":
        return $this->inPersonMediumId;
        break;
      case "letter":
        return $this->letterMediumId;
        break;
      case "phone":
        return $this->phoneMediumId;
        break;
      case "text":
        return $this->smsMediumId;
        break;
      default:
        return FALSE;
        break;
    }
  }

  /**
   * @param $sourceData
   * @return int
   */
  public function determineActivityType($sourceData){
    if (!isset($sourceData->template_type)) {
      return $this->meetingActivityTypeId;
    }
    // if communication direction is inbound, always incoming activity type
    if (isset($sourceData->communication_direction) && $sourceData->communication_direction == "Incoming") {
      return $this->incomingActivityTypeId;
    }
    switch ($sourceData->template_type) {
      case "Email":
        return $this->emailActivityTypeId;
        break;

      case "Letter":
        return $this->letterActivityTypeId;
        break;

      case "Phone":
        return $this->phoneActivityTypeId;
        break;

      case "Text":
        return $this->smsActivityTypeId;
        break;

      default:
        return $this->meetingActivityTypeId;
        break;
    }
  }

  /**
   * Method to determine the activity status
   *
   * @param $status
   * @return mixed
   */
  private function determineStatus($status) {
    $status = strtolower($status);
    switch ($status) {
      case "return to sender":
        return $this->returnToSenderActivityStatusId;
        break;
      case "scheduled":
        return $this->scheduledActivityStatusId;
        break;
      default:
        return $this->completedActivityStatusId;
        break;
    }
  }

  /**
   * Method to set the activity data for a case
   *
   * @param $contactId
   * @param $caseId
   * @param $sourceData
   * @return array
   */
  private function prepareCaseActivityData($contactId, $caseId, $sourceData) {
    $sourceDate = $sourceData->communication_date . " " . $sourceData->communication_time;
    $activityDate = new DateTime($sourceDate);
    $activityData = [
      'source_contact_id' => 'user_contact_id',
      'target_contact_id' => $contactId,
      'case_id' => $caseId,
      'priority_id' => $this->normalPriorityId,
      'medium_id' => $this->determineMedium($sourceData->template_type),
      'activity_type_id' => $this->determineActivityType($sourceData),
      'subject' => $sourceData->template_name . " (migration)",
      'status_id' => $this->determineStatus($sourceData->status),
      'activity_date_time' => $activityDate->format("Y-m-d H:i:s"),
    ];
    if (!empty($sourceData->contact_detail)) {
      $activityData['location'] = $sourceData->contact_detail;
    }
    if (!empty($sourceData->communication_category)) {
      $activityData['details'] = "<strong>Communication category:</strong> " . $sourceData->communication_category;
    }
    if (!empty($sourceData->communication_notes)) {
      if (!$activityData['details']) {
        $activityData['details'] = "<strong>Communication note:</strong> " . CRM_Core_DAO::escapeString($sourceData->communication_notes);
      }
      else {
        $activityData['details'] .= "\r\n <strong>Communication note:</strong> " . CRM_Core_DAO::escapeString($sourceData->communication_notes);
      }
    }
    return $activityData;
  }

  /**
   * Method to set the activity data for stand alone
   *
   * @param $contactId
   * @param $sourceData
   * @return array
   */
  private function prepareActivityData($contactId, $sourceData) {
    $sourceDate = $sourceData->communication_date . " " . $sourceData->communication_time;
    $activityDate = new DateTime($sourceDate);
    $activityData = [
      'source_contact_id' => 'user_contact_id',
      'target_contact_id' => $contactId,
      'priority_id' => $this->normalPriorityId,
      'activity_type_id' => $this->determineActivityType($sourceData),
      'subject' => $sourceData->template_name . " (migration)",
      'status_id' => $this->determineStatus($sourceData->status),
      'activity_date_time' => $activityDate->format("Y-m-d H:i:s"),
    ];
    if (!empty($sourceData->contact_detail)) {
      $activityData['location'] = $sourceData->contact_detail;
    }
    if (!empty($sourceData->communication_category)) {
      $activityData['details'] = "<strong>Communication category:</strong> " . $sourceData->communication_category;
    }
    if (!empty($sourceData->communication_notes)) {
      if (!$activityData['details']) {
        $activityData['details'] = "<strong>Communication note:</strong> " . CRM_Core_DAO::escapeString($sourceData->communication_notes);
      }
      else {
        $activityData['details'] .= "\r\n <strong>Communication note:</strong> " . CRM_Core_DAO::escapeString($sourceData->communication_notes);
      }
    }
    return $activityData;
  }

  /**
   * Method to add activity
   *
   * @param array
   * @throws Exception
   */
  public function createActivity($activityData) {
    // only if we have an activity type id
    if (isset($activityData['activity_type_id']) && !empty($activityData['activity_type_id'])) {
      if (!isset($activityData['activity_date_time']) || empty($activityData['activity_date_time'])) {
        $activityDateTime = new DateTime();
        $activityData['activity_date_time'] = $activityDateTime->format("Y-m-d");
      }
      if (!isset($activityData['subject']) || empty($activityData['subject'])) {
        $activityData['subject'] = "Communication activity added during migration of data from Starfish";
      }
      if (!isset($activityData['source_contact_id']) || empty($activityData['source_contact_id'])) {
        $activityData['source_contact_id'] = 'user_contact_id';
      }
      try {
        civicrm_api3('Activity', 'create', $activityData);
        return TRUE;
      }
      catch (CiviCRM_API3_Exception $ex) {
        $this->logger->logMessage("Could not create communication activity with data " . json_encode($activityData)
          . ", error from API Activity create: " . $ex->getMessage());
      }
    }
    else {
      $this->logger->logMessage("Trying to create case activity but activityTypeId is empty in data: " . json_encode($activityData), "Warning");
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
    // participant_id should be present and not empty
    if (!isset($sourceData->participant_id) || empty($sourceData->participant_id)) {
      $this->logger->logMessage('Empty participant_id or no participant_id in source data with id: ' . $sourceData->id, 'error');
      $valid = FALSE;
    }
    return $valid;
  }

}
