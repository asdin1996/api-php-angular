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
 * Method for a initial operation request that gives the card protocolVersion
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
	$cardDataInfoRequest->setTransactionType(RESTConstants::$AUTHORIZATION);
    
    //Card Data information
    $cardDataInfoRequest->setCardNumber("4918019199883839");
    $cardDataInfoRequest->setCardExpiryDate("3412");
    $cardDataInfoRequest->setCvv2("123");
	// Other optional parameters example can be added by "addParameter" method
	$cardDataInfoRequest->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago InSite con 3DSecure");
		
	//Method to ask about card information data
	$cardDataInfoRequest->demandCardData();

	// Service setting (Signature and Environment)
	$signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
	//$signatureKey = "Mk9m98IfEblmPfrpsawt7BmxObt98Jev";

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
    $operationRequest->setTransactionType(RESTConstants::$AUTHORIZATION);
    
    //Card Data information
    $operationRequest->setCardNumber("4918019199883839");
    $operationRequest->setCardExpiryDate("3412");
    $operationRequest->setCvv2("123");
    
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
	//URL v2
	$notificationURL= "http://localhost/ejemplosAPIREST/Example3DSecureV2CardData.php?urlOK=yes";
	//URL v1
	//$ope = strtr(base64_encode(serialize($operationRequest)), '+/', '-_');
	//$notificationURL = "http://localhost/ejemplosAPIREST/Example3DSecureV2CardData.php?urlOK=yes&ope=" . $ope;
	//Method to make a authenticationRequest with protocolVersion 2.0.X
    $operationRequest->setEMV3DSParamsV2($protocolVersion, $browserAcceptHeader, $browserUserAgent, $browserJavaEnable, $browserJavaScriptEnabled, $browserLanguage, $browserColorDepth, $browserScreenHeight, $browserScreenWidth, $browserTZ, $threeDSServerTransID, $notificationURL, $threeDSCompInd);	
    
    // Service setting (Signature and Environment)
	$signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
	//$signatureKey = "Mk9m98IfEblmPfrpsawt7BmxObt98Jev";

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

		if(json_decode($response->getOperation()->getEmv(),true)["protocolVersion"] == "2.1.0"){
			
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
		else{
			$operation = base64_encode(serialize($response->getOperation()));
			//echo "<h1>OPE: </h1>";
			//var_dump($operation);

			$filename = 'test.txt';
			$handle = file_put_contents($filename,$operation);

			$ope = strtr(base64_encode(serialize($response->getOperation())), '+/', '-_');
			echo "<h1>OPE: </h1>";
			var_dump($ope);

			var_dump(serialize($response->getOperation()));
			var_dump($response->getOperation());
			//If the operation was AUT, its a challenge response, the commerce needs to catch the authenticationParameters and send them in another Request
			$acsURL = $response->getAcsURLParameter();
			$pAReq = $response->getPAReqParameter();
			$md = $response->getMDParameter();
			$termUrl = "http://localhost/ejemplosAPIREST/Example3DSecureV2CardData.php?urlOK=yes&ope=" . $ope;
					
			//Formulario iframe que devuelve PARes, que es el resultado de la autenticación.
			$form = '<iframe name="redsys_iframe_acs" name="redsys_iframe_acs" src=""
						id="redsys_iframe_acs" target="_parent" referrerpolicy="origin"
						sandbox="allow-same-origin allow-scripts allow-top-navigation allow-forms"
						height="95%" width="100%" style="border: none; display: none;"></iframe>
				
				<form name="redsysAcsForm" id="redsysAcsForm"
					action="' . $acsURL . '" method="POST"
					target="redsys_iframe_acs" style="border: none;">
					<table name="dataTable" border="0" cellpadding="0">
						<input type="hidden" name="PaReq"
							value="' . $pAReq . '">
						<input type="hidden" name="TermUrl"
							value="' . $termUrl . '">
						<input type="hidden" name="MD"
							value="' . $md . '">
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
				
			echo " GET: ";
			var_dump($_GET);
					
			echo " POST: ";
			var_dump($_POST);
		
			die($form);
		}
	}
}

/**
 * Last authentication request. This request must be done with the return authenticacion parameters
 * that have to be catched
 */
