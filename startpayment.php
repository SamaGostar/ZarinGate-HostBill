<?php

#########################################
#***************************************#
#####$!~> C0d3d by Masoud Amini <~!$#####
#***************************************#
#########################################

//@prod:    HostBill
//@proj:    zarinpalzg Payment Gateway
//@date:    6/3/14
//@time:    12:05 PM
//@path:    /includes/modules/Payment/zarinpalzg/startpayment.php
//@desc:    N/A

?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Redirecting to zarinpalzg Payment Gateway</title>
    <style type="text/css">
        #wrapper {
            width: 250px;
            height: 360px;
            border: 1px solid #cccccc;
            margin: 150px auto;
        }
        #logo {
            width: 129px;
            height: 170px;
            background-image: url(/includes/modules/Payment/zarinpalzg/logo.png);
            margin: 20px auto;
        }
        #loading {
            width: 100px;
            height: 100px;
            background-image: url(/includes/modules/Payment/zarinpalzg/loading.gif);
            margin: 50px auto 0px;
        }
    </style>
    <script language="javascript">
        var speed = 1000;
        function reload() {
            checkout_confirmation.submit();
        }
    </script>
</head>
<body>
<div id="wrapper">
    <div id="logo"></div>
    <div id="loading"></div>
</div>
<?php

require_once('includes/modules/Payment/zarinpalzg/lib/nusoap/nusoap.php');
require_once('includes/modules/Payment/zarinpalzg/lib/pdo/Db.class.php');

$error = null;

extract($_POST, EXTR_PREFIX_SAME, 'dup'); // session_id, callback_url

$db = new Db();
$modconf = unserialize($db->single("SELECT config FROM hb_modules_configuration WHERE module = 'zarinpalzg'"));
$soapclient = new nusoap_client('https://de.zarinpalzg.com/pg/services/WebGate/wsdl', 'wsdl'); 

if((!$soapclient) or ($err = $soapclient->getError()))
{
    $error = "[Error][301] Soap Failed\n\nUnSuccessful Connection\n\nError:\n{$err}";
}
else
{
    $db->bind('sid', $session_id);
    $session = $db->row("SELECT * FROM mod_zarinpalzg WHERE id = :sid");
    $amount_rial = ceil((($session['toman'] ? ($session['amount'] * 10) : $session['amount']) * $session['conversion_rate']) * ((100 + $session['remittance_fees']) / 100));
    $order_id = $session['order_id'];

    $params = array(
        'MerchantID' => $merchant_pin,
        'Amount' => $amount_rial/10,
        'Description' => $order_id,
        'Email' => '',
        'Mobile' => '',
        'CallbackURL' => callback_url
    );
    $sendParams = array($params) ;

    $res = $soapclient->call('PaymentRequest', $sendParams);
    $authority = $res['Authority'];
    $status = $res['Status'];

    if($status == 100)
    {
        if($authority)
        {
            $db->bindMore(array(
                'Authority' => $authority,
                'sid' => $session_id
            ));
            $update = $db->query("UPDATE mod_zarinpalzg SET authority = :Authority, payment_datetime = NOW() WHERE id = :sid");

            $url = "https://www.zarinpalzg.com/pg/StartPay/" . $authority . "/ZarinGate";
            echo '<form id="checkout_confirmation" action="' . $url . '" method="get">
    
</form>';
        }
        else
        {
            $error = '[Error][100] Couldn’t get proper authority key from zarinpalzg'; // usually because of requesting from undefined IP
        }
    }
    else
    {
        echo'ERR: '.$status;
		$error = $status;
    }
}

if($error != null)
{
    $db->bind('Authority', $authority);
    $update = $db->query("UPDATE mod_zarinpalzg SET payment_datetime = NOW(), status = 'Failed' WHERE authority = :Authority");

    $msg_color = 'red';
    $message = $error;
    
    $invoice_url = "/clientarea/invoice/{$invoice_id}";

    $output  = '
<style type="text/css">
    hr {
        border: 1px solid #cccccc;
    }
    .message {
        display: inline-block;
        font: 14px tahoma;
        color: ' . $msg_color . ';
        margin-top: 20px;
    }
    .button {
        display: inline-block;
        width: 130px;
        height: 20px;
        font: bold 13px tahoma;
        text-decoration: none;
        text-align: center;
        color: black;
        background-color: #ddd;
        border: 2px solid #bbb;
        -moz-border-radius: 10px;
        -webkit-border-radius: 10px;
        -khtml-border-radius: 10px;
        -o-border-radius: 10px;
        -ms-border-radius: 10px;
        -icab-border-radius: 10px;
        behavior: url(/includes/modules/Payment/zarinpalzg/border-radius.htc);
        border-radius: 10px;
        padding: 6px 10px 4px;
        margin: 10px 0px 20px;
    }
</style>
<div id="error">
    <hr />
    <span class="message">' . nl2br($message) . '</span>
    <br /><br />
    <a href="' . $invoice_url . '" class="button">Back to Invoice</a>
</div>';

    echo $output;
}

?>
<script type="text/javascript">
    setTimeout("reload()", speed);
</script>
</body>
</html>