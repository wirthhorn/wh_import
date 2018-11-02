<?php

namespace Drupal\wh_import_vlb;
use Drupal\node\Entity\Node;
//use Drupal\wh_affiliate_links\GenerateAffiliateLinks;

/**
 * Class VlbService.
 */
class VlbService
{

    protected $error_message = '';
    protected $metaData;
    protected $ean;
    protected $metadataToken;
    protected $coverToken;
    protected $data;

    /**
     * Constructs a new VlbService object.
     */
    public function __construct()
    {
      $this->init();
    }

    /**
     * get Value from Array
     */
    private function getValue($var)
    {
        if(isset($var)){
          return $var;
        }else{
          return null;
        }
    }

    //get VLB config values
    private function init(){
      $config = \Drupal::config('wh_import_vlb.config');
      $this->metadataToken  = $config->get('metadata_token');
      $this->coverToken  = $config->get('cover_token');
    }

    public function getAllEans(){      
      $verlage = array('Droemer Knaur','S. Fischer','Argon Droemer','S.Fischer','Rowohlt','Kiepenheuer & Witsch','ArgonDroemer eBook',
      'Fischer digiBook','Rowohlt Berlin','E-Books im Verlag Kiepenheuer & Witsch','Argon Sauerländer Audio ein Imprint von Argon Droemer Taschenbuch',
      'Fischer Digital','ROWOHLT Kindler','Argon Balance ein Imprint v. Argon Verlag Knaur',
      'Fischer E-Books','ROWOHLT Polaris','Knaur eBook','Fischer FJB','ROWOHLT Repertoire','Knaur Taschenbuch','Fischer HC','ROWOHLT Taschenbuch',
      'Knaur Balance','Fischer Kinder-und Jugendbuch E-Book','ROWOHLT Wunderlich','Knaur Balance eBook','Fischer Kinder-und Jugendtaschenbuch','Ro Ro Ro',
      'Groh','Fischer KJB','Rowohlt e-Book','Pattloch Geschenkbuch','Fischer Krüger','Rowohlt Hundertaugen','Fischer Sauerländer','Fischer Scherz','Fischer Taschenbuch',
      'Fischer TOR');

      $search_verlage = array();
      foreach($verlage as $verlag){
        $search_verlage[] = 'vl='.$verlag;
      }
      $search_verlage_str = implode(" oder ",$search_verlage);
      
      $onix_codes = array();
      //get VLB Codes
      $query = \Drupal::entityQuery('taxonomy_term')->condition('vid', 'v_book_category');
      $v_book_category_tids = $query->execute();
      $v_book_category_terms = \Drupal\taxonomy\Entity\Term::loadMultiple($v_book_category_tids);
      foreach($v_book_category_terms as $term){
        $v_bc_onix_codes = $term->get("field_v_bc_onix_code")->getValue();
        foreach($v_bc_onix_codes as $v_bc_onix_code){
          $v_bc_onix_code = $v_bc_onix_code['value'];
          if(strpos($v_bc_onix_code, '+') != false){
            $v_bc_onix_codes = explode('+', $v_bc_onix_code);
            $categories_th = array();
            foreach($v_bc_onix_codes as $v_bc_onix_code){
              $categories_th[] = 'th='.$v_bc_onix_code;
            }
            $categories_th_str = implode(" und ",$categories_th);
            $onix_codes[] = '('.$categories_th_str.')';
          }else{
            $onix_codes[] = 'th='.$v_bc_onix_code;
          }
        }
      }
      $onix_codes_str = implode(" oder ",$onix_codes);
      $search_str = '(('.$search_verlage_str.') und ('.$onix_codes_str.')) und db=vlb';
      $search_str = urlencode($search_str);

      $responseData = array();
      $data = array();
      
          $client = \Drupal::httpClient();
          $vlbMetadatenToken = $this->metadataToken;
          $url = 'https://vlb.de/app/#search/advancedsearch/' . $search_str;
          $url = 'https://vlb.de/v1/' . $search_str;
          
          $url = 'https://api.vlb.de/api/v1/product/9783426306055/isbn13';
          $url = 'https://api.vlb.de/api/v1/login';
          $method = 'GET';
            
        $options = [
          'headers' => [
              'Authorization' => 'Bearer '.$vlbMetadatenToken,
          ],
      ];

      $url = 'http://api.vlb.de/api/v1/products/?size=250&search='.$search_str;

      $ean = array();
      $total_pages = 1;
      try {
          $response = $client->request($method, $url, $options);
          $code = $response->getStatusCode();
          if ($code == 200) {
                $responseData = $response->getBody()->getContents();
                $var = json_decode($responseData, true);
                $total_pages = $var['totalPages'];
                $contents = $var['content'];
                $this->getEans($ean, $contents);
          }else{
            watchdog_exception('Bookimport - vlb', "Import failed! VLB code: ".$code);
            $this->setErrorMessage("Import failed! VLB code: ".$code);
            return null;
          }
      }catch (\Exception $e) {
          // Logs an error
          \Drupal::logger('wh_import_vlb')->error("Import failed! Wrong vlb request. Exception: ".$e);
          return null;
      }
      //next pages
      for($page_number = 2; $page_number <= $total_pages; $page_number++ ){
        $url = 'http://api.vlb.de/api/v1/products/?page='.$page_number.'&size=250&search='.$search_str;
        try {
          $response = $client->request($method, $url, $options);
          $code = $response->getStatusCode();
          if ($code == 200) {
              $responseData = $response->getBody()->getContents();
              $var = json_decode($responseData, true);
              $contents = $var['content'];
              $this->getEans($ean, $contents);
          }else{
            watchdog_exception('Bookimport - vlb', "Import failed! VLB code: ".$code);
            $this->setErrorMessage("Import failed! VLB code: ".$code);
            return null;
          }
        }catch (\Exception $e) {
            // Logs an error
            \Drupal::logger('wh_import_vlb')->error("Import failed! Wrong vlb request. Exception: ".$e);
            return null;
        }
      }

      \Drupal::logger('wh_import_vlb')->notice("Search ok.");
      return $ean;
    }

