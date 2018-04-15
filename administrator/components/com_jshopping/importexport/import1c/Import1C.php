<?php
/**
 * @package     Jshopping
 * @subpackage  Import1C
 *
 * @copyright   Copyright (c) 2018 AwesomIO. All rights reserved.
 * @license     GNU General Public License v3.0; see LICENSE
 */

defined('_JEXEC') or die();

jimport('joomla.filesystem.folder');

require_once(JPATH_BASE.'/../cml/vendor/autoload.php');
require_once __DIR__.'/helpers/CategoryHelper.php';
require_once __DIR__.'/helpers/ProductHelper.php';
require_once __DIR__.'/helpers/OfferHelper.php';

function getCurDate(){
    return date("Y-m-d H:i:s", time());
}

class IeImport1C extends IeController
{
    public $_app;
    public $jsConfig;
    public $parameters;
    public $_alias;
    public $_categories;

	function view()
	{
		$ie_id = $this->ie_id;
		$_importexport = JSFactory::getTable('ImportExport', 'jshop');
		$_importexport->load($ie_id);
		$name = $_importexport->get('name');

		JToolBarHelper::title(_JSHOP_IMPORT. ' "'.$name.'"', 'generic.png' );
		JToolBarHelper::custom("backtolistie", "arrow-left", 'arrow-left', _JSHOP_BACK_TO.' "'._JSHOP_PANEL_IMPORT_EXPORT.'"', false );
		JToolBarHelper::spacer();
		JToolBarHelper::save("save", _JSHOP_IMPORT);

		include(dirname(__FILE__)."/form.php");
	}

	private function init(){
	    $this->_app = &JFactory::getApplication();
	    $this->jsConfig = &JSFactory::getConfig();
	    $this->parameters = new \Joomla\Registry\Registry;
	    $this->_alias = &JSFactory::getModel("alias");
        $this->_categories = &JSFactory::getModel('categories', 'JshoppingModel');

	    // set parameters of import-export
        $ie_id = $this->_app->input->getInt("ie_id");
        if (!$ie_id) $ie_id = intval($this->get('ie_id'));
        $ie = $this->getIE($ie_id);
	    $this->parameters->set('ie', $ie);

        // get languages of component
        $lang = JSFactory::getModel("languages");
        $this->parameters->set('languages', $lang->getAllLanguages(1));
    }

    static function getIE(int $ieId){
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->update($db->quoteName('#__jshopping_import_export'))
            ->set($db->quoteName('endstart') .'='. time())
            ->where($db->quoteName('id') .' = '. $ieId);
        $db->setQuery($query);
        $db->execute();
        $query->clear();
        $query->select('*')
            ->from($db->quoteName('#__jshopping_import_export'))
            ->where($db->quoteName('id') .' = '. $ieId);
        $db->setQuery($query);
        $ie = $db->loadAssoc();
        unset($db, $query);
        return $ie;
    }

    private function makeUpload($dir){
        require_once(JPATH_COMPONENT_SITE . '/lib/uploadfile.class.php');

        $upload = new UploadFile($_FILES['file']);
        $upload->setAllowFile(array('xml'));
        $upload->setDir($dir);
        if ($upload->upload())
        {
            @chmod($dir . "/" . $upload->getName(), 0777);
            return $upload->getName();
        }
        else
        {
            JError::raiseWarning("", _JSHOP_ERROR_UPLOADING);
            $this->end();
            return false;
        }
    }

	function save()
	{
	    $this->init();

		$dir = $this->jsConfig->importexport_path . $this->parameters->get('ie')['alias'] .'/';
        if($file=$this->makeUpload($dir))
        {
            $filename = $dir . '/' . $file;
            $this->parameters->set('filename', $file);
			$this->parse($filename);
			//@unlink($filename);
		}
		else {
            $this->end();
		}
	}

