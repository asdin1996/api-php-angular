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

    validationOperation();
}

/**
 * Example method for a directPayment Request
 * @param args not used in this testing example 
 */
function validationOperation(){
    $orderID = substr( str_shuffle( str_repeat( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 10 ) ), 0, 12 );
    $request = new RESTOperationMessage();
            
    // Operation mandatory data
    $request->setAmount("1234"); // i.e. 1,23 (decimal point depends on currency code)
    $request->setCurrency("978"); // ISO-4217 numeric currency code
    $request->setMerchant("999008881");
    $request->setTerminal("20");
    $request->setOrder($orderID);
    $request->setTransactionType(RESTConstants::$VALIDATION);
    
    //Card Data information
    $request->setCardNumber("4548810000000003");
    $request->setCardExpiryDate("4912");
    $request->setCvv2("123");

    // Other optional parameters example can be added by "addParameter" method
    $request->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago InSite con DirectPayment");

    //Method for a direct payment request (without authentication)
    $request->useDirectPayment();   

    //Printing SendMessage
    echo "<h1>Mensaje a enviar</h1>";
    var_dump($request);

    // Service setting (Signature, Environment, type of payment)
    $signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
    $service = new RESTOperationService($signatureKey, RESTConstants::$ENV_SANDBOX);

    $response = $service->sendOperation($request);

    // Response analysis
    echo "<h1>Respuesta recibida</h1>";
    var_dump($response);

    switch ($response->getResult()) {
        case RESTConstants::$RESP_LITERAL_OK:
            echo "<h1>Operation was OK</h1>";
            confirmationOperation($orderID);
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
 * Example method for a refund Request
 * @param args not used in this testing example
 */
function confirmationOperation($orderID) {

    $request = new RestOperationMessage();
    
    // Operation mandatory data
	$request->setAmount("123"); // i.e. 1,23 (decimal point depends on currency code)
	$request->setCurrency("978"); // ISO-4217 numeric currency code
	$request->setMerchant("999008881");
	$request->setTerminal("20");
	$request->setOrder($orderID);
	$request->setTransactionType(RESTConstants::$VALIDATION_CONFIRMATION);
	
	// Other optional parameters example can be added by "addParameter" method
	$request->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago InSite con DirectPayment");
    
    //Printing SendMessage
    echo "<h1>Mensaje a enviar</h1>";
    var_dump($request);
    
	//Method for a direct payment request (without authentication)
    $request->useDirectPayment();

    // Service setting (Signature, Environment, type of payment)
    $signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
    $service = new RESTOperationService($signatureKey, RESTConstants::$ENV_SANDBOX);

    $response = $service->sendOperation($request);

    // Response analysis
    echo "<h1>Respuesta recibida</h1>";
    var_dump($response);

    switch ($response->getResult()) {
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