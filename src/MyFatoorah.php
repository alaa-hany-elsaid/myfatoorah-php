<?php

namespace bawes\myfatoorah;

/**
 * Allows for processing of payments via MyFatoorah
 * For usage, need to set customer, add products, then request payment link
 *
 * MyFatoorah::configure($merchantCode, $username, $password)
 *  ->setCustomer($name, $email, $phone)
 *  ->addProduct("iPhone", 9.750, 5)
 *  ->getPaymentLink();
 *
 * @author Khalid Al-Mutawa <khalid@bawes.net>
 * @link http://www.bawes.net
 */
class MyFatoorah
{

    /**
     * @var string MyFatoorah Merchant Username
     */
    public $merchantUsername;

    /**
     * @var string MyFatoorah Merchant Password
     */
    public $merchantPassword;

    /**
     * @var string MyFatoorah Merchant Code
     */
    public $merchantCode;

    /**
     * @var string Payment gateway url
     */
    public $gatewayUrl = "https://test.myfatoorah.com/pg/PayGatewayServiceV2.asmx";

    /**
     * @var string Payment mode, as in which payment gateways will be available
     * Avail options:
     * - "BOTH" - Both KNET and creditcard?
     * - "knet" - KNET only
     * - "Visa" - VISA/MASTER
     * - "SADAD" - Sadad Saudi
     * - "BENEFITS" - BENEFIT BAHRAIN
     * - "QPAY" - Qpay Qatar
     * - "UAECC" - UAE debit cards
     */
    public $paymentMode = "BOTH";

    /**
     * @var string Return url once customer finishes payment
     */
    private $_customerReturnUrl;

    /**
     * @var string Return url once customer faces an error
     */
    private $_errorReturnUrl;

    /**
     * @var string Customer name
     */
    private $_customerName;

    /**
     * @var string Customer email
     */
    private $_customerEmail;

    /**
     * @var string Customer phone
     */
    private $_customerPhone;

    /**
     * @var string Reference id for the payment
     */
    private $_referenceId;

    /**
     * @var array Products, their quantities, and pricing we're charging
     */
    private $_products = [];

    /**
     * @var string the currency to bill in
     */
    private $_currency = "KWD";

    /**
     * @var string[] Parsing response from MyFatoorah using these codes
     */
    private $_responseCodeDetails = [
        0 => "Success",
        1000 => "Merchant ID not found",
        1001 => "Invalid username/password",
        1002 => "Transaction details not found",
        1003 => "Product details not found",
        1004 => "Customer details not found",
        1005 => "Reference details not found",
        9999 => "Unknown error",
        2009 => "Transaction Failed Messages (Not Captured, Voided, Cancelled, Failure)"
    ];

    /**
     * Starting point that returns an instance of this class
     * @param  $string $merchantCode
     * @param  $string $username
     * @param  $string $password
     * @return static
     */
    public static function configure($merchantCode, $username, $password)
    {
        $instance = new static;
        $instance->merchantUsername = $username;
        $instance->merchantPassword = $password;
        $instance->merchantCode = $merchantCode;
        return $instance;
    }

    /**
     * Sets customer info for generating payment link
     * @param string $name
     * @param string $email
     * @param string $phone
     * @return self
     */
    public function setCustomer($name, $email, $phone)
    {
        $this->_customerName = $name;
        $this->_customerEmail = $email;
        $this->_customerPhone = $phone;

        return $this;
    }

    /**
     * Set the reference id for this payment
     * This should be a unique record referencing this payment attempt
     *
     * You can leave id param empty to use current time as reference
     *
     * @param string $id unique id for reference
     * @return self
     */
    public function setReferenceId($id = null)
    {
        // Set $id to current time for random unique value
        if(!$id) $id = time();

        $this->_referenceId = $id;

        return $this;
    }

    /**
     * Sets the return url that MyFatoorah will redirect to
     * @param string $url
     * @return self
     */
    public function setReturnUrl($url)
    {
        $this->_customerReturnUrl = $url;

        return $this;
    }

    /**
     * Sets the error return url that MyFatoorah will redirect to
     * when there is an error in processing
     * @param string $url
     * @return self
     */
    public function setErrorReturnUrl($url)
    {
        $this->_errorReturnUrl = $url;

        return $this;
    }

    /**
     * Adds product as process to payment request
     * @param string $productName
     * @param double $productPrice
     * @param integer $productQuantity
     * @return self
     */
    public function addProduct($productName, $productPrice, $productQuantity)
    {
        $this->_products[] = [
            'name' => $productName,
            'price' => floatval($productPrice),
            'quantity' => (int) $productQuantity
        ];

        return $this;
    }

