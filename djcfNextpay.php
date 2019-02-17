<?php
/**
* @version		3.7.6
* @package		DJ Classifieds
* @subpackage	DJ Classifieds Payment Plugin
* @copyright	Copyright (C) 2019 Nextpay.ir LTD, All rights reserved.
* @license		http://www.gnu.org/licenses GNU/GPL
* @autor url    https://nextpay.ir
* @autor email  info@nextpay.ir
* @Developer    Nextpay Developers Team.
* 
* 
* DJ Classifieds is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* DJ Classifieds is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with DJ Classifieds. If not, see <http://www.gnu.org/licenses/>.
* 
*/
defined('_JEXEC') or die('Restricted access');
jimport('joomla.event.plugin');
$lang = JFactory::getLanguage();
$lang->load('plg_djclassifiedspayment_djcfNextpay',JPATH_ADMINISTRATOR);
require_once(JPATH_BASE.DS.'administrator/components/com_djclassifieds/lib/djseo.php');
require_once(JPATH_BASE.DS.'administrator/components/com_djclassifieds/lib/djnotify.php');
require_once(JPATH_BASE.DS.'administrator/components/com_djclassifieds/lib/djpayment.php');


class plgdjclassifiedspaymentdjcfNextpay extends JPlugin
{
	function __construct( &$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage('plg_djcfNextpay');
		$params["plugin_name"] = "djcfNextpay";
		$params["icon"] = "nextpay_icon.png";
		$params["logo"] = "nextpay_overview.png";
		$params["description"] = JText::_("PLG_DJCFNEXTPAY_PAYMENT_METHOD_DESC");
		$params["payment_method"] = JText::_("PLG_DJCFNEXTPAY_PAYMENT_METHOD_NAME");
		$params["currency_code"] = $this->params->get("currency_code");
		$params["api_key"] = $this->params->get("api_key");

		$this->params = $params;

	}
	function onProcessPayment()
	{
		$ptype = JRequest::getVar('ptype','');
		$id = JRequest::getInt('id','0');
		$html="";

			
		if($ptype == $this->params["plugin_name"])
		{
			$action = JRequest::getVar('pactiontype','');
			switch ($action)
			{
				case "process" :
				$html = $this->process($id);
				break;
				case "notify" :
				$html = $this->_notify_url();
				break;
				case "paymentmessage" :
				$html = $this->_paymentsuccess();
				break;
				default :
				$html =  $this->process($id);
				break;
			}
		}
		return $html;
	}
	function _notify_url()
	{
	    
		$db = JFactory::getDBO();
		$par = JComponentHelper::getParams( 'com_djclassifieds' );
		$user	= JFactory::getUser();
		$id	= JRequest::getInt('id','0');	
		
		$app = JFactory::getApplication();

		$input = $app->input;
		
		$messageUrl = JRoute::_(DJClassifiedsSEO::getCategoryRoute('0:all'));
		
		$amount = $input->getInt('amount');
		
		if ($this->params['currency_code'] == 'IRR'){
            $amount = $amount / 10;
		}
        
		try{
			$order_id = $input->post->get('order_id')? $input->getString('order_id') : $_POST['order_id'];
			$trans_id = $input->post->get('trans_id')? $input->getString('trans_id') : $_POST['trans_id'];
			if(!isset($order_id) || !isset($trans_id)) throw new Exception( JText::_("PLG_DJCFNEXTPAY_PAYMENT_FAILED"));
			
			$api_key = $this->params['api_key'];
			
			$params = array(
				'api_key' => $api_key,
				'amount' => $amount,
				'order_id' => $order_id,
				'trans_id' => $trans_id
			);
			


			$soap_client = new SoapClient("https://api.nextpay.org/gateway/verify.wsdl", array('encoding' => 'UTF-8'));
			$res = $soap_client->PaymentVerification($params);
			

			$res = $res->PaymentVerificationResult;
			$code = -1000;
			$status=='Failed';
			$paymentstatus=0;

			if ($res != "" && $res != NULL && is_object($res)) {
			    $code = $res->code;
			}
			
			if (intval($code) == 0) 
			{
                DJClassifiedsPayment::completePayment($id, JRequest::getVar('mc_gross'), $trans_id);
                $message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_SUCCEED") . '<br>' .  JText::_("PLG_DJCFNEXTPAY_PAYMENT_REF_ID") . $trans_id;
				$app->redirect($messageUrl, $message, 'message');
			}else{
				$query = "UPDATE #__djcf_payments SET status='".$status."',transaction_id='".$trans_id."' "
						."WHERE id=".$id." AND method='djcfNextpay'";					
				$db->setQuery($query);
				$db->query();
				$message = JText::_("PLG_DJCFNEXTPAY_AFTER_FAILED_MSG") . '<br>' .  JText::_("PLG_DJCFNEXTPAY_PAYMENT_REF_ID") . $trans_id;
				$app->redirect($messageUrl, $message, 'warning');
				exit;
			}
			
        } catch (Exception $e) {
			$message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_ERROR") . $e->getMessage();
			$app->redirect($messageUrl, $message, 'warning');
			exit;
		}
	}
	
