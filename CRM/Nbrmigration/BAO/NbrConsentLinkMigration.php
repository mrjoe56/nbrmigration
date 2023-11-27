<?php
use CRM_Nbrmigration_ExtensionUtil as E;

class CRM_Nbrmigration_BAO_NbrConsentLinkMigration extends CRM_Nbrmigration_DAO_NbrConsentLinkMigration {

  /**
   * Method to migrate consent pack and panel links
   *
   * @param CRM_Core_DAO $dao
   * @param CRM_Nihrbackbone_NihrLogger $logger
   * @return string
   * @throws API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function migrate(CRM_Core_DAO $dao, CRM_Nihrbackbone_NihrLogger $logger): string {
    $returnValue = "migrated";
    // find contact with participant id, log error if not found
    $contactId = self::getContactIdWithParticipantId($dao->cih_type_participant_id);
    if ($contactId) {
      // find consent activity, log error if not found
      $consentActivityId = self::findConsentActivityId($dao->consent_version, $dao->consent_date, $contactId, $logger);
      if ($consentActivityId) {
        if (!self::isExistingPackLink($dao->cih_type_packid, $consentActivityId, $contactId)) {
          if ($dao->cih_type_packid) {
            CRM_Nbrpanelconsentpack_BAO_ConsentPackLink::createPackLink($consentActivityId, $contactId, $dao->cih_type_packid, $dao->pack_id_type,  "migration");
          }
        }
        // find panel/site/centre id, create if not found
        $centrePanelSiteId = self::findPanelSiteCentreId($dao->centre, $dao->panel, $dao->site, $contactId, $logger);
        if ($centrePanelSiteId) {
          if (!self::isExistingPanelLink($centrePanelSiteId, $consentActivityId, $contactId)) {
            CRM_Nbrpanelconsentpack_BAO_ConsentPanelLink::createPanelLink($consentActivityId, $contactId, $centrePanelSiteId, 'migration');
          }
        }
        else {
          $returnValue = "No centre/panel/site found for participant " . $dao->cih_type_participant_id;
        }
      }
      else {
        $returnValue = "No consent activity found for participant " . $dao->cih_type_participant_id;
        $logger->logMessage($returnValue);
      }
    }
    else {
      $returnValue = "No contact found for participant " . $dao->cih_type_participant_id;
      $logger->logMessage($returnValue);
    }
    return $returnValue;
  }

  /**
   * Method to get contact ID with participant ID
   *
   * @param string $participantId
   * @return int|null
   */
  public static function getContactIdWithParticipantId(string $participantId): ?int {
    $contactId = NULL;
    if ($participantId) {
      try {
        $contactIdentity = \Civi\Api4\CustomValue::get('contact_id_history')
          ->addSelect('entity_id')
          ->addWhere('id_history_entry_type:name', '=', 'cih_type_participant_id')
          ->addWhere('id_history_entry', '=', $participantId)
          ->setCheckPermissions(FALSE)->execute()->first();
        if (isset($contactIdentity['entity_id'])) {
          $contactId = (int) $contactIdentity['entity_id'];
        }
      }
      catch (API_Exception $ex) {
      }
    }
    return $contactId;
  }

  /**
   * Method to check if pack link already exists
   * @param string|NULL $packId
   * @param int $consentActivityId
   * @param int $contactId
   * @return bool
   */
  public static function isExistingPackLink(?string $packId, int $consentActivityId, int $contactId): bool {
    if ($packId && $consentActivityId) {
      try {
        $count = \Civi\Api4\ConsentPackLink::get()
          ->addSelect('id')
          ->addWhere('activity_id', '=', $consentActivityId)
          ->addWhere('contact_id', '=', $contactId)
          ->addWhere('pack_id', '=', $packId)
          ->setCheckPermissions(FALSE)->execute()->count();
        if ($count > 0) {
          return TRUE;
        }
      }
      catch (API_Exception $ex) {
      }
    }
    return FALSE;
  }

