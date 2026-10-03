<?php
/**
 * What an anonymous booking and an unsigned webhook can do — checked in the plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-stub/tests/harness/security.php
 *
 * The harness runs Stub mounted inside Showtime (the two can't be installed side by side), which
 * is exactly the code synced from this repo. Until 5.8.1:
 *
 * - a blank Stripe webhook secret verified signatures made with an empty key, so anyone could post
 *   a `payment_intent.succeeded` and mark a booking paid;
 * - the public booking form trusted any time and provider — the past, a closed day, a full slot,
 *   a provider who doesn't offer the service;
 * - an anonymous booking that named an existing customer's email renamed them and changed their phone.
 *
 * Each refusal is paired with what should still work. Needs the harness's Stub fixtures (two
 * providers, four services). Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\stub\models\Customer;
use justinholtweb\stub\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

$stub = Plugin::getInstance();

if ($stub === null) {
    echo "Stub isn't loaded (standalone or mounted in Showtime).\n";
    exit(1);
}

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$email = "stub-security-$run@example.com";
$settings = $stub->getSettings();
$secretBefore = $settings->stripeWebhookSecret;

register_shutdown_function(function() use ($email, $stub) {
    foreach ((new Query())->select(['id'])->from('{{%stub_customers}}')->where(['like', 'email', 'stub-security-'])->column() as $customerId) {
        foreach ((new Query())->select(['id'])->from('{{%stub_bookings}}')->where(['customerId' => $customerId])->column() as $bookingId) {
            Craft::$app->getElements()->deleteElementById((int)$bookingId, null, null, true);
        }
        Craft::$app->getDb()->createCommand()->delete('{{%stub_customers}}', ['id' => $customerId])->execute();
    }
});

// A provider who offers a free service, and the first slot the booking form would offer for it.
$serviceId = (int)(new Query())->select('id')->from('{{%stub_services}}')->where(['price' => 0, 'enabled' => true])->scalar();
$providerId = (int)(new Query())->select('providerId')->from('{{%stub_provider_services}}')->where(['serviceId' => $serviceId])->scalar();
$provider = $stub->providers->getProviderById($providerId);
$slotUtc = null;

for ($day = 1; $day <= 21 && $slotUtc === null; $day++) {
    $date = (new DateTime("+$day days", new DateTimeZone($provider->timezone)))->format('Y-m-d');
    $slots = $stub->availability->getAvailableSlots($serviceId, $providerId, $date, 'UTC');

    if ($slots) {
        // getAvailableSlots answers HH:MM in the requested timezone for the provider's date.
        foreach ([$date, (new DateTime("$date +1 day"))->format('Y-m-d'), (new DateTime("$date -1 day"))->format('Y-m-d')] as $utcDate) {
            $candidate = new DateTime("$utcDate {$slots[0]['time']}", new DateTimeZone('UTC'));
            if ($candidate->setTimezone(new DateTimeZone($provider->timezone))->format('Y-m-d') === $date) {
                $slotUtc = $candidate->setTimezone(new DateTimeZone('UTC'));
                break;
            }
        }
    }
}

if (!$serviceId || !$provider || $slotUtc === null) {
    echo "Needs a free service with a provider who has an open slot in the next three weeks.\n";
    exit(1);
}

// A provider and a service they do *not* offer — any pair missing from provider_services.
$offered = (new Query())->select(['providerId', 'serviceId'])->from('{{%stub_provider_services}}')->all();
$offeredKeys = array_map(fn($r) => $r['providerId'] . ':' . $r['serviceId'], $offered);
[$unofferedProvider, $unofferedService] = [0, 0];
foreach ((new Query())->select('id')->from('{{%stub_providers}}')->column() as $pid) {
    foreach ((new Query())->select('id')->from('{{%stub_services}}')->column() as $sid) {
        if (!in_array("$pid:$sid", $offeredKeys, true)) {
            [$unofferedProvider, $unofferedService] = [(int)$pid, (int)$sid];
            break 2;
        }
    }
}

if (!$unofferedProvider) {
    echo "Needs a provider who doesn't offer every service.\n";
    exit(1);
}

// -------------------------------------------------------------------------------------------
echo "\nStripe webhooks\n";

$sign = static fn(string $payload, string $secret) => 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $payload, $secret);
$payload = json_encode(['id' => "evt_$run", 'object' => 'event', 'type' => 'stub.security.ping', 'data' => ['object' => ['id' => "pi_$run", 'metadata' => []]]]);

check('with no signing secret, an event signed with an empty key is refused', function() use ($stub, $settings, $sign, $payload) {
    $settings->stripeWebhookSecret = '';

    return $stub->payments->handleWebhookEvent($payload, $sign($payload, '')) === false ?: 'accepted';
});

check('with a secret, a correctly signed event is accepted', function() use ($stub, $settings, $sign, $payload, $secretBefore) {
    $settings->stripeWebhookSecret = 'whsec_security_test';

    try {
        return $stub->payments->handleWebhookEvent($payload, $sign($payload, 'whsec_security_test')) === true ?: 'refused';
    } finally {
        $settings->stripeWebhookSecret = $secretBefore;
    }
});

// -------------------------------------------------------------------------------------------
echo "\nWhat can be booked\n";

check('an open slot is bookable', fn() => $stub->availability->isBookable($serviceId, $providerId, $slotUtc) ?: 'refused');
check('…but not seven minutes into it', fn() => !$stub->availability->isBookable($serviceId, $providerId, (clone $slotUtc)->modify('+7 minutes')) ?: 'accepted');
check('…nor in the past', fn() => !$stub->availability->isBookable($serviceId, $providerId, new DateTime('-3 days 10:00', new DateTimeZone('UTC'))) ?: 'accepted');
check('…nor with a provider who doesn’t offer the service', function() use ($stub, $unofferedProvider, $unofferedService, $slotUtc) {
    // Same moment, so the only thing wrong is the pairing — and an offered service would book.
    return !$stub->availability->isBookable($unofferedService, $unofferedProvider, $slotUtc) ?: 'accepted';
});

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$json = ['Accept' => 'application/json'];
$book = static function(array $params) use ($http, $json): array {
    // The booking form's per-address limit would otherwise refuse later runs. Requests here come
    // from inside the container, so clear every address's counter for the bucket.
    foreach (['127.0.0.1', '::1', gethostbyname(gethostname())] as $ip) {
        Craft::$app->getCache()->delete("stub:rl:booking:$ip");
    }
    $csrf = (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');

    return json_decode((string)$http->post('index.php?p=actions/stub/booking-form/submit', [
        'headers' => $json,
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf],
    ])->getBody(), true) ?? [];
};

$booking = static fn(DateTime $at, int $provider, string $first = 'Anon', string $last = 'Booker', string $phone = '') => [
    'serviceId' => $serviceId, 'providerId' => $provider, 'startDateTime' => $at->format('Y-m-d H:i:s'), 'timezone' => 'UTC',
    'email' => $email, 'firstName' => $first, 'lastName' => $last, 'phone' => $phone,
];

check('the booking form refuses a time that isn’t a slot', function() use ($book, $booking, $slotUtc, $providerId) {
    $response = $book($booking((clone $slotUtc)->modify('+7 minutes'), $providerId));

    return ($response['success'] ?? null) === false ?: json_encode($response);
});

check('…and a provider who doesn’t offer the service', function() use ($book, $booking, $slotUtc, $unofferedProvider, $unofferedService) {
    $params = $booking($slotUtc, $unofferedProvider);
    $params['serviceId'] = $unofferedService;
    $response = $book($params);

    return ($response['success'] ?? null) === false ?: json_encode($response);
});

check('…and books an open slot', function() use ($book, $booking, $slotUtc, $providerId) {
    $response = $book($booking($slotUtc, $providerId));

    return ($response['success'] ?? null) === true ?: json_encode($response);
});

// -------------------------------------------------------------------------------------------
echo "\nExisting customers\n";

check('an anonymous booking doesn’t rename an existing customer, but fills in what’s missing', function() use ($stub, $email, $book, $booking, $slotUtc, $serviceId, $providerId) {
    $customer = $stub->customers->getCustomerByEmail($email);
    $customer->firstName = 'Original';
    $customer->lastName = 'Person';
    $customer->phone = null;
    $stub->customers->saveCustomer($customer);

    // A real booking has to get through, so find the next open slot after the first.
    $next = null;
    for ($minutes = 5; $minutes <= 24 * 60 && $next === null; $minutes += 5) {
        $candidate = (clone $slotUtc)->modify("+$minutes minutes");
        if ($stub->availability->isBookable($serviceId, $providerId, $candidate)) {
            $next = $candidate;
        }
    }

    if ($next === null) {
        return 'no second slot to book';
    }

    $response = $book($booking($next, $providerId, 'Someone', 'Else', '555-0100'));
    $after = $stub->customers->getCustomerByEmail($email);

    return ($response['success'] ?? null) === true && $after->firstName === 'Original' && $after->lastName === 'Person' && $after->phone === '555-0100'
        ?: json_encode(['response' => $response, 'name' => "{$after->firstName} {$after->lastName}", 'phone' => $after->phone]);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
