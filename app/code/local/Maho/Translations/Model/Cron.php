<?php

/**
 * Reconciles AI translation tasks and drafts.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Cron
{
    #[Maho\Config\CronJob('translations_ai_reconcile', schedule: '*/5 * * * *')]
    public function reconcileAiDrafts(): void
    {
        Mage::getService('translations/aiDraft')->reconcile();
    }
}
