<?php

namespace Drupal\wh_import_vlb;
use Drupal\node\Entity\Node;

/**
 * Class VlbService.
 */
class VlbService
{

    protected $metaData;
    protected $ean;
    protected $metadataToken;
    protected $coverToken;
    protected $data;
    protected $timestamp_today;
    protected $cover_dir;
    protected $log_message;
    protected $retry_count;
    protected $manually;
    protected $new_book_categories;

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

      $today = new \Drupal\Core\Datetime\DrupalDateTime('today 00:00:00', 'UTC');
      $this->timestamp_today =  $today->getTimestamp();
      $this->log_message = '';
      $this->manually = false;
      $this->new_book_categories = array();

      // Create the styles directory and ensure it's writable.
      $directory = 'book';

      //let hook to change file-dir
      \Drupal::moduleHandler()->alter('change_import_file_dir', $directory);
       
      $dir_ok = file_prepare_directory($directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
      $this->cover_dir = $directory;
    }

    public function getManuallyImportedEans(){
      $db = \Drupal::database();

      $query = $db->select('node_field_data', 'n');
      $query->join('node__field_book_ean', 'ean', 'ean.entity_id = n.nid');
      $query->join('node__field_book_manually_imported', 'import', 'import.entity_id = n.nid');

      $result = $query
      ->fields('ean', ['field_book_ean_value'])
      ->condition('import.field_book_manually_imported_value', 1)
      ->execute();

      $eans = $result->fetchCol();
      return $eans;
    }

