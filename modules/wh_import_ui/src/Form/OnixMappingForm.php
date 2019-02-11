<?php

namespace Drupal\wh_import_ui\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wh_import_vlb\VlbService;
use Drupal\user\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class OnixMappingForm.
 */
class OnixMappingForm extends FormBase
{
    /**
   * @var \Drupal\user\PrivateTempStore
   */
    protected $tempstore;

    /**
     * Drupal\wh_import_vlb\VlbService definition.
     *
     * @var \Drupal\wh_import_vlb\VlbService
     */
    protected $vlbService;

    /**
     * Constructs a new VlbController object.
     */
    public function __construct(VlbService $vlbService, PrivateTempStoreFactory $temp_store_factory)
    {
        $this->vlbService = $vlbService;
        $this->tempstore = $temp_store_factory->get('wh_import_ui');
        $ean = $this->tempstore->get('ean');
        $new_categories = $this->tempstore->get('new_categories');
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('wh_import_vlb.vlb'),
            $container->get('user.private_tempstore')

        );
    }


    
    /**
     * Importbook.
     *
     * @return string
     *   Return Hello string.
     */
    public function importBook($ean)
    {
        try{
            $data = $this->vlbService->getBookData($ean);
            $manually = true;
            $bookNode = $this->vlbService->reImportBook($manually);

            $url = $bookNode->toUrl('edit-form')->toString();
            drupal_set_message("Book re/imported successfully!\n");
            $rendered_message = \Drupal\Core\Render\Markup::create('<a href="' . $url . '">Klick here to edit it.</a>');
            drupal_set_message($rendered_message);

        }catch(\Exception $e){
            \Drupal::logger('wh_import_ui')->error("Import failed. '.$ean.' Exception: ".$e->getMessage());
            drupal_set_message("Import failed. '.$ean.' Exception: ".$e->getMessage(), 'error');

        }
        
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'onix_mapping_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['Title'] = [
            '#type' => 'html_tag',
            '#tag' => 'h1',
            '#value' => $this
            ->t('Onix Kategorien Mapping'),
          ];

        $form['description'] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this
              ->t('Bitte ordnen Sie mindestens eine Onix-Kategore zu.'),
          ];

        $form['onix'] = array(
            '#type' => 'fieldset',
            // '#title' => $this->t('Onix Kategorien Mapping'),
          );
          
        //get datas
        $new_categories = $this->tempstore->get('new_categories');
        $drupal_categories = $this->getExistingCategories();
        $drupal_categories[0] = $this->t('nicht zugeordnet');
        foreach($new_categories as $new_categorie){
            // $form['onix']['categories'][$new_categorie] = [
                $form[$new_categorie] = [
                '#type' => 'select',
                '#title' => $new_categorie,
                '#options' => $drupal_categories,
                '#default_value' => 0,
              ];
        }
        
        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Speichern'),
            '#attributes' => array('class' => array('ean-submit')),
        ];
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        $new_categories = $this->tempstore->get('new_categories');
        $mapped = false;
        foreach($new_categories as $new_category){
            if($form_state->getValue($new_category) != 0){
                $mapped = true;
                break;
            }
        }
        if(!$mapped){
            $form_state->setErrorByName('onix', t('Es wurde keine Kategorie zugewiesen'));
        }
    }

    private function getNewCategories(){
        $new_categories = array();
        $new_categories = array('TTT','ZU');
        return $new_categories;
    }

    private function getExistingCategories(){
        $categories = array();
        //get Taxonomy Terms
        $query = \Drupal::entityQuery('taxonomy_term')->condition('vid', 'v_book_category');
        $v_book_category_tids = $query->execute();
        $v_book_category_terms = \Drupal\taxonomy\Entity\Term::loadMultiple($v_book_category_tids);
        foreach($v_book_category_terms as $term){
            $tid = $term->id();
            $name = $term->getName();
            $categories[$tid] = $name;
        }
        return $categories;
    }



    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
    
        //save categories
        $new_categories = $this->tempstore->get('new_categories');
        $mapped_categories = array();
        foreach($new_categories as $new_category){
            $tid = $form_state->getValue($new_category);
            if($tid != 0){
                $mapped_category['tid'] = $tid;
                $mapped_category['onix_codes'] = $new_category;
                $mapped_categories[] = $mapped_category;
            }
        }
        $this->onixMapping($mapped_categories);
        drupal_set_message('New Mapping saved','notice');

        //import book
        $ean = $this->tempstore->get('ean');
        $this->importBook($ean);
        
        $form_state->setRedirect('wh_import_ui.import_form');
    }

    //maps onix categories
    private function onixMapping($mapped_categories){
        foreach($mapped_categories as $mapped_category){
            $term = \Drupal\taxonomy\Entity\Term::load($mapped_category['tid']);
            $term->field_v_bc_onix_code[] = $mapped_category['onix_codes'];
            $term->save();
        }
    }

}
