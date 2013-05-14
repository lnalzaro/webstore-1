<?php

/**
 * Install controller used for initial Web Store install
 *
 * @category   Controller
 * @package    Install
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright  Copyright &copy; 2013 Xsilva Systems, Inc. http://www.lightspeedretail.com
 * @version    3.0
 * @since      2013-05-14

 */

class InstallController extends Controller
{

	protected $online;
	
	public function init() {

		//We override init() to keep our system from trying to autoload stuff we haven't finished converting yet
	}


	/**
	 * For this controller, we only want to run these functions if LSKEY isn't set (meaning we're partially through an install)
	 * Otherwise, we give an exception to prevent running any of these processes.
	 * @param CAction $action
	 * @return bool
	 * @throws CHttpException
	 */
	public function beforeAction($action)
	{

		if (strlen(_xls_get_conf('LSKEY'))>0 && $action->id != "exportconfig" && $action->id != "upgrade" && $action->id != "fixlink")
		{
			error_log("stopped because key was "._xls_get_conf('LSKEY')." on action ".$action->id);

			throw new CHttpException(404,'The requested page does not exist.');
			return false;
		}
		return parent::beforeAction($action);

	}

	/**
	 * Hide controller behind 404 exception
	 * @throws CHttpException
	 */
	public function actionIndex()
	{
		throw new CHttpException(404,'The requested page does not exist.');
	}

	/**
	 * Create a symbolic link for the views file to our default viewset
	 */
	public function actionFixlink()
	{
		$symfile = YiiBase::getPathOfAlias('application')."/views";
		$strOriginal = YiiBase::getPathOfAlias('application.views')."-".strtolower("cities");
		@unlink($symfile);
		symlink($strOriginal, $symfile);
	}

	/**
	 * Export the initial configuration
	 */
	public function actionExportConfig()
	{

		Configuration::exportConfig();
		Configuration::exportLogging();



		echo json_encode(array('result'=>"success"));
	}

	/**
	 * Master function to call the other upgrade steps
	 */
	public function actionUpgrade()
	{

		$this->online = _xls_number_only($_POST['online']);

		if ($this->online==1) $retval = $this->actionConvertStepZero();
		if ($this->online >=2 && $this->online<=17) $retval = $this->actionConvertStep1();
		if ($this->online==18) $retval = $this->actionConvertStep2();
		if ($this->online>=19 && $this->online<=23) $retval = $this->actionConvertGoogle();
		if ($this->online==24) $retval = $this->actionConvertStep3();
		if ($this->online==25) $retval = $this->actionConvertStep4();
		if ($this->online==26) $retval = $this->actionConvertStep5();
		if ($this->online==28) $retval = $this->actionConvertStep6();
		if ($this->online==29) $retval = $this->actionConvertStep7();
		if ($this->online>=32 && $this->online<=44) $retval = $this->actionConvertStep8();
		if ($this->online==45) $retval = $this->actionConvertStep9();


		echo $retval;


	}

	/**
	 * Before we do anything else, write our config table to our params file for faster access
	 */
	protected function actionConvertStepZero()
	{

		return json_encode(array('result'=>"success",'makeline'=>2,'total'=>50));

	}