	function parse($filename)
	{
	    //$this->prepareDb();

		$parser = new \CommerceMLParser\Parser;

		$that = &$this;

        $db = JFactory::getDbo();

        $db->setQuery('SET FOREIGN_KEY_CHECKS = 0')->execute();
        $db->setQuery('TRUNCATE `#__tmp_ie_categories`')->execute();
        $db->setQuery('TRUNCATE `#__tmp_ie_products`')->execute();
        $db->setQuery('TRUNCATE `#__tmp_ie_product_categories`')->execute();
        $db->setQuery('TRUNCATE `#__tmp_ie_product_units`')->execute();
        $db->setQuery('TRUNCATE `#__tmp_ie_PriceType`')->execute();
        $db->setQuery('TRUNCATE `#__tmp_ie_prices`')->execute();
        $db->setQuery('TRUNCATE `#__tmp_ie_rests`')->execute();
        $db->setQuery('SET FOREIGN_KEY_CHECKS = 1')->execute();

        $db->setQuery('INSERT INTO `#__tmp_ie_categories` (`id`) VALUES (\'\')')->execute();

		$parser
			->addListener("CategoryEvent",
                function (\CommerceMLParser\Event\CategoryEvent $categoryEvent) use (&$that)
                {
                    //CategoryHelper::helper($categoryEvent->getCategory()->fetch(), $that);

                    $db = JFactory::getDbo();

                    $sql='INSERT  INTO '. $db->quoteName('#__tmp_ie_categories') .' (`id`, `name`, `parent`, `order`) VALUES ';

                    $vals = array();

                    foreach ($categoryEvent->getCategory()->fetch() as $category){
                        $values = array(
                            $db->quote($category->getId()),
                            $db->quote($category->getName()),
                            $db->quote($category->getParent()),
                            $category->getOrder()
                        );
                        $vals[] = '('.implode(',', $values).')';
                    }

                    $sql .= implode(',', $vals);

                    $db->setQuery($sql);

                    $db->execute();


                });


        $parser
            ->addListener("ProductEvent",
                function (\CommerceMLParser\Event\ProductEvent $ProductEvent) use (&$that)
                {
                    //ProductHelper::helper($ProductEvent->getProduct(), $that);
                    $product = $ProductEvent->getProduct();

                    $db = JFactory::getDbo();
                    $query = $db->getQuery(true);

                    $query
                        ->insert('#__tmp_ie_products')
                        ->columns($db->qn(array('id', 'name', 'description', 'code', 'barcode', 'manufacturer')))
                        ->values( implode(',', array(
                            $db->q($product->getId()),
                            $db->q($product->getName()),
                            $db->q($product->getDescription()),
                            $db->q($product->getCode()),
                            $db->q($product->getBarcode()),
                            $db->q($product->getManufacturer())
                        )));
                    $db->setQuery($query)->execute();

                    $categories = array();

                    foreach($product->getCategories()->fetch() as $category){
                        $categories[] = implode(',', array(
                            $db->q($product->getId()),
                            $db->q($category)
                        ));
                    }

                    $query
                        ->clear()
                        ->insert('#__tmp_ie_product_categories')
                        ->columns($db->qn(array('product', 'category')))
                        ->values($categories);
                    $db->setQuery($query)->execute();

                    unset($categories);

                    $query
                        ->clear()
                        ->insert('#__tmp_ie_product_units')
                        ->columns($db->qn(array('product', 'value', 'code', 'nameFull', 'nameShort', 'nameInterShort')))
                        ->values(implode(',', array(
                            $db->q($product->getId()),
                            $db->q($product->getbaseUnit()->__toString()),
                            $db->q($product->getbaseUnit()->getCode()),
                            $db->q($product->getbaseUnit()->getNameFull()),
                            $db->q($product->getbaseUnit()->getNameShort()),
                            $db->q($product->getbaseUnit()->getNameInterShort()),
                        )));
                    $db->setQuery($query)->execute();

                    $query->clear();

                });

        $parser
            ->addListener("PriceTypeEvent",
                function (\CommerceMLParser\Event\PriceTypeEvent $PriceTypeEvent) use (&$that)
                {
                    $pricesType = $PriceTypeEvent->getPriceType();

                    $db = JFactory::getDbo();
                    $query = $db->getQuery(true);

                    $query->insert('#__tmp_ie_PriceType')
                        ->columns($db->qn(array('id', 'type', 'currency')))
                        ->values(implode(',', array(
                            $db->q($pricesType->getId()),
                            $db->q($pricesType->getType()),
                            $db->q($pricesType->getCurrency()),
                        )));
                    $db->setQuery($query)->execute();
                });

        $parser
            ->addListener("OfferEvent",
                function (\CommerceMLParser\Event\OfferEvent $offerEvent) use (&$that)
                {
                    $offer = $offerEvent->getOffer();
                    $prices = $offer->getPrices()->fetch();
                    $rests = $offer->getRests()->fetch();

                    $db = JFactory::getDbo();
                    $query = $db->getQuery(true);

                    if(count($prices)){

                        $values = array();

                        foreach ($prices as $price){
                            $values[] = implode(',', array(
                                $db->q($offer->getId()),
                                $db->q($price->getTypeId()),
                                $db->q($price->getUnit()),
                                $db->q($price->getCurrency()),
                                $db->q($price->getBaseUnit()),
                                $db->q($price->getKoeff()),
                            ));
                        }

                        $query->clear()
                            ->insert('#__tmp_ie_prices')
                            ->columns($db->qn(array('product', 'type', 'unit', 'currency', 'baseUnit', 'koeff')))
                            ->values($values);
                        $db->setQuery($query)->execute();

                        unset($values);
                    }

                    if(count($rests)){

                        $values = array();

                        foreach ($rests as $rest){
                            $values[] = implode(',', array(
                                $db->q($offer->getId()),
                                $db->q($rest->getId()),
                                $db->q($rest->getRest())
                            ));
                        }

                        $query->clear()
                            ->insert('#__tmp_ie_rests')
                            ->columns($db->qn(array('product', 'rest_id', 'rest')))
                            ->values($values);
                        $db->setQuery($query)->execute();

                        unset($values);
                    }

                });

        $files = array(
            $this->jsConfig->importexport_path . $this->parameters->get('ie')['alias'] .'/import.xml',
            $this->jsConfig->importexport_path . $this->parameters->get('ie')['alias'] .'/offers.xml',
            $this->jsConfig->importexport_path . $this->parameters->get('ie')['alias'] .'/rests.xml',
        );
        foreach ($files as $filename){
            $parser->parse($filename);
        }

        //$this->end();
	}

	private function end(){
        //@unlink($this->jsConfig->importexport_path . $this->parameters->get('ie')['alias'] .'/'. $this->parameters->get('filename'));
        if (!$this->_app->input->getInt("noredirect")){
		    $this->_app->redirect("index.php?option=com_jshopping&controller=importexport&task=view&ie_id=".$this->parameters->get('ie')['id'], _JSHOP_COMPLETED);
		}
    }
}