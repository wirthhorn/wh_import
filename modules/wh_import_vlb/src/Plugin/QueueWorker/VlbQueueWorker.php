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
    try{
        //sleep for 5 seconds
        // if (count($context) % 1000 == 0) { 
        //   sleep(10);
        // }
        sleep(1);
        $import_service = \Drupal::service('wh_import_vlb.vlb');
        $data = $import_service->getBookData($ean);
        if(!empty($data)){
            $import_service->reImportBook();
            // \Drupal::logger('wh_import_cron')->notice("Node imported EAN: ".$ean);
        }else{
            \Drupal::logger('wh_import_cron')->error($ean . ' : ' .'vlb empty');
        }
    }catch (\Exception $e) {
        \Drupal::logger('wh_import_cron')->error($ean . ' : ' .$e->getMessage());
    }
  }

}