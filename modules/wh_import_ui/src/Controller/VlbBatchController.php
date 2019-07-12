<?php

namespace Drupal\wh_import_ui\Controller;

/**
 * Class VlbBatchController.
 */
class VlbBatchController {

  /**
   * Importbooks.
   *
   * @return null|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Return markup string.
   */
  public function importBooks() {
    try {

      $batch = array(
        'title' => t('importing books'),
        'init_message'     => t('Start importing books'),
        'progress_message' => t('Processed @current out of @total.'),
        'error_message'    => t('An error occurred during processing'),
        'finished' => 'batchFinished',
        //'file' => drupal_get_path('module', 'demo_batch') . '/demo_batch.mybatch.inc',
      );
      //loop over nids
      $eans = $this->getEans();
      $limit = 0;
      foreach ($eans as $ean) {
        // $limit++;
        // if($limit > 20){
        //   break;
        // }
        $batch['operations'][] = ['\Drupal\wh_import_ui\Controller\VlbController::importBook',[$ean]];
      }
      batch_set($batch);
      return batch_process('user');
    }catch (\Exception $e) {
      \Drupal::logger('wh_import_batch')->error($e->getMessage());
    }
  }

  public function checkImportBooks() {
    try {

      $batch = array(
        'title' => t('importing books'),
        'init_message'     => t('Start importing books'),
        'progress_message' => t('Processed @current out of @total.'),
        'error_message'    => t('An error occurred during processing'),
        'finished' => 'batchFinished',
        //'file' => drupal_get_path('module', 'demo_batch') . '/demo_batch.mybatch.inc',
      );
      //loop over nids
      $eans = $this->getimportedEans();
      $limit = 0;
      foreach ($eans as $ean) {
        // $limit++;
        // if($limit > 20){
        //   break;
        // }
        $batch['operations'][] = ['\Drupal\wh_import_ui\Controller\VlbController::checkImportBook',[$ean]];
      }
      batch_set($batch);
      return batch_process('user');
    }catch (\Exception $e) {
      \Drupal::logger('wh_import_batch')->error($e->getMessage());
    }
  }

  private function getEans(){

  

    //get all books
    $import_service = \Drupal::service('wh_import_vlb.vlb');
    $eans = $import_service->getAllEans();
    return $eans;
  }

  private function getimportedEans(){

  
    $query = \Drupal::entityQuery('node');
 
     $query->condition('type', 'book');
     $entity_ids = $query->execute();
     return $entity_ids;
 
   }

  public function batchFinished($success, $results, $operations) {
    \Drupal::logger('wh_import_batch')->notice('$success: '.$success.' - $results: '.$results.' - $operations: '.$operations);
    if ($success) {
     drupal_set_message(t("The contents are successfully imported from VLB source."));
     \Drupal::logger('wh_import_batch')->notice("Batch Import successfull!");
    }
    else {
      $error_operation = reset($operations);
      drupal_set_message(t('An error occurred while processing @operation with arguments : @args', array('@operation' => $error_operation[0], '@args' => print_r($error_operation[0], TRUE))));
      \Drupal::logger('wh_import_batch')->error("Batch Import failed!");
    }
    dpm('$success: '.$success);
    dpm('$results: '.$results);
    dpm('$operations: '.$operations);


  }
}