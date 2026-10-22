<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Content of the public landing page, edited by platform staff in the administration panel
 * (/admin/landing-page). Saved values replace the defaults section by section; every save
 * is validated and audited. Text is always rendered escaped; links must be site-relative
 * or http(s); media are uploaded files served by the platform or http(s) URLs.
 */
class LandingPageService
{
    private const KEY = 'landing_page';

    private const CACHE_KEY = 'platform.landing_page';

    /** Icons offered in the editor (Heroicons, outline). */
    public const ICONS = ['beaker', 'building-office-2', 'building-storefront', 'truck', 'home-modern', 'user-group', 'shield-check', 'qr-code',
        'link', 'chart-bar', 'cpu-chip', 'arrow-path', 'globe-europe-africa', 'device-phone-mobile', 'clipboard-document-check', 'banknotes',
        'cube', 'map-pin', 'bell-alert', 'lock-closed', 'heart', 'sparkles', 'scale', 'cloud-arrow-up'];

    public function content(): array
    {
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()->find(self::KEY)?->value ?? []);

        return array_replace(self::defaults(), array_intersect_key($saved, self::defaults()));
    }

    public function save(array $content, User $actor): array
    {
        $data = Validator::make($content, self::rules())->validate();
        if ($data['background']['mode'] === 'video' && blank($data['background']['video_url'])) {
            throw ValidationException::withMessages(['background.video_url' => 'Add a video (upload or link) to use the video background.']);
        }
        $before = PlatformSetting::query()->find(self::KEY)?->value ?? [];
        $after = array_intersect_key($data, self::defaults());
        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $after, 'updated_by' => $actor->id]);
        Cache::forget(self::CACHE_KEY);
        app(AuditLogger::class)->record('platform.landing_page_updated', null, ['sections' => array_keys($before)], ['sections' => array_keys($after)], organizationId: false);

        return $this->content();
    }

    public function reset(User $actor): array
    {
        PlatformSetting::query()->whereKey(self::KEY)->delete();
        Cache::forget(self::CACHE_KEY);
        app(AuditLogger::class)->record('platform.landing_page_reset', null, organizationId: false);

        return $this->content();
    }

    public static function rules(): array
    {
        $text = fn (int $max, bool $required = true) => [$required ? 'required' : 'nullable', 'string', 'max:'.$max];
        // Site-relative paths or http(s) URLs only (no javascript: or data: links).
        $link = ['nullable', 'string', 'max:500', 'regex:#^(/(?!/)[^\s]*|https?://[^\s]+|\#[A-Za-z0-9_-]+)$#'];
        $icon = ['required', Rule::in(self::ICONS)];

        return [
            'hero' => ['required', 'array'],
            'hero.eyebrow' => $text(80),
            'hero.title' => $text(140),
            'hero.highlight' => $text(80, false),
            'hero.subtitle' => $text(400),
            'hero.primary_label' => $text(40),
            'hero.primary_url' => [...$link, 'required'],
            'hero.secondary_label' => $text(40, false),
            'hero.secondary_url' => $link,
            'background' => ['required', 'array'],
            'background.mode' => ['required', Rule::in(['particles', 'video'])],
            'background.video_url' => $link,
            'background.poster_url' => $link,
            'background.overlay_opacity' => ['required', 'integer', 'between:20,95'],
            'background.particle_density' => ['required', 'integer', 'between:20,160'],
            'stats' => ['required', 'array', 'min:1', 'max:6'],
            'stats.*.value' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'stats.*.suffix' => $text(8, false),
            'stats.*.label' => $text(60),
            'network' => ['required', 'array'],
            'network.eyebrow' => $text(60),
            'network.title' => $text(140),
            'network.intro' => $text(500),
            'network.nodes' => ['required', 'array', 'min:3', 'max:8'],
            'network.nodes.*.label' => $text(40),
            'network.nodes.*.description' => $text(160),
            'network.nodes.*.icon' => $icon,
            'network.services' => ['required', 'array', 'min:1', 'max:6'],
            'network.services.*.label' => $text(40),
            'network.services.*.description' => $text(160),
            'network.services.*.icon' => $icon,
            'journey' => ['required', 'array'],
            'journey.title' => $text(140),
            'journey.steps' => ['required', 'array', 'min:2', 'max:6'],
            'journey.steps.*.title' => $text(60),
            'journey.steps.*.description' => $text(200),
            'features' => ['required', 'array'],
            'features.title' => $text(140),
            'features.intro' => $text(400),
            'features.items' => ['required', 'array', 'min:1', 'max:12'],
            'features.items.*.title' => $text(60),
            'features.items.*.description' => $text(240),
            'features.items.*.icon' => $icon,
            'app' => ['required', 'array'],
            'app.eyebrow' => $text(60),
            'app.title' => $text(140),
            'app.description' => $text(500),
            'app.bullets' => ['required', 'array', 'min:1', 'max:8'],
            'app.bullets.*' => $text(160),
            'app.screenshots' => ['required', 'array', 'min:1', 'max:3'],
            'app.screenshots.*.url' => [...$link, 'required'],
            'app.screenshots.*.caption' => $text(60),
            'app.note' => $text(240, false),
            'cta' => ['required', 'array'],
            'cta.title' => $text(140),
            'cta.text' => $text(300),
            'cta.button_label' => $text(40),
            'cta.button_url' => [...$link, 'required'],
            'footer' => ['required', 'array'],
            'footer.text' => $text(300),
            'footer.contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public static function defaults(): array
    {
        return [
            'hero' => [
                'eyebrow' => 'Pharmaceutical supply chain network',
                'title' => 'Every medicine, traced from factory to patient,',
                'highlight' => 'on one trusted network.',
                'subtitle' => 'Manufacturers, distributors, pharmacies, hospitals and their field teams share one platform for stock, orders, deliveries, recalls and batch traceability, built for Africa, online or offline.',
                'primary_label' => 'Sign in',
                'primary_url' => '/login',
                'secondary_label' => 'See the network',
                'secondary_url' => '#network',
            ],
            'background' => ['mode' => 'particles', 'video_url' => null, 'poster_url' => null, 'overlay_opacity' => 60, 'particle_density' => 100],
            'stats' => [
                ['value' => 6, 'suffix' => '', 'label' => 'Links in the chain, one record'],
                ['value' => 12, 'suffix' => '', 'label' => 'Modules on one platform'],
                ['value' => 100, 'suffix' => '%', 'label' => 'Stock movements on the trace ledger'],
                ['value' => 4, 'suffix' => '', 'label' => 'Shared network services'],
            ],
            'network' => [
                'eyebrow' => 'The network',
                'title' => 'One flow of goods, one shared source of truth',
                'intro' => 'Each organization keeps its own books. What moves between them, batches, orders, deliveries and recall notices, is recorded once and seen by everyone it concerns.',
                'nodes' => [
                    ['label' => 'Manufacturers', 'description' => 'Release batches with GS1 identifiers and own their recalls.', 'icon' => 'beaker'],
                    ['label' => 'Importers', 'description' => 'Receive and register imported lots against regulator records.', 'icon' => 'globe-europe-africa'],
                    ['label' => 'Distributors', 'description' => 'Hold national stock, run field teams and fulfil orders.', 'icon' => 'building-office-2'],
                    ['label' => 'Pharmacies', 'description' => 'Sell at the counter, online or offline, by batch and expiry.', 'icon' => 'building-storefront'],
                    ['label' => 'Hospitals', 'description' => 'Procure, receive and dispense with full lot history.', 'icon' => 'home-modern'],
                    ['label' => 'Patients', 'description' => 'Scan a pack to see what is recorded about it.', 'icon' => 'heart'],
                ],
                'services' => [
                    ['label' => 'Traceability ledger', 'description' => 'Hash-chained events for every stock movement, EPCIS export.', 'icon' => 'qr-code'],
                    ['label' => 'Logistics & cold chain', 'description' => 'Shipments, proof of delivery and temperature excursions.', 'icon' => 'truck'],
                    ['label' => 'B2B marketplace', 'description' => 'Discovery, RFQs and orders between verified partners.', 'icon' => 'link'],
                    ['label' => 'Regulatory compliance', 'description' => 'Verified licences, recalls and incident reporting deadlines.', 'icon' => 'shield-check'],
                ],
            ],
            'journey' => [
                'title' => 'How a batch moves through the network',
                'steps' => [
                    ['title' => 'Released', 'description' => 'The manufacturer records the batch, its expiry and identifiers.'],
                    ['title' => 'Shipped', 'description' => 'Carriers track the shipment, its temperature and proof of delivery.'],
                    ['title' => 'Received', 'description' => 'The distributor receives it into stock; the event joins the ledger.'],
                    ['title' => 'Sold', 'description' => 'Pharmacies sell first-expiry-first-out, even when offline.'],
                    ['title' => 'Verified', 'description' => 'A patient or pharmacist scans the pack and sees its history.'],
                ],
            ],
            'features' => [
                'title' => 'Everything the chain runs on',
                'intro' => 'One platform for each organization\'s own operations, connected to the network.',
                'items' => [
                    ['title' => 'Inventory & warehouses', 'description' => 'Batches, expiry, FEFO allocation, transfers, counts and quarantine.', 'icon' => 'cube'],
                    ['title' => 'Point of sale', 'description' => 'Fast counter sales with prescription controls and offline mode.', 'icon' => 'banknotes'],
                    ['title' => 'Field force & CRM', 'description' => 'Route plans, verified visits, detailing and mobile orders.', 'icon' => 'map-pin'],
                    ['title' => 'Recalls', 'description' => 'Every holder notified, stock quarantined, recovery tracked.', 'icon' => 'bell-alert'],
                    ['title' => 'Analytics & forecasts', 'description' => 'Dashboards, demand forecasts and reviewed recommendations.', 'icon' => 'chart-bar'],
                    ['title' => 'Enterprise integration', 'description' => 'SAP and other ERPs connected through a durable gateway.', 'icon' => 'cpu-chip'],
                ],
            ],
            'app' => [
                'eyebrow' => 'Field force app',
                'title' => 'The representative\'s day, in their pocket',
                'description' => 'Representatives plan routes, check in at customers with verified location, detail products with approved messages and capture orders. Everything works without signal and syncs when the network returns.',
                'bullets' => [
                    'My day: approved route plan in visiting order',
                    'Location-verified check-in and check-out',
                    'Detailing with approved key messages and samples',
                    'Orders captured offline, sent when back online',
                ],
                'screenshots' => [
                    ['url' => '/images/landing/app-my-day.webp', 'caption' => 'My day'],
                    ['url' => '/images/landing/app-visit.webp', 'caption' => 'Visit in progress'],
                    ['url' => '/images/landing/app-customer.webp', 'caption' => 'Customer'],
                ],
                'note' => 'Screens from the field force app. Names and figures are demonstration data.',
            ],
            'cta' => [
                'title' => 'Bring your organization onto the network',
                'text' => 'Register, verify your licences and start trading with partners you can trust.',
                'button_label' => 'Get started',
                'button_url' => '/login',
            ],
            'footer' => [
                'text' => 'A pharmaceutical supply chain network for manufacturers, distributors, pharmacies, hospitals and regulators.',
                'contact_email' => null,
            ],
        ];
    }
}