  /**
   * Method to check if link between consent activity and centre/panel/site is already there
   *
   * @param int $centrePanelSiteId
   * @param int $consentActivityId
   * @param int $contactId
   * @return bool
   * @throws API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function isExistingPanelLink(int $centrePanelSiteId, int $consentActivityId, int $contactId): bool {
    if ($centrePanelSiteId && $consentActivityId) {
      $count = \Civi\Api4\ConsentPanelLink::get()
        ->addSelect('id')
        ->addWhere('panel_etc_id', '=', $centrePanelSiteId)
        ->addWhere('activity_id', '=', $consentActivityId)
        ->addWhere('contact_id', '=', $contactId)
        ->setCheckPermissions(FALSE)->execute()->count();
      if ($count > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Method to find centre/panel/site ID with name
   *
   * @param string|NULL $centreName
   * @param string|NULL $panelName
   * @param string|NULL $siteName
   * @param int $contactId
   * @param CRM_Nihrbackbone_NihrLogger $logger
   * @return int|NULL
   */
  public static function findPanelSiteCentreId(?string $centreName, ?string $panelName, ?string $siteName, int $contactId, CRM_Nihrbackbone_NihrLogger $logger): ?int {
    $centrePanelSiteId = NULL;
    if ($contactId) {
      if (!empty($centreName) || !empty($panelName) || !empty($siteName)) {
        $query = "SELECT id FROM civicrm_value_nihr_volunteer_panel WHERE entity_id = %1";
        $queryParams = [1 =>[$contactId, "Integer"]];
        $index = 1;
        self::addWhere('nbr_centre', 'nvp_centre', $centreName, $query, $queryParams, $index);
        self::addWhere('nbr_panel', 'nvp_panel', $panelName, $query, $queryParams, $index);
        self::addWhere('nbr_site', 'nvp_site', $siteName, $query, $queryParams, $index);
        if ($index > 1) {
          $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
          if ($dao->N > 1) {
            $logger->logMessage("More than one center-panel-site record found for contact ID " . $contactId . " with data: " . json_encode($queryParams));
          }
          if ($dao->fetch()) {
            $centrePanelSiteId = (int) $dao->id;
          }
        }
      }
    }
    return $centrePanelSiteId;
  }

  /**
   * Method to add where for centre / panel / site
   *
   * @param string $getContactType
   * @param string $whereContactType
   * @param string $contactName
   * @param string $query
   * @param array $queryParams
   * @param int $index
   * @return void
   */
  private static function addWhere(string $getContactType, string $whereContactType, string $contactName, string &$query, array &$queryParams, int &$index): void {
    $where = " AND " . $whereContactType . " IS NULL";
    if (!empty($contactName)) {
      $contactId = self::getContactIdWithNameAndType($getContactType, $contactName);
      if ($contactId) {
        $index++;
        $where = " AND " . $whereContactType . " = %" . $index;
        $queryParams[$index] = [$contactId, "Integer"];
      }
    }
    $query .= $where;
  }


  /**
   * Method to get contact ID of centre-panel-site with name
   *
   * @param string $type
   * @param string $contactName
   * @return int|null
   */
  public static function getContactIdWithNameAndType(string $type, string $contactName): ?int {
    $contactId = NULL;
    if ($contactName && $type) {
      try {
        $contact = \Civi\Api4\Contact::get()
          ->addSelect('id')
          ->addWhere('contact_sub_type:name', '=', $type)
          ->addWhere('organization_name', '=', $contactName)
          ->setCheckPermissions(FALSE)->execute()->first();
        if (isset($contact['id'])) {
          $contactId = (int) $contact['id'];
        }
      }
      catch (API_Exception $ex) {
      }
    }
    return $contactId;
  }

  /**
   * Method to find consent activity ID with consent date and consent version
   *
   * @param string $consentVersion
   * @param string $consentDate
   * @param int $contactId
   * @param CRM_Nihrbackbone_NihrLogger $logger
   * @return int|null
   */
  public static function findConsentActivityId(string $consentVersion, string $consentDate, int $contactId, CRM_Nihrbackbone_NihrLogger $logger): ?int {
    $consentActivityId = NULL;
    if ($consentDate && $consentVersion) {
      try {
        $activityDate = new DateTime($consentDate);
        $query = "SELECT a.id
            FROM civicrm_activity a
                JOIN civicrm_activity_contact b ON a.id = b.activity_id
                JOIN civicrm_value_nihr_volunteer_consent c ON a.id = c.entity_id
            WHERE b.contact_id = %1 AND b.record_type_id = %2 AND (activity_date_time BETWEEN %3 AND %4) AND c.nvc_consent_version = %5 ORDER BY id DESC LIMIT 1";
        $queryParams = [
          1 => [$contactId, 'Integer'],
          2 => [\Civi::service('nbrBackbone')->getTargetRecordTypeId(), 'Integer'],
          3 => [$activityDate->format("Y-m-d") . " 00:00:00", "String"],
          4 => [$activityDate->format("Y-m-d") . " 23:59:59", "String"],
          5 => [$consentVersion, "String"],
        ];
        $consentActivityId = CRM_Core_DAO::singleValueQuery($query, $queryParams);
        if (!$consentActivityId) {
          $logger->logMessage("Could not find a consent activity id for contact ID " . $contactId . " and consent version "
            . $consentVersion . " on consent date " . $consentDate);
        }
      }
      catch (Exception $ex) {
        $logger->logMessage("Could not parse date " . $consentDate .", no consent activity found.");
      }
    }
    return $consentActivityId;
  }
}