    /**
     * Request payment link from MyFatoorah
     * @return string the payment url to redirect to
     */
    public function getPaymentLink()
    {
        // Validate for payment reference id available
        $requiredAttributes[] = '_referenceId';
        // Validate that customer info is available
        $requiredAttributes = ['_customerName', '_customerEmail', '_customerPhone'];
        // Validate for success url
        $requiredAttributes[] = '_customerReturnUrl';
        // Validate for error url
        $requiredAttributes[] = '_errorReturnUrl';
        $this->_validateAttributes($requiredAttributes);

        if(count($this->_products) == 0){
            throw new \Exception('Product list cant be empty');
        }

        $totalPrice = 0;
        $productData = "";
        foreach($this->_products as $product){
            $totalPrice += $product['price'] * $product['quantity'];
            $productData .= '<ProductDC>';
            $productData .= '<product_name>' . htmlspecialchars($product['name']) . '</product_name>';
            $productData .= '<unitPrice>' . $product['price'] . '</unitPrice>';
            $productData .= '<qty>' . $product['quantity'] . '</qty>';
            $productData .= '</ProductDC>';
        }

        $post_string = '
        <?xml version="1.0" encoding="windows-1256"?>
        <soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
            <soap12:Body>
            <PaymentRequest xmlns="http://tempuri.org/">
              <req>
                <CustomerDC>
                  <Name>' . $this->_customerName . '</Name>
                  <Email>' . $this->_customerEmail . '</Email>
                  <Mobile>' . $this->_customerPhone . '</Mobile>
                </CustomerDC>
                <MerchantDC>
                  <merchant_code>' . $this->merchantCode . '</merchant_code>
                  <merchant_username>' . $this->merchantUsername . '</merchant_username>
                  <merchant_password>' . $this->merchantPassword . '</merchant_password>
                  <merchant_ReferenceID>' . $this->_referenceId . '</merchant_ReferenceID>
                  <ReturnURL>' . $this->_customerReturnUrl . '</ReturnURL>
                  <merchant_error_url>' . $this->_errorReturnUrl . '</merchant_error_url>
                </MerchantDC>
                <lstProductDC>' . $productData . '</lstProductDC>
                <totalDC>
                    <subtotal>' . $totalPrice . '</subtotal>
                </totalDC>
                <paymentModeDC>
                    <paymentMode>' . $this->paymentMode . '</paymentMode>
                </paymentModeDC>
                <paymentCurrencyDC>
                  <paymentCurrrency>' . $this->_currency . '</paymentCurrrency>
                </paymentCurrencyDC>
              </req>
            </PaymentRequest>
          </soap12:Body>
        </soap12:Envelope>';

        $soap_do = curl_init();
        curl_setopt($soap_do, CURLOPT_URL, $this->gatewayUrl);
        curl_setopt($soap_do, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($soap_do, CURLOPT_TIMEOUT, 10);
        curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($soap_do, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($soap_do, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($soap_do, CURLOPT_POST, true);
        curl_setopt($soap_do, CURLOPT_POSTFIELDS, $post_string);
        curl_setopt($soap_do, CURLOPT_HTTPHEADER, array(
            'Content-Type: text/xml; charset=utf-8',
            'Content-Length: ' . strlen($post_string)
        ));
        curl_setopt($soap_do, CURLOPT_USERPWD, $this->merchantUsername . ":" . $this->merchantPassword);
        $result = curl_exec($soap_do);
        $err = curl_error($soap_do);
        $file_contents = htmlspecialchars($result);
        curl_close($soap_do);
        $doc = new \DOMDocument();

        if($doc == null){
            throw new \Exception("Failed creating a new DOM document");
        }

        $doc->loadXML(html_entity_decode($file_contents));
        $referenceID = $doc->getElementsByTagName("referenceID")->item(0)->nodeValue;
        $responseCode = $doc->getElementsByTagName("ResponseCode")->item(0)->nodeValue;
        $responseMessage = $doc->getElementsByTagName("ResponseMessage")->item(0)->nodeValue;

        // On Success
        if ($responseCode == 0) {
            $paymentUrl = $doc->getElementsByTagName("paymentURL")->item(0)->nodeValue;

            return $paymentUrl;
        }

        // On Failure
        throw new \Exception("Reference ID: $referenceID : Unable to generate link due to response code: ". $ResponseCode. "and message: ".$responseMessage);
    }

    /**
     * Validate that required attributes exist
     * @param string[] $requiredAttributes
     */
    private function _validateAttributes($requiredAttributes)
    {
        foreach ($requiredAttributes as $attribute) {
            if ($this->$attribute === null) {
                throw new \Exception(strtr('"{class}::{attribute}" cannot be empty.', [
                    '{class}' => static::class,
                    '{attribute}' => '$' . $attribute
                ]));
            }
        }
    }

}
