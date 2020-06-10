<?php

/**
 * Invoice Ninja (https://invoiceninja.com)
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2020. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\PaymentDrivers\Authorize;

use App\Exceptions\GenericPaymentDriverFailure;
use App\Models\ClientGatewayToken;
use App\Models\GatewayType;
use App\PaymentDrivers\AuthorizePaymentDriver;
use App\PaymentDrivers\Authorize\AuthorizeCreateCustomer;
use net\authorize\api\contract\v1\CreateCustomerPaymentProfileRequest;
use net\authorize\api\contract\v1\CreateCustomerProfileRequest;
use net\authorize\api\contract\v1\CustomerAddressType;
use net\authorize\api\contract\v1\CustomerPaymentProfileType;
use net\authorize\api\contract\v1\CustomerProfileType;
use net\authorize\api\contract\v1\OpaqueDataType;
use net\authorize\api\contract\v1\PaymentType;
use net\authorize\api\controller\CreateCustomerPaymentProfileController;
use net\authorize\api\controller\CreateCustomerProfileController;

/**
 * Class BaseDriver
 * @package App\PaymentDrivers
 *
 */
class AuthorizePaymentMethod
{
    public $authorize;

    public $payment_method;

    public function __construct(AuthorizePaymentDriver $authorize)
    {
        $this->authorize = $authorize;
    }

    public function authorizeView($payment_method)
    {
        $this->payment_method = $payment_method;

        switch ($payment_method) {
            case GatewayType::CREDIT_CARD:
                return $this->authorizeCreditCard();
                break;
            case GatewayType::BANK_TRANSFER:
                return $this->authorizeBankTransfer();
                break;

            default:
                # code...
                break;
        }

    }

    public function authorizeResponseView($payment_method, $data)
    {

        switch ($payment_method) {
            case GatewayType::CREDIT_CARD:
                return $this->authorizeCreditCardResponse($data);
                break;
            case GatewayType::BANK_TRANSFER:
                return $this->authorizeBankTransferResponse($data);
                break;

            default:
                # code...
                break;
        }

    }

    public function authorizeCreditCard()
    {
        $data['gateway'] = $this->authorize->company_gateway;
        $data['public_client_id'] = $this->authorize->init()->getPublicClientKey();
        $data['api_login_id'] = $this->authorize->company_gateway->getConfigField('apiLoginId');

        return render('gateways.authorize.add_credit_card', $data);
    }

    public function authorizeBankTransfer()
    {
        
    }

    public function authorizeCreditCardResponse($data)
    {
        $client_profile_id = null;

        if($client_gateway_token = $this->authorize->findClientGatewayRecord())
            $payment_profile = $this->addPaymentMethodToClient($client_gateway_token->gateway_customer_reference, $data);
        else{
            $gateway_customer_reference = (new AuthorizeCreateCustomer($this->authorize, $this->client))->create($data);
            $payment_profile = $this->addPaymentMethodToClient($gateway_customer_reference, $data);
        }

        $this->createClientGatewayToken($payment_profile, $gateway_customer_reference);

        return redirect()->route('client.payment_methods.index');

    }

    public function authorizeBankTransferResponse($data)
    {
        
    }

    private function createClientGatewayToken($payment_profile, $gateway_customer_reference)
    {
        $client_gateway_token = new ClientGatewayToken();
        $client_gateway_token->company_id = $this->client->company_id;
        $client_gateway_token->client_id = $this->client->id;
        $client_gateway_token->token = $payment_profile->getCustomerPaymentProfileId();
        $client_gateway_token->company_gateway_id = $this->authorize->company_gateway->id;
        $client_gateway_token->gateway_type_id = $this->payment_method;
        $client_gateway_token->gateway_customer_reference = $gateway_customer_reference;
        $client_gateway_token->meta = $this->buildPaymentMethod($payment_profile);
        $client_gateway_token->save();
    }

    public function buildPaymentMethod($payment_profile)
    {
        $payment_meta = new \stdClass;
        $payment_meta->exp_month = $stripe_payment_method_obj['card']['exp_month'];
        $payment_meta->exp_year = $stripe_payment_method_obj['card']['exp_year'];
        $payment_meta->brand = $stripe_payment_method_obj['card']['brand'];
        $payment_meta->last4 = $stripe_payment_method_obj['card']['last4'];
        $payment_meta->type = $this->payment_method;

        return $payment_meta;
    }

    private function addPaymentMethodToClient($gateway_customer_reference, $data)
    {

        error_reporting (E_ALL & ~E_DEPRECATED);

        $merchantAuthentication = $this->authorize->init();
    
        // Set the transaction's refId
        $refId = 'ref' . time();

        // Set the payment data for the payment profile to a token obtained from Accept.js
        $op = new OpaqueDataType();
        $op->setDataDescriptor($data['dataDescriptor']);
        $op->setDataValue($data['dataValue']);
        $paymentOne = new PaymentType();
        $paymentOne->setOpaqueData($op);

        $contact = $this->client->primary_contact()->first();

        if($contact){
        // Create the Bill To info for new payment type
            $billto = new CustomerAddressType();
            $billto->setFirstName($contact->present()->first_name());
            $billto->setLastName($contact->present()->last_name());
            $billto->setCompany($this->client->present()->name());
            $billto->setAddress($this->client->address1);
            $billto->setCity($this->client->city);
            $billto->setState($this->client->state);
            $billto->setZip($this->client->postal_code);
            $billto->setCountry("USA");
            $billto->setPhoneNumber($this->client->phone);
        }

        // Create a new Customer Payment Profile object
        $paymentprofile = new CustomerPaymentProfileType();
        $paymentprofile->setCustomerType('individual');

        if($billto)
            $paymentprofile->setBillTo($billto);

        $paymentprofile->setPayment($paymentOne);
        $paymentprofile->setDefaultPaymentProfile(true);
        $paymentprofiles[] = $paymentprofile;

        // Assemble the complete transaction request
        $paymentprofilerequest = new CreateCustomerPaymentProfileRequest();
        $paymentprofilerequest->setMerchantAuthentication($merchantAuthentication);

        // Add an existing profile id to the request
        $paymentprofilerequest->setCustomerProfileId($gateway_customer_references);
        $paymentprofilerequest->setPaymentProfile($paymentprofile);
        $paymentprofilerequest->setValidationMode("liveMode");

        // Create the controller and get the response
        $controller = new CreateCustomerPaymentProfileController($paymentprofilerequest);
        $response = $controller->executeWithApiResponse($this->authorize->mode());

        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok") ) {
            return $response;
        } else {

            $errorMessages = $response->getMessages()->getMessage();

            $message = "Unable to add customer to Authorize.net gateway";

            if(is_array($errorMessages))
                $message = $errorMessages[0]->getCode() . "  " .$errorMessages[0]->getText();

            throw new GenericPaymentDriverFailure($message);

        }

    }
    
}
