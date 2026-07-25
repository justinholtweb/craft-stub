<?php

namespace justinholtweb\stub;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\models\Settings;
use justinholtweb\stub\services\Availability;
use justinholtweb\stub\services\Bookings;
use justinholtweb\stub\services\Customers;
use justinholtweb\stub\services\Emails;
use justinholtweb\stub\services\Payments;
use justinholtweb\stub\services\Providers;
use justinholtweb\stub\services\Services;
use justinholtweb\stub\variables\StubVariable;
use yii\base\Event;

/**
 * Stub — Booking & Appointments for Craft CMS
 *
 * @property Services $services
 * @property Providers $providers
 * @property Bookings $bookings
 * @property Availability $availability
 * @property Customers $customers
 * @property Payments $payments
 * @property Emails $emails
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * When true, Stub is running as an internal module mounted inside the Showtime bundle
     * plugin rather than installed as a standalone plugin. In that mode Stub boots its
     * feature wiring but leaves control-panel "chrome" (nav, settings screen) to the
     * Showtime host, which unifies it with the other bundled plugins.
     *
     * Default false → standalone behavior is unchanged.
     */
    public bool $mountedUnderShowtime = false;

    public static function config(): array
    {
        return [
            'components' => [
                'services' => Services::class,
                'providers' => Providers::class,
                'bookings' => Bookings::class,
                'availability' => Availability::class,
                'customers' => Customers::class,
                'payments' => Payments::class,
                'emails' => Emails::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->bootFeatures();

        if (!$this->mountedUnderShowtime) {
            $this->bootChrome();
        }
    }

    /**
     * Functionality that must run in BOTH modes (standalone and mounted under Showtime).
     */
    private function bootFeatures(): void
    {
        $this->_registerElementTypes();
        $this->_registerVariables();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
        $this->_registerPermissions();
        $this->_registerEmailMessages();
    }

    /**
     * Control-panel chrome that only applies when Stub is installed as its own plugin.
     * When mounted under Showtime, the host owns the nav and the settings screen.
     *
     * Stub's nav and settings page are served via hasCpSection/hasCpSettings +
     * getCpNavItem()/settingsHtml(), which Craft only invokes for an installed plugin —
     * so there is nothing to unwire here. Kept so all bundled plugins share one mount
     * shape, and as the hook for anything chrome-ish added later.
     */
    private function bootChrome(): void
    {
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = $this->getSettings()->pluginName ?: 'Stub';

        $nav['subnav'] = [
            'dashboard' => ['label' => Craft::t('stub', 'Dashboard'), 'url' => 'stub/dashboard'],
            'calendar' => ['label' => Craft::t('stub', 'Calendar'), 'url' => 'stub/calendar'],
            'bookings' => ['label' => Craft::t('stub', 'Bookings'), 'url' => 'stub/bookings'],
            'services' => ['label' => Craft::t('stub', 'Services'), 'url' => 'stub/services'],
            'providers' => ['label' => Craft::t('stub', 'Providers'), 'url' => 'stub/providers'],
            'customers' => ['label' => Craft::t('stub', 'Customers'), 'url' => 'stub/customers'],
        ];

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('stub/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Booking::class;
            }
        );
    }

    private function _registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('stub', StubVariable::class);
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['stub'] = 'stub/dashboard/index';
                $event->rules['stub/dashboard'] = 'stub/dashboard/index';
                $event->rules['stub/calendar'] = 'stub/calendar/index';
                $event->rules['stub/bookings'] = 'stub/bookings/index';
                $event->rules['stub/bookings/<bookingId:\d+>'] = 'stub/bookings/edit';
                $event->rules['stub/services'] = 'stub/services/index';
                $event->rules['stub/services/new'] = 'stub/services/edit';
                $event->rules['stub/services/<serviceId:\d+>'] = 'stub/services/edit';
                $event->rules['stub/providers'] = 'stub/providers/index';
                $event->rules['stub/providers/new'] = 'stub/providers/edit';
                $event->rules['stub/providers/<providerId:\d+>'] = 'stub/providers/edit';
                $event->rules['stub/providers/<providerId:\d+>/schedule'] = 'stub/providers/schedule';
                $event->rules['stub/customers'] = 'stub/customers/index';
                $event->rules['stub/customers/<customerId:\d+>'] = 'stub/customers/detail';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Site routes handled via action URLs
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('stub', 'Stub'),
                    'permissions' => [
                        'stub:viewBookings' => [
                            'label' => Craft::t('stub', 'View bookings'),
                        ],
                        'stub:manageBookings' => [
                            'label' => Craft::t('stub', 'Manage bookings'),
                        ],
                        'stub:deleteBookings' => [
                            'label' => Craft::t('stub', 'Delete bookings'),
                        ],
                        'stub:manageServices' => [
                            'label' => Craft::t('stub', 'Manage services'),
                        ],
                        'stub:manageProviders' => [
                            'label' => Craft::t('stub', 'Manage providers'),
                        ],
                        'stub:manageCustomers' => [
                            'label' => Craft::t('stub', 'Manage customers'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerEmailMessages(): void
    {
        Event::on(
            \craft\services\SystemMessages::class,
            \craft\services\SystemMessages::EVENT_REGISTER_MESSAGES,
            function(\craft\events\RegisterEmailMessagesEvent $event) {
                $event->messages[] = [
                    'key' => 'stub_booking_confirmation',
                    'heading' => Craft::t('stub', 'Booking Confirmation'),
                    'subject' => Craft::t('stub', 'Your booking has been confirmed — {{referenceNumber}}'),
                    'body' => Craft::t('stub', "Hi {{customerName}},\n\nYour booking for {{serviceName}} with {{providerName}} on {{dateFormatted}} at {{timeFormatted}} has been confirmed.\n\nReference: {{referenceNumber}}\n\nThank you!"),
                ];
                $event->messages[] = [
                    'key' => 'stub_admin_notification',
                    'heading' => Craft::t('stub', 'New Booking Notification'),
                    'subject' => Craft::t('stub', 'New booking: {{referenceNumber}}'),
                    'body' => Craft::t('stub', "A new booking has been created.\n\nReference: {{referenceNumber}}\nService: {{serviceName}}\nProvider: {{providerName}}\nCustomer: {{customerName}}\nDate: {{dateFormatted}} at {{timeFormatted}}"),
                ];
                $event->messages[] = [
                    'key' => 'stub_booking_cancellation',
                    'heading' => Craft::t('stub', 'Booking Cancellation'),
                    'subject' => Craft::t('stub', 'Booking cancelled — {{referenceNumber}}'),
                    'body' => Craft::t('stub', "Hi {{customerName}},\n\nYour booking {{referenceNumber}} for {{serviceName}} on {{dateFormatted}} at {{timeFormatted}} has been cancelled.\n\nIf you have any questions, please contact us."),
                ];
            }
        );
    }
}
