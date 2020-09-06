<?php
/**
 * Payment Ninja (https://paymentninja.com).
 *
 * @link https://github.com/paymentninja/paymentninja source repository
 *
 * @copyright Copyright (c) 2020. Payment Ninja LLC (https://paymentninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Services\Payment;

use App\Events\Payment\PaymentWasCreated;
use App\Factory\PaymentFactory;
use App\Models\Client;
use App\Models\Payment;
use App\Services\AbstractService;
use App\Services\Client\ClientService;
use App\Services\Payment\PaymentService;
use App\Utils\Traits\GeneratesCounter;

class ApplyNumber extends AbstractService
{
    use GeneratesCounter;

    private $payment;

    public function __construct(Payment $payment)
    {
        $this->client = $payment->client;

        $this->payment = $payment;
    }

    public function run()
    {
        if ($this->payment->number != '') {
            return $this->payment;
        }

        $this->payment->number = $this->getNextPaymentNumber($this->client);

        return $this->payment;
    }
}