    public function getAllEans(){      
      $config = \Drupal::config('wh_import_vlb.config');
      $search_verlage_str = $config->get('book_publisher');
      $onix_codes_str = $config->get('book_categories');
 
      //dates
      $today = new \Drupal\Core\Datetime\DrupalDateTime('today 00:00:00', 'UTC');
      $today_str = $today->format('d.m.Y');

      $last_days = new \Drupal\Core\Datetime\DrupalDateTime('-1 day 00:00:00', 'UTC');
      $last_days_str = $last_days->format('d.m.Y');

      $last_modified = 'AD='.$last_days_str.'^'.$today_str.' oder ZD='.$last_days_str.'^'.$today_str;
      $search_str = '('.$search_verlage_str.') und ('.$onix_codes_str.') und ('.$last_modified.') und db=vlb';

      \Drupal::logger('wh_import_vlb')->notice($search_str);

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

      $url = 'https://api.vlb.de/api/v1/products/?size=250&search='.$search_str;

      $ean = array();
      $total_pages = 1;
      try {
        // \Drupal::logger('wh_import_vlb')->notice('<pre><code>' . print_r($url, TRUE) . '</code></pre>');
          $response = $client->request($method, $url, $options);
          $code = $response->getStatusCode();
          if ($code == 200) {
                $responseData = $response->getBody()->getContents();
                $var = json_decode($responseData, true);
                // \Drupal::logger('wh_import_vlb')->notice('<pre><code>' . print_r($var, TRUE) . '</code></pre>');
                $total_pages = $var['totalPages'];
                // \Drupal::logger('wh_import_vlb')->notice($total_pages);
                $contents = $var['content'];
                
                // \Drupal::logger('wh_import_vlb')->notice('<pre><code>' . print_r($contents, TRUE) . '</code></pre>');
                $this->getEans($ean, $contents);
          }else{
            throw new \Exception("Import failed! VLB code: ".$code);
          }
      }catch (\Exception $e) {
          // Logs an error
          throw new \Exception("Import failed! Wrong vlb request. \Exception: ".' - '.$e->getMessage());
      }
      //next pages
      //$total_pages = 1;
      for($page_number = 2; $page_number <= $total_pages; $page_number++ ){
        $url = 'https://api.vlb.de/api/v1/products/?page='.$page_number.'&size=250&search='.$search_str;
        try {
          $response = $client->request($method, $url, $options);
          $code = $response->getStatusCode();
          if ($code == 200) {
              $responseData = $response->getBody()->getContents();
              $var = json_decode($responseData, true);
              $contents = $var['content'];
              $this->getEans($ean, $contents);
          }else{
            throw new \Exception("Import failed! VLB code: ".$code);
          }
        }catch (\Exception $e) {
            // Logs an error
            throw new \Exception("Import failed! Wrong vlb request. \Exception: ".' - '.$e->getMessage());
        }
      }
      // $ean_str = implode(" ",$ean);
       \Drupal::logger('wh_import_vlb')->notice(count($ean));
      // $ean = array();
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
        $this->log_message = '';
        $this->retry_count = 0;
        $this->new_book_categories = array();
        if (!empty($ean)) {
            $client = \Drupal::httpClient();
            $vlbMetadatenToken = $this->metadataToken;
            $url = 'https://api.vlb.de/api/v1/product/' . $ean . '/ean';
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
                    $data['cover'] = $this->getCover($var['mediaFiles']);
                    $data['press'] = $this->getPress($var['texts']);
                    $data['pages'] = $this->getPages($var['extent']);
                    $data['binding'] = $this->getBinding($var['form']);
                    $data['publishers'] = $this->getPublishers($var['publishers']);
                    $data['series'] = $this->getSeries($var['collections']);
                    $data['availability'] = $var['availabilityStatusCode'];
                    // dpm($data);
                }else{
                  throw new \Exception("Import failed! VLB code: ".$code);
                }
            } catch (\Exception $e) {
                // Logs an error
                throw new \Exception("Import failed! Wrong vlb request. \Exception: ".' - '.$e->getMessage());
            }
        }
        $this->data = $data;
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
        if(!empty($contributor['lastName'])){
          $person['firstName'] = $contributor['firstName'];
          $person['lastName'] = $contributor['lastName'];
          $person['type'] = $contributor['type'];
          $person['biographicalNote'] = $contributor['biographicalNote'];
          $persons[] = $person;
        }
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

    //wandelt deutsches datum t.m.y in timestamp um
    private function timestampFromString($de_date_string){
      $timestamp = null;
      $date = new \Drupal\Core\Datetime\DrupalDateTime($de_date_string.' 00:00:00', 'UTC');
      if($date){
        $timestamp = $date->getTimestamp();
      }elseif($de_date_string != null){
        throw new \Exception("Ungültiges VLB-Datum: ".$de_date_string);
        // dpm($de_date_string);
      }
      return $timestamp;
    }

    private function getPrice($prices){
      $price = array();
      $german_prices = array();
      $price_keys_04 = $this->findInArray($prices, 'type', '04');
      $price_keys_02 = $this->findInArray($prices, 'type', '02');
      $price_keys = array_merge($price_keys_04, $price_keys_02);

      // dpm('---------------------------------------------------------------------------------------------------');
      // dpm('---------------------' .$this->ean. ': ---------------------------------------------------------------');

      foreach($price_keys as $key => $value){
        $price = $prices[$value];
        if($price['country'] == 'DE'){
          $german_prices[$key]['value'] = $price['value'];
          $german_prices[$key]['until_date'] = $this->timestampFromString($price['validUntil']);
          $german_prices[$key]['from_date'] = $this->timestampFromString($price['validFrom']);
          
          // dpm('$german_prices['.$key.'][until_date]: '.$price['validUntil']);
          // dpm('$german_prices['.$key.'][from_date]: '.$price['validFrom']);
          // dpm('$german_prices['.$key.'][value]: '.$price['value']);
          // dpm('.........................................');
        }
      }
      $price_now_array = array();
      //get price from now
      foreach($german_prices as $key => $german_price){
        if((isset($german_price['until_date'])) && (isset($german_price['from_date'])) && ($this->timestamp_today <= $german_price['until_date']) && ($this->timestamp_today >= $german_price['from_date'])){
          //from_date is in the past and until_date is in the future
          $price_now_array[] = $german_price['value'];
          // dpm('$german_prices - between: '.$key. ' - '.$german_price['value']);
        }elseif(isset($german_price['until_date']) && ($this->timestamp_today <= $german_price['until_date']) && (!isset($german_price['from_date']))){
          //until_date is in the future and no from_date
          $price_now_array[] = $german_price['value'];
          // dpm('$german_prices - until_date: '.$key. ' - '.$german_price['value']);
        }elseif(isset($german_price['from_date']) && ($this->timestamp_today >= $german_price['from_date']) && (!isset($german_price['until_date']))){
          //from_date is in the past  and no until_date
          $price_now_array[] = $german_price['value'];
          // dpm('$german_prices - from_date: '.$key. ' - '.$german_price['value']);
        }elseif((!isset($german_price['until_date'])) && (!isset($german_price['from_date']))){
          //no date is set
          $price_now_array[] = $german_price['value'];
        }
      }
      //get lowest price
      if(empty($price_now_array)){
        $price_now = 0;
      }else{
        $price_now = min($price_now_array);
      }

      // dpm('$price_now: '.$price_now);
      // dpm('.........................................');
      return $price_now;
    }


    private function getOldPriceFromTypo3(){
      $price_old_typo3 = 0;

      //connect to db
      \Drupal\Core\Database\Database::setActiveConnection('migration');
      $db = \Drupal\Core\Database\Database::getConnection();

      //get book-prices from ean, sorted by highest prices
      $book_table = "tx_sysglibrary_domain_model_book";
      $price_table = 'tx_sysglibrary_domain_model_price';
      $sql = "SELECT $price_table.price, $price_table.valid_to FROM $book_table INNER JOIN $price_table ON $book_table.uid = $price_table.book WHERE $price_table.deleted = 0 and $book_table.ean = $this->ean ORDER BY $price_table.price DESC";
      //  dpm($sql);
      $query = $db->query($sql);
      $records = $query->fetchAll();
      // dpm('count($records): '.count($records));
      //get highest price and check date (rekursiv)
      foreach ($records as &$record){
        //price in past?
        // dpm('isPriceInPast?: '.$record->price);
        $typo3_price = $record->price;
        if((!empty($record->valid_to)) && ($this->timestamp_today > $record->valid_to)){
          //set typo3-price
          $price_old_typo3 = $record->price;
          // dpm($price_old_typo3);
          break;
        }
      }
      // dpm('$price_old_typo3 '.$price_old_typo3);

      //set default db
      db_set_active();

      return floatval($price_old_typo3);
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

    private function isSpecificBook($ean){
      
      $query = \Drupal::entityQuery('node');
      $query->notExists('field_book_cover');
      $query->condition('field_book_ean',$ean,'=');
      $query->condition('type', 'book');

      $books=$query->execute();

      // $books = \Drupal::entityTypeManager()
      //   ->getStorage('node')
      //   ->loadByProperties(['field_book_ean' => $ean, 'field_book_price' => 0]);

      if(count($books) >= 1){
        return true;
      }
      return false;
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

    private function getCover($mediaFiles){
      $cover = array();
      $cover_size_drupal = NULL;
      $not_in_cloudinary = false;

      //get cover id from vlb
      $media_keys = $this->findInArray($mediaFiles, 'type', '04');
      if(empty($media_keys)){
        $media_keys = $this->findInArray($mediaFiles, 'type', '06');
      }
      if(empty($media_keys)){
        $media_keys = $this->findInArray($mediaFiles, 'type', '03');
      }
      if(empty($media_keys)){
        $media_keys = $this->findInArray($mediaFiles, 'type', '05');
      }
      if(count($media_keys) > 1){
        throw new \Exception("Book-Import failed! To much mediaFiles-Data with type 04 exists in VLB for book with EAN ".$this->ean);
      }elseif(empty($media_keys)){
        throw new \Exception("Book-Import failed! No mediaFiles-Data exists in VLB for book with EAN ".$this->ean);
      }

      //get existing cover-size from drupal, if exist
      $query = \Drupal::entityQuery('node');
      $query->condition('field_book_ean',$this->ean,'=');
      $query->condition('type', 'book');
      $query->exists('field_book_cover');
      $book_ids=$query->execute();
      //if cover exist
      if(!empty($book_ids)){
        //get cover-size from drupal
        $book_id = reset($book_ids);
        $book = \Drupal\node\Entity\Node::load($book_id);
        $file = $book->field_book_cover->entity;
        $cover_size_drupal = intval($file->filesize->value);

        //check, if exists in cloudinary
        $uri = $file->getFileUri();
        $url = file_create_url($uri);
        $file_headers = @get_headers($url);
        if(!$file_headers || $file_headers[0] == 'HTTP/1.1 404 Not Found') {
          $not_in_cloudinary = true;
        }
      }

      $vlb_request = 'https://api.vlb.de/api/v1/cover/'.$this->ean.'/l';

      $options = [
          'headers' => [
              'Authorization' => 'Bearer '.$this->coverToken,
          ],
      ];

      // Destination file to download
      $destination = $this->cover_dir.'/cover_'.$this->ean.'.jpg';
      $client = \Drupal::httpClient();
      
      //get cover-size from vlb
      try {
        $response = $client->request('GET', $vlb_request, $options);
        $code = $response->getStatusCode();
        if ($code == 200) {
          $data = (string) $response->getBody();
          $cover_size_vlb = strlen($data);
          //check book cover-size changed -> import
          if($cover_size_vlb != $cover_size_drupal || $not_in_cloudinary){
            $local = file_save_data($data, $destination, FILE_EXISTS_REPLACE);
            $cover['fid'] = $local->id();
            $this->setLogMessage("Cover re/import!");
          }       
        }else{
          throw new \Exception("Cover-Import failed! Book with EAN ".$this->ean." error-code: ".$code);
        }
      } catch(\Exception $e) {
          throw new \Exception("Cover-Import failed! Book with EAN ".$this->ean." Exception: ".$e->getMessage());
      }
      return $cover;
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
          // Logs an error
          \Drupal::logger('wh_import_vlb')->error("Import failed! Book with EAN ".$this->ean." exists ".count($books)."-times");
          throw new \Exception("Import failed! Book with EAN ".$this->ean." exists ".count($books)."-times");
        }
        $book = reset($books);
        $this->setBookValues($book);
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

        //person
        if(isset($this->data['persons'])){
          $personNodes = $this->setPersonValues();
          $persons = array();
          $names_for_facets = array();
          foreach($personNodes as $personNode){
            $person['target_id'] = $personNode['nid'];
            $person['value'] = $personNode['role'];
            $persons[] = $person;
            $names_for_facets[] = $personNode['name_for_facets'];
          }
          $node->set('field_book_person', $persons);
          //workaround for facets-filter
          $node->set('field_book_person_facets', $names_for_facets);
        }
        if(isset($this->data['description'])){
          $description = [
          'value' => $this->data['description'],
          'binding' => 'basic_html',
          ];
          $node->set('field_book_description', $description);
        }

        $node->set('uid', \Drupal::currentUser()->id());
        $node->status = 1;

        $node->set('field_book_manually_imported', $this->manually);
        
        
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
        if(!empty($this->data['press'])){
          $node->set('field_book_press', $this->data['press']);
        }

        if(isset($this->data['price'])){
          $this->setPrices($node);
        }
        if(isset($this->data['availability'])){
          $this->setAvailability($node);
        }
        if(isset($this->data['publishers'])){
          $this->setPublishers($node);
        }
        if(isset($this->data['category_codes'])){
          $this->setCategories($node);
        }
        if(isset($this->data['publication_date'])){
          $node->field_book_release_date->value = $this->data['publication_date'];
        }
        if(isset($this->data['cover'])){
          $this->setCover($node);
        }
        
        $node->set('field_book_ean', $this->ean.'');

        //Here is the offer for other modules to change the node, like wh_affiliate_links
        \Drupal::moduleHandler()->alter('change_node', $node);
        
      }catch(\Exception $e){
        throw new \Exception("Import failed! Cannot set book-values! Book with EAN ".$this->ean.' - '.$e->getMessage());
      }
    }

    private function getBiorgraphy($person){
      $biography = '';
      if(!empty($person['biographicalNote'])){
        $biography = $person['biographicalNote'];
      }
      // else{
      //   foreach($this->data['biographies'] as $biographie_searchstr){
      //     if (strpos($biographie_searchstr, $person['lastName']) !== false) {
      //       $biography = $biographie_searchstr;
      //     }
      //   }
      // }
      return $biography;
    }

    private function setPrices(&$node){
      $price_old = floatval($node->field_book_old_price->value);
      $price_old_log = $price_old;

      // //ceep care of old prices from typo3
      // $price_old_typo3 = $this->getOldPriceFromTypo3();
      // if((!is_null($price_old_typo3)) && (isset($price_old)) && ($price_old_typo3 > $price_old)){
      //   // dpm('set $price_old from typo3 - ($price_old_typo3 > $price_old): '.$price_old_typo3);
      //   $price_old = $price_old_typo3;
      //   $node->set('field_book_old_price', $price_old_typo3);
      // }elseif((!is_null($price_old_typo3)) && (!isset($price_old))){
      //   // dpm('set $price_old from typo3 - (!isset($price_old)): '.$price_old_typo3);
      //   $price_old = $price_old_typo3;
      //   $node->set('field_book_old_price', $price_old_typo3);
      // }

      $price_until_now = floatval($node->field_book_price->value);
      $price_until_now_log = $price_until_now;

      // if((!is_null($price_old_typo3)) && (isset($price_until_now)) && ($price_until_now < $price_old_typo3) ){
      //   //correct price
      //   $price_until_now = $price_old_typo3;
      //   // dpm('korrect price: $price_until_now = '.$price_until_now.'-------------------------------------------------------------------------------------------------------');
      // }

      // if((!is_null($price_old_typo3)) && ($price_old_typo3 > floatval($this->data["price"]))){
      //   //\Drupal::logger('wh_import_batch')->notice("Import old value from typo3 ".$price_old_typo3.' / '.$this->data["price"].' - EAN: '.$this->ean);
      // }

      if(isset($this->data['price'])){
        $price_new = floatval($this->data['price']);
        $node->set('field_book_price', $this->data['price']);
        // dpm('$price_new: '.$price_new);
      }

      if((isset($price_new)) && (isset($price_old)) && ($price_new < $price_old)){
        //preisreduzierung -> do nothing - old price remains
      }elseif((isset($price_new)) && (isset($price_until_now)) && ($price_new < $price_until_now)){
        //preisreduzierung -> old price did not exist
        $node->set('field_book_old_price', $price_until_now);
      }else{
        unset($node->field_book_old_price);
      }
      if(!empty($price_old)){
        // dpm('--------------------!empty($price_old)-------------------------------------------------------------------------------------------------------------------------');
      }
      $this->setLogMessage('Preis: '.$node->field_book_price->value.' - Streichpreis: '.$node->field_book_old_price->value.' (davor: Preis: '.$price_until_now_log.' - Streichpreis: '.$price_old_log.')');
      //\Drupal::logger('wh_import_vlb')->notice($this->log_message);
      // dpm('$price_old: '.$price_old);
      // dpm('$price_until_now: '.$price_until_now);
      // dpm('field_book_old_price: '.$node->field_book_old_price->value);
      // dpm('field_book_price: '.$node->field_book_price->value);
      // dpm('----------------------------------------------------------------------------------------');
    }

    private function setAvailability(&$node){
      $personNodes = array();
      $publisher_tids = array();
      //Create Persons(s)
      $code = $this->data['availability'];
        //check, if publisher-term already exists
        $options = ['field_v_ba_code' => $code];
        $availability_terms = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->loadByProperties($options);
            $availability_term = reset($availability_terms);
        //add code, if not exists
        if($availability_term === FALSE){
          //get term 'Nicht Lieferbar'
          $query = \Drupal::entityQuery('taxonomy_term')->condition('field_v_ba_code', 'IP','<>');
          $v_ba_tids = $query->execute();
          $v_ba_tid = reset($v_ba_tids);
          
          //add code
          $availability_term->field_v_ba_code[] = $code;
          $availability_term->save();
        }
      $availability_tid = $availability_term->id();
      $node->set('field_book_v_ba_availability', $availability_tid);
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
            throw new \Exception("Import failed! Cannot creat publisher for the book.".' - '.$e->getMessage());
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
            throw new \Exception("Import failed! Cannot creat series for the book. - ".$e->message());
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
            throw new \Exception("Import failed! Cannot creat binding for the book.".' - '.$e->getMessage());
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
            // $node->set('field_person_description', $this->getBiorgraphy($person));
            $node->set('field_person_description', $person['biographicalNote']);
            $node->set('field_person_onix_type', $person['type']);
            $node->set('uid', \Drupal::currentUser()->id());
            $node->status = 1;
            $node->enforceIsNew();
            $node->save();
            $personNode['nid'] = $node->id();
            $personNode['role'] = $role;
            $personNode['name_for_facets'] = $person['firstName'] . ' ' . $person['lastName'];
            $personNodes[] = $personNode;
          }catch(\Exception $e){
            throw new \Exception("Import failed! Cannot creat author for the book.".' - '.$e->getMessage());
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
            $personNode['name_for_facets'] = $person['firstName'] . ' ' . $person['lastName'];
            $personNodes[] = $personNode;
            break;
          }
        }
      }
      return $personNodes;
    }

    public function reImportBook($manually = false){
      $node = null;
      try{
        if(empty($this->data)){
          return null;
        }
        $this->manually = $manually;
        // if(!$this->isSpecificBook($this->ean)){
        //   return null;
        // }
        if($this->bookExists($this->ean)){
          $node = $this->updateBookNode();
        }else{
          $node = $this->createBookNode();
        }
        $node->save();
      }catch(\PDOException $e){
        if($this->retry_count == 0){
          $this->retry_count++;
          $this->reImportBook();
        }else{
          throw new \Exception("Import failed! Cannot re/ImportBook book! DB-Error. Book with EAN ".$this->ean.' - '.$e->getMessage());
        }
      }catch(\Exception $e){
        $search_str = 'SQLSTATE[40001]: Serialization failure';
        if((substr($e->getMessage(), 0, strlen($search_str)) === $search_str) && ($this->retry_count == 0)){
          $this->retry_count++;
          $this->setLogMessage('Info: class of SQLSTATE[40001]: Serialization failure: '.get_class($e));
          $this->reImportBook();
        }else{
          throw new \Exception("Import failed! Cannot re/ImportBook book! Book with EAN ".$this->ean.' - '.$e->getMessage());
        }
      }
      return $node;
    }

    public function getLogMessage(){
      return $this->log_message;
    }

    private function setLogMessage($message){
      $this->log_message .= ' '.$message;
    }

    private function createBookNode()
    {
        //check, if book already exists
        $books = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['field_book_ean' => $this->ean]);
        if(!empty($books)){
          throw new \Exception("Import failed! Book with EAN ".$this->ean." already exists.");
        }

        if(empty($books)){
          //Create Book
          try{
            $node = Node::create(['type' => 'book']);
            $this->setBookValues($node);
          }catch(\Exception $e){
            throw new \Exception("Import failed! Cannot create/set book-values.! Book with EAN ".$this->ean.' - '.$e->getMessage());
          }
        }

        $this->setBookValues($node);
        if(!empty($this->data['category_codes']) && (!$this->manually)){
          if(count($node->field_book_category->getValue()) == 0){
            \Drupal::logger('wh_import_vlb')->error("Import failed! False categories! Book with EAN ".$this->ean);
            throw new \Exception("Import failed! False categories! Book with EAN ".$this->ean);
          }
        }
        $node->enforceIsNew();
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

    public function getNewCategoryTerms(){
      return $this->new_book_categories;
    }

    public function getMappingCategories(){
      if(empty($this->data['category_codes'])){
        return null;
      }
      $tids = array();
      $onix_codes = array();
      //get Mapped Taxonomy Terms
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
      return $tids;
    }


    public function setCategories(&$node){
      if(!empty($this->data['category_codes'])){
        $tids = $this->getMappingCategories();

        $tids = array_unique($tids);
                
        $node->set('field_book_category', $tids);
        $node->set('field_book_category_onix_code', $this->data['category_codes']);
      }
    }


}
