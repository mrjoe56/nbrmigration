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
    $this->mapping = [
      'attempts' => "custom_" . Civi::service('nbrBackbone')->getAttemptsCustomFieldId(),
      'incident_form_completed' => "custom_" . Civi::service('nbrBackbone')->getIncidentFormCustomFieldId(),
      'mileage' => "custom_" . Civi::service('nbrBackbone')->getMileageCustomFieldId(),
      'parking' => "custom_" . Civi::service('nbrBackbone')->getParkingFeeCustomFieldId(),
      'other_expenses' => "custom_" . Civi::service('nbrBackbone')->getOtherExpensesCustomFieldId(),
      'claim_received_date' => "custom_" . Civi::service('nbrBackbone')->getClaimReceivedDateCustomFieldId(),
      'claim_submitted_date' => "custom_" . Civi::service('nbrBackbone')->getClaimSubmittedDateCustomFieldId(),
      'expenses_notes' => "custom_" . Civi::service('nbrBackbone')->getExpensesNotesCustomFieldId(),
      'to_lab_date' => "custom_" . Civi::service('nbrBackbone')->getToLabDateCustomFieldId(),
    ];
    $this->sampleReceivedActivityTypeId = Civi::service('nbrBackbone')->getSampleReceivedActivityTypeId();
    $this->visitStage1ActivityTypeId = Civi::service('nbrBackbone')->getVisitStage1ActivityTypeId();
    $this->visitStage2ActivityTypeId = Civi::service('nbrBackbone')->getVisitStage2ActivityTypeId();
    $this->consentStage2ActivityTypeId = Civi::service('nbrBackbone')->getConsentStage2ActivityTypeId();
    $this->completedActivityStatusId = Civi::service('nbrBackbone')->getCompletedActivityStatusId();
    $this->normalPriorityId = Civi::service('nbrBackbone')->getNormalPriorityId();
    $this->sampleSiteOptionGroupId = Civi::service('nbrBackbone')->getSampleSiteOptionGroupId();
    $this->bleedDifficultiesOptionGroupId = Civi::service('nbrBackbone')->getBleedDifficultiesOptionGroupId();
    $this->consentVersionOptionGroupId = Civi::service('nbrBackbone')->getConsentVersionOptionGroupId();
    $this->questionnaireVersionOptionGroupId = Civi::service('nbrBackbone')->getQuestionnaireVersionOptionGroupId();
    $this->studyPaymentOptionGroupId = Civi::service('nbrBackbone')->getStudyPaymentOptionGroupId();
    $this->otherBleedDifficultiesValue = Civi::service('nbrBackbone')->getOtherBleedDifficultiesValue();
    $this->otherSampleSiteValue = Civi::service('nbrBackbone')->getOtherSampleSiteValue();
    // get all option values for sample site, bleed difficulties, consent version and questionnaire version
    $this->bleedDifficulties = [];
    $this->detailLines = [];
    $this->consentVersions = [];
    $this->questionnaireVersions = [];
    $this->sampleSites = [];
    $this->studyPayments = [];
    $this->activityStatus = [];
    try {
      $apiResult = civicrm_api3('OptionValue', 'get', [
        'sequential' => 1,
        'return' => ["option_group_id.name", "value", "label"],
        'option_group_id' => ['IN' => [$this->bleedDifficultiesOptionGroupId, $this->sampleSiteOptionGroupId, $this->consentVersionOptionGroupId, $this->questionnaireVersionOptionGroupId, $this->studyPaymentOptionGroupId, "activity_status"]],
        'options' => ['limit' => 0],
      ]);
      foreach ($apiResult['values'] as $optionValue) {
        switch ($optionValue['option_group_id.name']) {
          case "activity_status":
            $this->activityStatus[strtolower($optionValue['label'])] = $optionValue['value'];
            break;
          case "nbr_bleed_difficulties":
            $this->bleedDifficulties[strtolower($optionValue['label'])] = $optionValue['value'];
            break;
          case "nbr_visit_bleed_site":
            $this->sampleSites[strtolower($optionValue['label'])] = $optionValue['value'];
            break;
          case "nbr_visit_participation_consent_version":
            $this->consentVersions[strtolower($optionValue['label'])] = $optionValue['value'];
            break;
          case "nbr_visit_participation_questionnaire_version":
            $this->questionnaireVersions[strtolower($optionValue['label'])] = $optionValue['value'];
            break;
          case "nbr_visit_participation_study_payment":
            $this->studyPayments[strtolower($optionValue['label'])] = $optionValue['value'];
            break;
        }
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
      $this->logger->logMessage("Could not find required option values, error message from OptionValue get: " . $ex->getMessage());
    }
  }

  /**
   * Method to migrate the source data
   *
   * @param $sourceData
   * @return bool
   */
  public function migrate($sourceData) {
    if ($this->isDataValid($sourceData)) {
      $this->detailLines = [];
      // get contact with sample id
      $contactId = CRM_Nbrmigration_NbrUtils::getContactIdWithSampleId($sourceData->sample_id);
      if (!$contactId) {
        $this->logger->logMessage('No contact found with sample_id: ' . $sourceData->sample_id, 'error');
        return FALSE;
      }
      // if study number is not empty, participation visit
      if (!empty($sourceData->study_number)) {
        $studyId = CRM_Nbrmigration_NbrUtils::getStudyIdWithStudyNumber($sourceData->study_number);
        if (!$studyId) {
          $this->logger->logMessage('No study found with study_number: ' . $sourceData->study_number, 'error');
          return FALSE;
        }
        $caseId = (int) CRM_Nbrmigration_NbrUtils::getParticipationCaseId($studyId, $contactId);
        if ($caseId) {
          $this->createActivity($this->prepareCaseActivityData($contactId, $caseId, $sourceData));
        } else {
          $this->logger->logMessage('No participation case for contact_id: ' . $contactId . ' and study_id: ' . $studyId . ', communication not migrated.', 'error');
          return FALSE;
        }
      }
      else {
        // if empty, recruitment visit
        $caseId = (int) CRM_Nbrmigration_NbrUtils::getRecruitmentCaseId($contactId);
        if ($caseId) {
          $this->createActivity($this->prepareCaseActivityData($contactId, $caseId, $sourceData));
        }
        else {
          $this->logger->logMessage('No recruitment case for contact_id: ' . $contactId . ' and study_id: ' . $studyId . ', communication not migrated.', 'error');
          return FALSE;
        }
      }
      // create sample received activity if required
      if (!empty($sourceData->lab_received_date)) {
        $this->createActivity($this->prepareSampleReceived($contactId, $caseId, $sourceData));
      }
      // create consent activity if required
      if (!empty($sourceData->stage2_consent_version) || !empty($sourceData->stage2_questionnaire_version)) {
        $this->createActivity($this->prepareConsent($contactId, $caseId, $sourceData));
      }
    }
  }

  /**
   * Method to prepare data for consent stage2 activity
   *
   * @param $contactId
   * @param $caseId
   * @param $sourceData
   * @return array
   * @throws Exception
   */
  private function prepareConsent($contactId, $caseId, $sourceData) {
    $consentData = [];
    $activityDate = $this->prepareVisitDate($sourceData->visit_date, $sourceData->visit_time);
    if (!$activityDate) {
      $this->logger->logMessage("Could not create valid time for consent record with id " . $sourceData->id . ", used today", "Warning");
      $activityDate = new DateTime();
    }
    $consentData = [
      'activity_type_id' => $this->consentStage2ActivityTypeId,
      'source_contact_id' => 'user_contact_id',
      'target_contact_id' => $contactId,
      'case_id' => $caseId,
      'priority_id' => $this->normalPriorityId,
      'subject' => "Consent stage2 on " . $activityDate->format("d-m-Y") . " (Starfish migration)",
      'activity_date_time' => $activityDate->format("Y-m-d H:i:s"),

    ];
    if (!empty($sourceData->stage2_consent_version) && strtolower($sourceData->stage2_consent_version) != "n/a") {
      $this->addConsentVersion($sourceData->stage2_consent_version, $consentData);
    }
    if (!empty($sourceData->stage2_questionnaire_version) && strtolower($sourceData->stage2_questionnaire_version) != "n/a") {
      $this->addQuestionnaireVersion($sourceData->stage2_questionnaire_version, $consentData);
    }
    return $consentData;
  }

  /**
   * Method to add the consent version if appropriate
   *
   * @param $consentVersion
   * @param $consentData
   */
  private function addConsentVersion($consentVersion, &$consentData) {
    $sourceValue = strtolower($consentVersion);
    $element = "custom_" . Civi::service('nbrBackbone')->getConsentVersionStage2CustomFieldId();
    if (isset($this->consentVersions[$sourceValue])) {
      $consentData[$element] = $this->consentVersions[$sourceValue];
    }
    else {
      // create if not exists
      $optionNameAndValue = Civi::service('nbrBackbone')->generateLabelFromValue($consentVersion);
      $query = "SELECT MAX(weight) FROM civicrm_option_value WHERE option_group_id = %1";
      $newWeight = CRM_Core_DAO::singleValueQuery($query, [1 => [$this->consentVersionOptionGroupId, "Integer"]]);
      $newWeight++;
      $insert = "INSERT INTO civicrm_option_value (option_group_id, name, value, label, is_reserved, is_active, weight)
        VALUES(%1, %2, %2, %3, %4, %4, %5)";
      $insertParams = [
        1 => [$this->consentVersionOptionGroupId, "Integer"],
        2 => [$optionNameAndValue, "String"],
        3 => [$consentVersion, "String"],
        4 => [1, "Integer"],
        5 => [(int) $newWeight, "Integer"],
      ];
      CRM_Core_DAO::executeQuery($insert, $insertParams);
    }
  }

  /**
   * Method to add the questionnaire version if appropriate
   *
   * @param $questionnaireVersion
   * @param $consentData
   */
  private function addQuestionnaireVersion($questionnaireVersion, &$consentData) {
    $sourceValue = strtolower($questionnaireVersion);
    $element = "custom_" . Civi::service('nbrBackbone')->getQuestionnaireVersionStage2CustomFieldId();
    if (isset($this->questionnaireVersions[$sourceValue])) {
      $consentData[$element] = $this->questionnaireVersions[$sourceValue];
    }
    else {
      // create if not exists
      $optionNameAndValue = Civi::service('nbrBackbone')->generateLabelFromValue($questionnaireVersion);
      $query = "SELECT MAX(weight) FROM civicrm_option_value WHERE option_group_id = %1";
      $newWeight = CRM_Core_DAO::singleValueQuery($query, [1 => [$this->questionnaireVersionOptionGroupId, "Integer"]]);
      $newWeight++;
      $insert = "INSERT INTO civicrm_option_value (option_group_id, name, value, label, is_reserved, is_active, weight)
        VALUES(%1, %2, %2, %3, %4, %4, %5)";
      $insertParams = [
        1 => [$this->questionnaireVersionOptionGroupId, "Integer"],
        2 => [$optionNameAndValue, "String"],
        3 => [$questionnaireVersion, "String"],
        4 => [1, "Integer"],
        5 => [(int) $newWeight, "Integer"],
      ];
      CRM_Core_DAO::executeQuery($insert, $insertParams);
    }
  }

  /**
   * Method to prepare the sample received date activity
   *
   * @param $contactId
   * @param $caseId
   * @param $sourceData
   * @return array
   * @throws Exception
   */
  private function prepareSampleReceived($contactId, $caseId, $sourceData) {
    $sampleData = [];
    if (!empty($sourceData->lab_received_date)) {
      $labReceivedDate = new DateTime($sourceData->lab_received_date);
      $sampleData = [
        'activity_type_id' => $this->sampleReceivedActivityTypeId,
        'source_contact_id' => 'user_contact_id',
        'target_contact_id' => $contactId,
        'case_id' => $caseId,
        'priority_id' => $this->normalPriorityId,
        'subject' => "Sample received on " . $labReceivedDate->format("d-m-Y") . " (Starfish migration)",
        'activity_date_time' => $labReceivedDate->format("Y-m-d H:i:s"),
      ];
    }
    return $sampleData;
  }

  /**
   * Method to create the activity
   *
   * @param $activityData
   * @return bool
   */
  private function createActivity($activityData) {
    // only if we have an activity type id
    if (isset($activityData['activity_type_id']) && !empty($activityData['activity_type_id'])) {
      if (!isset($activityData['activity_date_time']) || empty($activityData['activity_date_time'])) {
        $activityDateTime = new DateTime();
        $activityData['activity_date_time'] = $activityDateTime->format("Y-m-d");
      }
      if (!isset($activityData['subject']) || empty($activityData['subject'])) {
        $activityData['subject'] = "Visit activity added during migration of data from Starfish";
      }
      if (!isset($activityData['source_contact_id']) || empty($activityData['source_contact_id'])) {
        $activityData['source_contact_id'] = 'user_contact_id';
      }
      try {
        civicrm_api3('Activity', 'create', $activityData);
        return TRUE;
      }
      catch (CiviCRM_API3_Exception $ex) {
        $this->logger->logMessage("Could not create visit activity with data " . json_encode($activityData)
          . ", error from API Activity create: " . $ex->getMessage());
      }
    }
    else {
      $this->logger->logMessage("Trying to create visit case activity but activityTypeId is empty in data: " . json_encode($activityData), "Error");
    }
  }

  /**
   * Method to prepare the case activity for visit
   *
   * @param $contactId
   * @param $caseId
   * @param $sourceData
   * @return array
   * @throws Exception
   */
  private function prepareCaseActivityData($contactId, $caseId, $sourceData) {
    $activityDate = $this->prepareVisitDate($sourceData->visit_date, $sourceData->visit_time);
    if (!$activityDate) {
      $this->logger->logMessage("Could not create valid time for migration record with id " . $sourceData->id . ", used today", "Warning");
      $activityDate = new DateTime();
    }
    $activityData = [
      'source_contact_id' => 'user_contact_id',
      'target_contact_id' => $contactId,
      'case_id' => $caseId,
      'priority_id' => $this->normalPriorityId,
      'location' => $sourceData->location,
      'activity_type_id' => $this->getActivityTypeId($sourceData->study_number),
      'subject' => $this->getSubject($sourceData->study_number, $activityDate),
      'status_id' => $this->determineStatus($sourceData->status),
      'activity_date_time' => $activityDate->format("Y-m-d H:i:s"),
    ];
    $this->addCustomFields($sourceData, $activityData);
    $activityData['details'] = $this->getDetails($sourceData->notes);
    return $activityData;
  }

  /**
   * Method to get the activity details
   *
   * @param $notes
   */
  private function getDetails($notes) {
    if (!empty($notes)) {
      $this->detailLines[] = "Notes: " . $notes;
    }
    return implode("\r\n", $this->detailLines);
  }

  /**
   * Method to determine the status of the activity
   *
   * @param $sourceStatus
   */
  private function determineStatus($sourceStatus) {
    // check if status exists, if not default to Completed
    $status = Civi::service('nbrBackbone')->getCompletedActivityStatusId();
    $sourceStatus = strtolower($sourceStatus);
    if (isset($this->activityStatus[$sourceStatus])) {
      $status = $this->activityStatus[$sourceStatus];
    }
    return $status;
  }
  /**
   * Method to get the subject of the activity
   *
   * @param $studyNumber
   * @param $activityDate
   * @return mixed
   */
  private function getSubject($studyNumber, $activityDate) {
    if (empty($studyNumber)) {
      return "Visit on " . $activityDate->format("d-m-Y") . " on recruitment case (Starfish migration)";
    }
    else {
      return "Visit on " . $activityDate->format("d-m-Y") . " on ". $studyNumber > " (Starfish migration)";
    }
  }

  /**
   * Method to get activity type id (visit stage 1 or stage 2)
   *
   * @param $studyNumber
   * @return mixed
   */
  private function getActivityTypeId($studyNumber) {
    if (empty($studyNumber)) {
      return $this->visitStage1ActivityTypeId;
    }
    else {
      return $this->visitStage2ActivityTypeId;
    }
  }

  /**
   * Method to add all the custom fields to the activity data based on mapping
   *
   * @param $sourceData
   * @param $activityData
   */
  private function addCustomFields($sourceData, &$activityData) {
    $sourceArray = CRM_Nihrbackbone_Utils::moveDaoToArray($sourceData);
    foreach ($sourceArray as $sourceFieldKey => $sourceFieldValue) {
      if (isset($this->mapping[$sourceFieldKey]) && !empty($sourceFieldValue) && $sourceFieldValue != "0.00") {
        $activityData[$this->mapping[$sourceFieldKey]] = $sourceFieldValue;
      }
    }
    // add collected by if found
    $this->addCollectedBy($sourceData, $activityData);
    // add sample site, bleed difficulties, study payment if required
    if (!empty($sourceData->difficulties_with_the_bleed)) {
      $this->addBleedDifficulties($sourceData->difficulties_with_the_bleed, $activityData);
    }
    if (!empty($sourceData->sample_site)) {
      $this->addSampleSite($sourceData->sample_site, $activityData);
    }
    if (!empty($sourceData->study_payment)) {
      $this->addStudyPayment($sourceData->study_payment, $activityData);
    }
  }

  /**
   * Method to add bleed difficulties (use other if option values not found)
   *
   * @param $sourceData
   * @param $activityData
   */
  private function addBleedDifficulties($sourceValue, &$activityData) {
    $element = "custom_" . Civi::service('nbrBackbone')->getBleedDifficultiesCustomFieldId();
    $sourceValue = strtolower($sourceValue);
    if (isset($this->bleedDifficulties[$sourceValue])) {
      $activityData[$element] = $this->bleedDifficulties[$sourceValue];
    }
    else {
      $activityData[$element] = $this->otherBleedDifficultiesValue;
    }
  }

  /**
   * Method to add sample site (use other if option values not found)
   *
   * @param $sourceData
   * @param $activityData
   */
  private function addSampleSite($sourceValue, &$activityData) {
    $element = "custom_" . Civi::service('nbrBackbone')->getSampleSiteCustomFieldId();
    $sourceValue = strtolower($sourceValue);
    if (isset($this->sampleSites[$sourceValue])) {
      $activityData[$element] = $this->sampleSites[$sourceValue];
    }
    else {
      $activityData[$element] = $this->otherSampleSiteValue;
    }
  }

  /**
   * Method to add study paymeynt (log and ignore if option values not found)
   *
   * @param $sourceData
   * @param $activityData
   */
  private function addStudyPayment($sourceValue, &$activityData) {
    $element = "custom_" . Civi::service('nbrBackbone')->getStudyPaymentCustomFieldId();
    $sourceValue = strtolower($sourceValue);
    if (isset($this->studyPayments[$sourceValue])) {
      $activityData[$element] = $this->sampleSites[$sourceValue];
    }
    else {
      $this->logger->logMessage("Study payment from source data: " . $sourceValue . " not found in CiviCRM option group, study payment ignored.", "Warning");
    }
  }

  /**
   * Method to add the collected by (either contact id or details)
   *
   * @param $sourceData
   * @param $activityData
   */
  private function addCollectedBy($sourceData, &$activityData) {
    $collectedParam = "custom_" . Civi::service('nbrBackbone')->getCollectedByCustomFieldId();
    $collectedBy = $this->findCollectedBy($sourceData->collected_by);
    if ($collectedBy) {
      $activityData[$collectedParam] = $collectedBy;
    }
    else {
      $this->detailLines[] = "Collected by: " . $sourceData->collected_by;
    }
  }

  /**
   * Method to get the visit date from the 2 input fields
   *
   * @param $sourceDate
   * @param $sourceTime
   * @return string|bool
   * @throws Exception
   */
  private function prepareVisitDate($sourceDate, $sourceTime) {
    if (empty($sourceDate)) {
      return FALSE;
    }
    if (empty($sourceTime)) {
      $visitDate = $sourceDate;
    }
    else {
      $visitDate = $sourceDate . " " . $sourceTime;
    }
    $visitDate = new DateTime($visitDate);
    return $visitDate;
  }

  /**
   * Method to find the collected by contact if existing
   *
   * @param $sourceCollectedBy
   * @return int
   */
  private function findCollectedBy($sourceCollectedBy) {
    $query = "SELECT cc.id, first_name, middle_name, last_name
        FROM civicrm_group_contact AS cgc
            JOIN civicrm_contact AS cc ON cgc.contact_id = cc.id
            JOIN civicrm_group AS cg ON cgc.group_id = cg.id
        WHERE cg.title = %1 AND cgc.status = %2 AND cc.display_name LIKE %3";
    $queryParams = [
      1 => ["BioResourcers", "String"],
      2 => ["Added", "String"],
      3 => ["%" . trim($sourceCollectedBy) . "%", "String"],
    ];
    $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
    // no idea what to do when more than 1, ignore
    if ($dao->N > 1) {
      return NULL;
    }
    if ($dao->fetch()) {
      $contactName = $this->prepareContactName($dao);
      if ($contactName && $contactName == strtolower(trim($sourceCollectedBy))) {
        return $dao->id;
      }
    }
    return NULL;
  }

  /**
   * Method to prepare contact name for comparison
   *
   * @param $dao
   * @return false|string
   */
  private function prepareContactName($dao) {
    $nameParts = [];
    $fields = ['first_name', 'middle_name', 'last_name'];
    foreach ($fields as $field) {
      if (!empty($dao->$field)) {
        $nameParts[] = strtolower(trim($dao->$field));
      }
    }
    if (!empty($nameParts)) {
      return implode(" ", $nameParts);
    }
    else {
      return FALSE;
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
