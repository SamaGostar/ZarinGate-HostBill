<?php

#########################################
#***************************************#
########$!~> by Masoud Amini <~!$########
#***************************************#
#########################################

//@prod:    HostBill
//@proj:    zarinpalzg Payment Gateway
//@date:    6/3/14
//@time:    12:05 PM
//@path:    /includes/modules/Payment/zarinpalzg/class.zarinpalzg.php
//@desc:    N/A

class zarinpalzg extends PaymentModule
{
    protected $modname = 'zarinpalzg Payment Gateway';
    protected $description = 'zarinpalzg Payment Gateway<br /> by Masoud Amini <br />';
    protected $version = '1.0';

    protected $configuration = array(
        'PIN' => array(
            'value' => '',
            'type' => 'password',
            'description' => 'Enter your merchant PIN'
        ),
        'Toman' => array(
            'value' => '0',
            'type' => 'check',
            'description' => 'Check if the pricing is based on Toman'
        ),
        'Rate' => array(
            'value' => '1',
            'type' => 'input',
            'description' => 'Enter the gateway conversion rate for GBP to IRR (e.g 1.00000)'
        ),
        'Fees' => array(
            'value' => '0',
            'type' => 'input',
            'description' => 'Enter the remittance fees in percentage'
        )
    );

    protected $supportedCurrencies = array('GBP');

