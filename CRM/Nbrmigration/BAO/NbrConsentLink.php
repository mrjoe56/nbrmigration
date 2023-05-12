<?php
use CRM_Nbrmigration_ExtensionUtil as E;

class CRM_Nbrmigration_BAO_NbrConsentLink extends CRM_Nbrmigration_DAO_NbrConsentLink {

  /**
   * Create a new NbrConsentLink based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Nbrmigration_DAO_NbrConsentLink|NULL
   *
  public static function create($params) {
    $className = 'CRM_Nbrmigration_DAO_NbrConsentLink';
    $entityName = 'NbrConsentLink';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