	/**
	 * Extract shipping and billing address information, create address book and map to the carts
	 */
	protected function actionConvertStep1()
	{

		$sql = "select * from xlsws_cart where billaddress_id IS NULL and address_bill IS NOT NULL order by id limit 500";
		$results=Yii::app()->db->createCommand($sql)->queryAll();

		foreach($results AS $result) {

			$result['email'] = strtolower($result['email']);

			//verify that Customer ID really exists in customer table
			$objCust = Customer::model()->findByPk($result['customer_id']);

			if (!($objCust instanceof Customer))
				$result['customer_id']=0;


			if (strlen($result['address_bill'])>0) {

					$arrAddress = explode("\n",$result['address_bill']);
					if (count($arrAddress)==5) {
						//old format address, should be 6 pieces
						$arrAddress[5] = $arrAddress[4];
						$strSt = $arrAddress[3];
						if ($strSt[0]==" ") {
							//no state on this address
							$arrAddress[4] = substr($strSt,1,100);
							$arrAddress[3]="";
						} else {
							$arrSt = explode(" ",$strSt);
							$arrAddress[3] = $arrSt[0];
							$arrAddress[4] = str_replace($arrSt[0]." ","",$strSt);
						}

					}

				$objAddress = new CustomerAddress;

				if (count($arrAddress)>=5) {
					$objCountry = Country::LoadByCode($arrAddress[5]);
					if ($objCountry) {
						$objAddress->country_id = $objCountry->id;

						$objState = State::LoadByCode($arrAddress[3],$objCountry->id);
						if ($objState)
							$objAddress->state_id = $objState->id;
					}



					$objAddress->address1 = $arrAddress[0];
					$objAddress->address2 = $arrAddress[1];
					$objAddress->city = $arrAddress[2];
					$objAddress->postal = $arrAddress[4];

					$objAddress->first_name = $result['first_name'];
					$objAddress->last_name = $result['last_name'];
					$objAddress->company = $result['company'];

					$objAddress->phone = $result['phone'];
					$objAddress->residential = CustomerAddress::RESIDENTIAL;
					$objAddress->created = $result['datetime_cre'];
					$objAddress->modified = $result['datetime_cre'];
					$objAddress->active = 1;

					$blnFound = false;
					if ($result['customer_id']>0) {

						//See if this is already in our database
						$objPriorAddress = CustomerAddress::model()->findByAttributes(array(
							'address1'=>$objAddress->address1,
							'address2'=>$objAddress->address2,
							'city'=>$objAddress->city,
							'postal'=>$objAddress->postal,
							'first_name'=>$objAddress->first_name,
							'last_name'=>$objAddress->last_name,
							'company'=>$objAddress->company,
							'phone'=>$objAddress->phone));

						if ($objPriorAddress instanceof CustomerAddress) {
							Yii::app()->db->createCommand("update xlsws_cart set billaddress_id=".$objPriorAddress->id." where id=".$result['id'])->execute();
							$blnFound=true;
						}
						else
							$objAddress->customer_id=$result['customer_id'];

					}
					else
					{
						//We need a shell customer record just for the email
						$objC = Customer::model()->findByAttributes(array('email'=>$result['email']));
						if ($objC instanceof Customer)
							Yii::app()->db->createCommand("UPDATE xlsws_cart set customer_id=".$objC->id." where id=".$result['id'])->execute();
						else
						{
							$objC = new Customer;
							$objC->record_type = Customer::GUEST;
							$objC->email = $result['email'];
							$objC->first_name=$objAddress->first_name;
							$objC->last_name=$objAddress->last_name;
							$objC->company=$objAddress->company;
							if (!$objC->validate())
							{
								$arrErr = $objC->getErrors();

								if (isset($arrErr['email'])){
									$objC->email = $result['id'].".invalid@example.com";
								}
								if (!$objC->validate())
									return print_r($objC->getErrors(),true);
							}

							if (!$objC->save()) {
								Yii::log("Import Error ".print_r($objC->getErrors(),true), 'error', 'application.'.__CLASS__.".".__FUNCTION__);
								return print_r($objC->getErrors(),true);
							}
							else $cid = $objC->id;
							Yii::app()->db->createCommand("upDATE xlsws_cart set customer_id=".$cid." where id=".$result['id'])->execute();
						}

					}


					if (!$blnFound) {
						if (!$objAddress->save()) {
							Yii::log("Import Error ".print_r($objAddress->getErrors(),true), 'error', 'application.'.__CLASS__.".".__FUNCTION__);
							return print_r($objAddress->getErrors(),true);
						}
						else
							$cid = $objAddress->id;
						Yii::app()->db->createCommand("UPdate xlsws_cart set billaddress_id=".$cid." where id=".$result['id'])->execute();

					}
				}
				else
				{
					//We have a corrupt billing address, just blank it out so import goes on
					Yii::app()->db->createCommand("update xlsws_cart set address_bill=null where id=".$result['id'])->execute();
				}

			$objAddress = new CustomerAddress;


			$objCountry = Country::LoadByCode($result['ship_country']);
			if ($objCountry) {
				$objAddress->country_id = $objCountry->id;

				$objState = State::LoadByCode($result['ship_state'],$objCountry->id);
				if ($objState)
					$objAddress->state_id = $objState->id;
			}




			$objAddress->first_name = $result['ship_firstname'];
			$objAddress->last_name = $result['ship_lastname'];
			$objAddress->company = $result['ship_company'];
			$objAddress->address1 = $result['ship_address1'];
			$objAddress->address2 = $result['ship_address2'];
			$objAddress->city = $result['ship_city'];


			$objAddress->postal = $result['ship_zip'];
			$objAddress->phone = $result['ship_phone'];
			$objAddress->residential = CustomerAddress::RESIDENTIAL;
			$objAddress->created = $result['datetime_cre'];
			$objAddress->modified = $result['datetime_cre'];
			$objAddress->active = 1;

			$blnFound = false;
			if ($result['customer_id']>0) {

				//See if this is already in our database
				$objPriorAddress = CustomerAddress::model()->findByAttributes(array(
					'address1'=>$objAddress->address1,
					'address2'=>$objAddress->address2,
					'city'=>$objAddress->city,
					'postal'=>$objAddress->postal,
					'first_name'=>$objAddress->first_name,
					'last_name'=>$objAddress->last_name,
					'company'=>$objAddress->company,
					'phone'=>$objAddress->phone));

				if ($objPriorAddress instanceof CustomerAddress) {
					Yii::app()->db->createCommand("update xlsws_cart set shipaddress_id=".$objPriorAddress->id." where id=".$result['id'])->execute();
					$blnFound=true;
				}
				else
					$objAddress->customer_id=$result['customer_id'];
			}

				if (!$blnFound){
					if (!$objAddress->save())
						Yii::log("Import Error ".print_r($objAddress->getErrors(),true), 'error', 'application.'.__CLASS__.".".__FUNCTION__);
					else
						$cid = $objAddress->id;
					Yii::app()->db->createCommand("update xlsws_cart set shipaddress_id=".$cid." where id=".$result['id'])->execute();

				}
			}


			$objShipping = new CartShipping;

			$objShipping->shipping_method = $result['shipping_method'];
			$objShipping->shipping_module = $result['shipping_module'];
			$objShipping->shipping_data = $result['shipping_data'];
			$objShipping->shipping_cost = $result['shipping_cost'];
			$objShipping->shipping_sell = $result['shipping_sell'];

			if (!$objShipping->save())
				return print_r($objShipping->getErrors());
			else
				$cid = $objShipping->id;
			Yii::app()->db->createCommand("update xlsws_cart set shipping_id=".$cid." where id=".$result['id'])->execute();


			$objPayment = new CartPayment;

			$objPayment->payment_method = $result['payment_method'];
			$objPayment->payment_module = str_replace(".php","",$result['payment_module']);
			$objPayment->payment_data = $result['payment_data'];
			$objPayment->payment_amount = $result['payment_amount'];
			$objPayment->datetime_posted = $result['datetime_posted'];

			if ($result['fk_promo_id']>0) {

				$objPromo = PromoCode::model()->findByPk($result['fk_promo_id']);
				if ($objPromo) $objPayment->promocode = $objPromo->code;

			}

			if (!$objPayment->save())
				return print_r($objPayment->getErrors());
			else
				$cid = $objPayment->id;
			Yii::app()->db->createCommand("update xlsws_cart set payment_id=".$cid." where id=".$result['id'])->execute();


		}


		$results2=Yii::app()->db->createCommand(
			"select count(*) from xlsws_cart where billaddress_id IS NULL and address_bill IS NOT NULL")->queryScalar();

		if ($results2==0) $remain=18;
		else
		{
			$remain = round(18 - ($results2/1250));
			if ($remain<1) $remain=1;
			if ($remain>17) $remain=17;
		}

		return json_encode(array('result'=>"success",'makeline'=>$remain,'total'=>50,'tag'=>'Converting cart addresses, '.$results2.' remaining'));


	}


