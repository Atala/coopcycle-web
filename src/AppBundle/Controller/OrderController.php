<?php

namespace AppBundle\Controller;

use AppBundle\Utils\Cart;
use AppBundle\Entity\DeliveryAddress;
use AppBundle\Entity\Restaurant;
use AppBundle\Entity\Order;
use AppBundle\Entity\OrderItem;
use AppBundle\Entity\GeoCoordinates;
use AppBundle\Form\OrderType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use League\Geotools\Geotools;
use League\Geotools\Coordinate\Coordinate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Stripe;

/**
 * @Route("/order")
 */
class OrderController extends Controller
{
    use DoctrineTrait;

    private function getCart(Request $request)
    {
        return $request->getSession()->get('cart');
    }

    private function createOrderFromRequest(Request $request)
    {
        $cart = $this->getCart($request);

        $productRepository = $this->getRepository('Product');
        $restaurantRepository = $this->getRepository('Restaurant');

        $restaurant = $restaurantRepository->find($cart->getRestaurantId());

        $order = new Order();
        $order->setRestaurant($restaurant);
        $order->setCustomer($this->getUser());

        foreach ($cart->getItems() as $item) {

            $product = $productRepository->find($item['id']);

            $orderItem = new OrderItem();
            $orderItem->setProduct($product);
            $orderItem->setQuantity($item['quantity']);

            $order->addOrderedItem($orderItem);
        }

        return $order;
    }

    /**
     * @Route("/", name="order")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        if (null === $this->getCart($request)) {
            return [];
        }

        $order = $this->createOrderFromRequest($request);

        if (!$request->isMethod('POST') && $request->getSession()->has('deliveryAddress')) {
            $deliveryAddress = $request->getSession()->get('deliveryAddress');
            $deliveryAddress = $this->getDoctrine()
                ->getManagerForClass('AppBundle:DeliveryAddress')->merge($deliveryAddress);

            $order->setDeliveryAddress($deliveryAddress);
        }

        $form = $this->createForm(OrderType::class, $order);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $order = $form->getData();
            $deliveryAddress = $order->getDeliveryAddress();

            $createDeliveryAddress = $form->get('createDeliveryAddress')->getData();
            if ($createDeliveryAddress) {

                $latitude = $form->get('deliveryAddress')->get('latitude')->getData();
                $longitude = $form->get('deliveryAddress')->get('longitude')->getData();

                $deliveryAddress->setCustomer($this->getUser());
                $deliveryAddress->setGeo(new GeoCoordinates($latitude, $longitude));

                $this->getDoctrine()->getManagerForClass('AppBundle:DeliveryAddress')->persist($deliveryAddress);
                $this->getDoctrine()->getManagerForClass('AppBundle:DeliveryAddress')->flush();
            }

            $request->getSession()->set('deliveryAddress', $deliveryAddress);

            return $this->redirectToRoute('order_payment');
        }

        return array(
            'form' => $form->createView(),
            'google_api_key' => $this->getParameter('google_api_key'),
            'restaurant' => $order->getRestaurant(),
            'has_delivery_address' => count($this->getUser()->getDeliveryAddresses()) > 0,
            'cart' => $this->getCart($request),
        );
    }

    /**
     * @Route("/payment", name="order_payment")
     * @Template()
     */
    public function paymentAction(Request $request)
    {
        if (!$request->getSession()->has('deliveryAddress')) {
            return $this->redirectToRoute('order');
        }

        $order = $this->createOrderFromRequest($request);

        $deliveryAddress = $request->getSession()->get('deliveryAddress');
        $deliveryAddress = $this->getDoctrine()
            ->getManagerForClass('AppBundle:DeliveryAddress')->merge($deliveryAddress);

        $order->setDeliveryAddress($deliveryAddress);

        if ($request->isMethod('POST') && $request->request->has('stripeToken')) {

            $this->getDoctrine()->getManagerForClass('AppBundle:Order')->persist($order);
            $this->getDoctrine()->getManagerForClass('AppBundle:Order')->flush();

            Stripe\Stripe::setApiKey($this->getParameter('stripe_secret_key'));

            $token = $request->request->get('stripeToken');

            try {

                $charge = Stripe\Charge::create(array(
                    "amount" => $order->getTotal() * 100, // Amount in cents
                    "currency" => "eur",
                    "source" => $token,
                    "description" => "Order #".$order->getId(),
                    "transfer_group" => "Order#".$order->getId(),
                ));

                // Create a Transfer to a connected account (later):
                $owner = $order->getRestaurant()->getOwner();

                $transfer = \Stripe\Transfer::create(array(
                  "amount" => (($order->getTotal() * 100) * 0.75),
                  "currency" => "eur",
                  "destination" => $owner->getStripeParams()->getUserId(),
                  "transfer_group" => "Order#".$order->getId(),
                ));

            } catch (Stripe\Error\Card $e) {
                return $this->redirectToRoute('order_error', array('id' => $order->getId()));
            }

            $order->setStatus(Order::STATUS_WAITING);

            $this->getDoctrine()
                ->getManagerForClass('AppBundle:Order')->flush();

            $this->get('event_dispatcher')
                ->dispatch('order.payment_success', new GenericEvent($order));

            $request->getSession()->remove('cart');
            $request->getSession()->remove('deliveryAddress');

            return $this->redirectToRoute('profile_order', array('id' => $order->getId()));
        }

        return array(
            'order' => $order,
            'restaurant' => $order->getRestaurant(),
            'stripe_publishable_key' => $this->getParameter('stripe_publishable_key')
        );
    }

    /**
     * @Route("/{id}/confirm", name="order_confirm")
     * @Template()
     */
    public function confirmAction($id, Request $request)
    {
        $order = $this->getRepository('Order')->find($id);

        return array(
            'order' => $order,
            'order_json' => $this->get('serializer')->serialize($order, 'jsonld'),
            'google_api_key' => $this->getParameter('google_api_key')
        );
    }
}
