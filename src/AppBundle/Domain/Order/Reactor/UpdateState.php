<?php

namespace AppBundle\Domain\Order\Reactor;

use AppBundle\Domain\Order\Command\AcceptOrder;
use AppBundle\Domain\Order\Event;
use AppBundle\Sylius\Order\OrderTransitions;
use Predis\Client as Redis;
use SimpleBus\Message\Bus\MessageBus;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * This Reactor is responsible for updating the state of the aggregate.
 */
class UpdateState
{
    private $stateMachineFactory;
    private $orderProcessor;
    private $serializer;
    private $redis;
    private $eventBus;

    private $eventNameToTransition = [];

    public function __construct(
        StateMachineFactoryInterface $stateMachineFactory,
        OrderProcessorInterface $orderProcessor,
        SerializerInterface $serializer,
        Redis $redis,
        MessageBus $eventBus)
    {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->orderProcessor = $orderProcessor;
        $this->serializer = $serializer;
        $this->redis = $redis;
        $this->eventBus = $eventBus;

        $this->eventNameToTransition = [
            Event\OrderCreated::messageName()   => OrderTransitions::TRANSITION_CREATE,
            Event\OrderAccepted::messageName()  => OrderTransitions::TRANSITION_ACCEPT,
            Event\OrderRefused::messageName()   => OrderTransitions::TRANSITION_REFUSE,
            Event\OrderCancelled::messageName() => OrderTransitions::TRANSITION_CANCEL,
            Event\OrderFulfilled::messageName() => OrderTransitions::TRANSITION_FULFILL,
        ];
    }

    public function __invoke(Event $event)
    {
        if ($event instanceof Event\CheckoutSucceeded || $event instanceof Event\CheckoutFailed) {
            $this->handleCheckoutEvent($event);
            return;
        }

        $this->handleStateChange($event);

        $order = $event->getOrder();

        if ($event instanceof Event\OrderFulfilled) {
            $stateMachine = $this->stateMachineFactory->get($event->getPayment(), PaymentTransitions::GRAPH);
            $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
        }

        if ($event instanceof Event\OrderCancelled) {
            foreach ($order->getPayments() as $payment) {
                $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
                $stateMachine->apply(PaymentTransitions::TRANSITION_CANCEL);
            }
        }
    }

    private function handleCheckoutEvent(Event $event)
    {
        $stripePayment = $event->getPayment();
        $stateMachine = $this->stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);

        if ($event instanceof Event\CheckoutSucceeded) {

            // TODO Create class constant for "authorize" transition
            $stateMachine->apply('authorize');

            // Trigger an order:created event
            // The event will be handled by this very same class
            $this->eventBus->handle(new Event\OrderCreated($event->getOrder()));
        }

        if ($event instanceof Event\CheckoutFailed) {
            $stripePayment->setLastError($event->getReason());
            $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);

            // Call OrderProcessor to create a new payment
            $this->orderProcessor->process($event->getOrder());
        }
    }

    private function handleStateChange(Event $event)
    {
        if (isset($this->eventNameToTransition[$event::messageName()])) {

            $order = $event->getOrder();

            $transition = $this->eventNameToTransition[$event::messageName()];

            $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
            $stateMachine->apply($transition);

            $this->redis->publish(
                sprintf('order:%d:state_changed', $order->getId()),
                $this->serializer->serialize($order, 'json', ['groups' => ['order']])
            );
        }
    }
}
