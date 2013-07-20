<?php

/*
 * HostBill zarinpalzg gateway module
 * @see http://zarinpalzg.com
 *
 * 2013 HostBill -  Complete Client Management, Support and Billing Software
 * M.Amini
 */

class zarinpalzg extends PaymentModule {

    protected $modname = 'درگاه پرداخت زرین پال - زرین گیت';

    protected $description = 'توسعه داده شده توسط زرین پال';

    protected $supportedCurrencies = array();


    protected $configuration = array(
        'merchent' => array(
            'value' => '',
            'type' => 'input',
            'description' => 'لطفاکد merchent خود را وارد کنید'
        ),
		'success_message' => array(
            'value' => 'پرداخت با موفقیت انجام شد',
            'type' => 'input',
            'description' => 'پیام موفقیت'
        ),
    );


    public function drawForm() {
        function send($desc,$merchent,$amount,$redirect){
	$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding'=>'UTF-8'));
	$res = $client->PaymentRequest(
	array(
					'MerchantID' 	=> $merchent ,
					'Amount' 		=> $amount ,
					'Description' 	=> $desc ,
					'Email' 		=> '' ,
					'Mobile' 		=> '' ,
					'CallbackURL' 	=> $redirect

					)
	 );
    return $res;
				
				
	
			
			$merchent = $this->configuration['merchent']['value'] ;
			$amount = $this->amount;
			$redirect = urlencode($this->callback_url) ;
			$_SESSION['invoiceid']=$this->invoice_id;
			$_SESSION['amount']= $amount;
			
			$result = send($url,$merchent,$amount,$redirect);
				if($result->Status == 100 )){
				$go = "https://www.zarinpal.com/pg/StartPay/" . $result->Authority . "/ZarinGate"; 
				header("Location: $go");
				}
				switch($result->Status){
					case '-1':
					echo 'اطلاعات ارسال شده ناقص است';
					break;
					case '-2':
					echo 'وب سرویس نا معتبر است ؛ ای پی و یا ممرچنت کد وارد شده صحیح نیست';
					break;
					case '-3':
					echo 'رقم تراکنشی ارسالی زير ١٠٠ تومان است';
					break;
					case '-4':
					echo 'یوزر درخواست دهنده درگاه پرداخت به سطح تاييد نقره ای نرسيده است';
					break;
				}
    }
	
	
		

    function callback() {  
	
			function get($merchent,$au,$amount){
	$client = new SoapClient('https://de.zarinpal.com/pg/services/WebGate/wsdl', array('encoding'=>'UTF-8'));
	$res = $client->PaymentVerification(
			array(
					'MerchantID'	 => $merchent ,
					'Authority' 	 => $au ,
					'Amount'	 	=> $amount
				)
		);

        return $res;
    }     
				
				$merchent = $this->configuration['merchent']['value'];
				
				$au = $_GET['Authority'];
				$amount = $_SESSION['amount'];
				$result = get($merchent,$au,$amount);
				$trans_id = $result->RefID;


        if ( $result->Status == 100 ) {
            //2. log incoming payment
            $this->logActivity(array(
                'result' => 'Successfull',
                'output' => $_POST
            ));

            //3. add transaction to invoice
            $invoice_id = $_SESSION['invoiceid'];
            $amount = $_SESSION['amount'];
            $fee = 0;
            $transaction_id = $trans_id;
            
            $this->addTransaction(array(
                'in' => $amount,
                'invoice_id' => $invoice_id,
                'fee' => $fee,
                'transaction_id' => $transaction_id
		      
            ));
			$this->addInfo($this->configuration['success_message']['value']);
            Utilities::redirect('?cmd=clientarea');
            
            
            
            
        } else {
             $this->logActivity(array(
                'result' => 'Failed',
                'output' => $_POST
				
            ));
			Utilities::redirect('?cmd=clientarea');
			
			
        }
    }

}
