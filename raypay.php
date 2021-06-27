<?php
/**
 * RayPay payment plugin
 *
 * @developer     hanieh729
 * @publisher     RayPay
 * @package       Joomla - > Site and Administrator payment info
 * @subpackage    com_Guru
 * @subpackage    raypay
 * @copyright (C) 2021 RayPay
 * @license       http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * https://raypay.ir
 */

defined( '_JEXEC' ) or die( 'Restricted access' );

use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;

jimport('joomla.application.menu');
jimport( 'joomla.html.parameter' );

class plgGurupaymentRayPay extends JPlugin{

	var $_db = null;
    
	function plgGurupaymentRayPay(&$subject, $config){
		$this->_db = JFactory :: getDBO();
		parent :: __construct($subject, $config);
	}
	
	function onReceivePayment(&$post){
		$this->http = HttpFactory::getHttp();
		if($post['processor'] != 'raypay'){
			return 0;
		}	
		
		$params = new JRegistry($post['params']);
		$default = $this->params;
        
		$out['sid'] = $post['sid'];
		$out['order_id'] = $post['order_id'];
		$out['processor'] = $post['processor'];
		$Amount = round($this->getPayerPrice($out['order_id']),0);

		if(isset($post['txn_id'])){
			$out['processor_id'] = JRequest::getVar('tx', $post['txn_id']);
		}
		else{
			$out['processor_id'] = "";
		}
		if(isset($post['custom'])){
			$out['customer_id'] = JRequest::getInt('cm', $post['custom']);
		}
		else{
			$out['customer_id'] = "";
		}
		if(isset($post['mc_gross'])){
			$out['price'] = JRequest::getVar('amount', JRequest::getVar('mc_amount3', JRequest::getVar('mc_amount1', $post['mc_gross'])));
		}
		else{
			$out['price'] = $Amount;
		}
		$out['pay'] = $post['pay'];
		if(isset($post['email'])){
			$out['email'] = $post['email'];
		}
		else{
			$out['email'] = "";
		}
		$out["Itemid"] = $post["Itemid"];

		$cancel_return = JURI::root().'index.php?option=com_guru&controller=guruBuy&processor='.$params['processor'].'&task='.$params['task'].'&sid='.$params['sid'].'&order_id='.$post['order_id'].'&pay=fail';

		$app	= JFactory::getApplication();
		$jinput = $app->input;
		$invoiceId = $jinput->get->get('?invoiceID', '', 'STRING');
		$orderId = $jinput->get->get('order_id', '', 'STRING');


		if ( empty( $invoiceId ) || empty( $orderId ) )
		{
			$out['pay'] = 'fail';
			$msg = 'خطا هنگام بازگشت از درگاه پرداخت';
			$app->redirect($cancel_return, '<h2>'.$msg.'</h2>' , $msgType='Error');
		}
		$data = array('order_id' => $orderId);
		$url = 'https://api.raypay.ir/raypay/api/v1/Payment/checkInvoice?pInvoiceID=' . $invoiceId;
		$options = array('Content-Type: application/json');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HTTPHEADER,$options );
		$result = curl_exec($ch);
		$result = json_decode($result );
		$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		//$options = array('Content-Type' => 'application/json');
		//$result = $this->http->post($url, json_encode($data, true), $options);
		//$result = json_decode($result->body);
		//$http_status = $result->StatusCode;

		if ( $http_status != 200 )
		{
			$out['pay'] = 'fail';
			$msg = sprintf('خطا هنگام بررسی تراکنش. کد خطا: %s - پیام خطا: %s', $http_status, $result->Message);
			$app->redirect($cancel_return, '<h4>'.$msg.'</h4>', $msgType='Error');
		}
		$state           = $result->Data->State;
		$verify_order_id = $result->Data->FactorNumber;
		$verify_amount   = $result->Data->Amount;