    public function getEans(&$ean, $contents){
      foreach($contents as $content){
        $ean[]  = $content['gtin'];
       }
    }


    public function getBookData($ean)
    {
        $responseData = array();
        $data = array();
        $this->ean = $ean;
        if (!empty($ean)) {
            $client = \Drupal::httpClient();
            $vlbMetadatenToken = $this->metadataToken;
            $url = 'https://api.vlb.de/api/v1/product/' . $ean . '/isbn13';
            $method = 'GET';
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer '.$vlbMetadatenToken,
                ],
            ];
            try {
                $response = $client->request($method, $url, $options);
                $code = $response->getStatusCode();
                if ($code == 200) {
                    $responseData = $response->getBody()->getContents();
                    $var = json_decode($responseData, true);
                    // kint($var);
                    $title = $this->getTitle($var['titles']);
                    $data['title'] = $title['title'];
                    $data['subtitle'] = $title['subtitle'];
                    $data['category_codes'] = $this->getCategories($var['classifications']);
                    $data['publication_date'] = $this->getReleaseDate($var['publicationDate']); // 09.06.2010
                    $data['description'] = $this->getDescription($var['texts']);
                    $data['biographies'] = $this->getBiographies($var['texts']);
                    $data['persons'] = $this->getPersons($var['contributors']);
                    $data['price'] = $this->getPrice($var['prices']);
                    $data['cover'] = $this->getCover();
                    $data['press'] = $this->getPress($var['texts']);
                    $data['pages'] = $this->getPages($var['extent']);
                    $data['binding'] = $this->getBinding($var['form']);
                    $data['publishers'] = $this->getPublishers($var['publishers']);
                    $data['series'] = $this->getSeries($var['collections']);
                    // dpm($data);
                }else{
                  watchdog_exception('Bookimport - vlb', "Import failed! VLB code: ".$code);
                  $this->setErrorMessage("Import failed! VLB code: ".$code);
                  return null;
                }
            } catch (\Exception $e) {
                // Logs an error
                \Drupal::logger('wh_import_vlb')->error("Import failed! Wrong vlb request. Exception: ".$e);
                return null;
                //$this->setErrorMessage("import failed for " . $ean . '<pre>' . var_export($responseData, true) . '</pre>');
            }
        }
        $this->data = $data;
        \Drupal::logger('wh_import_vlb')->notice("Import ok. EAN: ".$ean);
        return $data;
    }

    private function getPublishers($publishers){
      $my_publishers = array();
      //get all publishers
      $publisher_keys = $this->findInArray($publishers, 'type', '01');
      foreach($publisher_keys as $key => $value){
        $publisher = $publishers[$value];
        $my_publisher['name'] = $publisher['name'];
        $my_publisher['id'] = $publisher['publisherId'];
        $my_publishers[] = $my_publisher;
      }
      return $my_publishers;
    }

    private function getSeries($collections){
      $series = array();
      //get all series
      foreach($collections as $serie){
        if(isset($serie['master']['type']) && ($serie['master']['type'] == 'series') && !empty($serie['master']['title'])){
          $series[] = $serie['master']['title'];
        }
      }
      return $series;
    }

    private function getBinding($form){
      if(isset($form['type'])){
        return $form['type'];
      }
      return null;
    }

    private function getPages($extent){
      if(isset($extent['pages'])){
        return $extent['pages'];
      }
      return null;
    }

    private function getPress($texts){
      $press = array();
      $press_keys = $this->findInArray($texts, 'type', '08');
      foreach($press_keys as $key => $value){
        $text = $texts[$value];
        $press[] = $text['value'];
      }
      return $press;
    }

    private function getPersons($contributors){
      $persons = array();
      $person_keys = $this->findInArray($contributors, 'type', 'A01');
      //get all persons
      foreach($contributors as $contributor){
        $person['firstName'] = $contributor['firstName'];
        $person['lastName'] = $contributor['lastName'];
        $person['type'] = $contributor['type'];
        $person['biographicalNote'] = $contributor['biographicalNote'];
        $persons[] = $person;
      }
      return $persons;
    }

    private function getBiographies($texts){
      $biographies = array();
      $biography_keys = $this->findInArray($texts, 'type', '13');
      foreach($biography_keys as $key => $value){
        $text = $texts[$value];
        $biography = $text['value'];
        $biographies[] = $biography;
      }
      return $biographies;
    }

    private function getAuthors($contributors){
      $authors = array();
      $author_keys = $this->findInArray($contributors, 'type', 'A01');
      foreach($author_keys as $key => $value){
        $contributor = $contributors[$value];
        $author['firstName'] = $contributor['firstName'];
        $author['lastName'] = $contributor['lastName'];
        $author['vlb_id'] = $contributor['id'];
        $authors[] = $author;
      }
      return $authors;
    }

    private function getPrice($prices){
      $german_price = 0;
      $price_keys = $this->findInArray($prices, 'type', '04');
      foreach($price_keys as $key => $value){
        $price = $prices[$value];
        if($price['country'] == 'DE'){
          $german_price = $price['value'];
        }
      }
      return $german_price;
    }

    private function getDescription($texts){
      $bestResult = array();
      foreach($texts as $key => $value ){
        if($value['type'] == "01"){
          return $value['value'];
        }elseif(empty($bestResult) || (intval($value['type']) < $bestResult['type'])){
          $bestResult['type'] = intval($value['type']);
          $bestResult['value'] = $value['value'];
        }
      }
      return $bestResult['value'];
    }

    private function getTitle($titles){
      $title = array();
      foreach($titles as $key => $value ){
        if($value['type'] == "01"){
          $title['subtitle'] = $value['subtitle'];
          $title['title'] = $value['title'];
          break;
        }
      }
      return $title;
    }

    private function bookExists($ean){
      $books = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['field_book_ean' => $ean]);
        if(count($books) >= 1){
         return true;
        }
        return false;
    }

    private function getCategories($classifications){
      $category_codes = array();
      $category_keys = $this->findInArray($classifications, 'type', '93');
      foreach($category_keys as $key => $value ){
        $category_codes[] = $classifications[$value]['code'];
      }
      return array_unique($category_codes);
    }

    private function getCover(){
      $file = array();

      // Create the styles directory and ensure it's writable.
      $directory = 'book';
      
      //let hook to change file-dir
      \Drupal::moduleHandler()->alter('change_import_file_dir', $directory);

      $dir_ok = file_prepare_directory($directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

      $vlbCoverToken = $this->coverToken;
      $remote_file_path = 'https://api.vlb.de/api/v1/cover/'.$this->ean.'/l';

      $options = [
          'headers' => [
              'Authorization' => 'Bearer '.$vlbCoverToken,
          ],
      ];

      // Destination file to download
      $destination = $directory.'/cover_'.$this->ean.'.jpg';
      $client = \Drupal::httpClient();

      try {
        $response = $client->request('GET', $remote_file_path, $options);
        $code = $response->getStatusCode();

        if ($code == 200) {
          $data = (string) $response->getBody();
          $managed = true;
          $local = $managed ? file_save_data($data, $destination, FILE_EXISTS_REPLACE) : file_unmanaged_save_data($data, $path, $replace);
          $file['fid'] = $local->id();
        }
      } catch (RequestException $e) {
          watchdog_exception('jsonapi', $e);
      }
      return $file;
    }

    private function findInArray($multi_array, $field, $value)
    {
      $keys = array();
      foreach($multi_array as $key => $product)
      {
          if (isset($product[$field]) && ($product[$field] === $value) )
          $keys[] = $key;
      }
      return $keys;
    }

    private function getReleaseDate($date){
      //change dateFormate from d.m.Y to Y-m-d
      $date = \DateTime::createFromFormat('d.m.Y', $date);
      if($date != false){
        $date = $date->format('Y-m-d'); // => 2013-12-24
      }
      return $date;
    }

    private function updateBookNode(){
      $books = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['field_book_ean' => $this->ean]);
        if(empty($books) || count($books) > 1){
          $this->setErrorMessage("Import failed! Book with EAN ".$this->ean." exists ".count($books)."-times");
          // Logs an error
          \Drupal::logger('wh_import_vlb')->error("Import failed! Book with EAN ".$this->ean." exists ".count($books)."-times");
          return null;
        }
        $book = reset($books);
        $this->setBookValues($book);
        $book->save();
        return $book;
    }

    private function getPersonRole($type){
      $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['field_v_p_type' => $type]);
      if(empty($terms)){
        return null;
      }
      $term = reset($terms);
      return $term->id();
    }

    private function setBookValues(&$node){
      try{
        $node->set('title', $this->data['title']);

        $personNodes = $this->setPersonValues();
        $persons = array();
        foreach($personNodes as $personNode){
          $person['target_id'] = $personNode['nid'];
          $person['value'] = $personNode['role'];
          $persons[] = $person;
        }
        $node->set('field_book_person', $persons);

        $description = [
        'value' => $this->data['description'],
        'binding' => 'basic_html',
        ];
        $node->set('field_book_description', $description);

        $node->set('uid', \Drupal::currentUser()->id());
        $node->status = 1;
        
        //set other fields
        if(isset($this->data['binding'])){
          $this->setBinding($node);
        }
        if(isset($this->data['series'])){
          $this->setSeries($node);
        }
        if(isset($this->data['subtitle'])){
          $node->set('field_book_subtitle', $this->data['subtitle']);
        }
        if(isset($this->data['pages']) && $this->data['pages'] != null){
          $node->set('field_book_pages', $this->data['pages']);
        }
        if(!empty($this->data['price'])){
          $node->set('field_book_price', $this->data['price']);
        }
        if(!empty($this->data['press'])){
          $node->set('field_book_press', $this->data['press']);
        }
        $this->setPublishers($node);
        $this->setCategories($node);
        if($this->data['publication_date'] != false){
          $node->field_book_release_date->value = $this->data['publication_date'];
        }
        $this->setCover($node);
        
        $node->set('field_book_ean', $this->ean.'');
        //todo
        //$affiliateLink = GenerateAffiliateLinks::aws_itemlookup($ean);
        //$node->set('field_affiliate_amazon', $affiliateLink);
        
      }catch(\Exception $e){
        \Drupal::logger('wh_import_vlb')->error("Import failed! Cannot set book-values.! Book with EAN ".$this->ean);
        watchdog_exception('Bookimport - nodecreate', $e);
        $this->setErrorMessage("Import failed! Cannot create book.");
        return null;
      }
      \Drupal::logger('wh_import_vlb')->debug("Re/Import success! Book with EAN ".$this->ean." re/imported");
    }

    private function getBiorgraphy($person){
      $biography = '';
      if(!empty($person['biographicalNote'])){
        $biography = $person['biographicalNote'];
      }else{
        foreach($this->data['biographies'] as $biographie_searchstr){
          if (strpos($biographie_searchstr, $person['lastName']) !== false) {
            $biography = $biographie_searchstr;
          }
        }
      }
      return $biography;
    }

    private function setPublishers(&$node){
      $personNodes = array();
      $publisher_tids = array();
      //Create Persons(s)
      foreach($this->data['publishers'] as $publisher){
        //check, if publisher-term already exists
        $options = ['field_v_bp_import_id' => $publisher['id']];
        $publisher_terms = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->loadByProperties($options);
        $publisher_term = reset($publisher_terms);
        //create term, if not exists
        if($publisher_term === FALSE){
          try{
              $term = \Drupal\taxonomy\Entity\Term::create(array(
              'parent' => array(),
              'name' => $publisher['name'],
              'field_v_bp_import_id' => $publisher['id'],
              'vid' => 'v_book_publisher',
            ));
            $term->save();
            $publisher_tids[] = $term->id();
          }catch(\Exception $e){
            watchdog_exception('Bookimport - nodecreate publisher', $e);
            $this->setErrorMessage("Import failed! Cannot creat publisher for the book.");
            return null;
          }
        }else{
          $publisher_tids[] = $publisher_term->id();
        }
      }
      $node->set('field_book_publisher', $publisher_tids);
    }

    private function setSeries(&$node){
      $book_series_term_ids = array();
      foreach($this->data['series'] as $serie_title){
        //check, if serie-term already exists
        $options = ['name' => $serie_title];
        $book_series_terms = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->loadByProperties($options);
            $book_series_term = reset($book_series_terms);
        //create term, if not exists
        if($book_series_term === FALSE){
          try{
              $term = \Drupal\taxonomy\Entity\Term::create(array(
              'parent' => array(),
              'name' => $serie_title,
              'vid' => 'v_book_series',
            ));
            $term->save();
            $book_series_term_ids[] = $term->id();
          }catch(\Exception $e){
            watchdog_exception('Bookimport - nodecreate series', $e);
            $this->setErrorMessage("Import failed! Cannot creat series for the book.");
            return null;
          }
        }else{
          $book_series_term_ids[] = $book_series_term->id();
        }
        
      }
      $node->set('field_book_v_bs_series', $book_series_term_ids);
    }

    private function setBinding(&$node){
        $book_binding_term_id = 0;
        //check, if binding-term already exists
        $options = ['field_v_bb_onix_type' => $this->data['binding'] ];
        $book_binding_terms = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->loadByProperties($options);
        $book_binding_term = reset($book_binding_terms);
        //create term, if not exists
        if($book_binding_term === FALSE){
          try{
              $term = \Drupal\taxonomy\Entity\Term::create(array(
              'parent' => array(),
              'name' => 'unknown',
              'field_v_bb_onix_type' => $this->data['binding'],
              'vid' => 'v_book_bindings',
            ));
            $term->save();
            $book_binding_term_id = $term->id();
          }catch(\Exception $e){
            watchdog_exception('Bookimport - nodecreate binding', $e);
            $this->setErrorMessage("Import failed! Cannot creat binding for the book.");
            return null;
          }
        }else{
          $book_binding_term_id = $book_binding_term->id();
        }
     
      $node->set('field_book_v_bb_binding', $book_binding_term_id);
    }

    private function setPersonValues(){
      $personNodes = array();
      //Create Persons(s)
      foreach($this->data['persons'] as $person){
        //check, if person is relevant
        $role = $this->getPersonRole($person['type']);
        if(empty($role)){
          continue;
        }
        //check, if person already exists
        $options = ['title' => $person['lastName']];
        if(!empty($person['firstName'])){
          $options['field_person_forename'] = $person['firstName'];
        }
        $persons = \Drupal::entityTypeManager()
            ->getStorage('node')
            ->loadByProperties($options);
        if(empty($persons)){
          try{
            $node = Node::create(['type' => 'person']);
            $node->set('title', $person['lastName']);
            $node->set('field_person_forename', $person['firstName']);
            $node->set('field_person_description', $this->getBiorgraphy($person));
            $node->set('field_person_onix_type', $person['type']);
            $node->set('uid', \Drupal::currentUser()->id());
            $node->status = 1;
            $node->enforceIsNew();
            $node->save();
            $personNode['nid'] = $node->id();
            $personNode['role'] = $role;
            $personNodes[] = $personNode;
          }catch(\Exception $e){
            watchdog_exception('Bookimport - nodecreate author', $e);
            $this->setErrorMessage("Import failed! Cannot creat author for the book.");
            return null;
          }
        }else{
          foreach ($persons as $key => $node){
            $updated = false;
            //add onix type, if not exists
            $types = $node->field_person_onix_type->getValue();
            $found_key = array_search($person['type'], array_column($types , 'value'));
            if($found_key === false){
              $node->field_person_onix_type[] = $person['type'];
              $updated = true;
            }
            //add biography, if not exists
            if(empty($node->field_person_description->value)){
              $node->set('field_person_description', $this->getBiorgraphy($person));
              $updated = true;
            }
            if($updated){
              $node->save();
            }
            $personNode['nid'] = $node->id();
            $personNode['role'] = $role;
            $personNodes[] = $personNode;
            break;
          }
        }
      }
      return $personNodes;
    }

    public function reImportBook(){
      $node = null;
      if(empty($this->data)){
        return null;
      }
      if($this->bookExists($this->ean)){
        $node = $this->updateBookNode();
      }else{
        $node = $this->createBookNode();
      }
      return $node;
    }

    private function createBookNode()
    {
        //check, if book already exists
        $books = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['field_book_ean' => $this->ean]);
        if(!empty($books)){
          $this->setErrorMessage("Import failed! Book with EAN ".$this->ean." already exists.");
          return null;
        }

        if(empty($books)){
          //Create Book
          try{
            $node = Node::create(['type' => 'book']);
            $this->setBookValues($node);
          }catch(\Exception $e){
            \Drupal::logger('wh_import_vlb')->error("Import failed! Cannot create/set book-values.! Book with EAN ".$this->ean);
            watchdog_exception('Bookimport - nodecreate', $e);
            $this->setErrorMessage("Import failed! Cannot create book.");
            return null;
          }
        }

        $this->setBookValues($node);
        if(count($node->field_book_category->getValue()) == 0){
          \Drupal::logger('wh_import_vlb')->error("Import failed! False categories! Book with EAN ".$this->ean);
          return null;
        }
        $node->enforceIsNew();
        $node->save();


        return $node;
    }

    public function setCover(&$node){
      if(isset($this->data['cover']['fid'])){
        $node->field_book_cover[] = [
          'target_id' => $this->data['cover']['fid'],
          'alt' => $this->data['title'],
          'title' => $this->data['title'],
        ];
      }
    }

    public function setCategories(&$node){
      if(!empty($this->data['category_codes'])){
        $tids = array();
        $query = \Drupal::entityQuery('taxonomy_term')->condition('vid', 'v_book_category');
        $v_book_category_tids = $query->execute();
        $v_book_category_terms = \Drupal\taxonomy\Entity\Term::loadMultiple($v_book_category_tids);
        foreach($v_book_category_terms as $term){
          $v_bc_onix_codes = $term->get("field_v_bc_onix_code")->getValue();
          foreach($v_bc_onix_codes as $v_bc_onix_code){
            //get field-value
            $v_bc_onix_code = $v_bc_onix_code['value'];
            //get +placeholder-terms / start
            //check, if category contains +placeholer und plit at +
            //get all placeholder categories saved in drupal like FH*
            $category_placeholder = '*';//category-placeholder
            $and_category_placeholder = '+';//and-placeholder
            $v_bc_onix_codes = explode(',', $v_bc_onix_code);
            $var_found_onix_codes = array();
            for($i=0; $i < count($v_bc_onix_codes); $i++){
              //get *placeholder-terms / start
              //check, if category contains *placeholer und get *placeholer-position
              //get all placeholder categories saved in drupal like FH*
              $placeholer_pos = strpos($v_bc_onix_codes[$i], $category_placeholder);
              if($placeholer_pos !== false){
                $short_v_bc_onix_code = substr($v_bc_onix_codes[$i],0,$placeholer_pos);
                //search short_onix_code in vlb-array
                foreach($this->data['category_codes'] as $vlb_category_code){
                  $short_vlb_category_code = substr($vlb_category_code,0,$placeholer_pos);
                  if($short_vlb_category_code === $short_v_bc_onix_code){
                    $tids[] = $term->id();
                    $var_found_onix_codes[$i] = true;
                  }
                }
              }//get *placeholder-terms / end
              else{//get matching-terms / start
                //search onix_code in vlb-array
                foreach($this->data['category_codes'] as $vlb_category_code){
                  if($vlb_category_code === $v_bc_onix_codes[$i]){
                    $tids[] = $term->id();
                  }
                }
              }//get matching-terms / end
            }//get +placeholder-terms / end
          }
        }
        $tids = array_unique($tids);
        $node->set('field_book_category', $tids);
        $node->set('field_book_category_onix_code', $this->data['category_codes']);
      }
    }

    public function getErrorMessage()
    {
        //
        return $this->error_message;
    }

    private function setErrorMessage($msg)
    {
        //
        $this->error_message = $msg;
    }

}