	function process($id)
	{
		JTable::addIncludePath(JPATH_COMPONENT_ADMINISTRATOR.DS.'tables');		
		jimport( 'joomla.database.table' );
		$db 	= JFactory::getDBO();
		$app 	= JFactory::getApplication();
		$Itemid = JRequest::getInt("Itemid",'0');
		$par 	= JComponentHelper::getParams( 'com_djclassifieds' );
		$user 	= JFactory::getUser();
		$ptype	= JRequest::getVar('ptype');
		$type	= JRequest::getVar('type','');
		$row 	= JTable::getInstance('Payments', 'DJClassifiedsTable');	
		$api_key= $this->params['api_key'];

		$pdetails = DJClassifiedsPayment::processPayment($id, $type,$ptype);				

		/*if($type=='order'){
			$query ="SELECT o.* FROM #__djcf_orders o "
					."WHERE o.id=".$id." LIMIT 1";
			$db->setQuery($query);
			$order = $db->loadObject();
			
			$query ="SELECT i.*, c.price as c_price FROM #__djcf_items i "
					."LEFT JOIN #__djcf_categories c ON c.id=i.cat_id "
					."WHERE i.id=".$order->item_id." LIMIT 1";
			$db->setQuery($query);
			$item = $db->loadObject();
			if(!isset($item)){
				$message = JText::_('COM_DJCLASSIFIEDS_WRONG_AD');
				$redirect="index.php?option=com_djclassifieds&view=items&cid=0";
			}
			$user_id = $item->user_id;
			
		}else if($type=='offer'){
			$query ="SELECT o.* FROM #__djcf_offers o "
					."WHERE o.id=".$id." LIMIT 1";
			$db->setQuery($query);
			$order = $db->loadObject();
			
			$query ="SELECT i.*, c.price as c_price FROM #__djcf_items i "
					."LEFT JOIN #__djcf_categories c ON c.id=i.cat_id "
					."WHERE i.id=".$order->item_id." LIMIT 1";
			$db->setQuery($query);
			$item = $db->loadObject();
			if(!isset($item)){
				$message = JText::_('COM_DJCLASSIFIEDS_WRONG_AD');
				$redirect="index.php?option=com_djclassifieds&view=items&cid=0";
			}			
			$user_id = $item->user_id;
		}*/
		
		header("Content-type: text/html; charset=utf-8");
		echo JText::_('PLG_DJCFNEXTPAY_REDIRECTING_PLEASE_WAIT');
		
		$order_id = $pdetails['item_id'];
		$amount = intval($pdetails['amount']);
		if ($this->params['currency_code'] == 'IRR'){
            $amount = $amount / 10;
		}
		$callback_uri = JRoute::_(JURI::root().'index.php?option=com_djclassifieds&task=processPayment&ptype='.$this->params["plugin_name"].'&pactiontype=notify&id='.$pdetails['item_id']. '&amount=' . $amount);
				
		$params = array(
			'api_key' => $api_key,
			'amount' => $amount,
			'order_id' => $order_id,
			'callback_uri' => $callback_uri
		);

		try{
			
			$trans_id = "";
			$code_error = -1000;
			
			$soap_client = new SoapClient("https://api.nextpay.org/gateway/token.wsdl", array('encoding' => 'UTF-8'));
			$res = $soap_client->TokenGenerator($params);

			$res = $res->TokenGeneratorResult;

			if ($res != "" && $res != NULL && is_object($res)) {
			    if (intval($res->code) == -1){
                    $trans_id = $res->trans_id;
                    $app->redirect("https://api.nextpay.org/gateway/payment/".$trans_id);
			    }else{
                    $code_error = $res->code;
                    $error = "خطا در پاسخ دهی به درخواست با :" . $code_error;
                    $return = JRoute::_('index.php/component/djclassifieds/?view=payment&id=' . $id, false);
                    $message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_ERROR") . $error;
                    $app->redirect($return, $message, 'error');
			    }
			}else{
			    $error = "خطا در پاسخ دهی به درخواست با SoapClinet";
			    $return = JRoute::_('index.php/component/djclassifieds/?view=payment&id=' . $id, false);
                $message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_ERROR") . $error;
                $app->redirect($return, $message, 'error');
			}

		} catch (Exception $e) {
			$return = JRoute::_('index.php/component/djclassifieds/?view=payment&id=' . $id, false);
			$message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_ERROR") . $e->getMessage();
			$app->redirect($return, $message, 'error');
			exit;
		}

	}

	function onPaymentMethodList($val)
	{
	    
		if($val["direct_payment"] && !$val["payment_email"]){
			die("injam");
			return null;
		}
		
		$type='';
		if($val['type']){
			$type='&type='.$val['type'];	
		}		
		$html ='';
		if($this->params["api_key"]!=''){
			$paymentLogoPath = JURI::root()."plugins/djclassifiedspayment/".$this->params["plugin_name"]."/".$this->params["plugin_name"]."/images/".$this->params["logo"];
			//$form_action = JRoute :: _("index.php?option=com_djclassifieds&task=processPayment&ptype=".$this->params["plugin_name"]."&pactiontype=process&id=".$val["id"].$type, false);
			$form_action = JURI::root()."index.php?option=com_djclassifieds&task=processPayment&ptype=".$this->params["plugin_name"]."&pactiontype=process&id=".$val["id"].$type;
			$html ='<table cellpadding="5" cellspacing="0" width="100%" border="0">
				<tr>';
					if($this->params["logo"] != ""){
				$html .='<td class="td1" width="160" align="center">
						<img src="'.$paymentLogoPath.'" title="'. $this->params["payment_method"].'"/>
					</td>';
					 }
					$html .='<td class="td2">
						<h2>نکست پی</h2>
						<p style="text-align:justify;">'.$this->params["description"].'</p>
					</td>
					<td class="td3" width="130" align="center">
						<a class="button" style="text-decoration:none;" href="'.$form_action.'">'.JText::_('COM_DJCLASSIFIEDS_BUY_NOW').'</a>
					</td>
				</tr>
			</table>';
		}
		return $html;
	}
	private function NextPayStatusMessage($status)
	{
		$prefix = "PLG_DJCFNEXTPAY_PAYMENT_STATUS_";
		$status = $prefix . $status;
		$message =  JText::_($status);
		if ($message == $status) {
			return JText::_($prefix . 'UNDEFINED');
		}
		return $message;
	}
}
?>
