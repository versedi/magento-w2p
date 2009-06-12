<?php
/**
  * Project	magento-w2p
  * Author	Pham Tri Cong <phtcong@gmail.com>
  * Issue 1 : Data import from W2P 
  * To run this batch, you have to add the properties to the Product.
  *	w2p_link			Template Url
  *	w2p_image			image link ** to view in Category page
  *	w2p_image_links		links to images
  *	w2p_created		created date time
  *	w2p_modified		modified date time
  *	w2p_image_large		links to large product images
  *	w2p_image_small		links to small product images 
  * Below Mapping is default field
  *	name				product name (only when a new product is created)
  *	description			product description (only when a new product is created)
  *	sku				template ID
  */
if (!defined('ZP_API_VER')){
	include('zp_api.php');
}
class Biinno_Api_Model_Importer
    extends Mage_Eav_Model_Convert_Adapter_Entity
{
    protected $_categoryCache = array();
    protected $_stores;    
    protected $_displayModes = array( 'PRODUCTS', 'PAGE', 'PRODUCTS_AND_PAGE');
	protected $debug = 0;   
	protected $base = "";   
	protected $key = "";   
	protected $last = "";
	protected $store = "default";
	protected $products = null;
	protected $pids = "";
	/**
	  * Parser feed of all category and product 
	  */
    public function parse()
    {	
		//$batchModel 	= Mage::getSingleton('dataflow/batch');
        $this->base 	= Mage::getStoreConfig('api/settings/w2p_url');
		$this->key 		= Mage::getStoreConfig('api/settings/w2p_key');
		$this->debug 	= Mage::getStoreConfig('api/settings/w2p_debug');
		$this->store 	= Mage::getStoreConfig('api/settings/w2p_store');
		$this->refresh 	= Mage::getStoreConfig('api/settings/w2p_refresh');
		if (!$this->base || !$this->key || !$this->store){
			$this->errorMess("Please setting ZetaPrints Api at Admin->System->Configuration->ZetaPrints Sync tab");
			return ;
		}
		$this->infoMess("Please setting ZetaPrints Api at Admin->System->Configuration->ZetaPrints Sync tab");
		$path 	= "w2p_last_update";
		$val 	= $this->getDate();
		
		
		zp_api_init($this->key,$this->base);
		//$val = "2009-05-05 10:02:47";
		$config = $this->getConfig($path);
		global $zp_cache_time;
		//NEW, create user attribute
		if (!$config->getData("config_id")){
			$this->initSetup();
			$zp_cache_time = "NO";
		}else{
			$zp_cache_time = $config->getData("value");
		}
		$this->last = null;
		if (!$this->refresh){		
			if ($config->getData("config_id")){
				$this->last = $config->getData("value");
			}
		}
		$this->products = array();
		$this->pids[] = "0";
		
		$this->importSite($this->base, $this->key);
		//save config
		$this->saveConfig($path, $val);
		//delete all 
		$this->removeRec();
		
		return;
    }
	
	function strToDate($val){
		if (!$val) return $this->getDate();
		return $this->getDate(strtotime($val));
	}
	function getDate($time = null){
		if (!$time) return date("Y-m-d h:i:s");
		return date("Y-m-d h:i:s", $time);
	}
	function saveConfig($name, $val){
		$config = $this->getConfig($name);
		$config->setData("value", $val);
		$config->setData("path", $name);
		$config->save();
	}
	function getConfig($name){
		$config = Mage::getModel('core/config_data');
		$config->load($name, "path");
		return $config;
	}
	/**
	  * Save List Category with product of user
	  * param	domain	url of site
	  * param 	key		ApiKey
	  */
    function importSite($domain, $key){
		$this->infoMess("**BEGIN**");
		$this->infoMess("**import data from url=[$domain]");
		$this->infoMess("**import data from key=[$key]");
		$this->infoMess("**last update =[$this->last]");
		
		//Get Category list
		$datas = zp_api_catalog_list();
		if (
		!$datas 
		|| (count($datas) < 1)
		){
			$this->infoMess("**No category found");
			return 0;
		}
		
		$this->infoMess(sprintf("**Number of categories:[%s]",count($datas)));
		//Parser Categories
		$count = 0;
		foreach ($datas as $cdata){
			if (!zp_api_catalog_check_public($cdata)) {
				$this->hiddenCategory($cdata);
				continue;
			}
			$ctotal = $this->importCategory($cdata);
			$count += $ctotal;
			$this->infoMess("****Number of products:[$ctotal]");
			//TODO
			//break;
		}
		//print_r($ret);
		$this->infoMess(sprintf("**Number of categories:[%s]",count($datas)));
		$this->infoMess(sprintf("**Number of products:[%s]",$count));
		$this->infoMess("**END**");
		return 0;
		
	}
	
	/**
	  * Products in Magento DB with a link to ZP that exist in ZP, but are no longer publicly
	  * accessible should change to out of stock items.
	  * param	domain	url of site
	  * param	id		id of category
	  * param	key		ApiKey
	  **/
	function hiddenCategory($cdata){
		if (!isset($cdata['id'])) return 0;
		$this->debugMess(sprintf("****BEGIN:Hidden Products Of Category:id=[%s]",$cdata['id']));
		$datas = zp_api_catalog_detail($cdata['id']);
		if (
		!$datas 
		|| (count($datas) < 1)
		){
			return 0;
		}
		foreach ($datas as $data){
			if (!isset($data['id'])) continue;
			$this->debugMess(sprintf("******Hidden Products pid=[%s]",$data['id']));			
			$product = Mage::getModel('catalog/product');		
			$old = $product->getIdBySku($data['id']);
			if($old)
			{
				$this->pids[] = $data['id'];
				$product->load($old);
				if ($product->getData("status") != 2){
					$product->setData("status", 2);
					$product->save();
				}
			}
		}
		$this->debugMess(sprintf("****END:Hidden Products Of Category:id=[%s]",$cdata['id']));		
		return 0;
	}
	/**
	  * Products in Magento DB with a link to ZP that no longer exist in ZP should be removed from Magento DB permanently.
	  * return 	number of deleted product
	  **/
	function removeRec($cid=0){
		//if (!$this->last) return;
		$this->debugMess("****BEGIN:DELETE");
		if (count($this->pids) < 2){
			$this->debugMess("****END:DELETE TOTAL=[0]");
			return 0;
		}
		$condPids = array('nin'=>$this->pids);
		$condZp = array('neq'=>"");
		$collection = array();
		$collection = Mage::getResourceModel('catalog/product_collection')
            ->addFieldToFilter('sku', $condPids)
			->addAttributeToSelect('w2p_isorder')
            ->addAttributeToSelect('w2p_image', 'left')
			->addFieldToFilter('w2p_image', $condZp);
			
        $count = 0;  
		if (!$collection || count ($collection) < 1) {
			$this->debugMess(sprintf("****END:DELETE TOTAL=[%s]",$count));
			return 0;
		}
		$this->debugMess(sprintf("****SELECT TOTAL=[%s]",count($collection)));
		foreach ($collection as $item){
			if ($item->getData("w2p_image") && !$item->getData("w2p_isorder")){
				$this->debugMess(sprintf("****DELETE SKU=[%s],order=[%s]",$item->getSku(),$item->getData("w2p_isorder")));
				//TODO
				$item->delete();
				$count++;
			}
		}
		$this->debugMess(sprintf("****END:DELETE TOTAL=[%s]",$count));
		return $count;
	}
	/**
	  * Save Product of category
	  * param	domain	url of site
	  * param	id		id of category
	  * param	key		ApiKey
	  * param	cid		cid of category in magento
	  **/
	function importCategory($cdata){
		if (!isset($cdata['id'])) return 0;
		$this->debugMess(sprintf("****BEGIN:Import Products Of Category:id=[%s]",$cdata['id']));
		$datas = zp_api_catalog_detail($cdata['id']);
		if (
		!$datas 
		|| (count($datas) < 1)
		){
			return 0;
		}
		$cate = array();
		$cate['store'] = $this->store;
		$cate['categories'] = str_replace('/','-',$cdata['title']);
		$cid = $this->saveCategoryData($cate);
		
		$products = $datas;
		zp_api_log_debug(sprintf("number of product[]=[%s]",count($datas)));
		foreach ($products as $product){
			if (!isset($product['id'])) continue;
			$product['cid'] = $cid;
			$this->importProduct($product);
			//TODO
			//break;
		}
		$this->debugMess(sprintf("****END:Save Products Of Category:id=[%s]",$cdata['id']));
		return count($datas);
		
	}
	function importProduct($product){
		$this->infoMess("****TID=[". $product['id'] . "]");
		$this->pids[] = $product['id'];
		if (!isset($product['id']) 
		|| !isset($product['title'])
		|| !isset($product['lastmodified'])
		){
			return 0;
		}
		$created = zp_api_common_str2date($product["lastmodified"]);
		if ($this->last > $created) {
			$this->infoMess("****TID=[". $product['id'] . "]- NO UPDATE - ". $this->last . " > $created");
			return 0;
		}
		$data = zp_api_template_detail($product['id']);
		if (!isset($data['created']) 
		|| !isset($data['previewimage']) 
		|| !isset($data['previewimage']) 
		|| !isset($data['accessurl'])){
			$this->infoMess("****TID=[". $product['id'] . "]- DATA ERROR ");
			return 0;
		}
		if (!isset($data['comments'])) {
			$data['comments'] = isset($product['description']) ? $product['description'] : " ";
		}
		$data['title']	= $product['title'];
		$data['id']		= $product['id'];
		$data['cids']	= $product['cid'];
		$data['price'] = 0;
		
		$data['description']	= $data['comments'];		
		$data['created']	= zp_api_common_str2date($data['created']);
		
		$this->saveProduct($data);
		return 0;
	}
	/**
	  * Save Product data to magento db. this function use core catalog product model class of magento
	  * param	data	product information which is get from Template Detail Feed of ZentaPrints
	  * return	nothing
	  */
	function saveProduct($data){
		if (!$data['id'] || !$data['title'] || !$data['cids'] || !isset($data['created'])){
			return 0;
		}
		$this->debugMess(sprintf("******BEGIN:Save Product:id=[%s]title=[%s]",$data['id'],$data['title']));
		$product = Mage::getModel('catalog/product');    
		$this->pids[] = $data['id'];
		$old = $product->getIdBySku($data['id']);
		if($old)
		{
			$product->load($old);
			$product->setData("w2p_image",$data['previewimage']);
			$product->setData("w2p_image_large",$data['previewimage']);
			$product->setData("w2p_image_small",$data['thumbimage']);
			$product->setData("w2p_modified",$data['created']);
			$product->setData("w2p_image_links",$data['previews']);			
			$product->setData("w2p_link", $data['accessurl']);
			$product->save();
			return $old;
		}
		$product->setWebsiteIds(array('1'));
		$product->setAttributeSetId(4);
		$product->setSku($data['id']);
		$product->setTypeId('simple'); 
		$product->setName($data['title']);
		$product->setDescription($data['description']);
		$product->setShortDescription(" ");
		$product->setPrice($data['price']);
		$product->setData("w2p_image",$data['previewimage']);
		$product->setData("w2p_image_large",$data['previewimage']);
		$product->setData("w2p_image_small",$data['thumbimage']);
		$product->setData("w2p_image_links",$data['previews']);			
		$product->setData("w2p_link", $data['accessurl']);
		$product->setData("w2p_created",$data['created']);
		$product->setData("w2p_modified",$data['created']);
		$product->setWeight(0);
		$product->setStatus(1);
		$product->setTaxClassId(2);
		$product->setCategoryIds(array($data['cids'] =>$data['cids']));
		$product->setVisibility(4);
		$product->save();
		$this->debugMess(sprintf("******End:Save Product:title=[%s]",$data['title']));
	}
	/**
	  * Save Category data to magento db. this function use core catalog category model class of magento
	  * param	data	category information which is get from Feed of ZentaPrints
	  * return	nothing
	  */
	public function saveCategoryData(array $importData)
    {
		$this->debugMess(sprintf("****BEGIN:Save Category:title=[%s]",$importData['categories']));
        $catId = 2;
        if (empty($importData['store'])) {
            if (!is_null($this->getBatchParams('store'))) {
                $store = $this->getStoreById($this->getBatchParams('store'));
            } else {
                $message = Mage::helper('catalog')->__('Skip import row, required field "%s" not defined', 'store');
                Mage::throwException($message);
            }
        } else {
            $store = $this->getStoreByCode($importData['store']);
        }

        if ($store === false) {
            $message = Mage::helper('catalog')->__('Skip import row, store "%s" field not exists', $importData['store']);
            Mage::throwException($message);
        }

        $rootId = $store->getRootCategoryId();
        if (!$rootId) {
            return array();
        }
        $rootPath = '1/'.$rootId;
        if (empty($this->_categoryCache[$store->getId()])) {
            $collection = Mage::getModel('catalog/category')->getCollection()
                ->setStore($store)
                ->addAttributeToSelect('name');
            $collection->getSelect()->where("path like '".$rootPath."/%'");

            foreach ($collection as $cat) {
                $pathArr = explode('/', $cat->getPath());
                $namePath = '';
                for ($i=2, $l=sizeof($pathArr); $i<$l; $i++) {
                    $name = $collection->getItemById($pathArr[$i])->getName();
                    $namePath .= (empty($namePath) ? '' : '/').trim($name);
                }
                $cat->setNamePath($namePath);
            }

            $cache = array();
            foreach ($collection as $cat) {
                $cache[strtolower($cat->getNamePath())] = $cat;
                $cat->unsNamePath();
            }
            $this->_categoryCache[$store->getId()] = $cache;
        }
        $cache =& $this->_categoryCache[$store->getId()];

        $importData['categories'] = preg_replace('#\s*/\s*#', '/', trim($importData['categories']));
        if (!empty($cache[$importData['categories']])) {
            return $cache[$importData['categories']]->getId();
        }

        $path = $rootPath;
        $namePath = '';

        $i = 1;
        $categories = explode('/', $importData['categories']);
        foreach ($categories as $catName) {
            $namePath .= (empty($namePath) ? '' : '/').strtolower($catName);
            if (empty($cache[$namePath])) {

                $dispMode = $this->_displayModes[2];

                $cat = Mage::getModel('catalog/category')
                    ->setStoreId($store->getId())
                    ->setPath($path)
                    ->setName($catName)
                    ->setIsActive(1)
                    ->setIsAnchor(1)
                    ->setDisplayMode($dispMode)
                    ->save();
                $cache[$namePath] = $cat;
            }
            $catId = $cache[$namePath]->getId();
            $path .= '/'.$catId;
            $i++;
        }
        return $catId ;
    }

	/**
	  * Retrieve store object by code
	  *
	  * @param string $store
	  * @return Mage_Core_Model_Store
	  */
    public function getStoreByCode($store)
    {
        $this->_initStores();
        if (isset($this->_stores[$store])) {
            return $this->_stores[$store];
        }
        return false;
    }

	/**
	  *  Init stores
	  *
	  *  @param    none
	  *  @return      void
	  */
    protected function _initStores ()
    {
        if (is_null($this->_stores)) {
            $this->_stores = Mage::app()->getStores(true, true);
            foreach ($this->_stores as $code => $store) {
                $this->_storesIdCode[$store->getId()] = $code;
            }
        }
    }
	
	/* Message function */
	protected function debugMess($mess){
		if ($this->debug){
			$message = Mage::helper('dataflow')->__($mess);
			$this->addException($message);
		}
	}
	protected function infoMess($mess){
		$message = Mage::helper('dataflow')->__($mess);
		$this->addException($message);
	}
	protected function errorMess($mess){
		$message = Mage::helper('dataflow')->__($mess);
		$this->addException("<span style='color:red'>$message</span>");
	}
	protected function initSetup(){
		$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
	    $setup->addAttribute('customer', 'w2p_user', array(
		    'type' => 'text',
	        'label'    => 'W2P UserID',
	        'visible'  => true,
	        'required' => false,
	        'position'     => 1,
	    ));
		$setup->addAttribute('customer', 'w2p_pass', array(	    
	        'label'    => 'W2P Password',
	        'visible'  => true,
	        'required' => false,
	        'position'     => 1,
	    ));
	}
}

?>