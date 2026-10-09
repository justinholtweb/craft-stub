<?php
/**
 * Stub integration checks — payment modes: full payment, deposits and pay in person.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-stub/tests/integration/checks.php
 *
 * The harness runs Stub mounted inside Showtime (the two can't be installed side by side), and the
 * mounted copy is this repo's `src/` synced by Showtime's `bin/sync-modules.sh` — so this checks the
 * code in this repo, through the bundle. It needs the harness's Stub fixtures (two providers, a
 * service each offers). No network: Stripe is never called — a payment intent's ledger row is
 * written the way `createPaymentIntent()` writes it, and the webhook is signed with a test secret.
 *
 * Idempotent and self-cleaning: every fixture it creates it deletes again, whether the run passes
 * or not, and the settings and service it borrows are put back the way they were found.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use craft\mail\Mailer;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\events\BookingEvent;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\models\Service;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\records\PaymentRecord;
use justinholtweb\stub\services\Bookings;
use yii\base\Event;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

Craft::$app->getPlugins()->loadPlugins();

$stub = Plugin::getInstance();

if ($stub === null) {
    echo "Stub isn't loaded (standalone or mounted in Showtime).\n";
    exit(1);
}

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$emailPrefix = 'stub-checks-';
$settings = $stub->getSettings();
$secretBefore = $settings->stripeWebhookSecret;
$createdServiceIds = [];
$createdUserIds = [];
$borrowedService = null;

// Mail is captured, never sent: every Stub email this run composes lands here instead.
$mail = [];
Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$mail) {
    $email = $event->message->getSymfonyEmail();
    $mail[] = [
        'key' => $event->message->key ?? null,
        'to' => array_keys((array)$event->message->getTo()),
        'subject' => (string)$email->getSubject(),
        'text' => (string)$email->getTextBody(),
    ];
    $event->isValid = false;
});

register_shutdown_function(function() use ($emailPrefix, &$createdServiceIds, &$createdUserIds, &$borrowedService, $stub, $settings, $secretBefore) {
    $settings->stripeWebhookSecret = $secretBefore;

    foreach ((new Query())->select(['id'])->from('{{%stub_customers}}')->where(['like', 'email', $emailPrefix])->column() as $customerId) {
        foreach ((new Query())->select(['id'])->from('{{%stub_bookings}}')->where(['customerId' => $customerId])->column() as $bookingId) {
            Craft::$app->getElements()->deleteElementById((int)$bookingId, null, null, true);
        }
        Craft::$app->getDb()->createCommand()->delete('{{%stub_customers}}', ['id' => $customerId])->execute();
    }

    foreach ($createdServiceIds as $serviceId) {
        Craft::$app->getDb()->createCommand()->delete('{{%stub_services}}', ['id' => $serviceId])->execute();
    }

    if ($borrowedService instanceof Service) {
        $stub->services->saveService($borrowedService);
    }

    foreach ($createdUserIds as $userId) {
        Craft::$app->getElements()->deleteElementById($userId, User::class, null, true);
    }

    echo "  cleaned\n";
});

// -------------------------------------------------------------------------------------------
// Fixtures

$customer = $stub->customers->findOrCreate("{$emailPrefix}{$run}@example.com", 'Checks', 'Customer', null);
$providerId = (int)(new Query())->select('id')->from('{{%stub_providers}}')->where(['enabled' => true, 'dateDeleted' => null])->scalar();

if (!$customer->id || !$providerId) {
    echo "Needs an enabled Stub provider.\n";
    exit(1);
}

$makeService = function(string $name, float $price, string $mode, string $depositType = 'percent', float $depositValue = 0) use ($stub, $run, &$createdServiceIds): Service {
    $service = new Service([
        'name' => "Checks $name $run",
        'handle' => 'checks' . ucfirst(preg_replace('/\W/', '', $name)) . $run,
        'duration' => 30,
        'price' => $price,
        'currency' => 'USD',
        'paymentMode' => $mode,
        'depositType' => $depositType,
        'depositValue' => $depositValue,
        'enabled' => false,
    ]);

    if ($stub->services->saveService($service)) {
        $createdServiceIds[] = $service->id;
    }

    return $service;
};

$dayOffset = 300;
$book = function(Service $service) use ($stub, $customer, $providerId, &$dayOffset): Booking {
    // Far enough ahead that no real slot is disturbed; each booking on its own day.
    $start = new DateTime('+' . ($dayOffset++) . ' days 15:00', new DateTimeZone('UTC'));

    return $stub->bookings->createBooking([
        'serviceId' => $service->id,
        'providerId' => $providerId,
        'customerId' => $customer->id,
        'startDateTime' => $start->format('Y-m-d H:i:s'),
        'endDateTime' => (clone $start)->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'timezone' => 'UTC',
    ]);
};

$reload = fn(Booking $booking): Booking => $stub->bookings->getBookingById($booking->id);

// What `Payments::createPaymentIntent()` writes before Stripe is asked anything — the ledger row
// the webhook later settles. Stripe itself isn't reachable from the harness.
$pendingIntent = function(Booking $booking) use ($run): string {
    static $n = 0;
    $intentId = 'pi_checks_' . $run . '_' . (++$n);
    $record = new PaymentRecord();
    $record->bookingId = $booking->id;
    $record->stripePaymentIntentId = $intentId;
    $record->amount = $booking->getAmountDueOnline();
    $record->currency = $booking->currency;
    $record->status = 'pending';
    $record->method = 'stripe';
    $record->save();

    return $intentId;
};

$secret = 'whsec_checks_' . $run;
$webhook = function(string $intentId, Booking $booking, int $minorAmount) use ($stub, $settings, $secret): bool {
    $settings->stripeWebhookSecret = $secret;
    $payload = json_encode([
        'id' => 'evt_' . $intentId,
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => $intentId,
            'object' => 'payment_intent',
            'amount' => $minorAmount,
            'amount_received' => $minorAmount,
            'latest_charge' => 'ch_' . $intentId,
            'metadata' => ['booking_id' => (string)$booking->id],
        ]],
    ]);
    $time = time();

    return $stub->payments->handleWebhookEvent($payload, "t=$time,v1=" . hash_hmac('sha256', "$time.$payload", $secret));
};

// -------------------------------------------------------------------------------------------
section('Schema');

check('services carry a payment mode and deposit', function() {
    $db = Craft::$app->getDb();
    foreach (['paymentMode', 'depositType', 'depositValue'] as $column) {
        if (!$db->columnExists('{{%stub_services}}', $column)) {
            return "stub_services.$column missing";
        }
    }
    return true;
});

check('bookings carry their mode, deposit and amount paid', function() {
    $db = Craft::$app->getDb();
    foreach (['paymentMode', 'depositAmount', 'amountPaid'] as $column) {
        if (!$db->columnExists('{{%stub_bookings}}', $column)) {
            return "stub_bookings.$column missing";
        }
    }
    return true;
});

check('the payments ledger records method, when paid, a note and who recorded it', function() {
    $db = Craft::$app->getDb();
    foreach (['method', 'paidAt', 'note', 'recordedById'] as $column) {
        if (!$db->columnExists('{{%stub_payments}}', $column)) {
            return "stub_payments.$column missing";
        }
    }
    return true;
});

check('the payment-modes migration is recorded on Stub’s track', fn() => (new Query())
    ->from('{{%migrations}}')
    ->where(['track' => 'plugin:stub', 'name' => 'm261008_000000_payment_modes'])
    ->exists() ?: 'not in the migrations table');

// -------------------------------------------------------------------------------------------
section('Service settings');

check('a deposit service has to ask for a deposit', function() {
    $service = new Service(['name' => 'x', 'handle' => 'x', 'price' => 100, 'paymentMode' => 'deposit', 'depositValue' => 0]);
    return !$service->validate() && $service->hasErrors('depositValue') ?: 'validated';
});

check('a percentage deposit must be under 100%', function() {
    $service = new Service(['name' => 'x', 'handle' => 'x', 'price' => 100, 'paymentMode' => 'deposit', 'depositType' => 'percent', 'depositValue' => 100]);
    return !$service->validate() && $service->hasErrors('depositValue') ?: 'validated';
});

check('a fixed deposit must be under the price', function() {
    $service = new Service(['name' => 'x', 'handle' => 'x', 'price' => 40, 'paymentMode' => 'deposit', 'depositType' => 'fixed', 'depositValue' => 40]);
    return !$service->validate() && $service->hasErrors('depositValue') ?: 'validated';
});

check('an unknown payment mode is refused', function() {
    $service = new Service(['name' => 'x', 'handle' => 'x', 'price' => 40, 'paymentMode' => 'barter']);
    return !$service->validate() && $service->hasErrors('paymentMode') ?: 'validated';
});

$fullService = $makeService('Full', 80, 'full');
$depositService = $makeService('Deposit', 120, 'deposit', 'percent', 25);
$fixedService = $makeService('Fixed', 50, 'deposit', 'fixed', 20);
$inPersonService = $makeService('InPerson', 60, 'inPerson');

check('services save and read back their payment settings', function() use ($stub, $depositService, $fixedService, $inPersonService, $fullService) {
    if (!$fullService->id || !$depositService->id || !$fixedService->id || !$inPersonService->id) {
        return 'a fixture service didn’t save: ' . json_encode([$depositService->getErrors(), $fixedService->getErrors(), $inPersonService->getErrors()]);
    }
    $read = $stub->services->getServiceById($depositService->id);
    $fixed = $stub->services->getServiceById($fixedService->id);
    $inPerson = $stub->services->getServiceById($inPersonService->id);

    return $read->paymentMode === 'deposit' && $read->depositType === 'percent' && $read->depositValue === 25.0
        && $fixed->depositType === 'fixed' && $fixed->depositValue === 20.0
        && $inPerson->paymentMode === 'inPerson'
        ?: json_encode([$read->toArray(['paymentMode', 'depositType', 'depositValue']), $inPerson->paymentMode]);
});

check('a percentage deposit is worked out on the price', fn() => $depositService->depositFor(120) === 30.0 ?: (string)$depositService->depositFor(120));
check('a fixed deposit is the fixed sum', fn() => $fixedService->depositFor(50) === 20.0 ?: (string)$fixedService->depositFor(50));
check('a full-payment service asks no deposit', fn() => $fullService->depositFor(80) === 0.0);

check('the booking form’s service cards show the deposit, and pay in person', function() use ($depositService, $inPersonService) {
    // The steps rather than the whole form: the form reads a CSRF token, which a console
    // request doesn't have.
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode($view::TEMPLATE_MODE_CP);

    try {
        $cards = $view->renderTemplate('stub/frontend/_steps/_service', ['services' => [$depositService, $inPersonService]]);
        $confirm = $view->renderTemplate('stub/frontend/_steps/_confirm');
    } finally {
        $view->setTemplateMode($mode);
    }

    return str_contains($cards, '$30.00 deposit to book') && str_contains($cards, 'Pay in person') && str_contains($confirm, 'id="stub-confirm-payment"')
        ?: 'card text missing';
});

// -------------------------------------------------------------------------------------------
section('Bookings by payment mode');

$fullBooking = $book($fullService);
$depositBooking = $book($depositService);
$fixedBooking = $book($fixedService);
$inPersonBooking = $book($inPersonService);

check('full payment: pending and unpaid, the whole price due online', function() use ($fullBooking) {
    return $fullBooking->id && $fullBooking->bookingStatus === 'pending' && $fullBooking->paymentStatus === 'unpaid'
        && $fullBooking->paymentMode === 'full' && $fullBooking->getAmountDueOnline() === 80.0
        ?: json_encode([$fullBooking->getErrors(), $fullBooking->bookingStatus, $fullBooking->getAmountDueOnline()]);
});

check('deposit: pending until the deposit is paid, and only the deposit is due online', function() use ($depositBooking, $reload) {
    $booking = $reload($depositBooking);

    return $booking->bookingStatus === 'pending' && $booking->paymentStatus === 'unpaid'
        && $booking->paymentMode === 'deposit' && (float)$booking->depositAmount === 30.0
        && $booking->getAmountDueOnline() === 30.0 && $booking->getBalanceDue() === 120.0
        ?: json_encode([$booking->bookingStatus, $booking->paymentStatus, $booking->depositAmount, $booking->getAmountDueOnline()]);
});

check('…and its summary says what’s due now and what’s left for the appointment', function() use ($depositBooking) {
    $summary = $depositBooking->getPaymentSummary();

    return str_contains($summary, '$30.00') && str_contains($summary, '$90.00') ?: $summary;
});

check('pay in person: confirmed straight away, unpaid, nothing due online', function() use ($inPersonBooking) {
    return $inPersonBooking->bookingStatus === 'confirmed' && $inPersonBooking->paymentStatus === 'unpaid'
        && $inPersonBooking->getAmountDueOnline() === 0.0 && $inPersonBooking->getBalanceDue() === 60.0
        ?: json_encode([$inPersonBooking->bookingStatus, $inPersonBooking->paymentStatus, $inPersonBooking->getAmountDueOnline()]);
});

check('…and its summary says payment is due at the appointment', fn() => str_contains($inPersonBooking->getPaymentSummary(), 'due at the appointment') ?: $inPersonBooking->getPaymentSummary());

check('the booking keeps its mode when the service changes later', function() use ($stub, $inPersonService, $inPersonBooking, $reload) {
    $service = $stub->services->getServiceById($inPersonService->id);
    $service->paymentMode = 'full';
    $stub->services->saveService($service);

    try {
        return $reload($inPersonBooking)->paymentMode === 'inPerson' ?: 'mode followed the service';
    } finally {
        $service->paymentMode = 'inPerson';
        $stub->services->saveService($service);
    }
});

check('a deposit follows a price a save handler changed (a member discount)', function() use ($book, $depositService) {
    $handler = function(BookingEvent $event) {
        $event->booking->price = 60.0;
    };
    Event::on(Bookings::class, Bookings::EVENT_BEFORE_SAVE_BOOKING, $handler);

    try {
        $booking = $book($depositService);
    } finally {
        Event::off(Bookings::class, Bookings::EVENT_BEFORE_SAVE_BOOKING, $handler);
    }

    return (float)$booking->price === 60.0 && (float)$booking->depositAmount === 15.0 ?: json_encode([$booking->price, $booking->depositAmount]);
});

// -------------------------------------------------------------------------------------------
section('Stripe: the deposit, then the balance');

$revenueBefore = (function() use ($stub) {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());
    return $stub->payments->getRevenueBetween(new DateTime('first day of this month 00:00:00', $tz), new DateTime('last day of this month 23:59:59', $tz));
})();
$monthRevenue = function() use ($stub): float {
    $tz = new DateTimeZone(Craft::$app->getTimeZone());
    return $stub->payments->getRevenueBetween(new DateTime('first day of this month 00:00:00', $tz), new DateTime('last day of this month 23:59:59', $tz));
};

$depositIntent = $pendingIntent($depositBooking);

check('the deposit’s intent is for the deposit, not the price', fn() => (float)PaymentRecord::findOne(['stripePaymentIntentId' => $depositIntent])->amount === 30.0);

check('a signed payment_intent.succeeded for the deposit is accepted', fn() => $webhook($depositIntent, $depositBooking, 3000) ?: 'refused');

check('the booking is confirmed and partially paid, with the balance still owed', function() use ($depositBooking, $reload) {
    $booking = $reload($depositBooking);

    return $booking->bookingStatus === 'confirmed' && $booking->paymentStatus === 'partiallyPaid'
        && (float)$booking->amountPaid === 30.0 && $booking->getBalanceDue() === 90.0 && $booking->paidAt === null
        ?: json_encode([$booking->bookingStatus, $booking->paymentStatus, $booking->amountPaid, $booking->paidAt]);
});

check('the deposit is settled in the ledger with the moment it arrived', function() use ($depositIntent) {
    $record = PaymentRecord::findOne(['stripePaymentIntentId' => $depositIntent]);

    return $record->status === 'succeeded' && $record->paidAt !== null && $record->stripeChargeId === 'ch_' . $depositIntent
        ?: json_encode($record->toArray(['status', 'paidAt', 'stripeChargeId']));
});

check('a second delivery of the same webhook doesn’t count the deposit twice', function() use ($webhook, $depositIntent, $depositBooking, $reload) {
    $webhook($depositIntent, $depositBooking, 3000);

    return (float)$reload($depositBooking)->amountPaid === 30.0 ?: (string)$reload($depositBooking)->amountPaid;
});

check('with the deposit taken, nothing more is due online', fn() => $reload($depositBooking)->getAmountDueOnline() === 0.0);

check('the confirmation email went out once, with the deposit and the balance in it', function() use (&$mail, $customer) {
    $confirmations = array_values(array_filter($mail, fn($m) => $m['key'] === 'stub_booking_confirmation' && in_array($customer->email, $m['to'], true)));

    if (count($confirmations) !== 1) {
        return count($confirmations) . ' confirmation(s) captured';
    }

    return str_contains($confirmations[0]['text'], '$30.00') && str_contains($confirmations[0]['text'], '$90.00')
        ?: $confirmations[0]['text'];
});

check('this month’s revenue rose by the deposit — cash received, not the booking’s price', fn() => round($monthRevenue() - $revenueBefore, 2) === 30.0 ?: (string)($monthRevenue() - $revenueBefore));

$fullIntent = $pendingIntent($fullBooking);
check('full payment: the webhook marks it paid in full and confirms it', function() use ($webhook, $fullIntent, $fullBooking, $reload) {
    $webhook($fullIntent, $fullBooking, 8000);
    $booking = $reload($fullBooking);

    return $booking->paymentStatus === 'paid' && $booking->bookingStatus === 'confirmed' && (float)$booking->amountPaid === 80.0 && $booking->paidAt !== null
        ?: json_encode([$booking->paymentStatus, $booking->bookingStatus, $booking->amountPaid]);
});

// -------------------------------------------------------------------------------------------
section('Mark as paid');

check('more than the balance is refused', function() use ($stub, $depositBooking, $reload) {
    $payment = $stub->payments->recordManualPayment($reload($depositBooking), 95.0);
    return $payment->hasErrors('amount') ?: 'recorded';
});

check('nothing, or less than nothing, is refused', function() use ($stub, $depositBooking, $reload) {
    return $stub->payments->recordManualPayment($reload($depositBooking), 0.0)->hasErrors('amount')
        && $stub->payments->recordManualPayment($reload($depositBooking), -5.0)->hasErrors('amount') ?: 'recorded';
});

check('part of the balance leaves it partially paid', function() use ($stub, $depositBooking, $reload) {
    $payment = $stub->payments->recordManualPayment($reload($depositBooking), 40.0, 'cash');
    $booking = $reload($depositBooking);

    return !$payment->hasErrors() && $booking->paymentStatus === 'partiallyPaid' && $booking->getBalanceDue() === 50.0
        ?: json_encode([$payment->getErrors(), $booking->paymentStatus, $booking->getBalanceDue()]);
});

check('the rest (the default amount) marks it paid, with the note in the ledger', function() use ($stub, $depositBooking, $reload) {
    $payment = $stub->payments->recordManualPayment($reload($depositBooking), null, 'card at the desk', 1);
    $booking = $reload($depositBooking);
    $ledger = $stub->payments->getPaymentsForBooking($booking->id);
    $last = end($ledger);

    return !$payment->hasErrors() && (float)$payment->amount === 50.0 && $booking->paymentStatus === 'paid'
        && (float)$booking->amountPaid === 120.0 && $booking->paidAt !== null
        && $last->method === 'manual' && $last->note === 'card at the desk' && $last->recordedById === 1 && count($ledger) === 3
        ?: json_encode([$payment->getErrors(), $booking->paymentStatus, $booking->amountPaid, count($ledger)]);
});

check('a paid booking can’t be marked paid again', fn() => $stub->payments->recordManualPayment($reload($depositBooking))->hasErrors('amount') ?: 'recorded');

check('pay in person: marking it paid records the whole price', function() use ($stub, $inPersonBooking, $reload) {
    $stub->payments->recordManualPayment($reload($inPersonBooking), null, 'cash');
    $booking = $reload($inPersonBooking);

    return $booking->paymentStatus === 'paid' && (float)$booking->amountPaid === 60.0 && $booking->bookingStatus === 'confirmed'
        ?: json_encode([$booking->paymentStatus, $booking->amountPaid]);
});

check('this month’s revenue is every payment received: 30 + 80 + 40 + 50 + 60', fn() => round($monthRevenue() - $revenueBefore, 2) === 260.0 ?: (string)($monthRevenue() - $revenueBefore));

check('a deposit paid last month counts last month, not this one', function() use ($stub, $fixedBooking, $reload, $monthRevenue, $revenueBefore) {
    $stub->payments->recordManualPayment($reload($fixedBooking), 20.0);
    $lastMonth = (new DateTime('first day of last month 12:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    Craft::$app->getDb()->createCommand()->update('{{%stub_payments}}', ['paidAt' => $lastMonth], ['bookingId' => $fixedBooking->id])->execute();

    return round($monthRevenue() - $revenueBefore, 2) === 260.0 ?: (string)($monthRevenue() - $revenueBefore);
});

check('the dashboard’s monthly revenue is the ledger’s', function() use ($stub, $monthRevenue) {
    $stats = $stub->bookings->getBookingStats();
    return abs($stats['monthlyRevenue'] - $monthRevenue()) < 0.001 ?: json_encode([$stats['monthlyRevenue'], $monthRevenue()]);
});

check('the dashboard counts the balance still owed on confirmed bookings', function() use ($stub, $fixedBooking, $reload) {
    // The fixed-deposit booking is confirmed? It's still pending (paid by hand, not by Stripe),
    // so it isn't owed money yet. Confirm it and its 30 balance becomes outstanding.
    $before = $stub->bookings->getBookingStats()['outstandingBalance'];
    $stub->bookings->updateStatus($reload($fixedBooking), BookingStatus::Confirmed);
    $after = $stub->bookings->getBookingStats()['outstandingBalance'];

    return round($after - $before, 2) === 30.0 ?: json_encode([$before, $after]);
});

check('a manual booking entered as paid is in the ledger', function() use ($stub, $fullService, $customer, $providerId) {
    $start = new DateTime('+400 days 09:00', new DateTimeZone('UTC'));
    $booking = $stub->bookings->createManualBooking([
        'serviceId' => $fullService->id,
        'providerId' => $providerId,
        'customerId' => $customer->id,
        'startDateTime' => $start->format('Y-m-d H:i:s'),
        'endDateTime' => (clone $start)->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'timezone' => 'UTC',
        'bookingStatus' => 'confirmed',
        'paymentStatus' => 'paid',
    ]);
    $ledger = $stub->payments->getPaymentsForBooking((int)$booking->id);

    return $booking->paymentStatus === 'paid' && (float)$booking->amountPaid === 80.0
        && count($ledger) === 1 && $ledger[0]->method === 'manual' && $ledger[0]->amount === 80.0 && $ledger[0]->status === 'succeeded'
        ?: json_encode([$booking->paymentStatus, $booking->amountPaid, count($ledger)]);
});

check('a manual booking can’t be entered as “partially paid” with no amount', function() use ($stub, $fullService, $customer, $providerId) {
    $start = new DateTime('+401 days 09:00', new DateTimeZone('UTC'));
    $booking = $stub->bookings->createManualBooking([
        'serviceId' => $fullService->id, 'providerId' => $providerId, 'customerId' => $customer->id,
        'startDateTime' => $start->format('Y-m-d H:i:s'), 'endDateTime' => (clone $start)->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'timezone' => 'UTC', 'bookingStatus' => 'confirmed', 'paymentStatus' => 'partiallyPaid',
    ]);

    return $booking->paymentStatus === 'unpaid' ?: $booking->paymentStatus;
});

check('the ledger backfill gives a paid booking with no payment a manual row, and is idempotent', function() use ($stub, $fullService, $customer, $providerId) {
    // A booking as an older version left it: marked paid, no payment row behind it.
    $start = new DateTime('+402 days 09:00', new DateTimeZone('UTC'));
    $booking = $stub->bookings->createManualBooking([
        'serviceId' => $fullService->id, 'providerId' => $providerId, 'customerId' => $customer->id,
        'startDateTime' => $start->format('Y-m-d H:i:s'), 'endDateTime' => (clone $start)->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'timezone' => 'UTC', 'bookingStatus' => 'confirmed', 'paymentStatus' => 'paid',
    ]);
    Craft::$app->getDb()->createCommand()->delete('{{%stub_payments}}', ['bookingId' => $booking->id])->execute();

    $migration = new \justinholtweb\stub\migrations\m261008_000000_payment_modes();
    $backfill = (new ReflectionClass($migration))->getMethod('_backfillLedger');
    $backfill->setAccessible(true);
    ob_start();
    $backfill->invoke($migration);
    $backfill->invoke($migration);
    ob_end_clean();

    $ledger = $stub->payments->getPaymentsForBooking((int)$booking->id);

    return count($ledger) === 1 && $ledger[0]->method === 'manual' && $ledger[0]->amount === 80.0 && $ledger[0]->paidAt !== null
        ?: json_encode(array_map(fn($p) => $p->toArray(['method', 'amount', 'paidAt']), $ledger));
});

// -------------------------------------------------------------------------------------------
section('Emails');

check('every Stub email body renders with the payment variables', function() use ($stub, $depositBooking, $reload) {
    $emails = $stub->emails;
    $variables = (new ReflectionMethod($emails, '_getEmailVariables'))->invoke($emails, $reload($depositBooking));

    foreach (['amountPaidFormatted', 'balanceDueFormatted', 'paymentSummary'] as $name) {
        if (!array_key_exists($name, $variables)) {
            return "$name isn't passed to emails";
        }
    }

    foreach (Plugin::emailDefinitions() as $key => $definition) {
        $declared = array_intersect_key($variables, array_flip($definition['variables']));
        $body = Craft::$app->getView()->renderSandboxedString($definition['body'], $declared);
        if ($key !== 'stub_booking_cancellation' && !str_contains($body, 'Paid in full: $120.00')) {
            return "$key: " . $body;
        }
    }

    return true;
});

check('a pay-in-person booking’s confirmation says what’s due at the appointment', function() use ($stub, $book, $inPersonService, &$mail) {
    $mail = [];
    $booking = $book($inPersonService);
    $stub->emails->sendConfirmation($booking);
    $text = $mail[0]['text'] ?? '';

    return str_contains($text, 'Payment of $60.00 is due at the appointment') ?: ($text ?: 'nothing captured');
});

// -------------------------------------------------------------------------------------------
section('Over HTTP');

$http = new Client(['base_uri' => 'http://127.0.0.1/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$json = ['Accept' => 'application/json'];
$csrf = static function(Client $client, string $prefix = 'index.php?p=') use ($json): string {
    return (string)(json_decode((string)$client->get($prefix . 'actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
};

// Borrow a real, bookable service for the public form, as pay-in-person, and put it back after.
$borrowedId = (int)(new Query())->select('s.id')->from(['s' => '{{%stub_services}}'])
    ->innerJoin(['ps' => '{{%stub_provider_services}}'], '[[ps.serviceId]] = [[s.id]]')
    ->where(['s.enabled' => true, 's.dateDeleted' => null])->andWhere(['>', 's.price', 0])
    ->andWhere(['ps.providerId' => $providerId])->scalar();
$borrowedService = $borrowedId ? $stub->services->getServiceById($borrowedId) : null;

$slot = null;
if ($borrowedService) {
    $provider = $stub->providers->getProviderById($providerId);
    for ($day = 2; $day <= 30 && $slot === null; $day++) {
        $date = (new DateTime("+$day days", new DateTimeZone($provider->timezone)))->format('Y-m-d');
        foreach ($stub->availability->getAvailableSlots($borrowedId, $providerId, $date, 'UTC') as $candidate) {
            $at = new DateTime($candidate['utc'], new DateTimeZone('UTC'));
            if ($stub->availability->isBookable($borrowedId, $providerId, $at)) {
                $slot = $at;
                break;
            }
        }
    }
}

check('the public form books a pay-in-person service: confirmed, nothing to pay online', function() use ($http, $json, $csrf, $stub, $borrowedService, $slot, $providerId, $emailPrefix, $run) {
    if (!$borrowedService || !$slot) {
        return 'no bookable paid service with an open slot in the next month';
    }

    $inPerson = clone $borrowedService;
    $inPerson->paymentMode = 'inPerson';
    $stub->services->saveService($inPerson);

    foreach (['127.0.0.1', '::1', gethostbyname(gethostname())] as $ip) {
        Craft::$app->getCache()->delete("stub:rl:booking:$ip");
    }

    $response = json_decode((string)$http->post('index.php?p=actions/stub/booking-form/submit', [
        'headers' => $json,
        'form_params' => [
            'serviceId' => $borrowedService->id, 'providerId' => $providerId,
            'startDateTime' => $slot->format('Y-m-d H:i:s'), 'timezone' => 'UTC',
            'email' => "{$emailPrefix}http-{$run}@example.com", 'firstName' => 'Http', 'lastName' => 'Checks',
            'CRAFT_CSRF_TOKEN' => $csrf($http),
        ],
    ])->getBody(), true) ?? [];

    $booking = isset($response['bookingId']) ? $stub->bookings->getBookingById((int)$response['bookingId']) : null;

    return ($response['success'] ?? null) === true && ($response['requiresPayment'] ?? null) === false
        && ($response['paymentMode'] ?? null) === 'inPerson' && str_contains((string)($response['paymentSummary'] ?? ''), 'due at the appointment')
        && $booking?->bookingStatus === 'confirmed' && $booking?->paymentStatus === 'unpaid'
        ?: json_encode($response);
});

check('create-intent refuses a pay-in-person booking — there is nothing to charge', function() use ($http, $json, $csrf, $inPersonService, $book) {
    $booking = $book($inPersonService);
    $response = json_decode((string)$http->post('index.php?p=actions/stub/payment/create-intent', [
        'headers' => $json,
        'form_params' => [
            'bookingId' => $booking->id,
            'paymentToken' => BookingHelper::generatePaymentToken($booking),
            'CRAFT_CSRF_TOKEN' => $csrf($http),
        ],
    ])->getBody(), true) ?? [];

    return ($response['success'] ?? null) === false && ($response['error'] ?? '') === 'No payment required.' ?: json_encode($response);
});

// The CP endpoint: who may mark a booking paid.
$target = $book($inPersonService);

check('mark-paid refuses an anonymous request', function() use ($http, $csrf, $target, $reload) {
    $response = $http->post('admin/actions/stub/bookings/mark-paid', [
        'form_params' => ['bookingId' => $target->id, 'CRAFT_CSRF_TOKEN' => $csrf($http)],
    ]);

    return $reload($target)->paymentStatus === 'unpaid' && in_array($response->getStatusCode(), [302, 403], true)
        ?: 'status ' . $response->getStatusCode() . ', payment ' . $reload($target)->paymentStatus;
});

$login = static function(string $username, string $password) use ($csrf, $json): ?Client {
    $client = new Client(['base_uri' => 'http://127.0.0.1/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $response = json_decode((string)$client->post('index.php?p=actions/users/login', [
        'headers' => $json,
        'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf($client)],
    ])->getBody(), true);

    // A JSON login answers with the signed-in user, or an `errorCode`.
    return isset($response['user']) && !isset($response['errorCode']) ? $client : null;
};

// A CP user who can manage bookings but not record payments.
$clerkPassword = 'checks-' . bin2hex(random_bytes(6));
$clerk = new User([
    'username' => "{$emailPrefix}clerk-{$run}",
    'email' => "{$emailPrefix}clerk-{$run}@example.com",
    'newPassword' => $clerkPassword,
]);
$clerk->active = true;
$clerk->pending = false;
if (Craft::$app->getElements()->saveElement($clerk, false)) {
    $createdUserIds[] = $clerk->id;
    Craft::$app->getUserPermissions()->saveUserPermissions($clerk->id, ['accessCp', 'stub:viewBookings', 'stub:manageBookings']);
}

check('mark-paid refuses a user without “Record payments”', function() use ($login, $clerk, $clerkPassword, $csrf, $target, $reload) {
    $client = $clerk->id ? $login($clerk->username, $clerkPassword) : null;
    if (!$client) {
        return 'couldn’t sign the test user in';
    }

    $response = $client->post('admin/actions/stub/bookings/mark-paid', [
        'form_params' => ['bookingId' => $target->id, 'CRAFT_CSRF_TOKEN' => $csrf($client, 'admin/')],
    ]);

    return $response->getStatusCode() === 403 && $reload($target)->paymentStatus === 'unpaid'
        ?: 'status ' . $response->getStatusCode() . ', payment ' . $reload($target)->paymentStatus;
});

check('mark-paid refuses a GET', function() use ($login, $target, $reload) {
    $admin = User::find()->id(1)->status(null)->one();
    $admin->newPassword = 'claudepassword';
    Craft::$app->getElements()->saveElement($admin, false);
    $client = $login('admin', 'claudepassword');
    if (!$client) {
        return 'couldn’t sign admin in';
    }

    $response = $client->get('admin/actions/stub/bookings/mark-paid?bookingId=' . $target->id);

    return in_array($response->getStatusCode(), [400, 405], true) && $reload($target)->paymentStatus === 'unpaid' ?: 'status ' . $response->getStatusCode();
});

check('…and with “Record payments”, it records the balance and marks the booking paid', function() use ($login, $csrf, $target, $reload, $stub) {
    $client = $login('admin', 'claudepassword');
    if (!$client) {
        return 'couldn’t sign admin in';
    }

    $response = $client->post('admin/actions/stub/bookings/mark-paid', [
        'form_params' => ['bookingId' => $target->id, 'note' => 'cash', 'CRAFT_CSRF_TOKEN' => $csrf($client, 'admin/')],
    ]);
    $booking = $reload($target);
    $ledger = $stub->payments->getPaymentsForBooking($booking->id);

    return $response->getStatusCode() === 302 && $booking->paymentStatus === 'paid' && (float)$booking->amountPaid === 60.0
        && count($ledger) === 1 && $ledger[0]->note === 'cash' && $ledger[0]->recordedById === 1
        ?: 'status ' . $response->getStatusCode() . ', payment ' . $booking->paymentStatus;
});

check('the booking screen shows the amounts and the Mark as Paid form', function() use ($login, $depositService, $book) {
    $client = $login('admin', 'claudepassword');
    $booking = $book($depositService);
    $html = (string)$client?->get("admin/stub/bookings/{$booking->id}")->getBody();

    foreach (['Balance Due', '$120.00', '$30.00', 'stub/bookings/mark-paid', 'Mark as Paid'] as $needle) {
        if (!str_contains($html, $needle)) {
            return "missing “{$needle}”";
        }
    }

    return true;
});

check('the service screen offers the three payment modes', function() use ($login, $depositService) {
    $client = $login('admin', 'claudepassword');
    $html = (string)$client?->get("admin/stub/services/{$depositService->id}")->getBody();

    foreach (['name="paymentMode"', 'value="inPerson"', 'name="depositType"', 'name="depositValue"'] as $needle) {
        if (!str_contains($html, $needle)) {
            return "missing {$needle}";
        }
    }

    return true;
});

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
