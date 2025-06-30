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

	$orderID = substr( str_shuffle( str_repeat( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 10)), 0, 12);

	if( empty ($_GET)){
		initialOperationV2($orderID);
	}
	else{
		if(!empty ($_POST)){
			echo "POST IS NOT EMPTY!\n";
			if ($_GET['urlOK'] == 'yes'){
				//header('Content-Type: text/html; charset=utf-8');
				
				$operation = fgets(fopen("test.txt","r"));
				$operation = unserialize(base64_decode($operation));
				echo "OPERATION: ";
				var_dump($operation);
				echo "Llamando a la función Challenge Request...";
				//The commerce needs to send these authentication parameter (PAreq and md) by post method to AcsURL
				//In the termUrl the commerce must catch the return parameter that the AcsURL gives back
				//Once again, the commerce needs to send these catched parameters in a Request
				if(json_decode($operation->getEmv(),true)["protocolVersion"] == "2.1.0")
					challengeRequestV2($operation);
				else
					challengeRequestV1($operation);

			}
		}
	}

}
	
/**
 * Method for a initial operation request that gives the card data info and exemptions
 */
function initialOperationV2($orderID) {
		
    $protocolVersion = "";
    $threeDSServerTransID = "";
	$threeDSMethodURL = "";
    
    $cardDataInfoRequest = new RESTInitialRequestMessage();
	
	// Operation mandatory data
	$cardDataInfoRequest->setAmount("123"); // i.e. 1,23 (decimal point depends on currency code)
	$cardDataInfoRequest->setCurrency("978"); // ISO-4217 numeric currency code
	$cardDataInfoRequest->setMerchant("999008881");
	$cardDataInfoRequest->setTerminal("20");
	$cardDataInfoRequest->setOrder($orderID);
	//$cardDataInfoRequest->useReference("020e2026d9449fd5b87cca8081782cf523496833");
	$cardDataInfoRequest->setCardNumber("4548815374025114");
	$cardDataInfoRequest->setCardExpiryDate("3412");
    $cardDataInfoRequest->setCvv2("123");
	$cardDataInfoRequest->setTransactionType(RESTConstants::$AUTHORIZATION);
		
	// Other optional parameters example can be added by "addParameter" method
	$cardDataInfoRequest->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago InSite con 3DSecure");
		
	//Method to ask about card information data
	$cardDataInfoRequest->demandCardData();

	//Method to ask about the exemption Info
	$cardDataInfoRequest->demandExemptionInfo();

	// Service setting (Signature and Environment)
	$signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
	$service = new RESTInitialRequestService($signatureKey, RESTConstants::$ENV_SANDBOX);
	
	//Printing SendMessage
	echo "<h1>INITIAL: Mensaje a enviar</h1>";
	var_dump($cardDataInfoRequest);
	var_dump($service);

	//Send the operation and catch the response
	$response = $service->sendOperation($cardDataInfoRequest);

	// Response analysis
	echo "<h1>INITIAL: Respuesta recibida</h1>";
	var_dump($response);
		
	//Method the gives the request Result (OK/KO/AUT)
	switch ($response->getResult()) {

		case RESTConstants::$RESP_LITERAL_OK:
			//In this case the operation was ok and PSD2= "N", so authentication is not needed but its possible to make authentication
			echo "<h1>Operation was OK</h1>";
			//In this case the commerce can choose which kind of operation want to use
			//directPaymentOperation (example of this operation in InsiteExampleDirectPayment.java)
			//or authenticationOperationV2 (recommended)
			authenticationOperationV2($cardDataInfoRequest, $protocolVersion, $threeDSServerTransID, $threeDSMethodURL);
		break;

		case RESTConstants::$RESP_LITERAL_AUT: 
			//In this case the operation was ok and PSD2= "Y" and return the protocolVersion parameter
			echo "<h1>Operation requires authentication</h1>";
			//Method to catch the threeDSInfo value
			$threeDSInfo = $response->getThreeDSInfo();
			//Method to catch the protocolVersion (Required for authentication Request)
            $protocolVersion = $response->protocolVersionAnalysis();
            //Because the protocolVersion in this example is 2.X.0, its required to catch two mandatory Parameters in the response
            $threeDSServerTransID = $response->getThreeDSServerTransID();
            $threeDSMethodURL = $response->getThreeDSMethodURL();
			
			//Method to catch the Exemption List
			$exemptionList = $response->getExemption();
			echo "The commerce can use Exemption: " . $exemptionList;
			
            //Ones we catch the parameters, we must make the authenticationRequest
			authenticationOperationV2($cardDataInfoRequest, $protocolVersion, $threeDSServerTransID, $threeDSMethodURL);
		break;
		
		case RESTConstants::$RESP_LITERAL_KO: 
			//Operation error
			echo "<h1>Operation was not OK</h1>";
		break;
		
		default:
			echo "<h1>Aqui no debemos de entrar!!!</h1>";
	}
}
	
/**
 * Method for a authentication operation request. This request depend on the initial request parameter "protocolVersion"
 * param $orderID
 * param $protocolVersion
 */
