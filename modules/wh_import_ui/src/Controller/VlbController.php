<?php

namespace Drupal\wh_import_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\wh_import_vlb\VlbService;

/**
 * Class VlbController.
 */
class VlbController extends ControllerBase {

  /**
   * Drupal\wh_import_vlb\VlbService definition.
   *
   * @var \Drupal\wh_import_vlb\VlbService
   */
  protected $vlbService;
  protected $eans;

  /**
   * Constructs a new VlbController object.
   */
  public function __construct(VlbService $vlbService) {
    $this->vlbService = $vlbService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('wh_import_vlb.vlb')
    );
  }

  public function importAction($action) {
    switch ($action) {
      case 'testAction':
          $this->getEans();
          $this->testAction();
          break;
      case 'batchProcess':
          break;
    }
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Book imported - action: '.$action)
    ];
  }
  
  public static function importBook($ean, &$context = NULL) {
    // dpm($context);
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
        $context['results'][] = $ean . ' : ' . 'ok';
        \Drupal::logger('wh_import_ui')->notice("Node imported EAN: ".$ean);
      }else{
        $context['results'][] = $ean . ' : ' . 'vlb empty';
      }
    }catch (\Exception $e) {
      $context['results'][] = $ean . ' : ' . 'error: '.$e->getMessage();
      \Drupal::logger('wh_import_batch')->error($ean . ' : ' .$e->getMessage());
    }
  }
  
  private function getEans(){
    //get all books
    $import_service = \Drupal::service('wh_import_vlb.vlb');
    $eans = $import_service->getAllEans();
    $this->$eans =  $eans;
  }

  private function testAction() {
    //$data = $this->vlbService->getBookData($ean);
    //$node = $this->vlbService->reImportBook();
  }
}
