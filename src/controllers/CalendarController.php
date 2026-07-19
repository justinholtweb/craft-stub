<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class CalendarController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('stub:viewBookings');
        return true;
    }

    public function actionIndex(): Response
    {
        $providers = Plugin::getInstance()->providers->getAllProviders();

        return $this->renderTemplate('stub/calendar/_index', [
            'providers' => $providers,
            'selectedSubnavItem' => 'calendar',
        ]);
    }

    public function actionEvents(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $start = $request->getParam('start');
        $end = $request->getParam('end');
        $providerId = $request->getParam('providerId');

        $bookings = Plugin::getInstance()->bookings->getBookingsForDateRange(
            $start,
            $end,
            $providerId ? (int)$providerId : null,
        );

        $events = [];
        foreach ($bookings as $booking) {
            $service = $booking->getService();
            $provider = $booking->getProvider();
            $customer = $booking->getCustomer();

            $events[] = [
                'id' => $booking->id,
                'title' => ($service ? $service->name : 'Booking') .
                    ($customer ? ' — ' . $customer->getFullName() : ''),
                'start' => $booking->startDateTime,
                'end' => $booking->endDateTime,
                'color' => $service ? $service->color : '#2563eb',
                'url' => $booking->getCpEditUrl(),
                'extendedProps' => [
                    'status' => $booking->bookingStatus,
                    'provider' => $provider ? $provider->name : '',
                    'reference' => $booking->referenceNumber,
                ],
            ];
        }

        return $this->asJson(['events' => $events]);
    }
}
