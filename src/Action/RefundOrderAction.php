<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * You can find more information about us on https://bitbag.io and write us
 * an email on hello@bitbag.io.
 */

declare(strict_types=1);

namespace BitBag\SyliusMolliePlugin\Action;

use BitBag\SyliusMolliePlugin\Action\Api\BaseApiAwareAction;
use BitBag\SyliusMolliePlugin\Helper\ConvertRefundDataInterface;
use BitBag\SyliusMolliePlugin\Logger\MollieLoggerActionInterface;
use BitBag\SyliusMolliePlugin\Request\Api\RefundOrder;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Types\PaymentStatus;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Webmozart\Assert\Assert;

final class RefundOrderAction extends BaseApiAwareAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /** @var MollieLoggerActionInterface */
    private $loggerAction;

    /** @var ConvertRefundDataInterface */
    private $convertOrderRefundData;

    public function __construct(
        MollieLoggerActionInterface $loggerAction,
        ConvertRefundDataInterface $convertOrderRefundData
    ) {
        $this->loggerAction = $loggerAction;
        $this->convertOrderRefundData = $convertOrderRefundData;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (!array_key_exists('refund', $details['metadata'])) {
            return;
        }

        /** @var PaymentInterface $payment */
        $payment = $request->getFirstModel();

        Assert::notNull($payment->getCurrencyCode());
        $refundData = $this->convertOrderRefundData->convert($details['metadata']['refund'], $payment->getCurrencyCode());

        $molliePayment = null;
        try {
            $order = $this->mollieApiClient->orders->get($details['order_mollie_id'], ['embed' => 'payments']);
            $embeddedPayments = $order->_embedded->payments;

            /** @var Payment $embeddedPayment */
            foreach ($embeddedPayments as $embeddedPayment) {
                if (PaymentStatus::STATUS_PAID === $embeddedPayment->status) {
                    $molliePayment = $this->mollieApiClient->payments->get($embeddedPayment->id);
                }
            }
        } catch (ApiException $e) {
            $this->loggerAction->addNegativeLog(sprintf('API call failed: %s', htmlspecialchars($e->getMessage())));

            throw new \Exception(sprintf('API call failed: %s', htmlspecialchars($e->getMessage())));
        }

        Assert::notNull($molliePayment);

        if ($molliePayment->hasRefunds()) {
            return;
        }

        /** @var PaymentInterface $payment */
        $payment = $request->getFirstModel();

        try {
            $currencyCode = $payment->getCurrencyCode();
            Assert::notNull($currencyCode);

            $refundData = $this->convertOrderRefundData->convert($details['metadata']['refund'], $currencyCode);

            $this->mollieApiClient->payments->refund(
                $molliePayment,
                [
                    'amount' => $refundData,
                ]
            );

            $this->loggerAction->addLog(sprintf('Refund order action with order id: %s', $order->id));
        } catch (ApiException $e) {
            $this->loggerAction->addNegativeLog(sprintf('Error refund order action with: %s', $e->getMessage()));

            throw new UpdateHandlingException(sprintf('API call failed: %s', htmlspecialchars($e->getMessage())));
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof RefundOrder &&
            $request->getModel() instanceof \ArrayAccess;
    }
}
