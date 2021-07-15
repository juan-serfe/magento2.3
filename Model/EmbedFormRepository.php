<?php

namespace Conekta\Payments\Model;

use Conekta\Payments\Logger\Logger as ConektaLogger;
use Conekta\Payments\Api\Data\ConektaQuoteInterface;
use Conekta\Payments\Api\EmbedFormRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Conekta\Order as ConektaOrderApi;
use Conekta\ParameterValidationError;
use Conekta\Payments\Exception\ConektaException;

class EmbedFormRepository implements EmbedFormRepositoryInterface
{
    private $_conektaLogger;
    private $conektaQuoteInterface;
    protected $conektaOrderApi;
    private $conektaQuoteFactory;
    private $conektaQuoteRepositoryFactory;

    public function __construct(
        ConektaLogger $conektaLogger,
        ConektaQuoteInterface $conektaQuoteInterface,
        ConektaOrderApi $conektaOrderApi,
        ConektaQuoteFactory $conektaQuoteFactory,
        ConektaQuoteRepositoryFactory $conektaQuoteRepositoryFactory
    ) {
        $this->_conektaLogger = $conektaLogger;
        $this->conektaQuoteInterface = $conektaQuoteInterface;
        $this->conektaQuoteRepositoryFactory = $conektaQuoteRepositoryFactory;
        $this->conektaQuoteFactory = $conektaQuoteFactory;
        $this->conektaOrderApi = $conektaOrderApi;
    }

    /**
     * @param array $orderParams
     * @return void
     * @throws ConektaException
     */
    private function validateOrderParameters($orderParameters)
    {
        //Currency
        if (strtoupper($orderParameters['currency']) !== 'MXN') {
            throw new ConektaException(
                __('Este medio de pago no acepta moneda extranjera')
            );
        }

        //Minimum amount per quote
        $total = 0;
        foreach ($orderParameters['line_items'] as $lineItem) {
            $total += $lineItem['unit_price']*$lineItem['quantity'];
        }
        
        if ($total < ConektaQuoteInterface::MINIMUM_AMOUNT_PER_QUOTE*100) {
            throw new ConektaException(
                __('Para utilizar este medio de pago
                debe ingresar una compra superior a $'.ConektaQuoteInterface::MINIMUM_AMOUNT_PER_QUOTE)
            );
        }
    }

    /**
     * @param int $quoteId
     * @param array $orderParams
     * @return \Conekta\Order
     * @throws ConektaException
     */
    public function generate($quoteId, $orderParams)
    {

        //Validate params
        $this->validateOrderParameters($orderParams);

        $conektaQuoteRepo = $this->conektaQuoteRepositoryFactory->create();

        $conektaQuote = null;
        $conektaOrder = null;
        $hasToCreateNewOrder = false;
        try {
            $conektaQuote = $conektaQuoteRepo->getByid($quoteId);
            $conektaOrder = $this->conektaOrderApi->find($conektaQuote->getConektaOrderId());

            if (!empty($conektaOrder) &&
                (!empty($conektaOrder->payment_status) || time() >= $conektaOrder->checkout->expires_at)
            ) {
                $hasToCreateNewOrder = true;
            }
        } catch (NoSuchEntityException $e) {
            $conektaQuote = null;
            $conektaOrder = null;
            $hasToCreateNewOrder = true;
        }

        try {
            /**
             * Creates new conekta order-checkout if:
             *   1- Not exist row in map table conekta_quote
             *   2- Exist row in map table and:
             *      2.1- conekta order has payment_status OR
             *      2.2- conekta order checkout has expired
             */
            if ($hasToCreateNewOrder) {
                $this->_conektaLogger->info('EmbedFormRepository::generate Creates conekta order', $orderParams);
                //Creates checkout order
                $conektaOrder = $this->conektaOrderApi->create($orderParams);
                
                //Save map conekta order and quote
                $conektaQuote = $this->conektaQuoteFactory->create();
                $conektaQuote->setQuoteId($quoteId);
                $conektaQuote->setConektaOrderId($conektaOrder['id']);
                $conektaQuoteRepo->save($conektaQuote);
            } else {
                $this->_conektaLogger->info('EmbedFormRepository::generate  Updates conekta order', $orderParams);
                //If map between conekta order and quote exist, then just updated conekta order
                $conektaOrder = $this->conektaOrderApi->find($conektaQuote->getConektaOrderId());
                
                //TODO detect if checkout config has been modified
                unset($orderParams['customer_info']);
                $conektaOrder->update($orderParams);
            }

            return $conektaOrder;
        } catch (ParameterValidationError $e) {
            $this->_conektaLogger->error('EmbedFormRepository::generate Error: ' . $e->getMessage());
            throw new ConektaException(__($e->getMessage()));
        }
    }
}