function challengeRequestV2($operation) {
	
    //OperationData
	$challengeRequest = new RESTAuthenticationRequestMessage();
	
	$challengeRequest->setAmount($operation->getAmount()); // i.e. 1,23 (decimal point depends on currency code)
	$challengeRequest->setCurrency($operation->getCurrency()); // ISO-4217 numeric currency code
	$challengeRequest->setMerchant($operation->getMerchant());
	$challengeRequest->setTerminal($operation->getTerminal());
	$challengeRequest->setOrder($operation->getOrder());
	$challengeRequest->setTransactionType($operation->getTransactionType());
   
    //Card Data information
    $challengeRequest->setCardNumber("4918019199883839");
    $challengeRequest->setCardExpiryDate("3412");
    $challengeRequest->setCvv2("123");

    // Other optional parameters example can be added by "addParameter" method
	$challengeRequest->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago InSite con 3DSecure");
	
	//Method add the challengeRequestParameters depends on the protocolVersion
	$challengeRequest->challengeRequestV2(json_decode($operation->getEmv(),true)["protocolVersion"] ,$_POST ["cres"]);

	// Service setting (Signature and Environment)
	$signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
	//$signatureKey = "Mk9m98IfEblmPfrpsawt7BmxObt98Jev";
	$service = new RESTAuthenticationRequestService($signatureKey, RESTConstants::$ENV_SANDBOX);

	//Printing SendMessage
	echo "<h1>CHALLENGE REQUEST: Mensaje a enviar</h1>";
	var_dump($challengeRequest);

	//Send the operation and catch the response
	$response = $service->sendOperation($challengeRequest);
	
	// Response analysis
	echo "<h1>CHALLENGE REQUEST: Respuesta recibida</h1>";
	var_dump($response);

	//Method the gives the request Result (OK/KO)
	switch ($response->getResult()) {
		case RESTConstants::$RESP_LITERAL_OK:
			echo "<h1>Operation was OK</h1>";
		break;
		
		case RESTConstants::$RESP_LITERAL_KO: 
			echo "<h1>Operation was not OK</h1>"; 
		break;
		
		default:
			echo "<h1>¡Aquí no debemos entrar!</h1>";
	}
}

/**
 * Last authentication request. This request must be done with the return authenticacion parameters
 * that have to be catched
 */
function challengeRequestV1($operation) {
		
	//Ones the commerce capture the return authentication parameters, there this a last request to which give us the result of the operation
	//$md = "90b12ddf327ec896ac9e153d856ad4401e0241ff";
	//$pares = "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";		
			
	//OperationData
	$challengeRequest = new RESTAuthenticationRequestMessage();
	
	$challengeRequest->setAmount($operation->getAmount()); // i.e. 1,23 (decimal point depends on currency code)
	$challengeRequest->setCurrency($operation->getCurrency()); // ISO-4217 numeric currency code
	$challengeRequest->setMerchant($operation->getMerchant());
	$challengeRequest->setTerminal($operation->getTerminal());
	$challengeRequest->setOrder($operation->getOrder());
	$challengeRequest->setTransactionType($operation->getTransactionType());
    
    //Card Data information
    $challengeRequest->setCardNumber("4918019199883839");
    $challengeRequest->setCardExpiryDate("4912");
    $challengeRequest->setCvv2("123");

	//Other optional parameters example can be added by "addParameter" method
	$challengeRequest->addParameter("DS_MERCHANT_PRODUCTDESCRIPTION", "Prueba de pago InSite con 3DSecure");
				
	//Method add the challengeRequestParameters depends on the protocolVersion
	$challengeRequest->challengeRequestV1($_POST ["PaRes"], $_POST ["MD"]);

	//Service setting (Signature and Environment)
	$signatureKey = "sq7HjrUOBfKmC576ILgskD5srU870gJ7";
	$service = new RESTAuthenticationRequestService($signatureKey, RESTConstants::$ENV_SANDBOX);

	//Printing SendMessage
	echo "<h1>CHALLENGE REQUEST: Mensaje a enviar</h1>";
	var_dump($challengeRequest);

	//Send the operation and catch the response
	$response = $service->sendOperation($challengeRequest);
	
	// Response analysis
	echo "<h1>CHALLENGE REQUEST: Respuesta recibida</h1>";
	var_dump($response);

	//Method the gives the request Result (OK/KO)
	switch ($response->getResult()) {
		case RESTConstants::$RESP_LITERAL_OK:
			echo "<h1>Operation was OK</h1>";
		break;
		
		case RESTConstants::$RESP_LITERAL_KO: 
			echo "<h1>Operation was not OK</h1>"; 
		break;
		
		default:
			echo "<h1>¡Aquí no debemos entrar!</h1>";
	}
}