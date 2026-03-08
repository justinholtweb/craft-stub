<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class PaymentController extends Controller
{
    protected array|int|bool $allowAnonymous = ['create-intent'];

    public function actionCreateIntent(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $bookingId = (int)Craft::$app->getRequest()->getRequiredBodyParam('bookingId');
        $booking = Plugin::getInstance()->bookings->getBookingById($bookingId);

        if (!$booking) {
            return $this->asJson(['success' => false, 'error' => 'Booking not found.']);
        }

        if ($booking->price <= 0) {
            return $this->asJson(['success' => false, 'error' => 'No payment required.']);
        }

        $result = Plugin::getInstance()->payments->createPaymentIntent($booking);

        if (!$result) {
            return $this->asJson(['success' => false, 'error' => 'Failed to create payment.']);
        }

        return $this->asJson([
            'success' => true,
            'clientSecret' => $result['clientSecret'],
        ]);
    }
}
