<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\events\BookingEvent;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\Plugin;
use yii\base\Component;

class Bookings extends Component
{
    public const EVENT_BEFORE_SAVE_BOOKING = 'beforeSaveBooking';
    public const EVENT_AFTER_SAVE_BOOKING = 'afterSaveBooking';
    public const EVENT_BEFORE_STATUS_CHANGE = 'beforeStatusChange';
    public const EVENT_AFTER_STATUS_CHANGE = 'afterStatusChange';

    public function createBooking(array $attributes): Booking
    {
        $booking = new Booking();
        $booking->serviceId = (int)$attributes['serviceId'];
        $booking->providerId = (int)$attributes['providerId'];
        $booking->customerId = (int)$attributes['customerId'];
        $booking->startDateTime = $attributes['startDateTime'];
        $booking->endDateTime = $attributes['endDateTime'];
        $booking->timezone = $attributes['timezone'] ?? 'UTC';
        $booking->referenceNumber = BookingHelper::generateReferenceNumber();
        $booking->customerNotes = $attributes['customerNotes'] ?? null;

        $service = Plugin::getInstance()->services->getServiceById($booking->serviceId);
        if ($service) {
            $booking->price = $service->price;
            $booking->currency = $service->currency;
        }

        // Auto-confirm free bookings
        $settings = Plugin::getInstance()->getSettings();
        if ($booking->price <= 0 && $settings->autoConfirmFreeBookings) {
            $booking->bookingStatus = BookingStatus::Confirmed->value;
            $booking->paymentStatus = PaymentStatus::Paid->value;
        } else {
            $booking->bookingStatus = BookingStatus::Pending->value;
            $booking->paymentStatus = PaymentStatus::Unpaid->value;
        }

        // Fire before event
        $event = new BookingEvent(['booking' => $booking, 'isNew' => true]);
        $this->trigger(self::EVENT_BEFORE_SAVE_BOOKING, $event);

        if (!Craft::$app->getElements()->saveElement($booking)) {
            return $booking;
        }

        // Fire after event
        $this->trigger(self::EVENT_AFTER_SAVE_BOOKING, new BookingEvent(['booking' => $booking, 'isNew' => true]));

        return $booking;
    }

    public function getBookingById(int $id): ?Booking
    {
        return Booking::find()->id($id)->one();
    }

    public function getBookingByReference(string $referenceNumber): ?Booking
    {
        return Booking::find()->referenceNumber($referenceNumber)->one();
    }

    public function updateStatus(Booking $booking, BookingStatus $newStatus, ?string $reason = null): bool
    {
        $oldStatus = $booking->bookingStatus;

        $event = new BookingEvent(['booking' => $booking]);
        $this->trigger(self::EVENT_BEFORE_STATUS_CHANGE, $event);

        $booking->bookingStatus = $newStatus->value;

        if ($newStatus === BookingStatus::Cancelled) {
            $booking->cancelledAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $booking->cancellationReason = $reason;
        }

        $success = Craft::$app->getElements()->saveElement($booking);

        if ($success) {
            $this->trigger(self::EVENT_AFTER_STATUS_CHANGE, new BookingEvent(['booking' => $booking]));

            // Trigger emails
            if ($newStatus === BookingStatus::Confirmed) {
                Plugin::getInstance()->emails->sendConfirmation($booking);
            } elseif ($newStatus === BookingStatus::Cancelled) {
                Plugin::getInstance()->emails->sendCancellation($booking);
            }
        }

        return $success;
    }

    public function markPaid(Booking $booking, string $paymentIntentId): bool
    {
        $booking->paymentStatus = PaymentStatus::Paid->value;
        $booking->stripePaymentIntentId = $paymentIntentId;
        $booking->paidAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return Craft::$app->getElements()->saveElement($booking);
    }

    public function getTodaysBookings(): array
    {
        $today = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');

        return Booking::find()
            ->startDateTime(">= {$today} 00:00:00")
            ->endDateTime("<= {$today} 23:59:59")
            ->bookingStatus('confirmed')
            ->orderBy(['startDateTime' => SORT_ASC])
            ->all();
    }

    public function getBookingsForDateRange(string $startDate, string $endDate, ?int $providerId = null): array
    {
        // Callers (e.g. FullCalendar) may pass ISO-8601 with an offset such as
        // "2026-07-19T00:00:00-04:00". Datetimes are stored in UTC, so convert both
        // bounds to UTC before comparing.
        $utc = new DateTimeZone('UTC');
        $start = (new DateTime($startDate))->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = (new DateTime($endDate))->setTimezone($utc)->format('Y-m-d H:i:s');

        $query = Booking::find()
            ->startDateTime(">= {$start}")
            ->endDateTime("<= {$end}")
            ->orderBy(['startDateTime' => SORT_ASC]);

        if ($providerId) {
            $query->providerId($providerId);
        }

        return $query->all();
    }

    public function getBookingStats(): array
    {
        $today = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $weekStart = (new DateTime('monday this week', new DateTimeZone('UTC')))->format('Y-m-d');
        $weekEnd = (new DateTime('sunday this week', new DateTimeZone('UTC')))->format('Y-m-d');
        $monthStart = (new DateTime('first day of this month', new DateTimeZone('UTC')))->format('Y-m-d');
        $monthEnd = (new DateTime('last day of this month', new DateTimeZone('UTC')))->format('Y-m-d');

        return [
            'todayCount' => (int)Booking::find()
                ->startDateTime(">= {$today} 00:00:00")
                ->startDateTime("<= {$today} 23:59:59")
                ->bookingStatus('confirmed')
                ->count(),

            'weekCount' => (int)Booking::find()
                ->startDateTime(">= {$weekStart} 00:00:00")
                ->startDateTime("<= {$weekEnd} 23:59:59")
                ->count(),

            'pendingCount' => (int)Booking::find()
                ->bookingStatus('pending')
                ->count(),

            'monthlyRevenue' => (float)(new Query())
                ->from('{{%stub_bookings}}')
                ->where(['paymentStatus' => 'paid'])
                ->andWhere(['>=', 'paidAt', "{$monthStart} 00:00:00"])
                ->andWhere(['<=', 'paidAt', "{$monthEnd} 23:59:59"])
                ->sum('price'),
        ];
    }
}