    public function install()
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS mod_zarinpalzg (id int(11) NOT NULL AUTO_INCREMENT,
                                                                   client_id int(11) NOT NULL,
                                                                   invoice_id int(11) NOT NULL,
                                                                   amount text NOT NULL,
                                                                   toman tinyint(1) NOT NULL,
                                                                   conversion_rate text NOT NULL,
                                                                   remittance_fees text NOT NULL,
                                                                   order_id int(20) NOT NULL,
                                                                   authority int(20),
                                                                   payment_datetime datetime,
                                                                   status text NOT NULL,
                                                                   PRIMARY KEY (id))");
        return true;
    }

    public function uninstall()
    {
        $this->db->exec('DROP TABLE IF EXISTS mod_zarinpalzg');
        return true;
    }

    public function drawForm()
    {
        $session_id = null;
        $form = null;

        $order_id = $this->invoice_id . rand(100,999);
        
        $query = $this->db->prepare("SELECT * FROM mod_zarinpalzg WHERE invoice_id = ? AND NOT status = 'Failed'");
        $query->execute(array($this->invoice_id));
        $result = $query->fetchAll();
        $query->closeCursor();
        
        $count = count($result);

        if($count > 0)
        {
            $session = $result[0];

            if($session['status'] == 'Pending')
            {
                $session_id = $session['id'];
                
                $query = $this->db->prepare("UPDATE mod_zarinpalzg SET amount = ?, toman = ?, conversion_rate = ?, remittance_fees = ?, order_id = ? WHERE id = ?");
                $query->execute(array($this->amount, $this->configuration['Toman']['value'], $this->configuration['Rate']['value'], $this->configuration['Fees']['value'], $order_id, $session_id));
                $query->closeCursor();
            }
            else if($session['status'] == 'Paid')
            {
                $form  = '<span style="color: green;">Already Paid</span>';
            }
        }

        if($form == null)
        {
            if($session_id == null)
            {
                $query = $this->db->prepare("INSERT INTO mod_zarinpalzg (client_id, invoice_id, amount, toman, conversion_rate, remittance_fees, order_id, status) VALUES(?, ?, ?, ?, ?, ?, ?, 'Pending')");
                $query->execute(array($this->client['id'], $this->invoice_id, $this->amount, $this->configuration['Toman']['value'], $this->configuration['Rate']['value'], $this->configuration['Fees']['value'], $order_id));
                $session_id = $this->db->lastInsertId();
                $query->closeCursor();
            }

            $form  = '<div style="text-align: center; font-size: 10px; margin: 6px auto;">GBP to IRR Conversion Rate:<br />' . $this->configuration['Rate']['value'] . ($this->configuration['Toman']['value'] ? ' Toman' : ' Rial') . '</div>';
            $form .= '<div style="margin: 6px auto; font-size: 10px; text-align: center;">Remittance Fees:<br />%' . $this->configuration['Fees']['value'] . '</div>';
            $form .= '<form action="startpayment.php" method="POST">';
            $form .= '<input type="hidden" name="session_id" value="' . $session_id . '" />';
            $form .= '<input type="hidden" name="callback_url" value="' . $this->callback_url . '" />';
            $form .= '<input type="submit" value="Pay now!" />';
            $form .= '</form>';
        }

        return $form;
    }

    function callback()
    {
        require_once('lib/nusoap/nusoap.php');

        extract($_GET, EXTR_PREFIX_SAME, 'dup'); // Authority, rs

        $error = null;

        if(($Status == "OK") and $Authority)
        {
            $query = $this->db->prepare("SELECT * FROM mod_zarinpalzg WHERE authority = ?");
            $query->execute(array($Authority));
            $result = $query->fetchAll();
            $query->closeCursor();
            
            $count = count($result);

            if($count == 0)
            {
                $error = '[Error][101] Invalid Authority';
            }
            else
            {
                $session = $result[0];
                $client_id = $session['client_id'];
                $invoice_id = $session['invoice_id'];
                // $toman = $session['toman'];
                // $conversion_rate = $session['conversion_rate'];
                // $remittance_fees = $session['remittance_fees'];
                $order_id = $session['order_id'];
				$amount_rial = ceil((($session['toman'] ? ($session['amount'] * 10) : $session['amount']) * $session['conversion_rate']) * ((100 + $session['remittance_fees']) / 100));
                $merchant_pin = $this->configuration['PIN']['value'];

                $soapclient = new nusoap_client('https://de.zarinpalzg.com/pg/services/WebGate/wsdl', 'wsdl'); 

                if((!$soapclient) or ($err = $soapclient->getError()))
                {
                    $error = '[Error][302] Soap Failed\n\nUnSuccessful Connection\n\nError:\n{$err}';
                }
                else
                {
				    $params = array(
                        'MerchantID' => $merchant_pin,
                        'Authority' => $Authority,
                        'Amount' => $amount_rial/10
                    );
                    $sendParams = array($params);

                    $res = $soapclient->call('PaymentVerification', $sendParams);

                    if($res['Status'] == 100)
                    {
                        $query = $this->db->prepare("SELECT * FROM hb_invoices WHERE id = ? AND client_id = ?");
                        $query->execute(array($invoice_id, $client_id));
                        $result = $query->fetchAll();
                        $query->closeCursor();

                        $count = count($result);

                        if($count > 0)
                        {
                            $invoice = $result[0];
                            $amount = $invoice['total'];
                            $fee = $invoice['subtotal'];

                            // $amount_rial = ceil((($toman ? ($amount * 10) : $amount) * $conversion_rate) * ((100 + $remittance_fees) / 100));

                            // if($amount_rial == REAL_PAID_AMOUNT_FROM_BANK)
                            // {
								
								$query = $this->db->prepare("UPDATE mod_zarinpalzg SET payment_datetime = NOW(), status = 'Paid' WHERE authority = ?");
                                $query->execute(array($Authority));
                                $query->closeCursor();

                                $this->logActivity(array(
                                    'result' => 'Successfull',
                                    'output' => array_splice($_GET, 3, 5)
                                ));

                                $this->addTransaction(array(
                                    'in' => $amount,
                                    'invoice_id' => $invoice_id,
                                    'fee' => $fee,
                                    'transaction_id' => $Authority
                                ));
                            // }
                            // else
                            // {
                            //  $error = '[Error][102] Invalid Amount';
                            // }
                        }
                        else
                        {
                            $error = '[Error] Invalid Invoice';
                        }
                    }
                    else
                    {
                        $error = '[Error] UnSucccessfull Payment';
                    }
                }
            }
        }
        else
        {
            $error = '[Error][105] UnSucccessfull Payment';
        }

        if($error != null)
        {
            $status = 'Failed';

			
			$query = $this->db->prepare("UPDATE mod_zarinpalzg SET payment_datetime = NOW(), status = ? WHERE authority = ?");
            $query->execute(array($status, $Authority));
            $query->closeCursor();

			$get = '';
			$G = array_splice($_GET, 3, 5);
            foreach($G as $k => $v)
            {
                $get .= "\n[{$k}] => {$v}";
            }
			
            $this->logActivity(array(
                'result' => 'Failure',
                'output' => ($error . $get)
            ));

            $title = 'Some errors occurred';
            $icon_name = 'error.png';
            $msg_color = 'red';

            $message = $error;
        }
        else
        {
            $title = 'Success';
            $icon_name = 'success.png';
            $msg_color = 'green';

            $message = 'Payment was successful';
        }

        $invoice_url = "/clientarea/invoice/{$invoice_id}";

        $output  = '<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>' . $title . '</title>
    <style type="text/css">
        body {
            text-align: center;
        }
        #wrapper {
            display: inline-block;
            width: 400px;
            border: 1px solid #cccccc;
            margin-top: 150px;
        }
        #icon {
            width: 128px;
            height: 128px;
            background-image: url(/includes/modules/Payment/zarinpalzg/' . $icon_name . ');
            margin: 15px auto;
        }
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
</head>
<body>
<div id="wrapper">
    <div id="icon"></div>
    <hr />
    <span class="message">' . nl2br($message) . '</span>
    <br /><br />
    <a href="' . $invoice_url . '" class="button">Continue to Invoice</a>
</div>
</body>
</html>';

        echo $output;
    }
}

?>