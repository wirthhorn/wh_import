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

    $batch = array(
      'title' => t('importing books'),
      'init_message'     => t('Start importing books'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => 'demo_batch_complete_callback',
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
  }

  private function getEans(){
    //get all books
    $import_service = \Drupal::service('wh_import_vlb.vlb');
    $eans = $import_service->getAllEans();
    return $eans;
  }

}