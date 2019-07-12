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


  public static function checkImportBook($nid, &$context = NULL) {

    try{
      //  sleep(1);
        $import_service = \Drupal::service('wh_import_vlb.vlb');
        $match_categories = $import_service->checkNode($nid);

        $book = \Drupal::entityTypeManager()->getStorage('node')->load($nid);

        if(!$match_categories){
          //check if manually imported
          if(empty($book->field_book_manually_imported->value)){
            \Drupal::logger('wh_import_check')->notice('set to manually_imported'.$book->Id());
            $output = 'set to manually_imported'.$book->Id();
            $book->setNewRevision(TRUE);
            $book->set('field_book_manually_imported', 1);
            $book->save();
          }
        }

        if($match_categories) {
          //check if manually imported
          if(!empty($book->field_book_manually_imported->value)){
            \Drupal::logger('wh_import_check')->notice('unset manually_imported'.$book->Id());
            $output = 'unset manually_imported'.$book->Id();
            $book->setNewRevision(TRUE);
            $book->set('field_book_manually_imported', 0);
            $book->save();
          }
        }



        // \Drupal::logger('wh_import_test')->notice('process'.$book->Id());

        // $onix_codes = $book->field_book_category_onix_code->getValue();
        // $match_fr = false;
        // $match_fu = false;
        // $matching_categories = array();

        // $hep_onix_categories_str = 'FP,FS,FT,FRD,FRH,FRM,FRX,FUP,FMR';
        // $hep_onix_categories = explode(',', $hep_onix_categories_str);

        // $hep_onix_categories_start_str = '';
        // $hep_onix_categories_start = explode(',', $hep_onix_categories_start_str);

        // foreach($onix_codes as $onix_code){
        //     // echo $onix_code['value'].'<br>';
        //     $book_onix_code = $onix_code['value'];

        //     //check FR* and FU*
        //     //get first two chars of category
        //     $short_vlb_category_code = substr($book_onix_code,0,2);
        //     if($short_vlb_category_code === 'FR'){
        //       $match_fr = true;
        //     }elseif($short_vlb_category_code === 'FU'){
        //       $match_fu = true;
        //     }

        //     //check other categories, exact matching
        //     if (in_array($book_onix_code, $hep_onix_categories)) {
        //       $matching_categories[] = $book_onix_code;
        //     }

        //     //check category* matching
        //     if (in_array($short_vlb_category_code, $hep_onix_categories_start)) {
        //       $matching_categories[] = $book_onix_code;
        //     }

        // }
        // //Feelings
        // if($match_fr and $match_fu){
        //   $matching_categories[] = 'FR* and FU*';
        // }

        // $output = '';

        // if(empty($matching_categories)){
        //   //check if manually imported
        //   if(empty($book->field_book_manually_imported->value)){
        //     \Drupal::logger('wh_import_settest')->notice('set to manually_imported'.$book->Id());
        //     $output = 'set to manually_imported'.$book->Id();
        //     $book->set('field_book_manually_imported', 1);
        //     $book->save();
        //   }
        // }

        // if(!empty($matching_categories)) {
        //   //check if manually imported
        //   if(!empty($book->field_book_manually_imported->value)){
        //     \Drupal::logger('wh_import_unsettest')->notice('unset manually_imported'.$book->Id());
        //     $output = 'unset manually_imported'.$book->Id();
        //     $book->set('field_book_manually_imported', 0);
        //     $book->save();
        //   }
        // }

        // return array(
        //   '#markup' => '<p>'.$output.'</p>',
        // );


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
