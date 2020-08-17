<?php

/**
 * Class with generic migration util functions
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org)
 * @date 6 July 2020
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Nbrmigration_NbrUtils {
  /**
   * Method to get a contact id with the "old" sample id (which is participant id)
   * @param $sampleId
   */
  public static function getContactIdWithSampleId($sampleId) {
    if (empty($sampleId)) {
      return FALSE;
    }
    // check if volunteer_ids table exists with required columns
    $table = CRM_Nihrbackbone_BackboneConfig::singleton()->getVolunteerIdsCustomGroup('table_name');
    $participantColumn = CRM_Nihrbackbone_BackboneConfig::singleton()->getVolunteerIdsCustomField('nva_participant_id', 'column_name');
    $query = "SELECT entity_id FROM " . $table . " WHERE " . $participantColumn . " = %1";
    $contactId = CRM_Core_DAO::singleValueQuery($query, [1 => [$sampleId, "String"]]);
    if ($contactId) {
      return (int)$contactId;
    }
    return FALSE;
  }

  /**
   * Method to get study id with study number
   *
   * @param $studyNumber
   * @return bool|int
   */
  public static function getStudyIdWithStudyNumber($studyNumber) {
    if (empty($studyNumber)) {
      return FALSE;
    }
    $table = CRM_Nihrbackbone_BackboneConfig::singleton()->getStudyDataCustomGroup('table_name');
    $studyNumberColumn = CRM_Nihrbackbone_BackboneConfig::singleton()->getStudyCustomField('nsd_study_number', 'column_name');
    $query = "SELECT entity_id FROM " . $table . " WHERE " . $studyNumberColumn . " = %1";
    $studyId = CRM_Core_DAO::singleValueQuery($query, [1 => [$studyNumber, "String"]]);
    if ($studyId) {
      return (int)$studyId;
    }
    return FALSE;
  }

  /**
   * Get recruitment case id for contact (there should only be one!)
   *
   * @param $contactId
   * @return bool|string
   */
  public static function getRecruitmentCaseId($contactId) {
    if (empty($contactId)) {
      return FALSE;
    }
    $query = "SELECT ccc.case_id
        FROM civicrm_case AS cc
            JOIN civicrm_case_contact AS ccc ON cc.id = ccc.case_id
        WHERE cc.is_deleted = %1 AND cc.case_type_id = %2 AND ccc.contact_id = %3";
    $caseId = CRM_Core_DAO::singleValueQuery($query, [
      1 => [0, "Integer"],
      2 => [(int) CRM_Nihrbackbone_BackboneConfig::singleton()->getRecruitmentCaseTypeId(), "Integer"],
      3 => [(int) $contactId, "Integer"],
    ]);
    if ($caseId) {
      return $caseId;
    }
    return FALSE;
  }

  /**
   * Get participation case id for contact (there should only be one but the latest active one will be selected)
   *
   * @param $studyId
   * @param $contactId
   * @return bool|string
   */
  public static function getParticipationCaseId($studyId, $contactId) {
    if (empty($contactId) || empty($studyId)) {
      return FALSE;
    }
    $table = CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationDataCustomGroup('table_name');
    $studyIdColumn = CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCustomField('nvpd_study_id', 'column_name');
    $query = "SELECT ccc.case_id
        FROM civicrm_case AS cc
        JOIN civicrm_case_contact AS ccc ON cc.id = ccc.case_id
        LEFT JOIN " . $table . " AS cvnpd ON cc.id = cvnpd.entity_id
        WHERE cc.is_deleted = %1 AND ccc.contact_id = %2 AND cc.case_type_id = %3 AND cvnpd." . $studyIdColumn . " = %4
        ORDER BY cc.id DESC LIMIT 1";
    $queryParams = [
      1 => [0, "Integer"],
      2 => [(int) $contactId, "Integer"],
      3 => [(int) CRM_Nihrbackbone_BackboneConfig::singleton()->getParticipationCaseTypeId(), "Integer"],
      4 => [(int) $studyId, "Integer"],
    ];
    $caseId = CRM_Core_DAO::singleValueQuery($query, $queryParams);
    if ($caseId) {
      return $caseId;
    }
    return FALSE;
  }
}
