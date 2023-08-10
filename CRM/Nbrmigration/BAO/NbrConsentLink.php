<?php
use CRM_Nbrmigration_ExtensionUtil as E;

class CRM_Nbrmigration_BAO_NbrConsentLink extends CRM_Nbrmigration_DAO_NbrConsentLink {

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
          CRM_Nbrpanelconsentpack_BAO_ConsentPackLink::createPackLink($consentActivityId, $contactId, $dao->cih_type_packid);
        }
        // find panel/site/centre id, create if not found
        $centrePanelSiteId = self::findPanelSiteCentreId($dao->centre, $dao->panel, $dao->site, $contactId, $logger);
        if ($centrePanelSiteId) {
          if (!self::isExistingPanelLink($centrePanelSiteId, $consentActivityId, $contactId)) {
            CRM_Nbrpanelconsentpack_BAO_ConsentPanelLink::createPanelLink($consentActivityId, $contactId, $centrePanelSiteId);
          }
        }
        else {
          $returnValue = "Could not find a centre-panel-site ID with centre: " . $dao->centre . ", panel: " . $dao->panel . " and site: " . $dao->site
            . " for participant " . $dao->cih_type_participant_id;
          $logger->logMessage($returnValue);
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
   * @param string $packId
   * @param int $consentActivityId
   * @param int $contactId
   * @return bool
   */
  public static function isExistingPackLink(string $packId, int $consentActivityId, int $contactId): bool {
    if ($packId && $consentActivityId) {
      try {
        $count = \Civi\Api4\ConsentPackLink::get()
          ->addSelect('id')
          ->addWhere('activity_id', '=', $consentActivityId)
          ->addWhere('contactId', '=', $contactId)
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
   * @param string $centreName
   * @param string $panelName
   * @param string $siteName
   * @param int $contactId
   * @param CRM_Nihrbackbone_NihrLogger $logger
   * @return int|NULL
   */
  public static function findPanelSiteCentreId(string $centreName, string $panelName, string $siteName, int $contactId, CRM_Nihrbackbone_NihrLogger $logger): ?int {
    $centrePanelSiteId = NULL;
    if ($contactId) {
      if (!empty($centreName) || !empty($panelName) || !empty($siteName)) {
        $query = "SELECT id FROM civicrm_value_nihr_volunteer_panel WHERE entity_id = %1";
        $queryParams = [1 =>[$contactId, "Integer"]];
        $index = 1;
        if (!empty($centreName)) {
          $centreId = self::getContactIdWithNameAndType('nbr_centre', $centreName);
          if ($centreId) {
            $index++;
            $query .= " AND nvp_centre = %" . $index;
            $queryParams[$index] = [$centreId, "Integer"];
          }
          else {
            $logger->logMessage("Could not find a centre contact with name " . $centreName);
          }
        }
        if (!empty($panelName)) {
          $panelId = self::getContactIdWithNameAndType('nbr_panel', $panelName);
          if ($panelId) {
            $index++;
            $query .= " AND nvp_panel = %" . $index;
            $queryParams[$index] = [$panelId, "Integer"];
          }
          else {
            $logger->logMessage("Could not find a panel contact with name " . $panelName);
          }
        }
        if (!empty($siteName)) {
          $siteId = self::getContactIdWithNameAndType('nbr_site', $siteName);
          if ($siteId) {
            $index++;
            $query .= " AND nvp_site = %" . $index;
            $queryParams[$index] = [$siteId, "Integer"];
          }
          else {
            $logger->logMessage("Could not find a site contact with name " . $siteName);
          }
        }
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
      $targetId = \Civi::service('nbrBackbone')->getTargetRecordTypeId();
      try {
        $activityDate = new DateTime($consentDate);
        try {
          $activity = \Civi\Api4\Activity::get()
            ->addSelect('id')->setCheckPermissions(FALSE)
            ->addJoin('ActivityContact AS act_contact', 'INNER', ['id', '=', 'act_contact.activity_id'])
            ->addWhere('nihr_volunteer_consent.nvc_consent_version:name', '=', $consentVersion)
            ->addWhere('activity_date_time', '=', $activityDate->format("YmdHis"))
            ->addWhere('is_deleted', '=', FALSE)
            ->addWhere('is_current_revision', '=', TRUE)
            ->addWhere('act_contact.record_type_id', '=', \Civi::service('nbrBackbone')->getTargetRecordTypeId())
            ->addWhere('act_contact.contact_id', '=', $contactId)
            ->setCheckPermissions(FALSE)->execute()->first();
          if (isset($activity['id'])) {
            $consentActivityId = (int) $activity['id'];
          }
        }
        catch (API_Exception $ex) {
          $logger->logMessage("Error trying to find consent activity for consent version " . $consentVersion
            . " and consent date " . $consentDate . ", error message from API4 Activity get: " . $ex->getMessage());
        }
      }
      catch (Exception $ex) {
        $logger->logMessage("Could not parse date " . $consentDate .", no consent activity found.");
      }
    }
    return $consentActivityId;
  }

}
