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
    $now = new \Drupal\Core\Datetime\DrupalDateTime('now');
    $now = $now->format('Y-m-d H:i:s');
    try{
        $import_service = \Drupal::service('wh_import_vlb.vlb');
        $data = $import_service->getBookData($ean);
        if(!empty($data)){
            $import_service->reImportBook();
            $log_message = $import_service->getLogMessage();
            $this->logImport('['.$now. '] #'.$item->count.' '.$ean.$log_message, $log_prefix, 'ok');
        }else{
            $this->logImport($now. ' ' .$ean . ' : ' .'vlb empty', $log_prefix, 'error');
        }
    }catch(\Exception $e) {
        $this->logImport($now. ' ' .$ean . ' : ' .$e->getMessage(), $log_prefix, 'error');
    }

    try{
        //if last element -> send email
        if($item->count == $item->total){
          $this->sendEmail($log_prefix);
        }
    }catch(\Exception $e) {
      \Drupal::logger('wh_import_vlb')->error($e->getMessage());
    }
  }

  private function sendEmail($log_prefix){
    $logfiles = array();
    $logfiles['error'] = \Drupal::service('file_system')->realpath('public://'.$log_prefix.'_vlb_import_error.log');
    $file_content_error = '';
    if (file_exists($logfiles['error'])) {
      $file_content_error = file_get_contents($logfiles['error']);
    }
    $logfiles['ok'] = \Drupal::service('file_system')->realpath('public://'.$log_prefix.'_vlb_import_ok.log');
    $file_content_ok = file_get_contents($logfiles['ok']);

    $mailManager = \Drupal::service('plugin.manager.mail');
    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $params['context']['subject'] = "Logreport";
    $params['context']['message'] =  '<h1>Logreport</h1><br><br>Fehler<br>'.$file_content_error.'<br><br>Importiert:<br>'.$file_content_ok;
    $to = "haendel@wirth-horn.de, gaertner@wirth-horn.de, subasic@wirth-horn.de, wirth@wirth-horn.de";
    $result = $mailManager->mail('system', 'mail', $to, $langcode, $params);
    if($result['result'] != true) {
      $message = t('There was a problem sending your email notification to @email.', array('@email' => $to));
      \Drupal::logger('wh_import_vlb')->error($message);
    }else{
      $message = t('An email notification has been sent to @email ', array('@email' => $to));
      \Drupal::logger('wh_import_vlb')->notice($message);

      //delete logfiles, if exists
      foreach($logfiles as $logfile){
        if (file_exists($logfile)) {
          //delete logfile
          $result = unlink($logfile);
          if($result != true) {
            \Drupal::logger('wh_import_vlb')->error('Can not delete logfile: '.$logfile);
          }
        }
      }
    }    
  }

  private function logImport($message, $log_prefix, $status){
    $log_path = \Drupal::service('file_system')->realpath('public://'.$log_prefix.'_vlb_import_'.$status.'.log');
    error_log($message . PHP_EOL, 3, $log_path);
  }
}