		if ( empty($verify_order_id) || empty($verify_amount) || $state !== 1 )
		{
			$out['pay'] = 'fail';
			$msg  = 'پرداخت ناموفق بوده است. شناسه ارجاع بانکی رای پی : ' . $invoiceId;
			$app->redirect($cancel_return, '<h4>'.$msg.'</h4>', $msgType='Error');
		}
		else
		{
			$out['pay'] = 'ipn';
			$msg  = 'پرداخت شما با موفقیت انجام شد.';
			$app->enqueueMessage( '<h2>'.$msg.'</h2>', 'message' );
		}
		return $out;
	}

	function onSendPayment(&$post){
		$app = JFactory::getApplication();
		$this->http = HttpFactory::getHttp();
		if($post['processor'] != 'raypay'){
			return false;
		}

		$params = new JRegistry($post['params']);
		$param['option'] = $post['option'];
		$param['controller'] = $post['controller'];
		$param['task'] = $post['task'];
		$param['processor'] = $post['processor'];
		$param['order_id'] = @$post['order_id'];
		$param['sid'] = @$post['sid'];
		$param['Itemid'] = isset($post['Itemid']) ? $post['Itemid'] : '0';
		foreach ($post['products'] as $i => $item){ $price += $item['value']; }  
		$cancel_return = JURI::root().'index.php?option=com_guru&controller=guruBuy&processor='.$param['processor'].'&task='.$param['task'].'&sid='.$param['sid'].'&order_id='.$post['order_id'].'&pay=fail';

			$amount = round($price,0);
			$desc = 'خرید محصول از آموزشگاه آنلاین Guru ';
			$invoice_id             = round(microtime(true) * 1000);
			$callback = JURI::root().'index.php?option=com_guru&controller=guruBuy&processor='.$param['processor'].'&task='.$param['task'].'&sid='.$param['sid'].'&order_id='.$post['order_id'].'&customer_id='.intval($post['customer_id']).'&pay=wait&';
			$user_id = $params->get('user_id');
			$acceptor_code = $params->get('acceptor_code');

			$data = array(
				'amount'       => strval($amount),
				'invoiceID'    => strval($invoice_id),
				'userID'       => $user_id,
				'redirectUrl'  => $callback,
				'factorNumber' => strval($post['order_id']),
				'acceptorCode' => $acceptor_code,
				'comment'      => $desc
			);

			$url  = 'https://api.raypay.ir/raypay/api/v1/Payment/getPaymentTokenWithUserID';
			$options = array('Content-Type: application/json');
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_HTTPHEADER,$options );
			$result = curl_exec($ch);
			$result = json_decode($result );
			$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			//$options = array('Content-Type' => 'application/json');
			//$result = $this->http->post($url, json_encode($data, true), $options);
			//$result = json_decode($result->body);
			//$http_status = $result->StatusCode;

			if ( $http_status != 200 || empty($result) || empty($result->Data) )
			{
				$msg         = sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s - پیام خطا: %s', $http_status, $result->Message);
				$app->redirect($cancel_return, '<h4>'.$msg.'</h4>', $msgType='Error');
			}

		$access_token = $result->Data->Accesstoken;
		$terminal_id  = $result->Data->TerminalID;

		echo '<p style="color:#ff0000; font:18px Tahoma; direction:rtl;">در حال اتصال به درگاه بانکی. لطفا صبر کنید ...</p>';
		echo '<form name="frmRayPayPayment" method="post" action=" https://mabna.shaparak.ir:8080/Pay ">';
		echo '<input type="hidden" name="TerminalID" value="' . $terminal_id . '" />';
		echo '<input type="hidden" name="token" value="' . $access_token . '" />';
		echo '<input class="submit" type="submit" value="پرداخت" /></form>';
		echo '<script>document.frmRayPayPayment.submit();</script>';

		exit();
	}

	function getPayerPrice ($id) {
		$user = JFactory::getUser();
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('amount')
			->from($db->qn('#__guru_order'));
		$query->where(
			$db->qn('userid') . ' = ' . $db->q($user->id) 
							. ' AND ' . 
			$db->qn('id') . ' = ' . $db->q($id)
		);
		$db->setQuery((string)$query); 
		$result = $db->loadResult();
		return $result;
	}
}
?>