	/**
	 * Rename modules, load google
	 */
	protected function actionConvertStep2()
	{
		//Change country to ID instead of text string based on xlsws_countries
		$strCountry = _xls_get_conf('DEFAULT_COUNTRY');
		$objCountry = Country::LoadByCode($strCountry);
		if ($objCountry)
			_xls_set_conf('DEFAULT_COUNTRY',$objCountry->id);

		_dbx("update xlsws_modules set module = replace(module, '.php', '')");


		$arrModuleRename = array(
			'authorize_dot_net_aim' =>'authorizedotnetaim',
			'authorize_dot_net_sim' =>'authorizedotnetsim',
			'axia'                  =>'axia',
			'beanstream_aim'        =>'beanstreamaim',
			'beanstream_sim'        =>'beanstreamsim',
			'cheque'                =>'cheque',
			'eway_cvn_aus'          =>'eway',
			'merchantware'          =>'merchantware',
			'paypal_webpayments_pro'=>'paypalpro',
			'paypal'                =>'paypal',
			'phone_order'           =>'phoneorder',
			'purchase_order'        =>'purchaseorder',
			'worldpay'              =>'worldpaysim',
			'xlsws_class_payment'   =>'cashondelivery'
		);

		foreach ($arrModuleRename as $key=>$value) {
			$objModule =  Modules::model()->findByAttributes(array('category'=>'payment','module'=>$key));
			if ($objModule instanceof Modules) {
				$objModule->module = $value;
				$objModule->save();
			}
		}

		$arrModuleRename = array(
			'australiapost'     =>'australiapost',
			'canadapost'        =>'canadapost',
			'destination_table' =>'destinationshipping',
			'fedex'             =>'wssfedex',
			'flat_rate'         =>'flatrate',
			'free_shipping'     =>'freeshipping',
			'intershipper'      =>'intershipper',
			'iups'              =>'iups',
			'store_pickup'      =>'storepickup',
			'tier_table'        =>'tieredshipping',
			'ups'               =>'ups',
			'usps'              =>'usps'
		);

		foreach ($arrModuleRename as $key=>$value) {
			$objModule =  Modules::model()->findByAttributes(array('category'=>'shipping','module'=>$key));
			if ($objModule instanceof Modules) {
				$objModule->module = $value;
				$objModule->save();
			}
		}

		$arrModuleRename = array(
			'xlsws_class_sidebar'     =>'wsbsidebar',
			'sidebar_wishlist'        =>'wsbwishlist',
			'sidebar_order_lookup' =>'wsborderlookup'
		);

		foreach ($arrModuleRename as $key=>$value) {
			$objModule =  Modules::model()->findByAttributes(array('category'=>'sidebar','module'=>$key));
			if ($objModule instanceof Modules) {
				$objModule->module = $value;
				$objModule->save();
			}
		}


		_dbx("INSERT INTO `xlsws_modules` (`active`, `module`, `category`, `version`, `name`, `sort_order`, `configuration`, `modified`, `created`)
VALUES	(0, 'wsmailchimp', 'CEventCustomer', 1, 'MailChimp', 1, 'a:2:{s:7:\"api_key\";s:0:\"\";s:4:\"list\";s:9:\"Web Store\";}', CURRENT_TIMESTAMP, NULL);");


		$arrKeys = array('SEO_PRODUCT_TITLE','SEO_PRODUCT_DESCRIPTION','SEO_CATEGORY_TITLE','SEO_CUSTOMPAGE_TITLE','EMAIL_SUBJECT_CART','EMAIL_SUBJECT_WISHLIST','EMAIL_SUBJECT_CUSTOMER','EMAIL_SUBJECT_OWNER');
		foreach ($arrKeys as $key)
		{

			$obj = Configuration::LoadByKey($key);
			$obj->key_value = str_replace("%storename%","{storename}",$obj->key_value);
			$obj->key_value = str_replace("%name%","{name}",$obj->key_value);
			$obj->key_value = str_replace("%description%","{description}",$obj->key_value);
			$obj->key_value = str_replace("%shortdescription%","{shortdescription}",$obj->key_value);
			$obj->key_value = str_replace("%longdescription%","{longdescription}",$obj->key_value);
			$obj->key_value = str_replace("%shortdescription%","{shortdescription}",$obj->key_value);
			$obj->key_value = str_replace("%keyword1%","",$obj->key_value);
			$obj->key_value = str_replace("%keyword2%","",$obj->key_value);
			$obj->key_value = str_replace("%keyword3%","",$obj->key_value);
			$obj->key_value = str_replace("%price%","{price}",$obj->key_value);
			$obj->key_value = str_replace("%family%","{family}",$obj->key_value);
			$obj->key_value = str_replace("%class%","{class}",$obj->key_value);
			$obj->key_value = str_replace("%crumbtrail%","{crumbtrail}",$obj->key_value);
			$obj->key_value = str_replace("%rcrumbtrail%","{rcrumbtrail}",$obj->key_value);
			$obj->key_value = str_replace("%orderid%","{orderid}",$obj->key_value);
			$obj->key_value = str_replace("%customername%","{customername}",$obj->key_value);
			$obj->save();

		}


		return json_encode(array('result'=>"success",'makeline'=>19,'tag'=>'Installing Google categories (group 1 of 6)','total'=>50));

	}


	/**
	 * load amazon, Convert our web keywords into new tags table
	 */
	protected function actionConvertGoogle()
	{
		$ct=0;

		//Load google categories
		_dbx('SET FOREIGN_KEY_CHECKS=0');
		if ($this->online==19)
			Yii::app()->db->createCommand()->truncateTable(CategoryGoogle::model()->tableName());
		$file = fopen(YiiBase::getPathOfAlias('ext.wsgoogle.assets')."/googlecategories.txt", "r");
		if ($file)
			while(!feof($file)) {
				$strLine = fgets($file);

				$ct++;
				if (
					($ct>=1 && $ct<=1000 && $this->online==19) ||
					($ct>=1001 && $ct<=2000 && $this->online==20) ||
					($ct>=2001 && $ct<=3000 && $this->online==21) ||
					($ct>=3001 && $ct<=4000 && $this->online==22) ||
					($ct>=4001 && $ct<=5000 && $this->online==23)
				)
				{
					$objGC = new CategoryGoogle();
					$objGC->name0 = trim($strLine);
					$arrItems = array_filter(explode(" > ",$strLine));
					if(isset($arrItems[0]))    $objGC->name1=trim($arrItems[0]);
					if(isset($arrItems[1]))    $objGC->name2=trim($arrItems[1]);
					if(isset($arrItems[2]))    $objGC->name3=trim($arrItems[2]);
					if(isset($arrItems[3]))    $objGC->name4=trim($arrItems[3]);
					if(isset($arrItems[4]))    $objGC->name5=trim($arrItems[4]);
					if(isset($arrItems[5]))    $objGC->name6=trim($arrItems[5]);
					if(isset($arrItems[6]))    $objGC->name7=trim($arrItems[6]);
					if(isset($arrItems[7]))    $objGC->name8=trim($arrItems[7]);
					if(isset($arrItems[8]))    $objGC->name9=trim($arrItems[8]);

					$objGC->save();
				}
			}
		fclose($file);

		if ($this->online==23)
		{
			for ($x=1; $x<=9; $x++)
				_dbx("update xlsws_category_google set `name".$x."`=null where `name".$x."`=''");

			CategoryGoogle::model()->deleteAllByAttributes(array('name1'=>null));


			//Import old google categories to new
			try {
				$dbC = Yii::app()->db->createCommand();
				$dbC->setFetchMode(PDO::FETCH_OBJ);//fetch each row as Object

				$dbC->select()->from('xlsws_category')->where('google_id IS NOT NULL')->order('id');

				foreach ($dbC->queryAll() as $row) {
					_dbx("delete from xlsws_category_integration where module='google' AND foreign_id=".$row->google_id." and category_id=".$row->id);
					$obj = new CategoryIntegration();
					$obj->category_id = $row->id;
					$obj->module = "google";
					$obj->foreign_id = $row->google_id;
					$obj->extra = $row->google_extra;
					$obj->save();
				}
				_dbx("alter table xlsws_category drop google_id");
				_dbx("alter table xlsws_category drop google_extra");
			}
			catch (Exception $e)
			{

			}


		}
		_dbx('SET FOREIGN_KEY_CHECKS=1');

		return json_encode(array('result'=>"success",'makeline'=>($this->online+1),'tag'=>'Installing Google categories (group '.($this->online-17)." of 6)",'total'=>50));

	}



	protected function actionConvertStep3()
	{




		$sql = "insert ignore into xlsws_tags (tag) select distinct web_keyword1 from xlsws_product where coalesce(web_keyword1,'')<>'' order by web_keyword1";
		Yii::app()->db->createCommand($sql)->execute();

		$sql = "insert ignore into xlsws_tags (tag) select distinct web_keyword2 from xlsws_product where coalesce(web_keyword2,'')<>'' order by web_keyword2";
		Yii::app()->db->createCommand($sql)->execute();

		$sql = "insert ignore into xlsws_tags (tag) select distinct web_keyword2 from xlsws_product where coalesce(web_keyword3,'')<>'' order by web_keyword3";
		Yii::app()->db->createCommand($sql)->execute();

		Yii::app()->db->createCommand("delete from xlsws_tags where tag is null")->execute();
		Yii::app()->db->createCommand("delete from xlsws_tags where tag=''")->execute();

		Yii::app()->db->createCommand("insert into xlsws_product_tags (product_id,tag_id) select a.id,b.id from xlsws_product as a left join xlsws_tags as b on a.web_keyword1=b.tag where coalesce(web_keyword1,'') <> '' and b.id is not null")->execute();

		Yii::app()->db->createCommand("insert into xlsws_product_tags (product_id,tag_id) select a.id,b.id from xlsws_product as a left join xlsws_tags as b on a.web_keyword2=b.tag where coalesce(web_keyword2,'') <> '' and b.id is not null")->execute();

		Yii::app()->db->createCommand("insert into xlsws_product_tags (product_id,tag_id) select a.id,b.id from xlsws_product as a left join xlsws_tags as b on a.web_keyword3=b.tag where coalesce(web_keyword3,'') <> '' and b.id is not null")->execute();


		//The process above may create duplicates, so we need to remove those and recreate them
		$sql = "select product_id,
			         tag_id,
			         count(*)
			from     xlsws_product_tags
			group by product_id,
			         tag_id
			having   count(*) > 1";
		$results=Yii::app()->db->createCommand($sql)->queryAll();
		foreach($results AS $result) {
			_dbx("delete from xlsws_product_tags where product_id=".$result['product_id']." and tag_id=".$result['tag_id']);
			_dbx("insert into xlsws_product_tags set product_id=".$result['product_id'].", tag_id=".$result['tag_id']);
		}

		return json_encode(array('result'=>"success",'makeline'=>25,'total'=>50));

	}

	/**
	 * Convert families into Ids and attach
	 * @return string
	 */
	protected function actionConvertStep4()
	{
		//families
		$sql = "insert ignore into xlsws_family (family) select distinct family from xlsws_product where coalesce(family,'')<>'' order by family";
		Yii::app()->db->createCommand($sql)->execute();

		_dbx("update xlsws_product as a set family_id=(select id from xlsws_family as b where b.family=a.family)");

		Family::ConvertSEO();

		$objFamilies = Family::model()->findAll();
		foreach ($objFamilies as $obj)
			$obj->UpdateChildCount();

		return json_encode(array('result'=>"success",'makeline'=>26,'total'=>50));
	}

	/**
	 * Convert classes into Ids and attach
	 * @return string
	 */
	public function actionConvertStep5()
	{
		//class
		$sql = "insert ignore into xlsws_classes (class_name) select distinct class_name from xlsws_product where coalesce(class_name,'')<>'' order by class_name";
		Yii::app()->db->createCommand($sql)->execute();

		_dbx("update xlsws_product as a set class_id=(select id from xlsws_classes as b where b.class_name=a.class_name)");

		Classes::ConvertSEO();

		return json_encode(array('result'=>"success",'makeline'=>28,'total'=>50));
	}



	/**
	 * Change destination tables and map to country/state ids
	 */
	protected function actionConvertStep6()
	{
		//Convert Wish List items to new formats
		//Ship to me
		//Ship to buyer
		//Keep in store

		$objDestinations = Destination::model()->findAll();
		foreach ($objDestinations as $objDestination)
		{
			if ($objDestination->country=="*") $objDestination->country=null;
			else {
				$objC = Country::LoadByCode($objDestination->country);
				$objDestination->country=$objC->id;
			}

			if ($objDestination->state=="*") $objDestination->state=null;
			else
			{
				$objS = State::LoadByCode($objDestination->state,$objDestination->country);
				$objDestination->state=$objS->id;
			}

			if (!$objDestination->save())
				return print_r($objDestination->getErrors());
		}

		//Need to map destinations to IDs before doing this
		_dbx("update `xlsws_destination` set country=null where country=0;");
		_dbx("update `xlsws_destination` set state=null where state=0;");
		_dbx("ALTER TABLE `xlsws_destination` CHANGE `country` `country` INT(11)  UNSIGNED  NULL  DEFAULT NULL;");
		_dbx("ALTER TABLE `xlsws_destination` CHANGE `state` `state` INT(11)  UNSIGNED  NULL  DEFAULT NULL;");
		_dbx("ALTER TABLE `xlsws_destination` CHANGE `taxcode` `taxcode` INT(11)  UNSIGNED  NULL  DEFAULT NULL;");
		_dbx("ALTER TABLE `xlsws_destination` ADD FOREIGN KEY (`state`) REFERENCES `xlsws_state` (`id`);");
		_dbx("ALTER TABLE `xlsws_destination` ADD FOREIGN KEY (`country`) REFERENCES `xlsws_country` (`id`);");
		_dbx("ALTER TABLE `xlsws_destination` ADD FOREIGN KEY (`taxcode`) REFERENCES `xlsws_tax_code` (`lsid`);");
		_dbx("ALTER TABLE `xlsws_category` CHANGE `custom_page` `custom_page` INT(11)  UNSIGNED  NULL  DEFAULT NULL;");
		_dbx("ALTER TABLE `xlsws_category` ADD FOREIGN KEY (`custom_page`) REFERENCES `xlsws_custom_page` (`id`);");
		_dbx("ALTER TABLE `xlsws_country` DROP `code_a3`;");
		_dbx("update `xlsws_shipping_tiers` set `class_name`='tieredshipping';");

		return json_encode(array('result'=>"success",'makeline'=>29,'tag'=>'Applying database schema changes','total'=>50));
	}

	/**
	 * Drop fields no longer needed
	 */
	protected function actionConvertStep7()
	{

		$sqlstrings = "ALTER TABLE `xlsws_cart` DROP `first_name`;
		ALTER TABLE `xlsws_cart` DROP `last_name`;
		ALTER TABLE `xlsws_cart` DROP `address_bill`;
		ALTER TABLE `xlsws_cart` DROP `address_ship`;
		ALTER TABLE `xlsws_cart` DROP `ship_firstname`;
		ALTER TABLE `xlsws_cart` DROP `ship_lastname`;
		ALTER TABLE `xlsws_cart` DROP `ship_company`;
		ALTER TABLE `xlsws_cart` DROP `ship_address1`;
		ALTER TABLE `xlsws_cart` DROP `ship_address2`;
		ALTER TABLE `xlsws_cart` DROP `ship_city`;
		ALTER TABLE `xlsws_cart` DROP `ship_zip`;
		ALTER TABLE `xlsws_cart` DROP `ship_state`;
		ALTER TABLE `xlsws_cart` DROP `ship_country`;
		ALTER TABLE `xlsws_cart` DROP `ship_phone`;
		ALTER TABLE `xlsws_cart` DROP `zipcode`;
		ALTER TABLE `xlsws_cart` DROP `contact`;
		ALTER TABLE `xlsws_cart` DROP `company`;
		ALTER TABLE `xlsws_cart` DROP `full_name`;
		ALTER TABLE `xlsws_cart` DROP `phone`;
		ALTER TABLE `xlsws_cart` DROP `shipping_method`;
		ALTER TABLE `xlsws_cart` DROP `shipping_module`;
		ALTER TABLE `xlsws_cart` DROP `shipping_data`;
		ALTER TABLE `xlsws_cart` DROP `shipping_cost`;
		ALTER TABLE `xlsws_cart` DROP `shipping_sell`;
		ALTER TABLE `xlsws_cart` DROP `payment_method`;
		ALTER TABLE `xlsws_cart` DROP `payment_module`;
		ALTER TABLE `xlsws_cart` DROP `payment_data`;
		ALTER TABLE `xlsws_cart` DROP `payment_amount`;
		ALTER TABLE `xlsws_cart` DROP `datetime_posted`;
		ALTER TABLE `xlsws_cart` DROP `tracking_number`;
		ALTER TABLE `xlsws_cart` DROP `email`;
		ALTER TABLE `xlsws_cart` DROP `cost_total`;
		ALTER TABLE `xlsws_cart` DROP `sell_total`;

		ALTER TABLE `xlsws_customer` DROP `address1_1`;
		ALTER TABLE `xlsws_customer` DROP `address1_2`;
		ALTER TABLE `xlsws_customer` DROP `address2_1`;
		ALTER TABLE `xlsws_customer` DROP `address_2_2`;
		ALTER TABLE `xlsws_customer` DROP `city1`;
		ALTER TABLE `xlsws_customer` DROP `city2`;
		ALTER TABLE `xlsws_customer` DROP `country1`;
		ALTER TABLE `xlsws_customer` DROP `country2`;
		ALTER TABLE `xlsws_customer` DROP `homepage`;
		ALTER TABLE `xlsws_customer` DROP `phone1`;
		ALTER TABLE `xlsws_customer` DROP `phonetype1`;
		ALTER TABLE `xlsws_customer` DROP `phone2`;
		ALTER TABLE `xlsws_customer` DROP `phonetype2`;
		ALTER TABLE `xlsws_customer` DROP `phone3`;
		ALTER TABLE `xlsws_customer` DROP `phonetype3`;
		ALTER TABLE `xlsws_customer` DROP `phone4`;
		ALTER TABLE `xlsws_customer` DROP `phonetype4`;
		ALTER TABLE `xlsws_customer` DROP `state1`;
		ALTER TABLE `xlsws_customer` DROP `state2`;
		ALTER TABLE `xlsws_customer` DROP `zip1`;
		ALTER TABLE `xlsws_customer` DROP `zip2`;
		ALTER TABLE `xlsws_customer` DROP `mainname`;

		ALTER TABLE `xlsws_product` DROP `family`;
		ALTER TABLE `xlsws_product` DROP `class_name`;
		ALTER TABLE `xlsws_product` DROP `web_keyword1`;
		ALTER TABLE `xlsws_product` DROP `web_keyword2`;
		ALTER TABLE `xlsws_product` DROP `web_keyword3`;
		ALTER TABLE `xlsws_product` DROP `meta_desc`;
		ALTER TABLE `xlsws_product` DROP `meta_keyword`;
		ALTER TABLE `xlsws_wishlist_item` DROP `registry_status`;

		ALTER TABLE `xlsws_wishlist_item` ADD CONSTRAINT `xlsws_wishlist_item_ibfk_1` FOREIGN KEY (`registry_id`) REFERENCES `xlsws_wishlist` (`id`);

		DROP TABLE `xlsws_gift_registry_receipents`;";

		$arrSql = explode(";",$sqlstrings);

		foreach ($arrSql as $strSql)
			if (!empty($strSql))
				Yii::app()->db->createCommand($strSql)->execute();



		return json_encode(array('result'=>"success",'makeline'=>32,'tag'=>'Installing Amazon categories (group 1 of 14)','total'=>50));

	}


	/**
	 * load amazon, 24 files starts at online 32
	 * @return string
	 */
	protected function actionConvertStep8()
	{

		$arr = array();
		$d = dir(YiiBase::getPathOfAlias('ext.wsamazon.assets.csv'));
		while (false!== ($filename = $d->read())) {
			if (substr($filename,-4)==".csv") {
				$arr[] = $filename;
			}
		}
		$d->close();
		sort($arr);

		_dbx('SET FOREIGN_KEY_CHECKS=0');
		if ($this->online==32)
			Yii::app()->db->createCommand()->truncateTable(CategoryAmazon::model()->tableName());

		$onBlock = 2*($this->online-32);  //online is 32 through 44
		for($x=0; $x<=1; $x++)
		{
			if(isset($arr[$onBlock+$x]))
			{
			$filename = $arr[$onBlock+$x];

			$csvData = file_get_contents(YiiBase::getPathOfAlias('ext.wsamazon.assets.csv')."/".$filename);
			$csvDataa = explode(chr(13),$csvData);
			$arrData = array();
			foreach ($csvDataa as $item)
				$arrData[]= str_getcsv($item, ",",'"');
			array_shift($arrData);


			foreach($arrData as $data)
			{
				$objGC = new CategoryAmazon();
				$objGC->name0 = trim($data[1]);
				$objGC->item_type = trim($data[2]);
				$arrItems = array_filter(explode("/",$data[1]));
				if(isset($arrItems[0]))    $objGC->name1=trim($arrItems[0]);
				if(isset($arrItems[1]))    $objGC->name2=trim($arrItems[1]);
				if(isset($arrItems[2]))    $objGC->name3=trim($arrItems[2]);
				if(isset($arrItems[3]))    $objGC->name4=trim($arrItems[3]);
				if(isset($arrItems[4]))    $objGC->name5=trim($arrItems[4]);
				if(isset($arrItems[5]))    $objGC->name6=trim($arrItems[5]);
				if(isset($arrItems[6]))    $objGC->name7=trim($arrItems[6]);
				if(isset($arrItems[7]))    $objGC->name8=trim($arrItems[7]);
				if(isset($arrItems[8]))    $objGC->name9=trim($arrItems[8]);

				$objGC->save();


			}}
		}
		$this->online++;

		return json_encode(array('result'=>"success",'makeline'=>$this->online,'tag'=>'Installing Amazon categories (group '.($this->online-31)." of 14)", 'total'=>50));
	}

	/**
	 * Cleanup details, config options that have changed, NULL where we had 0's, etc.
	 * @return string
	 */
	protected function actionConvertStep9()
	{


		//Amazon changes
		_dbx("update xlsws_category_amazon set product_type='AutoAccessory' where name1 like '%Automotive%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Beauty' where name1 like '%Beauty%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Beauty' where name1 like '%Beauty%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='CameraPhoto' where name0 like '%camera & photo%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='CE' where name1 like '%Electronics%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Computers' where name2 like '%Computers & Accessories%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='FoodAndBeverages' where name1 like '%Grocery & Gourmet Food%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Health' where name1 like '%Health & Personal Care%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Home' where name1 like '%Home & Kitchen%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Jewelry' where name1 like '%Jewelry%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Jewelry' where name1 like '%Jewelry%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='MusicalInstruments' where name1 like '%Musical Instruments%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Office' where name1 like '%Office%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='PetSupplies' where name1 like '%Pet Supplies%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Shoes' where name1 like '%Shoes%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Sports' where name1='Sports & Outdoors' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='SWVG' where name1='Software' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='SWVG' where name1='Video Games' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='TiresAndWheels' where name2 like '%Tires & Wheels%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Tools' where name1 like '%Tools & Home Improvement%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='Toys' where name1 like '%Toys & Games%' and product_type is null;");
		_dbx("update xlsws_category_amazon set product_type='ToysBaby' where name2 like '%Baby & Toddler Toys%';");


		_dbx("update xlsws_customer as a set default_billing_id=(select id from xlsws_customer_address as b where customer_id=a.id  order by b.id desc limit 1)");
		
		_dbx("update xlsws_customer as a set default_shipping_id=(select id from xlsws_customer_address as b where customer_id=a.id  order by b.id desc limit 1)");



		_dbx("update xlsws_wishlist_item set cart_item_id=null where cart_item_id=0;");
		_dbx("update xlsws_wishlist_item set purchased_by=null where purchased_by=0;");
		_dbx("update xlsws_promo_code set valid_from=null where valid_from='0000-00-00';");
		_dbx("update xlsws_promo_code set valid_until=null where valid_until='0000-00-00';");
		_dbx("delete from xlsws_configuration where `key_name`='PHONE_TYPES';");


		//Migrate our header image to the new folder
		$objConfig = Configuration::LoadByKey('HEADER_IMAGE');
		$objConfig->key_value = str_replace("/photos/","/images/header/",$objConfig->key_value);
		$objConfig->save();

		$objConfig = Configuration::LoadByKey('PRODUCT_SORT_FIELD');
		$objConfig->key_value = str_replace("Name","title",$objConfig->key_value);
		$objConfig->key_value = str_replace("Rowid","id",$objConfig->key_value);
		$objConfig->key_value = str_replace("Modified","modified",$objConfig->key_value);
		$objConfig->key_value = str_replace("Code","code",$objConfig->key_value);
		$objConfig->key_value = str_replace("InventoryTotal","inventory_total",$objConfig->key_value);
		$objConfig->key_value = str_replace("DescriptionShort","description_short",$objConfig->key_value);
		$objConfig->key_value = str_replace("WebKeyword1","title",$objConfig->key_value);
		$objConfig->key_value = str_replace("WebKeyword2","title",$objConfig->key_value);
		$objConfig->key_value = str_replace("WebKeyword3","title",$objConfig->key_value);
		$objConfig->save();



		_dbx("update xlsws_configuration set configuration_type_id=15,sort_order=2 where key_name='LANGUAGES'");
		_dbx("update xlsws_configuration set sort_order=sort_order+8 where configuration_type_id=19");
		_dbx("update xlsws_configuration set `key_name`='THEME',title='Site Theme',options='THEME',
			configuration_type_id=0,sort_order=2,param=0 where `key_name`='DEFAULT_TEMPLATE'");
		_dbx("update xlsws_configuration set `key_name`='CHILD_THEME',title='Theme color scheme',
			options='CHILD_THEME',sort_order=3,param=0,configuration_type_id=0 where `key_name`='DEFAULT_TEMPLATE_THEME'");
		_dbx("INSERT INTO `xlsws_configuration`
			(`title`, `key_name`, `key_value`, `helper_text`, `configuration_type_id`, `sort_order`, `options`, `template_specific`, `param`, `required`)
			VALUES ('Template Viewset', 'VIEWSET', 'cities', 'The master design set for themes.', 0, 1, 'VIEWSET', 0, 0, 1)");
		_dbx("INSERT INTO `xlsws_configuration`
			(`title`, `key_name`, `key_value`, `helper_text`, `configuration_type_id`, `sort_order`, `options`, `template_specific`, `param`, `required`)
			VALUES ('Enable Language Menu', 'LANG_MENU', '0', 'Show language switch menu on website.', 15, 1, 'BOOL', 0, 0, 1)");
		_dbx("INSERT INTO `xlsws_configuration`
			(`title`, `key_name`, `key_value`, `helper_text`, `configuration_type_id`, `sort_order`, `options`, `template_specific`, `param`, `required`)
			VALUES ('Add missing translations while navigating', 'LANG_MISSING', '0', 'For creating new translations. Do NOT leave this option on, it will slow your server down.', 15, 3, 'BOOL', 0, 0, 1)");

		_dbx("delete from xlsws_configuration where key_name='MODERATE_REGISTRATION'");
		_dbx("INSERT INTO `xlsws_configuration`
			(`title`, `key_name`, `key_value`, `helper_text`, `configuration_type_id`, `sort_order`, `options`, `template_specific`, `param`, `required`)
			VALUES ('Moderate Customer Registration', 'MODERATE_REGISTRATION', '0',
			 'If enabled, customer registrations will need to be moderated before they are approved.', 3, 1, 'BOOL', 0, 0, 1)");

		_dbx("INSERT INTO `xlsws_configuration`
			(`title`, `key_name`, `key_value`, `helper_text`, `configuration_type_id`, `sort_order`, `options`, `template_specific`, `param`, `required`)
			VALUES ('Language Options', 'LANG_OPTIONS', 'en:English,fr:français',
			 '', 0,0, NULL, 0, 0, 1)");

		_dbx("UPDATE `xlsws_configuration` SET `key_value`='300' where `key_name`='DATABASE_SCHEMA_VERSION'");
		_dbx("UPDATE `xlsws_customer` SET `pricing_level`=1 where pricing_level is null");

		_dbx("INSERT INTO `xlsws_modules` (`active`, `module`, `category`, `version`, `name`, `sort_order`,
				`configuration`, `modified`, `created`)
				VALUES (1, 'wsamazon', 'CEventProduct,CEventPhoto,CEventOrder', 1, 'Amazon MWS', 2, NULL, '2013-04-04 11:34:38', NULL);");

		return json_encode(array('result'=>"success",'makeline'=>50,'total'=>50));

	}

}