function authenticationOperationV2($cardDataInfoRequest, $protocolVersion, $threeDSServerTransID, $threeDSMethodURL) {
	
	$operationRequest = new RESTOperationMessage();
			
	// Operation mandatory data
	$operationRequest->setAmount($cardDataInfoRequest->getAmount()); // i.e. 1,23 (decimal point depends on currency code)
	$operationRequest->setCurrency($cardDataInfoRequest->getCurrency()); // ISO-4217 numeric currency code
	$operationRequest->setMerchant($cardDataInfoRequest->getMerchant());
	$operationRequest->setTerminal($cardDataInfoRequest->getTerminal());
	$operationRequest->setOrder($cardDataInfoRequest->getOrder());
	//$operationRequest->useReference("020e2026d9449fd5b87cca8081782cf523496833");
	$operationRequest->setCardNumber("4548815374025114");
	$operationRequest->setCardExpiryDate("3412");
    $operationRequest->setCvv2("123");
	$operationRequest->setTransactionType(RESTConstants::$AUTHORIZATION);
	
	//Method to use and exemption, in this case we use the MIT Exemption
	$operationRequest->setExemption(RESTConstants::$REQUEST_MERCHANT_EXEMPTION_VALUE_LWV);

    //To get these authenticationRequest parameters values, the commerce needs to implement the 3DSMethod Request (consult the rest connection guide)
	//Each operation has its own values for the parameters values and the commerce must catch them
	//This is only an example of the values. 
    $browserAcceptHeader = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,application/json";
    $browserUserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36";
    $browserJavaEnable = "false";
    $browserJavaScriptEnabled = "false";
    $browserLanguage = "ES-es";
    $browserColorDepth = "24";
    $browserScreenHeight = "1250";
    $browserScreenWidth = "1320";
    $browserTZ = "52";
	$threeDSCompInd = "Y";

	//URL v2:
	$notificationURL= "http://localhost/ejemplosAPIREST/Example3DSecureV2ExemptionLWV.php?urlOK=yes";

	//Method to make a authenticationRequest with protocolVersion 2.0.X
    $operationRequest->setEMV3DSParamsV2($protocolVersion, $browserAcceptHeader, $browserUserAgent, $browserJavaEnable, $browserJavaScriptEnabled, $browserLanguage, $browserColorDepth, $browserScreenHeight, $browserScreenWidth, $browserTZ, $threeDSServerTransID, $notificationURL, $threeDSCompInd);	
  
	// Service setting (Signature and Environment)
	$signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
	$service = new RESTOperationService($signatureKey, RESTConstants::$ENV_SANDBOX);

	//Printing SendMessage
	echo "<h1>AUTHENTICATION: Mensaje a enviar</h1>";
	var_dump($operationRequest);

	//Send the operation and catch the response
	$response = $service->sendOperation($operationRequest);

	// Response analysis
	echo "<h1>AUTHENTICATION: Respuesta recibida</h1>";
	var_dump($response);

	//Depending on the response, its nedeed to finish or to make another step
	//Method the gives the request Result (OK/KO/AUT)
	switch ($response->getResult()) {
		case RESTConstants::$RESP_LITERAL_OK:
			//This is a frictionless response. In this case the operation was ok and the api return the final operation response
			echo "<h1>Operation was OK</h1>";
		break;
			
		case RESTConstants::$RESP_LITERAL_AUT:
			echo "<h1>Requesting ChallengeRequest...</h1>";
		break;
			
		case RESTConstants::$RESP_LITERAL_KO: 
			echo "<h1>Operation was not OK</h1>"; 
		break;
			
		default:
			echo "<h1>¡Aquí no debemos entrar!</h1>";
	}

	// Coger los parámetros que recibes por POST ($result) y montar el formulario (iframe) del challenge.
	if ($response->getResult() == RESTConstants::$RESP_LITERAL_AUT) {
		echo "<h1>Entra en el if de RESP_LITERAL_AUT if</h1>";
			
		var_dump(serialize($response->getOperation()));
		var_dump($response->getOperation());
			
		//If the operation was AUT, its a challenge response, the commerce needs to catch the authenticationParameters and send them in another Request
		$acsURL = $response->getAcsURLParameter();
		//$pAReq = $response->getPAReqParameter();
		//$md = $response->getMDParameter();
		$creq = $response->getCreqParameter();
		$ope = base64_encode(serialize($response->getOperation()));
		echo "<h1>OPE: </h1>";
		var_dump($ope);

		$filename = 'test.txt';
		$handle = file_put_contents($filename,$ope);
			
		//Formulario iframe que devuelve PARes, que es el resultado de la autenticación.
		$form = '<iframe name="redsys_iframe_acs" name="redsys_iframe_acs" src=""
					id="redsys_iframe_acs" target="_parent" referrerpolicy="origin"
					sandbox="allow-same-origin allow-scripts allow-top-navigation allow-forms"
					height="95%" width="100%" style="border: none; display: none;"></iframe>
				
			<form name="redsysAcsForm" id="redsysAcsForm"
				action="' . $acsURL . '" method="POST"
				target="redsys_iframe_acs" style="border: none;">
				<table name="dataTable" border="0" cellpadding="0">
					<input type="hidden" name="creq"
						value="' . $creq . '">
					<br>
					<p
						style="font-family: Arial; font-size: 16; font-weight: bold; color: black; align: center;">
						Conectando con el emisor...</p>
				</table>
			</form>
					
			<script>
				window.onload = function () {
					document.getElementById("redsys_iframe_acs").onload = function() {
						document.getElementById("redsysAcsForm").style.display="none";
						document.getElementById("redsys_iframe_acs").style.display="inline";
					}
					document.redsysAcsForm.submit();
				}
			</script>';
		
		echo "<h1>Mensaje:  GET Y POST rellenos: </h1>";
			
		echo " ***GET:*** ";
		var_dump($_GET);
				
		echo " ***POST:*** ";
		var_dump($_POST);
	
		die($form);
	}
}