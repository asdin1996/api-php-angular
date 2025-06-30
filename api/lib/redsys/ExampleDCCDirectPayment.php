<?php

include_once 'ApiRedsysREST/initRedsysApi.php';

main();

/**
 * Main method for testing purposes
 */
function main(){	
	echo " GET: ";
	var_dump($_GET);
	
	echo " POST: ";
	var_dump($_POST);

    initialOperation();
}

/**
 * Example method for a directPayment Request
 * @param args not used in this testing example 
 */
function initialOperation(){
    $orderID = substr( str_shuffle( str_repeat( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 10 ) ), 0, 12 );
    $cardDataInfoRequest = new RESTInitialRequestMessage();
            
    // Operation mandatory data
    $cardDataInfoRequest->setAmount("1234"); // i.e. 1,23 (decimal point depends on currency code)
    $cardDataInfoRequest->setCurrency("978"); // ISO-4217 numeric currency code
    $cardDataInfoRequest->setMerchant("999008881");
    $cardDataInfoRequest->setTerminal("20");
    $cardDataInfoRequest->setOrder($orderID);
    $cardDataInfoRequest->setTransactionType(RESTConstants::$AUTHORIZATION);
    
    //Card Data information
    $cardDataInfoRequest->setCardNumber("5424180805648190");
    $cardDataInfoRequest->setCardExpiryDate("3412");
    $cardDataInfoRequest->setCvv2("123");

    // Other optional parameters example can be added by "addParameter" method
    $cardDataInfoRequest->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago DCC con directPayment");

    //Method to ask about DCC card data
    $cardDataInfoRequest->demandDCCinfo();
        
    //Printing SendMessage
    echo "<h1>Mensaje a enviar</h1>";
    var_dump($cardDataInfoRequest);

    // Service setting (Signature, Environment, type of payment)
    $signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
    $service = new RESTInitialRequestService($signatureKey, RESTConstants::$ENV_SANDBOX);

    $response = $service->sendOperation($cardDataInfoRequest);

    // Response analysis
    echo "<h1>Respuesta recibida</h1>";
    var_dump($response);

    switch ($response->getResult()) {
        case RESTConstants::$RESP_LITERAL_OK:
            echo "<h1>Operation was OK</h1>";
            //To get DCC information
            $dccCurrency = $response->getDCCCurrency();
            $dccAmount = $response->getDCCAmount();

            //In this case the commerce can choose which kind of operation want to use
            directPaymentDCCOperation($orderID, $dccCurrency, $dccAmount);
        break;
        
        case RESTConstants::$RESP_LITERAL_AUT:
            echo "<h1>Operation requires authentication</h1>";
        break;
        
        default:
            echo "<h1>Operation was not OK</h1>";
        break;
    }
}

/**
* Method for a directPayment operation request.
* @param orderID
*/
function directPaymentDCCOperation($orderID, $dccCurrency, $dccAmount ) {
    $dccRequest = new RestOperationMessage();
        
    // Operation mandatory data
    $dccRequest->setAmount("123"); // i.e. 1,23 (decimal point depends on currency code)
    $dccRequest->setCurrency("978"); // ISO-4217 numeric currency code
	$dccRequest->setMerchant("999008881");
	$dccRequest->setTerminal("20");
	$dccRequest->setOrder($orderID);
    $dccRequest->setTransactionType(RESTConstants::$AUTHORIZATION);
        
    //Card data information
    $dccRequest->setCardNumber("5424180805648190");
	$dccRequest->setCardExpiryDate("3412");
	$dccRequest->setCvv2("123");
				
	//To add DCC info to the request
	$dccRequest->dccOperation($dccCurrency, $dccAmount);
		
	// Other optional parameters example can be added by "addParameter" method
	$dccRequest->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago DCC con directPayment");
        
    //Printing SendMessage
    echo "<h1>Mensaje a enviar</h1>";
    var_dump($dccRequest);

	//Method for a direct payment request (without authentication)
	$dccRequest->useDirectPayment();
        
    // Service setting (Signature, Environment, type of payment)
    $signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
    $service = new RESTOperationService($signatureKey, RESTConstants::$ENV_SANDBOX);

    $dccResponse = $service->sendOperation($dccRequest);

    // Response analysis
    echo "<h1>Respuesta recibida</h1>";
    var_dump($dccResponse);

    switch ($dccResponse->getResult()) {
        case RESTConstants::$RESP_LITERAL_OK:
            echo "<h1>Operation was OK</h1>";
        break;
        
        case RESTConstants::$RESP_LITERAL_AUT:
            echo "<h1>Operation requires authentication</h1>";
        break;
        
        default:
            echo "<h1>Operation was not OK</h1>";
        break;
    }
}