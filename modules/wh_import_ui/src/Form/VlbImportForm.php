<?php

namespace Drupal\wh_import_ui\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wh_import_vlb\VlbService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class VlbImportForm.
 */
class VlbImportForm extends FormBase
{

    /**
     * Drupal\wh_import_vlb\VlbService definition.
     *
     * @var \Drupal\wh_import_vlb\VlbService
     */
    protected $vlbService;

    /**
     * Constructs a new VlbController object.
     */
    public function __construct(VlbService $vlbService)
    {
        $this->vlbService = $vlbService;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('wh_import_vlb.vlb')
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
            $bookNode = $this->vlbService->reImportBook();

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
        return 'vlb_import_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['ean'] = [
            '#type' => 'textfield',
            '#title' => $this->t('ISBN-13/EAN '),
            '#maxlength' => 64,
            '#size' => 64,
            '#weight' => '0',
        ];
        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Importieren'),
            '#attributes' => array('class' => array('ean-submit')),
        ];
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        if(empty($form_state->getValue('ean'))) {
          $form_state->setErrorByName('ean', t('Sorry, ISBN-13 can not be empty.'));
      }
      // parent::validateForm($form, $form_state);

    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
       $ean = str_replace ('-','',$form_state->getValue('ean'));
       $ean = trim($ean);
        $this->importBook($ean);
    }
}
