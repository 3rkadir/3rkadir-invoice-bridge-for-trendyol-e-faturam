<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

class Cron
{
    public const HOOK = 'tef_hourly_status_check';
    public const AUTO_HOOK = 'tef_auto_create_invoice';

    public function __construct(
        private InvoiceService $service,
        private InvoiceRepository $repo,
        private Logger $logger
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'checkPending']);
        add_action(self::AUTO_HOOK, [$this, 'autoCreate']);
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 3);
    }

    public function checkPending(): void
    {
        $this->logger->prune(30);
        if (!Settings::isConfigured()) {
            return;
        }
        foreach ($this->repo->pendingRemote(50) as $row) {
            $this->service->refreshStatus((int) $row['order_id']);
        }
    }

    public function onStatusChanged(int $orderId, string $from, string $to): void
    {
        if (Settings::get('auto_invoice') !== 'yes' || !Settings::isConfigured()) {
            return;
        }
        if (!in_array($to, Settings::triggerStatuses(), true)) {
            return;
        }
        if ($this->repo->find($orderId) !== null) {
            return;
        }
        if (!wp_next_scheduled(self::AUTO_HOOK, [$orderId])) {
            wp_schedule_single_event(time() + 10, self::AUTO_HOOK, [$orderId]);
        }
    }

    public function autoCreate(int $orderId): void
    {
        if (Settings::get('auto_invoice') !== 'yes') {
            return;
        }
        $this->service->createForOrder($orderId);
    }
}
