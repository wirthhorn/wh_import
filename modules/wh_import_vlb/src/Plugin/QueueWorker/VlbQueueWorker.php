<?php  
/**
 * @file
 * Contains \Drupal\wh_import_vlb\Plugin\QueueWorker\VlbQueueWorker.
 */

namespace Drupal\wh_import_vlb\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\wh_import_vlb\VlbService;

/**
 * Processes tasks for example module.
 *
 * @QueueWorker(
 *   id = "vlb_queue",
 *   title = @Translation("VLB: Queue worker"),
 *   cron = {"time" = 100}
 * )
 */
class VlbQueueWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    $ean = $item->ean;
    $log_prefix = $item->log_prefix;
   
    try{
        $import_service = \Drupal::service('wh_import_vlb.vlb');
        $data = $import_service->getBookData($ean);
        if(!empty($data)){
            $import_service->reImportBook();
            // \Drupal::logger('wh_import_cron')->notice("Node imported EAN: ".$ean);
            $log_message = $import_service->getLogMessage();
            $this->logImport($item->count.' '.$ean.' '.$log_message, $log_prefix);
        }else{
            $this->logErrorImport($ean . ' : ' .'vlb empty', $log_prefix);
            // \Drupal::logger('wh_import_cron')->error($ean . ' : ' .'vlb empty');
        }
    }catch (\Exception $e) {
        
        $this->logErrorImport($ean . ' : ' .$e->getMessage(), $log_prefix);
        // \Drupal::logger('wh_import_cron')->error($ean . ' : ' .$e->getMessage());
    }
  }

  private function logErrorImport($message, $log_prefix){
   
    $log_path = \Drupal::service('file_system')->realpath('public://vlb_import_error'.$log_prefix.'.log');
    error_log($message . PHP_EOL, 3, $log_path);
     
  }

  private function logImport($message, $log_prefix){
   
    $log_path = \Drupal::service('file_system')->realpath('public://vlb_import_ok'.$log_prefix.'.log');
    error_log($message . PHP_EOL, 3, $log_path);
  }

}