<?php

/**
 * AI translation draft.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Draft extends Mage_Core_Model_Abstract
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    #[\Override]
    protected function _construct(): void
    {
        $this->_init('translations/draft');
    }
}
