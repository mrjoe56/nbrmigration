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
    $table = "civicrm_value_nihr_volunteer_ids";
    $participantColumn = "nva_participant_id";
    if (!CRM_Core_DAO::checkTableExists($table) || !CRM_Core_BAO_SchemaHandler::checkIfFieldExists($table, $participantColumn)) {
      return FALSE;
    }
    $query = "SELECT entity_id FROM " . $table . " WHERE " . $participantColumn . " = %1";
    $contactId = CRM_Core_DAO::singleValueQuery($query, [1 => [$sampleId, "String"]]);
    if ($contactId) {
      return (int) $contactId;
    }
    return FALSE;
  }
}
